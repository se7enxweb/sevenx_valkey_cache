<?php
//
// Sevenx Valkey Cache extension for Exponential / eZ Publish Legacy
// Copyright (C) 2025 7x (https://se7enx.com)
//
// This program is free software; you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation; either version 2 of the License, or
// (at your option) any later version.
//

/*!
  \class sevenxValkeyINICache sevenxvalkeyinicache.php
  \brief Redis/Valkey backend for eZINI compiled cache files.

  eZINI normally writes compiled INI arrays to disk as PHP files and includes
  them on subsequent requests. This class stores the same array in Redis and
  returns it to eZINI::loadCache(), removing per-request disk touches and
  making INI caches cluster-safe.

  It reads valkeycache.ini directly (without going through eZINI) to avoid
  recursion while eZINI itself is still loading.
*/

class sevenxValkeyINICache
{
    const INI_FILE = 'extension/sevenx_valkey_cache/settings/valkeycache.ini.append.php';

    private static $instance;

    private $redis;
    private $enabled = false;
    private $prefix = 'sevenx_valkey_cache:';
    private $ttl = 3600;
    private $serializer = Redis::SERIALIZER_NONE;

    /**
     * Request-scoped local cache of all INI arrays, populated with a single MGET.
     * @var array
     */
    private $localCache = array();

    /**
     * Whether the local cache has already been preloaded from Redis.
     * @var bool
     */
    private $prefetched = false;

    public static function instance()
    {
        if ( !isset( self::$instance ) )
        {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct()
    {
        if ( !class_exists( 'Redis' ) )
        {
            return;
        }

        $settings = $this->rawSettings();
        if ( $settings['IniCache'] !== 'enabled' )
        {
            return;
        }

        $this->enabled = true;

        $prefix = rtrim( $settings['KeyPrefix'], ':' );
        $installationHash = $settings['InstallationHash'];
        if ( $installationHash === 'auto' )
            $installationHash = $this->computeInstallationHash();
        if ( $installationHash !== '' )
            $prefix .= ':' . $installationHash;

        $this->prefix  = $prefix . ':';
        $this->ttl     = (int) $settings['DefaultTTL'];
        if ( $this->ttl <= 0 )
            $this->ttl = 3600;

        $host = $settings['Host'];
        $port = (int) $settings['Port'];
        $db   = (int) $settings['Database'];

        try
        {
            $this->redis = new Redis();
            if ( $settings['Persistent'] === 'enabled' )
                $this->redis->pconnect( $host, $port );
            else
                $this->redis->connect( $host, $port );
            $this->redis->select( $db );

            if ( defined( 'Redis::SERIALIZER_IGBINARY' ) && function_exists( 'igbinary_serialize' ) )
            {
                $this->serializer = Redis::SERIALIZER_IGBINARY;
                $this->redis->setOption( Redis::OPT_SERIALIZER, Redis::SERIALIZER_IGBINARY );
            }
            else if ( defined( 'Redis::SERIALIZER_PHP' ) )
            {
                $this->serializer = Redis::SERIALIZER_PHP;
                $this->redis->setOption( Redis::OPT_SERIALIZER, Redis::SERIALIZER_PHP );
            }
        }
        catch ( Exception $e )
        {
            $this->enabled = false;
            $this->redis = null;
        }
    }

    /**
     * Read valkeycache.ini directly to avoid recursion through eZINI.
     */
    private function rawSettings()
    {
        $defaults = array(
            'Host'             => '127.0.0.1',
            'Port'             => 6379,
            'Database'         => 0,
            'KeyPrefix'        => 'sevenx_valkey_cache:',
            'InstallationHash' => 'auto',
            'IniCache'         => 'disabled',
            'DefaultTTL'       => 3600,
            'Persistent'       => 'disabled',
        );

        $file = __DIR__ . '/../../../' . self::INI_FILE;
        if ( !file_exists( $file ) )
            return $defaults;

        $content = file_get_contents( $file );
        if ( $content === false )
            return $defaults;

        // Strip the PHP wrapper used by eZ Publish .ini.append.php files
        $content = preg_replace( '@^\s*<\?php\s*/\*\s*#\?ini[^\n]*\n@', '', $content );
        $content = preg_replace( '@\s*\*/\s*\?>\s*$@', '', $content );

        // PHP 8's parse_ini_string does not accept # as a comment character,
        // while eZ Publish .ini files commonly use it. Remove # comments first.
        $lines = explode( "\n", $content );
        $clean = array();
        foreach ( $lines as $line )
        {
            $line = preg_replace( '/^\s*#.*$/', '', $line );
            if ( trim( $line ) !== '' )
                $clean[] = $line;
        }
        $content = implode( "\n", $clean );

        $parsed = @parse_ini_string( $content, false, INI_SCANNER_RAW );
        if ( $parsed === false )
            return $defaults;

        foreach ( $parsed as $key => $value )
        {
            if ( isset( $defaults[$key] ) )
                $defaults[$key] = $value;
        }

        return $defaults;
    }

    /**
     * Build a short, deterministic hash that uniquely identifies this installation.
     *
     * Unlike the main cache block class, this method must avoid going through
     * eZINI while eZINI is still bootstrapping, so it uses the filesystem
     * docroot and the current hostname instead of eZSys/eZINI.
     */
    private function computeInstallationHash()
    {
        // Avoid eZSys/eZINI here: this method is called while eZINI itself is
        // still loading, so using them creates an infinite recursion. The
        // filesystem root of the site is unique enough for key prefixing.
        $root = @realpath( dirname( __DIR__, 3 ) );
        if ( $root === false )
            $root = dirname( __DIR__, 3 );

        $host = isset( $_SERVER['HTTP_HOST'] ) ? $_SERVER['HTTP_HOST'] : php_uname( 'n' );

        return substr( hash( 'sha256', trim( $root ) . '|' . trim( $host ) ), 0, 16 );
    }

    private function key( $cachedFile )
    {
        return $this->prefix . 'ini:' . md5( $cachedFile );
    }

    public function isEnabled()
    {
        return $this->enabled && $this->redis !== null;
    }

    /**
     * Preload every INI cache entry from Redis into a request-scoped local
     * cache using a single MGET. This turns N per-INI Redis round-trips into
     * one.
     */
    private function prefetchAll()
    {
        if ( $this->prefetched || !$this->isEnabled() )
            return;

        $this->prefetched = true;
        $pattern = $this->prefix . 'ini:*';
        $keys = array();

        $iterator = null;
        do
        {
            $found = $this->redis->scan( $iterator, $pattern, 1000 );
            if ( is_array( $found ) )
            {
                $keys = array_merge( $keys, $found );
            }
        } while ( $iterator > 0 );

        if ( empty( $keys ) )
            return;

        $values = $this->redis->mGet( $keys );
        foreach ( $keys as $i => $key )
        {
            if ( $values[$i] !== false )
            {
                $this->localCache[$key] = $values[$i];
            }
        }
    }

    /**
     * Load a compiled INI cache array from the local cache (backed by Redis).
     *
     * @param string $cachedFile The full cache file path that eZINI would use.
     * @return array|false The cached data array, or false if not available.
     */
    public function load( $cachedFile )
    {
        if ( !$this->isEnabled() )
            return false;

        if ( !$this->prefetched )
            $this->prefetchAll();

        $key = $this->key( $cachedFile );
        if ( !array_key_exists( $key, $this->localCache ) )
            return false;

        return $this->localCache[$key];
    }

    /**
     * Save a compiled INI cache array to Redis and the local cache.
     *
     * @param string $cachedFile The full cache file path that eZINI would use.
     * @param array  $data       The cache data array (with 'rev', 'created', etc.).
     * @return bool
     */
    public function save( $cachedFile, array $data )
    {
        if ( !$this->isEnabled() )
            return false;

        try
        {
            $key = $this->key( $cachedFile );
            $this->localCache[$key] = $data;
            return $this->redis->setEx( $key, $this->ttl, $data );
        }
        catch ( Exception $e )
        {
            return false;
        }
    }

    /**
     * Delete a single compiled INI cache entry.
     *
     * @param string $cachedFile
     * @return bool
     */
    public function delete( $cachedFile )
    {
        if ( !$this->isEnabled() )
            return false;

        $key = $this->key( $cachedFile );
        unset( $this->localCache[$key] );
        $this->redis->del( $key );
        return true;
    }

    /**
     * Remove every compiled INI cache entry stored in Redis.
     */
    public function clear()
    {
        if ( !$this->isEnabled() )
            return false;

        $this->localCache = array();

        $iterator = null;
        $pattern = $this->prefix . 'ini:*';
        do
        {
            $keys = $this->redis->scan( $iterator, $pattern );
            if ( is_array( $keys ) && !empty( $keys ) )
            {
                $this->redis->del( $keys );
            }
        } while ( $iterator > 0 );

        return true;
    }

    private function __clone() {}
    public function __wakeup() { trigger_error( 'Deserialization is not allowed.', E_USER_ERROR ); }
}
