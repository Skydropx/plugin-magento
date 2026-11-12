<?php
/**
 * Data removal for bin/magento module:uninstall Skydropx_Shipping --remove-data (HU §6.8).
 */
declare(strict_types=1);

namespace Skydropx\Shipping\Setup;

use Magento\Framework\Setup\ModuleContextInterface;
use Magento\Framework\Setup\SchemaSetupInterface;
use Magento\Framework\Setup\UninstallInterface;
use Skydropx\Shipping\Model\Config;
use Skydropx\Shipping\Model\Connect\IntegrationProvisioner;
use Skydropx\Shipping\Model\OrderPush\OutboxRepository;

/**
 * Removes what the module left in Magento. It does not unlink the store in the merchant account,
 * and it keeps oauth/consumer/enable_integration_as_bearer: the merchant turned it on for the store (D6).
 */
class Uninstall implements UninstallInterface
{
    public const CONFIG_PATH_LIKE = 'carriers/skydropx/%';

    /**
     * Flags of the module (skydropx_pending_integrations, skydropx_rates_health). "_" is escaped:
     * in LIKE it would match any character.
     */
    public const FLAG_CODE_LIKE = 'skydropx\_%';

    /**
     * @param Config $config
     * @param IntegrationProvisioner $provisioner
     */
    public function __construct(
        private Config $config,
        private IntegrationProvisioner $provisioner
    ) {
    }

    /**
     * Integrations, settings, flags and the outbox table, in this order. Any of them may be missing.
     *
     * @param SchemaSetupInterface $setup
     * @param ModuleContextInterface $context
     * @return void
     */
    public function uninstall(SchemaSetupInterface $setup, ModuleContextInterface $context): void
    {
        // Integrations first: deleting them needs the settings that are removed next.
        $this->deleteIntegrations();

        $setup->startSetup();
        try {
            $connection = $setup->getConnection();
            $connection->delete($setup->getTable('core_config_data'), ['path LIKE ?' => self::CONFIG_PATH_LIKE]);
            $connection->delete($setup->getTable('flag'), ['flag_code LIKE ?' => self::FLAG_CODE_LIKE]);
            $table = $setup->getTable(OutboxRepository::TABLE);
            if ($connection->isTableExists($table)) {
                $connection->dropTable($table);
            }
        } finally {
            $setup->endSetup();
        }
    }

    /**
     * Delete the one-click integration and the pending ones. Never throws.
     *
     * An integration that is already gone must not stop the rest of the uninstall.
     *
     * @return void
     */
    private function deleteIntegrations(): void
    {
        foreach ($this->integrationIds() as $id) {
            try {
                $this->provisioner->delete($id);
            } catch (\Throwable $e) {
                // Already gone or not deletable: keep going.
                continue;
            }
        }
    }

    /**
     * Ids to delete: the stored integration in any mode, plus every pending one (OD-15 m3).
     *
     * Only the one-click connection writes integration_id, so the module always created it.
     *
     * @return int[]
     */
    private function integrationIds(): array
    {
        try {
            $currentId = $this->config->getIntegrationId();
        } catch (\Throwable $e) {
            $currentId = null;
        }
        $ids = $currentId !== null ? [$currentId] : [];

        try {
            $pending = $this->provisioner->getPending();
        } catch (\Throwable $e) {
            $pending = [];
        }
        foreach ($pending as $entry) {
            $ids[] = $entry['id'];
        }

        return array_values(array_unique($ids));
    }
}
