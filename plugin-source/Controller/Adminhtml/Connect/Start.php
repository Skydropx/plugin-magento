<?php
/**
 * One-click connection, step 1: preflight and redirect to labels-light (contract §4.1).
 */
declare(strict_types=1);

namespace Skydropx\Shipping\Controller\Adminhtml\Connect;

use Magento\Backend\App\Action;
use Magento\Backend\App\Action\Context;
use Magento\Framework\App\Action\HttpPostActionInterface;
use Magento\Framework\Controller\ResultInterface;
use Skydropx\Shipping\Logger\Logger;
use Skydropx\Shipping\Model\Connect\ConnectException;
use Skydropx\Shipping\Model\Connect\ConnectUrlBuilder;
use Skydropx\Shipping\Model\Connect\IntegrationProvisioner;
use Skydropx\Shipping\Model\Connect\StateManager;
use Skydropx\Shipping\Model\Connect\StoreUrlResolver;

/**
 * POST with form key (checked by Magento's backend validator). Never throws: errors become a message.
 */
class Start extends Action implements HttpPostActionInterface
{
    public const ADMIN_RESOURCE = 'Skydropx_Shipping::connect';

    /**
     * @param Context $context
     * @param StoreUrlResolver $storeUrlResolver
     * @param IntegrationProvisioner $provisioner
     * @param StateManager $stateManager
     * @param ConnectUrlBuilder $connectUrlBuilder
     * @param Logger $logger
     */
    public function __construct(
        Context $context,
        private StoreUrlResolver $storeUrlResolver,
        private IntegrationProvisioner $provisioner,
        private StateManager $stateManager,
        private ConnectUrlBuilder $connectUrlBuilder,
        private Logger $logger
    ) {
        parent::__construct($context);
    }

    /**
     * Run the preflight of HU §6.4.1 and send the browser to labels-light.
     *
     * @return ResultInterface
     */
    public function execute()
    {
        try {
            $urls = $this->storeUrlResolver->resolve();
            $adminUserId = $this->adminUserId();
            if (!$this->provisioner->isBearerEnabled()) {
                if ((string) $this->getRequest()->getParam('enable_bearer') !== '1') {
                    throw new ConnectException(ConnectException::BEARER_REQUIRED);
                }
                $this->provisioner->enableBearer();
                $this->logger->warning('BEARER_ENABLED', ['admin_user_id' => $adminUserId]);
            }
            $state = $this->stateManager->newState();
            $this->stateManager->start($state, $urls['store_url'], $urls['callback_url'], $adminUserId);

            return $this->resultRedirectFactory->create()->setUrl(
                $this->connectUrlBuilder->connectUrl($urls['store_url'], $state, $urls['callback_url'])
            );
        } catch (ConnectException $e) {
            $this->messageManager->addErrorMessage((string) $e->getMerchantMessage());
        } catch (\Throwable $e) {
            $this->logger->error('CONNECT_FAILED', ['reason' => 'start_error', 'detail' => $e->getMessage()]);
            $this->messageManager->addErrorMessage((string) __('The connection could not be started. Try again.'));
        }

        return $this->resultRedirectFactory->create()
            ->setPath('adminhtml/system_config/edit', ['section' => 'carriers']);
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
