<?php
/**
 * Disconnect the store in Magento (HU §6.4.7).
 */
declare(strict_types=1);

namespace Skydropx\Shipping\Controller\Adminhtml\Connect;

use Magento\Backend\App\Action;
use Magento\Backend\App\Action\Context;
use Magento\Framework\App\Action\HttpPostActionInterface;
use Magento\Framework\Controller\ResultInterface;
use Skydropx\Shipping\Logger\Logger;
use Skydropx\Shipping\Model\Build;
use Skydropx\Shipping\Model\Config;
use Skydropx\Shipping\Model\Connect\ConnectUrlBuilder;
use Skydropx\Shipping\Model\Connect\IntegrationProvisioner;
use Skydropx\Shipping\Model\ConnectionStore;

/**
 * Removes the integration the module created (whatever the current mode) and the stored connection.
 * The store stays linked in the merchant account until it is removed in My stores.
 */
class Disconnect extends Action implements HttpPostActionInterface
{
    public const ADMIN_RESOURCE = 'Skydropx_Shipping::connect';

    /**
     * @param Context $context
     * @param Config $config
     * @param IntegrationProvisioner $provisioner
     * @param ConnectionStore $connectionStore
     * @param ConnectUrlBuilder $connectUrlBuilder
     * @param Logger $logger
     */
    public function __construct(
        Context $context,
        private Config $config,
        private IntegrationProvisioner $provisioner,
        private ConnectionStore $connectionStore,
        private ConnectUrlBuilder $connectUrlBuilder,
        private Logger $logger
    ) {
        parent::__construct($context);
    }

    /**
     * Delete the integration and the connection settings.
     *
     * @return ResultInterface
     */
    public function execute()
    {
        try {
            $integrationId = $this->config->getIntegrationId();
            // Only saveExtensionConnection() writes integration_id, so it is always one the module created,
            // even after a manual store ID switched the mode to "manual" (OD-15).
            if ($integrationId !== null) {
                $this->provisioner->delete($integrationId);
            }
            $this->connectionStore->clear();
            $this->messageManager->addSuccessMessage((string) __(
                'Disconnected in Magento. To unlink the store from your account, also remove it in My stores of %1: %2',
                Build::NAME,
                $this->connectUrlBuilder->storesUrl()
            ));
        } catch (\Throwable $e) {
            $this->logger->error('CONNECT_FAILED', ['reason' => 'disconnect_error', 'detail' => $e->getMessage()]);
            $this->messageManager->addErrorMessage((string) __('The store could not be disconnected. Try again.'));
        }

        return $this->resultRedirectFactory->create()
            ->setPath('adminhtml/system_config/edit', ['section' => 'carriers']);
    }
}
