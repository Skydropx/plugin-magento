<?php
/**
 * File handler for var/log/skydropx.log.
 */
declare(strict_types=1);

namespace Skydropx\Shipping\Logger;

use Magento\Framework\Filesystem\DriverInterface;
use Magento\Framework\Logger\Handler\Base;
use Monolog\Logger as MonologLogger;
use Skydropx\Shipping\Model\Config;

/**
 * Writes INFO and above; DEBUG only when debug logging is on. write() and isHandling()
 * are not overridden because their signatures differ between Monolog 2 and 3 (OD-1).
 */
class Handler extends Base
{
    /**
     * @var string
     */
    protected $fileName = '/var/log/skydropx.log';

    /**
     * @param DriverInterface $filesystem
     * @param Config $config
     * @param string|null $filePath
     * @param string|null $fileName
     */
    public function __construct(
        DriverInterface $filesystem,
        Config $config,
        ?string $filePath = null,
        ?string $fileName = null
    ) {
        try {
            $debug = $config->isDebug();
        } catch (\Throwable $e) {
            // Config may be unreadable early in setup; fall back to INFO.
            $debug = false;
        }
        $this->loggerType = $debug ? MonologLogger::DEBUG : MonologLogger::INFO;
        parent::__construct($filesystem, $filePath, $fileName);
    }
}
