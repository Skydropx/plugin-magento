<?php
declare(strict_types=1);

namespace Skydropx\Shipping\Test\Unit\Model\Connect;

use Magento\Backend\Model\UrlInterface as BackendUrlInterface;
use Magento\Store\Model\Store;
use Magento\Store\Model\StoreManagerInterface;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Skydropx\Shipping\Model\Connect\ConnectException;
use Skydropx\Shipping\Model\Connect\StoreUrlResolver;

/**
 * Cases of eco's store-url.unit.spec.ts (1fd5d2f), plus the same-origin rule of labels-light (D4).
 */
class StoreUrlResolverTest extends TestCase
{
    /**
     * @dataProvider acceptedProvider
     */
    public function testAcceptsTheUrlsEcoCanonicalises(string $url, string $origin): void
    {
        $resolver = $this->resolver();

        $this->assertSame(trim($url), $resolver->validateStoreUrl($url));
        $this->assertSame($origin, $resolver->origin($url));
    }

    public static function acceptedProvider(): array
    {
        return [
            ['https://shop.example.com/', 'https://shop.example.com:443'],
            ['https://Shop.Example.com', 'https://shop.example.com:443'],
            ['https://shop.example.com:443/', 'https://shop.example.com:443'],
            ['https://shop.example.com:8443/', 'https://shop.example.com:8443'],
            ['https://shop.example.com/MX/store/', 'https://shop.example.com:443'],
            ['  https://shop.example.com/es  ', 'https://shop.example.com:443'],
        ];
    }

    /**
     * @dataProvider refusedProvider
     */
    public function testRefusesWhatEcoRefuses(string $url): void
    {
        $this->expectInvalidStoreUrl();
        $this->resolver()->validateStoreUrl($url);
    }

    public static function refusedProvider(): array
    {
        return [
            'http outside local' => ['http://shop.example.com/'],
            'ftp' => ['ftp://shop.example.com/'],
            'no scheme' => ['shop.example.com'],
            'credentials' => ['https://user:pass@shop.example.com/'],
            'empty' => [''],
        ];
    }

    /**
     * @dataProvider nonPublicHostProvider
     */
    public function testRefusesNonPublicHosts(string $url): void
    {
        $this->expectInvalidStoreUrl();
        $this->resolver()->validateStoreUrl($url);
    }

    public static function nonPublicHostProvider(): array
    {
        return [
            ['https://127.0.0.1/'],
            ['https://10.0.0.5/'],
            ['https://192.168.1.10:8443/'],
            ['https://169.254.169.254/latest'],
            ['https://[::1]/'],
            ['https://[fd00::1]/'],
            ['https://0x7f000001/'],
            ['https://localhost/'],
            ['https://magento/'],
            ['https://shop.localhost/'],
            ['https://shop.internal/'],
            ['https://shop.local/'],
            ['https://shop.lan/'],
            ['https://router.home.arpa/'],
        ];
    }

    public function testAcceptsHttpAndInternalHostsOnlyInLocal(): void
    {
        $this->assertSame('http://magento.localhost/', $this->resolver(true, true)->validateStoreUrl('http://magento.localhost/'));
        $this->assertSame('http://localhost:8092/', $this->resolver(true, true)->validateStoreUrl('http://localhost:8092/'));
    }

    public function testOriginNormalisesHostAndPort(): void
    {
        $resolver = $this->resolver();

        $this->assertSame('https://shop.example.com:443', $resolver->origin('https://Shop.example.com/admin/callback'));
        $this->assertSame('https://shop.example.com:443', $resolver->origin('https://shop.example.com./'));
        $this->assertSame('http://shop.example.com:80', $resolver->origin('HTTP://shop.example.com'));
        $this->assertNull($resolver->origin('not a url'));
    }

    /**
     * @dataProvider originProvider
     */
    public function testSameOrigin(string $storeUrl, string $callbackUrl, bool $same): void
    {
        $this->assertSame($same, $this->resolver()->isSameOrigin($storeUrl, $callbackUrl));
    }

    public static function originProvider(): array
    {
        $callback = 'https://shop.example.com/admin/skydropx/connect/callback/key/abc/';

        return [
            'same host' => ['https://shop.example.com/', $callback, true],
            'store in a subfolder' => ['https://shop.example.com/mx/store/', $callback, true],
            'trailing dot and case' => ['https://SHOP.example.com./', $callback, true],
            'explicit default port' => ['https://shop.example.com:443/', $callback, true],
            'other port' => ['https://shop.example.com:8443/', $callback, false],
            'admin on another host' => ['https://shop.example.com/', 'https://admin.example.com/admin/x/', false],
            'other scheme' => ['https://shop.example.com/', 'http://shop.example.com/admin/x/', false],
            'invalid callback' => ['https://shop.example.com/', 'not a url', false],
        ];
    }

    public function testResolveReturnsStoreAndCallbackUrls(): void
    {
        $callback = 'https://shop.example.com/admin/skydropx/connect/callback/key/abc/';
        $resolver = $this->resolver(true, false, 'https://shop.example.com/', $callback);

        $this->assertSame(
            ['store_url' => 'https://shop.example.com/', 'callback_url' => $callback],
            $resolver->resolve()
        );
    }

    public function testResolveRefusesAnUnbuiltPackage(): void
    {
        $this->expectReason(ConnectException::NOT_BUILT);
        $this->resolver(false)->resolve();
    }

    public function testResolveRefusesAnInsecureStoreUrl(): void
    {
        $this->expectInvalidStoreUrl();
        $this->resolver(true, false, 'http://shop.example.com/')->resolve();
    }

    public function testResolveRefusesAnAdminOnAnotherOrigin(): void
    {
        $this->expectReason(ConnectException::ORIGIN_MISMATCH);
        $this->resolver(true, false, 'https://shop.example.com/', 'https://admin.example.com/admin/x/')->resolve();
    }

    public function testStoreUrlUsesTheSecureBaseUrlOfTheDefaultStoreView(): void
    {
        $store = $this->getMockBuilder(Store::class)->disableOriginalConstructor()->onlyMethods(['getBaseUrl'])->getMock();
        $store->expects($this->once())->method('getBaseUrl')->with('web', true)->willReturn('https://shop.example.com/');
        $storeManager = $this->createMock(StoreManagerInterface::class);
        $storeManager->method('getDefaultStoreView')->willReturn($store);

        $resolver = new StoreUrlResolver($storeManager, $this->createMock(BackendUrlInterface::class));

        $this->assertSame('https://shop.example.com/', $resolver->storeUrl());
    }

    public function testStoreUrlWithoutDefaultStoreView(): void
    {
        $storeManager = $this->createMock(StoreManagerInterface::class);
        $storeManager->method('getDefaultStoreView')->willReturn(null);

        $this->expectInvalidStoreUrl();
        (new StoreUrlResolver($storeManager, $this->createMock(BackendUrlInterface::class)))->storeUrl();
    }

    public function testCallbackUrlIsTheAdminRoute(): void
    {
        $backendUrl = $this->createMock(BackendUrlInterface::class);
        $backendUrl->expects($this->once())->method('getUrl')
            ->with('skydropx/connect/callback', ['_nosid' => true])
            ->willReturn('https://shop.example.com/admin/skydropx/connect/callback/key/abc/');

        $resolver = new StoreUrlResolver($this->createMock(StoreManagerInterface::class), $backendUrl);

        $this->assertSame('https://shop.example.com/admin/skydropx/connect/callback/key/abc/', $resolver->callbackUrl());
    }

    /**
     * @param bool $built
     * @param bool $local
     * @param string $storeUrl
     * @param string $callbackUrl
     * @return StoreUrlResolver&MockObject
     */
    private function resolver(
        bool $built = true,
        bool $local = false,
        string $storeUrl = 'https://shop.example.com/',
        string $callbackUrl = 'https://shop.example.com/admin/skydropx/connect/callback/key/abc/'
    ): StoreUrlResolver {
        $store = $this->getMockBuilder(Store::class)->disableOriginalConstructor()->onlyMethods(['getBaseUrl'])->getMock();
        $store->method('getBaseUrl')->willReturn($storeUrl);
        $storeManager = $this->createMock(StoreManagerInterface::class);
        $storeManager->method('getDefaultStoreView')->willReturn($store);
        $backendUrl = $this->createMock(BackendUrlInterface::class);
        $backendUrl->method('getUrl')->willReturn($callbackUrl);

        $resolver = $this->getMockBuilder(StoreUrlResolver::class)
            ->setConstructorArgs([$storeManager, $backendUrl])
            ->onlyMethods(['isBuilt', 'isLocal'])
            ->getMock();
        $resolver->method('isBuilt')->willReturn($built);
        $resolver->method('isLocal')->willReturn($local);

        return $resolver;
    }

    private function expectInvalidStoreUrl(): void
    {
        $this->expectReason(ConnectException::INVALID_STORE_URL);
    }

    private function expectReason(string $reason): void
    {
        $this->expectException(ConnectException::class);
        $this->expectExceptionMessage($reason);
    }
}
