<?php
declare(strict_types=1);

namespace Skydropx\Shipping\Test\Unit\Controller\Adminhtml\Connect;

use Magento\Backend\App\Action\Context;
use Magento\Backend\Model\Auth;
use Magento\Backend\Model\View\Result\Redirect;
use Magento\Backend\Model\View\Result\RedirectFactory;
use Magento\Framework\App\RequestInterface;
use Magento\Framework\DataObject;
use Magento\Framework\Message\ManagerInterface;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Skydropx\Shipping\Controller\Adminhtml\Connect\Callback;
use Skydropx\Shipping\Logger\Logger;
use Skydropx\Shipping\Model\Config;
use Skydropx\Shipping\Model\Connect\ConnectException;
use Skydropx\Shipping\Model\Connect\ConnectUrlBuilder;
use Skydropx\Shipping\Model\Connect\Exchanger;
use Skydropx\Shipping\Model\Connect\IntegrationProvisioner;
use Skydropx\Shipping\Model\Connect\StateManager;
use Skydropx\Shipping\Model\ConnectionStore;

class CallbackTest extends TestCase
{
    private const STATE = 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa';
    private const CODE = 'Q2hhbmdlTWVQbGVhc2VDb2RlMTIzNDU2Nzg5MA';
    private const TOKEN = 'integration-access-token-value';
    private const SECRET = 'not-a-real-secret-current';
    private const SHOP_ID = '0b6f6a9e-3c1d-4f2a-9b8e-7d6c5b4a3f21';
    private const STORE_URL = 'https://shop.example.com/';
    private const COMPLETE_URL = 'https://pro.example.com/merchant_stores/plugin_connect/complete?platform=magento&code=x';

    /**
     * @var string[] Order of the side effects.
     */
    private array $calls = [];

    /**
     * @var array{string, mixed}[]
     */
    private array $redirects = [];

    /**
     * @var string[]
     */
    private array $errors = [];

    /**
     * @var array[]
     */
    private array $logs = [];

    /**
     * @var array
     */
    private array $params = ['state' => self::STATE, 'code' => self::CODE];

    /**
     * @var StateManager&MockObject
     */
    private $stateManager;

    /**
     * @var IntegrationProvisioner&MockObject
     */
    private $provisioner;

    /**
     * @var Exchanger&MockObject
     */
    private $exchanger;

    /**
     * @var ConnectionStore&MockObject
     */
    private $connectionStore;

    /**
     * @var Config&MockObject
     */
    private $config;

    /**
     * @var ConnectUrlBuilder&MockObject
     */
    private $connectUrlBuilder;

    protected function setUp(): void
    {
        $this->stateManager = $this->createMock(StateManager::class);
        $this->stateManager->method('consume')->willReturnCallback(function (string $state, int $userId) {
            $this->calls[] = 'consume';
            $this->assertSame(7, $userId);

            return $state === self::STATE
                ? ['store_url' => self::STORE_URL, 'callback_url' => 'https://shop.example.com/admin/cb/']
                : null;
        });

        $this->provisioner = $this->createMock(IntegrationProvisioner::class);
        $this->provisioner->method('provision')->willReturnCallback(function () {
            $this->calls[] = 'provision';

            return ['integrationId' => 42, 'accessToken' => self::TOKEN];
        });
        $this->provisioner->method('addPending')->willReturnCallback(function (int $id): void {
            $this->calls[] = 'addPending:' . $id;
        });
        $this->provisioner->method('removePending')->willReturnCallback(function (int $id): void {
            $this->calls[] = 'removePending:' . $id;
        });
        $this->provisioner->method('delete')->willReturnCallback(function (int $id): void {
            $this->calls[] = 'delete:' . $id;
        });

        $this->exchanger = $this->createMock(Exchanger::class);
        $this->connectionStore = $this->createMock(ConnectionStore::class);
        $this->connectionStore->method('saveExtensionConnection')->willReturnCallback(
            function (string $shopId, string $secret, int $integrationId, string $storeUrl): void {
                $this->calls[] = 'save';
                $this->assertSame([self::SHOP_ID, self::SECRET, 42, self::STORE_URL], [$shopId, $secret, $integrationId, $storeUrl]);
            }
        );

        $this->config = $this->createMock(Config::class);
        $this->config->method('getIntegrationId')->willReturnCallback(function () {
            $this->calls[] = 'getIntegrationId';

            return 5;
        });

        $this->connectUrlBuilder = $this->createMock(ConnectUrlBuilder::class);
        $this->connectUrlBuilder->method('completeUrl')->with(self::CODE)->willReturn(self::COMPLETE_URL);
    }

    public function testSuccessSavesThenDeletesThePreviousIntegrationAndGoesToComplete(): void
    {
        $this->exchanger->expects($this->once())->method('exchange')
            ->with(self::CODE, self::STATE, self::STORE_URL, self::TOKEN)
            ->willReturnCallback(function () {
                $this->calls[] = 'exchange';

                return ['shopId' => self::SHOP_ID, 'signingSecret' => self::SECRET];
            });

        $this->controller()->execute();

        $this->assertSame(
            ['consume', 'getIntegrationId', 'provision', 'addPending:42', 'exchange', 'save', 'delete:5', 'removePending:42'],
            $this->calls,
            'The previous integration id is read before saving and deleted only after the new connection is saved.'
        );
        $this->assertSame([['url', self::COMPLETE_URL]], $this->redirects);
        $this->assertSame([], $this->errors);
        $this->assertSame([['CONNECT_SUCCESS', ['shop_id' => self::SHOP_ID]]], $this->logs);
    }

    public function testSuccessWithoutPreviousIntegrationDeletesNothing(): void
    {
        $config = $this->createMock(Config::class);
        $config->method('getIntegrationId')->willReturn(null);
        $this->config = $config;
        $this->exchanger->method('exchange')->willReturn(['shopId' => self::SHOP_ID, 'signingSecret' => self::SECRET]);

        $this->controller()->execute();

        $this->assertNotContains('delete:5', $this->calls);
        $this->assertSame([['url', self::COMPLETE_URL]], $this->redirects);
    }

    public function testFailureDeletesTheNewIntegrationAndGoesBackToTheConfig(): void
    {
        $this->exchanger->method('exchange')->willReturnCallback(function () {
            $this->calls[] = 'exchange';
            throw new ConnectException(ConnectException::STORE_TAKEN, 409, 'Store linked to another company');
        });

        $this->controller()->execute();

        $this->assertSame(
            ['consume', 'getIntegrationId', 'provision', 'addPending:42', 'exchange', 'delete:42', 'removePending:42'],
            $this->calls,
            'Nothing is saved and the previous integration survives.'
        );
        $this->assertConfigRedirect();
        $this->assertSame(
            [(string) (new ConnectException(ConnectException::STORE_TAKEN))->getMerchantMessage()],
            $this->errors
        );
        $this->assertSame('CONNECT_FAILED', $this->logs[0][0]);
        $this->assertSame('store_taken', $this->logs[0][1]['reason']);
        $this->assertSame(409, $this->logs[0][1]['status']);
        $this->assertNoSecretsLogged();
    }

    public function testExpiredOrForeignStateStopsBeforeCreatingAnything(): void
    {
        $this->params['state'] = str_repeat('b', 64);

        $this->controller()->execute();

        $this->assertSame(['consume'], $this->calls);
        $this->assertConfigRedirect();
        $this->assertSame(['The link expired; click Connect again.'], $this->errors);
        $this->assertSame('state_invalid', $this->logs[0][1]['reason']);
    }

    public function testMissingStateStopsBeforeCreatingAnything(): void
    {
        unset($this->params['state']);

        $this->controller()->execute();

        $this->assertSame(['consume'], $this->calls);
        $this->assertConfigRedirect();
    }

    /**
     * @dataProvider invalidCodeProvider
     */
    public function testInvalidCodeStopsBeforeCreatingAnything(?string $code): void
    {
        $this->params['code'] = $code;

        $this->controller()->execute();

        $this->assertSame(['consume'], $this->calls, 'The state is consumed anyway (single use).');
        $this->assertConfigRedirect();
        $this->assertSame(['The connection code is not valid. Click Connect again.'], $this->errors);
    }

    public static function invalidCodeProvider(): array
    {
        return [
            'missing' => [null],
            'too short' => ['abc'],
            'too long' => [str_repeat('a', 129)],
            'not base64url' => ['abcdefghijklmnop+/='],
            'injection' => ['abcdefghijklmnop&state=x'],
        ];
    }

    public function testProvisionFailureDoesNotExchange(): void
    {
        $provisioner = $this->createMock(IntegrationProvisioner::class);
        $provisioner->method('provision')->willThrowException(new ConnectException(ConnectException::INTEGRATION_FAILED));
        $provisioner->expects($this->never())->method('delete');
        $this->provisioner = $provisioner;
        $this->exchanger->expects($this->never())->method('exchange');

        $this->controller()->execute();

        $this->assertConfigRedirect();
        $this->assertCount(1, $this->errors);
    }

    public function testUnexpectedErrorNeverEscapes(): void
    {
        $this->exchanger->method('exchange')->willThrowException(new \RuntimeException('boom'));

        $this->controller()->execute();

        $this->assertContains('delete:42', $this->calls);
        $this->assertConfigRedirect();
        $this->assertCount(1, $this->errors);
    }

    private function assertConfigRedirect(): void
    {
        $this->assertSame([['path', ['adminhtml/system_config/edit', ['section' => 'carriers']]]], $this->redirects);
    }

    private function assertNoSecretsLogged(): void
    {
        $dump = (string) json_encode($this->logs);
        foreach ([self::TOKEN, self::SECRET, self::CODE, self::STATE] as $sensitive) {
            $this->assertStringNotContainsString($sensitive, $dump);
        }
    }

    private function controller(): Callback
    {
        $request = $this->createMock(RequestInterface::class);
        $request->method('getParam')->willReturnCallback(fn ($name, $default = null) => $this->params[$name] ?? $default);

        $messages = $this->createMock(ManagerInterface::class);
        $messages->method('addErrorMessage')->willReturnCallback(function ($message) use ($messages) {
            $this->errors[] = (string) $message;

            return $messages;
        });

        $redirect = $this->createMock(Redirect::class);
        $redirect->method('setUrl')->willReturnCallback(function ($url) use ($redirect) {
            $this->redirects[] = ['url', $url];

            return $redirect;
        });
        $redirect->method('setPath')->willReturnCallback(function ($path, $params = []) use ($redirect) {
            $this->redirects[] = ['path', [$path, $params]];

            return $redirect;
        });
        $redirectFactory = $this->createMock(RedirectFactory::class);
        $redirectFactory->method('create')->willReturn($redirect);

        $auth = $this->createMock(Auth::class);
        $auth->method('getUser')->willReturn(new DataObject(['id' => 7]));

        $context = $this->createMock(Context::class);
        $context->method('getRequest')->willReturn($request);
        $context->method('getMessageManager')->willReturn($messages);
        $context->method('getResultRedirectFactory')->willReturn($redirectFactory);
        $context->method('getAuth')->willReturn($auth);

        $logger = $this->createMock(Logger::class);
        foreach (['info', 'warning', 'error'] as $level) {
            $logger->method($level)->willReturnCallback(function ($message, array $context = []): void {
                $this->logs[] = [$message, $context];
            });
        }

        return new Callback(
            $context,
            $this->stateManager,
            $this->provisioner,
            $this->exchanger,
            $this->connectionStore,
            $this->config,
            $this->connectUrlBuilder,
            $logger
        );
    }
}
