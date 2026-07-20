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
  \class sevenxValkeyCacheBlock sevenxvalkeycacheblock.php
  \brief Redis/Valkey backed cache block storage.

  Replaces file based storage for selected cache blocks and content view caches
  by storing the rendered data in Redis/Valkey. It supports TTL based expiry,
  generation locks, and reverse indexes by node/subtree id for selective purging.
*/

class sevenxValkeyCacheBlock
{
    /**
     * Singleton instance.
     * @var sevenxValkeyCacheBlock
     */
    private static $instance;

    /**
     * Redis/Valkey connection object.
     * @var Redis|null
     */
    private $redis = null;

    /**
     * If true the handler is ready to store and retrieve cache data.
     * @var bool
     */
    private $enabled = false;

    private $host;
    private $port;
    private $db;
    private $prefix;
    private $defaultTtl;
    private $lockTtl;
    private $persistent;
    private $useLocalCache;

    /**
     * Request-scoped in-memory cache to avoid repeated Redis round-trips.
     * @var array
     */
    private $localCache = array();

    /**
     * Node/subtree ids that have already been prefetched this request.
     * @var array
     */
    private $prefetchedNodes = array();
    private $prefetchedSubtrees = array();

    /**
     * Maximum number of retries while waiting for another process to generate
     * a cache entry.
     * @var int
     */
    private $maxRetries = 100;

    /**
     * Microseconds to sleep between retries.
     * @var int
     */
    private $retryDelay = 100000;

    private function __construct()
    {
        if ( !class_exists( 'Redis' ) )
        {
            eZDebug::writeError( 'The PHP redis extension is not available. Valkey cache is disabled.', __METHOD__ );
            return;
        }

        $ini = eZINI::instance( 'valkeycache.ini' );

        $this->host = $this->iniString( $ini, 'ValkeyCacheSettings', 'Host', '127.0.0.1' );
        $this->port = $this->iniInt( $ini, 'ValkeyCacheSettings', 'Port', 6379 );
        $this->db   = $this->iniInt( $ini, 'ValkeyCacheSettings', 'Database', 0 );

        $prefix = $this->iniString( $ini, 'ValkeyCacheSettings', 'KeyPrefix', 'sevenx_valkey_cache' );
        $prefix = rtrim( $prefix, ':' );
        $installationHash = $this->iniString( $ini, 'ValkeyCacheSettings', 'InstallationHash', 'auto' );
        if ( $installationHash === 'auto' )
        {
            $installationHash = $this->computeInstallationHash();
        }
        if ( $installationHash !== '' )
        {
            $prefix .= ':' . $installationHash;
        }
        $this->prefix = $prefix . ':';

        $this->defaultTtl = $this->iniInt( $ini, 'ValkeyCacheSettings', 'DefaultTTL', 3600 );
        $this->lockTtl = $this->iniInt( $ini, 'ValkeyCacheSettings', 'LockTTL', 30 );
        $this->persistent = $this->iniString( $ini, 'ValkeyCacheSettings', 'Persistent', 'disabled' ) === 'enabled';
        $this->useLocalCache = $this->iniString( $ini, 'ValkeyCacheSettings', 'LocalCache', 'enabled' ) !== 'disabled';

        if ( $this->defaultTtl <= 0 )
            $this->defaultTtl = 3600;
        if ( $this->lockTtl <= 0 )
            $this->lockTtl = 30;

        $this->redis = new Redis();
        try
        {
            if ( $this->persistent )
            {
                $this->redis->pconnect( $this->host, $this->port );
            }
            else
            {
                $this->redis->connect( $this->host, $this->port );
            }
            $this->redis->select( $this->db );

            $serializer = Redis::SERIALIZER_NONE;
            if ( defined( 'Redis::SERIALIZER_IGBINARY' ) && function_exists( 'igbinary_serialize' ) )
            {
                $serializer = Redis::SERIALIZER_IGBINARY;
            }
            elseif ( defined( 'Redis::SERIALIZER_PHP' ) )
            {
                $serializer = Redis::SERIALIZER_PHP;
            }
            $this->redis->setOption( Redis::OPT_SERIALIZER, $serializer );
            $this->enabled = true;
        }
        catch ( Exception $e )
        {
            eZDebug::writeError( 'Could not connect to Redis/Valkey at ' . $this->host . ':' . $this->port . ': ' . $e->getMessage(), __METHOD__ );
            $this->redis = null;
            $this->enabled = false;
        }
    }

    public static function instance()
    {
        if ( !isset( self::$instance ) )
        {
            self::$instance = new self();
        }
        return self::$instance;
    }

    public function isEnabled()
    {
        return $this->enabled;
    }

    /**
     * Fetch a cache entry.
     *
     * @param mixed $cacheKeys  A string or array that uniquely identifies the cache entry.
     * @param bool  $lock       If true a lock will be set while the entry is missing.
     * @param int   $tries      Internal retry counter.
     * @param int   $nodeExpiry Optional node id to prefetch related cache keys.
     * @param int   $subTreeExpiry Optional subtree id to prefetch related cache keys.
     *
     * @return mixed The cached content, or false if the entry is missing / locked.
     */
    public function get( $cacheKeys, $lock = true, $tries = 0, $nodeExpiry = 0, $subTreeExpiry = 0 )
    {
        if ( !$this->enabled )
            return false;

        $nodeExpiry = (int) $nodeExpiry;
        $subTreeExpiry = (int) $subTreeExpiry;

        if ( $nodeExpiry && !isset( $this->prefetchedNodes[$nodeExpiry] ) )
        {
            $this->prefetchByNode( $nodeExpiry );
        }
        if ( $subTreeExpiry && !isset( $this->prefetchedSubtrees[$subTreeExpiry] ) )
        {
            $this->prefetchBySubTree( $subTreeExpiry );
        }

        $key = $this->cacheKeysToKey( $cacheKeys );
        $lockKey = $this->lockKey( $key );

        if ( $this->useLocalCache && array_key_exists( $key, $this->localCache ) )
        {
            return $this->localCache[$key];
        }

        $content = $this->redis->get( $key );

        if ( !$lock )
        {
            $result = ( $content === false ) ? false : $content;
            if ( $result !== false && $this->useLocalCache )
            {
                $this->localCache[$key] = $result;
            }
            return $result;
        }

        if ( $content === false )
        {
            // Try to take the generation lock.
            $lockValue = microtime( true );
            $gotLock = $this->redis->set( $lockKey, $lockValue, array( 'nx', 'ex' => $this->lockTtl ) );

            if ( $gotLock )
            {
                // Caller is responsible for generating and calling put().
                return false;
            }
            else
            {
                // Another process is generating the entry. Wait and retry.
                return $this->retryGet( $cacheKeys, $tries );
            }
        }

        if ( $this->useLocalCache )
        {
            $this->localCache[$key] = $content;
        }
        return $content;
    }

    /**
     * Store a cache entry.
     *
     * @param mixed $cacheKeys    Cache key identifier.
     * @param mixed $content      Content to store (string, array, object).
     * @param int|null $ttl       TTL in seconds, null uses DefaultTTL.
     * @param int   $subTreeExpiry Node id used as subtree expiry index.
     * @param int   $nodeExpiry    Node id used as node expiry index.
     */
    public function put( $cacheKeys, $content, $ttl = null, $subTreeExpiry = 0, $nodeExpiry = 0 )
    {
        if ( !$this->enabled )
            return false;

        $key = $this->cacheKeysToKey( $cacheKeys );
        $lockKey = $this->lockKey( $key );
        $metaKey = $this->metaKey( $key );

        if ( $ttl === null || $ttl <= 0 )
        {
            $ttl = $this->defaultTtl;
        }

        $subTreeExpiry = (int) $subTreeExpiry;
        $nodeExpiry = (int) $nodeExpiry;

        $pipe = $this->redis->multi( Redis::PIPELINE );

        if ( $subTreeExpiry || $nodeExpiry )
        {
            $pipe->hMSet( $metaKey, array(
                'subtree_id' => $subTreeExpiry,
                'node_id'    => $nodeExpiry,
            ) );
            $pipe->expire( $metaKey, $ttl );

            if ( $subTreeExpiry )
            {
                $pipe->sAdd( $this->subtreeIndexKey( $subTreeExpiry ), $key );
                $pipe->expire( $this->subtreeIndexKey( $subTreeExpiry ), $ttl );
            }
            if ( $nodeExpiry )
            {
                $pipe->sAdd( $this->nodeIndexKey( $nodeExpiry ), $key );
                $pipe->expire( $this->nodeIndexKey( $nodeExpiry ), $ttl );
            }
        }

        $pipe->setEx( $key, $ttl, $content );
        $pipe->del( $lockKey );
        $result = $pipe->exec();

        if ( $this->useLocalCache )
        {
            $this->localCache[$key] = $content;
        }

        return ( $result !== false );
    }

    /**
     * Purge a single cache entry by its key identifier.
     */
    public function purge( $cacheKeys )
    {
        if ( !$this->enabled )
            return false;

        $key = $this->cacheKeysToKey( $cacheKeys );
        return $this->purgeByKey( $key );
    }

    /**
     * Purge a single cache entry by its internal key.
     */
    public function purgeByKey( $key )
    {
        if ( !$this->enabled )
            return false;

        $this->redis->del( $key );
        $this->redis->del( $this->lockKey( $key ) );
        $this->redis->del( $this->metaKey( $key ) );

        if ( $this->useLocalCache )
        {
            unset( $this->localCache[$key] );
        }
        return true;
    }

    /**
     * Purge all cache entries associated with a node id.
     */
    public function purgeByNode( $nodeID )
    {
        if ( !$this->enabled )
            return false;

        $nodeID = (int) $nodeID;
        $indexKey = $this->nodeIndexKey( $nodeID );
        $keys = $this->redis->sMembers( $indexKey );

        if ( is_array( $keys ) )
        {
            foreach ( $keys as $key )
            {
                $this->purgeByKey( $key );
            }
        }
        $this->redis->del( $indexKey );
        return true;
    }

    /**
     * Purge all cache entries associated with a subtree id.
     */
    public function purgeBySubTree( $subtreeID )
    {
        if ( !$this->enabled )
            return false;

        $subtreeID = (int) $subtreeID;
        $indexKey = $this->subtreeIndexKey( $subtreeID );
        $keys = $this->redis->sMembers( $indexKey );

        if ( is_array( $keys ) )
        {
            foreach ( $keys as $key )
            {
                $this->purgeByKey( $key );
            }
        }
        $this->redis->del( $indexKey );
        return true;
    }

    /**
     * Delete every key owned by this extension.
     */
    public function flush()
    {
        if ( !$this->enabled )
            return false;

        if ( $this->useLocalCache )
        {
            $this->localCache = array();
        }
        $this->prefetchedNodes = array();
        $this->prefetchedSubtrees = array();

        $iterator = null;
        do
        {
            $keys = $this->redis->scan( $iterator, $this->prefix . '*' );
            if ( is_array( $keys ) && !empty( $keys ) )
            {
                $this->redis->del( $keys );
            }
        } while ( $iterator > 0 );

        return true;
    }

    public function clearAll()
    {
        return $this->flush();
    }

    /**
     * Prefetch every cache entry indexed by a node id using a single MGET call.
     * The results are stored in the request-scoped local cache.
     */
    public function prefetchByNode( $nodeID )
    {
        if ( !$this->enabled )
            return false;

        $nodeID = (int) $nodeID;
        if ( !$nodeID )
            return false;

        $this->prefetchedNodes[$nodeID] = true;
        $indexKey = $this->nodeIndexKey( $nodeID );
        $keys = $this->redis->sMembers( $indexKey );

        if ( !$this->useLocalCache || !is_array( $keys ) || empty( $keys ) )
            return true;

        $values = $this->redis->mGet( $keys );
        foreach ( $keys as $i => $key )
        {
            $this->localCache[$key] = $values[$i];
        }
        return true;
    }

    /**
     * Prefetch every cache entry indexed by a subtree id using a single MGET call.
     */
    public function prefetchBySubTree( $subtreeID )
    {
        if ( !$this->enabled )
            return false;

        $subtreeID = (int) $subtreeID;
        if ( !$subtreeID )
            return false;

        $this->prefetchedSubtrees[$subtreeID] = true;
        $indexKey = $this->subtreeIndexKey( $subtreeID );
        $keys = $this->redis->sMembers( $indexKey );

        if ( !$this->useLocalCache || !is_array( $keys ) || empty( $keys ) )
            return true;

        $values = $this->redis->mGet( $keys );
        foreach ( $keys as $i => $key )
        {
            $this->localCache[$key] = $values[$i];
        }
        return true;
    }

    /**
     * Convert a cache key identifier into a stable Redis key.
     */
    public function cacheKeysToKey( $cacheKeys )
    {
        if ( is_array( $cacheKeys ) )
        {
            ksort( $cacheKeys );
        }
        return $this->prefix . md5( serialize( $cacheKeys ) );
    }

    /**
     * Helpers for the patched content/view.php content view cache path.
     */
    public static function contentViewRetrieve( $cachePath, $timestamp )
    {
        $content = self::instance()->get( $cachePath, false );
        return ( $content === false ) ? null : $content;
    }

    public static function contentViewGenerate( $cachePath, $args )
    {
        $data = eZNodeviewfunctions::contentViewGenerate( false, $args );
        return $data['content'];
    }

    private function lockKey( $key )
    {
        return $key . ':lock';
    }

    private function metaKey( $key )
    {
        return $key . ':meta';
    }

    private function nodeIndexKey( $nodeID )
    {
        return $this->prefix . 'idx:node:' . $nodeID;
    }

    private function subtreeIndexKey( $subtreeID )
    {
        return $this->prefix . 'idx:subtree:' . $subtreeID;
    }

    private function retryGet( $cacheKeys, $tries )
    {
        if ( $tries >= $this->maxRetries )
        {
            eZDebug::writeError( 'Valkey cache lock wait exceeded maximum retries for key ' . $this->cacheKeysToKey( $cacheKeys ), __METHOD__ );
            return false;
        }

        usleep( $this->retryDelay );
        return $this->get( $cacheKeys, true, $tries + 1 );
    }

    /**
     * Build a short, deterministic hash that uniquely identifies this installation.
     *
     * The hash is based on the database name and site name. If those cannot be
     * determined it falls back to the filesystem root and hostname. This makes it
     * safe to run many eZ Publish / Exponential sites on a single Redis/Valkey
     * instance even when the operator forgets to set KeyPrefix manually.
     */
    private function computeInstallationHash()
    {
        $siteIni = eZINI::instance( 'site.ini' );

        $database = $this->iniValue( $siteIni, 'DatabaseSettings', 'Database', '' );
        $siteName = $this->iniValue( $siteIni, 'SiteSettings', 'SiteName', '' );

        $seed = trim( $database ) . '|' . trim( $siteName );
        if ( $seed === '|' )
        {
            $seed = eZSys::hostname() . '|' . eZSys::rootDir();
        }

        // Use SHA-256 (not bcrypt) because cache key generation must be
        // deterministic and fast. bcrypt uses random salts and is too slow here.
        return substr( hash( 'sha256', $seed ), 0, 16 );
    }

    private function iniValue( $ini, $section, $variable, $default )
    {
        if ( $ini->hasVariable( $section, $variable ) )
        {
            $value = $ini->variable( $section, $variable );
            if ( is_array( $value ) )
                $value = reset( $value );
            if ( is_string( $value ) || is_numeric( $value ) )
                return $value;
        }
        return $default;
    }

    private function iniString( $ini, $section, $variable, $default )
    {
        if ( $ini->hasVariable( $section, $variable ) )
        {
            $value = $ini->variable( $section, $variable );
            if ( is_string( $value ) && $value !== '' )
            {
                return $value;
            }
        }
        return $default;
    }

    private function iniInt( $ini, $section, $variable, $default )
    {
        if ( $ini->hasVariable( $section, $variable ) )
        {
            $value = $ini->variable( $section, $variable );
            if ( is_array( $value ) )
                $value = reset( $value );
            if ( is_numeric( $value ) )
                return (int) $value;
        }
        return (int) $default;
    }

    private function __clone()
    {
        trigger_error( 'Cloning is not allowed.', E_USER_ERROR );
    }

    public function __wakeup()
    {
        trigger_error( 'Deserialization is not allowed.', E_USER_ERROR );
    }
}
