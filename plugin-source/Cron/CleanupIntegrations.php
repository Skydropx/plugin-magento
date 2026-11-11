<?php
/**
 * Hourly removal of integrations left behind by unfinished connections (HU §6.4.8).
 */
declare(strict_types=1);

namespace Skydropx\Shipping\Cron;

use Skydropx\Shipping\Logger\Logger;
use Skydropx\Shipping\Model\Config;
use Skydropx\Shipping\Model\Connect\IntegrationProvisioner;

/**
 * Deletes pending integrations older than one hour, except the one the connection uses.
 */
class CleanupIntegrations
{
    public const MAX_AGE = 3600;

    /**
     * @param IntegrationProvisioner $provisioner
     * @param Config $config
     * @param Logger $logger
     */
    public function __construct(
        private IntegrationProvisioner $provisioner,
        private Config $config,
        private Logger $logger
    ) {
    }

    /**
     * Run the cleanup. Never throws.
     *
     * @return void
     */
    public function execute(): void
    {
        try {
            $pending = $this->provisioner->getPending();
            if ($pending === []) {
                return;
            }
            $currentId = $this->config->getIntegrationId();
            $now = $this->now();
            $keep = [];
            foreach ($pending as $entry) {
                if ($now - $entry['at'] <= self::MAX_AGE) {
                    $keep[] = $entry;
                    continue;
                }
                if ($entry['id'] === $currentId) {
                    continue;
                }
                try {
                    $this->provisioner->delete($entry['id']);
                    $this->logger->info('INTEGRATION_CLEANUP', ['integration_id' => $entry['id']]);
                } catch (\Throwable $e) {
                    $keep[] = $entry;
                    $this->logger->error(
                        'INTEGRATION_CLEANUP',
                        ['integration_id' => $entry['id'], 'detail' => $e->getMessage()]
                    );
                }
            }
            $this->provisioner->setPending($keep);
        } catch (\Throwable $e) {
            $this->logger->error('INTEGRATION_CLEANUP', ['detail' => $e->getMessage()]);
        }
    }

    /**
     * Clock seam for tests.
     *
     * @return int
     */
    protected function now(): int
    {
        return time();
    }
}
