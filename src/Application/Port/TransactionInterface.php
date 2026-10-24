<?php

declare(strict_types=1);

namespace SW\Calendar\Application\Port;

interface TransactionInterface
{
    /**
     * Runs the callback in a database transaction; rolls back and rethrows on any exception.
     *
     * @template T
     * @param callable(): T $callback
     * @return T
     */
    public function run(callable $callback): mixed;
}
