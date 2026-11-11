<?php
/**
 * Typed access to carriers/skydropx/*.
 */
declare(strict_types=1);

namespace Skydropx\Shipping\Model;

use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Store\Model\ScopeInterface;

/**
 * Reads the module configuration; ScopeConfig values arrive already decrypted.
 * The connection lives in the default scope (one per installation).
 */
class Config
{
    public const XML_PATH_ACTIVE = 'carriers/skydropx/active';
    public const XML_PATH_TITLE = 'carriers/skydropx/title';
    public const XML_PATH_SHOP_ID = 'carriers/skydropx/shop_id';
    public const XML_PATH_SIGNING_SECRET = 'carriers/skydropx/signing_secret';
    public const XML_PATH_CONNECTION_MODE = 'carriers/skydropx/connection_mode';
    public const XML_PATH_INTEGRATION_ID = 'carriers/skydropx/integration_id';
    public const XML_PATH_CONNECTED_STORE_URL = 'carriers/skydropx/connected_store_url';
    public const XML_PATH_CONNECTED_AT = 'carriers/skydropx/connected_at';
    public const XML_PATH_ORDER_PUSH = 'carriers/skydropx/order_push';
    public const XML_PATH_DEBUG = 'carriers/skydropx/debug';
    public const XML_PATH_LENGTH_ATTRIBUTE = 'carriers/skydropx/length_attribute';
    public const XML_PATH_WIDTH_ATTRIBUTE = 'carriers/skydropx/width_attribute';
    public const XML_PATH_HEIGHT_ATTRIBUTE = 'carriers/skydropx/height_attribute';
    public const XML_PATH_RATES_TIMEOUT = 'carriers/skydropx/rates_timeout';
    public const XML_PATH_RATES_CACHE_TTL = 'carriers/skydropx/rates_cache_ttl';

    public const MODE_EXTENSION = 'extension';
    public const MODE_MANUAL = 'manual';

    public const UUID_PATTERN = '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/';

    private const DEFAULT_RATES_TIMEOUT = 4;
    private const DEFAULT_RATES_CACHE_TTL = 300;

    /**
     * @param ScopeConfigInterface $scopeConfig
     */
    public function __construct(
        private ScopeConfigInterface $scopeConfig
    ) {
    }

    /**
     * Whether rates are enabled at checkout. Order push does not depend on it.
     *
     * @param int|null $storeId
     * @return bool
     */
    public function isActive(?int $storeId = null): bool
    {
        return (bool) $this->scopeConfig->getValue(self::XML_PATH_ACTIVE, ScopeInterface::SCOPE_STORE, $storeId);
    }

    /**
     * Carrier title shown at checkout.
     *
     * @param int|null $storeId
     * @return string
     */
    public function getTitle(?int $storeId = null): string
    {
        return (string) $this->scopeConfig->getValue(self::XML_PATH_TITLE, ScopeInterface::SCOPE_STORE, $storeId);
    }

    /**
     * Shop UUID in lower case, or null when missing or malformed.
     *
     * @return string|null
     */
    public function getShopId(): ?string
    {
        $value = strtolower(trim($this->defaultValue(self::XML_PATH_SHOP_ID)));

        return preg_match(self::UUID_PATTERN, $value) === 1 ? $value : null;
    }

    /**
     * Signing secret in clear, or null when empty.
     *
     * Not decrypted here: the Encrypted backend_model declared in etc/config.xml makes
     * Magento's MetadataProcessor decrypt it when the config is loaded.
     *
     * @return string|null
     */
    public function getSigningSecret(): ?string
    {
        return $this->nullable($this->defaultValue(self::XML_PATH_SIGNING_SECRET));
    }

    /**
     * Connected = valid shop UUID + secret + a built package.
     *
     * @return bool
     */
    public function isConnected(): bool
    {
        return $this->getShopId() !== null && $this->getSigningSecret() !== null && $this->isBuilt();
    }

    /**
     * Connection mode: 'extension', 'manual' or null.
     *
     * @return string|null
     */
    public function getConnectionMode(): ?string
    {
        $mode = $this->defaultValue(self::XML_PATH_CONNECTION_MODE);

        return in_array($mode, [self::MODE_EXTENSION, self::MODE_MANUAL], true) ? $mode : null;
    }

    /**
     * Integration created by the one-click connection.
     *
     * @return int|null
     */
    public function getIntegrationId(): ?int
    {
        $value = $this->defaultValue(self::XML_PATH_INTEGRATION_ID);

        return ctype_digit($value) && (int) $value > 0 ? (int) $value : null;
    }

    /**
     * Store URL sent at connection time.
     *
     * @return string|null
     */
    public function getConnectedStoreUrl(): ?string
    {
        return $this->nullable($this->defaultValue(self::XML_PATH_CONNECTED_STORE_URL));
    }

    /**
     * Connection date (UTC ISO-8601).
     *
     * @return string|null
     */
    public function getConnectedAt(): ?string
    {
        return $this->nullable($this->defaultValue(self::XML_PATH_CONNECTED_AT));
    }

    /**
     * Whether orders are pushed automatically.
     *
     * @return bool
     */
    public function isOrderPushEnabled(): bool
    {
        return (bool) $this->defaultValue(self::XML_PATH_ORDER_PUSH);
    }

    /**
     * Whether debug logging is on.
     *
     * @return bool
     */
    public function isDebug(): bool
    {
        return (bool) $this->defaultValue(self::XML_PATH_DEBUG);
    }

    /**
     * Product attribute codes holding the dimensions (null = not mapped).
     *
     * @return array{length: ?string, width: ?string, height: ?string}
     */
    public function getDimensionAttributes(): array
    {
        return [
            'length' => $this->nullable($this->defaultValue(self::XML_PATH_LENGTH_ATTRIBUTE)),
            'width' => $this->nullable($this->defaultValue(self::XML_PATH_WIDTH_ATTRIBUTE)),
            'height' => $this->nullable($this->defaultValue(self::XML_PATH_HEIGHT_ATTRIBUTE)),
        ];
    }

    /**
     * Rates request timeout in seconds (eco answers within 3 s, contract §2).
     *
     * @return int
     */
    public function getRatesTimeout(): int
    {
        return $this->positiveInt(self::XML_PATH_RATES_TIMEOUT, self::DEFAULT_RATES_TIMEOUT);
    }

    /**
     * Rates cache lifetime in seconds.
     *
     * @return int
     */
    public function getRatesCacheTtl(): int
    {
        return $this->positiveInt(self::XML_PATH_RATES_CACHE_TTL, self::DEFAULT_RATES_CACHE_TTL);
    }

    /**
     * Seam for tests (OD-3).
     *
     * @return bool
     */
    protected function isBuilt(): bool
    {
        return Build::isBuilt();
    }

    /**
     * Default-scope value as a trimmed string.
     *
     * @param string $path
     * @return string
     */
    private function defaultValue(string $path): string
    {
        $value = $this->scopeConfig->getValue($path, ScopeConfigInterface::SCOPE_TYPE_DEFAULT);

        return is_scalar($value) ? trim((string) $value) : '';
    }

    /**
     * Empty string to null.
     *
     * @param string $value
     * @return string|null
     */
    private function nullable(string $value): ?string
    {
        return $value === '' ? null : $value;
    }

    /**
     * Positive integer setting with a fallback.
     *
     * @param string $path
     * @param int $default
     * @return int
     */
    private function positiveInt(string $path, int $default): int
    {
        $value = $this->defaultValue($path);

        return ctype_digit($value) && (int) $value > 0 ? (int) $value : $default;
    }
}
