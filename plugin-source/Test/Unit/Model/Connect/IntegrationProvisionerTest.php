<?php
declare(strict_types=1);

namespace Skydropx\Shipping\Test\Unit\Model\Connect;

use Magento\Framework\Acl\AclResource\ProviderInterface;
use Magento\Framework\App\Cache\TypeListInterface;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\App\Config\Storage\WriterInterface;
use Magento\Framework\DataObject;
use Magento\Framework\Exception\IntegrationException;
use Magento\Framework\FlagManager;
use Magento\Framework\Phrase;
use Magento\Integration\Api\IntegrationServiceInterface;
use Magento\Integration\Api\OauthServiceInterface;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Skydropx\Shipping\Model\Connect\ConnectException;
use Skydropx\Shipping\Model\Connect\IntegrationProvisioner;

class IntegrationProvisionerTest extends TestCase
{
    private const ALL_RESOURCES = [
        'Magento_Sales::sales',
        'Magento_Sales::sales_operation',
        'Magento_Sales::sales_order',
        'Magento_Sales::actions',
        'Magento_Sales::actions_view',
        'Magento_Sales::comment',
        'Magento_Sales::ship',
        'Magento_Sales::shipment',
        'Magento_Backend::stores',
        'Magento_Backend::stores_settings',
        'Magento_Backend::store',
        'Magento_InventoryApi::inventory',
        'Magento_InventoryApi::source',
    ];

    /**
     * @var IntegrationServiceInterface&MockObject
     */
    private $integrationService;

    /**
     * @var OauthServiceInterface&MockObject
     */
    private $oauthService;

    /**
     * @var ProviderInterface&MockObject
     */
    private $aclProvider;

    /**
     * @var ScopeConfigInterface&MockObject
     */
    private $scopeConfig;

    /**
     * @var WriterInterface&MockObject
     */
    private $writer;

    /**
     * @var TypeListInterface&MockObject
     */
    private $typeList;

    /**
     * @var FlagManager&MockObject
     */
    private $flagManager;

    /**
     * @var mixed
     */
    private $flag = null;

    protected function setUp(): void
    {
        $this->integrationService = $this->createMock(IntegrationServiceInterface::class);
        $this->oauthService = $this->createMock(OauthServiceInterface::class);
        $this->aclProvider = $this->createMock(ProviderInterface::class);
        $this->aclProvider->method('getAclResources')->willReturn($this->aclTree(true));
        $this->scopeConfig = $this->createMock(ScopeConfigInterface::class);
        $this->writer = $this->createMock(WriterInterface::class);
        $this->typeList = $this->createMock(TypeListInterface::class);
        $this->flagManager = $this->createMock(FlagManager::class);
        $this->flagManager->method('getFlagData')->willReturnCallback(fn () => $this->flag);
        $this->flagManager->method('saveFlag')->willReturnCallback(function ($code, $value) {
            $this->assertSame('skydropx_pending_integrations', $code);
            $this->flag = $value;

            return true;
        });
        $this->flagManager->method('deleteFlag')->willReturnCallback(function ($code) {
            $this->assertSame('skydropx_pending_integrations', $code);
            $this->flag = null;

            return true;
        });
    }

    public function testResourcesAreTheExactListInOrder(): void
    {
        $this->assertSame(self::ALL_RESOURCES, IntegrationProvisioner::RESOURCES);
        $this->assertSame(self::ALL_RESOURCES, $this->provisioner()->resources());
        $this->assertNotContains('Magento_Catalog::catalog', $this->provisioner()->resources());
    }

    public function testResourcesWithoutMsiDropInventory(): void
    {
        $aclProvider = $this->createMock(ProviderInterface::class);
        $aclProvider->method('getAclResources')->willReturn($this->aclTree(false));
        $this->aclProvider = $aclProvider;

        $this->assertSame(array_slice(self::ALL_RESOURCES, 0, 11), $this->provisioner()->resources());
    }

    public function testProvisionCreatesTheIntegrationAndItsToken(): void
    {
        $this->integrationService->expects($this->once())->method('create')->willReturnCallback(
            function (array $data) {
                $this->assertMatchesRegularExpression(
                    '/^PLUGIN_NAME · \d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/',
                    $data['name']
                );
                $this->assertSame(1, $data['status']);
                $this->assertSame(0, $data['setup_type']);
                $this->assertSame(self::ALL_RESOURCES, $data['resource']);

                return new DataObject(['id' => 42, 'consumer_id' => 9]);
            }
        );
        $this->oauthService->expects($this->once())->method('createAccessToken')->with(9, true)->willReturn(true);
        $this->oauthService->expects($this->once())->method('getAccessToken')->with(9)
            ->willReturn(new DataObject(['token' => 'access-token']));
        $this->integrationService->expects($this->never())->method('delete');

        $this->assertSame(
            ['integrationId' => 42, 'accessToken' => 'access-token'],
            $this->provisioner()->provision()
        );
    }

    public function testProvisionWithoutTokenDeletesTheIntegration(): void
    {
        $this->integrationService->method('create')->willReturn(new DataObject(['id' => 42, 'consumer_id' => 9]));
        $this->oauthService->method('getAccessToken')->willReturn(false);
        $this->integrationService->expects($this->once())->method('delete')->with(42);

        $this->expectException(ConnectException::class);
        $this->expectExceptionMessage(ConnectException::INTEGRATION_FAILED);
        $this->provisioner()->provision();
    }

    public function testProvisionWhenCreateFails(): void
    {
        $this->integrationService->method('create')->willThrowException(new \RuntimeException('Duplicate name'));
        $this->integrationService->expects($this->never())->method('delete');

        $this->expectException(ConnectException::class);
        $this->expectExceptionMessage(ConnectException::INTEGRATION_FAILED);
        $this->provisioner()->provision();
    }

    public function testDeleteToleratesAMissingIntegration(): void
    {
        $this->integrationService->expects($this->once())->method('delete')->with(42)
            ->willThrowException(new IntegrationException(new Phrase('The integration with ID "42" doesn\'t exist.')));

        $this->provisioner()->delete(42);
    }

    public function testDeleteIgnoresInvalidIds(): void
    {
        $this->integrationService->expects($this->never())->method('delete');

        $this->provisioner()->delete(0);
    }

    /**
     * @dataProvider bearerProvider
     */
    public function testIsBearerEnabled(bool $flag): void
    {
        $this->scopeConfig->expects($this->once())->method('isSetFlag')
            ->with('oauth/consumer/enable_integration_as_bearer', 'default')
            ->willReturn($flag);

        $this->assertSame($flag, $this->provisioner()->isBearerEnabled());
    }

    public static function bearerProvider(): array
    {
        return [[true], [false]];
    }

    public function testEnableBearerWritesTheDefaultScopeAndCleansConfigCache(): void
    {
        $this->writer->expects($this->once())->method('save')
            ->with('oauth/consumer/enable_integration_as_bearer', '1', 'default', 0);
        $this->typeList->expects($this->once())->method('cleanType')->with('config');

        $this->provisioner()->enableBearer();
    }

    public function testPendingListKeepsIdAndDate(): void
    {
        $provisioner = $this->provisioner();
        $provisioner->addPending(42);
        $provisioner->addPending(43);

        $pending = $provisioner->getPending();
        $this->assertSame([42, 43], array_column($pending, 'id'));
        $this->assertEqualsWithDelta(time(), $pending[0]['at'], 5);

        $provisioner->removePending(42);
        $this->assertSame([43], array_column($provisioner->getPending(), 'id'));

        $provisioner->removePending(43);
        $this->assertSame([], $provisioner->getPending());
        $this->assertNull($this->flag, 'An empty list removes the flag.');
    }

    public function testPendingListIgnoresGarbage(): void
    {
        $this->flag = ['x', ['id' => 0], ['id' => '7', 'at' => '100']];

        $this->assertSame([['id' => 7, 'at' => 100]], $this->provisioner()->getPending());
    }

    private function provisioner(): IntegrationProvisioner
    {
        return new IntegrationProvisioner(
            $this->integrationService,
            $this->oauthService,
            $this->aclProvider,
            $this->scopeConfig,
            $this->writer,
            $this->typeList,
            $this->flagManager
        );
    }

    /**
     * ACL tree as Magento\Framework\Acl\AclResource\Provider returns it (2.4.8 ids).
     *
     * @param bool $withMsi
     * @return array
     */
    private function aclTree(bool $withMsi): array
    {
        $node = static fn (string $id, array $children = []): array => [
            'id' => $id,
            'title' => $id,
            'sortOrder' => 0,
            'disabled' => false,
            'children' => $children,
        ];
        $storesChildren = [
            $node('Magento_Backend::stores_settings', [
                $node('Magento_Backend::store'),
                $node('Magento_Config::config', [$node('Skydropx_Shipping::connect')]),
            ]),
        ];
        if ($withMsi) {
            $storesChildren[] = $node('Magento_InventoryApi::inventory', [$node('Magento_InventoryApi::source')]);
        }

        return [
            $node('Magento_Backend::admin', [
                $node('Magento_Sales::sales', [
                    $node('Magento_Sales::sales_operation', [
                        $node('Magento_Sales::sales_order', [
                            $node('Magento_Sales::actions', [
                                $node('Magento_Sales::create'),
                                $node('Magento_Sales::actions_view'),
                                $node('Magento_Sales::comment'),
                                $node('Magento_Sales::ship'),
                            ]),
                        ]),
                        $node('Magento_Sales::shipment'),
                    ]),
                ]),
                $node('Magento_Catalog::catalog', [$node('Magento_Catalog::products')]),
                $node('Magento_Backend::stores', $storesChildren),
            ]),
        ];
    }
}
