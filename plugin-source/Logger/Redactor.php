<?php
/**
 * Secret redaction for log context.
 */
declare(strict_types=1);

namespace Skydropx\Shipping\Logger;

/**
 * Replaces sensitive values with "***" at any depth, matching keys case-insensitively.
 * Stateless; the Logger applies it to every record, so callers never call it themselves.
 */
class Redactor
{
    public const MASK = '***';

    private const SENSITIVE_KEYS = [
        'signing_secret',
        'secret',
        'access_token',
        'token',
        'code',
        'state',
        'x-skydropx-signature',
        'authorization',
    ];

    /**
     * Redact a log context.
     *
     * @param array $context
     * @return array
     */
    public static function redact(array $context): array // phpcs:ignore Magento2.Functions.StaticFunction
    {
        foreach ($context as $key => $value) {
            if (is_string($key) && in_array(strtolower($key), self::SENSITIVE_KEYS, true)) {
                $context[$key] = self::MASK;
            } elseif (is_array($value)) {
                $context[$key] = self::redact($value);
            }
        }

        return $context;
    }
}
