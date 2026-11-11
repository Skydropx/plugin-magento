<?php
declare(strict_types=1);

namespace Skydropx\Shipping\Test\Unit\Controller\Adminhtml\Connect;

use Magento\Backend\App\Action\Context;
use Magento\Backend\Model\View\Result\Redirect;
use Magento\Backend\Model\View\Result\RedirectFactory;
use Magento\Framework\App\Action\HttpPostActionInterface;
use Magento\Framework\Message\ManagerInterface;
use Magento\Store\Model\Store;
use Magento\Store\Model\StoreManagerInterface;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Skydropx\Shipping\Controller\Adminhtml\Connect\Test;
use Skydropx\Shipping\Logger\Logger;
use Skydropx\Shipping\Model\Carrier\Skydropx;
use Skydropx\Shipping\Model\Config;
use Skydropx\Shipping\Model\Http\EcoClient;
use Skydropx\Shipping\Model\Http\EcoResponse;
use Skydropx\Shipping\Model\Rates\PayloadBuilder;

class TestTest extends TestCase
{
    private const SHOP_ID = '0b6f6a9e-3c1d-4f2a-9b8e-7d6c5b4a3f21';

    private const SAMPLE = [
        'quote_id' => '',
        'store_id' => 1,
        'currency' => 'MXN',
        'origin' => ['country' => 'MX', 'postcode' => '06600', 'street' => []],
        'destination' => ['country' => 'MX', 'postcode' => '06600', 'street' => []],
        'package' => ['weight' => 1.0, 'weight_unit' => 'kgs', 'value' => 100.0, 'qty' => 1],
        'items' => [['sku' => 'TEST', 'name' => 'Test', 'qty' => 1, 'weight' => 1.0, 'price' => 100.0]],
    ];

    /**
     * @var string[]
     */
    private array $errors = [];

    /**
     * @var string[]
     */
    private array $successes = [];

    /**
     * @var array{string, mixed}[]
     */
    private array $redirects = [];

    /**
     * @var Config&MockObject
     */
    private $config;

    /**
     * @var EcoClient&MockObject
     */
    private $ecoClient;

    protected function setUp(): void
    {
        $this->config = $this->createMock(Config::class);
        $this->config->method('getShopId')->willReturn(self::SHOP_ID);
        $this->config->method('getRatesTimeout')->willReturn(4);
        $this->ecoClient = $this->createMock(EcoClient::class);
    }

    public function testIsAPostAction(): void
    {
        $this->assertInstanceOf(HttpPostActionInterface::class, $this->controller());
    }

    public function testNotConnectedDoesNotCallEco(): void
    {
        $this->config->method('isConnected')->willReturn(false);
        $this->ecoClient->expects($this->never())->method('postSigned');

        $this->controller()->execute();

        $this->assertCount(1, $this->errors);
        $this->assertConfigRedirect();
    }

    public function testAcceptedSignatureReportsTheRateCount(): void
    {
        $this->config->method('isConnected')->willReturn(true);
        $this->ecoClient->expects($this->once())->method('postSigned')
            ->with(
                Skydropx::RATES_PATH,
                json_encode(self::SAMPLE, EcoClient::JSON_FLAGS),
                ['shop_id' => self::SHOP_ID],
                [],
                4
            )
            ->willReturn(new EcoResponse(200, '{"rates":[{"code":"a"},{"code":"b"},{"code":"c"}]}'));

        $this->controller()->execute();

        $this->assertSame(['Signature accepted: 3 rates.'], $this->successes);
        $this->assertSame([], $this->errors);
        $this->assertConfigRedirect();
    }

    public function testRejectedSignature(): void
    {
        $this->config->method('isConnected')->willReturn(true);
        $this->ecoClient->method('postSigned')->willReturn(new EcoResponse(401, '{}', null, time()));

        $this->controller()->execute();

        $this->assertSame(['The signing key does not match (401).'], $this->errors);
        $this->assertSame([], $this->successes);
    }

    public function testRejectedSignatureWithSkewedClockWarnsAboutTheClock(): void
    {
        $this->config->method('isConnected')->willReturn(true);
        $this->ecoClient->method('postSigned')->willReturn(new EcoResponse(401, '{}', null, time() + 600));

        $this->controller()->execute();

        $this->assertCount(1, $this->errors);
        $this->assertStringStartsWith('The signing key does not match (401).', $this->errors[0]);
        $this->assertStringContainsString('clock', $this->errors[0]);
    }

    /**
     * @dataProvider noAnswerProvider
     */
    public function testNoAnswer(EcoResponse $response, string $message): void
    {
        $this->config->method('isConnected')->willReturn(true);
        $this->ecoClient->method('postSigned')->willReturn($response);

        $this->controller()->execute();

        $this->assertSame([$message], $this->errors);
        $this->assertSame([], $this->successes);
    }

    public static function noAnswerProvider(): array
    {
        return [
            'timeout' => [new EcoResponse(0, null, 'Operation timed out'), 'No response from PLUGIN_NAME.'],
            '500' => [new EcoResponse(500, '{}'), 'No response from PLUGIN_NAME (HTTP 500).'],
            '404' => [new EcoResponse(404, '{}'), 'No response from PLUGIN_NAME (HTTP 404).'],
        ];
    }

    public function testUnexpectedErrorNeverEscapes(): void
    {
        $this->config->method('isConnected')->willReturn(true);
        $this->ecoClient->method('postSigned')->willThrowException(new \RuntimeException('boom'));

        $this->controller()->execute();

        $this->assertCount(1, $this->errors);
        $this->assertConfigRedirect();
    }

    private function assertConfigRedirect(): void
    {
        $this->assertSame([['path', ['adminhtml/system_config/edit', ['section' => 'carriers']]]], $this->redirects);
    }

    private function controller(): Test
    {
        $messages = $this->createMock(ManagerInterface::class);
        $messages->method('addErrorMessage')->willReturnCallback(function ($message) use ($messages) {
            $this->errors[] = (string) $message;

            return $messages;
        });
        $messages->method('addSuccessMessage')->willReturnCallback(function ($message) use ($messages) {
            $this->successes[] = (string) $message;

            return $messages;
        });

        $redirect = $this->createMock(Redirect::class);
        $redirect->method('setPath')->willReturnCallback(function ($path, $params = []) use ($redirect) {
            $this->redirects[] = ['path', [$path, $params]];

            return $redirect;
        });
        $redirectFactory = $this->createMock(RedirectFactory::class);
        $redirectFactory->method('create')->willReturn($redirect);

        $context = $this->createMock(Context::class);
        $context->method('getMessageManager')->willReturn($messages);
        $context->method('getResultRedirectFactory')->willReturn($redirectFactory);

        $store = $this->getMockBuilder(Store::class)->disableOriginalConstructor()->onlyMethods(['getId'])->getMock();
        $store->method('getId')->willReturn(1);
        $storeManager = $this->createMock(StoreManagerInterface::class);
        $storeManager->method('getDefaultStoreView')->willReturn($store);

        $payloadBuilder = $this->createMock(PayloadBuilder::class);
        $payloadBuilder->method('buildSample')->with(1)->willReturn(self::SAMPLE);

        return new Test(
            $context,
            $this->config,
            $this->ecoClient,
            $payloadBuilder,
            $storeManager,
            $this->createMock(Logger::class)
        );
    }
}
