<?php
/**
 * Cache warm-up engine for Transparent Edge CDN
 *
 * Non-blocking warm-up with rate limiting. Sends HTTP requests to key pages
 * after cache invalidation to ensure the CDN has fresh cached content.
 *
 * @package   TransparentEdge_CDN
 * @author    Transparent Edge Services
 * @copyright Copyright (c) 2025 Transparent Edge Services
 * @license   MIT
 */
declare(strict_types=1);

namespace TransparentEdge\CDN\Model;

use GuzzleHttp\ClientFactory;
use GuzzleHttp\Exception\GuzzleException;
use GuzzleHttp\Pool;
use GuzzleHttp\Psr7\Request;
use Magento\Catalog\Model\ResourceModel\Category\CollectionFactory as CategoryCollectionFactory;
use Magento\Sitemap\Model\ResourceModel\Sitemap\CollectionFactory as SitemapCollectionFactory;
use Magento\Store\Model\App\Emulation;
use Magento\Framework\App\Area;
use Magento\Store\Model\StoreManagerInterface;
use Psr\Log\LoggerInterface;

class Warmup
{
    /**
     * Maximum URLs per warm-up batch
     */
    private const MAX_URLS_PER_BATCH = 200;

    /**
     * @var Config
     */
    private Config $config;

    /**
     * @var ClientFactory
     */
    private ClientFactory $clientFactory;

    /**
     * @var CategoryCollectionFactory
     */
    private CategoryCollectionFactory $categoryCollectionFactory;

    /**
     * @var StoreManagerInterface
     */
    private StoreManagerInterface $storeManager;

    /**
     * @var Emulation
     */
    private Emulation $appEmulation;

    /**
     * @var LoggerInterface
     */
    private LoggerInterface $logger;

    /**
     * Queue of URLs to warm up
     *
     * @var array
     */
    private array $queue = [];

    /**
     * @param Config                    $config
     * @param ClientFactory             $clientFactory
     * @param CategoryCollectionFactory $categoryCollectionFactory
     * @param StoreManagerInterface     $storeManager
     * @param Emulation                 $appEmulation
     * @param LoggerInterface           $logger
     */
    public function __construct(
        Config                    $config,
        ClientFactory             $clientFactory,
        CategoryCollectionFactory $categoryCollectionFactory,
        StoreManagerInterface     $storeManager,
        Emulation                 $appEmulation,
        LoggerInterface           $logger
    ) {
        $this->config                    = $config;
        $this->clientFactory             = $clientFactory;
        $this->categoryCollectionFactory = $categoryCollectionFactory;
        $this->storeManager              = $storeManager;
        $this->appEmulation              = $appEmulation;
        $this->logger                    = $logger;
    }

    /**
     * Add a URL to the warm-up queue
     *
     * @param string $url
     */
    public function queueUrl(string $url): void
    {
        $url = trim($url);
        if (!empty($url) && !in_array($url, $this->queue, true)) {
            $this->queue[] = $url;
        }
    }

    /**
     * Add multiple URLs to the warm-up queue
     *
     * @param array $urls
     */
    public function queueUrls(array $urls): void
    {
        foreach ($urls as $url) {
            $this->queueUrl($url);
        }
    }

    /**
     * Schedule warm-up for all active category pages
     *
     * Uses store emulation to ensure category URLs are generated
     * as frontend URLs, not admin URLs. Handles CLI context gracefully
     * where emulation may not be available.
     */
    public function scheduleCategories(): void
    {
        $emulationStarted = false;

        try {
            // Switch to frontend context so getUrl() returns frontend URLs
            $defaultStore = $this->storeManager->getDefaultStoreView();
            if ($defaultStore) {
                $this->appEmulation->startEnvironmentEmulation(
                    (int) $defaultStore->getId(),
                    Area::AREA_FRONTEND,
                    true
                );
                $emulationStarted = true;
            }

            $collection = $this->categoryCollectionFactory->create();
            $collection->addIsActiveFilter()
                ->addUrlRewriteToResult()
                ->addAttributeToSelect(['url_key', 'url_path'])
                ->setPageSize(self::MAX_URLS_PER_BATCH);

            $baseUrl = $this->config->getBaseUrl();

            foreach ($collection as $category) {
                $url = $category->getUrl();
                if ($url) {
                    // If URL is an admin URL (emulation failed), build it manually
                    if (strpos($url, '/admin') !== false || strpos($url, '/key/') !== false) {
                        $urlKey = $category->getUrlKey();
                        if ($urlKey) {
                            $url = $baseUrl . '/' . $urlKey . '.html';
                        } else {
                            continue;
                        }
                    }
                    $this->queueUrl($url);
                }
            }

            if ($emulationStarted) {
                $this->appEmulation->stopEnvironmentEmulation();
            }
        } catch (\Exception $e) {
            if ($emulationStarted) {
                try {
                    $this->appEmulation->stopEnvironmentEmulation();
                } catch (\Exception $ignore) {
                }
            }

            $this->logger->warning('TransparentEdge: Could not load categories for warm-up', [
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Schedule a full warm-up (homepage + categories + custom URLs + sitemap)
     */
    public function scheduleFullWarmup(): void
    {
        $baseUrl = $this->config->getBaseUrl();

        // Homepage
        if ($this->config->isWarmupHomepage()) {
            $this->queueUrl($baseUrl . '/');
        }

        // Categories
        if ($this->config->isWarmupCategories()) {
            $this->scheduleCategories();
        }

        // Custom URLs
        $customUrls = $this->config->getWarmupCustomUrls();
        foreach ($customUrls as $url) {
            // Relative URLs → absolute
            if (strpos($url, 'http') !== 0) {
                $url = $baseUrl . '/' . ltrim($url, '/');
            }
            $this->queueUrl($url);
        }

        // Sitemap
        if ($this->config->isWarmupSitemap()) {
            $this->scheduleSitemapUrls();
        }
    }

    /**
     * Parse sitemap and queue URLs for warm-up
     */
    private function scheduleSitemapUrls(): void
    {
        $sitemapUrl = $this->config->getWarmupSitemapUrl();
        if (empty($sitemapUrl)) {
            // Try to find Magento's default sitemap
            $sitemapUrl = $this->config->getBaseUrl() . '/sitemap.xml';
        }

        try {
            $client   = $this->clientFactory->create(['config' => ['timeout' => 15]]);
            $response = $client->request('GET', $sitemapUrl);

            if ($response->getStatusCode() === 200) {
                $xml = @simplexml_load_string($response->getBody()->getContents());
                if ($xml) {
                    $count = 0;
                    foreach ($xml->url as $url) {
                        if ($count >= self::MAX_URLS_PER_BATCH) {
                            break;
                        }
                        $loc = (string) $url->loc;
                        if (!empty($loc)) {
                            $this->queueUrl($loc);
                            $count++;
                        }
                    }

                    // Handle sitemap index files
                    foreach ($xml->sitemap as $sitemap) {
                        $loc = (string) $sitemap->loc;
                        if (!empty($loc) && $count < self::MAX_URLS_PER_BATCH) {
                            $this->parseSitemapFile($loc, $count);
                        }
                    }
                }
            }
        } catch (GuzzleException $e) {
            $this->logger->warning('TransparentEdge: Could not parse sitemap for warm-up', [
                'url'   => $sitemapUrl,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Parse a single sitemap file and add URLs to queue
     *
     * @param string $sitemapUrl
     * @param int    &$count Running count of added URLs
     */
    private function parseSitemapFile(string $sitemapUrl, int &$count): void
    {
        try {
            $client   = $this->clientFactory->create(['config' => ['timeout' => 15]]);
            $response = $client->request('GET', $sitemapUrl);

            if ($response->getStatusCode() === 200) {
                $xml = @simplexml_load_string($response->getBody()->getContents());
                if ($xml) {
                    foreach ($xml->url as $url) {
                        if ($count >= self::MAX_URLS_PER_BATCH) {
                            return;
                        }
                        $loc = (string) $url->loc;
                        if (!empty($loc)) {
                            $this->queueUrl($loc);
                            $count++;
                        }
                    }
                }
            }
        } catch (GuzzleException $e) {
            $this->logger->warning('TransparentEdge: Could not parse sub-sitemap', [
                'url'   => $sitemapUrl,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Process the warm-up queue with rate limiting
     *
     * Uses Guzzle's concurrent request pool for non-blocking execution.
     *
     * @return array{total: int, success: int, failed: int}
     */
    public function processQueue(): array
    {
        if (empty($this->queue)) {
            return ['total' => 0, 'success' => 0, 'failed' => 0];
        }

        $urls = array_slice(array_unique($this->queue), 0, self::MAX_URLS_PER_BATCH);
        $this->queue = [];

        $rateLimit  = $this->config->getWarmupRateLimit();
        $concurrency = max(1, min($rateLimit, 5)); // Cap concurrency at 5

        $client = $this->clientFactory->create([
            'config' => [
                'timeout'         => 30,
                'connect_timeout' => 10,
                'http_errors'     => false,
            ],
        ]);

        $requests = function () use ($urls) {
            foreach ($urls as $url) {
                yield new Request('GET', $url, [
                    'User-Agent' => 'TransparentEdge-Warmup/2.0 (Magento)',
                ]);
            }
        };

        $success = 0;
        $failed  = 0;

        $pool = new Pool($client, $requests(), [
            'concurrency' => $concurrency,
            'fulfilled'   => function ($response, $index) use (&$success, $urls) {
                $success++;
                $this->logger->debug('TransparentEdge: Warm-up OK', [
                    'url'    => $urls[$index] ?? 'unknown',
                    'status' => $response->getStatusCode(),
                ]);
            },
            'rejected' => function ($reason, $index) use (&$failed, $urls) {
                $failed++;
                $this->logger->warning('TransparentEdge: Warm-up failed', [
                    'url'   => $urls[$index] ?? 'unknown',
                    'error' => $reason->getMessage(),
                ]);
            },
        ]);

        // Wait for all requests to complete
        $pool->promise()->wait();

        $this->logger->info('TransparentEdge: Warm-up completed', [
            'total'   => count($urls),
            'success' => $success,
            'failed'  => $failed,
        ]);

        return [
            'total'   => count($urls),
            'success' => $success,
            'failed'  => $failed,
        ];
    }

    /**
     * Get the current queue size
     *
     * @return int
     */
    public function getQueueSize(): int
    {
        return count($this->queue);
    }

    /**
     * Get the queued URLs (for debugging)
     *
     * @return array
     */
    public function getQueue(): array
    {
        return $this->queue;
    }
}
