<?php
declare(strict_types=1);

namespace Skydropx\Shipping\Test\Unit\Controller\Adminhtml\Connect;

use Magento\Backend\App\Action\Context;
use Magento\Backend\Model\View\Result\Redirect;
use Magento\Backend\Model\View\Result\RedirectFactory;
use Magento\Framework\App\Action\HttpPostActionInterface;
use Magento\Framework\Message\ManagerInterface;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Skydropx\Shipping\Controller\Adminhtml\Connect\Disconnect;
use Skydropx\Shipping\Logger\Logger;
use Skydropx\Shipping\Model\Config;
use Skydropx\Shipping\Model\Connect\ConnectUrlBuilder;
use Skydropx\Shipping\Model\Connect\IntegrationProvisioner;
use Skydropx\Shipping\Model\ConnectionStore;

class DisconnectTest extends TestCase
{
    private const STORES_URL = 'https://pro.example.com/merchant_stores';

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
     * @var IntegrationProvisioner&MockObject
     */
    private $provisioner;

    /**
     * @var ConnectionStore&MockObject
     */
    private $connectionStore;

    protected function setUp(): void
    {
        $this->config = $this->createMock(Config::class);
        $this->provisioner = $this->createMock(IntegrationProvisioner::class);
        $this->connectionStore = $this->createMock(ConnectionStore::class);
    }

    public function testIsAPostAction(): void
    {
        $this->assertInstanceOf(HttpPostActionInterface::class, $this->controller());
    }

    public function testExtensionModeDeletesTheIntegrationAndTheConfig(): void
    {
        $this->config->method('getConnectionMode')->willReturn('extension');
        $this->config->method('getIntegrationId')->willReturn(42);
        $this->provisioner->expects($this->once())->method('delete')->with(42);
        $this->connectionStore->expects($this->once())->method('clear');

        $this->controller()->execute();

        $this->assertCount(1, $this->successes);
        $this->assertStringContainsString(self::STORES_URL, $this->successes[0]);
        $this->assertStringNotContainsString('<a', $this->successes[0], 'Plain text message.');
        $this->assertSame([], $this->errors);
        $this->assertConfigRedirect();
    }

    public function testManualModeStillDeletesTheIntegrationTheModuleCreated(): void
    {
        $this->config->method('getConnectionMode')->willReturn('manual');
        $this->config->method('getIntegrationId')->willReturn(42);
        $this->provisioner->expects($this->once())->method('delete')->with(42);
        $this->connectionStore->expects($this->once())->method('clear');

        $this->controller()->execute();

        $this->assertCount(1, $this->successes);
    }

    /**
     * @dataProvider modeProvider
     */
    public function testWithoutIntegrationOnlyClears(?string $mode): void
    {
        $this->config->method('getConnectionMode')->willReturn($mode);
        $this->config->method('getIntegrationId')->willReturn(null);
        $this->provisioner->expects($this->never())->method('delete');
        $this->connectionStore->expects($this->once())->method('clear');

        $this->controller()->execute();

        $this->assertCount(1, $this->successes);
    }

    public static function modeProvider(): array
    {
        return ['extension' => ['extension'], 'manual' => ['manual'], 'no mode' => [null]];
    }

    public function testFailureShowsAnErrorAndNeverEscapes(): void
    {
        $this->config->method('getConnectionMode')->willReturn('extension');
        $this->config->method('getIntegrationId')->willReturn(42);
        $this->provisioner->method('delete')->willThrowException(new \RuntimeException('db down'));
        $this->connectionStore->expects($this->never())->method('clear');

        $this->controller()->execute();

        $this->assertSame([], $this->successes);
        $this->assertCount(1, $this->errors);
        $this->assertConfigRedirect();
    }

    private function assertConfigRedirect(): void
    {
        $this->assertSame([['path', ['adminhtml/system_config/edit', ['section' => 'carriers']]]], $this->redirects);
    }

    private function controller(): Disconnect
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

        $urlBuilder = $this->createMock(ConnectUrlBuilder::class);
        $urlBuilder->method('storesUrl')->willReturn(self::STORES_URL);

        return new Disconnect(
            $context,
            $this->config,
            $this->provisioner,
            $this->connectionStore,
            $urlBuilder,
            $this->createMock(Logger::class)
        );
    }
}
