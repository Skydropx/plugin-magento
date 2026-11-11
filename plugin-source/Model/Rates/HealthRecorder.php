<?php
/**
 * Last outcome of the rates call, shown by the connection panel.
 */
declare(strict_types=1);

namespace Skydropx\Shipping\Model\Rates;

use Magento\Framework\FlagManager;

/**
 * Stored in a flag. To avoid a database write on every checkout, it is only written when the
 * status changes or the stored one is older than 5 minutes.
 */
class HealthRecorder
{
    public const FLAG_CODE = 'skydropx_rates_health';

    public const STATUS_OK = 'ok';
    public const STATUS_EMPTY = 'empty';
    public const STATUS_UNAUTHORIZED = 'unauthorized';
    public const STATUS_TIMEOUT = 'timeout';
    public const STATUS_FAILED = 'failed';

    private const REFRESH_AFTER = 300;

    /**
     * @var array|null
     */
    private ?array $last = null;

    /**
     * @var bool
     */
    private bool $loaded = false;

    /**
     * @param FlagManager $flagManager
     */
    public function __construct(
        private FlagManager $flagManager
    ) {
    }

    /**
     * Record an outcome. Never throws: health is informative and must not affect checkout.
     *
     * @param string $status One of the STATUS_* constants.
     * @param int|null $httpStatus
     * @return void
     */
    public function record(string $status, ?int $httpStatus = null): void
    {
        try {
            $now = $this->now();
            $last = $this->getLast();
            if ($last !== null && $last['status'] === $status) {
                $at = strtotime($last['at']);
                if ($at !== false && $now - $at <= self::REFRESH_AFTER) {
                    return;
                }
            }
            $entry = ['status' => $status, 'http_status' => $httpStatus, 'at' => gmdate('Y-m-d\TH:i:s\Z', $now)];
            $this->flagManager->saveFlag(self::FLAG_CODE, $entry);
            $this->last = $entry;
        } catch (\Throwable $e) {
            return;
        }
    }

    /**
     * Last recorded outcome; "at" is ISO-8601 UTC.
     *
     * @return array{status: string, http_status: ?int, at: string}|null
     */
    public function getLast(): ?array
    {
        if (!$this->loaded) {
            $this->loaded = true;
            $data = $this->flagManager->getFlagData(self::FLAG_CODE);
            if (is_array($data) && is_string($data['status'] ?? null) && is_string($data['at'] ?? null)) {
                $http = $data['http_status'] ?? null;
                $this->last = [
                    'status' => $data['status'],
                    'http_status' => is_numeric($http) ? (int) $http : null,
                    'at' => $data['at'],
                ];
            }
        }

        return $this->last;
    }

    /**
     * Seam for tests.
     *
     * @return int
     */
    protected function now(): int
    {
        return time();
    }
}
