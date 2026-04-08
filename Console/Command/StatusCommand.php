<?php
/**
 * CLI command to check Transparent Edge CDN configuration status
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
use TransparentEdge\CDN\Model\VclGenerator;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

class StatusCommand extends Command
{
    private Config $config;
    private ApiClient $apiClient;
    private VclGenerator $vclGenerator;

    public function __construct(Config $config, ApiClient $apiClient, VclGenerator $vclGenerator)
    {
        $this->config       = $config;
        $this->apiClient    = $apiClient;
        $this->vclGenerator = $vclGenerator;
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->setName('transparentedge:status')
            ->setDescription('Show Transparent Edge CDN configuration status and test connection')
            ->addOption('vcl', null, InputOption::VALUE_NONE, 'Show generated VCL configuration');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $output->writeln('');
        $output->writeln('<info>═══════════════════════════════════════════════════════</info>');
        $output->writeln('<info>  Transparent Edge CDN — Magento 2 Plugin v2.0.0</info>');
        $output->writeln('<info>═══════════════════════════════════════════════════════</info>');
        $output->writeln('');

        // General
        $this->printSection($output, 'General');
        $this->printSetting($output, 'Enabled', $this->config->isEnabled() ? 'Yes' : 'No');
        $this->printSetting($output, 'Company ID', $this->config->getCompanyId() ?: '(not set)');
        $this->printSetting($output, 'Client ID', $this->config->getClientId() ? '****' . substr($this->config->getClientId(), -4) : '(not set)');
        $this->printSetting($output, 'Dashboard', $this->config->getDashboardUrl());

        // Cache
        $this->printSection($output, 'Cache Settings');
        $this->printSetting($output, 'HTML TTL (CDN)', $this->formatTtl($this->config->getHtmlTtl()));
        $this->printSetting($output, 'HTML TTL (Browser)', $this->formatTtl($this->config->getHtmlBrowserTtl()));
        $this->printSetting($output, 'Static TTL (CDN)', $this->formatTtl($this->config->getStaticTtl()));
        $this->printSetting($output, 'Static TTL (Browser)', $this->formatTtl($this->config->getStaticBrowserTtl()));
        $this->printSetting($output, 'Stale-While-Revalidate', $this->formatTtl($this->config->getStaleWhileRevalidate()));
        $this->printSetting($output, 'Soft Purge', $this->config->isSoftPurgeEnabled() ? 'Yes' : 'No');
        $this->printSetting($output, 'Refetch', $this->config->isRefetchEnabled() ? 'Yes' : 'No');

        // Warm-up
        $this->printSection($output, 'Warm-up');
        $this->printSetting($output, 'Enabled', $this->config->isWarmupEnabled() ? 'Yes' : 'No');
        $this->printSetting($output, 'Homepage', $this->config->isWarmupHomepage() ? 'Yes' : 'No');
        $this->printSetting($output, 'Categories', $this->config->isWarmupCategories() ? 'Yes' : 'No');
        $this->printSetting($output, 'Sitemap', $this->config->isWarmupSitemap() ? 'Yes' : 'No');
        $this->printSetting($output, 'Rate Limit', $this->config->getWarmupRateLimit() . ' req/s');

        // i3
        $this->printSection($output, 'i3 Image Optimization');
        $this->printSetting($output, 'Enabled', $this->config->isI3Enabled() ? 'Yes' : 'No');
        $this->printSetting($output, 'Auto WebP/AVIF', $this->config->isI3AutoWebp() ? 'Yes' : 'No');
        $this->printSetting($output, 'Quality', (string) $this->config->getI3Quality());
        $this->printSetting($output, 'Max Width', $this->config->getI3MaxWidth() . 'px');

        // Advanced
        $this->printSection($output, 'Advanced');
        $this->printSetting($output, 'Admin Bypass', $this->config->isAdminBypassEnabled() ? 'Yes' : 'No');
        $this->printSetting($output, 'Debug Mode', $this->config->isDebugMode() ? 'Yes' : 'No');

        // Connection test
        if ($this->config->isConfigured()) {
            $this->printSection($output, 'Connection Test');
            $output->write('  Testing API connection... ');
            $result = $this->apiClient->testConnection();
            if ($result['success']) {
                $output->writeln('<info>✓ ' . $result['message'] . '</info>');
            } else {
                $output->writeln('<e>✗ ' . $result['message'] . '</e>');
            }
        }

        // VCL output
        if ($input->getOption('vcl')) {
            $output->writeln('');
            $this->printSection($output, 'Generated VCL');
            $output->writeln($this->vclGenerator->generateFull());
        }

        $output->writeln('');
        return Command::SUCCESS;
    }

    private function printSection(OutputInterface $output, string $title): void
    {
        $output->writeln('');
        $output->writeln(sprintf('<comment>  %s</comment>', $title));
        $output->writeln(sprintf('  %s', str_repeat('─', strlen($title) + 2)));
    }

    private function printSetting(OutputInterface $output, string $label, string $value): void
    {
        $output->writeln(sprintf('    %-24s %s', $label . ':', $value));
    }

    private function formatTtl(int $seconds): string
    {
        if ($seconds >= 86400) {
            $days = $seconds / 86400;
            return sprintf('%s (%dd)', number_format($seconds), (int) $days);
        }
        if ($seconds >= 3600) {
            $hours = $seconds / 3600;
            return sprintf('%s (%dh)', number_format($seconds), (int) $hours);
        }
        return sprintf('%s (%dm)', number_format($seconds), (int) ($seconds / 60));
    }
}
