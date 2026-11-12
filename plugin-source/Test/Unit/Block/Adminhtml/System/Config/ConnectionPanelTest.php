<?php
declare(strict_types=1);

namespace Skydropx\Shipping\Test\Unit\Block\Adminhtml\System\Config;

use Magento\Backend\Block\Template\Context;
use Magento\Framework\App\ObjectManager;
use Magento\Framework\Data\Form\Element\AbstractElement;
use Magento\Framework\Data\Form\FormKey;
use Magento\Framework\Escaper;
use Magento\Framework\Filesystem\Driver\File;
use Magento\Framework\Module\Dir\Reader;
use Magento\Framework\ObjectManagerInterface;
use Magento\Framework\UrlInterface;
use Magento\Framework\View\Helper\SecureHtmlRenderer;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Skydropx\Shipping\Block\Adminhtml\System\Config\ConnectionPanel;
use Skydropx\Shipping\Model\Config;
use Skydropx\Shipping\Model\Connect\ConnectException;
use Skydropx\Shipping\Model\Connect\ConnectUrlBuilder;
use Skydropx\Shipping\Model\Connect\IntegrationProvisioner;
use Skydropx\Shipping\Model\Connect\StoreUrlResolver;
use Skydropx\Shipping\Model\OrderPush\OutboxRepository;
use Skydropx\Shipping\Model\Rates\HealthRecorder;

class ConnectionPanelTest extends TestCase
{
    private const NOW = 1762459205;
    private const SHOP_ID = '0b6f6a9e-3c1d-4f2a-9b8e-7d6c5b4a3f21';
    private const MODULE_VIEW_DIR = '/var/www/app/code/Skydropx/Shipping/view';
    /**
     * The checkbox element; the panel script also names the selector, so plain text search is not enough.
     */
    private const BEARER_INPUT = '/<input[^>]*type="checkbox"[^>]*data-role="enable-bearer"/';
    private const LOGO = '/var/www/app/code/Skydropx/Shipping/view/adminhtml/web/images/logo.png';

    /**
     * @var mixed ObjectManager instance before the test (Backend Template asks it for two helpers).
     */
    private $previousObjectManager;

    /**
     * @var Config&MockObject
     */
    private $config;

    /**
     * @var StoreUrlResolver&MockObject
     */
    private $resolver;

    /**
     * @var IntegrationProvisioner&MockObject
     */
    private $provisioner;

    /**
     * @var HealthRecorder&MockObject
     */
    private $healthRecorder;

    /**
     * @var OutboxRepository&MockObject
     */
    private $outbox;

    /**
     * @var File&MockObject
     */
    private $file;

    protected function setUp(): void
    {
        $property = new \ReflectionProperty(ObjectManager::class, '_instance');
        $this->previousObjectManager = $property->getValue();
        $objectManager = $this->createMock(ObjectManagerInterface::class);
        $objectManager->method('get')->willReturnCallback(fn (string $class) => $this->createMock($class));
        ObjectManager::setInstance($objectManager);

        $this->config = $this->createMock(Config::class);
        $this->config->expects($this->never())->method('getSigningSecret');
        $this->config->method('isActive')->willReturn(true);
        $this->resolver = $this->createMock(StoreUrlResolver::class);
        $this->provisioner = $this->createMock(IntegrationProvisioner::class);
        $this->provisioner->method('isBearerEnabled')->willReturn(true);
        $this->healthRecorder = $this->createMock(HealthRecorder::class);
        $this->outbox = $this->createMock(OutboxRepository::class);
        $this->outbox->method('getStats')->willReturn(
            ['pending' => 0, 'failed' => 0, 'oldest_pending_at' => null, 'last_sent_at' => null]
        );
        $this->file = $this->createMock(File::class);
        $this->file->method('isExists')->willReturnCallback(fn (string $path): bool => $path === self::LOGO);
        $this->file->method('fileGetContents')->with(self::LOGO)->willReturn('PNGDATA');
    }

    protected function tearDown(): void
    {
        $property = new \ReflectionProperty(ObjectManager::class, '_instance');
        $property->setValue(null, $this->previousObjectManager);
    }

    /**
     * @dataProvider stateProvider
     */
    public function testShowsTheThreeConnectionStates(bool $connected, ?string $mode, string $state, string $label): void
    {
        $this->connected($connected, $mode);
        $panel = $this->panel();
        $html = $this->html($panel);

        $this->assertSame($state, $panel->getConnectionState());
        $this->assertStringContainsString('data-state="' . $state . '"', $html);
        $this->assertStringContainsString($label, $html);
        $this->assertSame($connected, strpos($html, 'data-action="test"') !== false, 'Test only when connected.');
        $this->assertSame($connected, strpos($html, 'data-action="disconnect"') !== false);
        $this->assertSame($connected, strpos($html, self::SHOP_ID) !== false);
        $this->assertStringContainsString($connected ? 'Reconnect' : 'Connect with PLUGIN_NAME', $html);
    }

    public static function stateProvider(): array
    {
        return [
            'extension' => [true, 'extension', ConnectionPanel::STATE_EXTENSION, 'Connected with the extension'],
            'manual' => [true, 'manual', ConnectionPanel::STATE_MANUAL, 'Connected manually'],
            'manual without mode' => [true, null, ConnectionPanel::STATE_MANUAL, 'Connected manually'],
            'disconnected' => [false, null, ConnectionPanel::STATE_DISCONNECTED, 'Not connected'],
        ];
    }

    public function testActionsArePlainButtonsInsideTheConfigForm(): void
    {
        $this->connected(true, 'extension');
        $html = $this->html($this->panel());

        $this->assertStringNotContainsString('<form', $html, 'No nested forms (OD-7).');
        $this->assertSame(3, substr_count($html, '<button type="button"'));
        $this->assertStringContainsString('data-form-key="FK123"', $html);
        $this->assertStringContainsString('data-url="https://admin.example.com/skydropx/connect/start/"', $html);
        $this->assertStringContainsString('data-confirm=', $html, 'Disconnect asks for confirmation.');
        $this->assertStringContainsString('<script nonce>', $html, 'JS goes through SecureHtmlRenderer.');
        $this->assertStringContainsString("document.createElement('form')", $html);
        $this->assertStringContainsString('enable_bearer', $html);
    }

    public function testOtherOriginDisablesOneClickAndExplainsTheManualConnection(): void
    {
        $this->connected(false, null);
        $resolver = $this->createMock(StoreUrlResolver::class);
        $resolver->method('resolve')->willThrowException(new ConnectException(ConnectException::ORIGIN_MISMATCH));
        $this->resolver = $resolver;
        $provisioner = $this->createMock(IntegrationProvisioner::class);
        $provisioner->method('isBearerEnabled')->willReturn(false);
        $this->provisioner = $provisioner;
        $panel = $this->panel();
        $html = $this->html($panel);

        $this->assertNotNull($panel->getOneClickBlocker());
        $this->assertMatchesRegularExpression('/data-action="connect"[^>]*disabled="disabled"/', $html);
        $this->assertStringContainsString('data-role="one-click-blocked"', $html);
        $this->assertStringContainsString('Connect manually', $html);
        $this->assertDoesNotMatchRegularExpression(
            self::BEARER_INPUT,
            $html,
            'No Bearer consent when one-click is off.'
        );
        $this->assertStringContainsString('data-role="manual-guide"', $html);
    }

    public function testBearerCheckboxOnlyWhenTheSettingIsOff(): void
    {
        $this->connected(false, null);
        $this->assertDoesNotMatchRegularExpression(self::BEARER_INPUT, $this->html($this->panel()));

        $provisioner = $this->createMock(IntegrationProvisioner::class);
        $provisioner->method('isBearerEnabled')->willReturn(false);
        $this->provisioner = $provisioner;
        $html = $this->html($this->panel());

        $this->assertMatchesRegularExpression(self::BEARER_INPUT, $html);
        $this->assertStringContainsString('Allow integration tokens as Bearer (required to connect)', $html);
        $this->assertDoesNotMatchRegularExpression('/data-action="connect"[^>]*disabled/', $html);
    }

    public function testLogoIsInlinedAsDataUri(): void
    {
        $this->connected(false, null);
        $panel = $this->panel();

        $this->assertSame('data:image/png;base64,' . base64_encode('PNGDATA'), $panel->getLogoDataUri());
        $this->assertStringContainsString('<img src="data:image/png;base64,UE5HREFUQQ=="', $this->html($panel));
    }

    public function testWithoutLogoThereIsNoImage(): void
    {
        $this->connected(false, null);
        $file = $this->createMock(File::class);
        $file->method('isExists')->willReturn(false);
        $file->expects($this->never())->method('fileGetContents');
        $this->file = $file;
        $panel = $this->panel();

        $this->assertNull($panel->getLogoDataUri());
        $this->assertStringNotContainsString('<img', $this->html($panel));
    }

    public function testPackageInfoShowsVersionEnvironmentAndEcoUrl(): void
    {
        $this->connected(false, null);

        $this->assertStringContainsString(
            'Package: PLUGIN_VERSION · PLUGIN_ENVIRONMENT · ECOMMERCE_URL',
            $this->html($this->panel())
        );
    }

    public function testWarnsWhenTheCarrierIsDisabled(): void
    {
        $config = $this->createMock(Config::class);
        $config->method('isConnected')->willReturn(true);
        $config->method('isActive')->willReturn(false);
        $this->config = $config;

        $this->assertStringContainsString('Rates are not shown at your checkout', $this->html($this->panel()));
    }

    /**
     * @dataProvider healthProvider
     */
    public function testRatesHealthText(?array $last, ?string $text): void
    {
        $this->healthRecorder->method('getLast')->willReturn($last);

        $this->assertSame($text, $this->panel()->getRatesHealth());
    }

    public static function healthProvider(): array
    {
        $at = '2026-11-11T10:00:00Z';

        return [
            'none' => [null, null],
            'ok' => [['status' => 'ok', 'http_status' => 200, 'at' => $at], "Last rates request ($at): rates returned."],
            'empty' => [
                ['status' => 'empty', 'http_status' => 200, 'at' => $at],
                "Last rates request ($at): no rates for that cart.",
            ],
            'unauthorized' => [
                ['status' => 'unauthorized', 'http_status' => 401, 'at' => $at],
                "Last rates request ($at): signing key rejected (HTTP 401).",
            ],
            'timeout' => [
                ['status' => 'timeout', 'http_status' => null, 'at' => $at],
                "Last rates request ($at): no answer in time.",
            ],
            'failed' => [
                ['status' => 'failed', 'http_status' => 502, 'at' => $at],
                "Last rates request ($at): failed (HTTP 502).",
            ],
        ];
    }

    /**
     * @dataProvider staleProvider
     */
    public function testPushAlertAfter15Minutes(int $ageSeconds, bool $stale): void
    {
        $this->connected(true, 'extension');
        $outbox = $this->createMock(OutboxRepository::class);
        $outbox->method('getStats')->willReturn([
            'pending' => 2,
            'failed' => 1,
            'oldest_pending_at' => gmdate('Y-m-d H:i:s', self::NOW - $ageSeconds),
            'last_sent_at' => '2026-11-11 09:00:00',
        ]);
        $this->outbox = $outbox;
        $html = $this->html($this->panel());

        $this->assertStringContainsString('2 pending · 1 failed · last sent: 2026-11-11 09:00:00', $html);
        $this->assertSame($stale, strpos($html, 'data-role="push-stale"') !== false);
    }

    public static function staleProvider(): array
    {
        return [
            'fresh' => [60, false],
            'exactly 15 min' => [900, false],
            'stuck' => [901, true],
        ];
    }

    public function testNoPushStaleWithoutPendingOrders(): void
    {
        $this->assertFalse($this->panel()->isPushStale(null));
        $this->assertFalse($this->panel()->isPushStale(['oldest_pending_at' => null]));
    }

    public function testRenderUsesTheWholeRow(): void
    {
        $panel = $this->getMockBuilder(ConnectionPanel::class)
            ->setConstructorArgs($this->constructorArgs())
            ->onlyMethods(['toHtml'])
            ->getMock();
        $panel->method('toHtml')->willReturn('<div>panel</div>');
        $element = $this->createMock(AbstractElement::class);
        $element->method('getHtmlId')->willReturn('carriers_skydropx_connection_panel');

        $this->assertSame(
            '<tr id="row_carriers_skydropx_connection_panel"><td colspan="5"><div>panel</div></td></tr>',
            $panel->render($element)
        );
    }

    private function connected(bool $connected, ?string $mode): void
    {
        $this->config->method('isConnected')->willReturn($connected);
        $this->config->method('getConnectionMode')->willReturn($mode);
        $this->config->method('getShopId')->willReturn($connected ? self::SHOP_ID : null);
        $this->config->method('getConnectedStoreUrl')->willReturn($connected ? 'https://shop.example.com/' : null);
        $this->config->method('getConnectedAt')->willReturn($connected ? '2026-11-11T10:00:00Z' : null);
    }

    /**
     * @return ConnectionPanel&MockObject
     */
    private function panel(): ConnectionPanel
    {
        $panel = $this->getMockBuilder(ConnectionPanel::class)
            ->setConstructorArgs($this->constructorArgs())
            ->onlyMethods(['now'])
            ->getMock();
        $panel->method('now')->willReturn(self::NOW);

        return $panel;
    }

    private function constructorArgs(): array
    {
        $escaper = $this->escaper();
        $urlBuilder = $this->createMock(UrlInterface::class);
        $urlBuilder->method('getUrl')->willReturnCallback(
            fn ($route) => 'https://admin.example.com/' . $route . '/'
        );
        $formKey = $this->createMock(FormKey::class);
        $formKey->method('getFormKey')->willReturn('FK123');

        $context = $this->createMock(Context::class);
        $context->method('getEscaper')->willReturn($escaper);
        $context->method('getUrlBuilder')->willReturn($urlBuilder);
        $context->method('getFormKey')->willReturn($formKey);

        $reader = $this->createMock(Reader::class);
        $reader->method('getModuleDir')->with('view', 'Skydropx_Shipping')->willReturn(self::MODULE_VIEW_DIR);

        $connectUrlBuilder = $this->createMock(ConnectUrlBuilder::class);
        $connectUrlBuilder->method('storesUrl')->willReturn('https://pro.example.com/merchant_stores');

        return [
            $context,
            $this->config,
            $this->resolver,
            $this->provisioner,
            $connectUrlBuilder,
            $this->healthRecorder,
            $this->outbox,
            $reader,
            $this->file,
            [],
            $this->secureRenderer(),
        ];
    }

    /**
     * Render the phtml the way Magento's PHP engine does: $block, $escaper and $secureRenderer in scope.
     *
     * @param ConnectionPanel $block
     * @return string
     */
    private function html(ConnectionPanel $block): string
    {
        $escaper = $this->escaper();
        $secureRenderer = $this->secureRenderer();
        $template = dirname(__DIR__, 6) . '/view/adminhtml/templates/system/config/connection-panel.phtml';

        ob_start();
        try {
            include $template;
        } finally {
            $html = (string) ob_get_clean();
        }

        return $html;
    }

    /**
     * @return Escaper&MockObject
     */
    private function escaper(): Escaper
    {
        $html = static fn ($value): string => htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $escaper = $this->createMock(Escaper::class);
        $escaper->method('escapeHtml')->willReturnCallback($html);
        $escaper->method('escapeHtmlAttr')->willReturnCallback($html);
        $escaper->method('escapeUrl')->willReturnCallback($html);
        $escaper->method('escapeJs')->willReturnCallback(static fn ($value): string => addcslashes((string) $value, "'\\\n\r"));

        return $escaper;
    }

    /**
     * @return SecureHtmlRenderer&MockObject
     */
    private function secureRenderer(): SecureHtmlRenderer
    {
        $renderer = $this->createMock(SecureHtmlRenderer::class);
        $renderer->method('renderTag')->willReturnCallback(
            static fn (string $tag, array $attributes, ?string $content = null): string => "<$tag nonce>$content</$tag>"
        );

        return $renderer;
    }
}
