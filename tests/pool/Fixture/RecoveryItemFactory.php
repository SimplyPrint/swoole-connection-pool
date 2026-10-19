<?php

declare(strict_types=1);

namespace Allsilaevex\Pool\Test\Fixture;

use Closure;
use Throwable;
use Allsilaevex\Pool\PoolItemFactoryInterface;

/** @implements PoolItemFactoryInterface<RecoveryItem> */
final class RecoveryItemFactory implements PoolItemFactoryInterface
{
    public int $creates = 0;
    public int $destroys = 0;
    public ?Throwable $createFailure = null;
    public ?Throwable $destroyFailure = null;

    /** @var list<int> */
    public array $destroyedIds = [];

    /** @var (Closure(): void)|null */
    public ?Closure $beforeCreate = null;

    /** @var (Closure(): void)|null */
    public ?Closure $beforeDestroy = null;

    public function create(): RecoveryItem
    {
        if ($this->createFailure !== null) {
            throw $this->createFailure;
        }

        $item = new RecoveryItem(++$this->creates);
        $this->beforeCreate?->__invoke();

        return $item;
    }

    public function destroy(mixed $item): void
    {
        ++$this->destroys;
        $this->destroyedIds[] = $item->id;
        $this->beforeDestroy?->__invoke();
        if ($this->destroyFailure !== null) {
            throw $this->destroyFailure;
        }
    }
}
