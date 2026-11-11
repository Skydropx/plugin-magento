<?php
declare(strict_types=1);

namespace Skydropx\Shipping\Test\Unit\Model\Rates;

use Magento\Framework\App\CacheInterface;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Skydropx\Shipping\Model\Cache\Type\Rates;
use Skydropx\Shipping\Model\Config;
use Skydropx\Shipping\Model\Rates\RateCache;

class RateCacheTest extends TestCase
{
    private const SHOP_ID = '0b6f6a9e-3c1d-4f2a-9b8e-7d6c5b4a3f21';

    private int $now = 1762459205;

    private bool $typeDisabled = false;

    /**
     * Fake application cache honoring lifetimes against $now: id => [data, expires at].
     *
     * @var array<string, array{0: string, 1: ?int}>
     */
    private array $appCache = [];

    /**
     * What the rates cache type received: id => [data, tags, lifetime].
     *
     * @var array<string, array{0: string, 1: array, 2: ?int}>
     */
    private array $ratesSaved = [];

    /**
     * @var Rates&MockObject
     */
    private $ratesCache;

    /**
     * @var RateCache&MockObject
     */
    private $rateCache;

    protected function setUp(): void
    {
        $this->ratesCache = $this->getMockBuilder(Rates::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['load', 'save'])
            ->getMock();
        $this->ratesCache->method('load')->willReturnCallback(function ($id) {
            if ($this->typeDisabled) {
                // A disabled cache type: the pool's access proxy loads nothing.
                return false;
            }

            return isset($this->ratesSaved[$id]) ? $this->ratesSaved[$id][0] : false;
        });
        $this->ratesCache->method('save')->willReturnCallback(function ($data, $id, $tags = [], $lifetime = null) {
            $this->ratesSaved[$id] = [$data, $tags, $lifetime];

            return true;
        });

        $cache = $this->createMock(CacheInterface::class);
        $cache->method('load')->willReturnCallback(function ($id) {
            $entry = $this->appCache[$id] ?? null;
            if ($entry === null || ($entry[1] !== null && $this->now >= $entry[1])) {
                return false;
            }

            return $entry[0];
        });
        $cache->method('save')->willReturnCallback(function ($data, $id, $tags = [], $lifetime = null) {
            $this->appCache[$id] = [$data, $lifetime === null ? null : $this->now + $lifetime];

            return true;
        });
        $cache->method('remove')->willReturnCallback(function ($id) {
            unset($this->appCache[$id]);

            return true;
        });

        $config = $this->getMockBuilder(Config::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['getRatesCacheTtl'])
            ->getMock();
        $config->method('getRatesCacheTtl')->willReturn(300);

        $this->rateCache = $this->getMockBuilder(RateCache::class)
            ->setConstructorArgs([$this->ratesCache, $cache, $config])
            ->onlyMethods(['now'])
            ->getMock();
        $this->rateCache->method('now')->willReturnCallback(function () {
            return $this->now;
        });
    }

    public function testKeyIsStableForTheSameCartAndChangesWithTheAddress(): void
    {
        $body = '{"quote_id":"1","destination":{"postcode":"44100"}}';
        $otherAddress = '{"quote_id":"1","destination":{"postcode":"44200"}}';

        $key = $this->rateCache->key(self::SHOP_ID, $body);

        $this->assertSame($key, $this->rateCache->key(self::SHOP_ID, $body));
        $this->assertNotSame($key, $this->rateCache->key(self::SHOP_ID, $otherAddress));
        $this->assertNotSame($key, $this->rateCache->key('11111111-2222-4333-8444-555555555555', $body));
        $this->assertSame('skydropx_rates_' . hash('sha256', self::SHOP_ID . "\n" . $body), $key);
    }

    public function testRatesAreCachedForTheConfiguredTtl(): void
    {
        $rates = [['code' => 'fedex_express', 'price' => 189]];

        $this->rateCache->save('k', $rates);

        $this->assertSame(300, $this->ratesSaved['k'][2]);
        $this->assertContains(Rates::CACHE_TAG, $this->ratesSaved['k'][1]);
        $this->assertSame($rates, $this->rateCache->load('k'));
    }

    public function testEmptyListIsCachedForSixtySeconds(): void
    {
        $this->rateCache->save('k', []);

        $this->assertSame(60, $this->ratesSaved['k'][2]);
        $this->assertSame([], $this->rateCache->load('k'));
    }

    public function testMissOrUnreadableEntryIsNull(): void
    {
        $this->assertNull($this->rateCache->load('missing'));

        $this->ratesSaved['broken'] = ['not json', [], 300];
        $this->assertNull($this->rateCache->load('broken'));
    }

    public function testBreakerOpensAfterThreeFailuresAndClosesAfterSixtySeconds(): void
    {
        $this->rateCache->recordFailure();
        $this->now += 10;
        $this->rateCache->recordFailure();
        $this->assertFalse($this->rateCache->isOpen());

        $this->now += 10;
        $this->rateCache->recordFailure();
        $this->assertTrue($this->rateCache->isOpen());

        $this->now += 59;
        $this->assertTrue($this->rateCache->isOpen());

        $this->now += 1;
        $this->assertFalse($this->rateCache->isOpen());
    }

    public function testFailuresOutsideTheWindowDoNotAddUp(): void
    {
        $this->rateCache->recordFailure();
        $this->now += 30;
        $this->rateCache->recordFailure();
        $this->now += 31;
        $this->rateCache->recordFailure();

        $this->assertFalse($this->rateCache->isOpen());
    }

    public function testSuccessResetsTheFailureCount(): void
    {
        $this->rateCache->recordFailure();
        $this->rateCache->recordFailure();
        $this->rateCache->save('k', []);
        $this->rateCache->recordFailure();

        $this->assertFalse($this->rateCache->isOpen());
    }

    public function testErrorsAreNotCached(): void
    {
        $this->ratesCache->expects($this->never())->method('save');

        $this->rateCache->recordFailure();
        $this->rateCache->recordFailure();
        $this->rateCache->recordFailure();

        $this->assertSame([], $this->ratesSaved);
    }

    public function testRequestMemoryWorksWithTheCacheTypeDisabled(): void
    {
        $this->typeDisabled = true;
        $rates = [['code' => 'fedex_express', 'price' => 189]];

        $this->rateCache->save('full', $rates);
        $this->rateCache->save('empty', []);

        $this->assertSame($rates, $this->rateCache->load('full'));
        $this->assertSame([], $this->rateCache->load('empty'));
        $this->assertNull($this->rateCache->load('other'));
    }

    public function testRequestMemoryIsReadBeforeTheCacheType(): void
    {
        $this->rateCache->save('k', [['code' => 'a']]);
        // The persistent entry changes behind our back; the request keeps what it saw.
        $this->ratesSaved['k'][0] = '[{"code":"b"}]';

        $this->assertSame([['code' => 'a']], $this->rateCache->load('k'));
    }

    public function testRequestMemoryNeverHoldsErrors(): void
    {
        $this->typeDisabled = true;

        $this->rateCache->recordFailure();

        $this->assertNull($this->rateCache->load($this->rateCache->key(self::SHOP_ID, '{}')));
        $this->assertSame([], $this->ratesSaved);
    }
}
