<?php
declare(strict_types=1);

namespace TransparentEdge\CDN\Model\SpeculationRules;

use TransparentEdge\CDN\Model\Config;
use Psr\Log\LoggerInterface;

/**
 * Generates Speculation Rules JSON for the browser.
 *
 * Produces a standards-compliant speculationrules object that tells the browser
 * which URLs to prefetch or prerender speculatively. The rules are adaptive
 * based on the configured mode (conservative, balanced, aggressive).
 *
 * @see https://wicg.github.io/nav-speculation/speculation-rules.html
 * @see https://developer.chrome.com/docs/web-platform/prerender-pages
 */
class Generator
{
    /**
     * Eagerness settings per mode.
     * - conservative: only on click/tap (safest, minimal wasted bandwidth)
     * - moderate: on hover/pointerdown (~200ms before click, sweet spot)
     * - eager: on viewport entry (most aggressive prefetch)
     */
    private const MODE_EAGERNESS = [
        'conservative' => 'conservative',
        'balanced'     => 'moderate',
        'aggressive'   => 'eager',
    ];

    private Config $config;
    private ExclusionCollector $exclusionCollector;
    private LoggerInterface $logger;

    public function __construct(
        Config $config,
        ExclusionCollector $exclusionCollector,
        LoggerInterface $logger
    ) {
        $this->config              = $config;
        $this->exclusionCollector  = $exclusionCollector;
        $this->logger              = $logger;
    }

    /**
     * Generate the complete Speculation Rules object
     *
     * @return array The rules array, ready to json_encode
     */
    public function generate(): array
    {
        $mode = $this->config->getSpeculationMode();

        if ($mode === 'off' || !$this->config->isSpeculationEnabled()) {
            return [];
        }

        $rules = [
            'prefetch' => [
                $this->buildPrefetchRule($mode),
            ],
        ];

        // Aggressive mode: add prerender for high-intent navigations
        if ($mode === 'aggressive') {
            $rules['prerender'] = [
                $this->buildPrerenderRule(),
            ];
        }

        $this->logger->debug('TransparentEdge: Speculation Rules generated', [
            'mode'       => $mode,
            'prefetch'   => count($rules['prefetch']),
            'prerender'  => count($rules['prerender'] ?? []),
            'exclusions' => count($this->exclusionCollector->getPathExclusions()),
        ]);

        return $rules;
    }

    /**
     * Build the main prefetch rule.
     *
     * Uses document source (matches links in the page) with exclusions
     * for unsafe paths and selector-based filters.
     *
     * @param  string $mode conservative|balanced|aggressive
     * @return array
     */
    private function buildPrefetchRule(string $mode): array
    {
        $conditions = [];

        // Match all same-origin links
        $conditions[] = ['href_matches' => '/*'];

        // Exclude unsafe paths
        foreach ($this->exclusionCollector->getPathExclusions() as $path) {
            $conditions[] = ['not' => ['href_matches' => $path]];
        }

        // Exclude links with specific attributes
        foreach ($this->exclusionCollector->getSelectorExclusions() as $selector) {
            $conditions[] = ['not' => ['selector_matches' => $selector]];
        }

        return [
            'source'    => 'document',
            'where'     => ['and' => $conditions],
            'eagerness' => self::MODE_EAGERNESS[$mode] ?? 'moderate',
        ];
    }

    /**
     * Build prerender rule for aggressive mode.
     *
     * Only prerenders on conservative eagerness (click/tap) to limit
     * the impact on analytics and browser resources. The prefetch rule
     * already handles hover-based speculation.
     *
     * @return array
     */
    private function buildPrerenderRule(): array
    {
        $conditions = [];

        $conditions[] = ['href_matches' => '/*'];

        foreach ($this->exclusionCollector->getPathExclusions() as $path) {
            $conditions[] = ['not' => ['href_matches' => $path]];
        }

        foreach ($this->exclusionCollector->getSelectorExclusions() as $selector) {
            $conditions[] = ['not' => ['selector_matches' => $selector]];
        }

        // Prerender only on click intent (conservative) to avoid
        // inflating analytics with phantom pageviews from hover
        return [
            'source'    => 'document',
            'where'     => ['and' => $conditions],
            'eagerness' => 'conservative',
        ];
    }
}
