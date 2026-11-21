<?php
//
// Sevenx Valkey Cache extension for Exponential / eZ Publish Legacy
// Copyright (C) 1998 - 2026 Exponential Foundation and 7x. All rights reserved. (https://se7enx.com)
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

  NOTE: INI data is now stored in a single Redis hash keyed by the file MD5.
  This removes the expensive full-keyspace SCAN that was performed on every
  request to prefetch INI data.
*/

class sevenxValkeyINICache
{
    const INI_FILE = 'extension/sevenx_valkey_cache/settings/valkeycache.ini.append.php';

    private static $instance;

    private $redis;
    private $enabled = false;
    private $prefix = 'sevenx_valkey_cache:';
    /** KeyPrefix alone (with its colon), without the installation hash: the shared part of every installation's keys. */
    private $basePrefix = 'sevenx_valkey_cache:';
    private $ttl = 3600;
    private $serializer = Redis::SERIALIZER_NONE;

    /**
     * Request-scoped in-memory cache of all INI arrays, populated on demand.
     * @var array
     */
    private $localCache = array();

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
        $this->basePrefix = $prefix . ':';
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
     */
    private function computeInstallationHash()
    {
        $host = isset( $_SERVER['HTTP_HOST'] ) ? $_SERVER['HTTP_HOST'] : php_uname( 'n' );

        return substr( hash( 'sha256', trim( self::installationRoot() ) . '|' . trim( $host ) ), 0, 16 );
    }

    /**
     * The installation's root folder (the same for every web host and the CLI).
     */
    private static function installationRoot()
    {
        $root = @realpath( dirname( __DIR__, 3 ) );
        return $root === false ? dirname( __DIR__, 3 ) : $root;
    }

    /**
     * The set that lists every INI hash key this installation has written. With InstallationHash=auto each web host
     * (and the CLI) has a key of its own; clear() needs all of them without scanning the shared key prefix, which
     * other installations use too.
     */
    private function indexKey()
    {
        return $this->basePrefix . 'inikeys:' . substr( hash( 'sha256', trim( self::installationRoot() ) ), 0, 16 );
    }

    /**
     * Redis hash key where all compiled INI arrays for this installation live.
     */
    private function iniHashKey()
    {
        return $this->prefix . 'ini';
    }

    /**
     * Field name inside the INI hash for a given compiled cache file.
     */
    private function iniField( $cachedFile )
    {
        return md5( $cachedFile );
    }

    public function isEnabled()
    {
        return $this->enabled && $this->redis !== null;
    }

    /**
     * Load a compiled INI cache array from Redis.
     *
     * @param string $cachedFile The full cache file path that eZINI would use.
     * @return array|false The cached data array, or false if not available.
     */
    public function load( $cachedFile )
    {
        if ( !$this->isEnabled() )
            return false;

        $field = $this->iniField( $cachedFile );
        if ( array_key_exists( $field, $this->localCache ) )
            return $this->localCache[$field];

        $value = $this->redis->hGet( $this->iniHashKey(), $field );
        if ( $value === false )
            return false;

        $this->localCache[$field] = $value;
        return $value;
    }

    /**
     * Save a compiled INI cache array to Redis.
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
            $field = $this->iniField( $cachedFile );
            $this->localCache[$field] = $data;
            $hash = $this->iniHashKey();
            $result = $this->redis->hSet( $hash, $field, $data );
            if ( $result !== false )
            {
                $this->redis->expire( $hash, $this->ttl );
                // remembered for clear(): the hash keys of this installation, one per web host with InstallationHash=auto
                $this->redis->sAdd( $this->indexKey(), $hash );
                $this->redis->expire( $this->indexKey(), $this->ttl );
            }
            return $result !== false;
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

        $field = $this->iniField( $cachedFile );
        unset( $this->localCache[$field] );
        $this->redis->hDel( $this->iniHashKey(), $field );
        return true;
    }

    /**
     * Remove every compiled INI cache entry of THIS installation stored in Redis: its own hash key and the keys of its
     * other web hosts (InstallationHash=auto gives each host one), found in the installation's index set, never by
     * scanning the shared key prefix. Called by eZCache when the INI caches are cleared (ezcache --clear-tag=ini,
     * --clear-id=ini/global_ini, --clear-all).
     *
     * @return bool
     */
    public function clear()
    {
        if ( !$this->isEnabled() )
            return false;

        $this->localCache = array();
        $keys = array( $this->iniHashKey() => true );
        try
        {
            $members = $this->redis->sMembers( $this->indexKey() );
        }
        catch ( Exception $e )
        {
            $members = array();
        }
        foreach ( is_array( $members ) ? $members : array() as $key )
        {
            // only INI hash keys under this KeyPrefix, as save() writes them
            if ( is_string( $key ) && strpos( $key, $this->basePrefix ) === 0 && substr( $key, -4 ) === ':ini' )
                $keys[$key] = true;
        }
        foreach ( array_keys( $keys ) as $key )
            $this->redis->del( $key );
        $this->redis->del( $this->indexKey() );
        return true;
    }

    private function __clone() {}
    public function __wakeup() { trigger_error( "Deserialization is not allowed.", E_USER_ERROR ); }
}
