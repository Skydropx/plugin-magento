<?php
/**
 * Rate request to the checkout rates body (contract §2).
 */
declare(strict_types=1);

namespace Skydropx\Shipping\Model\Rates;

use Magento\Catalog\Model\Product;
use Magento\Catalog\Model\ResourceModel\Product\CollectionFactory as ProductCollectionFactory;
use Magento\Directory\Helper\Data as DirectoryHelper;
use Magento\Directory\Model\Currency;
use Magento\Directory\Model\RegionFactory;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\DataObject;
use Magento\Quote\Model\Quote\Address\RateRequest;
use Magento\Sales\Model\Order\Shipment;
use Magento\Store\Model\ScopeInterface;
use Skydropx\Shipping\Model\Config;

/**
 * Builds the array that the carrier serializes once and signs (contract §1, §2).
 * Regions and product dimensions are memoized for the request: no query per item.
 */
class PayloadBuilder
{
    private const SIMPLE_PRODUCT_OPTION = 'simple_product';

    /**
     * Region id => [code, name].
     *
     * @var array<int, array{0: string, 1: string}>
     */
    private array $regions = [];

    /**
     * Product id => dimensions.
     *
     * @var array<int, array{length: ?float, width: ?float, height: ?float}>
     */
    private array $dimensions = [];

    /**
     * @param ScopeConfigInterface $scopeConfig
     * @param RegionFactory $regionFactory
     * @param ProductCollectionFactory $productCollectionFactory
     * @param Config $config
     */
    public function __construct(
        private ScopeConfigInterface $scopeConfig,
        private RegionFactory $regionFactory,
        private ProductCollectionFactory $productCollectionFactory,
        private Config $config
    ) {
    }

    /**
     * Body of POST /rates/magento/MX/checkout (contract §2).
     *
     * @param RateRequest $request
     * @return array
     */
    public function build(RateRequest $request): array
    {
        $storeId = (int) $request->getStoreId();
        $allItems = is_array($request->getAllItems()) ? $request->getAllItems() : [];
        $first = reset($allItems);

        $items = [];
        foreach ($allItems as $item) {
            if ($item->getParentItem() || $this->isVirtual($item)) {
                continue;
            }
            $items[] = $item;
        }

        return [
            'quote_id' => $first ? (string) $first->getQuoteId() : '',
            'store_id' => $storeId,
            'currency' => $this->currency($request->getBaseCurrency(), $storeId),
            'origin' => $this->origin($storeId),
            'destination' => $this->destination($request),
            'package' => [
                'weight' => (float) $request->getPackageWeight(),
                'weight_unit' => $this->storeValue(DirectoryHelper::XML_PATH_WEIGHT_UNIT, $storeId),
                'value' => (float) $request->getPackageValue(),
                'qty' => (int) $request->getPackageQty(),
            ],
            'items' => $this->items($items, $storeId),
        ];
    }

    /**
     * Sample body for the panel's connection test: origin = store shipping origin, destination = same place.
     *
     * @param int $storeId
     * @return array
     */
    public function buildSample(int $storeId): array
    {
        $origin = $this->origin($storeId);
        $destination = $origin;
        $destination['street'] = [];

        return [
            'quote_id' => '',
            'store_id' => $storeId,
            'currency' => $this->currency(null, $storeId),
            'origin' => $origin,
            'destination' => $destination,
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
        ];
    }

    /**
     * Store base currency (HU D10). The request may carry a Currency model or a code.
     *
     * @param mixed $currency
     * @param int $storeId
     * @return string
     */
    private function currency($currency, int $storeId): string
    {
        $code = '';
        if ($currency instanceof Currency) {
            $code = (string) $currency->getCode();
        } elseif (is_string($currency)) {
            $code = $currency;
        }
        $code = trim($code);

        return $code !== '' ? $code : $this->storeValue(Currency::XML_PATH_CURRENCY_BASE, $storeId);
    }

    /**
     * Shipping origin from config: RateRequest has no origin street.
     *
     * @param int $storeId
     * @return array
     */
    private function origin(int $storeId): array
    {
        $regionId = $this->storeValue(Shipment::XML_PATH_STORE_REGION_ID, $storeId);
        if (ctype_digit($regionId)) {
            [$regionCode, $region] = $this->region((int) $regionId);
        } else {
            [$regionCode, $region] = ['', $regionId];
        }
        $street = [
            $this->storeValue(Shipment::XML_PATH_STORE_ADDRESS1, $storeId),
            $this->storeValue(Shipment::XML_PATH_STORE_ADDRESS2, $storeId),
        ];
        while ($street && end($street) === '') {
            array_pop($street);
        }

        return [
            'country' => $this->storeValue(Shipment::XML_PATH_STORE_COUNTRY_ID, $storeId),
            'region_code' => $regionCode,
            'region' => $region,
            'postcode' => $this->storeValue(Shipment::XML_PATH_STORE_ZIP, $storeId),
            'city' => $this->storeValue(Shipment::XML_PATH_STORE_CITY, $storeId),
            'street' => $street,
        ];
    }

    /**
     * Destination; street[0] is the street and street[1] the neighborhood (contract §2).
     *
     * @param RateRequest $request
     * @return array
     */
    private function destination(RateRequest $request): array
    {
        $regionId = $request->getDestRegionId();
        $region = is_numeric($regionId) && (int) $regionId > 0 ? $this->region((int) $regionId)[1] : '';
        $street = array_map('trim', explode("\n", (string) $request->getDestStreet()));

        return [
            'country' => (string) $request->getDestCountryId(),
            'region_code' => (string) $request->getDestRegionCode(),
            'region' => $region,
            'postcode' => (string) $request->getDestPostcode(),
            'city' => (string) $request->getDestCity(),
            'street' => array_values(array_filter($street, static fn (string $line): bool => $line !== '')),
        ];
    }

    /**
     * Items, weights per unit in the store weight unit.
     *
     * @param DataObject[] $items Shippable quote items (no children, no virtual).
     * @param int $storeId
     * @return array
     */
    private function items(array $items, int $storeId): array
    {
        $productIds = [];
        foreach ($items as $index => $item) {
            $productIds[$index] = $this->dimensionProductId($item);
        }
        $this->loadDimensions($productIds, $storeId);

        $result = [];
        foreach ($items as $index => $item) {
            $dimensions = $this->dimensions[$productIds[$index]]
                ?? ['length' => null, 'width' => null, 'height' => null];
            $result[] = [
                'sku' => (string) $item->getSku(),
                'name' => (string) $item->getName(),
                'qty' => (float) $item->getQty(),
                'weight' => (float) $item->getWeight(),
                'price' => (float) $item->getBasePrice(),
                'length' => $dimensions['length'],
                'width' => $dimensions['width'],
                'height' => $dimensions['height'],
            ];
        }

        return $result;
    }

    /**
     * Product holding the dimensions: the child of a configurable, otherwise the item's product.
     *
     * @param DataObject $item
     * @return int
     */
    private function dimensionProductId(DataObject $item): int
    {
        $option = $item->getOptionByCode(self::SIMPLE_PRODUCT_OPTION);
        if ($option instanceof DataObject && (int) $option->getProductId() > 0) {
            return (int) $option->getProductId();
        }

        return (int) $item->getProductId();
    }

    /**
     * One product collection for the ids not loaded yet, and only when a dimension attribute is mapped.
     *
     * @param int[] $productIds
     * @param int $storeId
     * @return void
     */
    private function loadDimensions(array $productIds, int $storeId): void
    {
        $codes = array_filter($this->config->getDimensionAttributes());
        $missing = array_values(array_unique(array_filter(
            $productIds,
            fn (int $id): bool => $id > 0 && !isset($this->dimensions[$id])
        )));
        if (!$codes || !$missing) {
            return;
        }

        $collection = $this->productCollectionFactory->create();
        $collection->setStoreId($storeId);
        $collection->addIdFilter($missing);
        $collection->addAttributeToSelect(array_values($codes));
        foreach ($collection->getItems() as $product) {
            $this->dimensions[(int) $product->getId()] = [
                'length' => $this->dimension($product, $codes['length'] ?? null),
                'width' => $this->dimension($product, $codes['width'] ?? null),
                'height' => $this->dimension($product, $codes['height'] ?? null),
            ];
        }
        foreach ($missing as $id) {
            $this->dimensions[$id] ??= ['length' => null, 'width' => null, 'height' => null];
        }
    }

    /**
     * Numeric attribute value as float; anything else is null.
     *
     * @param DataObject $product
     * @param string|null $attribute
     * @return float|null
     */
    private function dimension(DataObject $product, ?string $attribute): ?float
    {
        if ($attribute === null) {
            return null;
        }
        $value = $product->getData($attribute);

        return is_numeric($value) ? (float) $value : null;
    }

    /**
     * Whether the item is virtual (nothing to ship).
     *
     * @param DataObject $item
     * @return bool
     */
    private function isVirtual(DataObject $item): bool
    {
        if ($item->getIsVirtual()) {
            return true;
        }
        $product = $item->getProduct();

        return $product instanceof Product && $product->isVirtual();
    }

    /**
     * Region code and name, loaded once per id.
     *
     * @param int $regionId
     * @return array{0: string, 1: string}
     */
    private function region(int $regionId): array
    {
        if (!isset($this->regions[$regionId])) {
            $region = $this->regionFactory->create();
            $region->load($regionId);
            $this->regions[$regionId] = [(string) $region->getCode(), (string) $region->getName()];
        }

        return $this->regions[$regionId];
    }

    /**
     * Trimmed store-scope config value.
     *
     * @param string $path
     * @param int $storeId
     * @return string
     */
    private function storeValue(string $path, int $storeId): string
    {
        $value = $this->scopeConfig->getValue($path, ScopeInterface::SCOPE_STORE, $storeId);

        return is_scalar($value) ? trim((string) $value) : '';
    }
}
