<?php
declare(strict_types=1);

namespace Skydropx\Shipping\Test\Unit\Model\Connect;

use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Skydropx\Shipping\Logger\Logger;
use Skydropx\Shipping\Model\Connect\ConnectException;
use Skydropx\Shipping\Model\Connect\Exchanger;
use Skydropx\Shipping\Model\Http\EcoClient;
use Skydropx\Shipping\Model\Http\EcoResponse;

class ExchangerTest extends TestCase
{
    private const SHOP_ID = '0b6f6a9e-3c1d-4f2a-9b8e-7d6c5b4a3f21';
    private const CODE = 'Q2hhbmdlTWVQbGVhc2VDb2RlMTIzNDU2Nzg5MA';
    private const STATE = 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa';
    private const STORE_URL = 'https://shop.example.com/';
    private const TOKEN = 'integration-access-token-value';
    private const SECRET = 'not-a-real-secret-current';

    /**
     * @var array[]
     */
    private array $bodies = [];

    /**
     * @var int[]
     */
    private array $pauses = [];

    /**
     * @var array[]
     */
    private array $logs = [];

    public function testSuccessSendsTheContractBodyOnce(): void
    {
        $result = $this->exchanger([$this->ok()])->exchange(self::CODE, self::STATE, self::STORE_URL, self::TOKEN);

        $this->assertSame(['shopId' => self::SHOP_ID, 'signingSecret' => self::SECRET], $result);
        $this->assertSame(
            [[
                'code' => self::CODE,
                'state' => self::STATE,
                'store_url' => self::STORE_URL,
                'access_token' => self::TOKEN,
                'module_version' => 'PLUGIN_VERSION',
            ]],
            $this->bodies
        );
        $this->assertSame([], $this->pauses);
    }

    public function testShopIdIsLowerCased(): void
    {
        $response = new EcoResponse(200, json_encode(['shop_id' => strtoupper(self::SHOP_ID), 'signing_secret' => 's']));

        $result = $this->exchanger([$response])->exchange(self::CODE, self::STATE, self::STORE_URL, self::TOKEN);

        $this->assertSame(self::SHOP_ID, $result['shopId']);
    }

    /**
     * @dataProvider statusProvider
     */
    public function testStatusMapsToReason(int $status, string $reason): void
    {
        $responses = array_fill(0, 3, $this->error($status));

        $exception = $this->exchangeFailure($responses);

        $this->assertSame($reason, $exception->getReason());
        $this->assertSame($status, $exception->getHttpStatus());
    }

    public static function statusProvider(): array
    {
        return [
            '400 CONNECT_REQUEST_INVALID' => [400, ConnectException::INVALID_REQUEST],
            '401 INVALID_GRANT' => [401, ConnectException::TOKEN_REJECTED],
            '404 PLATFORM_CONNECT_UNAVAILABLE' => [404, ConnectException::UNAVAILABLE],
            '409 STORE_LINKED_TO_ANOTHER_COMPANY' => [409, ConnectException::STORE_TAKEN],
            '410 CONNECT_CODE_GONE' => [410, ConnectException::CODE_GONE],
            '429 rate limit' => [429, ConnectException::RATE_LIMITED],
            '503 EXTENSION_SIGNING_UNAVAILABLE' => [503, ConnectException::SIGNING_UNAVAILABLE],
            '0 transport' => [0, ConnectException::UNREACHABLE],
            '500' => [500, ConnectException::UNREACHABLE],
            '502' => [502, ConnectException::UNREACHABLE],
            'other 4xx' => [422, ConnectException::INVALID_REQUEST],
        ];
    }

    /**
     * @dataProvider noRetryProvider
     */
    public function testClientErrorsAreNotRetried(int $status): void
    {
        $this->exchangeFailure([$this->error($status), $this->ok()]);

        $this->assertCount(1, $this->bodies);
        $this->assertSame([], $this->pauses);
    }

    public static function noRetryProvider(): array
    {
        return [[400], [401], [404], [409], [410], [429], [422]];
    }

    /**
     * @dataProvider retryProvider
     */
    public function testTransportAndServerErrorsAreRetriedWithTheSameBody(int $status): void
    {
        $result = $this->exchanger([$this->error($status), $this->error($status), $this->ok()])
            ->exchange(self::CODE, self::STATE, self::STORE_URL, self::TOKEN);

        $this->assertSame(self::SHOP_ID, $result['shopId']);
        $this->assertCount(3, $this->bodies);
        $this->assertSame($this->bodies[0], $this->bodies[1]);
        $this->assertSame($this->bodies[0], $this->bodies[2]);
        $this->assertSame([1, 3], $this->pauses);
    }

    public static function retryProvider(): array
    {
        return [[0], [500], [502], [503], [504]];
    }

    public function testGivesUpAfterTwoRetries(): void
    {
        $exception = $this->exchangeFailure(array_fill(0, 5, $this->error(0)));

        $this->assertSame(ConnectException::UNREACHABLE, $exception->getReason());
        $this->assertCount(3, $this->bodies);
        $this->assertSame([1, 3], $this->pauses);
    }

    public function testRetryStopsAtAClientError(): void
    {
        $exception = $this->exchangeFailure([$this->error(502), $this->error(410), $this->ok()]);

        $this->assertSame(ConnectException::CODE_GONE, $exception->getReason());
        $this->assertCount(2, $this->bodies);
        $this->assertSame([1], $this->pauses);
    }

    /**
     * @dataProvider invalidSuccessProvider
     */
    public function testInvalid200IsInvalidResponse(?string $body): void
    {
        $exception = $this->exchangeFailure([new EcoResponse(200, $body)]);

        $this->assertSame(ConnectException::INVALID_RESPONSE, $exception->getReason());
        $this->assertCount(1, $this->bodies);
    }

    public static function invalidSuccessProvider(): array
    {
        return [
            'no body' => [null],
            'not json' => ['<html></html>'],
            'no shop id' => ['{"signing_secret":"s"}'],
            'shop id not a uuid' => ['{"shop_id":"123","signing_secret":"s"}'],
            'empty secret' => ['{"shop_id":"0b6f6a9e-3c1d-4f2a-9b8e-7d6c5b4a3f21","signing_secret":""}'],
            'secret not a string' => ['{"shop_id":"0b6f6a9e-3c1d-4f2a-9b8e-7d6c5b4a3f21","signing_secret":42}'],
        ];
    }

    public function testExceptionCarriesTheDetailForTheLog(): void
    {
        $exception = $this->exchangeFailure([$this->error(410, 'Connect code is gone')]);

        $this->assertSame('Connect code is gone', $exception->getDetail());
    }

    public function testLogsNeverContainTokenSecretCodeOrState(): void
    {
        $this->exchanger([$this->error(0), $this->error(503), $this->ok()])
            ->exchange(self::CODE, self::STATE, self::STORE_URL, self::TOKEN);

        $this->assertNotEmpty($this->logs, 'Retries are logged.');
        $dump = (string) json_encode($this->logs);
        foreach ([self::TOKEN, self::SECRET, self::CODE, self::STATE] as $sensitive) {
            $this->assertStringNotContainsString($sensitive, $dump);
        }
    }

    public function testMessagesExistForEveryReason(): void
    {
        foreach (
            [
                ConnectException::INVALID_REQUEST,
                ConnectException::TOKEN_REJECTED,
                ConnectException::UNAVAILABLE,
                ConnectException::STORE_TAKEN,
                ConnectException::CODE_GONE,
                ConnectException::RATE_LIMITED,
                ConnectException::SIGNING_UNAVAILABLE,
                ConnectException::UNREACHABLE,
                ConnectException::INVALID_RESPONSE,
            ] as $reason
        ) {
            $this->assertNotSame('', (string) (new ConnectException($reason))->getMerchantMessage());
        }
        $this->assertStringContainsString(
            'Bearer',
            (string) (new ConnectException(ConnectException::TOKEN_REJECTED))->getMerchantMessage()
        );
    }

    private function exchangeFailure(array $responses): ConnectException
    {
        try {
            $this->exchanger($responses)->exchange(self::CODE, self::STATE, self::STORE_URL, self::TOKEN);
        } catch (ConnectException $e) {
            return $e;
        }
        $this->fail('ConnectException expected.');
    }

    /**
     * @param EcoResponse[] $responses
     * @return Exchanger&MockObject
     */
    private function exchanger(array $responses): Exchanger
    {
        $eco = $this->createMock(EcoClient::class);
        $eco->method('postJson')->willReturnCallback(
            function (string $path, array $body, int $timeout) use (&$responses): EcoResponse {
                $this->assertSame('/api/v3/shops/magento/connect', $path);
                $this->assertSame(15, $timeout);
                $this->bodies[] = $body;

                return array_shift($responses);
            }
        );
        $logger = $this->createMock(Logger::class);
        foreach (['debug', 'info', 'notice', 'warning', 'error', 'critical'] as $level) {
            $logger->method($level)->willReturnCallback(function ($message, array $context = []): void {
                $this->logs[] = [$message, $context];
            });
        }

        $exchanger = $this->getMockBuilder(Exchanger::class)
            ->setConstructorArgs([$eco, $logger])
            ->onlyMethods(['pause'])
            ->getMock();
        $exchanger->method('pause')->willReturnCallback(function (int $seconds): void {
            $this->pauses[] = $seconds;
        });

        return $exchanger;
    }

    private function ok(): EcoResponse
    {
        return new EcoResponse(200, json_encode(['shop_id' => self::SHOP_ID, 'signing_secret' => self::SECRET]));
    }

    private function error(int $status, string $detail = 'failure'): EcoResponse
    {
        if ($status === 0) {
            return new EcoResponse(0, null, 'Operation timed out');
        }

        return new EcoResponse($status, json_encode(['errors' => [['status' => (string) $status, 'detail' => $detail]]]));
    }
}
