<?php

declare(strict_types=1);

namespace Allsilaevex\Pool\Test\Integration;

use stdClass;
use Allsilaevex\Pool\Pool;
use PHPUnit\Framework\TestCase;
use Allsilaevex\Pool\PoolConfig;
use Allsilaevex\Pool\PoolItemWrapperFactory;
use PHPUnit\Framework\Attributes\CoversClass;
use Allsilaevex\Pool\PoolItemFactoryInterface;
use Allsilaevex\Pool\TimerTask\TimerTaskSchedulerInterface;

#[CoversClass(Pool::class)]
class HasBoundItemTest extends TestCase
{
    public function testHasItemIsFalseWhenNotBound(): void
    {
        \Swoole\Coroutine\go(function () {
            $pool = $this->createSimplePool(bindToCoroutine: false);

            static::assertFalse($pool->hasBoundItem());

            $item = $pool->borrow();
            static::assertFalse($pool->hasBoundItem());

            $pool->return($item);
        });
    }

    public function testHasItemIsTrueWhenBound(): void
    {
        \Swoole\Coroutine\go(function () {
            $pool = $this->createSimplePool(bindToCoroutine: true);

            static::assertFalse($pool->hasBoundItem());

            $item = $pool->borrow();
            static::assertTrue($pool->hasBoundItem());

            $pool->return($item);
            static::assertFalse($pool->hasBoundItem());
        });
    }

    public function testNestedBorrowingWithHasItemPattern(): void
    {
        \Swoole\Coroutine\go(function () {
            $pool = $this->createSimplePool(bindToCoroutine: true);

            $withConnection = static function (callable $f) use ($pool) {
                $hasConnection = $pool->hasBoundItem();
                $connection = $pool->borrow();

                try {
                    return $f($connection);
                } finally {
                    // Only return if we didn't have it before (i.e. we are the ones who borrowed it)
                    if (!$hasConnection) {
                        $pool->return($connection);
                    }
                }
            };

            // Outer scope
            $withConnection(static function (stdClass $conn1) use ($withConnection) {
                $conn1->id = 'outer';

                // Inner scope
                $withConnection(static function (stdClass $conn2) use ($conn1) {
                    // Should be same connection
                    if ($conn1 !== $conn2) {
                        throw new \RuntimeException('Expected same connection');
                    }
                    $conn2->id = 'inner';
                });

                // Back in outer scope
                // Connection should still be valid and not returned to pool
                /** @phpstan-ignore-next-line */
                if ($conn1->id !== 'inner') {
                    throw new \RuntimeException('Connection state lost');
                }
            });

            // After all, connection should be returned
            static::assertEquals(1, $pool->getIdleCount());
        });
    }

    /**
     * @return Pool<stdClass>
     */
    protected function createSimplePool(bool $bindToCoroutine): Pool
    {
        $factory = new /**
         * @implements PoolItemFactoryInterface<stdClass>
         */ class() implements PoolItemFactoryInterface {
            public function create(): mixed
            {
                return new stdClass();
            }

            public function destroy(mixed $item): void
            {
            }
        };

        $timerTaskSchedulerMock = $this->createMock(TimerTaskSchedulerInterface::class);

        return new Pool(
            name: 'test',
            config: new PoolConfig(size: 1, borrowingTimeoutSec: .1, returningTimeoutSec: .1, bindToCoroutine: $bindToCoroutine),
            poolItemWrapperFactory: new PoolItemWrapperFactory(
                factory: $factory,
                poolItemTimerTaskScheduler: $timerTaskSchedulerMock,
            ),
        );
    }
}
