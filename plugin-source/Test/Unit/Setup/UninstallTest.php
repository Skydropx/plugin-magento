<?php
declare(strict_types=1);

namespace Skydropx\Shipping\Test\Unit\Setup;

use Magento\Framework\DB\Adapter\AdapterInterface;
use Magento\Framework\Setup\ModuleContextInterface;
use Magento\Framework\Setup\SchemaSetupInterface;
use PHPUnit\Framework\TestCase;
use Skydropx\Shipping\Model\Config;
use Skydropx\Shipping\Model\Connect\IntegrationProvisioner;
use Skydropx\Shipping\Setup\Uninstall;

class UninstallTest extends TestCase
{
    /**
     * @var string[] Every call in order: "delete-integration:<id>", "delete:<table>:<where>", "drop:<table>", …
     */
    private array $calls = [];

    /**
     * @var int|null
     */
    private ?int $integrationId = null;

    /**
     * @var string|null
     */
    private ?string $mode = null;

    /**
     * @var array List of ['id' => int, 'at' => int].
     */
    private array $pending = [];

    /**
     * @var int[] Ids whose delete throws.
     */
    private array $failing = [];

    /**
     * @var bool
     */
    private bool $tableExists = true;

    public function testRemovesEverythingInOrder(): void
    {
        $this->mode = Config::MODE_EXTENSION;
        $this->integrationId = 7;
        $this->pending = [['id' => 8, 'at' => 1762459205]];

        $this->uninstall();

        $this->assertSame([
            'delete-integration:7',
            'delete-integration:8',
            'start',
            "delete:pfx_core_config_data:path LIKE ? => carriers/skydropx/%",
            "delete:pfx_flag:flag_code LIKE ? => skydropx\\_%",
            'exists:pfx_skydropx_order_event',
            'drop:pfx_skydropx_order_event',
            'end',
        ], $this->calls);
    }

    public function testToleratesAMissingOutboxTable(): void
    {
        $this->tableExists = false;

        $this->uninstall();

        $this->assertContains('exists:pfx_skydropx_order_event', $this->calls);
        $this->assertNotContains('drop:pfx_skydropx_order_event', $this->calls);
        $this->assertSame('end', end($this->calls));
    }

    public function testAnIntegrationThatIsGoneDoesNotStopTheRest(): void
    {
        $this->mode = Config::MODE_EXTENSION;
        $this->integrationId = 7;
        $this->pending = [['id' => 8, 'at' => 1762459205], ['id' => 9, 'at' => 1762459205]];
        $this->failing = [7, 8];

        $this->uninstall();

        $this->assertSame(['delete-integration:7', 'delete-integration:8', 'delete-integration:9'], $this->deletes());
        $this->assertContains('drop:pfx_skydropx_order_event', $this->calls);
        $this->assertSame('end', end($this->calls));
    }

    public function testManualModeStillDeletesTheStoredIntegration(): void
    {
        // Connected with one click, then a store ID pasted by hand: the module's integration must not survive.
        $this->mode = Config::MODE_MANUAL;
        $this->integrationId = 7;

        $this->uninstall();

        $this->assertSame(['delete-integration:7'], $this->deletes());
        $this->assertContains('drop:pfx_skydropx_order_event', $this->calls);
    }

    public function testManualModeDeletesTheStoredAndPendingIntegrationsOnce(): void
    {
        $this->mode = Config::MODE_MANUAL;
        $this->integrationId = 7;
        $this->pending = [['id' => 7, 'at' => 1762459205], ['id' => 8, 'at' => 1762459205]];

        $this->uninstall();

        $this->assertSame(['delete-integration:7', 'delete-integration:8'], $this->deletes());
    }

    public function testManualModeWithoutIntegrationDeletesNothing(): void
    {
        $this->mode = Config::MODE_MANUAL;

        $this->uninstall();

        $this->assertSame([], $this->deletes());
    }

    public function testWithoutIntegrationsNothingIsDeleted(): void
    {
        $this->uninstall();

        $this->assertSame([], $this->deletes());
        $this->assertContains("delete:pfx_core_config_data:path LIKE ? => carriers/skydropx/%", $this->calls);
    }

    public function testTheSameIntegrationIsDeletedOnce(): void
    {
        $this->mode = Config::MODE_EXTENSION;
        $this->integrationId = 7;
        $this->pending = [['id' => 7, 'at' => 1762459205]];

        $this->uninstall();

        $this->assertSame(['delete-integration:7'], $this->deletes());
    }

    public function testUnreadableConnectionStillRemovesTheData(): void
    {
        $config = $this->createMock(Config::class);
        $config->method('getIntegrationId')->willThrowException(new \RuntimeException('config'));
        $provisioner = $this->createMock(IntegrationProvisioner::class);
        $provisioner->method('getPending')->willThrowException(new \RuntimeException('flag'));
        $provisioner->expects($this->never())->method('delete');

        (new Uninstall($config, $provisioner))->uninstall(
            $this->schemaSetup(),
            $this->createMock(ModuleContextInterface::class)
        );

        $this->assertSame([
            'start',
            "delete:pfx_core_config_data:path LIKE ? => carriers/skydropx/%",
            "delete:pfx_flag:flag_code LIKE ? => skydropx\\_%",
            'exists:pfx_skydropx_order_event',
            'drop:pfx_skydropx_order_event',
            'end',
        ], $this->calls);
    }

    public function testEndsTheSetupWhenTheDatabaseFails(): void
    {
        $connection = $this->createMock(AdapterInterface::class);
        $connection->method('delete')->willThrowException(new \RuntimeException('db'));
        $setup = $this->createMock(SchemaSetupInterface::class);
        $setup->method('getConnection')->willReturn($connection);
        $setup->method('getTable')->willReturnArgument(0);
        $setup->expects($this->once())->method('startSetup');
        $setup->expects($this->once())->method('endSetup');

        $this->expectException(\RuntimeException::class);
        $this->sut()->uninstall($setup, $this->createMock(ModuleContextInterface::class));
    }

    /**
     * Run the uninstall with the fixture state.
     *
     * @return void
     */
    private function uninstall(): void
    {
        $this->sut()->uninstall($this->schemaSetup(), $this->createMock(ModuleContextInterface::class));
    }

    /**
     * Integration deletions only.
     *
     * @return string[]
     */
    private function deletes(): array
    {
        return array_values(array_filter(
            $this->calls,
            static fn (string $call): bool => strpos($call, 'delete-integration:') === 0
        ));
    }

    private function sut(): Uninstall
    {
        $config = $this->createMock(Config::class);
        $config->method('getIntegrationId')->willReturnCallback(fn () => $this->integrationId);
        $config->method('getConnectionMode')->willReturnCallback(fn () => $this->mode);

        $provisioner = $this->createMock(IntegrationProvisioner::class);
        $provisioner->method('getPending')->willReturnCallback(fn () => $this->pending);
        $provisioner->method('delete')->willReturnCallback(function (int $id): void {
            $this->calls[] = 'delete-integration:' . $id;
            if (in_array($id, $this->failing, true)) {
                throw new \RuntimeException('Integration ' . $id . ' does not exist.');
            }
        });

        return new Uninstall($config, $provisioner);
    }

    private function schemaSetup(): SchemaSetupInterface
    {
        $connection = $this->createMock(AdapterInterface::class);
        $connection->method('delete')->willReturnCallback(function (string $table, array $where): int {
            foreach ($where as $condition => $value) {
                $this->calls[] = 'delete:' . $table . ':' . $condition . ' => ' . $value;
            }

            return 0;
        });
        $connection->method('isTableExists')->willReturnCallback(function (string $table): bool {
            $this->calls[] = 'exists:' . $table;

            return $this->tableExists;
        });
        $connection->method('dropTable')->willReturnCallback(function (string $table): bool {
            $this->calls[] = 'drop:' . $table;

            return true;
        });

        $setup = $this->createMock(SchemaSetupInterface::class);
        $setup->method('getConnection')->willReturn($connection);
        $setup->method('getTable')->willReturnCallback(static fn (string $name): string => 'pfx_' . $name);
        $setup->method('startSetup')->willReturnCallback(function () use ($setup) {
            $this->calls[] = 'start';

            return $setup;
        });
        $setup->method('endSetup')->willReturnCallback(function () use ($setup) {
            $this->calls[] = 'end';

            return $setup;
        });

        return $setup;
    }
}
