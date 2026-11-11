<?php
/**
 * Order push event payload (contract §3).
 */
declare(strict_types=1);

namespace Skydropx\Shipping\Model\OrderPush;

use Magento\Framework\UrlInterface;
use Magento\Sales\Api\Data\OrderInterface;
use Magento\Store\Model\StoreManagerInterface;

/**
 * Builds the thin event eco receives. sent_at is added by the publisher at send time.
 */
class EventFactory
{
    public const EVENT_CREATED = 'order.created';
    public const EVENT_UPDATED = 'order.updated';
    public const EVENT_CANCELED = 'order.canceled';

    /**
     * @param StoreManagerInterface $storeManager
     */
    public function __construct(
        private StoreManagerInterface $storeManager
    ) {
    }

    /**
     * Event payload without sent_at (contract §3). entity_id travels as a string.
     *
     * @param OrderInterface $order
     * @param string $event
     * @return array{event: string, entity_id: string, increment_id: string, state: string,
     *     status: ?string, store_base_url: string, created_at: string}
     */
    public function fromOrder(OrderInterface $order, string $event): array
    {
        $status = $order->getStatus();
        $storeId = $order->getStoreId();

        return [
            'event' => $event,
            'entity_id' => (string) $order->getEntityId(),
            'increment_id' => (string) $order->getIncrementId(),
            'state' => (string) $order->getState(),
            'status' => $status === null ? null : (string) $status,
            'store_base_url' => $this->storeBaseUrl($storeId === null ? null : (int) $storeId),
            'created_at' => (string) $order->getCreatedAt(),
        ];
    }

    /**
     * Secure base URL of the order's store.
     *
     * @param int|null $storeId
     * @return string
     */
    private function storeBaseUrl(?int $storeId): string
    {
        return (string) $this->storeManager->getStore($storeId)->getBaseUrl(UrlInterface::URL_TYPE_WEB, true);
    }
}
