<?php
/**
 * HTTP client towards eco.
 */
declare(strict_types=1);

namespace Skydropx\Shipping\Model\Http;

use Magento\Framework\App\ProductMetadataInterface;
use Magento\Framework\HTTP\Client\Curl;
use Magento\Framework\HTTP\Client\CurlFactory;
use Skydropx\Shipping\Logger\Logger;
use Skydropx\Shipping\Model\Build;
use Skydropx\Shipping\Model\Config;
use Skydropx\Shipping\Model\Signer;

/**
 * Never throws: every outcome is an EcoResponse. The raw body that is signed is the exact
 * string handed to Curl::post(), which sends a string as CURLOPT_POSTFIELDS without
 * re-encoding it (contract §1).
 */
class EcoClient
{
    public const JSON_FLAGS = JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
        | JSON_PRESERVE_ZERO_FRACTION | JSON_THROW_ON_ERROR;

    private const CONNECT_TIMEOUT = 2;

    /**
     * @var string|null
     */
    private ?string $userAgent = null;

    /**
     * @param CurlFactory $curlFactory
     * @param Config $config
     * @param Signer $signer
     * @param ProductMetadataInterface $productMetadata
     * @param Logger $logger
     */
    public function __construct(
        private CurlFactory $curlFactory,
        private Config $config,
        private Signer $signer,
        private ProductMetadataInterface $productMetadata,
        private Logger $logger
    ) {
    }

    /**
     * Signed POST with the configured secret. $rawBody is sent as is: it is the signed string.
     *
     * @param string $path
     * @param string $rawBody
     * @param array $query
     * @param array $headers
     * @param int $timeout
     * @return EcoResponse
     */
    public function postSigned(
        string $path,
        string $rawBody,
        array $query = [],
        array $headers = [],
        int $timeout = 10
    ): EcoResponse {
        if (!$this->isBuilt()) {
            return $this->notBuilt();
        }
        $secret = $this->config->getSigningSecret();
        if ($secret === null) {
            return new EcoResponse(0, null, 'Module is not connected.');
        }
        if ($this->config->isDebug()) {
            $this->logger->debug(
                'ECO_REQUEST_BODY',
                ['path' => $this->stripQuery($path), 'body' => $this->decode($rawBody)]
            );
        }
        // Timestamp taken right before sending, never earlier (contract §1).
        $signed = array_merge($headers, $this->signer->headers($secret, $rawBody));
        $response = $this->send($path, $rawBody, $query, $signed, $timeout);
        if ($this->config->isDebug()) {
            $this->logger->debug(
                'ECO_RESPONSE_BODY',
                ['path' => $this->stripQuery($path), 'body' => $response->getBody()]
            );
        }

        return $response;
    }

    /**
     * Unsigned JSON POST (connect exchange, contract §4.4). Serializes $body once; bodies are never logged.
     *
     * @param string $path
     * @param array $body
     * @param int $timeout
     * @return EcoResponse
     */
    public function postJson(string $path, array $body, int $timeout = 15): EcoResponse
    {
        if (!$this->isBuilt()) {
            return $this->notBuilt();
        }
        try {
            $rawBody = json_encode($body, self::JSON_FLAGS);
        } catch (\JsonException $e) {
            return new EcoResponse(0, null, 'Could not encode the request body.');
        }

        return $this->send($path, $rawBody, [], [], $timeout);
    }

    /**
     * Seam for tests (OD-3).
     *
     * @return bool
     */
    protected function isBuilt(): bool
    {
        return Build::isBuilt();
    }

    /**
     * Seam for tests (OD-3).
     *
     * @return string
     */
    protected function ecommerceBase(): string
    {
        return Build::ECOMMERCE_BASE;
    }

    /**
     * Perform the POST and turn every outcome into an EcoResponse.
     *
     * @param string $path
     * @param string $rawBody
     * @param array $query
     * @param array $headers
     * @param int $timeout
     * @return EcoResponse
     */
    private function send(string $path, string $rawBody, array $query, array $headers, int $timeout): EcoResponse
    {
        $url = rtrim($this->ecommerceBase(), '/') . $path;
        if ($query) {
            $url .= '?' . http_build_query($query);
        }
        $headers = array_merge(
            [
                'Content-Type' => 'application/json',
                'Accept' => 'application/json',
                'User-Agent' => $this->userAgent(),
                // An empty Expect disables "100 Continue", which Curl::parseHeaders() would read as the status.
                'Expect' => '',
            ],
            $headers
        );

        $started = microtime(true);
        try {
            /** @var Curl $curl */
            $curl = $this->curlFactory->create();
            $curl->setHeaders($headers);
            $curl->setTimeout($timeout);
            $curl->setOption(CURLOPT_CONNECTTIMEOUT, self::CONNECT_TIMEOUT);
            $curl->post($url, $rawBody);
            $response = new EcoResponse(
                (int) $curl->getStatus(),
                (string) $curl->getBody(),
                null,
                $this->serverDate((array) $curl->getHeaders())
            );
        } catch (\Throwable $e) {
            $response = new EcoResponse(0, null, $e->getMessage() !== '' ? $e->getMessage() : get_class($e));
        }

        $this->logger->info('ECO_HTTP', [
            'method' => 'POST',
            'path' => $this->stripQuery($path),
            'status' => $response->getStatus(),
            'ms' => (int) round((microtime(true) - $started) * 1000),
        ]);

        return $response;
    }

    /**
     * Response for an unbuilt package: never call placeholder URLs.
     *
     * @return EcoResponse
     */
    private function notBuilt(): EcoResponse
    {
        return new EcoResponse(0, null, 'Module is not built: the service URLs are missing.');
    }

    /**
     * "<slug>-magento/<version> (Magento <version>)".
     *
     * @return string
     */
    private function userAgent(): string
    {
        if ($this->userAgent === null) {
            $this->userAgent = sprintf(
                '%s-magento/%s (Magento %s)',
                Build::SLUG,
                Build::VERSION,
                $this->productMetadata->getVersion()
            );
        }

        return $this->userAgent;
    }

    /**
     * Read the Date header, whatever its case.
     *
     * @param array $headers
     * @return int|null
     */
    private function serverDate(array $headers): ?int
    {
        foreach ($headers as $name => $value) {
            if (is_string($name) && strtolower($name) === 'date' && is_string($value)) {
                $time = strtotime($value);

                return $time === false ? null : $time;
            }
        }

        return null;
    }

    /**
     * Path without query string, for logs.
     *
     * @param string $path
     * @return string
     */
    private function stripQuery(string $path): string
    {
        return explode('?', $path, 2)[0];
    }

    /**
     * Decode a JSON body for the debug log (so the redactor can see its keys).
     *
     * @param string $rawBody
     * @return mixed
     */
    private function decode(string $rawBody)
    {
        $decoded = json_decode($rawBody, true);

        return is_array($decoded) ? $decoded : null;
    }
}
