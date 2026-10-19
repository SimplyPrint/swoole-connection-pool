<?php

declare(strict_types=1);

namespace Allsilaevex\ConnectionPool\Tasks;

use Throwable;
use Psr\Log\LoggerInterface;
use Allsilaevex\Pool\PoolItemState;
use Allsilaevex\Pool\PoolItemWrapperInterface;
use Allsilaevex\Pool\TimerTask\TimerTaskInterface;
use Allsilaevex\ConnectionPool\KeepaliveCheckerInterface;
use Allsilaevex\Pool\Exceptions\PoolItemRemovedException;

use function is_null;

/**
 * @template TItem of object
 * @implements TimerTaskInterface<PoolItemWrapperInterface<TItem>>
 */
readonly class KeepaliveCheckTimerTask implements TimerTaskInterface
{
    /**
     * @param  KeepaliveCheckerInterface<TItem>  $keepaliveChecker
     */
    public function __construct(
        protected LoggerInterface $logger,
        protected KeepaliveCheckerInterface $keepaliveChecker,
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

        if (!$runner->compareAndSetState(PoolItemState::IDLE, PoolItemState::RESERVED)) {
            return;
        }

        $logContext = ['item_id' => $runner->getId()];
        try {
            if (!$this->keepaliveChecker->check($runner->getItem())) {
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
        return $this->keepaliveChecker->getIntervalSec();
    }
}
