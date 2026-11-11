<?php
/**
 * Build-time constants: the build script writes the brand and environment values here.
 */
declare(strict_types=1);

namespace Skydropx\Shipping\Model;

// phpcs:disable Magento2.Functions.StaticFunction -- pure constants, never injected or intercepted.
/**
 * Brand and environment baked into the package by the build.
 */
final class Build // phpcs:ignore Magento2.PHP.FinalImplementation -- never injected (OD-5).
{
    public const NAME = 'PLUGIN_NAME';
    public const SLUG = 'PLUGIN_SLUG';
    public const VERSION = 'PLUGIN_VERSION';
    public const ENVIRONMENT = 'PLUGIN_ENVIRONMENT';
    public const ECOMMERCE_BASE = 'ECOMMERCE_URL';
    public const APP_BASE = 'APP_URL';
    public const COUNTRY = 'MX';
    public const PLATFORM = 'magento';
    public const CARRIER_CODE = 'skydropx';

    /**
     * Whether the build replaced the service URLs (an unbuilt source tree must never call out).
     *
     * @return bool
     */
    public static function isBuilt(): bool
    {
        return self::isHttpUrl(self::ECOMMERCE_BASE) && self::isHttpUrl(self::APP_BASE);
    }

    /**
     * Whether this package was built for the local environment.
     *
     * @return bool
     */
    public static function isLocal(): bool
    {
        return self::ENVIRONMENT === 'local';
    }

    /**
     * Check for an http(s) scheme.
     *
     * @param string $value
     * @return bool
     */
    private static function isHttpUrl(string $value): bool
    {
        return strncmp($value, 'http://', 7) === 0 || strncmp($value, 'https://', 8) === 0;
    }
}
