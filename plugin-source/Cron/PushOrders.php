<?php
/**
 * Cron: order push (every minute).
 */
declare(strict_types=1);

namespace Skydropx\Shipping\Cron;

use Skydropx\Shipping\Model\OrderPush\Publisher;

/**
 * Drains the order push outbox.
 */
class PushOrders
{
    /**
     * @param Publisher $publisher
     */
    public function __construct(
        private Publisher $publisher
    ) {
    }

    /**
     * Send the due events.
     *
     * @return void
     */
    public function execute(): void
    {
        $this->publisher->run();
    }
}
