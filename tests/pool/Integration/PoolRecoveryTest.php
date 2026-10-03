<?php

declare(strict_types=1);

namespace Allsilaevex\Pool\Test\Integration;

use Throwable;
use WeakReference;
use RuntimeException;
use Psr\Log\NullLogger;
use Allsilaevex\Pool\Pool;
use Psr\Log\LoggerInterface;
use Swoole\Coroutine\Channel;
use PHPUnit\Framework\TestCase;
use Allsilaevex\Pool\PoolConfig;
use Allsilaevex\Pool\PoolMetrics;
use Allsilaevex\Pool\PoolItemState;
use Allsilaevex\Pool\PoolItemWrapper;
use PHPUnit\Framework\Attributes\UsesClass;
use Allsilaevex\Pool\PoolItemWrapperFactory;
use PHPUnit\Framework\Attributes\CoversClass;
use Allsilaevex\Pool\Hook\PoolItemHookManager;
use Allsilaevex\Pool\PoolItemFactoryInterface;
use Allsilaevex\Pool\PoolItemWrapperInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use Allsilaevex\Pool\Test\Fixture\RecoveryItem;
use Allsilaevex\Pool\Hook\PoolItemHookInterface;
use Allsilaevex\Pool\TimerTask\TimerTaskScheduler;
use Allsilaevex\Pool\Test\Fixture\RecoveryItemFactory;
use Allsilaevex\Pool\Exceptions\BorrowTimeoutException;
use Allsilaevex\ConnectionPool\Hooks\ConnectionCheckHook;
use Allsilaevex\ConnectionPool\Hooks\ConnectionResetHook;
use Allsilaevex\ConnectionPool\KeepaliveCheckerInterface;
use Allsilaevex\Pool\Exceptions\PoolItemRemovedException;
use Allsilaevex\Pool\Exceptions\PoolItemCreationException;
use Allsilaevex\ConnectionPool\Tasks\KeepaliveCheckTimerTask;
use Allsilaevex\ConnectionPool\Tasks\PoolItemUpdaterTimerTask;

#[CoversClass(Pool::class)]
#[CoversClass(PoolItemWrapper::class)]
#[CoversClass(PoolItemUpdaterTimerTask::class)]
#[CoversClass(KeepaliveCheckTimerTask::class)]
#[UsesClass(PoolConfig::class)]
#[UsesClass(PoolMetrics::class)]
#[UsesClass(PoolItemHookManager::class)]
#[UsesClass(PoolItemWrapperFactory::class)]
#[UsesClass(TimerTaskScheduler::class)]
#[UsesClass(ConnectionCheckHook::class)]
#[UsesClass(ConnectionResetHook::class)]
final class PoolRecoveryTest extends TestCase
{
    /** @return iterable<string, array{bool}> */
    public static function fullReturnPaths(): iterable
    {
        yield 'already full' => [true];
        yield 'fills during return hook' => [false];
    }

    #[DataProvider('fullReturnPaths')]
    public function testReturnDisposesItsOwnItemWhenTheIdleQueueIsFull(bool $alreadyFull): void
    {
        $factory = new RecoveryItemFactory();
        $entered = new Channel(1);
        $resume = new Channel(1);
        $done = new Channel(1);
        $hook = new ConnectionResetHook(static function (RecoveryItem $item) use ($entered, $resume, $alreadyFull): void {
            if (!$alreadyFull) {
                $entered->push(true);
                $resume->pop();
            }
        });
        $pool = $this->pool($factory, $hook);
        $item = null;
        if ($alreadyFull) {
            $item = $pool->borrow();
            $pool->increaseItems();
            $pool->return($item);
        } else {
            \Swoole\Coroutine\go(static function () use ($pool, &$item, $done): void {
                $item = $pool->borrow();
                $pool->return($item);
                $done->push(!$pool->hasBoundItem());
            });
            static::assertTrue($entered->pop(.1));
            $pool->increaseItems();
            $resume->push(true);
            static::assertTrue($done->pop(.1));
        }
        static::assertNull($item);
        static::assertFalse($pool->hasBoundItem());
        $destroyedBeforeNextBorrow = $factory->destroyedIds;
        static::assertSame([1], $destroyedBeforeNextBorrow);
        static::assertSame(1, $pool->getCurrentSize());
        static::assertSame(1, $pool->getIdleCount());
        static::assertCount(0, $pool->getBorrowedItemStorage());
        static::assertCount(1, $pool->getIdledItemStorage());
        $fresh = $pool->borrow();
        static::assertSame(2, $fresh->id);
        $pool->removeItem($fresh);
        static::assertSame([1, 2], $factory->destroyedIds);
    }

    /** @return iterable<string, array{bool}> */
    public static function yieldingRecreatePaths(): iterable
    {
        yield 'yielding creation' => [true];
        yield 'yielding disposal' => [false];
    }

    #[DataProvider('yieldingRecreatePaths')]
    public function testCloseDuringRecreateDisposesEachItemOnce(bool $duringCreate): void
    {
        $factory = new RecoveryItemFactory();
        /** @var TimerTaskScheduler<PoolItemWrapperInterface<RecoveryItem>> $scheduler */
        $scheduler = new TimerTaskScheduler([]);
        /** @var PoolItemFactoryInterface<RecoveryItem> $itemFactory */
        $itemFactory = $factory;
        /** @psalm-suppress InvalidArgument Psalm widens the nested wrapper template to object. */
        $wrapper = new PoolItemWrapper($itemFactory, $scheduler);
        $entered = new Channel(1);
        $resume = new Channel(1);
        $done = new Channel(1);
        $pause = static function () use ($entered, $resume): void {
            $entered->push(true);
            $resume->pop();
        };
        if ($duringCreate) {
            $factory->beforeCreate = $pause;
        } else {
            $factory->beforeDestroy = $pause;
        }
        \Swoole\Coroutine\go(static function () use ($wrapper, $done): void {
            try {
                $wrapper->recreateItem();
                $done->push(true);
            } catch (Throwable $failure) {
                $done->push($failure);
            }
        });
        static::assertTrue($entered->pop(.1));
        $factory->beforeDestroy = null;
        $wrapper->close();
        $resume->push(true);
        static::assertInstanceOf(PoolItemRemovedException::class, $done->pop(.1));
        static::assertSame(PoolItemState::REMOVED, $wrapper->getState());
        static::assertSame($duringCreate ? [1, 2] : [1], $factory->destroyedIds);
        static::assertSame($duringCreate ? 2 : 1, $factory->creates);
        $wrapper->close();
        static::assertSame($duringCreate ? [1, 2] : [1], $factory->destroyedIds);
    }

    /** @return iterable<string, array{string}> */
    public static function borrowFailures(): iterable
    {
        yield 'throwing checker' => ['checker'];
        yield 'throwing replacement' => ['replacement'];
        yield 'caught replacement leaves null' => ['null'];
        yield 'cleanup and logger fail too' => ['cleanup'];
    }

    #[DataProvider('borrowFailures')]
    public function testFailedBorrowFreesCapacityAndRestoredBackendRecovers(string $mode): void
    {
        $factory = new RecoveryItemFactory();
        $failure = $mode === 'null' ? new PoolItemCreationException('unavailable') : new RuntimeException('unavailable');
        $checker = static function (RecoveryItem $item) use ($factory, $failure, $mode): bool {
            if ($factory->createFailure !== null && ($mode === 'checker' || $mode === 'cleanup')) {
                throw $failure;
            }

            return $factory->createFailure === null;
        };
        $logger = $this->createMock(LoggerInterface::class);
        if ($mode === 'cleanup') {
            $logger->method('error')->willThrowException(new RuntimeException('logger failed'));
        }
        $pool = $this->pool($factory, new ConnectionCheckHook($checker, new NullLogger()), $logger);
        $pool->increaseItems();
        $factory->createFailure = $failure;
        if ($mode === 'cleanup') {
            $factory->destroyFailure = new RuntimeException('destroy failed');
        }

        try {
            $pool->borrow();
            static::fail('The unavailable connection must not be borrowed.');
        } catch (Throwable $caught) {
            if ($mode === 'null') {
                static::assertInstanceOf(BorrowTimeoutException::class, $caught);
            } else {
                static::assertSame($failure, $caught);
            }
        }
        try {
            static::assertSame(0, $pool->getCurrentSize());
        } finally {
            $factory->destroyFailure = null;
        }
        static::assertSame(0, $pool->getIdleCount());
        static::assertCount(0, $pool->getBorrowedItemStorage());
        static::assertCount(0, $pool->getIdledItemStorage());
        static::assertFalse($pool->hasBoundItem());
        $factory->createFailure = null;
        $factory->destroyFailure = null;
        $item = $pool->borrow();
        static::assertSame(2, $item->id);
        $pool->return($item);
        static::assertSame(1, $pool->getIdleCount());
        $pool->decreaseItems();
    }

    public function testThrowingReturnHookFreesCapacityAndDoesNotBindTheNextBorrower(): void
    {
        $factory = new RecoveryItemFactory();
        $failure = new RuntimeException('reset failed');
        $state = new class() {
            public bool $failing = true;
        };
        $hook = new ConnectionResetHook(static function (RecoveryItem $item) use ($state, $failure): void {
            if ($state->failing) {
                throw $failure;
            }
        });
        $pool = $this->pool($factory, $hook);
        $item = $pool->borrow();
        try {
            $pool->return($item);
            static::fail('Reset failure must propagate.');
        } catch (RuntimeException $caught) {
            static::assertSame($failure, $caught);
        }
        static::assertNull($item);
        static::assertFalse($pool->hasBoundItem());
        static::assertSame(0, $pool->getCurrentSize());
        $state->failing = false;
        $fresh = $pool->borrow();
        static::assertSame(2, $fresh->id);
        $pool->return($fresh);
        $pool->decreaseItems();
    }

    public function testDestroyFailureStillReleasesCountedCapacity(): void
    {
        $factory = new RecoveryItemFactory();
        $pool = $this->pool($factory);
        $item = $pool->borrow();
        $failure = new RuntimeException('destroy failed');
        $factory->destroyFailure = $failure;
        try {
            $pool->removeItem($item);
            static::fail('Destroy failure must propagate.');
        } catch (RuntimeException $caught) {
            static::assertSame($failure, $caught);
        }
        static::assertSame(0, $pool->getCurrentSize());
        static::assertSame(1, $pool->stats()['item_deleted_total']);
        $factory->destroyFailure = null;
        $fresh = $pool->borrow();
        static::assertSame(2, $fresh->id);
        $pool->return($fresh);
        $pool->decreaseItems();
    }

    public function testYieldingDestroyRetainsItsSlotUntilDisposalFinishes(): void
    {
        $factory = new RecoveryItemFactory();
        $entered = new Channel(1);
        $release = new Channel(1);
        $done = new Channel(1);
        $factory->beforeDestroy = static function () use ($entered, $release): void {
            $entered->push(true);
            $release->pop();
        };
        $failure = new RuntimeException('reset failed');
        $pool = $this->pool($factory, new ConnectionResetHook(static function (RecoveryItem $item) use ($failure): never {
            throw $failure;
        }));
        \Swoole\Coroutine\go(static function () use ($pool, $done): void {
            $item = $pool->borrow();
            try {
                $pool->return($item);
            } catch (RuntimeException $caught) {
                $done->push($caught);
            }
        });
        static::assertTrue($entered->pop(.1));
        static::assertSame(1, $pool->getCurrentSize());
        static::assertSame(0, $pool->getIdleCount());
        static::assertCount(0, $pool->getBorrowedItemStorage());
        static::assertFalse($pool->hasBoundItem());
        $waiting = new Channel(1);
        \Swoole\Coroutine\go(static function () use ($pool, $waiting): void {
            try {
                $pool->borrow();
                $waiting->push(true);
            } catch (BorrowTimeoutException) {
                $waiting->push(false);
            }
        });
        static::assertSame(1, $factory->creates);
        static::assertSame(1, $pool->stats()['consumer_pending_count']);
        $release->push(true);
        static::assertSame($failure, $done->pop());
        static::assertFalse($waiting->pop());
        static::assertSame(0, $pool->getCurrentSize());
        $factory->beforeDestroy = null;
        $fresh = $pool->borrow();
        static::assertSame(2, $fresh->id);
        $pool->removeItem($fresh);
    }

    /** @return iterable<string, array{bool, bool, bool}> */
    public static function maintenanceFailures(): iterable
    {
        yield 'updater recreation' => [false, false, false];
        yield 'updater logging' => [false, false, true];
        yield 'keepalive recreation' => [true, false, false];
        yield 'keepalive checker' => [true, true, false];
        yield 'keepalive logging' => [true, true, true];
    }

    #[DataProvider('maintenanceFailures')]
    public function testFailedMaintenanceReportsAndReleasesItsReservation(bool $keepalive, bool $throwingChecker, bool $throwingLogger): void
    {
        $factory = new RecoveryItemFactory();
        $pool = $this->pool($factory);
        $pool->increaseItems();
        $storage = $pool->getIdledItemStorage();
        $storage->rewind();
        $wrapper = $storage->current();
        $failure = new RuntimeException('maintenance backend unavailable');
        $factory->createFailure = $failure;
        $checker = new /** @implements KeepaliveCheckerInterface<RecoveryItem> */ class($throwingChecker, $failure) implements KeepaliveCheckerInterface {
            public function __construct(
                private bool $throwing,
                private RuntimeException $failure,
            ) {
            }

            public function check(mixed $connection): bool
            {
                if ($this->throwing) {
                    throw $this->failure;
                }

                return false;
            }

            public function getIntervalSec(): float
            {
                return 1;
            }
        };
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects(static::once())->method('error')->with(static::anything(), static::callback(
            static fn (array $context): bool => ($context['exception'] ?? null) === $failure,
        ))->willReturnCallback(static function () use ($throwingLogger): void {
            if ($throwingLogger) {
                throw new RuntimeException('logger failed');
            }
        });
        $task = $keepalive ? new KeepaliveCheckTimerTask($logger, $checker) : new PoolItemUpdaterTimerTask(1, 0, $logger);
        $task->run(-1, WeakReference::create($wrapper));
        static::assertSame(PoolItemState::IDLE, $wrapper->getState());
        $factory->createFailure = null;
        $item = $pool->borrow();
        static::assertSame($throwingChecker ? 1 : 2, $item->id);
        $pool->return($item);
        $pool->decreaseItems();
    }

    public function testFirstBorrowReplacesANullTimerItemWithoutAResizer(): void
    {
        $factory = new RecoveryItemFactory();
        $pool = $this->pool($factory);
        $pool->increaseItems();
        $storage = $pool->getIdledItemStorage();
        $storage->rewind();
        $wrapper = $storage->current();
        $factory->createFailure = new PoolItemCreationException('backend unavailable');
        /** @var PoolItemUpdaterTimerTask<RecoveryItem> $task */
        $task = new PoolItemUpdaterTimerTask(1, 0, new NullLogger());
        $task->run(-1, WeakReference::create($wrapper));
        static::assertNull($wrapper->getItem());
        $factory->createFailure = null;
        $fresh = $pool->borrow();
        static::assertSame(2, $fresh->id);
        static::assertSame(1, $pool->getCurrentSize());
        static::assertSame(1, $pool->stats()['item_deleted_total']);
        $pool->return($fresh);
        $pool->decreaseItems();
    }

    public function testKeepaliveDoesNotReviveAnItemRemovedWhileItsCheckYields(): void
    {
        $factory = new RecoveryItemFactory();
        $pool = $this->pool($factory);
        $pool->increaseItems();
        $storage = $pool->getIdledItemStorage();
        $storage->rewind();
        $wrapper = $storage->current();
        $entered = new Channel(1);
        $resume = new Channel(1);
        $done = new Channel(1);
        $checker = new /** @implements KeepaliveCheckerInterface<RecoveryItem> */ class($entered, $resume) implements KeepaliveCheckerInterface {
            public function __construct(
                private Channel $entered,
                private Channel $resume,
            ) {
            }

            public function check(mixed $connection): bool
            {
                $this->entered->push(true);
                $this->resume->pop();

                return false;
            }

            public function getIntervalSec(): float
            {
                return 1;
            }
        };
        $task = new KeepaliveCheckTimerTask(new NullLogger(), $checker);
        \Swoole\Coroutine\go(static function () use ($task, $wrapper, $done): void {
            try {
                $task->run(-1, WeakReference::create($wrapper));
                $done->push(true);
            } catch (Throwable $failure) {
                $done->push($failure);
            }
        });
        static::assertTrue($entered->pop(.1));
        static::assertSame(PoolItemState::RESERVED, $wrapper->getState());
        static::assertTrue($pool->decreaseItems());
        $resume->push(true);
        static::assertTrue($done->pop(.1));
        static::assertSame(PoolItemState::REMOVED, $wrapper->getState());
        static::assertSame(0, $pool->getCurrentSize());
        static::assertSame(1, $factory->creates);
        static::assertSame(1, $factory->destroys);
        $fresh = $pool->borrow();
        static::assertSame(2, $fresh->id);
        $pool->return($fresh);
        $pool->decreaseItems();
    }

    /**
     * @param PoolItemHookInterface<RecoveryItem>|null $hook
     * @return Pool<RecoveryItem>
     */
    private function pool(RecoveryItemFactory $factory, ?PoolItemHookInterface $hook = null, ?LoggerInterface $logger = null): Pool
    {
        /** @var TimerTaskScheduler<PoolItemWrapperInterface<RecoveryItem>> $scheduler */
        $scheduler = new TimerTaskScheduler([]);
        /** @var PoolItemFactoryInterface<RecoveryItem> $itemFactory */
        $itemFactory = $factory;
        /** @psalm-suppress InvalidArgument Psalm widens the nested wrapper template to object. */
        $wrapperFactory = new PoolItemWrapperFactory($itemFactory, $scheduler);
        return new Pool(
            name: 'recovery-test',
            config: new PoolConfig(1, .02, .02, false, true),
            poolItemWrapperFactory: $wrapperFactory,
            logger: $logger ?? new NullLogger(),
            poolItemHookManager: $hook === null ? null : new PoolItemHookManager([$hook]),
        );
    }
}
