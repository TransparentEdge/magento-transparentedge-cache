<?php
/**
 * CLI command for cache purge operations
 *
 * Usage:
 *   bin/magento transparentedge:purge                 # Full purge
 *   bin/magento transparentedge:purge --tags p-123     # Purge by Surrogate-Key tags
 *   bin/magento transparentedge:purge --url /product   # Purge by URL
 *
 * @package   TransparentEdge_CDN
 * @author    Transparent Edge Services
 * @copyright Copyright (c) 2025 Transparent Edge Services
 * @license   MIT
 */
declare(strict_types=1);

namespace TransparentEdge\CDN\Console\Command;

use TransparentEdge\CDN\Api\ApiClient;
use TransparentEdge\CDN\Model\Config;
use TransparentEdge\CDN\Model\Invalidator;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

class PurgeCommand extends Command
{
    /**
     * @var Config
     */
    private Config $config;

    /**
     * @var ApiClient
     */
    private ApiClient $apiClient;

    /**
     * @var Invalidator
     */
    private Invalidator $invalidator;

    /**
     * @param Config      $config
     * @param ApiClient   $apiClient
     * @param Invalidator $invalidator
     */
    public function __construct(
        Config      $config,
        ApiClient   $apiClient,
        Invalidator $invalidator
    ) {
        $this->config      = $config;
        $this->apiClient   = $apiClient;
        $this->invalidator = $invalidator;
        parent::__construct();
    }

    /**
     * @inheritdoc
     */
    protected function configure(): void
    {
        $this->setName('transparentedge:purge')
            ->setDescription('Purge Transparent Edge CDN cache')
            ->addOption('tags', 't', InputOption::VALUE_OPTIONAL, 'Comma-separated Surrogate-Key tags to purge')
            ->addOption('url', 'u', InputOption::VALUE_OPTIONAL, 'URL to purge')
            ->addOption('all', 'a', InputOption::VALUE_NONE, 'Purge all CDN cache');
    }

    /**
     * @inheritdoc
     */
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        if (!$this->config->isConfigured()) {
            $output->writeln('<error>Transparent Edge CDN is not configured. Check Stores > Configuration > Advanced > Transparent Edge CDN.</error>');
            return Command::FAILURE;
        }

        $tags = $input->getOption('tags');
        $url  = $input->getOption('url');
        $all  = $input->getOption('all');

        if ($tags) {
            return $this->purgeByTags($tags, $output);
        }

        if ($url) {
            return $this->purgeByUrl($url, $output);
        }

        // Default: full purge
        return $this->purgeAll($output);
    }

    private function purgeByTags(string $tagsString, OutputInterface $output): int
    {
        $tags = array_filter(array_map('trim', explode(',', $tagsString)));
        $output->writeln(sprintf('<info>Purging %d tag(s): %s</info>', count($tags), implode(', ', $tags)));

        $result = $this->apiClient->invalidateByTags($tags);

        if ($result['success']) {
            $output->writeln('<info>✓ Tags purged successfully.</info>');
            return Command::SUCCESS;
        }

        $output->writeln(sprintf('<error>✗ Purge failed: %s</error>', $result['message']));
        return Command::FAILURE;
    }

    private function purgeByUrl(string $url, OutputInterface $output): int
    {
        // Make relative URLs absolute
        if (strpos($url, 'http') !== 0) {
            $url = $this->config->getBaseUrl() . '/' . ltrim($url, '/');
        }

        $output->writeln(sprintf('<info>Purging URL: %s</info>', $url));
        $result = $this->apiClient->invalidateByUrls([$url]);

        if ($result['success']) {
            $output->writeln('<info>✓ URL purged successfully.</info>');
            return Command::SUCCESS;
        }

        $output->writeln(sprintf('<error>✗ Purge failed: %s</error>', $result['message']));
        return Command::FAILURE;
    }

    private function purgeAll(OutputInterface $output): int
    {
        $output->writeln('<info>Purging ALL Transparent Edge CDN cache...</info>');
        $result = $this->apiClient->purgeAll();

        if ($result['success']) {
            $output->writeln('<info>✓ Full CDN cache purged successfully.</info>');
            return Command::SUCCESS;
        }

        $output->writeln(sprintf('<error>✗ Full purge failed: %s</error>', $result['message']));
        return Command::FAILURE;
    }
}
