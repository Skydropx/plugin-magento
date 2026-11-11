<?php
declare(strict_types=1);

namespace Skydropx\Shipping\Test\Unit\Observer;

use Magento\Framework\Event;
use Magento\Framework\Event\Observer;
use Magento\Sales\Model\Order;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Skydropx\Shipping\Logger\Logger;
use Skydropx\Shipping\Model\Config;
use Skydropx\Shipping\Model\OrderPush\EventFactory;
use Skydropx\Shipping\Model\OrderPush\OutboxRepository;
use Skydropx\Shipping\Observer\OrderStateObserver;

class OrderStateObserverTest extends TestCase
{
    /**
     * @var Config&MockObject
     */
    private $config;

    /**
     * @var EventFactory&MockObject
     */
    private $eventFactory;

    /**
     * @var OutboxRepository&MockObject
     */
    private $outbox;

    /**
     * @var Logger&MockObject
     */
    private $logger;

    protected function setUp(): void
    {
        $this->config = $this->createMock(Config::class);
        $this->config->method('isConnected')->willReturn(true);
        $this->config->method('isOrderPushEnabled')->willReturn(true);

        $this->eventFactory = $this->createMock(EventFactory::class);
        $this->eventFactory->method('fromOrder')->willReturnCallback(
            static function ($order, string $event): array {
                return ['event' => $event, 'entity_id' => (string) $order->getEntityId()];
            }
        );

        $this->outbox = $this->createMock(OutboxRepository::class);
        $this->logger = $this->createMock(Logger::class);
    }

    public function testNewOrderQueuesCreated(): void
    {
        // A new order has no original data in save_after.
        $order = $this->order(['entity_id' => 42, 'store_id' => 3, 'state' => 'new', 'status' => 'pending']);

        $this->outbox->expects($this->once())
            ->method('enqueue')
            ->with(['event' => 'order.created', 'entity_id' => '42'], 3);

        $this->observer()->execute($this->event($order));
    }

    public function testStateChangeToProcessingQueuesUpdated(): void
    {
        $order = $this->order(
            ['entity_id' => 42, 'store_id' => 1, 'state' => 'processing', 'status' => 'processing'],
            ['entity_id' => 42, 'store_id' => 1, 'state' => 'new', 'status' => 'pending']
        );

        $this->outbox->expects($this->once())
            ->method('enqueue')
            ->with(['event' => 'order.updated', 'entity_id' => '42'], 1);

        $this->observer()->execute($this->event($order));
    }

    public function testCancellationQueuesCanceled(): void
    {
        $order = $this->order(
            ['entity_id' => 42, 'store_id' => 1, 'state' => 'canceled', 'status' => 'canceled'],
            ['entity_id' => 42, 'store_id' => 1, 'state' => 'processing', 'status' => 'processing']
        );

        $this->outbox->expects($this->once())
            ->method('enqueue')
            ->with(['event' => 'order.canceled', 'entity_id' => '42'], 1);

        $this->observer()->execute($this->event($order));
    }

    public function testStatusOnlyChangeQueuesNothing(): void
    {
        $order = $this->order(
            ['entity_id' => 42, 'store_id' => 1, 'state' => 'processing', 'status' => 'packed'],
            ['entity_id' => 42, 'store_id' => 1, 'state' => 'processing', 'status' => 'processing']
        );

        $this->outbox->expects($this->never())->method('enqueue');

        $this->observer()->execute($this->event($order));
    }

    public function testVirtualOrderQueuesNothing(): void
    {
        $order = $this->order(['entity_id' => 42, 'store_id' => 1, 'state' => 'new', 'is_virtual' => 1]);

        $this->outbox->expects($this->never())->method('enqueue');

        $this->observer()->execute($this->event($order));
    }

    public function testDisconnectedQueuesNothing(): void
    {
        $this->config = $this->createMock(Config::class);
        $this->config->method('isConnected')->willReturn(false);
        $this->config->method('isOrderPushEnabled')->willReturn(true);
        $order = $this->order(['entity_id' => 42, 'store_id' => 1, 'state' => 'new']);

        $this->outbox->expects($this->never())->method('enqueue');

        $this->observer()->execute($this->event($order));
    }

    public function testPushDisabledQueuesNothing(): void
    {
        $this->config = $this->createMock(Config::class);
        $this->config->method('isConnected')->willReturn(true);
        $this->config->method('isOrderPushEnabled')->willReturn(false);
        $order = $this->order(['entity_id' => 42, 'store_id' => 1, 'state' => 'new']);

        $this->outbox->expects($this->never())->method('enqueue');

        $this->observer()->execute($this->event($order));
    }

    public function testExceptionIsLoggedAndNotPropagated(): void
    {
        $order = $this->order(['entity_id' => 42, 'store_id' => 1, 'state' => 'new']);
        $this->outbox->method('enqueue')
            ->willThrowException(new \RuntimeException('SQLSTATE[HY000] ... query was: INSERT ... {"event":…}'));

        $this->logger->expects($this->once())
            ->method('warning')
            ->with('PUSH_FAILED', ['order_id' => 42, 'error' => \RuntimeException::class]);

        $this->observer()->execute($this->event($order));
    }

    public function testEventWithoutOrderIsIgnored(): void
    {
        $this->outbox->expects($this->never())->method('enqueue');

        $this->observer()->execute(new Observer(['event' => new Event([])]));
    }

    private function observer(): OrderStateObserver
    {
        return new OrderStateObserver($this->config, $this->eventFactory, $this->outbox, $this->logger);
    }

    private function event(Order $order): Observer
    {
        return new Observer(['event' => new Event(['order' => $order])]);
    }

    /**
     * Order with real data accessors (getData, getOrigData, dataHasChangedFor).
     *
     * @param array $data
     * @param array|null $origData Null for an order that was never loaded (new)
     * @return Order
     */
    private function order(array $data, ?array $origData = null): Order
    {
        $order = $this->getMockBuilder(Order::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['getStore'])
            ->getMock();
        $order->setData($data);
        foreach ($origData ?? [] as $key => $value) {
            $order->setOrigData($key, $value);
        }

        return $order;
    }
}
