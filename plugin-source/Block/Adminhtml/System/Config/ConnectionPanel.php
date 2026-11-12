<?php
/**
 * Connection panel at the top of the carrier settings (HU §6.4.6).
 */
declare(strict_types=1);

namespace Skydropx\Shipping\Block\Adminhtml\System\Config;

use Magento\Backend\Block\Template\Context;
use Magento\Config\Block\System\Config\Form\Field;
use Magento\Framework\Data\Form\Element\AbstractElement;
use Magento\Framework\Filesystem\Driver\File;
use Magento\Framework\Module\Dir;
use Magento\Framework\Module\Dir\Reader;
use Magento\Framework\View\Helper\SecureHtmlRenderer;
use Skydropx\Shipping\Model\Build;
use Skydropx\Shipping\Model\Config;
use Skydropx\Shipping\Model\Connect\ConnectException;
use Skydropx\Shipping\Model\Connect\ConnectUrlBuilder;
use Skydropx\Shipping\Model\Connect\IntegrationProvisioner;
use Skydropx\Shipping\Model\Connect\StoreUrlResolver;
use Skydropx\Shipping\Model\OrderPush\OutboxRepository;
use Skydropx\Shipping\Model\Rates\HealthRecorder;

/**
 * Rendered inside Magento's config form: the template has no <form>; its buttons post a form built by JS (OD-7).
 * The signing secret is never read here.
 */
class ConnectionPanel extends Field
{
    public const STATE_EXTENSION = 'extension';
    public const STATE_MANUAL = 'manual';
    public const STATE_DISCONNECTED = 'disconnected';

    /**
     * Pending orders older than this point to a stopped Magento cron.
     */
    public const PUSH_STALE_AFTER = 900;

    private const MODULE_NAME = 'Skydropx_Shipping';
    private const LOGO_PATH = '/adminhtml/web/images/logo.png';

    /**
     * @var string
     */
    protected $_template = 'Skydropx_Shipping::system/config/connection-panel.phtml';

    /**
     * @var string|false|null Cached logo; false = no logo.
     */
    private $logo = null;

    /**
     * @var string|false|null Cached one-click blocker; false = available.
     */
    private $oneClickBlocker = null;

    /**
     * @param Context $context
     * @param Config $config
     * @param StoreUrlResolver $storeUrlResolver
     * @param IntegrationProvisioner $provisioner
     * @param ConnectUrlBuilder $connectUrlBuilder
     * @param HealthRecorder $healthRecorder
     * @param OutboxRepository $outboxRepository
     * @param Reader $moduleReader
     * @param File $fileDriver
     * @param array $data
     * @param SecureHtmlRenderer|null $secureRenderer
     */
    public function __construct(
        Context $context,
        private Config $config,
        private StoreUrlResolver $storeUrlResolver,
        private IntegrationProvisioner $provisioner,
        private ConnectUrlBuilder $connectUrlBuilder,
        private HealthRecorder $healthRecorder,
        private OutboxRepository $outboxRepository,
        private Reader $moduleReader,
        private File $fileDriver,
        array $data = [],
        ?SecureHtmlRenderer $secureRenderer = null
    ) {
        parent::__construct($context, $data, $secureRenderer);
    }

    /**
     * Render the panel across the whole row, without label or scope columns.
     *
     * @param AbstractElement $element
     * @return string
     */
    public function render(AbstractElement $element)
    {
        $htmlId = $this->_escaper->escapeHtmlAttr((string) $element->getHtmlId());

        return '<tr id="row_' . $htmlId . '"><td colspan="5">' . $this->toHtml() . '</td></tr>';
    }

    /**
     * Brand name baked by the build.
     *
     * @return string
     */
    public function getBrandName(): string
    {
        return Build::NAME;
    }

    /**
     * One of the STATE_* constants.
     *
     * @return string
     */
    public function getConnectionState(): string
    {
        if (!$this->config->isConnected()) {
            return self::STATE_DISCONNECTED;
        }

        return $this->config->getConnectionMode() === Config::MODE_EXTENSION
            ? self::STATE_EXTENSION
            : self::STATE_MANUAL;
    }

    /**
     * Whether the store is connected in either mode.
     *
     * @return bool
     */
    public function isConnected(): bool
    {
        return $this->getConnectionState() !== self::STATE_DISCONNECTED;
    }

    /**
     * Human label of the connection state.
     *
     * @return string
     */
    public function getStateLabel(): string
    {
        switch ($this->getConnectionState()) {
            case self::STATE_EXTENSION:
                return (string) __('Connected with the extension');
            case self::STATE_MANUAL:
                return (string) __('Connected manually');
            default:
                return (string) __('Not connected');
        }
    }

    /**
     * Connection details (no secret).
     *
     * @return array{shop_id: ?string, store_url: ?string, connected_at: ?string}
     */
    public function getConnectionDetails(): array
    {
        return [
            'shop_id' => $this->config->getShopId(),
            'store_url' => $this->config->getConnectedStoreUrl(),
            'connected_at' => $this->config->getConnectedAt(),
        ];
    }

    /**
     * "Package: <version> · <environment> · <eco URL>", so support spots a sandbox build on a live store.
     *
     * @return string
     */
    public function getPackageInfo(): string
    {
        return (string) __('Package: %1 · %2 · %3', Build::VERSION, Build::ENVIRONMENT, Build::ECOMMERCE_BASE);
    }

    /**
     * Whether the carrier is enabled at checkout.
     *
     * @return bool
     */
    public function isCarrierActive(): bool
    {
        return $this->config->isActive();
    }

    /**
     * Last rates result as text, or null when there is none yet.
     *
     * @return string|null
     */
    public function getRatesHealth(): ?string
    {
        try {
            $last = $this->healthRecorder->getLast();
        } catch (\Throwable $e) {
            return null;
        }
        if ($last === null) {
            return null;
        }
        $at = $last['at'];
        switch ($last['status']) {
            case HealthRecorder::STATUS_OK:
                return (string) __('Last rates request (%1): rates returned.', $at);
            case HealthRecorder::STATUS_EMPTY:
                return (string) __('Last rates request (%1): no rates for that cart.', $at);
            case HealthRecorder::STATUS_UNAUTHORIZED:
                return (string) __('Last rates request (%1): signing key rejected (HTTP 401).', $at);
            case HealthRecorder::STATUS_TIMEOUT:
                return (string) __('Last rates request (%1): no answer in time.', $at);
            default:
                return (string) __('Last rates request (%1): failed (HTTP %2).', $at, $last['http_status'] ?? 0);
        }
    }

    /**
     * Order push statistics, or null when they cannot be read.
     *
     * @return array{pending: int, failed: int, oldest_pending_at: ?string, last_sent_at: ?string}|null
     */
    public function getPushStats(): ?array
    {
        try {
            return $this->outboxRepository->getStats();
        } catch (\Throwable $e) {
            return null;
        }
    }

    /**
     * Whether the oldest pending order waits for more than 15 minutes.
     *
     * @param array|null $stats Result of getPushStats().
     * @return bool
     */
    public function isPushStale(?array $stats): bool
    {
        $oldest = $stats['oldest_pending_at'] ?? null;
        if (!is_string($oldest) || $oldest === '') {
            return false;
        }
        $time = strtotime($oldest . ' UTC');

        return $time !== false && $this->now() - $time > self::PUSH_STALE_AFTER;
    }

    /**
     * Why the one-click connection cannot run here, or null when it can (D4, unbuilt package, bad store URL).
     *
     * @return string|null
     */
    public function getOneClickBlocker(): ?string
    {
        if ($this->oneClickBlocker === null) {
            try {
                $this->storeUrlResolver->resolve();
                $this->oneClickBlocker = false;
            } catch (ConnectException $e) {
                $this->oneClickBlocker = (string) $e->getMerchantMessage();
            } catch (\Throwable $e) {
                $this->oneClickBlocker = (string) __('The one-click connection is not available on this store.');
            }
        }

        return $this->oneClickBlocker === false ? null : $this->oneClickBlocker;
    }

    /**
     * Whether the Bearer consent checkbox must be shown (D6).
     *
     * @return bool
     */
    public function isBearerConsentNeeded(): bool
    {
        return $this->getOneClickBlocker() === null && !$this->provisioner->isBearerEnabled();
    }

    /**
     * Module logo as a data URI (works without static content deploy), or null when the build has none.
     *
     * @return string|null
     */
    public function getLogoDataUri(): ?string
    {
        if ($this->logo === null) {
            $this->logo = false;
            try {
                $path = $this->moduleReader->getModuleDir(Dir::MODULE_VIEW_DIR, self::MODULE_NAME) . self::LOGO_PATH;
                if ($this->fileDriver->isExists($path)) {
                    $content = (string) $this->fileDriver->fileGetContents($path);
                    if ($content !== '') {
                        $this->logo = 'data:image/png;base64,' . base64_encode($content);
                    }
                }
            } catch (\Throwable $e) {
                $this->logo = false;
            }
        }

        return $this->logo === false ? null : $this->logo;
    }

    /**
     * Action URL of the connect controller.
     *
     * @return string
     */
    public function getStartUrl(): string
    {
        return $this->getUrl('skydropx/connect/start');
    }

    /**
     * Action URL of the test controller.
     *
     * @return string
     */
    public function getTestUrl(): string
    {
        return $this->getUrl('skydropx/connect/test');
    }

    /**
     * Action URL of the disconnect controller.
     *
     * @return string
     */
    public function getDisconnectUrl(): string
    {
        return $this->getUrl('skydropx/connect/disconnect');
    }

    /**
     * "My stores" in labels-light.
     *
     * @return string
     */
    public function getStoresUrl(): string
    {
        return $this->connectUrlBuilder->storesUrl();
    }

    /**
     * Clock seam for tests.
     *
     * @return int
     */
    protected function now(): int
    {
        return time();
    }
}
