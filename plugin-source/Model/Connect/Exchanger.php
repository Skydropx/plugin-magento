<?php
/**
 * Server-to-server exchange of the connection code (contract §4.4).
 */
declare(strict_types=1);

namespace Skydropx\Shipping\Model\Connect;

use Skydropx\Shipping\Logger\Logger;
use Skydropx\Shipping\Model\Build;
use Skydropx\Shipping\Model\Config;
use Skydropx\Shipping\Model\Http\EcoClient;
use Skydropx\Shipping\Model\Http\EcoResponse;

/**
 * Retries 0/5xx with the very same body: eco answers a repeated exchange with the same shop and secret
 * (contract §4.4). Bodies are never logged; only status, detail and shop_id.
 */
class Exchanger
{
    public const PATH = '/api/v3/shops/magento/connect';
    public const TIMEOUT = 15;

    /**
     * Wait before each retry, in seconds.
     */
    private const RETRY_DELAYS = [1, 3];

    /**
     * Status → reason (HU §6.4.4). Any other 4xx is a module error.
     */
    private const REASONS = [
        400 => ConnectException::INVALID_REQUEST,
        401 => ConnectException::TOKEN_REJECTED,
        404 => ConnectException::UNAVAILABLE,
        409 => ConnectException::STORE_TAKEN,
        410 => ConnectException::CODE_GONE,
        429 => ConnectException::RATE_LIMITED,
        503 => ConnectException::SIGNING_UNAVAILABLE,
    ];

    /**
     * @param EcoClient $ecoClient
     * @param Logger $logger
     */
    public function __construct(
        private EcoClient $ecoClient,
        private Logger $logger
    ) {
    }

    /**
     * Exchange the code for the shop id and its signing secret.
     *
     * @param string $code
     * @param string $state
     * @param string $storeUrl
     * @param string $accessToken
     * @return array{shopId: string, signingSecret: string}
     * @throws ConnectException
     */
    public function exchange(string $code, string $state, string $storeUrl, string $accessToken): array
    {
        $body = [
            'code' => $code,
            'state' => $state,
            'store_url' => $storeUrl,
            'access_token' => $accessToken,
            'module_version' => Build::VERSION,
        ];

        $response = $this->ecoClient->postJson(self::PATH, $body, self::TIMEOUT);
        foreach (self::RETRY_DELAYS as $attempt => $delay) {
            if (!$this->isRetryable($response)) {
                break;
            }
            $this->logger->warning('CONNECT_FAILED', [
                'reason' => 'retrying',
                'attempt' => $attempt + 2,
                'status' => $response->getStatus(),
                'detail' => $response->getErrorDetail(),
            ]);
            $this->pause($delay);
            $response = $this->ecoClient->postJson(self::PATH, $body, self::TIMEOUT);
        }

        return $this->result($response);
    }

    /**
     * Sleep seam, overridden by tests.
     *
     * @param int $seconds
     * @return void
     */
    protected function pause(int $seconds): void
    {
        sleep($seconds); // phpcs:ignore Magento2.Functions.DiscouragedFunction -- documented 1 s / 3 s back-off.
    }

    /**
     * Whether eco may answer differently on a retry: no answer or a server error.
     *
     * @param EcoResponse $response
     * @return bool
     */
    private function isRetryable(EcoResponse $response): bool
    {
        return $response->isTransportError() || $response->getStatus() >= 500;
    }

    /**
     * Turn the final answer into the result or a ConnectException.
     *
     * @param EcoResponse $response
     * @return array{shopId: string, signingSecret: string}
     * @throws ConnectException
     */
    private function result(EcoResponse $response): array
    {
        $status = $response->getStatus();
        if ($response->isSuccess()) {
            $body = $response->getBody() ?? [];
            $shopId = is_string($body['shop_id'] ?? null) ? strtolower(trim($body['shop_id'])) : '';
            $secret = is_string($body['signing_secret'] ?? null) ? $body['signing_secret'] : '';
            if (preg_match(Config::UUID_PATTERN, $shopId) !== 1 || $secret === '') {
                throw new ConnectException(ConnectException::INVALID_RESPONSE, $status);
            }

            return ['shopId' => $shopId, 'signingSecret' => $secret];
        }

        if (isset(self::REASONS[$status])) {
            $reason = self::REASONS[$status];
        } elseif ($status === 0 || $status >= 500) {
            $reason = ConnectException::UNREACHABLE;
        } else {
            $reason = ConnectException::INVALID_REQUEST;
        }

        throw new ConnectException($reason, $status, $response->getErrorDetail());
    }
}
