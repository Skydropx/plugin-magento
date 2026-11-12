<?php
/**
 * Order push retry schedule.
 */
declare(strict_types=1);

namespace Skydropx\Shipping\Model\OrderPush;

/**
 * Delay before each retry. An event fails for good once a failed attempt goes past maxAttempts().
 */
class RetryPolicy
{
    /**
     * Seconds to wait after failed attempt 1, 2, … 6.
     */
    private const DELAYS = [60, 300, 900, 3600, 21600, 21600];

    /**
     * Seconds to wait after the given failed attempt (1-based). Out of range values are clamped.
     *
     * @param int $attempt
     * @return int
     */
    public function delay(int $attempt): int
    {
        $index = min(max($attempt, 1), count(self::DELAYS)) - 1;

        return self::DELAYS[$index];
    }

    /**
     * Failed attempts that are still retried.
     *
     * @return int
     */
    public function maxAttempts(): int
    {
        return count(self::DELAYS);
    }
}
