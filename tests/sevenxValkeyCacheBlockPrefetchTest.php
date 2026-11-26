<?php
//
// Sevenx Valkey Cache extension for Exponential
// Copyright (C) 1998 - 2026 Exponential Foundation and 7x; All rights reserved. (https://se7enx.com)
// GNU General Public License v2.0 (or any later version)
//
// 1.0.2.4: a block with node or subtree expiry prefetches the node's other entries with one MGET only when the index
// is small (PrefetchMaxKeys); se7enx.com sent MGETs of 2 300 keys that blocked Valkey to serve one block.
//

require_once __DIR__ . '/../classes/sevenxvalkeycacheblock.php';

class sevenxValkeyCacheBlockPrefetchTest extends PHPUnit\Framework\TestCase
{
    public function testASmallIndexIsPrefetched()
    {
        $this->assertTrue( sevenxValkeyCacheBlock::shouldPrefetch( 1, 32 ) );
        $this->assertTrue( sevenxValkeyCacheBlock::shouldPrefetch( 32, 32 ) );
    }

    public function testALargeIndexIsNot()
    {
        $this->assertFalse( sevenxValkeyCacheBlock::shouldPrefetch( 33, 32 ) );
        $this->assertFalse( sevenxValkeyCacheBlock::shouldPrefetch( 2318, 32 ) );
    }

    public function testAnEmptyIndexOrZeroMaxPrefetchesNothing()
    {
        $this->assertFalse( sevenxValkeyCacheBlock::shouldPrefetch( 0, 32 ) );
        $this->assertFalse( sevenxValkeyCacheBlock::shouldPrefetch( 5, 0 ) );
        $this->assertFalse( sevenxValkeyCacheBlock::shouldPrefetch( false, 32 ) );
    }
}
