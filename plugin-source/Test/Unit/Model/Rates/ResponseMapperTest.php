<?php
declare(strict_types=1);

namespace Skydropx\Shipping\Test\Unit\Model\Rates;

use Magento\Framework\Phrase;
use Magento\Framework\Phrase\Renderer\Placeholder;
use Magento\Framework\Pricing\PriceCurrencyInterface;
use Magento\Quote\Model\Quote\Address\RateResult\Method;
use Magento\Quote\Model\Quote\Address\RateResult\MethodFactory;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Skydropx\Shipping\Logger\Logger;
use Skydropx\Shipping\Model\Config;
use Skydropx\Shipping\Model\Rates\ResponseMapper;

class ResponseMapperTest extends TestCase
{
    /**
     * @var Logger&MockObject
     */
    private $logger;

    private ResponseMapper $mapper;

    protected function setUp(): void
    {
        Phrase::setRenderer(new Placeholder());

        $priceCurrency = $this->createMock(PriceCurrencyInterface::class);
        $priceCurrency->method('round')->willReturnArgument(0);
        $methodFactory = $this->getMockBuilder(MethodFactory::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['create'])
            ->getMock();
        $methodFactory->method('create')->willReturnCallback(static function () use ($priceCurrency) {
            return new Method($priceCurrency);
        });

        $config = $this->getMockBuilder(Config::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['getTitle'])
            ->getMock();
        $config->method('getTitle')->with(1)->willReturn('Envíos');

        $this->logger = $this->createMock(Logger::class);
        $this->mapper = new ResponseMapper($methodFactory, $config, $this->logger);
    }

    public function testMapsRatesInServiceOrderIgnoringUnknownFields(): void
    {
        $methods = $this->mapper->map([
            $this->rate(['code' => 'fedex_express', 'price' => 189, 'unknown' => ['x' => 1]]),
            $this->rate(['code' => 'dhl_economy', 'carrier' => 'DHL', 'service' => 'Economy', 'price' => 99.5]),
            $this->rate(['code' => 'estafeta_free', 'carrier' => 'Estafeta', 'service' => 'Terrestre', 'price' => 0]),
        ], 1, 'MXN');

        $this->assertSame(['fedex_express', 'dhl_economy', 'estafeta_free'], array_map(
            static fn (Method $method) => $method->getMethod(),
            $methods
        ));
        $first = $methods[0];
        $this->assertSame('skydropx', $first->getCarrier());
        $this->assertSame('Envíos', $first->getCarrierTitle());
        $this->assertSame('FedEx Express (2 days)', $first->getMethodTitle());
        $this->assertSame(189.0, $first->getPrice());
        $this->assertSame(189.0, $first->getCost());
        $this->assertSame(0.0, $methods[2]->getPrice());
    }

    /**
     * @dataProvider invalidCodes
     * @param mixed $code
     */
    public function testInvalidCodeIsDiscardedWithLog($code): void
    {
        $this->logger->expects($this->once())->method('warning')->with(
            'RATES_FAILED',
            $this->callback(static fn (array $context) => $context['reason'] === 'rate_discarded'
                && $context['field'] === 'code')
        );

        $this->assertSame([], $this->mapper->map([$this->rate(['code' => $code])], 1, 'MXN'));
    }

    public static function invalidCodes(): array
    {
        return [
            'upper case' => ['FedEx_express'],
            'dash' => ['fedex-express'],
            'empty' => [''],
            'number' => [123],
            'missing' => [null],
        ];
    }

    public function testCodeLengthLimit(): void
    {
        // "skydropx_" (9) + code must fit sales_order.shipping_method (120).
        $longest = str_repeat('a', 111);
        $tooLong = str_repeat('a', 112);
        $this->logger->expects($this->once())->method('warning');

        $methods = $this->mapper->map([$this->rate(['code' => $tooLong]), $this->rate(['code' => $longest])], 1, 'MXN');

        $this->assertCount(1, $methods);
        $this->assertSame($longest, $methods[0]->getMethod());
    }

    public function testOtherCurrencyIsDiscarded(): void
    {
        $this->logger->expects($this->once())->method('warning')->with(
            'RATES_FAILED',
            $this->callback(static fn (array $context) => $context['field'] === 'currency')
        );

        $methods = $this->mapper->map([$this->rate(['currency' => 'USD']), $this->rate(['code' => 'dhl_x'])], 1, 'MXN');

        $this->assertCount(1, $methods);
        $this->assertSame('dhl_x', $methods[0]->getMethod());
    }

    /**
     * @dataProvider invalidPrices
     * @param mixed $price
     */
    public function testInvalidPriceIsDiscarded($price): void
    {
        $this->logger->expects($this->once())->method('warning')->with(
            'RATES_FAILED',
            $this->callback(static fn (array $context) => $context['field'] === 'price')
        );

        $this->assertSame([], $this->mapper->map([$this->rate(['price' => $price])], 1, 'MXN'));
    }

    public static function invalidPrices(): array
    {
        return [
            'negative' => [-1],
            'text' => ['free'],
            'missing' => [null],
        ];
    }

    /**
     * @dataProvider titles
     * @param mixed $min
     * @param mixed $max
     * @param string $expected
     */
    public function testTitleWithDays($min, $max, string $expected): void
    {
        $methods = $this->mapper->map([$this->rate(['min_days' => $min, 'max_days' => $max])], 1, 'MXN');

        $this->assertSame($expected, $methods[0]->getMethodTitle());
    }

    public static function titles(): array
    {
        return [
            'same days' => [3, 3, 'FedEx Express (3 days)'],
            'one day' => [1, 1, 'FedEx Express (1 day)'],
            'range' => [1, 3, 'FedEx Express (1-3 days)'],
            'no days' => [null, null, 'FedEx Express'],
            'only min' => [2, null, 'FedEx Express'],
            'zero' => [0, 0, 'FedEx Express'],
            'inverted' => [3, 1, 'FedEx Express'],
        ];
    }

    public function testNonArrayRateIsDiscarded(): void
    {
        $this->logger->expects($this->once())->method('warning');

        $this->assertSame([], $this->mapper->map(['oops'], 1, 'MXN'));
    }

    /**
     * Valid rate (contract §2 example) with overrides.
     *
     * @param array $overrides
     * @return array
     */
    private function rate(array $overrides = []): array
    {
        return $overrides + [
            'code' => 'fedex_express',
            'carrier' => 'FedEx',
            'service' => 'Express',
            'price' => 189,
            'currency' => 'MXN',
            'min_days' => 2,
            'max_days' => 2,
        ];
    }
}
