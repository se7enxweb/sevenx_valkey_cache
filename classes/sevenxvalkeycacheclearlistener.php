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
  \class sevenxValkeyCacheClearListener sevenxvalkeycacheclearlistener.php
  \brief Clears this installation's Valkey caches whenever the kernel clears its own caches.

  Registered in this extension's site.ini.append.php ([Cache] ClearListeners[]), so the kernel's eZCache calls it
  for every cache it clears: bin/php/ezcache.php (--clear-id, --clear-tag, --clear-all, --purge), Setup > Cache in
  the administration interface and the cache steps of exp:velocity deploy. eZContentCacheManager calls it for the
  nodes a content change touches (publish, move, hide ...), whatever the static cache handler is.

  - content, template-block: the content view caches and {valkey-block} entries of the installation. Both live in
    the same key space and cannot be told apart, so both go; the INI arrays stay.
  - the nodes of a content change: their view caches and the entries with node_expiry, through the node index, and
    the entries with subtree_expiry on any of them (sevenxValkeyCacheHandler).

  The compiled INI arrays are not cleared here: the kernel clears them itself with its ini and global_ini caches
  (eZCache::clearValkeyINICache()), as eZINI reads them by the class's name and this listener is found in the
  settings that very cache holds.

  Needs Exponential 6.0.15 or later with the kernel's expCacheClearListener (an older kernel never loads this class).
  Without the PHP redis extension, or with Valkey unreachable, the stores report themselves disabled and nothing is
  cleared: the kernel's clear goes on, with a warning.
*/

class sevenxValkeyCacheClearListener implements expCacheClearListener
{
    /** Cache ids of eZCache whose clear also clears the view cache and cache-block entries in Valkey */
    const BLOCK_CACHE_IDS = array( 'content', 'template-block' );

    /** @var object|null the view cache and cache-block store (sevenxValkeyCacheBlock), null: the singleton */
    private $blocks;
    /** @var object|null clears by node (sevenxValkeyCacheHandler), null: a new one */
    private $nodes;

    /**
     * The kernel creates the listener without arguments; tests pass stand-ins for the stores.
     */
    public function __construct( $blocks = null, $nodes = null )
    {
        $this->blocks = $blocks;
        $this->nodes = $nodes;
    }

    /**
     * @param array $cacheItem an item of eZCache::fetchList(), with 'purge' set
     */
    public function cacheItemCleared( array $cacheItem )
    {
        $id = isset( $cacheItem['id'] ) ? (string) $cacheItem['id'] : '';
        if ( in_array( $id, self::BLOCK_CACHE_IDS, true ) )
        {
            $blocks = $this->blockStore();
            if ( $blocks !== null )
                $blocks->clearBlocks();
        }
    }

    /**
     * @param array $nodeIDList the node ids a content change touched, as eZContentCacheManager hands them to the
     *        static cache handler
     */
    public function nodeCachesCleared( array $nodeIDList )
    {
        if ( empty( $nodeIDList ) || $this->blockStore() === null )
            return;
        // A site with this extension's handler as its static cache handler had it called already by the kernel
        if ( $this->nodes === null && self::isStaticCacheHandler() )
            return;
        $nodes = $this->nodes !== null ? $this->nodes : new sevenxValkeyCacheHandler();
        $nodes->generateNodeListCache( $nodeIDList );
    }

    /** The block store when it is usable, else null (no PHP redis extension, Valkey not answering) */
    private function blockStore()
    {
        $store = $this->blocks !== null ? $this->blocks : sevenxValkeyCacheBlock::instance();
        return $store->isEnabled() ? $store : null;
    }

    /** Whether sevenxValkeyCacheHandler is the site's static cache handler, which the kernel calls on its own */
    private static function isStaticCacheHandler()
    {
        $ini = eZINI::instance();
        return $ini->variable( 'ContentSettings', 'StaticCache' ) === 'enabled'
            && strcasecmp( (string) $ini->variable( 'ContentSettings', 'StaticCacheHandler' ), 'sevenxValkeyCacheHandler' ) === 0;
    }
}
