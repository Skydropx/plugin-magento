<?php
/**
 * Cron: outbox clean-up (daily).
 */
declare(strict_types=1);

namespace Skydropx\Shipping\Cron;

use Skydropx\Shipping\Model\OrderPush\OutboxRepository;

/**
 * Deletes sent events after 7 days and failed events after 30.
 */
class PurgeOutbox
{
    private const SENT_RETENTION_DAYS = 7;
    private const FAILED_RETENTION_DAYS = 30;

    /**
     * @param OutboxRepository $outbox
     */
    public function __construct(
        private OutboxRepository $outbox
    ) {
    }

    /**
     * Purge old rows.
     *
     * @return void
     */
    public function execute(): void
    {
        $now = time();
        $this->outbox->purge(
            gmdate('Y-m-d H:i:s', $now - self::SENT_RETENTION_DAYS * 86400),
            gmdate('Y-m-d H:i:s', $now - self::FAILED_RETENTION_DAYS * 86400)
        );
    }
}
