<?php
declare(strict_types=1);

namespace Skydropx\Shipping\Test\Unit\Model\Config\Source;

use Magento\Catalog\Model\ResourceModel\Product\Attribute\Collection;
use Magento\Catalog\Model\ResourceModel\Product\Attribute\CollectionFactory;
use Magento\Framework\DataObject;
use Magento\Framework\DB\Adapter\AdapterInterface;
use Magento\Framework\DB\Select;
use PHPUnit\Framework\TestCase;
use Skydropx\Shipping\Model\Config\Source\ProductAttribute;

class ProductAttributeTest extends TestCase
{
    /**
     * WHERE conditions the collection received.
     *
     * @var string[]
     */
    private array $where = [];

    /**
     * @var int
     */
    private int $creates = 0;

    public function testSingleQueryIncludesNumericAndUserDefinedTextAttributes(): void
    {
        $source = new ProductAttribute($this->factory([
            new DataObject(['attribute_code' => 'ts_dimensions_length', 'frontend_label' => 'Length']),
            new DataObject(['attribute_code' => 'depth_cm', 'frontend_label' => '']),
        ]));

        $options = $source->toOptionArray();

        $this->assertCount(1, $this->where);
        $this->assertSame(
            "(main_table.backend_type IN ('decimal', 'int') AND main_table.frontend_input IN ('text', 'weight', 'price'))"
            . " OR (main_table.backend_type = 'varchar' AND main_table.frontend_input = 'text'"
            . " AND main_table.is_user_defined = '1')",
            $this->where[0]
        );
        $this->assertSame('', $options[0]['value']);
        $this->assertSame('ts_dimensions_length', $options[1]['value']);
        $this->assertSame('Length (ts_dimensions_length)', $options[1]['label']);
        $this->assertSame(['value' => 'depth_cm', 'label' => 'depth_cm'], $options[2]);
    }

    public function testOptionsAreLoadedOnce(): void
    {
        $source = new ProductAttribute($this->factory([]));

        $first = $source->toOptionArray();
        $second = $source->toOptionArray();

        $this->assertSame(1, $this->creates);
        $this->assertCount(1, $first);
        $this->assertSame($first, $second);
    }

    /**
     * Collection factory whose collection records WHERE clauses and yields $items.
     *
     * @param DataObject[] $items
     */
    private function factory(array $items): CollectionFactory
    {
        $connection = $this->createMock(AdapterInterface::class);
        $connection->method('quoteInto')->willReturnCallback(static function ($text, $value) {
            $quoted = implode(', ', array_map(static fn ($v) => "'" . $v . "'", (array) $value));

            return str_replace('?', $quoted, $text);
        });

        $select = $this->getMockBuilder(Select::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['where'])
            ->getMock();
        $select->method('where')->willReturnCallback(function ($condition) use ($select) {
            $this->where[] = $condition;

            return $select;
        });

        $collection = $this->getMockBuilder(Collection::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['getConnection', 'getSelect', 'setOrder', 'addFieldToFilter', 'getIterator'])
            ->getMock();
        $collection->method('getConnection')->willReturn($connection);
        $collection->method('getSelect')->willReturn($select);
        $collection->method('setOrder')->willReturnSelf();
        $collection->expects($this->never())->method('addFieldToFilter');
        $collection->method('getIterator')->willReturn(new \ArrayIterator($items));

        $factory = $this->getMockBuilder(CollectionFactory::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['create'])
            ->getMock();
        $factory->method('create')->willReturnCallback(function () use ($collection) {
            $this->creates++;

            return $collection;
        });

        return $factory;
    }
}
