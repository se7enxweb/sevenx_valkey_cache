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
  \class sevenxValkeyCacheHandler sevenxvalkeycachehandler.php
  \brief Static cache handler that purges Redis/Valkey cached content on node changes.

  Registered as ContentSettings.StaticCacheHandler, this class is invoked by
  eZContentCacheManager whenever content changes. It purges view cache and
  cache block entries stored in Redis/Valkey using the reverse node and subtree
  indexes maintained by sevenxValkeyCacheBlock.
*/

class sevenxValkeyCacheHandler implements ezpStaticCache
{
    /**
     * Purges Redis/Valkey cache entries related to the supplied node list.
     *
     * @param array $nodeList List of node ids that changed.
     */
    public function generateNodeListCache( $nodeList )
    {
        if ( empty( $nodeList ) || !is_array( $nodeList ) )
        {
            return;
        }

        $cache = sevenxValkeyCacheBlock::instance();
        if ( !$cache->isEnabled() )
        {
            return;
        }

        // Purge by every node in the path up to (and including) the root node.
        // This covers cache blocks that use subtree_expiry on any ancestor.
        foreach ( $this->getNodePath( $nodeList ) as $nodeID )
        {
            $cache->purgeBySubTree( $nodeID );
        }

        // Purge by the exact nodes that changed. This covers view caches and
        // cache blocks that use node_expiry.
        foreach ( $nodeList as $nodeID )
        {
            $cache->purgeByNode( $nodeID );
        }
    }

    /**
     * Extract the path from the supplied node list, stopping at the root node.
     */
    private function getNodePath( $nodeList )
    {
        $return = array();
        foreach ( $nodeList as $node )
        {
            $return[] = $node;
            if ( $node == 1 )
            {
                break;
            }
        }
        return $return;
    }

    public function generateAlwaysUpdatedCache( $quiet = false, $cli = false, $delay = true )
    {
    }

    public function generateCache( $force = false, $quiet = false, $cli = false, $delay = true )
    {
    }

    public function cacheURL( $url, $nodeID = false, $skipExisting = false, $delay = true )
    {
        return true;
    }

    public function removeURL( $url )
    {
        return true;
    }

    public static function executeActions()
    {
    }
}
