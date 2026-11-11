<?php
declare(strict_types=1);

namespace Skydropx\Shipping\Test\Unit\Model\OrderPush;

use PHPUnit\Framework\TestCase;
use Skydropx\Shipping\Model\OrderPush\RetryPolicy;

class RetryPolicyTest extends TestCase
{
    /**
     * @dataProvider scheduleProvider
     */
    public function testDelayFollowsTheSchedule(int $attempt, int $expected): void
    {
        $this->assertSame($expected, (new RetryPolicy())->delay($attempt));
    }

    public static function scheduleProvider(): array
    {
        return [
            'attempt 1' => [1, 60],
            'attempt 2' => [2, 300],
            'attempt 3' => [3, 900],
            'attempt 4' => [4, 3600],
            'attempt 5' => [5, 21600],
            'attempt 6' => [6, 21600],
            'beyond the schedule is clamped' => [9, 21600],
            'below the schedule is clamped' => [0, 60],
        ];
    }

    public function testMaxAttemptsIsSix(): void
    {
        $this->assertSame(6, (new RetryPolicy())->maxAttempts());
    }
}
