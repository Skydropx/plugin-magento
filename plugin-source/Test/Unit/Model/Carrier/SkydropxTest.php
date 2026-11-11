<?php
declare(strict_types=1);

namespace Skydropx\Shipping\Test\Unit\Model\Carrier;

use Magento\Framework\App\CacheInterface;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\Phrase;
use Magento\Framework\Phrase\Renderer\Placeholder;
use Magento\Framework\Pricing\PriceCurrencyInterface;
use Magento\Quote\Model\Quote\Address\RateRequest;
use Magento\Quote\Model\Quote\Address\RateResult\Error;
use Magento\Quote\Model\Quote\Address\RateResult\ErrorFactory;
use Magento\Quote\Model\Quote\Address\RateResult\Method;
use Magento\Quote\Model\Quote\Address\RateResult\MethodFactory;
use Magento\Shipping\Model\Rate\Result;
use Magento\Shipping\Model\Rate\ResultFactory;
use Magento\Store\Model\StoreManagerInterface;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Skydropx\Shipping\Logger\Logger;
use Skydropx\Shipping\Model\Cache\Type\Rates;
use Skydropx\Shipping\Model\Carrier\Skydropx;
use Skydropx\Shipping\Model\Config;
use Skydropx\Shipping\Model\Http\EcoClient;
use Skydropx\Shipping\Model\Http\EcoResponse;
use Skydropx\Shipping\Model\Rates\HealthRecorder;
use Skydropx\Shipping\Model\Rates\PayloadBuilder;
use Skydropx\Shipping\Model\Rates\RateCache;
use Skydropx\Shipping\Model\Rates\ResponseMapper;

class SkydropxTest extends TestCase
{
    private const SHOP_ID = '0b6f6a9e-3c1d-4f2a-9b8e-7d6c5b4a3f21';
    private const PAYLOAD = [
        'quote_id' => '123',
        'store_id' => 1,
        'currency' => 'MXN',
        'destination' => ['country' => 'MX', 'postcode' => '44100', 'city' => 'Guadalajara'],
        'items' => [['sku' => 'TSHIRT-M', 'qty' => 2.0]],
    ];
    private const RATES = [
        ['code' => 'fedex_express', 'carrier' => 'FedEx', 'service' => 'Express', 'price' => 189,
            'currency' => 'MXN', 'min_days' => 2, 'max_days' => 2],
        ['code' => 'dhl_economy', 'carrier' => 'DHL', 'service' => 'Economy', 'price' => 99.5,
            'currency' => 'MXN', 'min_days' => 3, 'max_days' => 5],
    ];

    /**
     * carriers/skydropx/* values.
     *
     * @var array<string, mixed>
     */
    private array $settings;

    private bool $connected = true;

    private ?\Throwable $buildError = null;

    /**
     * @var Logger&MockObject
     */
    private $logger;

    /**
     * @var PayloadBuilder&MockObject
     */
    private $payloadBuilder;

    /**
     * @var RateCache&MockObject
     */
    private $rateCache;

    /**
     * @var HealthRecorder&MockObject
     */
    private $healthRecorder;

    /**
     * @var EcoClient&MockObject
     */
    private $ecoClient;

    private Skydropx $carrier;

    /**
     * Builds a carrier around a given RateCache (Magento creates one carrier per collectRates call).
     *
     * @var \Closure
     */
    private \Closure $newCarrier;

    /**
     * @var Config&MockObject
     */
    private $config;

    protected function setUp(): void
    {
        Phrase::setRenderer(new Placeholder());
        $this->settings = [
            'active' => '1',
            'title' => 'Envíos',
            'showmethod' => '0',
            'specificerrmsg' => 'Not available.',
        ];

        $scopeConfig = $this->createMock(ScopeConfigInterface::class);
        $scopeConfig->method('getValue')->willReturnCallback(function ($path) {
            return $this->settings[substr($path, strlen('carriers/skydropx/'))] ?? null;
        });
        $scopeConfig->method('isSetFlag')->willReturnCallback(function ($path) {
            return (bool) ($this->settings[substr($path, strlen('carriers/skydropx/'))] ?? false);
        });

        $errorFactory = $this->getMockBuilder(ErrorFactory::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['create'])
            ->getMock();
        $errorFactory->method('create')->willReturnCallback(static fn () => new Error());

        $storeManager = $this->createMock(StoreManagerInterface::class);
        $resultFactory = $this->getMockBuilder(ResultFactory::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['create'])
            ->getMock();
        $resultFactory->method('create')->willReturnCallback(static fn () => new Result($storeManager));

        $config = $this->getMockBuilder(Config::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['isConnected', 'getShopId', 'getRatesTimeout', 'getTitle', 'getRatesCacheTtl'])
            ->getMock();
        $config->method('isConnected')->willReturnCallback(function () {
            return $this->connected;
        });
        $config->method('getShopId')->willReturn(self::SHOP_ID);
        $config->method('getRatesTimeout')->willReturn(4);
        $config->method('getTitle')->willReturn('Envíos');
        $config->method('getRatesCacheTtl')->willReturn(300);

        $this->logger = $this->createMock(Logger::class);

        $this->payloadBuilder = $this->getMockBuilder(PayloadBuilder::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['build'])
            ->getMock();
        $this->payloadBuilder->method('build')->willReturnCallback(function () {
            if ($this->buildError !== null) {
                throw $this->buildError;
            }

            return self::PAYLOAD;
        });

        $priceCurrency = $this->createMock(PriceCurrencyInterface::class);
        $priceCurrency->method('round')->willReturnArgument(0);
        $methodFactory = $this->getMockBuilder(MethodFactory::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['create'])
            ->getMock();
        $methodFactory->method('create')->willReturnCallback(static fn () => new Method($priceCurrency));
        $responseMapper = new ResponseMapper($methodFactory, $config, $this->logger);

        // key() stays real: it has no dependencies.
        $this->rateCache = $this->getMockBuilder(RateCache::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['load', 'save', 'isOpen', 'recordFailure'])
            ->getMock();

        $this->healthRecorder = $this->getMockBuilder(HealthRecorder::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['record'])
            ->getMock();

        $this->ecoClient = $this->getMockBuilder(EcoClient::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['postSigned'])
            ->getMock();

        $this->newCarrier = function (RateCache $rateCache) use (
            $scopeConfig,
            $errorFactory,
            $resultFactory,
            $config,
            $responseMapper
        ): Skydropx {
            return new Skydropx(
                $scopeConfig,
                $errorFactory,
                $this->logger,
                $resultFactory,
                $config,
                $this->payloadBuilder,
                $responseMapper,
                $rateCache,
                $this->healthRecorder,
                $this->ecoClient
            );
        };
        $this->config = $config;
        $this->carrier = ($this->newCarrier)($this->rateCache);
    }

    public function testContract(): void
    {
        $this->assertSame('/api/v3/rates/magento/MX/checkout', Skydropx::RATES_PATH);
        $this->assertSame('skydropx', $this->carrier->getCarrierCode());
        $this->assertSame(['skydropx' => 'Envíos'], $this->carrier->getAllowedMethods());
    }

    public function testInactiveCarrierIsHidden(): void
    {
        $this->settings['active'] = '0';
        $this->expectNoQuote();

        $this->assertFalse($this->carrier->collectRates($this->request()));
    }

    public function testNotConnectedIsHidden(): void
    {
        $this->connected = false;
        $this->expectNoQuote();

        $this->assertFalse($this->carrier->collectRates($this->request()));
    }

    public function testCartEstimateWithoutPostcodeIsNotQuoted(): void
    {
        $this->expectNoQuote();

        $this->assertFalse($this->carrier->collectRates($this->request(['dest_postcode' => ''])));
    }

    public function testDestinationOutsideMexicoIsNotQuoted(): void
    {
        $this->expectNoQuote();

        $this->assertFalse($this->carrier->collectRates($this->request(['dest_country_id' => 'US'])));
    }

    public function testSuccessReturnsRatesCachesThemAndRecordsHealth(): void
    {
        $rawBody = json_encode(self::PAYLOAD, EcoClient::JSON_FLAGS);
        $key = $this->rateCache->key(self::SHOP_ID, $rawBody);
        $this->rateCache->method('load')->willReturn(null);
        $this->rateCache->method('isOpen')->willReturn(false);
        $this->ecoClient->expects($this->once())->method('postSigned')
            ->with(Skydropx::RATES_PATH, $rawBody, ['shop_id' => self::SHOP_ID], [], 4)
            ->willReturn(new EcoResponse(200, json_encode(['rates' => self::RATES, 'extra' => true])));
        $this->rateCache->expects($this->once())->method('save')->with($key, self::RATES);
        $this->healthRecorder->expects($this->once())->method('record')->with(HealthRecorder::STATUS_OK, 200);

        $result = $this->carrier->collectRates($this->request());

        $this->assertInstanceOf(Result::class, $result);
        $rates = $result->getAllRates();
        $this->assertCount(2, $rates);
        $this->assertSame('fedex_express', $rates[0]->getMethod());
        $this->assertSame('FedEx Express (2 days)', $rates[0]->getMethodTitle());
        $this->assertSame('DHL Economy (3-5 days)', $rates[1]->getMethodTitle());
        $this->assertSame(99.5, $rates[1]->getPrice());
    }

    public function testEmptyListHidesTheCarrierAndIsCached(): void
    {
        $this->rateCache->method('load')->willReturn(null);
        $this->rateCache->method('isOpen')->willReturn(false);
        $this->ecoClient->method('postSigned')->willReturn(new EcoResponse(200, '{"rates":[]}'));
        $this->rateCache->expects($this->once())->method('save')->with($this->anything(), []);
        $this->rateCache->expects($this->never())->method('recordFailure');
        $this->healthRecorder->expects($this->once())->method('record')->with(HealthRecorder::STATUS_EMPTY, 200);

        $this->assertFalse($this->carrier->collectRates($this->request()));
    }

    public function testCacheHitSkipsTheService(): void
    {
        $this->rateCache->method('load')->willReturn(self::RATES);
        $this->ecoClient->expects($this->never())->method('postSigned');

        $result = $this->carrier->collectRates($this->request());

        $this->assertInstanceOf(Result::class, $result);
        $this->assertCount(2, $result->getAllRates());
    }

    /**
     * Magento builds a new carrier per collectRates(); the shared RateCache keeps the request memory
     * (OD-15), even with the "skydropx_rates" cache type disabled.
     */
    public function testTwoCarrierInstancesSharingRateCacheCallTheServiceOnce(): void
    {
        $disabledType = $this->getMockBuilder(Rates::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['load', 'save'])
            ->getMock();
        $disabledType->method('load')->willReturn(false);
        $disabledType->method('save')->willReturn(true);
        $appCache = $this->createMock(CacheInterface::class);
        $appCache->method('load')->willReturn(false);
        $sharedCache = new RateCache($disabledType, $appCache, $this->config);

        $this->ecoClient->expects($this->once())->method('postSigned')
            ->willReturn(new EcoResponse(200, json_encode(['rates' => self::RATES])));

        $first = ($this->newCarrier)($sharedCache)->collectRates($this->request());
        $second = ($this->newCarrier)($sharedCache)->collectRates($this->request());

        $this->assertCount(2, $first->getAllRates());
        $this->assertCount(2, $second->getAllRates());
    }

    public function testOpenBreakerSkipsTheService(): void
    {
        $this->rateCache->method('load')->willReturn(null);
        $this->rateCache->method('isOpen')->willReturn(true);
        $this->ecoClient->expects($this->never())->method('postSigned');

        $this->assertFalse($this->carrier->collectRates($this->request()));
    }

    public function testUnauthorizedIsHiddenLoggedAndNotCounted(): void
    {
        $this->rateCache->method('load')->willReturn(null);
        $this->rateCache->method('isOpen')->willReturn(false);
        $this->ecoClient->method('postSigned')->willReturn(new EcoResponse(401, null, null, time()));
        $this->logger->expects($this->once())->method('warning')->with(
            'RATES_UNAUTHORIZED',
            $this->callback(static fn (array $context) => $context['status'] === 401
                && !isset($context['clock_skew_seconds']))
        );
        $this->healthRecorder->expects($this->once())->method('record')
            ->with(HealthRecorder::STATUS_UNAUTHORIZED, 401);
        $this->rateCache->expects($this->never())->method('recordFailure');
        $this->rateCache->expects($this->never())->method('save');

        $this->assertFalse($this->carrier->collectRates($this->request()));
    }

    public function testUnauthorizedWithSkewedClockSaysSo(): void
    {
        $this->rateCache->method('load')->willReturn(null);
        $this->rateCache->method('isOpen')->willReturn(false);
        $this->ecoClient->method('postSigned')->willReturn(new EcoResponse(401, null, null, time() - 600));
        $this->logger->expects($this->once())->method('warning')->with(
            'RATES_UNAUTHORIZED',
            $this->callback(static fn (array $context) => $context['clock_skew_seconds'] >= 600
                && isset($context['hint']))
        );

        $this->assertFalse($this->carrier->collectRates($this->request()));
    }

    public function testTimeoutIsHiddenCountedAndNotCached(): void
    {
        $this->rateCache->method('load')->willReturn(null);
        $this->rateCache->method('isOpen')->willReturn(false);
        $this->ecoClient->method('postSigned')
            ->willReturn(new EcoResponse(0, null, 'Operation timed out after 4001 milliseconds with 0 bytes received'));
        $this->logger->expects($this->once())->method('warning')->with(
            'RATES_FAILED',
            $this->callback(static fn (array $context) => $context['reason'] === 'timeout')
        );
        $this->rateCache->expects($this->once())->method('recordFailure');
        $this->rateCache->expects($this->never())->method('save');
        $this->healthRecorder->expects($this->once())->method('record')->with(HealthRecorder::STATUS_TIMEOUT, null);

        $this->assertFalse($this->carrier->collectRates($this->request()));
    }

    public function testServerErrorIsHiddenAndCounted(): void
    {
        $this->rateCache->method('load')->willReturn(null);
        $this->rateCache->method('isOpen')->willReturn(false);
        $this->ecoClient->method('postSigned')->willReturn(new EcoResponse(503, '{"errors":[{"detail":"down"}]}'));
        $this->rateCache->expects($this->once())->method('recordFailure');
        $this->rateCache->expects($this->never())->method('save');
        $this->healthRecorder->expects($this->once())->method('record')->with(HealthRecorder::STATUS_FAILED, 503);

        $this->assertFalse($this->carrier->collectRates($this->request()));
    }

    public function testShowMethodReturnsTheConfiguredError(): void
    {
        $this->settings['showmethod'] = '1';
        $this->rateCache->method('load')->willReturn(null);
        $this->rateCache->method('isOpen')->willReturn(false);
        $this->ecoClient->method('postSigned')->willReturn(new EcoResponse(200, '{"rates":[]}'));

        $result = $this->carrier->collectRates($this->request());

        $this->assertInstanceOf(Result::class, $result);
        $this->assertTrue((bool) $result->getError());
        $error = $result->getAllRates()[0];
        $this->assertInstanceOf(Error::class, $error);
        $this->assertSame('skydropx', $error->getCarrier());
        $this->assertSame('Envíos', $error->getCarrierTitle());
        $this->assertSame('Not available.', $error->getErrorMessage());
    }

    public function testThrowableIsLoggedAndHidden(): void
    {
        $this->settings['showmethod'] = '1';
        $this->buildError = new \RuntimeException('boom');
        $this->logger->expects($this->once())->method('error')->with('RATES_FAILED');

        $this->assertFalse($this->carrier->collectRates($this->request()));
    }

    /**
     * Nothing must be built nor sent.
     */
    private function expectNoQuote(): void
    {
        $this->payloadBuilder->expects($this->never())->method('build');
        $this->ecoClient->expects($this->never())->method('postSigned');
    }

    /**
     * Checkout request in Mexico.
     *
     * @param array $overrides
     * @return RateRequest
     */
    private function request(array $overrides = []): RateRequest
    {
        return new RateRequest($overrides + [
            'store_id' => 1,
            'dest_country_id' => 'MX',
            'dest_postcode' => '44100',
        ]);
    }
}
