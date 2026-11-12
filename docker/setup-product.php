<?php
/**
 * Creates or updates the E2E product (SKU E2E-1KG: simple, 1 kg, qty 100, in stock).
 * Called by setup-store.sh as www-data. Idempotent: a second run only re-applies the same values.
 */
declare(strict_types=1);

use Magento\Catalog\Api\Data\ProductInterfaceFactory;
use Magento\Catalog\Api\ProductRepositoryInterface;
use Magento\Catalog\Model\Product\Attribute\Source\Status;
use Magento\Catalog\Model\Product\Type;
use Magento\Catalog\Model\Product\Visibility;
use Magento\Framework\App\Area;
use Magento\Framework\App\Bootstrap;
use Magento\Framework\App\State;
use Magento\Framework\Exception\NoSuchEntityException;
use Magento\Store\Model\Store;
use Magento\Store\Model\StoreManagerInterface;

require '/var/www/html/app/bootstrap.php';

$sku = 'E2E-1KG';

$objectManager = Bootstrap::create(BP, $_SERVER)->getObjectManager();
$objectManager->get(State::class)->setAreaCode(Area::AREA_ADMINHTML);
// Admin store: values land on the default scope.
$objectManager->get(StoreManagerInterface::class)->setCurrentStore(Store::DEFAULT_STORE_ID);

/** @var ProductRepositoryInterface $repository */
$repository = $objectManager->get(ProductRepositoryInterface::class);

try {
    $product = $repository->get($sku, true, Store::DEFAULT_STORE_ID, true);
    $action = 'updated';
} catch (NoSuchEntityException $e) {
    $product = $objectManager->get(ProductInterfaceFactory::class)->create();
    $product->setSku($sku);
    $product->setUrlKey('producto-e2e-1kg');
    $action = 'created';
}

$product->setName('Producto E2E 1 kg');
$product->setTypeId(Type::TYPE_SIMPLE);
$product->setAttributeSetId((int) $product->getDefaultAttributeSetId());
$product->setStatus(Status::STATUS_ENABLED);
$product->setVisibility(Visibility::VISIBILITY_BOTH);
$product->setPrice(500);
$product->setWeight(1);
$product->setTaxClassId(2); // Taxable Goods
$product->setWebsiteIds([1]);
$product->setStockData([
    'use_config_manage_stock' => 1,
    'manage_stock' => 1,
    'qty' => 100,
    'is_in_stock' => 1,
]);

$saved = $repository->save($product);
printf("[setup-store] product %s %s (id %d, 1 kg, qty 100)\n", $sku, $action, (int) $saved->getId());
