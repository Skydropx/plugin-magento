<?php
declare(strict_types=1);

namespace Skydropx\Shipping\Test\Unit\Model\OrderPush;

use Magento\Framework\App\ResourceConnection;
use Magento\Framework\DB\Adapter\AdapterInterface;
use Magento\Framework\DB\Select;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Skydropx\Shipping\Model\Http\EcoClient;
use Skydropx\Shipping\Model\OrderPush\OutboxRepository;

class OutboxRepositoryTest extends TestCase
{
    private const TABLE = 'pfx_skydropx_order_event';
    private const DATE_FORMAT = '/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/';

    /**
     * @var AdapterInterface&MockObject
     */
    private $connection;

    /**
     * @var Select&MockObject
     */
    private $select;

    /**
     * Calls received by the Select mock, in order. Only the meaningful arguments are kept
     * (table + columns for from(), the first one otherwise): PHPUnit 10 fills in the signature
     * defaults (null type, null offset…) and PHPUnit 9.5 may not.
     *
     * @var array<int, array{0: string, 1: array}>
     */
    private array $selectCalls = [];

    private OutboxRepository $repository;

    protected function setUp(): void
    {
        $this->select = $this->getMockBuilder(Select::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['from', 'where', 'order', 'limit'])
            ->getMock();
        $meaningful = ['from' => 2, 'where' => 1, 'order' => 1, 'limit' => 1];
        foreach ($meaningful as $method => $count) {
            $this->select->method($method)->willReturnCallback(function (...$args) use ($method, $count) {
                $this->selectCalls[] = [$method, array_slice($args, 0, $count)];

                return $this->select;
            });
        }

        $this->connection = $this->createMock(AdapterInterface::class);
        $this->connection->method('select')->willReturn($this->select);
        $this->connection->method('quoteInto')->willReturnCallback(
            static function ($text, $value): string {
                return str_replace('?', "'" . $value . "'", $text);
            }
        );

        $resource = $this->createMock(ResourceConnection::class);
        $resource->method('getConnection')->willReturn($this->connection);
        $resource->method('getTableName')->with('skydropx_order_event')->willReturn(self::TABLE);

        $this->repository = new OutboxRepository($resource);
    }

    public function testEnqueueInsertsIgnoringDuplicates(): void
    {
        $event = [
            'event' => 'order.created',
            'entity_id' => '42',
            'increment_id' => '000000042',
            'state' => 'processing',
            'status' => 'processing',
            'store_base_url' => 'https://shop.example.com/tienda/',
            'created_at' => '2026-11-06 20:00:00',
        ];
        $received = null;
        $this->connection->expects($this->once())
            ->method('insertArray')
            ->willReturnCallback(function (...$args) use (&$received) {
                $received = $args;

                return 1;
            });

        $before = gmdate('Y-m-d H:i:s');
        $this->assertTrue($this->repository->enqueue($event, 3));
        $after = gmdate('Y-m-d H:i:s');

        [$table, $columns, $data, $strategy] = $received;
        $this->assertSame(self::TABLE, $table);
        $this->assertSame(AdapterInterface::INSERT_IGNORE, $strategy, 'Duplicates are ignored (OD-11)');
        $this->assertCount(1, $data);
        $row = array_combine($columns, $data[0]);
        $this->assertSame(42, $row['order_id']);
        $this->assertSame('000000042', $row['increment_id']);
        $this->assertSame(3, $row['store_id']);
        $this->assertSame('order.created', $row['event']);
        $this->assertSame('processing', $row['state']);
        $this->assertSame('processing', $row['status']);
        $this->assertSame('pending', $row['delivery_status']);
        $this->assertSame(0, $row['attempts']);
        $this->assertSame(json_encode($event, EcoClient::JSON_FLAGS), $row['payload']);
        $this->assertStringNotContainsString('sent_at', $row['payload']);
        $this->assertMatchesRegularExpression(self::DATE_FORMAT, $row['created_at']);
        $this->assertGreaterThanOrEqual($before, $row['created_at'], 'created_at is UTC');
        $this->assertLessThanOrEqual($after, $row['created_at']);
        $this->assertArrayNotHasKey('next_attempt_at', $row, 'A new event is due right away');
    }

    public function testEnqueueReportsAnIgnoredDuplicate(): void
    {
        $this->connection->method('insertArray')->willReturn(0);

        $this->assertFalse($this->repository->enqueue(
            ['event' => 'order.updated', 'entity_id' => '42', 'increment_id' => '1', 'state' => 'new', 'status' => null],
            null
        ));
    }

    public function testFetchDueSelectsPendingRowsWhoseAttemptIsDue(): void
    {
        $rows = [['event_id' => '7', 'order_id' => '42', 'payload' => '{}', 'attempts' => '0']];
        $this->connection->expects($this->once())
            ->method('fetchAll')
            ->with($this->select, ['status' => 'pending', 'now' => '2026-11-06 20:00:00'])
            ->willReturn($rows);

        $this->assertSame($rows, $this->repository->fetchDue(25, '2026-11-06 20:00:00'));
        $this->assertSame(
            [
                ['from', [self::TABLE, ['event_id', 'order_id', 'payload', 'attempts']]],
                ['where', ['delivery_status = :status']],
                ['where', ['next_attempt_at IS NULL OR next_attempt_at <= :now']],
                ['order', ['event_id ASC']],
                ['limit', [25]],
            ],
            $this->selectCalls
        );
    }

    public function testMarkSent(): void
    {
        $this->connection->expects($this->once())
            ->method('update')
            ->with(
                self::TABLE,
                [
                    'delivery_status' => 'sent',
                    'attempts' => 2,
                    'sent_at' => '2026-11-06 20:00:01',
                    'next_attempt_at' => null,
                    'last_error' => null,
                ],
                ['event_id = ?' => 7]
            );

        $this->repository->markSent(7, 2, '2026-11-06 20:00:01');
    }

    public function testMarkRetry(): void
    {
        $this->connection->expects($this->once())
            ->method('update')
            ->with(
                self::TABLE,
                ['attempts' => 1, 'next_attempt_at' => '2026-11-06 20:01:00', 'last_error' => '503 Unavailable'],
                ['event_id = ?' => 7]
            );

        $this->repository->markRetry(7, 1, '2026-11-06 20:01:00', '503 Unavailable');
    }

    public function testMarkFailedTruncatesTheError(): void
    {
        $this->connection->expects($this->once())
            ->method('update')
            ->with(
                self::TABLE,
                [
                    'delivery_status' => 'failed',
                    'attempts' => 1,
                    'next_attempt_at' => null,
                    'last_error' => str_repeat('x', 255),
                ],
                ['event_id = ?' => 7]
            );

        $this->repository->markFailed(7, 1, str_repeat('x', 300));
    }

    public function testResetFailed(): void
    {
        $this->connection->expects($this->once())
            ->method('update')
            ->with(
                self::TABLE,
                ['delivery_status' => 'pending', 'attempts' => 0, 'next_attempt_at' => null, 'last_error' => null],
                ['delivery_status = ?' => 'failed']
            )
            ->willReturn(4);

        $this->assertSame(4, $this->repository->resetFailed());
    }

    public function testPurgeDeletesOldSentAndFailedRowsInOneQuery(): void
    {
        $this->connection->expects($this->once())
            ->method('delete')
            ->with(
                self::TABLE,
                "(delivery_status = 'sent' AND sent_at < '2026-10-30 03:15:00')"
                . " OR (delivery_status = 'failed' AND created_at < '2026-10-07 03:15:00')"
            )
            ->willReturn(9);

        $this->assertSame(9, $this->repository->purge('2026-10-30 03:15:00', '2026-10-07 03:15:00'));
    }

    public function testGetStats(): void
    {
        $this->connection->expects($this->once())
            ->method('fetchRow')
            ->with($this->select, ['pending' => 'pending', 'failed' => 'failed', 'oldest_status' => 'pending'])
            ->willReturn([
                'pending' => '3',
                'failed' => '1',
                'oldest_pending_at' => '2026-11-06 19:40:00',
                'last_sent_at' => '2026-11-06 19:59:00',
            ]);

        $this->assertSame(
            [
                'pending' => 3,
                'failed' => 1,
                'oldest_pending_at' => '2026-11-06 19:40:00',
                'last_sent_at' => '2026-11-06 19:59:00',
            ],
            $this->repository->getStats()
        );
        $this->assertSame('from', $this->selectCalls[0][0]);
        $this->assertSame(self::TABLE, $this->selectCalls[0][1][0]);
        $this->assertSame(
            ['pending', 'failed', 'oldest_pending_at', 'last_sent_at'],
            array_keys($this->selectCalls[0][1][1])
        );
    }

    public function testGetStatsOnAnEmptyTable(): void
    {
        // SUM()/MIN()/MAX() over no rows return NULL.
        $this->connection->method('fetchRow')->willReturn([
            'pending' => null,
            'failed' => null,
            'oldest_pending_at' => null,
            'last_sent_at' => null,
        ]);

        $this->assertSame(
            ['pending' => 0, 'failed' => 0, 'oldest_pending_at' => null, 'last_sent_at' => null],
            $this->repository->getStats()
        );
    }
}
