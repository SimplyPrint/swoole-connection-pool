<?php

declare(strict_types=1);

namespace Allsilaevex\ConnectionPool\ConnectionFactories;

use PDO;
use Allsilaevex\Pool\PoolItemFactoryInterface;

/**
 * @implements PoolItemFactoryInterface<PDO>
 */
readonly class PDOConnectionFactory implements PoolItemFactoryInterface
{
    /**
     * @param  array<string, mixed>  $options
     */
    public function __construct(
        protected string $dsn,
        protected ?string $username = null,
        protected ?string $password = null,
        protected ?array $options = null,
    ) {
    }

    public function create(): mixed
    {
        return new PDO(
            dsn: $this->dsn,
            username: $this->username,
            password: $this->password,
            options: $this->options,
        );
    }

    public function destroy(mixed $item): void
    {
        // PDO connections close automatically when set to null
        // No explicit action needed
    }
}
