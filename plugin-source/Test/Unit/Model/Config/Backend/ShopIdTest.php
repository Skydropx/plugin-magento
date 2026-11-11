<?php
declare(strict_types=1);

namespace Skydropx\Shipping\Test\Unit\Model\Config\Backend;

use Magento\Framework\App\Cache\TypeListInterface;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\App\Config\Storage\WriterInterface;
use Magento\Framework\Event\ManagerInterface;
use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\Model\Context;
use Magento\Framework\Registry;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Skydropx\Shipping\Model\Config\Backend\ShopId;

class ShopIdTest extends TestCase
{
    private const UUID = '0b6f6a9e-3c1d-4f2a-9b8e-7d6c5b4a3f21';
    private const OTHER_UUID = 'f1e2d3c4-b5a6-4789-8abc-def012345678';

    /**
     * @var WriterInterface&MockObject
     */
    private $writer;

    protected function setUp(): void
    {
        $this->writer = $this->createMock(WriterInterface::class);
    }

    public function testValueIsNormalizedToLowerCase(): void
    {
        $model = $this->model(' ' . strtoupper(self::UUID) . ' ', '');

        $model->beforeSave();

        $this->assertSame(self::UUID, $model->getValue());
    }

    public function testEmptyValueIsAllowed(): void
    {
        $model = $this->model('', self::UUID);

        $model->beforeSave();

        $this->assertSame('', $model->getValue());
    }

    /**
     * @dataProvider invalidValues
     */
    public function testInvalidValueIsRejected(string $value): void
    {
        $this->expectException(LocalizedException::class);

        $this->model($value, '')->beforeSave();
    }

    public static function invalidValues(): array
    {
        return [
            'free text' => ['my-shop'],
            'missing group' => ['0b6f6a9e-3c1d-4f2a-9b8e-'],
            'no dashes' => ['0b6f6a9e3c1d4f2a9b8e7d6c5b4a3f21'],
            'non hex' => ['0b6f6a9e-3c1d-4f2a-9b8e-7d6c5b4a3fzz'],
            'extra chars' => [self::UUID . 'x'],
        ];
    }

    public function testChangedValueSwitchesToManualMode(): void
    {
        $this->writer->expects($this->once())->method('save')
            ->with('carriers/skydropx/connection_mode', 'manual', 'default', 0);
        $this->writer->expects($this->never())->method('delete');

        $model = $this->model(self::OTHER_UUID, self::UUID);
        $model->beforeSave();
        $model->afterSave();
    }

    public function testFirstManualValueSwitchesToManualMode(): void
    {
        $this->writer->expects($this->once())->method('save')
            ->with('carriers/skydropx/connection_mode', 'manual', 'default', 0);

        $model = $this->model(self::UUID, '');
        $model->beforeSave();
        $model->afterSave();
    }

    public function testSameValueInOtherCaseKeepsTheMode(): void
    {
        $this->writer->expects($this->never())->method('save');

        $model = $this->model(strtoupper(self::UUID), self::UUID);
        $model->beforeSave();
        $model->afterSave();
    }

    public function testClearingTheValueKeepsTheMode(): void
    {
        $this->writer->expects($this->never())->method('save');

        $model = $this->model('', self::UUID);
        $model->beforeSave();
        $model->afterSave();
    }

    /**
     * Backend model for carriers/skydropx/shop_id with $stored as the saved value.
     */
    private function model(string $value, string $stored): ShopId
    {
        $context = $this->createMock(Context::class);
        $context->method('getEventDispatcher')->willReturn($this->createMock(ManagerInterface::class));

        $scopeConfig = $this->createMock(ScopeConfigInterface::class);
        $scopeConfig->method('getValue')->willReturn($stored);

        $model = new ShopId(
            $context,
            $this->createMock(Registry::class),
            $scopeConfig,
            $this->createMock(TypeListInterface::class),
            $this->writer
        );
        $model->setPath('carriers/skydropx/shop_id');
        $model->setScope('default');
        $model->setScopeId(0);
        $model->setValue($value);

        return $model;
    }
}
