<?php
declare(strict_types=1);

namespace Skydropx\Shipping\Test\Unit\Model\OrderPush;

use Magento\Framework\UrlInterface;
use Magento\Sales\Api\Data\OrderInterface;
use Magento\Store\Model\Store;
use Magento\Store\Model\StoreManagerInterface;
use PHPUnit\Framework\TestCase;
use Skydropx\Shipping\Model\Http\EcoClient;
use Skydropx\Shipping\Model\OrderPush\EventFactory;

class EventFactoryTest extends TestCase
{
    /**
     * Contract §3 example (HU §2.4) without sent_at, which the publisher adds at send time.
     */
    private const CONTRACT_EXAMPLE = '{ "event": "order.created", "entity_id": "42", "increment_id": "000000042",'
        . ' "state": "processing", "status": "processing", "store_base_url": "https://shop.example.com/",'
        . ' "created_at": "2026-11-06 20:00:00" }';

    public function testBuildsTheContractPayload(): void
    {
        $event = $this->factory(3)->fromOrder($this->order(3), EventFactory::EVENT_CREATED);

        // Compared decoded (HU §9.3), after the same serialization the outbox uses.
        $this->assertSame(
            json_decode(self::CONTRACT_EXAMPLE, true),
            json_decode(json_encode($event, EcoClient::JSON_FLAGS), true)
        );
        $this->assertArrayNotHasKey('sent_at', $event);
    }

    public function testFieldTypes(): void
    {
        $event = $this->factory(3)->fromOrder($this->order(3), EventFactory::EVENT_UPDATED);

        $this->assertSame('order.updated', $event['event']);
        $this->assertSame('42', $event['entity_id'], 'entity_id travels as a string');
        $this->assertSame('000000042', $event['increment_id']);
        $this->assertSame('processing', $event['state']);
        $this->assertSame('processing', $event['status']);
        $this->assertSame('https://shop.example.com/', $event['store_base_url']);
        $this->assertSame('2026-11-06 20:00:00', $event['created_at']);
        $this->assertSame(
            ['event', 'entity_id', 'increment_id', 'state', 'status', 'store_base_url', 'created_at'],
            array_keys($event)
        );
    }

    public function testNullStatusStaysNull(): void
    {
        $order = $this->order(3, null);

        $this->assertNull($this->factory(3)->fromOrder($order, EventFactory::EVENT_CANCELED)['status']);
    }

    private function factory(int $expectedStoreId): EventFactory
    {
        $store = $this->getMockBuilder(Store::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['getBaseUrl'])
            ->getMock();
        // Secure web URL of the order's store.
        $store->expects($this->once())
            ->method('getBaseUrl')
            ->with(UrlInterface::URL_TYPE_WEB, true)
            ->willReturn('https://shop.example.com/');

        $storeManager = $this->createMock(StoreManagerInterface::class);
        $storeManager->expects($this->once())
            ->method('getStore')
            ->with($expectedStoreId)
            ->willReturn($store);

        return new EventFactory($storeManager);
    }

    private function order(int $storeId, ?string $status = 'processing'): OrderInterface
    {
        $order = $this->createMock(OrderInterface::class);
        $order->method('getEntityId')->willReturn(42);
        $order->method('getIncrementId')->willReturn('000000042');
        $order->method('getState')->willReturn('processing');
        $order->method('getStatus')->willReturn($status);
        $order->method('getStoreId')->willReturn($storeId);
        $order->method('getCreatedAt')->willReturn('2026-11-06 20:00:00');

        return $order;
    }
}
