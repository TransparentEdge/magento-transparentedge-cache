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
use TransparentEdge\CDN\Model\Optimizer\SpeculationInlineOptimizer;
use Magento\Framework\App\RequestInterface;
use Magento\Framework\App\Response\Http as HttpResponse;
use Psr\Log\LoggerInterface;

class HtmlOptimizer
{
    private Config $config;
    private PreloadOptimizer $preloadOptimizer;
    private LazyLoadOptimizer $lazyLoadOptimizer;
    private DnsPrefetchOptimizer $dnsPrefetchOptimizer;
    private SpeculationInlineOptimizer $speculationInlineOptimizer;
    private RequestInterface $request;
    private LoggerInterface $logger;

    public function __construct(
        Config                      $config,
        PreloadOptimizer            $preloadOptimizer,
        LazyLoadOptimizer           $lazyLoadOptimizer,
        DnsPrefetchOptimizer        $dnsPrefetchOptimizer,
        SpeculationInlineOptimizer  $speculationInlineOptimizer,
        RequestInterface            $request,
        LoggerInterface             $logger
    ) {
        $this->config                       = $config;
        $this->preloadOptimizer             = $preloadOptimizer;
        $this->lazyLoadOptimizer            = $lazyLoadOptimizer;
        $this->dnsPrefetchOptimizer         = $dnsPrefetchOptimizer;
        $this->speculationInlineOptimizer   = $speculationInlineOptimizer;
        $this->request                      = $request;
        $this->logger                       = $logger;
    }

    /**
     * Optimize the HTML response
     *
     * @param  HttpResponse $response
     * @return void
     */
    public function optimize(HttpResponse $response): void
    {
        try {
            $this->optimizeInternal($response);
        } catch (\Throwable $e) {
            // Never let an HTML rewrite break the page, but never lose the
            // reason either — this used to fail silently.
            $this->logger->warning('TransparentEdge: HTML optimization failed', [
                'exception' => get_class($e),
                'error'     => $e->getMessage(),
            ]);
        }
    }

    /**
     * @param  HttpResponse $response
     * @return void
     */
    private function optimizeInternal(HttpResponse $response): void
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
        // 1-3 are the WPO pipeline proper and follow the WPO master switch.
        if ($this->config->isWpoEnabled()) {
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
        }

        // 4. Speculation Rules inline — contextual prerender/prefetch per page
        //    type. This has its own enabled check and its own admin section, so
        //    it must NOT depend on the WPO switch: turning WPO off used to
        //    disable inline speculation silently, with nothing in the UI or the
        //    log to say so.
        $html = $this->speculationInlineOptimizer->process($html);

        // Only update body if something changed
        if ($html !== $response->getBody()) {
            $response->setBody($html);

            if ($this->config->shouldLog('debug')) {
                $this->logger->debug('TransparentEdge: HTML optimized', [
                    'original_size'  => $originalLength,
                    'optimized_size' => strlen($html),
                ]);
            }
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
        // The module must be on, and at least one of the two things that
        // rewrite HTML (the WPO pipeline, inline Speculation Rules) must be
        // enabled. The WPO switch alone no longer gates the whole method.
        if (!$this->config->isEnabled()) {
            return false;
        }

        // SpeculationInlineOptimizer gates itself on isSpeculationEnabled()
        // alone — the injection mode (php/vcl) only decides how the GLOBAL
        // rules are delivered, not whether the contextual inline ones run.
        if (!$this->config->isWpoEnabled() && !$this->config->isSpeculationEnabled()) {
            return false;
        }

        // Never rewrite an error page or a redirect body
        $status = (int) $response->getHttpResponseCode();
        if ($status >= 300) {
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
