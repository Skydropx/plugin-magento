<?php
declare(strict_types=1);

namespace Skydropx\Shipping\Test\Unit\Model\Rates;

use Magento\Catalog\Model\Product;
use Magento\Catalog\Model\ResourceModel\Product\Collection as ProductCollection;
use Magento\Catalog\Model\ResourceModel\Product\CollectionFactory as ProductCollectionFactory;
use Magento\Directory\Model\Currency;
use Magento\Directory\Model\Region;
use Magento\Directory\Model\RegionFactory;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\DataObject;
use Magento\Quote\Model\Quote\Address\RateRequest;
use Magento\Quote\Model\Quote\Item;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Skydropx\Shipping\Model\Config;
use Skydropx\Shipping\Model\Http\EcoClient;
use Skydropx\Shipping\Model\Rates\PayloadBuilder;

class PayloadBuilderTest extends TestCase
{
    /**
     * Body of HU §2.3 (contract §2).
     */
    private const CONTRACT_EXAMPLE = <<<'JSON'
{
  "quote_id": "123",
  "store_id": 1,
  "currency": "MXN",
  "origin":      { "country": "MX", "region_code": "CMX", "region": "Ciudad de México", "postcode": "06600", "city": "Ciudad de México", "street": ["Reforma 1", "Juárez"] },
  "destination": { "country": "MX", "region_code": "JAL", "region": "Jalisco", "postcode": "44100", "city": "Guadalajara", "street": ["Av. Juárez 10", "Centro"] },
  "package": { "weight": 1.5, "weight_unit": "kgs", "value": 899.0, "qty": 2 },
  "items": [ { "sku": "TSHIRT-M", "name": "T-shirt", "qty": 2, "weight": 0.75, "price": 449.5, "length": null, "width": null, "height": null } ]
}
JSON;

    private const REGION_CMX = 1001;
    private const REGION_JAL = 1002;

    /**
     * @var array<string, mixed>
     */
    private array $settings;

    /**
     * @var array{length: ?string, width: ?string, height: ?string}
     */
    private array $dimensionAttributes = ['length' => null, 'width' => null, 'height' => null];

    /**
     * @var RegionFactory&MockObject
     */
    private $regionFactory;

    /**
     * @var ProductCollectionFactory&MockObject
     */
    private $collectionFactory;

    private PayloadBuilder $builder;

    protected function setUp(): void
    {
        $this->settings = [
            'shipping/origin/country_id' => 'MX',
            'shipping/origin/region_id' => (string) self::REGION_CMX,
            'shipping/origin/postcode' => '06600',
            'shipping/origin/city' => 'Ciudad de México',
            'shipping/origin/street_line1' => 'Reforma 1',
            'shipping/origin/street_line2' => 'Juárez',
            'general/locale/weight_unit' => 'kgs',
            'currency/options/base' => 'MXN',
        ];

        $scopeConfig = $this->createMock(ScopeConfigInterface::class);
        $scopeConfig->method('getValue')->willReturnCallback(function ($path) {
            return $this->settings[$path] ?? null;
        });

        $regions = [
            self::REGION_CMX => ['code' => 'CMX', 'name' => 'Ciudad de México'],
            self::REGION_JAL => ['code' => 'JAL', 'name' => 'Jalisco'],
        ];
        $this->regionFactory = $this->getMockBuilder(RegionFactory::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['create'])
            ->getMock();
        $this->regionFactory->method('create')->willReturnCallback(function () use ($regions) {
            $region = $this->getMockBuilder(Region::class)
                ->disableOriginalConstructor()
                ->onlyMethods(['load'])
                ->getMock();
            $region->method('load')->willReturnCallback(function ($id) use ($region, $regions) {
                $region->setData($regions[(int) $id] ?? []);

                return $region;
            });

            return $region;
        });

        $this->collectionFactory = $this->getMockBuilder(ProductCollectionFactory::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['create'])
            ->getMock();

        $config = $this->getMockBuilder(Config::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['getDimensionAttributes'])
            ->getMock();
        $config->method('getDimensionAttributes')->willReturnCallback(function () {
            return $this->dimensionAttributes;
        });

        $this->builder = new PayloadBuilder($scopeConfig, $this->regionFactory, $this->collectionFactory, $config);
    }

    /**
     * MX address with region and neighborhood, no dimension attribute: exactly the contract example.
     */
    public function testBuildsTheContractExample(): void
    {
        $this->collectionFactory->expects($this->never())->method('create');

        $payload = $this->builder->build($this->exampleRequest());

        $this->assertEquals(
            json_decode(self::CONTRACT_EXAMPLE, true),
            json_decode(json_encode($payload, EcoClient::JSON_FLAGS), true)
        );
        $this->assertSame(['Av. Juárez 10', 'Centro'], $payload['destination']['street']);
        $this->assertSame('CMX', $payload['origin']['region_code']);
    }

    public function testStreetOnOneLine(): void
    {
        $request = $this->exampleRequest();
        $request->setDestStreet('Av. Juárez 10');

        $this->assertSame(['Av. Juárez 10'], $this->builder->build($request)['destination']['street']);
    }

    public function testCartEstimateWithoutStreet(): void
    {
        $request = $this->exampleRequest();
        $request->setDestStreet(null);

        $this->assertSame([], $this->builder->build($request)['destination']['street']);
    }

    public function testPoundsAreSentAsIs(): void
    {
        $this->settings['general/locale/weight_unit'] = 'lbs';
        $request = $this->exampleRequest();
        $request->setPackageWeight(3.3);

        $payload = $this->builder->build($request);

        $this->assertSame('lbs', $payload['package']['weight_unit']);
        $this->assertSame(3.3, $payload['package']['weight']);
        $this->assertSame(0.75, $payload['items'][0]['weight']);
    }

    public function testConfigurableChildrenAndVirtualItemsAreExcluded(): void
    {
        $parent = $this->item(['sku' => 'SHIRT-RED-M', 'name' => 'Shirt', 'product_id' => 20], new DataObject(['product_id' => 21]));
        $child = $this->item(['sku' => 'SHIRT-RED-M', 'name' => 'Shirt red M', 'product_id' => 21], null, $parent);
        $virtual = $this->item(['sku' => 'WARRANTY', 'name' => 'Warranty', 'is_virtual' => 1, 'product_id' => 30]);
        $virtualProduct = $this->getMockBuilder(Product::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['isVirtual'])
            ->getMock();
        $virtualProduct->method('isVirtual')->willReturn(true);
        $download = $this->item(['sku' => 'EBOOK', 'name' => 'E-book', 'product_id' => 31], null, null, $virtualProduct);

        $request = $this->exampleRequest();
        $request->setAllItems([$parent, $child, $virtual, $download]);

        $items = $this->builder->build($request)['items'];

        $this->assertCount(1, $items);
        $this->assertSame('SHIRT-RED-M', $items[0]['sku']);
        $this->assertSame('Shirt', $items[0]['name']);
    }

    public function testDimensionsComeFromOneCollectionLoadedOncePerRequest(): void
    {
        $this->dimensionAttributes = ['length' => 'ts_length', 'width' => 'ts_width', 'height' => null];
        $collection = $this->getMockBuilder(ProductCollection::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['setStoreId', 'addIdFilter', 'addAttributeToSelect', 'getItems'])
            ->getMock();
        $collection->expects($this->once())->method('setStoreId')->with(1)->willReturnSelf();
        $collection->expects($this->once())->method('addIdFilter')->with([21, 40])->willReturnSelf();
        $collection->expects($this->once())->method('addAttributeToSelect')
            ->with(['ts_length', 'ts_width'])->willReturnSelf();
        $collection->method('getItems')->willReturn([
            21 => new DataObject(['id' => 21, 'ts_length' => '30.5', 'ts_width' => '']),
            40 => new DataObject(['id' => 40, 'ts_length' => '10', 'ts_width' => 'n/a']),
        ]);
        $this->collectionFactory->expects($this->once())->method('create')->willReturn($collection);

        $configurable = $this->item(['sku' => 'SHIRT-RED-M', 'product_id' => 20], new DataObject(['product_id' => 21]));
        $simple = $this->item(['sku' => 'MUG', 'product_id' => 40]);
        $request = $this->exampleRequest();
        $request->setAllItems([$configurable, $simple]);

        $first = $this->builder->build($request);
        $second = $this->builder->build($request);

        $this->assertSame(
            ['length' => 30.5, 'width' => null, 'height' => null],
            array_intersect_key($first['items'][0], array_flip(['length', 'width', 'height']))
        );
        $this->assertSame(
            ['length' => 10.0, 'width' => null, 'height' => null],
            array_intersect_key($first['items'][1], array_flip(['length', 'width', 'height']))
        );
        $this->assertSame($first, $second);
    }

    public function testNoDimensionAttributeMeansNullsAndNoQuery(): void
    {
        $this->collectionFactory->expects($this->never())->method('create');

        $item = $this->builder->build($this->exampleRequest())['items'][0];

        $this->assertNull($item['length']);
        $this->assertNull($item['width']);
        $this->assertNull($item['height']);
    }

    public function testOriginRegionAsText(): void
    {
        $this->settings['shipping/origin/region_id'] = 'Ciudad de México';
        $this->settings['shipping/origin/street_line2'] = '';

        $origin = $this->builder->build($this->exampleRequest())['origin'];

        $this->assertSame('', $origin['region_code']);
        $this->assertSame('Ciudad de México', $origin['region']);
        $this->assertSame(['Reforma 1'], $origin['street']);
    }

    public function testRegionsAreLoadedOncePerRequest(): void
    {
        $this->regionFactory->expects($this->exactly(2))->method('create');

        $this->builder->build($this->exampleRequest());
        $this->builder->build($this->exampleRequest());
    }

    public function testCurrencyAsStringAndConfigFallback(): void
    {
        $request = $this->exampleRequest();
        $request->setBaseCurrency('USD');
        $this->assertSame('USD', $this->builder->build($request)['currency']);

        $request->setBaseCurrency(null);
        $this->assertSame('MXN', $this->builder->build($request)['currency']);
    }

    public function testBuildSample(): void
    {
        $this->assertSame(
            [
                'quote_id' => '',
                'store_id' => 1,
                'currency' => 'MXN',
                'origin' => [
                    'country' => 'MX',
                    'region_code' => 'CMX',
                    'region' => 'Ciudad de México',
                    'postcode' => '06600',
                    'city' => 'Ciudad de México',
                    'street' => ['Reforma 1', 'Juárez'],
                ],
                'destination' => [
                    'country' => 'MX',
                    'region_code' => 'CMX',
                    'region' => 'Ciudad de México',
                    'postcode' => '06600',
                    'city' => 'Ciudad de México',
                    'street' => [],
                ],
                'package' => ['weight' => 1.0, 'weight_unit' => 'kgs', 'value' => 100.0, 'qty' => 1],
                'items' => [
                    [
                        'sku' => 'TEST',
                        'name' => 'Test',
                        'qty' => 1,
                        'weight' => 1.0,
                        'price' => 100.0,
                        'length' => null,
                        'width' => null,
                        'height' => null,
                    ],
                ],
            ],
            $this->builder->buildSample(1)
        );
    }

    /**
     * Request matching the contract example.
     *
     * @return RateRequest
     */
    private function exampleRequest(): RateRequest
    {
        $currency = $this->getMockBuilder(Currency::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['getCode'])
            ->getMock();
        $currency->method('getCode')->willReturn('MXN');

        return new RateRequest([
            'store_id' => 1,
            'base_currency' => $currency,
            'dest_country_id' => 'MX',
            'dest_region_id' => self::REGION_JAL,
            'dest_region_code' => 'JAL',
            'dest_postcode' => '44100',
            'dest_city' => 'Guadalajara',
            'dest_street' => "Av. Juárez 10\nCentro",
            'package_weight' => 1.5,
            'package_value' => 899,
            'package_qty' => 2,
            'all_items' => [
                $this->item([
                    'quote_id' => 123,
                    'sku' => 'TSHIRT-M',
                    'name' => 'T-shirt',
                    'qty' => 2,
                    'weight' => 0.75,
                    'base_price' => 449.5,
                    'product_id' => 10,
                ]),
            ],
        ]);
    }

    /**
     * Quote item mock; magic getters read the data set here.
     *
     * @param array $data
     * @param DataObject|null $simpleOption
     * @param Item|null $parent
     * @param Product|null $product
     * @return Item&MockObject
     */
    private function item(array $data, ?DataObject $simpleOption = null, ?Item $parent = null, ?Product $product = null)
    {
        $item = $this->getMockBuilder(Item::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['getParentItem', 'getProduct', 'getOptionByCode'])
            ->getMock();
        $item->setData($data + ['qty' => 1, 'weight' => 1, 'base_price' => 10]);
        $item->method('getParentItem')->willReturn($parent);
        $item->method('getProduct')->willReturn($product);
        $item->method('getOptionByCode')->willReturnCallback(function ($code) use ($simpleOption) {
            return $code === 'simple_product' ? $simpleOption : null;
        });

        return $item;
    }
}
