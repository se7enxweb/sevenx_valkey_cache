<?php
/**
 * sevenxValkeyCacheClearListener: what the kernel's cache clears (eZCache, eZContentCacheManager) clear in Valkey.
 * DB-less and Valkey-less: stand-ins for the stores record the calls.
 *   phpunit tests/sevenxValkeyCacheClearListenerTest.php
 *
 * @license http://www.gnu.org/licenses/gpl-2.0.txt GNU General Public License v2 (or any later version)
 */

if ( !interface_exists( 'expCacheClearListener' ) )
{
    /** The kernel's interface (Exponential 6.0.15), for a run without the kernel */
    interface expCacheClearListener
    {
        public function cacheItemCleared( array $cacheItem );
        public function nodeCachesCleared( array $nodeIDList );
    }
}
if ( !class_exists( 'sevenxValkeyCacheClearListener' ) )
    require_once __DIR__ . '/../classes/sevenxvalkeycacheclearlistener.php';

/** Records what a listener asks of a store; $enabled false is a store without Valkey */
class sevenxValkeyCacheClearListenerTestStore
{
    public $enabled = true;
    public $calls = array();
    public function isEnabled() { return $this->enabled; }
    public function clear() { $this->calls[] = 'clear'; return true; }
    public function clearBlocks() { $this->calls[] = 'clearBlocks'; return true; }
    public function generateNodeListCache( $nodeList ) { $this->calls[] = array( 'nodes', $nodeList ); }
}

class sevenxValkeyCacheClearListenerTest extends PHPUnit\Framework\TestCase
{
    private $blocks;
    private $nodes;
    private $listener;

    protected function setUp(): void
    {
        $this->blocks = new sevenxValkeyCacheClearListenerTestStore();
        $this->nodes = new sevenxValkeyCacheClearListenerTestStore();
        $this->listener = new sevenxValkeyCacheClearListener( $this->blocks, $this->nodes );
    }

    private static function item( $id )
    {
        return array( 'id' => $id, 'name' => $id, 'tag' => array(), 'purge' => false );
    }

    public function testItIsTheKernelsListener()
    {
        $this->assertInstanceOf( 'expCacheClearListener', $this->listener );
    }

    public function testTheContentAndTemplateBlockCachesClearTheBlocks()
    {
        $this->listener->cacheItemCleared( self::item( 'content' ) );
        $this->listener->cacheItemCleared( self::item( 'template-block' ) );
        $this->assertSame( array( 'clearBlocks', 'clearBlocks' ), $this->blocks->calls );
    }

    public function testAPurgeClearsLikeAClear()
    {
        $item = self::item( 'content' );
        $item['purge'] = true;
        $this->listener->cacheItemCleared( $item );
        $this->assertSame( array( 'clearBlocks' ), $this->blocks->calls );
    }

    public function testOtherCachesLeaveValkeyAlone()
    {
        foreach ( array( 'ini', 'global_ini', 'template', 'template-override', 'classid', 'translation', 'user_info_cache', 'exphttpcache', '' ) as $id )
            $this->listener->cacheItemCleared( self::item( $id ) );
        $this->listener->cacheItemCleared( array() );
        $this->assertSame( array(), $this->blocks->calls );
        $this->assertSame( array(), $this->nodes->calls );
    }

    public function testTheNodesOfAContentChangeArePurgedByTheirIndexes()
    {
        $this->listener->nodeCachesCleared( array( 4563, 1268, 2, 1 ) );
        $this->assertSame( array( array( 'nodes', array( 4563, 1268, 2, 1 ) ) ), $this->nodes->calls );
    }

    public function testNoNodesNoCall()
    {
        $this->listener->nodeCachesCleared( array() );
        $this->assertSame( array(), $this->nodes->calls );
    }

    public function testWithoutValkeyNothingIsCalled()
    {
        $this->blocks->enabled = false;
        $this->listener->cacheItemCleared( self::item( 'content' ) );
        $this->listener->nodeCachesCleared( array( 4563 ) );
        $this->assertSame( array(), $this->blocks->calls );
        $this->assertSame( array(), $this->nodes->calls );
    }
}
