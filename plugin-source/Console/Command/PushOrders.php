<?php
/**
 * bin/magento skydropx:orders:push
 */
declare(strict_types=1);

namespace Skydropx\Shipping\Console\Command;

use Skydropx\Shipping\Model\OrderPush\Publisher;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Runs the order push on demand. Prints counts only, never bodies.
 */
class PushOrders extends Command
{
    private const NAME = 'skydropx:orders:push';
    private const OPTION_RETRY_FAILED = 'retry-failed';
    private const OPTION_LIMIT = 'limit';

    // symfony/console 4.4 (Magento 2.4.4) has no Command::SUCCESS / Command::FAILURE.
    private const EXIT_SUCCESS = 0;
    private const EXIT_FAILURE = 1;

    /**
     * @param Publisher $publisher Proxy injected by di.xml (OD-6)
     */
    public function __construct(
        private Publisher $publisher
    ) {
        parent::__construct();
    }

    /**
     * @inheritdoc
     */
    protected function configure(): void
    {
        $this->setName(self::NAME)
            ->setDescription('Send queued order events now.')
            ->addOption(
                self::OPTION_RETRY_FAILED,
                null,
                InputOption::VALUE_NONE,
                'Put failed events back in the queue before sending.'
            )
            ->addOption(
                self::OPTION_LIMIT,
                null,
                InputOption::VALUE_REQUIRED,
                'Maximum number of events to send.',
                (string) Publisher::DEFAULT_LIMIT
            );
        parent::configure();
    }

    /**
     * @inheritdoc
     */
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $limit = (string) $input->getOption(self::OPTION_LIMIT);
        if (!ctype_digit($limit) || (int) $limit < 1) {
            $output->writeln('<error>' . __('The limit must be a positive integer.') . '</error>');

            return self::EXIT_FAILURE;
        }
        try {
            $summary = $this->publisher->run((int) $limit, (bool) $input->getOption(self::OPTION_RETRY_FAILED));
        } catch (\Throwable $e) {
            // Exception messages may quote SQL with payloads: print the class only.
            $output->writeln('<error>' . __('Order push failed: %1', get_class($e)) . '</error>');

            return self::EXIT_FAILURE;
        }
        $output->writeln((string) __(
            'Sent: %1. Rescheduled: %2. Failed: %3.',
            $summary['sent'],
            $summary['retried'],
            $summary['failed']
        ));

        return self::EXIT_SUCCESS;
    }
}
