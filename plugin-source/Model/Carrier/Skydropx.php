<?php
/**
 * Checkout carrier: signed rates from the service (contract §2).
 */
declare(strict_types=1);

namespace Skydropx\Shipping\Model\Carrier;

use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Quote\Model\Quote\Address\RateRequest;
use Magento\Quote\Model\Quote\Address\RateResult\ErrorFactory;
use Magento\Quote\Model\Quote\Address\RateResult\Method;
use Magento\Shipping\Model\Carrier\AbstractCarrier;
use Magento\Shipping\Model\Carrier\CarrierInterface;
use Magento\Shipping\Model\Rate\Result;
use Magento\Shipping\Model\Rate\ResultFactory;
use Skydropx\Shipping\Logger\Logger;
use Skydropx\Shipping\Model\Build;
use Skydropx\Shipping\Model\Config;
use Skydropx\Shipping\Model\Http\EcoClient;
use Skydropx\Shipping\Model\Http\EcoResponse;
use Skydropx\Shipping\Model\Rates\HealthRecorder;
use Skydropx\Shipping\Model\Rates\PayloadBuilder;
use Skydropx\Shipping\Model\Rates\RateCache;
use Skydropx\Shipping\Model\Rates\ResponseMapper;

/**
 * On any failure the carrier hides itself so checkout goes on with the other methods (HU D9).
 */
class Skydropx extends AbstractCarrier implements CarrierInterface
{
    public const RATES_PATH = '/api/v3/rates/' . Build::PLATFORM . '/' . Build::COUNTRY . '/checkout';

    /**
     * Beyond this difference with the service clock, signatures are likely rejected (contract §1, R5).
     */
    private const CLOCK_SKEW_LIMIT = 120;

    /**
     * @var string
     */
    protected $_code = Build::CARRIER_CODE;

    /**
     * @param ScopeConfigInterface $scopeConfig
     * @param ErrorFactory $rateErrorFactory
     * @param Logger $logger
     * @param ResultFactory $rateResultFactory
     * @param Config $config
     * @param PayloadBuilder $payloadBuilder
     * @param ResponseMapper $responseMapper
     * @param RateCache $rateCache
     * @param HealthRecorder $healthRecorder
     * @param EcoClient $ecoClient
     * @param array $data
     */
    public function __construct(
        ScopeConfigInterface $scopeConfig,
        ErrorFactory $rateErrorFactory,
        Logger $logger,
        private ResultFactory $rateResultFactory,
        private Config $config,
        private PayloadBuilder $payloadBuilder,
        private ResponseMapper $responseMapper,
        private RateCache $rateCache,
        private HealthRecorder $healthRecorder,
        private EcoClient $ecoClient,
        array $data = []
    ) {
        parent::__construct($scopeConfig, $rateErrorFactory, $logger, $data);
    }

    /**
     * Collect rates. Never throws.
     *
     * @param RateRequest $request
     * @return Result|bool
     */
    public function collectRates(RateRequest $request)
    {
        try {
            return $this->quote($request);
        } catch (\Throwable $e) {
            $this->_logger->error('RATES_FAILED', [
                'reason' => 'exception',
                'error' => get_class($e) . ': ' . mb_substr($e->getMessage(), 0, 255),
            ]);

            return false;
        }
    }

    /**
     * Allowed methods (the actual codes come from the service).
     *
     * @return array
     */
    public function getAllowedMethods()
    {
        return [$this->_code => (string) $this->getConfigData('title')];
    }

    /**
     * Steps 1-7 of the rates flow.
     *
     * @param RateRequest $request
     * @return Result|bool
     */
    private function quote(RateRequest $request)
    {
        if (!$this->getConfigFlag('active') || !$this->config->isConnected()) {
            return false;
        }
        // The service only quotes Mexico; a cart estimate without postcode is not quoted.
        if ($request->getDestCountryId() !== Build::COUNTRY || trim((string) $request->getDestPostcode()) === '') {
            return false;
        }

        $storeId = (int) $request->getStoreId();
        $payload = $this->payloadBuilder->build($request);
        // Serialized once: this exact string is the cache key input and the signed body (contract §1).
        $rawBody = json_encode($payload, EcoClient::JSON_FLAGS);
        $shopId = (string) $this->config->getShopId();
        $key = $this->rateCache->key($shopId, $rawBody);
        $currency = (string) $payload['currency'];

        // Request memory first, then the cache type (both inside RateCache, OD-15).
        $cached = $this->rateCache->load($key);
        if ($cached !== null) {
            return $this->respond($this->responseMapper->map($cached, $storeId, $currency));
        }

        if ($this->rateCache->isOpen()) {
            $this->_logger->debug('RATES_FAILED', ['reason' => 'breaker_open']);

            return $this->unavailable();
        }

        $response = $this->ecoClient->postSigned(
            self::RATES_PATH,
            $rawBody,
            ['shop_id' => $shopId],
            [],
            $this->config->getRatesTimeout()
        );

        if ($response->getStatus() === 401) {
            return $this->unauthorized($response);
        }
        $rates = $response->isSuccess() ? ($response->getBody()['rates'] ?? null) : null;
        if (!is_array($rates) || !array_is_list($rates)) {
            return $this->failed($response);
        }

        $methods = $this->responseMapper->map($rates, $storeId, $currency);
        // An empty list (or only invalid rates) is not an error: cached briefly, carrier hidden (contract §2).
        $toCache = $methods ? $rates : [];
        $this->rateCache->save($key, $toCache);
        $this->healthRecorder->record(
            $methods ? HealthRecorder::STATUS_OK : HealthRecorder::STATUS_EMPTY,
            $response->getStatus()
        );

        return $this->respond($methods);
    }

    /**
     * Result with the methods, or the "unavailable" answer when there are none.
     *
     * @param Method[] $methods
     * @return Result|bool
     */
    private function respond(array $methods)
    {
        if (!$methods) {
            return $this->unavailable();
        }
        $result = $this->rateResultFactory->create();
        foreach ($methods as $method) {
            $result->append($method);
        }

        return $result;
    }

    /**
     * Hide the carrier, or show the configured message when "showmethod" is on (HU D9).
     *
     * @return Result|bool
     */
    private function unavailable()
    {
        if (!$this->getConfigFlag('showmethod')) {
            return false;
        }
        $error = $this->_rateErrorFactory->create();
        $error->setCarrier($this->_code);
        $error->setCarrierTitle((string) $this->getConfigData('title'));
        $error->setErrorMessage((string) $this->getConfigData('specificerrmsg'));
        $result = $this->rateResultFactory->create();
        $result->append($error);

        return $result;
    }

    /**
     * 401: the signature did not verify (contract §1). Often a skewed server clock.
     *
     * @param EcoResponse $response
     * @return Result|bool
     */
    private function unauthorized(EcoResponse $response)
    {
        $context = ['status' => 401, 'detail' => $response->getErrorDetail()];
        $serverDate = $response->getServerDate();
        if ($serverDate !== null && abs($serverDate - time()) > self::CLOCK_SKEW_LIMIT) {
            $context['clock_skew_seconds'] = time() - $serverDate;
            $context['hint'] = 'The server clock is out of sync with the service; signed requests expire.';
        }
        $this->_logger->warning('RATES_UNAUTHORIZED', $context);
        $this->healthRecorder->record(HealthRecorder::STATUS_UNAUTHORIZED, 401);

        return $this->unavailable();
    }

    /**
     * Transport error, timeout, 5xx, other status or an unreadable 200: counts towards the breaker.
     *
     * @param EcoResponse $response
     * @return Result|bool
     */
    private function failed(EcoResponse $response)
    {
        $status = $response->getStatus();
        $detail = $response->getErrorDetail();
        // curl reports "Operation timed out ..." / "Connection timeout ...".
        $timeout = $status === 0 && $detail !== null && preg_match('/timed out|timeout/i', $detail) === 1;
        $this->_logger->warning('RATES_FAILED', [
            'reason' => $timeout ? 'timeout' : ($response->isSuccess() ? 'invalid_body' : 'http_error'),
            'status' => $status,
            'detail' => $detail,
        ]);
        $this->rateCache->recordFailure();
        $this->healthRecorder->record(
            $timeout ? HealthRecorder::STATUS_TIMEOUT : HealthRecorder::STATUS_FAILED,
            $status === 0 ? null : $status
        );

        return $this->unavailable();
    }
}
