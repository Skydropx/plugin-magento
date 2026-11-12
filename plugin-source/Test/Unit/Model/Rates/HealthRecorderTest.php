<?php
declare(strict_types=1);

namespace Skydropx\Shipping\Test\Unit\Model\Rates;

use Magento\Framework\FlagManager;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Skydropx\Shipping\Model\Rates\HealthRecorder;

class HealthRecorderTest extends TestCase
{
    private int $now = 1762459205;

    /**
     * @var mixed
     */
    private $stored = null;

    private int $writes = 0;

    /**
     * @var FlagManager&MockObject
     */
    private $flagManager;

    protected function setUp(): void
    {
        $this->flagManager = $this->getMockBuilder(FlagManager::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['getFlagData', 'saveFlag'])
            ->getMock();
        $this->flagManager->method('getFlagData')->with(HealthRecorder::FLAG_CODE)->willReturnCallback(function () {
            return $this->stored;
        });
        $this->flagManager->method('saveFlag')->willReturnCallback(function ($code, $value) {
            $this->assertSame(HealthRecorder::FLAG_CODE, $code);
            $this->stored = $value;
            $this->writes++;

            return true;
        });
    }

    public function testFirstRecordIsWrittenWithIsoUtcDate(): void
    {
        $this->recorder()->record(HealthRecorder::STATUS_OK, 200);

        $this->assertSame(
            ['status' => 'ok', 'http_status' => 200, 'at' => '2025-11-06T20:00:05Z'],
            $this->stored
        );
    }

    public function testSameStatusWithinFiveMinutesIsNotWritten(): void
    {
        $recorder = $this->recorder();
        $recorder->record(HealthRecorder::STATUS_OK, 200);
        $this->now += 300;
        $recorder->record(HealthRecorder::STATUS_OK, 200);

        $this->assertSame(1, $this->writes);
    }

    public function testSameStatusAfterFiveMinutesIsWritten(): void
    {
        $recorder = $this->recorder();
        $recorder->record(HealthRecorder::STATUS_OK, 200);
        $this->now += 301;
        $recorder->record(HealthRecorder::STATUS_OK, 200);

        $this->assertSame(2, $this->writes);
    }

    public function testStatusChangeIsWrittenAtOnce(): void
    {
        $recorder = $this->recorder();
        $recorder->record(HealthRecorder::STATUS_OK, 200);
        $this->now += 1;
        $recorder->record(HealthRecorder::STATUS_TIMEOUT);

        $this->assertSame(2, $this->writes);
        $this->assertSame(
            ['status' => 'timeout', 'http_status' => null, 'at' => '2025-11-06T20:00:06Z'],
            $this->recorder()->getLast()
        );
    }

    public function testStoredValueSurvivesANewInstance(): void
    {
        $this->stored = ['status' => 'unauthorized', 'http_status' => 401, 'at' => '2025-11-06T20:00:00Z'];

        $this->recorder()->record(HealthRecorder::STATUS_UNAUTHORIZED, 401);

        $this->assertSame(0, $this->writes);
    }

    /**
     * @dataProvider unreadable
     * @param mixed $stored
     */
    public function testGetLastIsNullWhenNothingUsableIsStored($stored): void
    {
        $this->stored = $stored;

        $this->assertNull($this->recorder()->getLast());
    }

    public static function unreadable(): array
    {
        return [
            'nothing' => [null],
            'string' => ['ok'],
            'no date' => [['status' => 'ok']],
        ];
    }

    public function testStorageErrorsNeverReachCheckout(): void
    {
        $flagManager = $this->getMockBuilder(FlagManager::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['getFlagData', 'saveFlag'])
            ->getMock();
        $flagManager->method('getFlagData')->willThrowException(new \RuntimeException('db down'));

        (new HealthRecorder($flagManager))->record(HealthRecorder::STATUS_FAILED, 503);

        $this->addToAssertionCount(1);
    }

    /**
     * Recorder with a controllable clock.
     *
     * @return HealthRecorder&MockObject
     */
    private function recorder()
    {
        $recorder = $this->getMockBuilder(HealthRecorder::class)
            ->setConstructorArgs([$this->flagManager])
            ->onlyMethods(['now'])
            ->getMock();
        $recorder->method('now')->willReturnCallback(function () {
            return $this->now;
        });

        return $recorder;
    }
}
