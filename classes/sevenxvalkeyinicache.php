<?php
//
// Sevenx Valkey Cache extension for Exponential / eZ Publish Legacy
// Copyright (C) 1998 - 2026 Exponential Foundation and 7x; All rights reserved. (https://se7enx.com)
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

  1.0.2.3: one Valkey round trip per request instead of one per INI file. Each
  compiled array is its own key, named by a generation number, with a TTL that
  has a random part (no hour where every array expires at once), and it is kept
  in APCu as well (IniLocalCache), under the same generation. A request reads
  the generation once (a GET) and every array it needs from APCu; a clear or a
  delete raises the generation, so every node and every PHP process drops its
  copies at once. IniSharedAcrossHosts=enabled keys the arrays by the
  installation alone, not by the web host name: a compiled INI file is the
  same for every host of a siteaccess (its name already says which files and
  siteaccess it was built from), so the hosts of an installation share one copy.
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
    /** Seconds an INI array is kept (IniTTL) and the largest random part added to it (IniTTLJitter) */
    private $iniTTL = 86400;
    private $iniJitter = 8640;
    /** Whether the arrays are also kept in APCu (IniLocalCache, and APCu usable in this process) */
    private $apcu = false;
    /** @var string|null the generation of this request, read once (generation()) */
    private $generation = null;
    /** @var string|null the request the generation and the local copies belong to (a persistent worker serves many) */
    private $request = null;
    /** 1.0.2.4: reads go to a local replica (ReadHost, ReadPort) when one is set and answers; writes to the primary */
    private $reader = null;
    private $readHost = '';
    private $readPort = 6379;
    private $connectArgs = array();
    /** Redis::SERIALIZER_NONE (0), written as its value: a constant of Redis here is a fatal error where the PHP redis extension is missing */
    private $serializer = 0;

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
            $installationHash = $this->computeInstallationHash( $settings['IniSharedAcrossHosts'] === 'enabled' );
        if ( $installationHash !== '' )
            $prefix .= ':' . $installationHash;

        $this->prefix  = $prefix . ':';
        $this->ttl     = (int) $settings['DefaultTTL'];
        if ( $this->ttl <= 0 )
            $this->ttl = 3600;
        $this->iniTTL = max( 60, (int) $settings['IniTTL'] );
        $this->iniJitter = max( 0, (int) $settings['IniTTLJitter'] );
        $this->apcu = $settings['IniLocalCache'] === 'enabled' && function_exists( 'apcu_enabled' ) && apcu_enabled();

        $host = $settings['Host'];
        $port = (int) $settings['Port'];
        $db   = (int) $settings['Database'];
        $this->readHost = trim( (string) $settings['ReadHost'] );
        $this->readPort = (int) $settings['ReadPort'];
        $this->connectArgs = array( $host, $port, $db, $settings['Persistent'] === 'enabled' );

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
            'IniLocalCache'    => 'enabled',
            'IniSharedAcrossHosts' => 'disabled',
            'IniTTL'           => 86400,
            'IniTTLJitter'     => 8640,
            'ReadHost'         => '',
            'ReadPort'         => 6379,
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
    private function computeInstallationHash( $sharedAcrossHosts = false )
    {
        if ( $sharedAcrossHosts )
            return substr( hash( 'sha256', trim( self::installationRoot() ) . '|ini' ), 0, 16 );
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

    /** The connection reads go to: the local replica when ReadHost is set and answers, else the primary. */
    private function reader()
    {
        if ( $this->reader !== null )
            return $this->reader;
        $this->reader = $this->redis;
        list( $host, $port, $db, $persistent ) = $this->connectArgs + array( '', 0, 0, false );
        if ( $this->readHost === '' || ( $this->readHost === $host && $this->readPort === (int) $port ) || !class_exists( 'Redis' ) )
            return $this->reader;
        try
        {
            $r = new Redis();
            if ( $persistent )
                $r->pconnect( $this->readHost, $this->readPort, 0.5 );
            else
                $r->connect( $this->readHost, $this->readPort, 0.5 );
            $r->select( (int) $db );
            $r->setOption( Redis::OPT_SERIALIZER, $this->serializer );
            $this->reader = $r;
        }
        catch ( Exception $e )
        {
        }
        return $this->reader;
    }

    /**
     * The key of the generation number of this installation's INI arrays: one for the installation (by its root, like
     * the index set), whatever the host, so a clear from the command line reaches the arrays of every web host.
     */
    private function generationKey()
    {
        return $this->basePrefix . 'inigen:' . substr( hash( 'sha256', trim( self::installationRoot() ) ), 0, 16 );
    }

    /** The key of one INI array in one generation */
    private function entryKey( $generation, $field )
    {
        return $this->prefix . 'ini:g' . $generation . ':' . $field;
    }

    /** The APCu key of one INI array in one generation */
    private function apcuKey( $generation, $field )
    {
        return 'svc-ini:' . $this->prefix . 'g' . $generation . ':' . $field;
    }

    /**
     * Seconds to keep an entry: IniTTL plus a random part up to IniTTLJitter (pure but for the random number), so the
     * entries written together do not expire together.
     */
    public static function ttlWithJitter( $ttl, $jitter, $random = null )
    {
        $ttl = max( 1, (int) $ttl );
        $jitter = max( 0, (int) $jitter );
        if ( $jitter === 0 )
            return $ttl;
        $r = $random === null ? mt_rand( 0, $jitter ) : max( 0, min( $jitter, (int) $random ) );
        return $ttl + $r;
    }

    /** Starts the request's state again when a new request is served (PHP-FPM: always; a persistent worker: per request). */
    private function forRequest()
    {
        $request = isset( $_SERVER['REQUEST_TIME_FLOAT'] ) ? (string) $_SERVER['REQUEST_TIME_FLOAT'] : '';
        if ( $this->request !== $request )
        {
            $this->request = $request;
            $this->generation = null;
            $this->localCache = array();
        }
    }

    /** The generation of this installation's INI arrays, read once per request (the one round trip of a request). */
    public function generation()
    {
        $this->forRequest();
        if ( $this->generation === null )
        {
            try
            {
                $g = $this->reader()->get( $this->generationKey() );
                if ( !self::validGeneration( $g ) )
                {
                    // 1.0.2.5: no generation (never set, or deleted by a flush of the key prefix): a new one, never 0 or
                    // an old number, so arrays kept under an earlier generation are never taken as current again
                    $new = self::newGeneration();
                    $this->redis->set( $this->generationKey(), $new, array( 'nx' ) );
                    $g = $this->redis->get( $this->generationKey() );
                }
            }
            catch ( Exception $e )
            {
                $g = false;
            }
            // without Valkey nothing is read from it anyway; a generation of this request alone keeps APCu out of it
            $this->generation = self::validGeneration( $g ) ? (string) $g : 'x' . self::newGeneration();
        }
        return $this->generation;
    }

    /** A new generation: unique, never a number used before (pure but for the random part). */
    public static function newGeneration()
    {
        return base_convert( (string) time(), 10, 36 ) . bin2hex( random_bytes( 6 ) );
    }

    /** Whether a value read for the generation is one (pure): 1.0.2.5 tokens, never empty, never a bare counter. */
    public static function validGeneration( $g )
    {
        return is_string( $g ) && preg_match( '/^[a-z0-9]{13,32}$/', $g ) === 1 && !ctype_digit( $g );
    }

    /** Raises the generation: every node and process drops the copies it has (APCu, entries of the old generation). */
    private function bumpGeneration()
    {
        $this->localCache = array();
        $this->generation = null;
        try
        {
            // 1.0.2.5: a new random generation, not a counter: a deleted key that starts again at 1 met old entries
            $this->redis->set( $this->generationKey(), self::newGeneration() );
            return true;
        }
        catch ( Exception $e )
        {
            return false;
        }
    }

    /**
     * Load a compiled INI cache array: this request's copy, APCu, then the entry of the current generation.
     *
     * @param string $cachedFile The full cache file path that eZINI would use.
     * @return array|false The cached data array, or false if not available.
     */
    public function load( $cachedFile )
    {
        if ( !$this->isEnabled() )
            return false;

        $field = $this->iniField( $cachedFile );
        $generation = $this->generation();
        if ( array_key_exists( $field, $this->localCache ) )
            return $this->localCache[$field];

        if ( $this->apcu )
        {
            $value = apcu_fetch( $this->apcuKey( $generation, $field ), $found );
            if ( $found && is_array( $value ) )
                return $this->localCache[$field] = $value;
        }

        try
        {
            // 1.0.2.5: never from the hash of the versions before 1.0.2.3: it holds arrays of before the last clear
            $value = $this->reader()->get( $this->entryKey( $generation, $field ) );
            if ( !is_array( $value ) )
                return false;
        }
        catch ( Exception $e )
        {
            return false;
        }

        if ( $this->apcu )
            @apcu_store( $this->apcuKey( $generation, $field ), $value, self::ttlWithJitter( $this->iniTTL, $this->iniJitter ) );
        return $this->localCache[$field] = $value;
    }

    /**
     * Save a compiled INI cache array as the entry of the current generation (and in APCu).
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
            $generation = $this->generation();
            $this->localCache[$field] = $data;
            $result = $this->redis->set( $this->entryKey( $generation, $field ), $data, array( 'ex' => self::ttlWithJitter( $this->iniTTL, $this->iniJitter ) ) );
            if ( $this->apcu )
                @apcu_store( $this->apcuKey( $generation, $field ), $data, self::ttlWithJitter( $this->iniTTL, $this->iniJitter ) );
            return $result !== false;
        }
        catch ( Exception $e )
        {
            return false;
        }
    }

    /**
     * Delete a single compiled INI cache entry. The generation goes up as well, so no node keeps a copy in APCu.
     *
     * @param string $cachedFile
     * @return bool
     */
    public function delete( $cachedFile )
    {
        if ( !$this->isEnabled() )
            return false;

        $field = $this->iniField( $cachedFile );
        try
        {
            $this->redis->del( $this->entryKey( $this->generation(), $field ) );
            $this->redis->hDel( $this->iniHashKey(), $field );
        }
        catch ( Exception $e )
        {
        }
        return $this->bumpGeneration();
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

        $this->bumpGeneration();
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
