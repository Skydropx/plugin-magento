<?php
/**
 * Queues order push events (contract §3).
 */
declare(strict_types=1);

namespace Skydropx\Shipping\Observer;

use Magento\Framework\Event\Observer;
use Magento\Framework\Event\ObserverInterface;
use Magento\Sales\Model\Order;
use Skydropx\Shipping\Logger\Logger;
use Skydropx\Shipping\Model\Config;
use Skydropx\Shipping\Model\OrderPush\EventFactory;
use Skydropx\Shipping\Model\OrderPush\OutboxRepository;

/**
 * sales_order_save_after (global area): writes to the outbox inside the order's transaction.
 * Never throws, so a push problem can never block an order save.
 */
class OrderStateObserver implements ObserverInterface
{
    /**
     * @param Config $config
     * @param EventFactory $eventFactory
     * @param OutboxRepository $outbox
     * @param Logger $logger
     */
    public function __construct(
        private Config $config,
        private EventFactory $eventFactory,
        private OutboxRepository $outbox,
        private Logger $logger
    ) {
    }

    /**
     * Queue order.created, order.updated or order.canceled when it applies.
     *
     * @param Observer $observer
     * @return void
     */
    public function execute(Observer $observer): void
    {
        $order = $observer->getEvent()->getData('order');
        if (!$order instanceof Order) {
            return;
        }
        try {
            if (!$this->config->isOrderPushEnabled() || !$this->config->isConnected() || $order->getIsVirtual()) {
                return;
            }
            $event = $this->resolveEvent($order);
            if ($event === null) {
                return;
            }
            $storeId = $order->getStoreId();
            $this->outbox->enqueue(
                $this->eventFactory->fromOrder($order, $event),
                $storeId === null ? null : (int) $storeId
            );
        } catch (\Throwable $e) {
            // The message may quote SQL with the payload: log the class only (§6.7).
            $this->logger->warning('PUSH_FAILED', [
                'order_id' => (int) $order->getEntityId(),
                'error' => get_class($e),
            ]);
        }
    }

    /**
     * Event for this save, or null when only the status (or nothing eco reads) changed.
     *
     * @param Order $order
     * @return string|null
     */
    private function resolveEvent(Order $order): ?string
    {
        if ($this->isNewOrder($order)) {
            return EventFactory::EVENT_CREATED;
        }
        if (!$order->dataHasChangedFor('state')) {
            // eco filters on state; a status-only change carries nothing new.
            return null;
        }

        return $order->getState() === Order::STATE_CANCELED
            ? EventFactory::EVENT_CANCELED
            : EventFactory::EVENT_UPDATED;
    }

    /**
     * A new order has no original data yet in save_after (verify in Docker, HU R17).
     *
     * @param Order $order
     * @return bool
     */
    private function isNewOrder(Order $order): bool
    {
        return $order->getOrigData('entity_id') === null;
    }
}
