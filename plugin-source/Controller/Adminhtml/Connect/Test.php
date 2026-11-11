<?php
/**
 * "Test connection": one signed rates call with a sample cart (HU §6.4.7).
 */
declare(strict_types=1);

namespace Skydropx\Shipping\Controller\Adminhtml\Connect;

use Magento\Backend\App\Action;
use Magento\Backend\App\Action\Context;
use Magento\Framework\App\Action\HttpPostActionInterface;
use Magento\Framework\Controller\ResultInterface;
use Magento\Store\Model\StoreManagerInterface;
use Skydropx\Shipping\Logger\Logger;
use Skydropx\Shipping\Model\Build;
use Skydropx\Shipping\Model\Carrier\Skydropx;
use Skydropx\Shipping\Model\Config;
use Skydropx\Shipping\Model\Http\EcoClient;
use Skydropx\Shipping\Model\Http\EcoResponse;
use Skydropx\Shipping\Model\Rates\PayloadBuilder;

/**
 * Calls eco directly: no rates cache and no circuit breaker, so it always reflects the current key.
 */
class Test extends Action implements HttpPostActionInterface
{
    public const ADMIN_RESOURCE = 'Skydropx_Shipping::connect';

    /**
     * Seconds of difference with eco's clock that point to a skewed server clock (OD-14).
     */
    private const CLOCK_SKEW = 120;

    /**
     * @param Context $context
     * @param Config $config
     * @param EcoClient $ecoClient
     * @param PayloadBuilder $payloadBuilder
     * @param StoreManagerInterface $storeManager
     * @param Logger $logger
     */
    public function __construct(
        Context $context,
        private Config $config,
        private EcoClient $ecoClient,
        private PayloadBuilder $payloadBuilder,
        private StoreManagerInterface $storeManager,
        private Logger $logger
    ) {
        parent::__construct($context);
    }

    /**
     * Send the sample and report 200 / 401 / no answer.
     *
     * @return ResultInterface
     */
    public function execute()
    {
        try {
            $shopId = $this->config->getShopId();
            if (!$this->config->isConnected() || $shopId === null) {
                $this->messageManager->addErrorMessage(
                    (string) __('The store is not connected: there is no valid store ID and signing key.')
                );
            } else {
                $this->report($this->send($shopId));
            }
        } catch (\Throwable $e) {
            $this->logger->error('RATES_FAILED', ['source' => 'connection_test', 'detail' => $e->getMessage()]);
            $this->messageManager->addErrorMessage((string) __('No response from %1.', Build::NAME));
        }

        return $this->resultRedirectFactory->create()
            ->setPath('adminhtml/system_config/edit', ['section' => 'carriers']);
    }

    /**
     * Signed call with the sample payload of the default store view.
     *
     * @param string $shopId
     * @return EcoResponse
     */
    private function send(string $shopId): EcoResponse
    {
        $store = $this->storeManager->getDefaultStoreView();
        $storeId = $store ? (int) $store->getId() : 0;
        $raw = json_encode($this->payloadBuilder->buildSample($storeId), EcoClient::JSON_FLAGS);

        return $this->ecoClient->postSigned(
            Skydropx::RATES_PATH,
            $raw,
            ['shop_id' => $shopId],
            [],
            $this->config->getRatesTimeout()
        );
    }

    /**
     * Turn the answer into an admin message.
     *
     * @param EcoResponse $response
     * @return void
     */
    private function report(EcoResponse $response): void
    {
        $status = $response->getStatus();
        if ($status === 200) {
            $rates = $response->getBody()['rates'] ?? null;
            $this->messageManager->addSuccessMessage(
                (string) __('Signature accepted: %1 rates.', is_array($rates) ? count($rates) : 0)
            );

            return;
        }
        if ($status === 401) {
            $message = (string) __('The signing key does not match (401).');
            $serverDate = $response->getServerDate();
            if ($serverDate !== null && abs($serverDate - time()) > self::CLOCK_SKEW) {
                $message .= ' ' . __(
                    'The clock of this server differs from %1 by %2 seconds: signatures expire after 5 minutes. '
                    . 'Fix the server time (NTP) and try again.',
                    Build::NAME,
                    abs($serverDate - time())
                );
            }
            $this->logger->warning('RATES_UNAUTHORIZED', ['source' => 'connection_test', 'status' => $status]);
            $this->messageManager->addErrorMessage($message);

            return;
        }
        $this->logger->warning('RATES_FAILED', [
            'source' => 'connection_test',
            'status' => $status,
            'detail' => $response->getErrorDetail(),
        ]);
        $this->messageManager->addErrorMessage(
            $status === 0
                ? (string) __('No response from %1.', Build::NAME)
                : (string) __('No response from %1 (HTTP %2).', Build::NAME, $status)
        );
    }
}
