<?php
/**
 * HTML Optimizer — WPO pipeline orchestrator
 *
 * Runs all enabled WPO optimizations on the HTML response in the
 * correct order:
 *
 * 1. DNS Prefetch (inject hints early in <head>)
 * 2. Preload critical resources (after DNS hints)
 * 3. Lazy load iframes/videos (process <body> content)
 *
 * Only runs on cacheable HTML responses, not on AJAX, API, or admin pages.
 *
 * @package   TransparentEdge_CDN
 * @author    Transparent Edge Services
 * @copyright Copyright (c) 2025 Transparent Edge Services
 * @license   MIT
 */
declare(strict_types=1);

namespace TransparentEdge\CDN\Model;

use TransparentEdge\CDN\Model\Optimizer\PreloadOptimizer;
use TransparentEdge\CDN\Model\Optimizer\LazyLoadOptimizer;
use TransparentEdge\CDN\Model\Optimizer\DnsPrefetchOptimizer;
use Magento\Framework\App\RequestInterface;
use Magento\Framework\App\Response\Http as HttpResponse;
use Psr\Log\LoggerInterface;

class HtmlOptimizer
{
    /**
     * @var Config
     */
    private Config $config;

    /**
     * @var PreloadOptimizer
     */
    private PreloadOptimizer $preloadOptimizer;

    /**
     * @var LazyLoadOptimizer
     */
    private LazyLoadOptimizer $lazyLoadOptimizer;

    /**
     * @var DnsPrefetchOptimizer
     */
    private DnsPrefetchOptimizer $dnsPrefetchOptimizer;

    /**
     * @var RequestInterface
     */
    private RequestInterface $request;

    /**
     * @var LoggerInterface
     */
    private LoggerInterface $logger;

    /**
     * @param Config                $config
     * @param PreloadOptimizer      $preloadOptimizer
     * @param LazyLoadOptimizer     $lazyLoadOptimizer
     * @param DnsPrefetchOptimizer  $dnsPrefetchOptimizer
     * @param RequestInterface      $request
     * @param LoggerInterface       $logger
     */
    public function __construct(
        Config                $config,
        PreloadOptimizer      $preloadOptimizer,
        LazyLoadOptimizer     $lazyLoadOptimizer,
        DnsPrefetchOptimizer  $dnsPrefetchOptimizer,
        RequestInterface      $request,
        LoggerInterface       $logger
    ) {
        $this->config                = $config;
        $this->preloadOptimizer      = $preloadOptimizer;
        $this->lazyLoadOptimizer     = $lazyLoadOptimizer;
        $this->dnsPrefetchOptimizer  = $dnsPrefetchOptimizer;
        $this->request               = $request;
        $this->logger                = $logger;
    }

    /**
     * Optimize the HTML response
     *
     * @param  HttpResponse $response
     * @return void
     */
    public function optimize(HttpResponse $response): void
    {
        if (!$this->shouldOptimize($response)) {
            return;
        }

        $html = $response->getBody();
        if (empty($html) || strlen($html) < 200) {
            return;
        }

        $originalLength = strlen($html);

        // Run optimizers in order
        // 1. DNS Prefetch — inject hints early in <head>
        if ($this->config->isWpoDnsPrefetchEnabled()) {
            $html = $this->dnsPrefetchOptimizer->process($html);
        }

        // 2. Preload critical resources — after DNS hints, before body
        if ($this->config->isWpoPreloadEnabled()) {
            $html = $this->preloadOptimizer->process($html);
        }

        // 3. Lazy load iframes/videos — process body content
        if ($this->config->isWpoLazyLoadEnabled()) {
            $html = $this->lazyLoadOptimizer->process($html);
        }

        // Only update body if something changed
        if ($html !== $response->getBody()) {
            $response->setBody($html);

            $this->logger->debug('TransparentEdge: HTML optimized', [
                'original_size'  => $originalLength,
                'optimized_size' => strlen($html),
            ]);
        }
    }

    /**
     * Check if we should optimize this response
     *
     * @param  HttpResponse $response
     * @return bool
     */
    private function shouldOptimize(HttpResponse $response): bool
    {
        // Must be enabled
        if (!$this->config->isEnabled() || !$this->config->isWpoEnabled()) {
            return false;
        }

        // Only optimize GET/HEAD requests
        $method = $this->request->getMethod();
        if (!in_array($method, ['GET', 'HEAD'], true)) {
            return false;
        }

        // Only optimize HTML responses
        $contentType = $response->getHeader('Content-Type');
        if ($contentType) {
            $ctValue = is_object($contentType) ? $contentType->getFieldValue() : (string) $contentType;
            if (stripos($ctValue, 'text/html') === false) {
                return false;
            }
        }

        // Don't optimize AJAX requests
        if ($this->request->isAjax()) {
            return false;
        }

        // Don't optimize admin pages
        $uri = $this->request->getRequestUri();
        if ($uri && preg_match('#^/admin|^/[a-z0-9]+_admin#i', $uri)) {
            return false;
        }

        return true;
    }
}
