<?php
declare(strict_types=1);

namespace Skydropx\Shipping\Test\Unit\Model\Http;

use Magento\Framework\App\ProductMetadataInterface;
use Magento\Framework\HTTP\Client\Curl;
use Magento\Framework\HTTP\Client\CurlFactory;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Skydropx\Shipping\Logger\Logger;
use Skydropx\Shipping\Model\Build;
use Skydropx\Shipping\Model\Config;
use Skydropx\Shipping\Model\Http\EcoClient;
use Skydropx\Shipping\Model\Signer;

class EcoClientTest extends TestCase
{
    private const SECRET = 'not-a-real-secret-current';
    private const SHOP_ID = '0b6f6a9e-3c1d-4f2a-9b8e-7d6c5b4a3f21';
    private const RATES_PATH = '/api/v3/rates/magento/MX/checkout';

    /**
     * @var Curl&MockObject
     */
    private $curl;

    /**
     * @var CurlFactory&MockObject
     */
    private $curlFactory;

    /**
     * @var Config&MockObject
     */
    private $config;

    /**
     * @var Logger&MockObject
     */
    private $logger;

    /**
     * What the mocked Curl received.
     *
     * @var array{url: ?string, body: mixed, headers: array, timeout: ?int, options: array}
     */
    private array $sent;

    /**
     * Exception the mocked Curl::post() throws, if any.
     *
     * @var \Throwable|null
     */
    private ?\Throwable $postError = null;

    protected function setUp(): void
    {
        $this->sent = ['url' => null, 'body' => null, 'headers' => [], 'timeout' => null, 'options' => []];

        $this->curl = $this->getMockBuilder(Curl::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['setHeaders', 'setTimeout', 'setOption', 'post', 'getStatus', 'getBody', 'getHeaders'])
            ->getMock();
        $this->curl->method('setHeaders')->willReturnCallback(function ($headers) {
            $this->sent['headers'] = $headers;
        });
        $this->curl->method('setTimeout')->willReturnCallback(function ($timeout) {
            $this->sent['timeout'] = $timeout;
        });
        $this->curl->method('setOption')->willReturnCallback(function ($name, $value) {
            $this->sent['options'][$name] = $value;
        });
        $this->curl->method('post')->willReturnCallback(function ($url, $body) {
            $this->sent['url'] = $url;
            $this->sent['body'] = $body;
            if ($this->postError !== null) {
                throw $this->postError;
            }
        });

        $this->curlFactory = $this->getMockBuilder(CurlFactory::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['create'])
            ->getMock();
        $this->curlFactory->method('create')->willReturn($this->curl);

        $this->config = $this->createMock(Config::class);
        $this->config->method('getSigningSecret')->willReturn(self::SECRET);

        $this->logger = $this->createMock(Logger::class);
    }

    public function testPostSignedSendsExactlyTheSignedString(): void
    {
        $this->respond(200, '{"rates":[]}');
        $rawBody = '{"name":"Camión","url":"https://shop.example.com/a/b","weight":1.0,"street":["Av. Juárez 10"]}';

        $response = $this->client()->postSigned(self::RATES_PATH, $rawBody, ['shop_id' => self::SHOP_ID]);

        $this->assertSame(200, $response->getStatus());
        $this->assertTrue($response->isSuccess());
        $this->assertSame($rawBody, $this->sent['body'], 'post() receives the signed string, byte for byte.');
        $this->assertIsString($this->sent['body']);
        $this->assertSame(
            (new Signer())->sign(self::SECRET, $this->sent['headers']['X-Skydropx-Timestamp'], $rawBody),
            $this->sent['headers']['X-Skydropx-Signature']
        );
    }

    public function testPostSignedHeaders(): void
    {
        $this->respond(200, '{}');
        $before = time();

        $this->client()->postSigned(
            '/api/v3/order/webhook/MX/MAGENTO/NEW_ORDER',
            '{"event":"order.created"}',
            [],
            ['X-Skydropx-Shop-Id' => self::SHOP_ID, 'X-Skydropx-Signature' => 'forged']
        );

        $headers = $this->sent['headers'];
        $this->assertSame('application/json', $headers['Content-Type']);
        $this->assertSame('application/json', $headers['Accept']);
        $this->assertSame(
            sprintf('%s-magento/%s (Magento 2.4.8)', Build::SLUG, Build::VERSION),
            $headers['User-Agent']
        );
        $this->assertSame(self::SHOP_ID, $headers['X-Skydropx-Shop-Id']);
        $this->assertGreaterThanOrEqual($before, (int) $headers['X-Skydropx-Timestamp']);
        $this->assertLessThanOrEqual(time(), (int) $headers['X-Skydropx-Timestamp']);
        $this->assertNotSame('forged', $headers['X-Skydropx-Signature'], 'Extra headers cannot replace the signature.');
        $this->assertSame(
            'https://eco.example.com/api/v3/order/webhook/MX/MAGENTO/NEW_ORDER',
            $this->sent['url'],
            'No query string when none is given.'
        );
    }

    public function testShopIdTravelsInTheQueryAndTimeoutsAreSet(): void
    {
        $this->respond(200, '{"rates":[]}');

        $this->client('https://eco.example.com/')
            ->postSigned(self::RATES_PATH, '{}', ['shop_id' => self::SHOP_ID], [], 4);

        $this->assertSame(
            'https://eco.example.com' . self::RATES_PATH . '?shop_id=' . self::SHOP_ID,
            $this->sent['url']
        );
        $this->assertSame(4, $this->sent['timeout']);
        $this->assertSame(2, $this->sent['options'][CURLOPT_CONNECTTIMEOUT]);
    }

    public function testTransportErrorBecomesStatusZero(): void
    {
        $this->postError = new \Exception('Operation timed out after 4001 milliseconds with 0 bytes received');

        $response = $this->client()->postSigned(self::RATES_PATH, '{}', ['shop_id' => self::SHOP_ID], [], 4);

        $this->assertSame(0, $response->getStatus());
        $this->assertTrue($response->isTransportError());
        $this->assertFalse($response->isSuccess());
        $this->assertNull($response->getBody());
        $this->assertStringContainsString('timed out', (string) $response->getErrorDetail());
    }

    public function testTransportMessageIsTruncated(): void
    {
        $this->postError = new \Exception(str_repeat('e', 400));

        $response = $this->client()->postSigned(self::RATES_PATH, '{}');

        $this->assertSame(255, strlen((string) $response->getErrorDetail()));
    }

    public function testUnbuiltModuleNeverCalls(): void
    {
        $this->curlFactory->expects($this->never())->method('create');

        $client = $this->client('https://eco.example.com', false);
        $signed = $client->postSigned(self::RATES_PATH, '{}', ['shop_id' => self::SHOP_ID]);
        $unsigned = $client->postJson('/api/v3/shops/magento/connect', ['code' => 'x']);

        $this->assertSame(0, $signed->getStatus());
        $this->assertSame(0, $unsigned->getStatus());
        $this->assertNotNull($signed->getErrorDetail());
    }

    public function testMissingSecretNeverCalls(): void
    {
        $config = $this->createMock(Config::class);
        $config->method('getSigningSecret')->willReturn(null);
        $this->config = $config;
        $this->curlFactory->expects($this->never())->method('create');

        $response = $this->client()->postSigned(self::RATES_PATH, '{}');

        $this->assertSame(0, $response->getStatus());
    }

    public function testJsonApiErrorDetailIsTruncatedTo255(): void
    {
        $detail = str_repeat('x', 300);
        $this->respond(410, json_encode(['errors' => [['status' => '410', 'title' => 'Gone', 'detail' => $detail]]]));

        $response = $this->client()->postJson('/api/v3/shops/magento/connect', ['code' => 'abc']);

        $this->assertSame(410, $response->getStatus());
        $this->assertFalse($response->isSuccess());
        $this->assertFalse($response->isTransportError());
        $this->assertSame(str_repeat('x', 255), $response->getErrorDetail());
    }

    public function testServerDateIsReadCaseInsensitively(): void
    {
        $this->respond(401, '{"errors":[]}', ['date' => 'Wed, 11 Nov 2026 10:00:00 GMT']);

        $response = $this->client()->postSigned(self::RATES_PATH, '{}');

        $this->assertSame(gmmktime(10, 0, 0, 11, 11, 2026), $response->getServerDate());
        $this->assertNull($response->getErrorDetail());
    }

    public function testPostJsonSerializesOnceAndDoesNotSign(): void
    {
        $this->respond(200, '{"shop_id":"' . self::SHOP_ID . '","signing_secret":"s"}');
        $body = ['store_url' => 'https://shop.example.com/', 'module_version' => '1.0.0', 'name' => 'Tienda Niño'];

        $response = $this->client()->postJson('/api/v3/shops/magento/connect', $body, 15);

        $this->assertSame(
            json_encode($body, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION),
            $this->sent['body']
        );
        $this->assertArrayNotHasKey('X-Skydropx-Signature', $this->sent['headers']);
        $this->assertArrayNotHasKey('X-Skydropx-Timestamp', $this->sent['headers']);
        $this->assertSame(15, $this->sent['timeout']);
        $this->assertSame(self::SHOP_ID, $response->getBody()['shop_id']);
    }

    public function testOneLogLinePerCallWithoutQuery(): void
    {
        $this->respond(200, '{}');
        $this->logger->expects($this->once())->method('info')->with(
            'ECO_HTTP',
            $this->callback(static function (array $context): bool {
                return $context['method'] === 'POST'
                    && $context['path'] === self::RATES_PATH
                    && $context['status'] === 200
                    && is_int($context['ms'])
                    && !array_key_exists('body', $context);
            })
        );
        $this->logger->expects($this->never())->method('debug');

        $this->client()->postSigned(self::RATES_PATH, '{"secret":"x"}', ['shop_id' => self::SHOP_ID]);
    }

    /**
     * Stub the Curl response.
     */
    private function respond(int $status, string $body, array $headers = []): void
    {
        $this->curl->method('getStatus')->willReturn($status);
        $this->curl->method('getBody')->willReturn($body);
        $this->curl->method('getHeaders')->willReturn($headers);
    }

    /**
     * Client with the Build seams overridden (OD-3).
     */
    private function client(string $base = 'https://eco.example.com', bool $built = true): EcoClient
    {
        $metadata = $this->createMock(ProductMetadataInterface::class);
        $metadata->method('getVersion')->willReturn('2.4.8');

        $client = $this->getMockBuilder(EcoClient::class)
            ->setConstructorArgs([$this->curlFactory, $this->config, new Signer(), $metadata, $this->logger])
            ->onlyMethods(['isBuilt', 'ecommerceBase'])
            ->getMock();
        $client->method('isBuilt')->willReturn($built);
        $client->method('ecommerceBase')->willReturn($base);

        return $client;
    }
}
