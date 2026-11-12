<?php
/**
 * Module logger.
 */
declare(strict_types=1);

namespace Skydropx\Shipping\Logger;

use DateTimeZone;
use Magento\Framework\Logger\Monolog;
use Monolog\Handler\HandlerInterface;

/**
 * Inject this class by type. Every record's context goes through Redactor (OD-1, §6.7).
 *
 * Takes a single $handler instead of $handlers on purpose: Magento's DI hands subclasses the
 * arguments configured for the parent type, merged by item key, so a "handlers" parameter would
 * also receive the system/debug/syslog handlers that app/etc/di.xml sets on Monolog.
 */
class Logger extends Monolog
{
    /**
     * @param string $name
     * @param HandlerInterface $handler
     * @param array $processors
     * @param DateTimeZone|null $timezone
     */
    public function __construct(
        string $name,
        HandlerInterface $handler,
        array $processors = [],
        ?DateTimeZone $timezone = null
    ) {
        $processors[] = static function ($record) {
            // Monolog 3 passes an immutable LogRecord; Monolog 2 passes an array.
            if ($record instanceof \Monolog\LogRecord) {
                return $record->with(context: Redactor::redact($record->context));
            }
            $record['context'] = Redactor::redact((array) ($record['context'] ?? []));

            return $record;
        };
        parent::__construct($name, [$handler], $processors, $timezone);
    }
}
