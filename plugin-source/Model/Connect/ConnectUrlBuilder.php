<?php
/**
 * labels-light URLs of the one-click connection.
 */
declare(strict_types=1);

namespace Skydropx\Shipping\Model\Connect;

use Skydropx\Shipping\Model\Build;

/**
 * Every external redirect of the module goes to the constant Build::APP_BASE: no open redirect (HU §6.7).
 * The optional /:app_country_code/:locale prefixes are not sent; labels-light takes them from the session.
 */
class ConnectUrlBuilder
{
    /**
     * Confirmation page (contract §4.1).
     *
     * @param string $storeUrl
     * @param string $state
     * @param string $callbackUrl
     * @return string
     */
    public function connectUrl(string $storeUrl, string $state, string $callbackUrl): string
    {
        return $this->base() . '/merchant_stores/plugin_connect?' . $this->query([
            'platform' => Build::PLATFORM,
            'store_url' => $storeUrl,
            'state' => $state,
            'callback_url' => $callbackUrl,
        ]);
    }

    /**
     * Page that reads the consumed code and links the store (contract §4.5).
     *
     * @param string $code
     * @return string
     */
    public function completeUrl(string $code): string
    {
        return $this->base() . '/merchant_stores/plugin_connect/complete?' . $this->query([
            'platform' => Build::PLATFORM,
            'code' => $code,
        ]);
    }

    /**
     * "My stores" in labels-light.
     *
     * @return string
     */
    public function storesUrl(): string
    {
        return $this->base() . '/merchant_stores';
    }

    /**
     * Seam for tests (OD-3).
     *
     * @return string
     */
    protected function appBase(): string
    {
        return Build::APP_BASE;
    }

    /**
     * Base URL without trailing slash.
     *
     * @return string
     */
    private function base(): string
    {
        return rtrim($this->appBase(), '/');
    }

    /**
     * RFC 3986 query string.
     *
     * @param array $params
     * @return string
     */
    private function query(array $params): string
    {
        return http_build_query($params, '', '&', PHP_QUERY_RFC3986);
    }
}
