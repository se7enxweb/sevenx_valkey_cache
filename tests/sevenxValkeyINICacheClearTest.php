<?php
//
// Sevenx Valkey Cache extension for Exponential
// Copyright (C) 1998 - 2026 Exponential Foundation and 7x; All rights reserved. (https://se7enx.com)
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
    public $calls = array();
    public function get( $k ) { $this->calls[] = 'get'; return isset( $this->data[$k] ) ? $this->data[$k] : false; }
    public function set( $k, $v, $o = null ) { $this->calls[] = 'set'; $this->data[$k] = $v; $this->ttls[$k] = is_array( $o ) && isset( $o['ex'] ) ? $o['ex'] : null; return true; }
    public $ttls = array();
    public function incr( $k ) { $this->calls[] = 'incr'; $this->data[$k] = ( isset( $this->data[$k] ) ? (int)$this->data[$k] : 0 ) + 1; return $this->data[$k]; }
    public function hGet( $k, $f ) { $this->calls[] = 'hget'; return isset( $this->data[$k][$f] ) ? $this->data[$k][$f] : false; }
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
                         'basePrefix' => 'sevenx_valkey_cache:', 'ttl' => 3600, 'localCache' => array(), 'apcu' => false,
                         'iniTTL' => 21600, 'iniJitter' => 3600 ) as $p => $v )
        {
            $prop = $r->getProperty( $p );
            if ( PHP_VERSION_ID < 80100 ) $prop->setAccessible( true );
            $prop->setValue( $c, $v );
        }
        return $c;
    }

    public function testClearRemovesTheIniKeysOfEveryWebHostOfThisInstallation()
    {
        // the hashes the versions before 1.0.2.3 wrote, one per web host, listed in the installation's index set
        $a = $this->host( 'aaaaaaaaaaaaaaaa' );
        $r = new ReflectionMethod( 'sevenxValkeyINICache', 'indexKey' );
        if ( PHP_VERSION_ID < 80100 ) $r->setAccessible( true );
        foreach ( array( 'aaaaaaaaaaaaaaaa', 'bbbbbbbbbbbbbbbb' ) as $h )
        {
            $this->redis->data['sevenx_valkey_cache:' . $h . ':ini'][md5( '/var/cache/ini/site.php' )] = array( 'v' => $h );
            $this->redis->sAdd( $r->invoke( $a ), 'sevenx_valkey_cache:' . $h . ':ini' );
        }
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

    // 1.0.2.3: one round trip per request, a generation, jittered TTLs, one copy for every host

    public function testARequestReadsTheGenerationOnceAndEveryArrayAfterThatFromItsOwnCopy()
    {
        $_SERVER['REQUEST_TIME_FLOAT'] = '1000.1';
        $c = $this->host( 'aaaaaaaaaaaaaaaa' );
        for ( $i = 0; $i < 31; $i++ )
            $c->save( "/var/cache/ini/f$i.php", array( 'v' => $i ) );
        $_SERVER['REQUEST_TIME_FLOAT'] = '1000.2';                       // the next request, in the same process
        $this->redis->calls = array();
        $c = $this->host( 'aaaaaaaaaaaaaaaa' );
        for ( $i = 0; $i < 31; $i++ )
            $this->assertSame( array( 'v' => $i ), $c->load( "/var/cache/ini/f$i.php" ) );
        $this->assertSame( 32, count( array_keys( $this->redis->calls, 'get', true ) ), 'the generation and the 31 entries: APCu is off in this test' );
        $this->assertSame( 'get', $this->redis->calls[0], 'the generation is read first' );
        $this->assertNotContains( 'hget', $this->redis->calls, 'no per-file hash lookups' );
    }

    public function testWithApcuARequestMakesOneRoundTripForAllItsArrays()
    {
        if ( !function_exists( 'apcu_enabled' ) || !apcu_enabled() )
            $this->markTestSkipped( 'APCu is not usable here (apc.enable_cli)' );
        $prefix = 'apcu' . bin2hex( random_bytes( 4 ) );
        $_SERVER['REQUEST_TIME_FLOAT'] = '1500.1';
        $c = $this->host( $prefix );
        $this->setProp( $c, 'apcu', true );
        for ( $i = 0; $i < 31; $i++ )
            $c->save( "/var/cache/ini/f$i.php", array( 'v' => $i ) );
        $_SERVER['REQUEST_TIME_FLOAT'] = '1500.2';
        $this->redis->calls = array();
        $c = $this->host( $prefix );
        $this->setProp( $c, 'apcu', true );
        for ( $i = 0; $i < 31; $i++ )
            $this->assertSame( array( 'v' => $i ), $c->load( "/var/cache/ini/f$i.php" ) );
        $this->assertSame( array( 'get' ), $this->redis->calls, 'one GET (the generation), every array from APCu' );
    }

    private function setProp( $o, $name, $value )
    {
        $p = new ReflectionProperty( 'sevenxValkeyINICache', $name );
        if ( PHP_VERSION_ID < 80100 ) $p->setAccessible( true );
        $p->setValue( $o, $value );
    }

    public function testWithoutAReadHostReadsGoToThePrimary()
    {
        $c = $this->host( 'aaaaaaaaaaaaaaaa' );
        $m = new ReflectionMethod( 'sevenxValkeyINICache', 'reader' );
        if ( PHP_VERSION_ID < 80100 ) $m->setAccessible( true );
        $this->assertSame( $this->redis, $m->invoke( $c ) );
    }

    public function testAReadHostThatDoesNotAnswerFallsBackToThePrimary()
    {
        $c = $this->host( 'aaaaaaaaaaaaaaaa' );
        $this->setProp( $c, 'readHost', '127.0.0.1' );
        $this->setProp( $c, 'readPort', 1 );                               // nothing listens there
        $this->setProp( $c, 'connectArgs', array( '127.0.0.1', 6379, 0, false ) );
        $m = new ReflectionMethod( 'sevenxValkeyINICache', 'reader' );
        if ( PHP_VERSION_ID < 80100 ) $m->setAccessible( true );
        $this->assertSame( $this->redis, $m->invoke( $c ) );
    }

    public function testAClearRaisesTheGenerationSoNoCopyOfTheOldArraysIsServed()
    {
        $_SERVER['REQUEST_TIME_FLOAT'] = '2000.1';
        $c = $this->host( 'aaaaaaaaaaaaaaaa' );
        $c->save( '/var/cache/ini/site.php', array( 'v' => 'old' ) );
        $before = $c->generation();
        $this->assertTrue( sevenxValkeyINICache::validGeneration( $before ) );
        $c->clear();
        $_SERVER['REQUEST_TIME_FLOAT'] = '2000.2';
        $c = $this->host( 'aaaaaaaaaaaaaaaa' );
        $this->assertNotSame( $before, $c->generation() );
        $this->assertFalse( $c->load( '/var/cache/ini/site.php' ), 'the array of the old generation is gone for this installation' );
    }

    public function testADeletedGenerationNeverBringsOldArraysBack()
    {
        // 1.0.2.5: a flush of the key prefix deleted the counter; it started again at 0 and met the arrays of before
        $_SERVER['REQUEST_TIME_FLOAT'] = '2100.1';
        $c = $this->host( 'aaaaaaaaaaaaaaaa' );
        $c->save( '/var/cache/ini/site.php', array( 'v' => 'old' ) );
        $g = new ReflectionMethod( 'sevenxValkeyINICache', 'generationKey' );
        if ( PHP_VERSION_ID < 80100 ) $g->setAccessible( true );
        unset( $this->redis->data[$g->invoke( $c )] );
        $_SERVER['REQUEST_TIME_FLOAT'] = '2100.2';
        $c = $this->host( 'aaaaaaaaaaaaaaaa' );
        $this->assertFalse( $c->load( '/var/cache/ini/site.php' ) );
    }

    public function testGenerationsAreTokensNotCounters()
    {
        $a = sevenxValkeyINICache::newGeneration();
        $this->assertTrue( sevenxValkeyINICache::validGeneration( $a ) );
        $this->assertNotSame( $a, sevenxValkeyINICache::newGeneration() );
        foreach ( array( '0', '1', '13', '', false, null, 'x' ) as $old )
            $this->assertFalse( sevenxValkeyINICache::validGeneration( $old ) );
    }

    public function testPerHostAClearFromTheCommandLineReachesEveryWebHost()
    {
        $_SERVER['REQUEST_TIME_FLOAT'] = '2500.1';
        $web = $this->host( 'aaaaaaaaaaaaaaaa' );
        $web->save( '/var/cache/ini/site.php', array( 'v' => 'old' ) );
        $this->host( 'cccccccccccccccc' )->clear();                        // the CLI: another host name, another prefix
        $_SERVER['REQUEST_TIME_FLOAT'] = '2500.2';
        $web = $this->host( 'aaaaaaaaaaaaaaaa' );
        $this->assertFalse( $web->load( '/var/cache/ini/site.php' ) );
    }

    public function testADeleteRaisesTheGenerationToo()
    {
        $_SERVER['REQUEST_TIME_FLOAT'] = '3000.1';
        $c = $this->host( 'aaaaaaaaaaaaaaaa' );
        $c->save( '/var/cache/ini/site.php', array( 'v' => 1 ) );
        $c->save( '/var/cache/ini/content.php', array( 'v' => 2 ) );
        $c->delete( '/var/cache/ini/site.php' );
        $_SERVER['REQUEST_TIME_FLOAT'] = '3000.2';
        $c = $this->host( 'aaaaaaaaaaaaaaaa' );
        $this->assertFalse( $c->load( '/var/cache/ini/site.php' ) );
        $this->assertFalse( $c->load( '/var/cache/ini/content.php' ), 'other nodes may hold copies of the old generation: all of it goes' );
    }

    public function testTheHashOfTheVersionsBeforeIsNeverRead()
    {
        $_SERVER['REQUEST_TIME_FLOAT'] = '4000.1';
        $this->redis->data['sevenx_valkey_cache:aaaaaaaaaaaaaaaa:ini'][md5( '/var/cache/ini/site.php' )] = array( 'v' => 'legacy' );
        $c = $this->host( 'aaaaaaaaaaaaaaaa' );
        $this->assertFalse( $c->load( '/var/cache/ini/site.php' ), 'it holds arrays of before the last clear' );
    }

    public function testEveryEntryHasAJitteredTtlWithinItsBounds()
    {
        $_SERVER['REQUEST_TIME_FLOAT'] = '5000.1';
        $c = $this->host( 'aaaaaaaaaaaaaaaa' );
        for ( $i = 0; $i < 50; $i++ )
            $c->save( "/var/cache/ini/j$i.php", array( 'v' => $i ) );
        $ttls = array_values( array_filter( $this->redis->ttls ) );
        $this->assertCount( 50, $ttls );
        $this->assertGreaterThanOrEqual( 21600, min( $ttls ) );
        $this->assertLessThanOrEqual( 21600 + 3600, max( $ttls ) );
        $this->assertGreaterThan( 1, count( array_unique( $ttls ) ), 'the entries written together do not expire together' );
        $this->assertSame( 3600, sevenxValkeyINICache::ttlWithJitter( 3600, 0 ) );
        $this->assertSame( 3700, sevenxValkeyINICache::ttlWithJitter( 3600, 600, 100 ) );
        $this->assertSame( 4200, sevenxValkeyINICache::ttlWithJitter( 3600, 600, 9999 ) );
    }

    public function testSharedAcrossHostsTheHostNameIsNoPartOfTheKey()
    {
        $m = new ReflectionMethod( 'sevenxValkeyINICache', 'computeInstallationHash' );
        if ( PHP_VERSION_ID < 80100 ) $m->setAccessible( true );
        $c = ( new ReflectionClass( 'sevenxValkeyINICache' ) )->newInstanceWithoutConstructor();
        $_SERVER['HTTP_HOST'] = 'centre.se7enx.com';
        $a = $m->invoke( $c, true );
        $perHostA = $m->invoke( $c, false );
        $_SERVER['HTTP_HOST'] = 'store.se7enx.com';
        $this->assertSame( $a, $m->invoke( $c, true ) );
        $this->assertNotSame( $perHostA, $m->invoke( $c, false ), 'per host (the default) as before' );
        unset( $_SERVER['HTTP_HOST'] );
    }

    public function testADisabledCacheClearsNothing()
    {
        $this->redis->data['sevenx_valkey_cache:aaaaaaaaaaaaaaaa:ini'] = array( 'x' => 1 );
        $this->assertFalse( $this->host( 'aaaaaaaaaaaaaaaa', false )->clear() );
        $this->assertArrayHasKey( 'sevenx_valkey_cache:aaaaaaaaaaaaaaaa:ini', $this->redis->data );
    }
}
