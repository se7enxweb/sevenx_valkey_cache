<?php
//
// Sevenx Valkey Cache extension for Exponential
// Copyright (C) 1998 - 2026 Exponential Foundation and 7x. All rights reserved. (https://se7enx.com)
// GNU General Public License v2.0 (or any later version)
//
// sevenxValkeyINICache::clear() removes every compiled INI hash of THIS installation (with InstallationHash=auto each
// web host has a key of its own) and nothing else: no other installation's keys, nothing outside the key prefix, and
// never by scanning. No Valkey server: an in-memory stand-in for the connection is put in with reflection.
//   phpunit tests/sevenxValkeyINICacheClearTest.php      (needs the PHP redis extension for the class's constants)
//

require_once __DIR__ . '/../classes/sevenxvalkeyinicache.php';

/** The connection methods the INI cache uses, on an array; counts SCAN/KEYS calls to prove none are made. */
class sevenxValkeyINICacheClearTestRedis
{
    public $data = array();
    public $scans = 0;
    public function hGet( $k, $f ) { return isset( $this->data[$k][$f] ) ? $this->data[$k][$f] : false; }
    public function hSet( $k, $f, $v ) { $this->data[$k][$f] = $v; return 1; }
    public function hDel( $k, $f ) { unset( $this->data[$k][$f] ); return 1; }
    public function expire( $k, $t ) { return true; }
    public function sAdd( $k, $m ) { $this->data[$k][$m] = true; return 1; }
    public function sMembers( $k ) { return isset( $this->data[$k] ) ? array_keys( $this->data[$k] ) : array(); }
    public function del( $k ) { $had = isset( $this->data[$k] ); unset( $this->data[$k] ); return $had ? 1 : 0; }
    public function scan( &$it, $pattern = null ) { $this->scans++; return false; }
    public function keys( $pattern ) { $this->scans++; return array(); }
}

class sevenxValkeyINICacheClearTest extends PHPUnit\Framework\TestCase
{
    private $redis;

    protected function setUp(): void
    {
        if ( !class_exists( 'Redis' ) )
            $this->markTestSkipped( 'the PHP redis extension is not loaded' );
        $this->redis = new sevenxValkeyINICacheClearTestRedis();
    }

    /** One web host's view of this installation: its own hash key (prefix) on the shared connection. */
    private function host( $installationHash, $enabled = true )
    {
        $r = new ReflectionClass( 'sevenxValkeyINICache' );
        $c = $r->newInstanceWithoutConstructor();
        foreach ( array( 'redis' => $this->redis, 'enabled' => $enabled, 'prefix' => 'sevenx_valkey_cache:' . $installationHash . ':',
                         'basePrefix' => 'sevenx_valkey_cache:', 'ttl' => 3600, 'localCache' => array() ) as $p => $v )
        {
            $prop = $r->getProperty( $p );
            if ( PHP_VERSION_ID < 80100 ) $prop->setAccessible( true );
            $prop->setValue( $c, $v );
        }
        return $c;
    }

    public function testClearRemovesTheIniKeysOfEveryWebHostOfThisInstallation()
    {
        $a = $this->host( 'aaaaaaaaaaaaaaaa' );
        $b = $this->host( 'bbbbbbbbbbbbbbbb' );
        $this->assertTrue( $a->save( '/var/cache/ini/site.php', array( 'v' => 1 ) ) );
        $this->assertTrue( $b->save( '/var/cache/ini/site.php', array( 'v' => 2 ) ) );
        $this->assertArrayHasKey( 'sevenx_valkey_cache:aaaaaaaaaaaaaaaa:ini', $this->redis->data );
        $this->assertArrayHasKey( 'sevenx_valkey_cache:bbbbbbbbbbbbbbbb:ini', $this->redis->data );

        // the CLI (another host name) clears: both web hosts' keys go
        $this->assertTrue( $this->host( 'cccccccccccccccc' )->clear() );
        $this->assertArrayNotHasKey( 'sevenx_valkey_cache:aaaaaaaaaaaaaaaa:ini', $this->redis->data );
        $this->assertArrayNotHasKey( 'sevenx_valkey_cache:bbbbbbbbbbbbbbbb:ini', $this->redis->data );
        $this->assertSame( 0, $this->redis->scans, 'clear() must never scan the shared key prefix' );
    }

    public function testClearLeavesOtherInstallationsAndOtherCachesAlone()
    {
        $this->redis->data['sevenx_valkey_cache:ffffffffffffffff:ini'] = array( 'x' => 1 );        // another installation
        $this->redis->data['sevenx_valkey_cache:aaaaaaaaaaaaaaaa:view:42'] = array( 'x' => 1 );    // this one's view cache
        $this->redis->data['PHPREDIS_SESSION:abc'] = array( 'x' => 1 );                            // a session
        $this->host( 'aaaaaaaaaaaaaaaa' )->save( 'site.php', array( 'v' => 1 ) );
        $this->host( 'aaaaaaaaaaaaaaaa' )->clear();
        $this->assertArrayHasKey( 'sevenx_valkey_cache:ffffffffffffffff:ini', $this->redis->data );
        $this->assertArrayHasKey( 'sevenx_valkey_cache:aaaaaaaaaaaaaaaa:view:42', $this->redis->data );
        $this->assertArrayHasKey( 'PHPREDIS_SESSION:abc', $this->redis->data );
    }

    public function testAnIndexEntryOutsideThePrefixOrNotAnIniKeyIsNeverDeleted()
    {
        $c = $this->host( 'aaaaaaaaaaaaaaaa' );
        $c->save( 'site.php', array( 'v' => 1 ) );
        $r = new ReflectionMethod( 'sevenxValkeyINICache', 'indexKey' );
        if ( PHP_VERSION_ID < 80100 ) $r->setAccessible( true );
        $index = $r->invoke( $c );
        $this->redis->data['PHPREDIS_SESSION:abc'] = array( 'x' => 1 );
        $this->redis->data['sevenx_valkey_cache:aaaaaaaaaaaaaaaa:view:42'] = array( 'x' => 1 );
        $this->redis->sAdd( $index, 'PHPREDIS_SESSION:abc' );
        $this->redis->sAdd( $index, 'sevenx_valkey_cache:aaaaaaaaaaaaaaaa:view:42' );
        $c->clear();
        $this->assertArrayHasKey( 'PHPREDIS_SESSION:abc', $this->redis->data );
        $this->assertArrayHasKey( 'sevenx_valkey_cache:aaaaaaaaaaaaaaaa:view:42', $this->redis->data );
        $this->assertArrayNotHasKey( $index, $this->redis->data );
    }

    public function testAFixedInstallationHashHasOneKeyAndItGoes()
    {
        $c = $this->host( 'se7enxcomcluster' );
        $c->save( 'site.php', array( 'v' => 1 ) );
        $c->clear();
        $this->assertArrayNotHasKey( 'sevenx_valkey_cache:se7enxcomcluster:ini', $this->redis->data );
    }

    public function testADisabledCacheClearsNothing()
    {
        $this->redis->data['sevenx_valkey_cache:aaaaaaaaaaaaaaaa:ini'] = array( 'x' => 1 );
        $this->assertFalse( $this->host( 'aaaaaaaaaaaaaaaa', false )->clear() );
        $this->assertArrayHasKey( 'sevenx_valkey_cache:aaaaaaaaaaaaaaaa:ini', $this->redis->data );
    }
}
