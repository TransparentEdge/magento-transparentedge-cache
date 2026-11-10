<?php
/**
 * CLI command for cache warm-up
 *
 * Usage:
 *   bin/magento transparentedge:warmup              # Full warm-up
 *   bin/magento transparentedge:warmup --url /page   # Warm specific URL
 *   bin/magento transparentedge:warmup --sitemap     # Warm from sitemap
 *
 * @package   TransparentEdge_CDN
 * @author    Transparent Edge Services
 * @copyright Copyright (c) 2025 Transparent Edge Services
 * @license   MIT
 */
declare(strict_types=1);

namespace TransparentEdge\CDN\Console\Command;

use TransparentEdge\CDN\Model\Config;
use TransparentEdge\CDN\Model\Warmup;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

class WarmupCommand extends Command
{
    private Config $config;
    private Warmup $warmup;

    public function __construct(Config $config, Warmup $warmup)
    {
        $this->config = $config;
        $this->warmup = $warmup;
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->setName('transparentedge:warmup')
            ->setDescription('Warm up Transparent Edge CDN cache')
            ->addOption('url', 'u', InputOption::VALUE_OPTIONAL, 'Specific URL to warm up')
            ->addOption('sitemap', 's', InputOption::VALUE_NONE, 'Warm up from sitemap');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        if (!$this->config->isEnabled()) {
            $output->writeln('<e>Transparent Edge CDN is not enabled.</e>');
            return Command::FAILURE;
        }

        $url = $input->getOption('url');

        if ($url) {
            if (strpos($url, 'http') !== 0) {
                $url = $this->config->getBaseUrl() . '/' . ltrim($url, '/');
            }
            $this->warmup->queueUrl($url);
            $output->writeln(sprintf('<info>Warming up: %s</info>', $url));
        } else {
            $output->writeln('<info>Starting full cache warm-up...</info>');
            $this->warmup->scheduleFullWarmup();
        }

        $output->writeln(sprintf('<info>Queued %d URL(s) for warm-up</info>', $this->warmup->getQueueSize()));

        $result = $this->warmup->processQueue();

        $output->writeln(sprintf(
            '<info>Warm-up handed to the platform: %d URL(s) in %d API call(s), %d queued, %d failed</info>',
            $result['total'],
            $result['calls'] ?? 0,
            $result['success'],
            $result['failed']
        ));

        if (($result['skipped'] ?? 0) > 0) {
            $output->writeln(sprintf(
                '<comment>%d URL(s) skipped: the hourly warm-up budget for this hour is spent. '
                . 'Raise it under Stores > Configuration > Transparent Edge CDN > Warm-up, or wait.</comment>',
                $result['skipped']
            ));
        }

        $output->writeln(
            '<comment>Each URL is soft-invalidated with refetch, so every edge node re-fetches it '
            . 'without going through this server. Budget for roughly one origin render per URL: the '
            . 'refetchers collapse the fetches for a given page across nodes, not across pages.</comment>'
        );

        return $result['failed'] > 0 ? Command::FAILURE : Command::SUCCESS;
    }
}
