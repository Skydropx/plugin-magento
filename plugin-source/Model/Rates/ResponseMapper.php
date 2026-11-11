<?php
/**
 * Service rates to Magento rate methods.
 */
declare(strict_types=1);

namespace Skydropx\Shipping\Model\Rates;

use Magento\Quote\Model\Quote\Address\RateResult\Method;
use Magento\Quote\Model\Quote\Address\RateResult\MethodFactory;
use Skydropx\Shipping\Logger\Logger;
use Skydropx\Shipping\Model\Build;
use Skydropx\Shipping\Model\Config;

/**
 * Reads tolerantly (unknown fields are ignored, contract §7) and drops invalid rates with a log line.
 */
class ResponseMapper
{
    /**
     * Length of sales_order.shipping_method in Magento 2.4.x (OD-13).
     */
    public const MAX_SHIPPING_METHOD_LENGTH = 120;

    private const CODE_PATTERN = '/^[a-z0-9_]+$/';

    /**
     * @param MethodFactory $methodFactory
     * @param Config $config
     * @param Logger $logger
     */
    public function __construct(
        private MethodFactory $methodFactory,
        private Config $config,
        private Logger $logger
    ) {
    }

    /**
     * Map the "rates" list of a 200 answer, keeping the service order.
     *
     * @param array $rates
     * @param int $storeId
     * @param string $baseCurrency
     * @return Method[]
     */
    public function map(array $rates, int $storeId, string $baseCurrency): array
    {
        $title = $this->config->getTitle($storeId);
        $methods = [];
        foreach ($rates as $rate) {
            $problem = $this->validate($rate, $baseCurrency);
            if ($problem !== null) {
                $code = is_array($rate) && is_scalar($rate['code'] ?? null) ? (string) $rate['code'] : null;
                $this->logger->warning('RATES_FAILED', [
                    'reason' => 'rate_discarded',
                    'field' => $problem,
                    'code' => $code === null ? null : mb_substr($code, 0, 140),
                ]);
                continue;
            }
            $price = (float) $rate['price'];
            /** @var Method $method */
            $method = $this->methodFactory->create();
            $method->setCarrier(Build::CARRIER_CODE);
            $method->setCarrierTitle($title);
            $method->setMethod($rate['code']);
            $method->setMethodTitle($this->methodTitle($rate));
            $method->setPrice($price);
            $method->setCost($price);
            $methods[] = $method;
        }

        return $methods;
    }

    /**
     * Name of the first invalid field, or null when the rate is usable.
     *
     * @param mixed $rate
     * @param string $baseCurrency
     * @return string|null
     */
    private function validate($rate, string $baseCurrency): ?string
    {
        if (!is_array($rate)) {
            return 'rate';
        }
        $code = $rate['code'] ?? null;
        if (!is_string($code) || preg_match(self::CODE_PATTERN, $code) !== 1
            || strlen(Build::CARRIER_CODE . '_' . $code) > self::MAX_SHIPPING_METHOD_LENGTH
        ) {
            return 'code';
        }
        $price = $rate['price'] ?? null;
        if (!is_int($price) && !is_float($price) && !(is_string($price) && is_numeric($price))) {
            return 'price';
        }
        if ((float) $price < 0 || !is_finite((float) $price)) {
            return 'price';
        }
        // Magento stores shipping prices in base currency; no conversion (HU D10).
        $currency = $rate['currency'] ?? null;
        if (!is_string($currency) || strcasecmp($currency, $baseCurrency) !== 0) {
            return 'currency';
        }

        return null;
    }

    /**
     * "<carrier> <service>" plus the delivery days when known.
     *
     * @param array $rate
     * @return string
     */
    private function methodTitle(array $rate): string
    {
        $base = trim($this->text($rate['carrier'] ?? null) . ' ' . $this->text($rate['service'] ?? null));
        if ($base === '') {
            $base = (string) $rate['code'];
        }
        $min = $this->days($rate['min_days'] ?? null);
        $max = $this->days($rate['max_days'] ?? null);
        if ($min === null || $max === null) {
            return $base;
        }
        if ($min === $max && $min === 1) {
            return (string) __('%1 (1 day)', $base);
        }
        if ($min === $max && $min > 0) {
            return (string) __('%1 (%2 days)', $base, $min);
        }
        if ($min < $max) {
            return (string) __('%1 (%2-%3 days)', $base, $min, $max);
        }

        return $base;
    }

    /**
     * Trimmed scalar text, or "".
     *
     * @param mixed $value
     * @return string
     */
    private function text($value): string
    {
        return is_scalar($value) ? trim((string) $value) : '';
    }

    /**
     * Non-negative whole number of days, or null.
     *
     * @param mixed $value
     * @return int|null
     */
    private function days($value): ?int
    {
        if (is_int($value)) {
            return $value >= 0 ? $value : null;
        }
        if ((is_float($value) || is_string($value)) && is_numeric($value)
            && (float) $value >= 0 && floor((float) $value) === (float) $value
        ) {
            return (int) $value;
        }

        return null;
    }
}
