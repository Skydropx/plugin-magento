<?php
declare(strict_types=1);

namespace Skydropx\Shipping\Test\Unit\Model;

use Magento\Framework\App\Config\ScopeConfigInterface;
use PHPUnit\Framework\TestCase;
use Skydropx\Shipping\Model\Config;

class ConfigTest extends TestCase
{
    private const UUID = '0b6f6a9e-3c1d-4f2a-9b8e-7d6c5b4a3f21';

    /**
     * @var array<string, mixed>
     */
    private array $values = [];

    protected function setUp(): void
    {
        $this->values = [];
    }

    public function testShopIdIsLowercased(): void
    {
        $this->values['carriers/skydropx/shop_id'] = '  ' . strtoupper(self::UUID) . ' ';

        $this->assertSame(self::UUID, $this->config()->getShopId());
    }

    /**
     * @dataProvider invalidShopIds
     */
    public function testInvalidShopIdIsNull($value): void
    {
        $this->values['carriers/skydropx/shop_id'] = $value;

        $this->assertNull($this->config()->getShopId());
    }

    public static function invalidShopIds(): array
    {
        return [
            'null' => [null],
            'empty' => [''],
            'not a uuid' => ['shop-123'],
            'no dashes' => ['0b6f6a9e3c1d4f2a9b8e7d6c5b4a3f21'],
            'non hex' => ['zb6f6a9e-3c1d-4f2a-9b8e-7d6c5b4a3f21'],
        ];
    }

    public function testSigningSecretIsReturnedAsLoadedByMagento(): void
    {
        // MetadataProcessor already decrypted it (Encrypted backend_model in config.xml).
        $this->values['carriers/skydropx/signing_secret'] = ' e2e-plain-secret ';

        $this->assertSame('e2e-plain-secret', $this->config()->getSigningSecret());
    }

    /**
     * @dataProvider emptySecrets
     */
    public function testEmptySigningSecretIsNull($value): void
    {
        $this->values['carriers/skydropx/signing_secret'] = $value;

        $this->assertNull($this->config()->getSigningSecret());
    }

    public static function emptySecrets(): array
    {
        return [
            'null' => [null],
            'empty' => [''],
            'spaces' => ['   '],
        ];
    }

    public function testIsConnectedWithUuidSecretAndBuild(): void
    {
        $this->values['carriers/skydropx/shop_id'] = self::UUID;
        $this->values['carriers/skydropx/signing_secret'] = 'secret';

        $this->assertTrue($this->config(true)->isConnected());
    }

    /**
     * @dataProvider disconnectedCases
     */
    public function testIsNotConnected(?string $shopId, ?string $secret, bool $built): void
    {
        $this->values['carriers/skydropx/shop_id'] = $shopId;
        $this->values['carriers/skydropx/signing_secret'] = $secret;

        $this->assertFalse($this->config($built)->isConnected());
    }

    public static function disconnectedCases(): array
    {
        return [
            'no shop id' => [null, 'secret', true],
            'invalid shop id' => ['shop-123', 'secret', true],
            'no secret' => [self::UUID, null, true],
            'blank secret' => [self::UUID, '  ', true],
            'unbuilt package' => [self::UUID, 'secret', false],
        ];
    }

    /**
     * @dataProvider connectionModes
     */
    public function testConnectionMode(?string $stored, ?string $expected): void
    {
        $this->values['carriers/skydropx/connection_mode'] = $stored;

        $this->assertSame($expected, $this->config()->getConnectionMode());
    }

    public static function connectionModes(): array
    {
        return [
            'extension' => ['extension', 'extension'],
            'manual' => ['manual', 'manual'],
            'empty' => ['', null],
            'unknown' => ['api', null],
        ];
    }

    public function testIntegrationId(): void
    {
        $this->values['carriers/skydropx/integration_id'] = '17';
        $this->assertSame(17, $this->config()->getIntegrationId());

        $this->values['carriers/skydropx/integration_id'] = '';
        $this->assertNull($this->config()->getIntegrationId());

        $this->values['carriers/skydropx/integration_id'] = '0';
        $this->assertNull($this->config()->getIntegrationId());
    }

    public function testConnectionDetails(): void
    {
        $this->values['carriers/skydropx/connected_store_url'] = 'https://shop.example.com';
        $this->values['carriers/skydropx/connected_at'] = '2026-11-11T10:00:00Z';
        $config = $this->config();

        $this->assertSame('https://shop.example.com', $config->getConnectedStoreUrl());
        $this->assertSame('2026-11-11T10:00:00Z', $config->getConnectedAt());

        $this->values = [];
        $this->assertNull($config->getConnectedStoreUrl());
        $this->assertNull($config->getConnectedAt());
    }

    public function testFlags(): void
    {
        $this->values['carriers/skydropx/order_push'] = '1';
        $this->values['carriers/skydropx/debug'] = '0';
        $this->values['carriers/skydropx/active'] = '1';
        $this->values['carriers/skydropx/title'] = 'Shipping';
        $config = $this->config();

        $this->assertTrue($config->isOrderPushEnabled());
        $this->assertFalse($config->isDebug());
        $this->assertTrue($config->isActive(1));
        $this->assertSame('Shipping', $config->getTitle(1));
    }

    public function testDimensionAttributes(): void
    {
        $this->values['carriers/skydropx/length_attribute'] = 'ts_dimensions_length';
        $this->values['carriers/skydropx/width_attribute'] = '';

        $this->assertSame(
            ['length' => 'ts_dimensions_length', 'width' => null, 'height' => null],
            $this->config()->getDimensionAttributes()
        );
    }

    public function testRatesTimeoutAndTtlFallBackToDefaults(): void
    {
        $this->assertSame(4, $this->config()->getRatesTimeout());
        $this->assertSame(300, $this->config()->getRatesCacheTtl());

        $this->values['carriers/skydropx/rates_timeout'] = '6';
        $this->values['carriers/skydropx/rates_cache_ttl'] = 'abc';
        $this->assertSame(6, $this->config()->getRatesTimeout());
        $this->assertSame(300, $this->config()->getRatesCacheTtl());
    }

    /**
     * Config whose Build seam returns $built (OD-3).
     */
    private function config(bool $built = true): Config
    {
        $scopeConfig = $this->createMock(ScopeConfigInterface::class);
        $scopeConfig->method('getValue')->willReturnCallback(
            fn ($path) => $this->values[$path] ?? null
        );

        $config = $this->getMockBuilder(Config::class)
            ->setConstructorArgs([$scopeConfig])
            ->onlyMethods(['isBuilt'])
            ->getMock();
        $config->method('isBuilt')->willReturn($built);

        return $config;
    }
}
