<?php
declare(strict_types=1);

namespace Skydropx\Shipping\Test\Unit\Model\Connect;

use PHPUnit\Framework\TestCase;
use Skydropx\Shipping\Model\Connect\ConnectUrlBuilder;

class ConnectUrlBuilderTest extends TestCase
{
    private const STORE_URL = 'https://shop.example.com/mx store/';
    private const CALLBACK_URL = 'https://shop.example.com/admin/skydropx/connect/callback/key/a+b/?x=1&y=2';

    public function testConnectUrlEncodesTheQueryWithRfc3986(): void
    {
        $url = $this->builder('https://pro.example.com/')->connectUrl(self::STORE_URL, 'st~ate.-_0123456789', self::CALLBACK_URL);

        $this->assertSame(
            'https://pro.example.com/merchant_stores/plugin_connect?platform=magento'
            . '&store_url=https%3A%2F%2Fshop.example.com%2Fmx%20store%2F'
            . '&state=st~ate.-_0123456789'
            . '&callback_url=https%3A%2F%2Fshop.example.com%2Fadmin%2Fskydropx%2Fconnect%2Fcallback%2Fkey%2Fa%2Bb%2F'
            . '%3Fx%3D1%26y%3D2',
            $url
        );
    }

    public function testConnectUrlRoundTripsEveryParameter(): void
    {
        $url = $this->builder('https://pro.example.com')->connectUrl(self::STORE_URL, str_repeat('f', 64), self::CALLBACK_URL);

        parse_str((string) parse_url($url, PHP_URL_QUERY), $query);
        $this->assertSame(
            [
                'platform' => 'magento',
                'store_url' => self::STORE_URL,
                'state' => str_repeat('f', 64),
                'callback_url' => self::CALLBACK_URL,
            ],
            $query
        );
    }

    public function testCompleteUrl(): void
    {
        $this->assertSame(
            'https://pro.example.com/merchant_stores/plugin_connect/complete?platform=magento&code=abc_DEF-123',
            $this->builder('https://pro.example.com/')->completeUrl('abc_DEF-123')
        );
    }

    public function testStoresUrl(): void
    {
        $this->assertSame('https://pro.example.com/merchant_stores', $this->builder('https://pro.example.com/')->storesUrl());
    }

    public function testUnbuiltSourceUsesThePlaceholderBase(): void
    {
        // Test/ is not shipped nor replaced by the build, so the raw placeholder is expected here (OD-2).
        $this->assertSame('APP_URL/merchant_stores', (new ConnectUrlBuilder())->storesUrl());
    }

    private function builder(string $base): ConnectUrlBuilder
    {
        $builder = $this->getMockBuilder(ConnectUrlBuilder::class)->onlyMethods(['appBase'])->getMock();
        $builder->method('appBase')->willReturn($base);

        return $builder;
    }
}
