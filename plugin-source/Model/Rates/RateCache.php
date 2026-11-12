<?php
/**
 * Rates cache per cart and circuit breaker.
 */
declare(strict_types=1);

namespace Skydropx\Shipping\Model\Rates;

use Magento\Framework\App\CacheInterface;
use Skydropx\Shipping\Model\Cache\Type\Rates;
use Skydropx\Shipping\Model\Config;

/**
 * Rates live in the "skydropx_rates" cache type (it may be disabled). The breaker lives in the
 * application cache, which is always on: 3 failures within 60 s open it for 60 s (HU D9).
 */
class RateCache
{
    public const EMPTY_TTL = 60;
    public const FAILURE_THRESHOLD = 3;
    public const FAILURE_WINDOW = 60;
    public const OPEN_TTL = 60;

    private const KEY_PREFIX = 'skydropx_rates_';
    private const FAILURES_KEY = 'skydropx_rates_failures';
    private const OPEN_KEY = 'skydropx_rates_open';

    /**
     * Rates resolved in this request, by key. Magento builds a new carrier on every collectRates()
     * call, so the request memory lives here, in the shared service (OD-15). Errors are never stored.
     *
     * @var array<string, array>
     */
    private array $memory = [];

    /**
     * @param Rates $ratesCache
     * @param CacheInterface $cache
     * @param Config $config
     */
    public function __construct(
        private Rates $ratesCache,
        private CacheInterface $cache,
        private Config $config
    ) {
    }

    /**
     * Cache key for a shop and the exact body sent to the service.
     *
     * @param string $shopId
     * @param string $rawBody
     * @return string
     */
    public function key(string $shopId, string $rawBody): string
    {
        return self::KEY_PREFIX . hash('sha256', $shopId . "\n" . $rawBody);
    }

    /**
     * Cached rates as returned by the service, or null on a miss. An empty array is a cached "no rates".
     *
     * @param string $key
     * @return array|null
     */
    public function load(string $key): ?array
    {
        if (isset($this->memory[$key])) {
            return $this->memory[$key];
        }
        $data = $this->ratesCache->load($key);
        if (!is_string($data) || $data === '') {
            return null;
        }
        $rates = json_decode($data, true);
        if (!is_array($rates)) {
            return null;
        }
        $this->memory[$key] = $rates;

        return $rates;
    }

    /**
     * Cache a successful answer in memory and in the cache type.
     *
     * TTL: the configured one, or 60 s for an empty list. Also closes the failure window.
     *
     * @param string $key
     * @param array $rates
     * @return void
     */
    public function save(string $key, array $rates): void
    {
        $this->memory[$key] = $rates;
        $ttl = $rates ? $this->config->getRatesCacheTtl() : self::EMPTY_TTL;
        $this->ratesCache->save(
            (string) json_encode($rates, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            $key,
            [Rates::CACHE_TAG],
            $ttl
        );
        $this->cache->remove(self::FAILURES_KEY);
    }

    /**
     * Whether the breaker is open (calls to the service are skipped).
     *
     * @return bool
     */
    public function isOpen(): bool
    {
        $value = $this->cache->load(self::OPEN_KEY);

        return is_string($value) && $value !== '';
    }

    /**
     * Count a failed call; the third one inside the window opens the breaker.
     *
     * @return void
     */
    public function recordFailure(): void
    {
        $now = $this->now();
        $state = json_decode((string) $this->cache->load(self::FAILURES_KEY), true);
        $count = (int) ($state['count'] ?? 0);
        $since = (int) ($state['since'] ?? 0);
        if ($count === 0 || $now - $since >= self::FAILURE_WINDOW) {
            $count = 0;
            $since = $now;
        }
        $count++;

        if ($count >= self::FAILURE_THRESHOLD) {
            $this->cache->save((string) $now, self::OPEN_KEY, [Rates::CACHE_TAG], self::OPEN_TTL);
            $this->cache->remove(self::FAILURES_KEY);

            return;
        }
        $this->cache->save(
            (string) json_encode(['count' => $count, 'since' => $since]),
            self::FAILURES_KEY,
            [Rates::CACHE_TAG],
            max(1, self::FAILURE_WINDOW - ($now - $since))
        );
    }

    /**
     * Seam for tests.
     *
     * @return int
     */
    protected function now(): int
    {
        return time();
    }
}
