<?php
/**
 * Request signing (contract §1).
 */
declare(strict_types=1);

namespace Skydropx\Shipping\Model;

/**
 * HMAC-SHA256 signature over "<timestamp>.<raw body>" (contract §1).
 */
class Signer
{
    public const HEADER_TIMESTAMP = 'X-Skydropx-Timestamp';
    public const HEADER_SIGNATURE = 'X-Skydropx-Signature';

    /**
     * Sign the raw body. The key is the secret string as is, never decoded.
     *
     * @param string $secret
     * @param string $timestamp
     * @param string $rawBody
     * @return string
     */
    public function sign(string $secret, string $timestamp, string $rawBody): string
    {
        return base64_encode(hash_hmac('sha256', $timestamp . '.' . $rawBody, $secret, true));
    }

    /**
     * Signature headers. The timestamp must be taken at send time (contract §1).
     *
     * @param string $secret
     * @param string $rawBody
     * @param int|null $now
     * @return array{X-Skydropx-Timestamp: string, X-Skydropx-Signature: string}
     */
    public function headers(string $secret, string $rawBody, ?int $now = null): array
    {
        $timestamp = (string) ($now ?? time());

        return [
            self::HEADER_TIMESTAMP => $timestamp,
            self::HEADER_SIGNATURE => $this->sign($secret, $timestamp, $rawBody),
        ];
    }
}
