<?php
/**
 * One-click connection, step 2: labels-light sends the browser back with the code (contract §4.3-§4.5).
 */
declare(strict_types=1);

namespace Skydropx\Shipping\Controller\Adminhtml\Connect;

use Magento\Backend\App\Action;
use Magento\Backend\App\Action\Context;
use Magento\Framework\App\Action\HttpGetActionInterface;
use Magento\Framework\Controller\ResultInterface;
use Skydropx\Shipping\Logger\Logger;
use Skydropx\Shipping\Model\Config;
use Skydropx\Shipping\Model\Connect\ConnectException;
use Skydropx\Shipping\Model\Connect\ConnectUrlBuilder;
use Skydropx\Shipping\Model\Connect\Exchanger;
use Skydropx\Shipping\Model\Connect\IntegrationProvisioner;
use Skydropx\Shipping\Model\Connect\StateManager;
use Skydropx\Shipping\Model\ConnectionStore;

/**
 * GET with the admin secret key, ACL and the one-time state. The new integration replaces the previous one
 * only after a successful exchange (D5). Never logs the code, the state, the token or the secret.
 */
class Callback extends Action implements HttpGetActionInterface
{
    public const ADMIN_RESOURCE = 'Skydropx_Shipping::connect';

    /**
     * eco mints base64url codes of 32 bytes.
     */
    private const CODE_PATTERN = '/^[A-Za-z0-9_-]{16,128}$/';

    /**
     * @param Context $context
     * @param StateManager $stateManager
     * @param IntegrationProvisioner $provisioner
     * @param Exchanger $exchanger
     * @param ConnectionStore $connectionStore
     * @param Config $config
     * @param ConnectUrlBuilder $connectUrlBuilder
     * @param Logger $logger
     */
    public function __construct(
        Context $context,
        private StateManager $stateManager,
        private IntegrationProvisioner $provisioner,
        private Exchanger $exchanger,
        private ConnectionStore $connectionStore,
        private Config $config,
        private ConnectUrlBuilder $connectUrlBuilder,
        private Logger $logger
    ) {
        parent::__construct($context);
    }

    /**
     * Validate, create the token, exchange, save and send the browser to labels-light (HU §6.4.2).
     *
     * @return ResultInterface
     */
    public function execute()
    {
        $newIntegrationId = 0;
        $exchanged = false;
        try {
            $state = $this->param('state');
            $code = $this->param('code');
            $attempt = $this->stateManager->consume($state, $this->adminUserId());
            if ($attempt === null) {
                throw new ConnectException(ConnectException::STATE_INVALID);
            }
            if (preg_match(self::CODE_PATTERN, $code) !== 1) {
                throw new ConnectException(ConnectException::CODE_INVALID);
            }

            // Read it now: ConnectionStore writes straight to the database and this request keeps the old config.
            $previousIntegrationId = $this->config->getIntegrationId();

            $provisioned = $this->provisioner->provision();
            $newIntegrationId = $provisioned['integrationId'];
            $this->provisioner->addPending($newIntegrationId);

            $result = $this->exchanger->exchange($code, $state, $attempt['store_url'], $provisioned['accessToken']);
            $exchanged = true;

            $this->connectionStore->saveExtensionConnection(
                $result['shopId'],
                $result['signingSecret'],
                $newIntegrationId,
                $attempt['store_url']
            );
            if ($previousIntegrationId !== null && $previousIntegrationId !== $newIntegrationId) {
                $this->provisioner->delete($previousIntegrationId);
            }
            $this->provisioner->removePending($newIntegrationId);
            $this->logger->info('CONNECT_SUCCESS', ['shop_id' => $result['shopId']]);

            return $this->resultRedirectFactory->create()->setUrl($this->connectUrlBuilder->completeUrl($code));
        } catch (ConnectException $e) {
            $this->discard($newIntegrationId);
            $this->logger->warning('CONNECT_FAILED', [
                'reason' => $e->getReason(),
                'status' => $e->getHttpStatus(),
                'detail' => $e->getDetail(),
            ]);
            $this->messageManager->addErrorMessage((string) $e->getMerchantMessage());
        } catch (\Throwable $e) {
            // After a successful exchange eco already holds the new token: keep it pending for the cron.
            if (!$exchanged) {
                $this->discard($newIntegrationId);
            }
            $this->logger->error('CONNECT_FAILED', ['reason' => 'callback_error', 'detail' => $e->getMessage()]);
            $this->messageManager->addErrorMessage((string) __('The connection could not be completed. Try again.'));
        }

        return $this->resultRedirectFactory->create()
            ->setPath('adminhtml/system_config/edit', ['section' => 'carriers']);
    }

    /**
     * Remove the integration created for a failed exchange.
     *
     * @param int $integrationId
     * @return void
     */
    private function discard(int $integrationId): void
    {
        if ($integrationId <= 0) {
            return;
        }
        try {
            $this->provisioner->delete($integrationId);
            $this->provisioner->removePending($integrationId);
        } catch (\Throwable $e) {
            // Left in the pending list: the hourly cron removes it.
            $this->logger->error('CONNECT_FAILED', ['reason' => 'cleanup_error', 'detail' => $e->getMessage()]);
        }
    }

    /**
     * Query parameter as a string.
     *
     * @param string $name
     * @return string
     */
    private function param(string $name): string
    {
        $value = $this->getRequest()->getParam($name);

        return is_string($value) ? $value : '';
    }

    /**
     * Id of the signed-in admin user.
     *
     * @return int
     */
    private function adminUserId(): int
    {
        $user = $this->_auth->getUser();

        return $user ? (int) $user->getId() : 0;
    }
}
