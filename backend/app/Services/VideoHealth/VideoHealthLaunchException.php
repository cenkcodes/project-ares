<?php

namespace App\Services\VideoHealth;

use InvalidArgumentException;
use RuntimeException;
use Throwable;

final class VideoHealthLaunchException extends RuntimeException
{
    public const CONFIRMED_NOT_LAUNCHED = 'CONFIRMED_NOT_LAUNCHED';
    public const RECONCILIATION_REQUIRED = 'RECONCILIATION_REQUIRED';

    public function __construct(private readonly string $outcome, string $message, ?Throwable $previous = null)
    {
        if (!in_array($outcome, [self::CONFIRMED_NOT_LAUNCHED, self::RECONCILIATION_REQUIRED], true)) {
            throw new InvalidArgumentException('Unknown video health launch outcome.');
        }

        parent::__construct($message, 0, $previous);
    }

    public function outcome(): string
    {
        return $this->outcome;
    }

    public function confirmedNotLaunched(): bool
    {
        return $this->outcome === self::CONFIRMED_NOT_LAUNCHED;
    }

    public function requiresReconciliation(): bool
    {
        return $this->outcome === self::RECONCILIATION_REQUIRED;
    }
}
