<?php
declare(strict_types=1);

namespace Skydropx\Shipping\Test\Unit\Logger;

use Monolog\Handler\TestHandler;
use PHPUnit\Framework\TestCase;
use Skydropx\Shipping\Logger\Logger;

class LoggerTest extends TestCase
{
    public function testContextReachesHandlersRedacted(): void
    {
        $handler = new TestHandler();
        $logger = new Logger('skydropx', $handler);

        $logger->warning('CONNECT_FAILED', [
            'Signing_Secret' => 'plain',
            'shop_id' => '0b6f6a9e-3c1d-4f2a-9b8e-7d6c5b4a3f21',
            'request' => [
                'ACCESS_TOKEN' => 'tok',
                'headers' => ['x-skydropx-signature' => 'sig', 'Content-Type' => 'application/json'],
                'State' => 'abc',
            ],
        ]);

        $records = $handler->getRecords();
        $this->assertCount(1, $records);
        $this->assertSame('CONNECT_FAILED', $records[0]['message']);
        $this->assertSame(
            [
                'Signing_Secret' => '***',
                'shop_id' => '0b6f6a9e-3c1d-4f2a-9b8e-7d6c5b4a3f21',
                'request' => [
                    'ACCESS_TOKEN' => '***',
                    'headers' => ['x-skydropx-signature' => '***', 'Content-Type' => 'application/json'],
                    'State' => '***',
                ],
            ],
            $records[0]['context']
        );
    }

    public function testOnlyTheGivenHandlerIsAttached(): void
    {
        $handler = new TestHandler();

        $this->assertSame([$handler], (new Logger('skydropx', $handler))->getHandlers());
    }

    public function testCallerProcessorsAreKept(): void
    {
        $handler = new TestHandler();
        $calls = 0;
        $processor = static function ($record) use (&$calls) {
            $calls++;

            return $record;
        };
        $logger = new Logger('skydropx', $handler, [$processor]);

        $logger->info('ECO_HTTP', ['token' => 'x']);

        $this->assertSame(1, $calls);
        $this->assertSame(['token' => '***'], $handler->getRecords()[0]['context']);
    }
}
