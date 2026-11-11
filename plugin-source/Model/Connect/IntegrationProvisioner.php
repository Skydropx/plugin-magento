<?php
/**
 * Magento integration and access token handed to eco (contract §6).
 */
declare(strict_types=1);

namespace Skydropx\Shipping\Model\Connect;

use Magento\Framework\Acl\AclResource\ProviderInterface;
use Magento\Framework\App\Cache\Type\Config as ConfigCacheType;
use Magento\Framework\App\Cache\TypeListInterface;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\App\Config\Storage\WriterInterface;
use Magento\Framework\Exception\IntegrationException;
use Magento\Framework\FlagManager;
use Magento\Integration\Api\IntegrationServiceInterface;
use Magento\Integration\Api\OauthServiceInterface;
use Magento\Integration\Model\Integration;
use Skydropx\Shipping\Model\Build;

/**
 * Creates a fresh integration per connection (D5) and keeps the list of integrations still waiting
 * for an exchange result, so the hourly cron can remove the ones left behind.
 */
class IntegrationProvisioner
{
    public const XML_PATH_BEARER = 'oauth/consumer/enable_integration_as_bearer';
    public const PENDING_FLAG = 'skydropx_pending_integrations';

    /**
     * Resources of the REST routes eco calls (OD-13, verified on 2.4.8), parents included, in this order.
     */
    public const RESOURCES = [
        'Magento_Sales::sales',
        'Magento_Sales::sales_operation',
        'Magento_Sales::sales_order',
        'Magento_Sales::actions',
        'Magento_Sales::actions_view',
        'Magento_Sales::comment',
        'Magento_Sales::ship',
        'Magento_Sales::shipment',
        'Magento_Backend::stores',
        'Magento_Backend::stores_settings',
        'Magento_Backend::store',
        'Magento_InventoryApi::inventory',
        'Magento_InventoryApi::source',
    ];

    /**
     * @param IntegrationServiceInterface $integrationService
     * @param OauthServiceInterface $oauthService
     * @param ProviderInterface $aclResourceProvider
     * @param ScopeConfigInterface $scopeConfig
     * @param WriterInterface $configWriter
     * @param TypeListInterface $cacheTypeList
     * @param FlagManager $flagManager
     */
    public function __construct(
        private IntegrationServiceInterface $integrationService,
        private OauthServiceInterface $oauthService,
        private ProviderInterface $aclResourceProvider,
        private ScopeConfigInterface $scopeConfig,
        private WriterInterface $configWriter,
        private TypeListInterface $cacheTypeList,
        private FlagManager $flagManager
    ) {
    }

    /**
     * Create the integration with its resources and an access token.
     *
     * The Magento_Integration plugin on IntegrationServiceInterface::create() grants the "resource" list (OD-13).
     *
     * @return array{integrationId: int, accessToken: string}
     * @throws ConnectException
     */
    public function provision(): array
    {
        try {
            $integration = $this->integrationService->create([
                'name' => Build::NAME . ' · ' . gmdate('Y-m-d H:i:s'),
                'status' => Integration::STATUS_ACTIVE,
                Integration::SETUP_TYPE => Integration::TYPE_MANUAL,
                'resource' => $this->resources(),
            ]);
        } catch (\Throwable $e) {
            throw new ConnectException(ConnectException::INTEGRATION_FAILED, null, $e->getMessage());
        }
        $integrationId = (int) $integration->getId();
        $consumerId = (int) $integration->getConsumerId();

        try {
            $this->oauthService->createAccessToken($consumerId, true);
            $token = $this->oauthService->getAccessToken($consumerId);
            $accessToken = $token ? (string) $token->getToken() : '';
        } catch (\Throwable $e) {
            $accessToken = '';
        }
        if ($integrationId <= 0 || $accessToken === '') {
            if ($integrationId > 0) {
                $this->delete($integrationId);
            }
            throw new ConnectException(ConnectException::INTEGRATION_FAILED);
        }

        return ['integrationId' => $integrationId, 'accessToken' => $accessToken];
    }

    /**
     * Delete an integration; this also revokes its token. A missing integration is not an error.
     *
     * @param int $integrationId
     * @return void
     */
    public function delete(int $integrationId): void
    {
        if ($integrationId <= 0) {
            return;
        }
        try {
            $this->integrationService->delete($integrationId);
        } catch (IntegrationException $e) {
            // Already gone.
            return;
        }
    }

    /**
     * Requested resources that exist in this installation (no MSI = no Magento_InventoryApi::*).
     *
     * @return string[]
     */
    public function resources(): array
    {
        $available = [];
        $this->collectIds($this->aclResourceProvider->getAclResources(), $available);

        return array_values(array_filter(
            self::RESOURCES,
            static fn (string $id): bool => isset($available[$id])
        ));
    }

    /**
     * Whether integration tokens are accepted as standalone Bearer tokens (contract §6).
     *
     * @return bool
     */
    public function isBearerEnabled(): bool
    {
        return $this->scopeConfig->isSetFlag(self::XML_PATH_BEARER, ScopeConfigInterface::SCOPE_TYPE_DEFAULT);
    }

    /**
     * Turn the Bearer setting on in the default scope. Only with the merchant's consent (D6).
     *
     * @return void
     */
    public function enableBearer(): void
    {
        $this->configWriter->save(self::XML_PATH_BEARER, '1', ScopeConfigInterface::SCOPE_TYPE_DEFAULT, 0);
        $this->cacheTypeList->cleanType(ConfigCacheType::TYPE_IDENTIFIER);
    }

    /**
     * Integrations created by a callback whose exchange has not finished.
     *
     * @return array List of ['id' => int, 'at' => int].
     */
    public function getPending(): array
    {
        $data = $this->flagManager->getFlagData(self::PENDING_FLAG);
        $pending = [];
        foreach (is_array($data) ? $data : [] as $entry) {
            $id = is_array($entry) ? (int) ($entry['id'] ?? 0) : 0;
            if ($id > 0) {
                $pending[] = ['id' => $id, 'at' => (int) ($entry['at'] ?? 0)];
            }
        }

        return $pending;
    }

    /**
     * Replace the pending list.
     *
     * @param array $pending List of ['id' => int, 'at' => int].
     * @return void
     */
    public function setPending(array $pending): void
    {
        if ($pending === []) {
            $this->flagManager->deleteFlag(self::PENDING_FLAG);

            return;
        }
        $this->flagManager->saveFlag(self::PENDING_FLAG, array_values($pending));
    }

    /**
     * Track a new integration until the exchange ends.
     *
     * @param int $integrationId
     * @return void
     */
    public function addPending(int $integrationId): void
    {
        $pending = $this->getPending();
        $pending[] = ['id' => $integrationId, 'at' => time()];
        $this->setPending($pending);
    }

    /**
     * Stop tracking an integration.
     *
     * @param int $integrationId
     * @return void
     */
    public function removePending(int $integrationId): void
    {
        $this->setPending(array_filter(
            $this->getPending(),
            static fn (array $entry): bool => $entry['id'] !== $integrationId
        ));
    }

    /**
     * Flatten the ACL tree (nodes with "id" and "children") into a set of ids.
     *
     * @param array $nodes
     * @param array $ids
     * @return void
     */
    private function collectIds(array $nodes, array &$ids): void
    {
        foreach ($nodes as $node) {
            if (!is_array($node)) {
                continue;
            }
            if (isset($node['id']) && is_string($node['id'])) {
                $ids[$node['id']] = true;
            }
            if (isset($node['children']) && is_array($node['children'])) {
                $this->collectIds($node['children'], $ids);
            }
        }
    }
}
