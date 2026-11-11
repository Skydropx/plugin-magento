<?php
/**
 * Sends queued order events to eco (contract §3).
 */
declare(strict_types=1);

namespace Skydropx\Shipping\Model\OrderPush;

use Magento\Framework\Lock\LockManagerInterface;
use Skydropx\Shipping\Logger\Logger;
use Skydropx\Shipping\Model\Config;
use Skydropx\Shipping\Model\Http\EcoClient;
use Skydropx\Shipping\Model\Http\EcoResponse;

/**
 * Drains the outbox under a lock: signed POST per event, retries with backoff, gives up on 4xx.
 */
class Publisher
{
    /** Upper case platform, fixed country (contract §3). */
    public const WEBHOOK_PATH = '/api/v3/order/webhook/MX/MAGENTO/NEW_ORDER';
    public const LOCK_NAME = 'skydropx_order_push';
    public const DEFAULT_LIMIT = 100;

    private const TIMEOUT = 10;
    private const MAX_CONSECUTIVE_TRANSPORT_ERRORS = 3;
    private const HEADER_SHOP_ID = 'X-Skydropx-Shop-Id';

    /**
     * @param OutboxRepository $outbox
     * @param EcoClient $ecoClient
     * @param Config $config
     * @param RetryPolicy $retryPolicy
     * @param LockManagerInterface $lockManager
     * @param Logger $logger
     */
    public function __construct(
        private OutboxRepository $outbox,
        private EcoClient $ecoClient,
        private Config $config,
        private RetryPolicy $retryPolicy,
        private LockManagerInterface $lockManager,
        private Logger $logger
    ) {
    }

    /**
     * Send the due events. Does nothing when another run holds the lock or the module is not connected.
     *
     * @param int $limit
     * @param bool $retryFailed Put failed events back in the queue first
     * @return array{sent: int, retried: int, failed: int}
     */
    public function run(int $limit = self::DEFAULT_LIMIT, bool $retryFailed = false): array
    {
        $summary = ['sent' => 0, 'retried' => 0, 'failed' => 0];
        if (!$this->lockManager->lock(self::LOCK_NAME, 0)) {
            return $summary;
        }
        try {
            $shopId = $this->config->getShopId();
            if (!$this->config->isConnected() || $shopId === null || $limit < 1) {
                return $summary;
            }
            if ($retryFailed) {
                $this->outbox->resetFailed();
            }
            $transportErrors = 0;
            foreach ($this->outbox->fetchDue($limit, $this->dbDate($this->now())) as $row) {
                $attempts = (int) $row['attempts'] + 1;
                $rawBody = $this->rawBody((string) $row['payload']);
                if ($rawBody === null) {
                    $summary[$this->fail($row, $attempts, 'Unreadable payload')]++;
                    continue;
                }
                $response = $this->ecoClient->postSigned(
                    self::WEBHOOK_PATH,
                    $rawBody,
                    [],
                    [self::HEADER_SHOP_ID => $shopId],
                    self::TIMEOUT
                );
                $summary[$this->record($row, $attempts, $response)]++;
                $transportErrors = $response->isTransportError() ? $transportErrors + 1 : 0;
                // eco looks down: do not hang the cron for $limit x timeout seconds.
                if ($transportErrors >= self::MAX_CONSECUTIVE_TRANSPORT_ERRORS) {
                    break;
                }
            }
        } finally {
            $this->lockManager->unlock(self::LOCK_NAME);
        }

        return $summary;
    }

    /**
     * Current Unix time. Seam for tests (OD-3).
     *
     * @return int
     */
    protected function now(): int
    {
        return time();
    }

    /**
     * Stored payload + sent_at, serialized once: this exact string is signed and sent (contract §1, §3).
     *
     * @param string $payload
     * @return string|null Null when the stored payload is unreadable
     */
    private function rawBody(string $payload): ?string
    {
        $body = json_decode($payload, true);
        if (!is_array($body)) {
            return null;
        }
        // Taken at send time, never at enqueue time.
        $body['sent_at'] = gmdate('Y-m-d\TH:i:s\Z', $this->now());
        try {
            return json_encode($body, EcoClient::JSON_FLAGS);
        } catch (\JsonException $e) {
            return null;
        }
    }

    /**
     * Persist the outcome of one delivery.
     *
     * @param array $row
     * @param int $attempts This attempt, 1-based
     * @param EcoResponse $response
     * @return string Summary key: 'sent', 'retried' or 'failed'
     */
    private function record(array $row, int $attempts, EcoResponse $response): string
    {
        $eventId = (int) $row['event_id'];
        $now = $this->now();
        if ($response->isSuccess()) {
            $this->outbox->markSent($eventId, $attempts, $this->dbDate($now));

            return 'sent';
        }
        $error = $this->describe($response);
        if (!$this->isRetryable($response) || $attempts > $this->retryPolicy->maxAttempts()) {
            return $this->fail($row, $attempts, $error);
        }
        $nextAttemptAt = $this->dbDate($now + $this->retryPolicy->delay($attempts));
        $this->outbox->markRetry($eventId, $attempts, $nextAttemptAt, $error);

        return 'retried';
    }

    /**
     * Mark the row as failed and log it. Bodies are never logged.
     *
     * @param array $row
     * @param int $attempts
     * @param string $error
     * @return string
     */
    private function fail(array $row, int $attempts, string $error): string
    {
        $this->outbox->markFailed((int) $row['event_id'], $attempts, $error);
        $this->logger->warning('PUSH_FAILED', [
            'event_id' => (int) $row['event_id'],
            'order_id' => (int) $row['order_id'],
            'attempts' => $attempts,
            'error' => $error,
        ]);

        return 'failed';
    }

    /**
     * Transport errors, 429 and 5xx are retried; any other status is final.
     *
     * @param EcoResponse $response
     * @return bool
     */
    private function isRetryable(EcoResponse $response): bool
    {
        $status = $response->getStatus();

        return $status === 0 || $status === 429 || $status >= 500;
    }

    /**
     * "<status> <detail>" for last_error (truncated by the repository).
     *
     * @param EcoResponse $response
     * @return string
     */
    private function describe(EcoResponse $response): string
    {
        return trim($response->getStatus() . ' ' . (string) $response->getErrorDetail());
    }

    /**
     * UTC date in the table's format.
     *
     * @param int $timestamp
     * @return string
     */
    private function dbDate(int $timestamp): string
    {
        return gmdate('Y-m-d H:i:s', $timestamp);
    }
}
