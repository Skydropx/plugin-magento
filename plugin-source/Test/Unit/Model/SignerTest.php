<?php
declare(strict_types=1);

namespace Skydropx\Shipping\Test\Unit\Model;

use PHPUnit\Framework\TestCase;
use Skydropx\Shipping\Model\Signer;

class SignerTest extends TestCase
{
    /**
     * Shared vector with eco's extension-signature.helpers.unit.spec.ts (contract §1).
     */
    private const SECRET = 'not-a-real-secret-current';
    private const TIMESTAMP = '1762459205';
    private const BODY = '{"event":"order.created","entity_id":42}';
    private const SIGNATURE = '24Lj/jim1gdfYwWKLIEy6LzqefvvhaB0QSaYBYmMFnI=';

    public function testSignMatchesContractVector(): void
    {
        $this->assertSame(self::SIGNATURE, (new Signer())->sign(self::SECRET, self::TIMESTAMP, self::BODY));
    }

    public function testHeadersUseGivenTimestamp(): void
    {
        $headers = (new Signer())->headers(self::SECRET, self::BODY, (int) self::TIMESTAMP);

        $this->assertSame(
            ['X-Skydropx-Timestamp' => self::TIMESTAMP, 'X-Skydropx-Signature' => self::SIGNATURE],
            $headers
        );
    }

    public function testHeadersDefaultToCurrentTime(): void
    {
        $before = time();
        $headers = (new Signer())->headers(self::SECRET, self::BODY);
        $after = time();

        $timestamp = (int) $headers['X-Skydropx-Timestamp'];
        $this->assertGreaterThanOrEqual($before, $timestamp);
        $this->assertLessThanOrEqual($after, $timestamp);
        $this->assertSame(
            (new Signer())->sign(self::SECRET, $headers['X-Skydropx-Timestamp'], self::BODY),
            $headers['X-Skydropx-Signature']
        );
    }

    public function testAnyByteChangeChangesTheSignature(): void
    {
        $signer = new Signer();

        $this->assertNotSame(
            self::SIGNATURE,
            $signer->sign(self::SECRET, self::TIMESTAMP, '{"event":"order.created","entity_id":42 }')
        );
    }
}
