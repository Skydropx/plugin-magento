<?php
/**
 * Order push outbox (table skydropx_order_event).
 */
declare(strict_types=1);

namespace Skydropx\Shipping\Model\OrderPush;

use Magento\Framework\App\ResourceConnection;
use Magento\Framework\DB\Adapter\AdapterInterface;
use Magento\Framework\DB\Sql\Expression;
use Skydropx\Shipping\Model\Http\EcoClient;

/**
 * Reads and writes the outbox on the default connection, so enqueue() joins the order's transaction.
 * Every date is a UTC "Y-m-d H:i:s" string passed as a parameter; SQL clocks (NOW()) are never used.
 */
class OutboxRepository
{
    public const TABLE = 'skydropx_order_event';

    public const STATUS_PENDING = 'pending';
    public const STATUS_SENT = 'sent';
    public const STATUS_FAILED = 'failed';

    private const MAX_ERROR_LENGTH = 255;

    /**
     * @param ResourceConnection $resource
     */
    public function __construct(
        private ResourceConnection $resource
    ) {
    }

    /**
     * Queue an event built by EventFactory. A duplicate (order_id, event, state) is ignored (OD-11).
     *
     * @param array $event EventFactory::fromOrder() output, without sent_at
     * @param int|null $storeId
     * @return bool Whether a new row was written
     * @throws \JsonException
     */
    public function enqueue(array $event, ?int $storeId = null): bool
    {
        $payload = json_encode($event, EcoClient::JSON_FLAGS);
        $row = [
            (int) $event['entity_id'],
            (string) $event['increment_id'],
            $storeId,
            (string) $event['event'],
            (string) $event['state'],
            $event['status'] ?? null,
            $payload,
            self::STATUS_PENDING,
            0,
            $this->now(),
        ];
        $columns = [
            'order_id',
            'increment_id',
            'store_id',
            'event',
            'state',
            'status',
            'payload',
            'delivery_status',
            'attempts',
            'created_at',
        ];

        // AdapterInterface::insertArray() declares three arguments; the Mysql adapter takes the strategy.
        $inserted = $this->connection()->insertArray(
            $this->table(),
            $columns,
            [$row],
            AdapterInterface::INSERT_IGNORE
        );

        return (int) $inserted > 0;
    }

    /**
     * Pending events whose next attempt is due, oldest first.
     *
     * @param int $limit
     * @param string $now UTC "Y-m-d H:i:s"
     * @return array<int, array{event_id: string, order_id: string, payload: string, attempts: string}>
     */
    public function fetchDue(int $limit, string $now): array
    {
        $connection = $this->connection();
        $select = $connection->select()
            ->from($this->table(), ['event_id', 'order_id', 'payload', 'attempts'])
            ->where('delivery_status = :status')
            ->where('next_attempt_at IS NULL OR next_attempt_at <= :now')
            ->order('event_id ASC')
            ->limit($limit);

        return $connection->fetchAll($select, ['status' => self::STATUS_PENDING, 'now' => $now]);
    }

    /**
     * Mark an event as delivered.
     *
     * @param int $eventId
     * @param int $attempts
     * @param string $now UTC "Y-m-d H:i:s"
     * @return void
     */
    public function markSent(int $eventId, int $attempts, string $now): void
    {
        $this->updateRow($eventId, [
            'delivery_status' => self::STATUS_SENT,
            'attempts' => $attempts,
            'sent_at' => $now,
            'next_attempt_at' => null,
            'last_error' => null,
        ]);
    }

    /**
     * Reschedule an event after a retryable failure.
     *
     * @param int $eventId
     * @param int $attempts
     * @param string $nextAttemptAt UTC "Y-m-d H:i:s"
     * @param string $error
     * @return void
     */
    public function markRetry(int $eventId, int $attempts, string $nextAttemptAt, string $error): void
    {
        $this->updateRow($eventId, [
            'attempts' => $attempts,
            'next_attempt_at' => $nextAttemptAt,
            'last_error' => $this->truncate($error),
        ]);
    }

    /**
     * Give up on an event.
     *
     * @param int $eventId
     * @param int $attempts
     * @param string $error
     * @return void
     */
    public function markFailed(int $eventId, int $attempts, string $error): void
    {
        $this->updateRow($eventId, [
            'delivery_status' => self::STATUS_FAILED,
            'attempts' => $attempts,
            'next_attempt_at' => null,
            'last_error' => $this->truncate($error),
        ]);
    }

    /**
     * Put every failed event back in the queue with a fresh retry budget.
     *
     * @return int Rows reset
     */
    public function resetFailed(): int
    {
        return (int) $this->connection()->update(
            $this->table(),
            [
                'delivery_status' => self::STATUS_PENDING,
                'attempts' => 0,
                'next_attempt_at' => null,
                'last_error' => null,
            ],
            ['delivery_status = ?' => self::STATUS_FAILED]
        );
    }

    /**
     * Delete sent events older than $sentBefore and failed events created before $failedBefore.
     *
     * @param string $sentBefore UTC "Y-m-d H:i:s"
     * @param string $failedBefore UTC "Y-m-d H:i:s"
     * @return int Rows deleted
     */
    public function purge(string $sentBefore, string $failedBefore): int
    {
        $connection = $this->connection();
        $where = sprintf(
            '(%s AND %s) OR (%s AND %s)',
            $connection->quoteInto('delivery_status = ?', self::STATUS_SENT),
            $connection->quoteInto('sent_at < ?', $sentBefore),
            $connection->quoteInto('delivery_status = ?', self::STATUS_FAILED),
            $connection->quoteInto('created_at < ?', $failedBefore)
        );

        return (int) $connection->delete($this->table(), $where);
    }

    /**
     * Outbox health for the connection panel (OD-14). Dates are UTC "Y-m-d H:i:s".
     *
     * @return array{pending: int, failed: int, oldest_pending_at: ?string, last_sent_at: ?string}
     */
    public function getStats(): array
    {
        $connection = $this->connection();
        $select = $connection->select()->from(
            $this->table(),
            [
                'pending' => new Expression('SUM(delivery_status = :pending)'),
                'failed' => new Expression('SUM(delivery_status = :failed)'),
                'oldest_pending_at' => new Expression(
                    'MIN(CASE WHEN delivery_status = :oldest_status THEN created_at END)'
                ),
                'last_sent_at' => new Expression('MAX(sent_at)'),
            ]
        );
        $row = $connection->fetchRow($select, [
            'pending' => self::STATUS_PENDING,
            'failed' => self::STATUS_FAILED,
            'oldest_status' => self::STATUS_PENDING,
        ]);
        $row = is_array($row) ? $row : [];

        return [
            'pending' => (int) ($row['pending'] ?? 0),
            'failed' => (int) ($row['failed'] ?? 0),
            'oldest_pending_at' => $this->nullableString($row['oldest_pending_at'] ?? null),
            'last_sent_at' => $this->nullableString($row['last_sent_at'] ?? null),
        ];
    }

    /**
     * Current UTC time as stored in the table.
     *
     * @return string
     */
    private function now(): string
    {
        return gmdate('Y-m-d H:i:s');
    }

    /**
     * Update one row by id. Values in $bind are bound parameters.
     *
     * @param int $eventId
     * @param array $bind
     * @return void
     */
    private function updateRow(int $eventId, array $bind): void
    {
        $this->connection()->update($this->table(), $bind, ['event_id = ?' => $eventId]);
    }

    /**
     * Fit last_error in its column.
     *
     * @param string $error
     * @return string
     */
    private function truncate(string $error): string
    {
        return mb_substr($error, 0, self::MAX_ERROR_LENGTH);
    }

    /**
     * Null or a non-empty string.
     *
     * @param mixed $value
     * @return string|null
     */
    private function nullableString($value): ?string
    {
        return $value === null || $value === '' ? null : (string) $value;
    }

    /**
     * Default connection: the same one (and transaction) the order is saved with.
     *
     * @return AdapterInterface
     */
    private function connection(): AdapterInterface
    {
        return $this->resource->getConnection();
    }

    /**
     * Prefixed table name.
     *
     * @return string
     */
    private function table(): string
    {
        return $this->resource->getTableName(self::TABLE);
    }
}
