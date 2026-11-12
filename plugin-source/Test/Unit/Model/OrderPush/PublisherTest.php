<?php
declare(strict_types=1);

namespace Skydropx\Shipping\Test\Unit\Model\OrderPush;

use Magento\Framework\App\ProductMetadataInterface;
use Magento\Framework\HTTP\Client\Curl;
use Magento\Framework\HTTP\Client\CurlFactory;
use Magento\Framework\Lock\LockManagerInterface;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Skydropx\Shipping\Logger\Logger;
use Skydropx\Shipping\Model\Config;
use Skydropx\Shipping\Model\Http\EcoClient;
use Skydropx\Shipping\Model\Http\EcoResponse;
use Skydropx\Shipping\Model\OrderPush\OutboxRepository;
use Skydropx\Shipping\Model\OrderPush\Publisher;
use Skydropx\Shipping\Model\OrderPush\RetryPolicy;
use Skydropx\Shipping\Model\Signer;

class PublisherTest extends TestCase
{
    private const SECRET = 'not-a-real-secret-current';
    private const SHOP_ID = '0b6f6a9e-3c1d-4f2a-9b8e-7d6c5b4a3f21';
    private const WEBHOOK_PATH = '/api/v3/order/webhook/MX/MAGENTO/NEW_ORDER';

    /** 2026-11-06 20:00:01 UTC */
    private const NOW = 1793995201;
    private const NOW_DB = '2026-11-06 20:00:01';

    private const PAYLOAD = '{"event":"order.created","entity_id":"42","increment_id":"000000042",'
        . '"state":"processing","status":"processing","store_base_url":"https://shop.example.com/",'
        . '"created_at":"2026-11-06 20:00:00"}';

    /**
     * @var OutboxRepository&MockObject
     */
    private $outbox;

    /**
     * @var EcoClient&MockObject
     */
    private $ecoClient;

    /**
     * @var Config&MockObject
     */
    private $config;

    /**
     * @var LockManagerInterface&MockObject
     */
    private $lockManager;

    /**
     * @var Logger&MockObject
     */
    private $logger;

    /**
     * Arguments of every postSigned() call.
     *
     * @var array<int, array>
     */
    private array $posts = [];

    /**
     * Responses postSigned() returns, in order.
     *
     * @var EcoResponse[]
     */
    private array $responses = [];

    protected function setUp(): void
    {
        $this->outbox = $this->createMock(OutboxRepository::class);

        $this->ecoClient = $this->createMock(EcoClient::class);
        $this->ecoClient->method('postSigned')->willReturnCallback(function (...$args): EcoResponse {
            $this->posts[] = $args;

            return array_shift($this->responses) ?? new EcoResponse(200, '{}');
        });

        $this->config = $this->createMock(Config::class);
        $this->config->method('isConnected')->willReturn(true);
        $this->config->method('getShopId')->willReturn(self::SHOP_ID);
        $this->config->method('getSigningSecret')->willReturn(self::SECRET);

        $this->lockManager = $this->createMock(LockManagerInterface::class);
        $this->lockManager->method('lock')->with('skydropx_order_push', 0)->willReturn(true);

        $this->logger = $this->createMock(Logger::class);
    }

    public function testPostsTheStoredPayloadPlusSentAtWithTheShopHeader(): void
    {
        $this->due([$this->row(7)]);

        $summary = $this->publisher()->run();

        $this->assertSame(['sent' => 1, 'retried' => 0, 'failed' => 0], $summary);
        $this->assertCount(1, $this->posts);
        [$path, $rawBody, $query, $headers, $timeout] = $this->posts[0];
        $this->assertSame(self::WEBHOOK_PATH, $path);
        $this->assertSame(Publisher::WEBHOOK_PATH, $path);
        // Payload serialized once, with sent_at last and taken at send time (contract §3).
        $this->assertSame(substr(self::PAYLOAD, 0, -1) . ',"sent_at":"2026-11-06T20:00:01Z"}', $rawBody);
        $this->assertSame([], $query);
        $this->assertSame(['X-Skydropx-Shop-Id' => self::SHOP_ID], $headers);
        $this->assertSame(10, $timeout);
    }

    public function testSentAtIsTakenWhenEachRowIsSent(): void
    {
        $this->due([$this->row(7), $this->row(8)]);
        $clock = [self::NOW, self::NOW, self::NOW, self::NOW + 5, self::NOW + 5];

        $this->publisher($clock)->run();

        $this->assertStringContainsString('"sent_at":"2026-11-06T20:00:01Z"', $this->posts[0][1]);
        $this->assertStringContainsString('"sent_at":"2026-11-06T20:00:06Z"', $this->posts[1][1]);
    }

    public function testSignatureCoversTheBodyWithSentAtAndATimestampTakenAtSendTime(): void
    {
        $sent = [];
        $curl = $this->getMockBuilder(Curl::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['setHeaders', 'setTimeout', 'setOption', 'post', 'getStatus', 'getBody', 'getHeaders'])
            ->getMock();
        $curl->method('setHeaders')->willReturnCallback(function ($headers) use (&$sent) {
            $sent['headers'] = $headers;
        });
        $curl->method('post')->willReturnCallback(function ($url, $body) use (&$sent) {
            $sent['body'] = $body;
        });
        $curl->method('getStatus')->willReturn(200);
        $curl->method('getBody')->willReturn('{}');
        $curl->method('getHeaders')->willReturn([]);
        $curlFactory = $this->getMockBuilder(CurlFactory::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['create'])
            ->getMock();
        $curlFactory->method('create')->willReturn($curl);
        $metadata = $this->createMock(ProductMetadataInterface::class);
        $metadata->method('getVersion')->willReturn('2.4.8');
        $ecoClient = $this->getMockBuilder(EcoClient::class)
            ->setConstructorArgs([$curlFactory, $this->config, new Signer(), $metadata, $this->logger])
            ->onlyMethods(['isBuilt', 'ecommerceBase'])
            ->getMock();
        $ecoClient->method('isBuilt')->willReturn(true);
        $ecoClient->method('ecommerceBase')->willReturn('https://eco.example.com');
        $this->due([$this->row(7)]);

        $before = time();
        $publisher = new Publisher(
            $this->outbox,
            $ecoClient,
            $this->config,
            new RetryPolicy(),
            $this->lockManager,
            $this->logger
        );
        $this->assertSame(['sent' => 1, 'retried' => 0, 'failed' => 0], $publisher->run());
        $after = time();

        $body = json_decode($sent['body'], true);
        $sentAt = strtotime($body['sent_at']);
        $this->assertGreaterThanOrEqual($before, $sentAt);
        $this->assertLessThanOrEqual($after, $sentAt);
        $timestamp = $sent['headers']['X-Skydropx-Timestamp'];
        $this->assertGreaterThanOrEqual($before, (int) $timestamp);
        $this->assertLessThanOrEqual($after, (int) $timestamp);
        $this->assertSame(
            (new Signer())->sign(self::SECRET, $timestamp, $sent['body']),
            $sent['headers']['X-Skydropx-Signature'],
            'The signed string is the body with sent_at, byte for byte'
        );
        $this->assertSame(self::SHOP_ID, $sent['headers']['X-Skydropx-Shop-Id']);
    }

    public function testSuccessMarksTheRowSent(): void
    {
        $this->due([$this->row(7, 2)]);
        $this->responses = [new EcoResponse(200, '{"ok":true}')];

        $this->outbox->expects($this->once())->method('markSent')->with(7, 3, self::NOW_DB);
        $this->outbox->expects($this->never())->method('markRetry');
        $this->outbox->expects($this->never())->method('markFailed');

        $this->assertSame(['sent' => 1, 'retried' => 0, 'failed' => 0], $this->publisher()->run());
    }

    /**
     * @dataProvider retryProvider
     */
    public function testRetryableErrorsAreRescheduled(int $status, int $previousAttempts, int $delay): void
    {
        $this->due([$this->row(7, $previousAttempts)]);
        $this->responses = [$this->response($status)];

        $this->outbox->expects($this->once())
            ->method('markRetry')
            ->with(7, $previousAttempts + 1, gmdate('Y-m-d H:i:s', self::NOW + $delay), $this->stringStartsWith((string) $status));
        $this->outbox->expects($this->never())->method('markFailed');
        $this->logger->expects($this->never())->method('warning');

        $this->assertSame(['sent' => 0, 'retried' => 1, 'failed' => 0], $this->publisher()->run());
    }

    public static function retryProvider(): array
    {
        return [
            'transport error, 1st attempt' => [0, 0, 60],
            '429, 2nd attempt' => [429, 1, 300],
            '500, 3rd attempt' => [500, 2, 900],
            '502, 4th attempt' => [502, 3, 3600],
            '503, 5th attempt' => [503, 4, 21600],
            'transport error, 6th attempt' => [0, 5, 21600],
        ];
    }

    /**
     * @dataProvider retryableStatusProvider
     */
    public function testSeventhFailedAttemptGivesUp(int $status): void
    {
        $this->due([$this->row(7, 6)]);
        $this->responses = [$this->response($status)];

        $this->outbox->expects($this->never())->method('markRetry');
        $this->outbox->expects($this->once())
            ->method('markFailed')
            ->with(7, 7, $this->stringStartsWith((string) $status));
        $this->logger->expects($this->once())
            ->method('warning')
            ->with('PUSH_FAILED', $this->callback(static function (array $context): bool {
                return $context['order_id'] === 42 && $context['event_id'] === 7 && $context['attempts'] === 7;
            }));

        $this->assertSame(['sent' => 0, 'retried' => 0, 'failed' => 1], $this->publisher()->run());
    }

    public static function retryableStatusProvider(): array
    {
        return ['transport error' => [0], '429' => [429], '503' => [503]];
    }

    /**
     * @dataProvider clientErrorProvider
     */
    public function testOtherClientErrorsFailForGood(int $status): void
    {
        $this->due([$this->row(7)]);
        $this->responses = [new EcoResponse($status, '{"errors":[{"status":"' . $status . '","detail":"Rejected"}]}')];

        $this->outbox->expects($this->never())->method('markRetry');
        $this->outbox->expects($this->once())->method('markFailed')->with(7, 1, $status . ' Rejected');
        $this->logger->expects($this->once())->method('warning')->with('PUSH_FAILED', $this->anything());

        $this->assertSame(['sent' => 0, 'retried' => 0, 'failed' => 1], $this->publisher()->run());
    }

    public static function clientErrorProvider(): array
    {
        return ['400' => [400], '401' => [401], '404' => [404], '422' => [422]];
    }

    public function testLogsNeverCarryTheBody(): void
    {
        $this->due([$this->row(7)]);
        $this->responses = [new EcoResponse(400, '{"errors":[{"detail":"Bad"}]}')];

        $this->logger->expects($this->once())
            ->method('warning')
            ->with('PUSH_FAILED', $this->callback(static function (array $context): bool {
                $flat = json_encode($context);

                return strpos($flat, 'store_base_url') === false && strpos($flat, 'payload') === false;
            }));

        $this->publisher()->run();
    }

    public function testThreeTransportErrorsInARowStopTheRun(): void
    {
        $this->due([$this->row(1), $this->row(2), $this->row(3), $this->row(4), $this->row(5)]);
        $this->responses = [$this->response(0), $this->response(0), $this->response(0), $this->response(0)];

        $summary = $this->publisher()->run();

        $this->assertCount(3, $this->posts);
        $this->assertSame(['sent' => 0, 'retried' => 3, 'failed' => 0], $summary);
    }

    public function testHttpErrorsBreakTheTransportStreak(): void
    {
        // 429 and 5xx are answers from eco, not transport errors.
        $this->due([
            $this->row(1), $this->row(2), $this->row(3), $this->row(4),
            $this->row(5), $this->row(6), $this->row(7), $this->row(8),
        ]);
        $this->responses = [
            $this->response(0), $this->response(0), $this->response(503),
            $this->response(0), $this->response(0), $this->response(429),
            $this->response(0), $this->response(0),
        ];

        $summary = $this->publisher()->run();

        $this->assertCount(8, $this->posts);
        $this->assertSame(['sent' => 0, 'retried' => 8, 'failed' => 0], $summary);
    }

    public function testBusyLockDoesNothing(): void
    {
        $this->lockManager = $this->createMock(LockManagerInterface::class);
        $this->lockManager->method('lock')->willReturn(false);

        $this->lockManager->expects($this->never())->method('unlock');
        $this->outbox->expects($this->never())->method($this->anything());
        $this->ecoClient->expects($this->never())->method('postSigned');

        $this->assertSame(['sent' => 0, 'retried' => 0, 'failed' => 0], $this->publisher()->run(100, true));
    }

    public function testDisconnectedTouchesNoRows(): void
    {
        $this->config = $this->createMock(Config::class);
        $this->config->method('isConnected')->willReturn(false);
        $this->config->method('getShopId')->willReturn(null);

        $this->outbox->expects($this->never())->method($this->anything());
        $this->ecoClient->expects($this->never())->method('postSigned');
        $this->lockManager->expects($this->once())->method('unlock')->with('skydropx_order_push');

        $this->assertSame(['sent' => 0, 'retried' => 0, 'failed' => 0], $this->publisher()->run(100, true));
    }

    public function testRetryFailedResetsFailedRowsBeforeReading(): void
    {
        $calls = [];
        $this->outbox->expects($this->once())->method('resetFailed')->willReturnCallback(function () use (&$calls) {
            $calls[] = 'resetFailed';

            return 2;
        });
        $this->outbox->method('fetchDue')->willReturnCallback(function () use (&$calls) {
            $calls[] = 'fetchDue';

            return [];
        });

        $this->publisher()->run(100, true);

        $this->assertSame(['resetFailed', 'fetchDue'], $calls);
    }

    public function testFailedRowsStayFailedWithoutTheFlag(): void
    {
        $this->outbox->expects($this->never())->method('resetFailed');
        $this->due([]);

        $this->publisher()->run();
    }

    public function testReadsDueRowsUpToTheLimit(): void
    {
        $this->outbox->expects($this->once())->method('fetchDue')->with(25, self::NOW_DB)->willReturn([]);

        $this->publisher()->run(25);
    }

    public function testUnreadablePayloadFailsWithoutSending(): void
    {
        $this->due([$this->row(7, 0, '{"event":'), $this->row(8)]);

        $this->outbox->expects($this->once())->method('markFailed')->with(7, 1, 'Unreadable payload');
        $this->logger->expects($this->once())->method('warning')->with('PUSH_FAILED', $this->anything());

        $summary = $this->publisher()->run();

        $this->assertCount(1, $this->posts, 'Only the readable row is sent');
        $this->assertSame(['sent' => 1, 'retried' => 0, 'failed' => 1], $summary);
    }

    public function testLockIsReleasedWhenSomethingThrows(): void
    {
        $this->outbox->method('fetchDue')->willThrowException(new \RuntimeException('DB down'));
        $this->lockManager->expects($this->once())->method('unlock')->with('skydropx_order_push');

        $this->expectException(\RuntimeException::class);

        $this->publisher()->run();
    }

    /**
     * Publisher with a fixed clock (OD-3). $clock lists successive now() values; the last one repeats.
     *
     * @param int[] $clock
     * @return Publisher
     */
    private function publisher(array $clock = [self::NOW]): Publisher
    {
        $publisher = $this->getMockBuilder(Publisher::class)
            ->setConstructorArgs([
                $this->outbox,
                $this->ecoClient,
                $this->config,
                new RetryPolicy(),
                $this->lockManager,
                $this->logger,
            ])
            ->onlyMethods(['now'])
            ->getMock();
        $publisher->method('now')->willReturnCallback(static function () use (&$clock): int {
            return count($clock) > 1 ? array_shift($clock) : $clock[0];
        });

        return $publisher;
    }

    /**
     * Rows fetchDue() returns.
     *
     * @param array $rows
     * @return void
     */
    private function due(array $rows): void
    {
        $this->outbox->method('fetchDue')->willReturn($rows);
    }

    /**
     * Outbox row as the adapter returns it (strings).
     *
     * @param int $eventId
     * @param int $attempts
     * @param string $payload
     * @return array
     */
    private function row(int $eventId, int $attempts = 0, string $payload = self::PAYLOAD): array
    {
        return [
            'event_id' => (string) $eventId,
            'order_id' => '42',
            'payload' => $payload,
            'attempts' => (string) $attempts,
        ];
    }

    /**
     * Error response: status 0 is a transport error.
     *
     * @param int $status
     * @return EcoResponse
     */
    private function response(int $status): EcoResponse
    {
        return $status === 0
            ? new EcoResponse(0, null, 'Operation timed out')
            : new EcoResponse($status, '{"errors":[{"detail":"Try later"}]}');
    }
}
