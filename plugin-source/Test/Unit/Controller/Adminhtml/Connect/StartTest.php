<?php
declare(strict_types=1);

namespace Skydropx\Shipping\Test\Unit\Controller\Adminhtml\Connect;

use Magento\Backend\App\Action\Context;
use Magento\Backend\Model\Auth;
use Magento\Backend\Model\View\Result\Redirect;
use Magento\Backend\Model\View\Result\RedirectFactory;
use Magento\Framework\App\Action\HttpPostActionInterface;
use Magento\Framework\App\RequestInterface;
use Magento\Framework\DataObject;
use Magento\Framework\Message\ManagerInterface;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Skydropx\Shipping\Controller\Adminhtml\Connect\Start;
use Skydropx\Shipping\Logger\Logger;
use Skydropx\Shipping\Model\Connect\ConnectException;
use Skydropx\Shipping\Model\Connect\ConnectUrlBuilder;
use Skydropx\Shipping\Model\Connect\IntegrationProvisioner;
use Skydropx\Shipping\Model\Connect\StateManager;
use Skydropx\Shipping\Model\Connect\StoreUrlResolver;

class StartTest extends TestCase
{
    private const STORE_URL = 'https://shop.example.com/';
    private const CALLBACK_URL = 'https://shop.example.com/admin/skydropx/connect/callback/key/abc/';
    private const STATE = 'cccccccccccccccccccccccccccccccccccccccccccccccccccccccccccccccc';
    private const CONNECT_URL = 'https://pro.example.com/merchant_stores/plugin_connect?platform=magento&state=x';

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
    private array $params = [];

    /**
     * @var StoreUrlResolver&MockObject
     */
    private $resolver;

    /**
     * @var IntegrationProvisioner&MockObject
     */
    private $provisioner;

    /**
     * @var StateManager&MockObject
     */
    private $stateManager;

    /**
     * @var ConnectUrlBuilder&MockObject
     */
    private $connectUrlBuilder;

    protected function setUp(): void
    {
        $this->resolver = $this->createMock(StoreUrlResolver::class);
        $this->provisioner = $this->createMock(IntegrationProvisioner::class);
        $this->stateManager = $this->createMock(StateManager::class);
        $this->stateManager->method('newState')->willReturn(self::STATE);
        $this->connectUrlBuilder = $this->createMock(ConnectUrlBuilder::class);
    }

    public function testIsAPostAction(): void
    {
        $this->assertInstanceOf(HttpPostActionInterface::class, $this->controller());
        $this->assertSame('Skydropx_Shipping::connect', Start::ADMIN_RESOURCE);
    }

    /**
     * @dataProvider preflightProvider
     */
    public function testPreflightFailureShowsTheMessageAndStays(string $reason): void
    {
        $this->resolver->method('resolve')->willThrowException(new ConnectException($reason));
        $this->provisioner->expects($this->never())->method('enableBearer');
        $this->stateManager->expects($this->never())->method('start');
        $this->connectUrlBuilder->expects($this->never())->method('connectUrl');

        $this->controller()->execute();

        $this->assertConfigRedirect();
        $this->assertSame([(string) (new ConnectException($reason))->getMerchantMessage()], $this->errors);
    }

    public static function preflightProvider(): array
    {
        return [
            'unbuilt package' => [ConnectException::NOT_BUILT],
            'http store or invalid host' => [ConnectException::INVALID_STORE_URL],
            'admin on another origin (D4)' => [ConnectException::ORIGIN_MISMATCH],
        ];
    }

    public function testBearerOffWithoutConsentAsksForTheCheckbox(): void
    {
        $this->resolvable();
        $this->provisioner->method('isBearerEnabled')->willReturn(false);
        $this->provisioner->expects($this->never())->method('enableBearer');
        $this->stateManager->expects($this->never())->method('start');

        $this->controller()->execute();

        $this->assertConfigRedirect();
        $this->assertSame(
            [(string) (new ConnectException(ConnectException::BEARER_REQUIRED))->getMerchantMessage()],
            $this->errors
        );
    }

    public function testBearerOffWithConsentEnablesItLogsAndContinues(): void
    {
        $this->resolvable();
        $this->params['enable_bearer'] = '1';
        $this->provisioner->method('isBearerEnabled')->willReturn(false);
        $this->provisioner->expects($this->once())->method('enableBearer');
        $this->stateManager->expects($this->once())->method('start');
        $this->connectUrlBuilder->method('connectUrl')->willReturn(self::CONNECT_URL);

        $this->controller()->execute();

        $this->assertSame([['url', self::CONNECT_URL]], $this->redirects);
        $this->assertSame([['BEARER_ENABLED', ['admin_user_id' => 7]]], $this->logs);
    }

    public function testSuccessStoresTheStateAndRedirectsToLabelsLight(): void
    {
        $this->resolvable();
        $this->provisioner->method('isBearerEnabled')->willReturn(true);
        $this->provisioner->expects($this->never())->method('enableBearer');
        $this->stateManager->expects($this->once())->method('start')
            ->with(self::STATE, self::STORE_URL, self::CALLBACK_URL, 7);
        $this->connectUrlBuilder->expects($this->once())->method('connectUrl')
            ->with(self::STORE_URL, self::STATE, self::CALLBACK_URL)
            ->willReturn(self::CONNECT_URL);

        $this->controller()->execute();

        $this->assertSame([['url', self::CONNECT_URL]], $this->redirects, 'The only external redirect is to labels-light.');
        $this->assertSame([], $this->errors);
    }

    public function testUnexpectedErrorNeverEscapes(): void
    {
        $this->resolvable();
        $this->provisioner->method('isBearerEnabled')->willReturn(true);
        $this->stateManager->method('start')->willThrowException(new \RuntimeException('session down'));

        $this->controller()->execute();

        $this->assertConfigRedirect();
        $this->assertCount(1, $this->errors);
    }

    private function resolvable(): void
    {
        $this->resolver->method('resolve')->willReturn(['store_url' => self::STORE_URL, 'callback_url' => self::CALLBACK_URL]);
    }

    private function assertConfigRedirect(): void
    {
        $this->assertSame([['path', ['adminhtml/system_config/edit', ['section' => 'carriers']]]], $this->redirects);
    }

    private function controller(): Start
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

        return new Start(
            $context,
            $this->resolver,
            $this->provisioner,
            $this->stateManager,
            $this->connectUrlBuilder,
            $logger
        );
    }
}
