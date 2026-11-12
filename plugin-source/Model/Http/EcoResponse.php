<?php
/**
 * Result of a call to eco.
 */
declare(strict_types=1);

namespace Skydropx\Shipping\Model\Http;

/**
 * Immutable HTTP result. Status 0 means transport error, timeout or a call that was never made.
 */
class EcoResponse
{
    private const MAX_DETAIL_LENGTH = 255;

    /**
     * @param int $status
     * @param string|null $rawBody
     * @param string|null $transportError
     * @param int|null $serverDate
     */
    public function __construct(
        private int $status,
        private ?string $rawBody = null,
        private ?string $transportError = null,
        private ?int $serverDate = null
    ) {
    }

    /**
     * HTTP status; 0 = transport error or timeout.
     *
     * @return int
     */
    public function getStatus(): int
    {
        return $this->status;
    }

    /**
     * Whether the status is 2xx.
     *
     * @return bool
     */
    public function isSuccess(): bool
    {
        return $this->status >= 200 && $this->status < 300;
    }

    /**
     * Whether no HTTP response was received.
     *
     * @return bool
     */
    public function isTransportError(): bool
    {
        return $this->status === 0;
    }

    /**
     * Decoded JSON object, or null. Unknown fields are kept and ignored by callers (contract §7).
     *
     * @return array|null
     */
    public function getBody(): ?array
    {
        if ($this->rawBody === null || $this->rawBody === '') {
            return null;
        }
        $decoded = json_decode($this->rawBody, true);

        return is_array($decoded) ? $decoded : null;
    }

    /**
     * JSON:API errors[0].detail, or the transport message; truncated. Only meant for logs.
     *
     * @return string|null
     */
    public function getErrorDetail(): ?string
    {
        $detail = $this->transportError;
        if ($detail === null) {
            $body = $this->getBody();
            $candidate = $body['errors'][0]['detail'] ?? null;
            $detail = is_scalar($candidate) ? (string) $candidate : null;
        }
        if ($detail === null || $detail === '') {
            return null;
        }

        return mb_substr($detail, 0, self::MAX_DETAIL_LENGTH);
    }

    /**
     * Unix time from eco's Date header, used to spot a skewed clock.
     *
     * @return int|null
     */
    public function getServerDate(): ?int
    {
        return $this->serverDate;
    }
}
