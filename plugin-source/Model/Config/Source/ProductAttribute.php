<?php
/**
 * Numeric product attributes for the dimension settings.
 */
declare(strict_types=1);

namespace Skydropx\Shipping\Model\Config\Source;

use Magento\Catalog\Model\ResourceModel\Product\Attribute\CollectionFactory;
use Magento\Framework\Data\OptionSourceInterface;

/**
 * Product attributes that can hold a dimension, plus an empty option: decimal/int with a
 * text/weight/price input, or a user-defined varchar with a text input.
 */
class ProductAttribute implements OptionSourceInterface
{
    /**
     * @var array|null
     */
    private ?array $options = null;

    /**
     * @param CollectionFactory $collectionFactory
     */
    public function __construct(
        private CollectionFactory $collectionFactory
    ) {
    }

    /**
     * @inheritDoc
     */
    public function toOptionArray()
    {
        if ($this->options !== null) {
            return $this->options;
        }

        $collection = $this->collectionFactory->create();
        $connection = $collection->getConnection();
        // Numeric storage, or a merchant-created "Text Field" (stored as varchar). System text
        // attributes (name, url_key...) are excluded; the payload builder drops non-numeric values.
        $numeric = $connection->quoteInto('main_table.backend_type IN (?)', ['decimal', 'int'])
            . ' AND ' . $connection->quoteInto('main_table.frontend_input IN (?)', ['text', 'weight', 'price']);
        $textField = $connection->quoteInto('main_table.backend_type = ?', 'varchar')
            . ' AND ' . $connection->quoteInto('main_table.frontend_input = ?', 'text')
            . ' AND ' . $connection->quoteInto('main_table.is_user_defined = ?', 1);
        $collection->getSelect()->where('(' . $numeric . ') OR (' . $textField . ')');
        $collection->setOrder('frontend_label', 'ASC');

        $this->options = [['value' => '', 'label' => __('-- None --')]];
        foreach ($collection as $attribute) {
            $code = (string) $attribute->getAttributeCode();
            $label = (string) $attribute->getFrontendLabel();
            $this->options[] = [
                'value' => $code,
                'label' => $label !== '' ? sprintf('%s (%s)', $label, $code) : $code,
            ];
        }

        return $this->options;
    }
}
