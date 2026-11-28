<?php
/**
 * sevenxValkeyCacheBlock::deleteKeys(), which clearBlocks() and flush() use, against a real Valkey or Redis: every key
 * under the prefix goes but the kept ones, and nothing outside the prefix is touched. The keys are written under a
 * prefix of the test's own (with a '*' in it, which must not act as a wildcard) in the database VALKEY_TEST_DB
 * (default 15) and removed at the end.
 *   VALKEY_TEST_HOST=127.0.0.1 [VALKEY_TEST_PORT=6379] [VALKEY_TEST_DB=15] phpunit tests/sevenxValkeyCacheBlockDeleteKeysTest.php
 * Skipped without VALKEY_TEST_HOST or the PHP redis extension.
 *
 * @license http://www.gnu.org/licenses/gpl-2.0.txt GNU General Public License v2 (or any later version)
 */

if ( !class_exists( 'sevenxValkeyCacheBlock' ) )
    require_once __DIR__ . '/../classes/sevenxvalkeycacheblock.php';

class sevenxValkeyCacheBlockDeleteKeysTest extends PHPUnit\Framework\TestCase
{
    /** @var Redis */
    private $redis;
    private $base;

    protected function setUp(): void
    {
        $host = getenv( 'VALKEY_TEST_HOST' );
        if ( !$host || !class_exists( 'Redis' ) )
            $this->markTestSkipped( 'needs VALKEY_TEST_HOST and the PHP redis extension' );
        $this->redis = new Redis();
        try
        {
            $this->redis->connect( $host, (int) ( getenv( 'VALKEY_TEST_PORT' ) ?: 6379 ), 2.0 );
            $this->redis->select( (int) ( getenv( 'VALKEY_TEST_DB' ) ?: 15 ) );
        }
        catch ( Exception $e )
        {
            $this->markTestSkipped( 'Valkey at ' . $host . ' does not answer: ' . $e->getMessage() );
        }
        $this->base = 'svc-test-' . bin2hex( random_bytes( 6 ) ) . ':';
    }

    protected function tearDown(): void
    {
        if ( $this->redis && $this->base )
            sevenxValkeyCacheBlock::deleteKeys( $this->redis, $this->base );
        parent::tearDown();
    }

    public function testClearBlocksKeepsTheIniKeysAndNothingElse()
    {
        $prefix = $this->base . 'abc*:';
        $blocks = array( $prefix . md5( 'view' ), $prefix . md5( 'view' ) . ':meta', $prefix . md5( 'view' ) . ':lock',
                         $prefix . 'idx:node:4563', $prefix . 'idx:subtree:2' );
        $kept = array( $prefix . 'ini', $prefix . 'ini:gabc:' . md5( 'site.ini' ), $prefix . 'inigen:x', $prefix . 'inikeys:x',
                       $this->base . 'abcd:' . md5( 'other installation' ), $this->base . 'abc:' . md5( 'no star' ) );
        foreach ( array_merge( $blocks, $kept ) as $key )
            $this->redis->set( $key, '1' );

        $this->assertSame( count( $blocks ), sevenxValkeyCacheBlock::deleteKeys( $this->redis, $prefix, array( 'ini', 'inikeys', 'inigen' ) ) );
        foreach ( $blocks as $key )
            $this->assertSame( 0, (int) $this->redis->exists( $key ), $key );
        foreach ( $kept as $key )
            $this->assertSame( 1, (int) $this->redis->exists( $key ), $key );
    }

    public function testFlushDeletesEveryKeyUnderThePrefixAcrossManyScanPages()
    {
        $prefix = $this->base . 'many:';
        for ( $i = 0; $i < 2500; $i++ )
            $this->redis->set( $prefix . $i, '1' );
        $this->redis->set( $this->base . 'other', '1' );

        $this->assertSame( 2500, sevenxValkeyCacheBlock::deleteKeys( $this->redis, $prefix ) );
        $this->assertSame( 1, (int) $this->redis->exists( $this->base . 'other' ) );
    }
}
