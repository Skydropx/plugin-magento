<?php
declare(strict_types=1);

namespace Skydropx\Shipping\Test\Unit\Logger;

use PHPUnit\Framework\TestCase;
use Skydropx\Shipping\Logger\Redactor;

class RedactorTest extends TestCase
{
    /**
     * @dataProvider sensitiveKeys
     */
    public function testSensitiveKeysAreMaskedCaseInsensitively(string $key): void
    {
        $this->assertSame([$key => '***'], Redactor::redact([$key => 'value']));
    }

    public static function sensitiveKeys(): array
    {
        return [
            ['signing_secret'],
            ['SIGNING_SECRET'],
            ['secret'],
            ['Secret'],
            ['access_token'],
            ['Access_Token'],
            ['token'],
            ['code'],
            ['state'],
            ['x-skydropx-signature'],
            ['X-Skydropx-Signature'],
            ['authorization'],
            ['Authorization'],
        ];
    }

    public function testNestedKeysAreMaskedAtAnyDepth(): void
    {
        $context = [
            'shop_id' => '0b6f6a9e-3c1d-4f2a-9b8e-7d6c5b4a3f21',
            'status' => 401,
            'headers' => [
                'X-Skydropx-Signature' => 'sig',
                'X-Skydropx-Timestamp' => '1762459205',
                'Authorization' => 'Bearer abc',
            ],
            'body' => [
                'code' => 'one-time',
                'items' => [
                    ['sku' => 'A', 'meta' => ['ACCESS_TOKEN' => 'tok']],
                ],
                'signing_secret' => ['nested' => 'array'],
            ],
        ];

        $this->assertSame(
            [
                'shop_id' => '0b6f6a9e-3c1d-4f2a-9b8e-7d6c5b4a3f21',
                'status' => 401,
                'headers' => [
                    'X-Skydropx-Signature' => '***',
                    'X-Skydropx-Timestamp' => '1762459205',
                    'Authorization' => '***',
                ],
                'body' => [
                    'code' => '***',
                    'items' => [
                        ['sku' => 'A', 'meta' => ['ACCESS_TOKEN' => '***']],
                    ],
                    'signing_secret' => '***',
                ],
            ],
            Redactor::redact($context)
        );
    }

    public function testNonSensitiveKeysAndListsAreKept(): void
    {
        $context = ['path' => '/api', 'ms' => 12, 'list' => ['token', 'secret'], 'obj' => null];

        $this->assertSame($context, Redactor::redact($context));
    }

    public function testKeysThatOnlyContainASensitiveWordAreKept(): void
    {
        $context = ['postcode' => '06600', 'region_code' => 'JAL', 'tokens_used' => 3];

        $this->assertSame($context, Redactor::redact($context));
    }
}
