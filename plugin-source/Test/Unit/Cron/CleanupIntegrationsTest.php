<?php
declare(strict_types=1);

namespace Skydropx\Shipping\Test\Unit\Cron;

use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Skydropx\Shipping\Cron\CleanupIntegrations;
use Skydropx\Shipping\Logger\Logger;
use Skydropx\Shipping\Model\Config;
use Skydropx\Shipping\Model\Connect\IntegrationProvisioner;

class CleanupIntegrationsTest extends TestCase
{
    private const NOW = 1762459205;

    /**
     * @var array List of ['id' => int, 'at' => int], as IntegrationProvisioner keeps it.
     */
    private array $pending = [];

    /**
     * @var int[]
     */
    private array $deleted = [];

    /**
     * @var int[] Ids whose delete throws.
     */
    private array $failing = [];

    /**
     * @var array[]
     */
    private array $logs = [];

    /**
     * @var int|null
     */
    private ?int $currentId = null;

    public function testDeletesPendingIntegrationsOlderThanOneHour(): void
    {
        $this->pending = [
            ['id' => 10, 'at' => self::NOW - 3601],
            ['id' => 11, 'at' => self::NOW - 86400],
        ];

        $this->cron()->execute();

        $this->assertSame([10, 11], $this->deleted);
        $this->assertSame([], $this->pending);
    }

    public function testKeepsPendingIntegrationsYoungerThanOneHour(): void
    {
        $this->pending = [
            ['id' => 10, 'at' => self::NOW - 60],
            ['id' => 11, 'at' => self::NOW - 3600],
            ['id' => 12, 'at' => self::NOW - 7200],
        ];

        $this->cron()->execute();

        $this->assertSame([12], $this->deleted);
        $this->assertSame(
            [['id' => 10, 'at' => self::NOW - 60], ['id' => 11, 'at' => self::NOW - 3600]],
            $this->pending
        );
    }

    public function testNeverDeletesTheIntegrationInUse(): void
    {
        $this->currentId = 42;
        $this->pending = [
            ['id' => 42, 'at' => self::NOW - 7200],
            ['id' => 43, 'at' => self::NOW - 7200],
        ];

        $this->cron()->execute();

        $this->assertSame([43], $this->deleted);
        $this->assertNotContains(42, $this->deleted);
        $this->assertSame([], $this->pending, 'The integration in use leaves the pending list without being deleted.');
    }

    public function testFailedDeleteKeepsTheEntryAndDoesNotStopTheCron(): void
    {
        $this->failing = [10];
        $this->pending = [
            ['id' => 10, 'at' => self::NOW - 7200],
            ['id' => 11, 'at' => self::NOW - 7200],
        ];

        $this->cron()->execute();

        $this->assertSame([11], $this->deleted);
        $this->assertSame([['id' => 10, 'at' => self::NOW - 7200]], $this->pending);
    }

    public function testEmptyListDoesNothing(): void
    {
        $this->cron()->execute();

        $this->assertSame([], $this->deleted);
        $this->assertSame([], $this->logs);
    }

    public function testLogsIntegrationCleanupWithoutSensitiveData(): void
    {
        $this->failing = [11];
        $this->pending = [
            ['id' => 10, 'at' => self::NOW - 7200],
            ['id' => 11, 'at' => self::NOW - 7200],
        ];

        $this->cron()->execute();

        $this->assertSame(['INTEGRATION_CLEANUP', 'INTEGRATION_CLEANUP'], array_column($this->logs, 0));
        $this->assertSame(['integration_id' => 10], $this->logs[0][1]);
        $this->assertSame(11, $this->logs[1][1]['integration_id']);
        foreach ($this->logs as [, $context]) {
            $this->assertSame([], array_diff(array_keys($context), ['integration_id', 'detail']));
        }
        $dump = strtolower((string) json_encode($this->logs));
        foreach (['token', 'secret', 'signing', 'code', 'state'] as $sensitive) {
            $this->assertStringNotContainsString($sensitive, $dump);
        }
    }

    public function testUnexpectedErrorNeverEscapes(): void
    {
        $provisioner = $this->createMock(IntegrationProvisioner::class);
        $provisioner->method('getPending')->willThrowException(new \RuntimeException('db down'));

        $this->cron($provisioner)->execute();

        $this->assertSame('INTEGRATION_CLEANUP', $this->logs[0][0]);
    }

    /**
     * @param IntegrationProvisioner|null $provisioner
     * @return CleanupIntegrations&MockObject
     */
    private function cron(?IntegrationProvisioner $provisioner = null): CleanupIntegrations
    {
        if ($provisioner === null) {
            $provisioner = $this->createMock(IntegrationProvisioner::class);
            $provisioner->method('getPending')->willReturnCallback(fn (): array => $this->pending);
            $provisioner->method('setPending')->willReturnCallback(function (array $pending): void {
                $this->pending = array_values($pending);
            });
            $provisioner->method('delete')->willReturnCallback(function (int $id): void {
                if (in_array($id, $this->failing, true)) {
                    throw new \RuntimeException('Could not delete integration');
                }
                $this->deleted[] = $id;
            });
        }

        $config = $this->createMock(Config::class);
        $config->method('getIntegrationId')->willReturnCallback(fn (): ?int => $this->currentId);

        $logger = $this->createMock(Logger::class);
        foreach (['info', 'warning', 'error'] as $level) {
            $logger->method($level)->willReturnCallback(function ($message, array $context = []): void {
                $this->logs[] = [$message, $context];
            });
        }

        $cron = $this->getMockBuilder(CleanupIntegrations::class)
            ->setConstructorArgs([$provisioner, $config, $logger])
            ->onlyMethods(['now'])
            ->getMock();
        $cron->method('now')->willReturn(self::NOW);

        return $cron;
    }
}
