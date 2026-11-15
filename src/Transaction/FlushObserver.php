<?php

declare(strict_types=1);

namespace Nowo\UserKitBundle\Transaction;

use Symfony\Contracts\Service\ResetInterface;

/**
 * Records whether the manager flushed while one unit of work of {@see UserKitTransactionRunner} ran. One instance per
 * unit (created and dropped by the runner), never a shared service.
 *
 * @internal
 */
final class FlushObserver implements ResetInterface
{
    public bool $flushed = false;

    public function postFlush(): void
    {
        $this->flushed = true;
    }

    public function reset(): void
    {
        $this->flushed = false;
    }
}
