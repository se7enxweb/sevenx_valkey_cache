<?php
/**
 * The lock wait of sevenxValkeyCacheBlock (valkeycache.ini LockWait / LockRetryDelay): how many retries, how far apart.
 * DB-less and Valkey-less: only the static calculation is tested.
 *   phpunit tests/sevenxValkeyCacheBlockLockWaitTest.php
 *
 * @license http://www.gnu.org/licenses/gpl-2.0.txt GNU General Public License v2 (or any later version)
 */

if ( !class_exists( 'sevenxValkeyCacheBlock' ) )
    require_once __DIR__ . '/../classes/sevenxvalkeycacheblock.php';

class sevenxValkeyCacheBlockLockWaitTest extends PHPUnit\Framework\TestCase
{
    public function testDefaultWaitsTwoSecondsInTenthSecondSteps()
    {
        $this->assertSame( array( 20, 100000 ), sevenxValkeyCacheBlock::lockWaitRetries( 2000, 100 ) );
    }

    public function testOldBehaviourIsTenSeconds()
    {
        $this->assertSame( array( 100, 100000 ), sevenxValkeyCacheBlock::lockWaitRetries( 10000, 100 ) );
    }

    public function testZeroWaitMeansNoRetry()
    {
        $this->assertSame( array( 0, 100000 ), sevenxValkeyCacheBlock::lockWaitRetries( 0, 100 ) );
    }

    public function testPartialStepRoundsUp()
    {
        $this->assertSame( array( 7, 300000 ), sevenxValkeyCacheBlock::lockWaitRetries( 2000, 300 ) );
    }

    public function testNonsenseValuesAreSafe()
    {
        $this->assertSame( array( 0, 100000 ), sevenxValkeyCacheBlock::lockWaitRetries( -5, 100 ) );
        $this->assertSame( array( 2000, 1000 ), sevenxValkeyCacheBlock::lockWaitRetries( 2000, 0 ) );
    }
}
