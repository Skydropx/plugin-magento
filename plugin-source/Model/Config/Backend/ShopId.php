<?php
/**
 * Backend model of carriers/skydropx/shop_id.
 */
declare(strict_types=1);

namespace Skydropx\Shipping\Model\Config\Backend;

use Magento\Framework\App\Cache\TypeListInterface;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\App\Config\Storage\WriterInterface;
use Magento\Framework\App\Config\Value;
use Magento\Framework\Data\Collection\AbstractDb;
use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\Model\Context;
use Magento\Framework\Model\ResourceModel\AbstractResource;
use Magento\Framework\Registry;
use Skydropx\Shipping\Model\Config;

/**
 * Validates the shop UUID typed by the merchant. A changed, non-empty value switches the
 * connection to manual mode (contract §5). Integrations are left untouched.
 * ConnectionStore writes through WriterInterface, so it never goes through this model.
 */
class ShopId extends Value
{
    /**
     * @var WriterInterface
     */
    private WriterInterface $configWriter;

    /**
     * @param Context $context
     * @param Registry $registry
     * @param ScopeConfigInterface $config
     * @param TypeListInterface $cacheTypeList
     * @param WriterInterface $configWriter
     * @param AbstractResource|null $resource
     * @param AbstractDb|null $resourceCollection
     * @param array $data
     */
    public function __construct(
        Context $context,
        Registry $registry,
        ScopeConfigInterface $config,
        TypeListInterface $cacheTypeList,
        WriterInterface $configWriter,
        ?AbstractResource $resource = null,
        ?AbstractDb $resourceCollection = null,
        array $data = []
    ) {
        $this->configWriter = $configWriter;
        parent::__construct($context, $registry, $config, $cacheTypeList, $resource, $resourceCollection, $data);
    }

    /**
     * Normalize to lower case and reject anything that is not a UUID. Empty is allowed.
     *
     * @return $this
     * @throws LocalizedException
     */
    public function beforeSave()
    {
        $value = strtolower(trim((string) $this->getValue()));
        if ($value !== '' && preg_match(Config::UUID_PATTERN, $value) !== 1) {
            throw new LocalizedException(
                __('The store ID must be a UUID, for example 123e4567-e89b-12d3-a456-426614174000.')
            );
        }
        $this->setValue($value);

        return parent::beforeSave();
    }

    /**
     * Mark the connection as manual when the merchant changed the shop ID.
     *
     * @return $this
     */
    public function afterSave()
    {
        if ((string) $this->getValue() !== '' && $this->isValueChanged()) {
            $this->configWriter->save(
                Config::XML_PATH_CONNECTION_MODE,
                Config::MODE_MANUAL,
                ScopeConfigInterface::SCOPE_TYPE_DEFAULT,
                0
            );
        }

        return parent::afterSave();
    }
}
