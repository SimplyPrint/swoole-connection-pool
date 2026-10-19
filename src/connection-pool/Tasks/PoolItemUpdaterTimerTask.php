<?php

declare(strict_types=1);

namespace Allsilaevex\ConnectionPool\Tasks;

use Throwable;
use Psr\Log\LoggerInterface;
use Allsilaevex\Pool\PoolItemState;
use Allsilaevex\Pool\PoolItemWrapperInterface;
use Allsilaevex\Pool\TimerTask\TimerTaskInterface;
use Allsilaevex\Pool\Exceptions\PoolItemRemovedException;

use function is_null;

/**
 * @template TItem of object
 * @implements TimerTaskInterface<PoolItemWrapperInterface<TItem>>
 */
readonly class PoolItemUpdaterTimerTask implements TimerTaskInterface
{
    public function __construct(
        public float $intervalSec,
        public float $maxLifetimeSec,
        public LoggerInterface $logger,
        public float $maxItemReservingWaitingTimeSec = .0,
    ) {
    }

    /**
     * @inheritDoc
     */
    public function run(int $timerId, mixed $runnerRef): void
    {
        /** @var PoolItemWrapperInterface<TItem>|null $runner */
        $runner = $runnerRef->get();

        if (is_null($runner)) {
            return;
        }

        if ($this->maxItemReservingWaitingTimeSec == .0) {
            $isReserved = $runner->compareAndSetState(
                expect: PoolItemState::IDLE,
                update: PoolItemState::RESERVED,
            );
        } else {
            $isReserved = $runner->waitForCompareAndSetState(
                expect: PoolItemState::IDLE,
                update: PoolItemState::RESERVED,
                timeoutSec: $this->maxItemReservingWaitingTimeSec,
            );
        }

        if (!$isReserved) {
            return;
        }

        $logContext = ['item_id' => $runner->getId()];

        try {
            if ($runner->stats()['item_lifetime_sec'] > $this->maxLifetimeSec) {
                $runner->recreateItem();
            }
        } catch (Throwable $exception) {
            try {
                $this->logger->error('Can\'t recreate item: ' . $exception->getMessage(), $logContext + ['exception' => $exception]);
            } catch (Throwable) {
            }
        } finally {
            try {
                $runner->setState(PoolItemState::IDLE);
            } catch (PoolItemRemovedException) {
            }
        }
    }

    public function getIntervalSec(): float
    {
        return $this->intervalSec;
    }
}
