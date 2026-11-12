<?php
/**
 * Store URL and admin callback for the one-click connection.
 */
declare(strict_types=1);

namespace Skydropx\Shipping\Model\Connect;

use Magento\Backend\Model\UrlInterface as BackendUrlInterface;
use Magento\Framework\UrlInterface;
use Magento\Store\Model\StoreManagerInterface;
use Skydropx\Shipping\Model\Build;

/**
 * Applies the same host rules as eco (store-url.ts) and the same-origin rule of labels-light (contract §4).
 */
class StoreUrlResolver
{
    public const CALLBACK_ROUTE = 'skydropx/connect/callback';

    /**
     * Names that never resolve to a public store (store-url.ts INTERNAL_SUFFIXES).
     */
    private const INTERNAL_SUFFIXES = ['.localhost', '.local', '.internal', '.lan', '.home.arpa'];

    private const DEFAULT_PORTS = ['https' => 443, 'http' => 80];

    /**
     * @param StoreManagerInterface $storeManager
     * @param BackendUrlInterface $backendUrl
     */
    public function __construct(
        private StoreManagerInterface $storeManager,
        private BackendUrlInterface $backendUrl
    ) {
    }

    /**
     * Full preflight: built package, valid store URL and a callback on the same origin (D4).
     *
     * @return array{store_url: string, callback_url: string}
     * @throws ConnectException
     */
    public function resolve(): array
    {
        if (!$this->isBuilt()) {
            throw new ConnectException(ConnectException::NOT_BUILT);
        }
        $storeUrl = $this->storeUrl();
        $callbackUrl = $this->callbackUrl();
        if (!$this->isSameOrigin($storeUrl, $callbackUrl)) {
            throw new ConnectException(ConnectException::ORIGIN_MISMATCH);
        }

        return ['store_url' => $storeUrl, 'callback_url' => $callbackUrl];
    }

    /**
     * Secure base URL of the default store view of the default website, validated.
     *
     * @return string
     * @throws ConnectException
     */
    public function storeUrl(): string
    {
        $store = $this->storeManager->getDefaultStoreView();
        if ($store === null) {
            throw new ConnectException(ConnectException::INVALID_STORE_URL);
        }

        return $this->validateStoreUrl((string) $store->getBaseUrl(UrlInterface::URL_TYPE_WEB, true));
    }

    /**
     * Absolute admin URL of the callback, with the secret key.
     *
     * @return string
     */
    public function callbackUrl(): string
    {
        return (string) $this->backendUrl->getUrl(self::CALLBACK_ROUTE, ['_nosid' => true]);
    }

    /**
     * Check a store URL with the rules of eco (store-url.ts).
     *
     * Https (http only in local), no credentials and a public host name: not an IP, not single-label,
     * not localhost and not an internal suffix.
     *
     * @param string $url
     * @return string The trimmed URL.
     * @throws ConnectException
     */
    public function validateStoreUrl(string $url): string
    {
        $url = trim($url);
        // phpcs:ignore Magento2.Functions.DiscouragedFunction -- plain RFC 3986 split, no I/O; same parts as eco.
        $parts = parse_url($url);
        if (!is_array($parts) || !isset($parts['scheme'], $parts['host'])) {
            throw new ConnectException(ConnectException::INVALID_STORE_URL);
        }
        $allowInsecure = $this->isLocal();
        $schemes = $allowInsecure ? ['https', 'http'] : ['https'];
        if (!in_array(strtolower($parts['scheme']), $schemes, true)) {
            throw new ConnectException(ConnectException::INVALID_STORE_URL);
        }
        if (isset($parts['user']) || isset($parts['pass'])) {
            throw new ConnectException(ConnectException::INVALID_STORE_URL);
        }
        $host = $this->normalizeHost($parts['host']);
        if ($host === '' || (!$allowInsecure && !$this->isPublicHostName($host))) {
            throw new ConnectException(ConnectException::INVALID_STORE_URL);
        }

        return $url;
    }

    /**
     * Origin "scheme://host:port": lower-case host without trailing dot and the effective port.
     *
     * @param string $url
     * @return string|null Null when the URL is not absolute.
     */
    public function origin(string $url): ?string
    {
        // phpcs:ignore Magento2.Functions.DiscouragedFunction -- plain RFC 3986 split, no I/O; same parts as eco.
        $parts = parse_url(trim($url));
        if (!is_array($parts) || !isset($parts['scheme'], $parts['host'])) {
            return null;
        }
        $scheme = strtolower($parts['scheme']);
        $host = $this->normalizeHost($parts['host']);
        $port = isset($parts['port']) ? (int) $parts['port'] : (self::DEFAULT_PORTS[$scheme] ?? 0);
        if ($host === '' || $port === 0) {
            return null;
        }

        return $scheme . '://' . $host . ':' . $port;
    }

    /**
     * Whether two URLs share scheme, host and port.
     *
     * @param string $first
     * @param string $second
     * @return bool
     */
    public function isSameOrigin(string $first, string $second): bool
    {
        $origin = $this->origin($first);

        return $origin !== null && $origin === $this->origin($second);
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
     * Seam for tests (OD-3).
     *
     * @return bool
     */
    protected function isLocal(): bool
    {
        return Build::isLocal();
    }

    /**
     * Lower case, without the trailing dot.
     *
     * @param string $host
     * @return string
     */
    private function normalizeHost(string $host): string
    {
        return rtrim(strtolower($host), '.');
    }

    /**
     * Same as isPublicHostName() in store-url.ts.
     *
     * @param string $host
     * @return bool
     */
    private function isPublicHostName(string $host): bool
    {
        $bare = trim($host, '[]');
        if (filter_var($bare, FILTER_VALIDATE_IP) !== false || $this->endsInNumber($host)) {
            return false;
        }
        if (strpos($host, '.') === false || $host === 'localhost') {
            return false;
        }
        foreach (self::INTERNAL_SUFFIXES as $suffix) {
            if (substr($host, -strlen($suffix)) === $suffix) {
                return false;
            }
        }

        return true;
    }

    /**
     * A host whose last label is a number is an IPv4 address for browsers (WHATWG URL), e.g. 0x7f000001 or 127.1.
     *
     * @param string $host
     * @return bool
     */
    private function endsInNumber(string $host): bool
    {
        $labels = explode('.', $host);
        $last = (string) end($labels);

        return preg_match('/^(0x[0-9a-f]*|[0-9]+)$/', $last) === 1;
    }
}
