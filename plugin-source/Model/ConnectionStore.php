<?php
/**
 * Persists the connection (default scope, one per installation).
 */
declare(strict_types=1);

namespace Skydropx\Shipping\Model;

use Magento\Framework\App\Cache\Type\Config as ConfigCacheType;
use Magento\Framework\App\Cache\TypeListInterface;
use Magento\Framework\App\CacheInterface;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\App\Config\Storage\WriterInterface;
use Magento\Framework\Encryption\EncryptorInterface;

/**
 * Writes and clears the connection. Writes go straight to core_config_data, so the
 * ShopId backend model is not involved and connection_mode stays "extension".
 */
class ConnectionStore
{
    /**
     * Same value as Model\Cache\Type\Rates::CACHE_TAG.
     */
    private const RATES_CACHE_TAG = 'SKYDROPX_RATES';

    /**
     * @param WriterInterface $configWriter
     * @param EncryptorInterface $encryptor
     * @param TypeListInterface $cacheTypeList
     * @param CacheInterface $cache
     */
    public function __construct(
        private WriterInterface $configWriter,
        private EncryptorInterface $encryptor,
        private TypeListInterface $cacheTypeList,
        private CacheInterface $cache
    ) {
    }

    /**
     * Save the result of a one-click connection (contract §4.4).
     *
     * @param string $shopId
     * @param string $signingSecret
     * @param int $integrationId
     * @param string $storeUrl
     * @return void
     */
    public function saveExtensionConnection(
        string $shopId,
        string $signingSecret,
        int $integrationId,
        string $storeUrl
    ): void {
        $values = [
            Config::XML_PATH_SHOP_ID => strtolower(trim($shopId)),
            Config::XML_PATH_SIGNING_SECRET => $this->encryptor->encrypt($signingSecret),
            Config::XML_PATH_CONNECTION_MODE => Config::MODE_EXTENSION,
            Config::XML_PATH_INTEGRATION_ID => (string) $integrationId,
            Config::XML_PATH_CONNECTED_STORE_URL => $storeUrl,
            Config::XML_PATH_CONNECTED_AT => gmdate('Y-m-d\TH:i:s\Z'),
        ];
        foreach ($values as $path => $value) {
            $this->configWriter->save($path, $value, ScopeConfigInterface::SCOPE_TYPE_DEFAULT, 0);
        }
        $this->cleanCaches();
    }

    /**
     * Remove the connection from Magento. It does not unlink the store in the merchant account.
     *
     * @return void
     */
    public function clear(): void
    {
        foreach ([
            Config::XML_PATH_SHOP_ID,
            Config::XML_PATH_SIGNING_SECRET,
            Config::XML_PATH_CONNECTION_MODE,
            Config::XML_PATH_INTEGRATION_ID,
            Config::XML_PATH_CONNECTED_STORE_URL,
            Config::XML_PATH_CONNECTED_AT,
        ] as $path) {
            $this->configWriter->delete($path, ScopeConfigInterface::SCOPE_TYPE_DEFAULT, 0);
        }
        $this->cleanCaches();
    }

    /**
     * Drop cached config and cached rates of the previous connection.
     *
     * @return void
     */
    private function cleanCaches(): void
    {
        $this->cacheTypeList->cleanType(ConfigCacheType::TYPE_IDENTIFIER);
        $this->cache->clean([self::RATES_CACHE_TAG]);
    }
}
