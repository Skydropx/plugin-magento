<?php
declare(strict_types=1);

namespace Skydropx\Shipping\Test\Unit\Model;

use Magento\Framework\App\Cache\TypeListInterface;
use Magento\Framework\App\CacheInterface;
use Magento\Framework\App\Config\Storage\WriterInterface;
use Magento\Framework\Encryption\EncryptorInterface;
use PHPUnit\Framework\TestCase;
use Skydropx\Shipping\Model\ConnectionStore;

class ConnectionStoreTest extends TestCase
{
    private const UUID = '0b6f6a9e-3c1d-4f2a-9b8e-7d6c5b4a3f21';

    public function testSaveExtensionConnectionWritesDefaultScopeAndCleansCaches(): void
    {
        $saved = [];
        $writer = $this->createMock(WriterInterface::class);
        $writer->expects($this->exactly(6))->method('save')->willReturnCallback(
            function ($path, $value, $scope = 'default', $scopeId = 0) use (&$saved) {
                $this->assertSame('default', $scope);
                $this->assertSame(0, $scopeId);
                $saved[$path] = $value;
            }
        );
        $encryptor = $this->createMock(EncryptorInterface::class);
        $encryptor->expects($this->once())->method('encrypt')->with('plain-secret')->willReturn('enc:plain-secret');
        $typeList = $this->createMock(TypeListInterface::class);
        $typeList->expects($this->once())->method('cleanType')->with('config');
        $cache = $this->createMock(CacheInterface::class);
        $cache->expects($this->once())->method('clean')->with(['SKYDROPX_RATES']);

        (new ConnectionStore($writer, $encryptor, $typeList, $cache))
            ->saveExtensionConnection(strtoupper(self::UUID), 'plain-secret', 42, 'https://shop.example.com');

        $this->assertSame(self::UUID, $saved['carriers/skydropx/shop_id']);
        $this->assertSame('enc:plain-secret', $saved['carriers/skydropx/signing_secret']);
        $this->assertSame('extension', $saved['carriers/skydropx/connection_mode']);
        $this->assertSame('42', $saved['carriers/skydropx/integration_id']);
        $this->assertSame('https://shop.example.com', $saved['carriers/skydropx/connected_store_url']);
        $this->assertMatchesRegularExpression(
            '/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}Z$/',
            $saved['carriers/skydropx/connected_at']
        );
        $this->assertNotContains('plain-secret', $saved, 'The secret is never stored in clear.');
    }

    public function testClearDeletesEveryConnectionKeyAndCleansCaches(): void
    {
        $deleted = [];
        $writer = $this->createMock(WriterInterface::class);
        $writer->expects($this->never())->method('save');
        $writer->method('delete')->willReturnCallback(
            function ($path, $scope = 'default', $scopeId = 0) use (&$deleted) {
                $this->assertSame('default', $scope);
                $this->assertSame(0, $scopeId);
                $deleted[] = $path;
            }
        );
        $typeList = $this->createMock(TypeListInterface::class);
        $typeList->expects($this->once())->method('cleanType')->with('config');
        $cache = $this->createMock(CacheInterface::class);
        $cache->expects($this->once())->method('clean')->with(['SKYDROPX_RATES']);

        (new ConnectionStore($writer, $this->createMock(EncryptorInterface::class), $typeList, $cache))->clear();

        sort($deleted);
        $this->assertSame(
            [
                'carriers/skydropx/connected_at',
                'carriers/skydropx/connected_store_url',
                'carriers/skydropx/connection_mode',
                'carriers/skydropx/integration_id',
                'carriers/skydropx/shop_id',
                'carriers/skydropx/signing_secret',
            ],
            $deleted
        );
    }
}
