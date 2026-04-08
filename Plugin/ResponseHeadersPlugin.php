<?php
/**
 * Plugin (interceptor) for HTTP response processing
 *
 * Hooks into Magento's HTTP response lifecycle to:
 * 1. Add Surrogate-Key and Cache-Control headers (for CDN)
 * 2. Optimize HTML (WPO: preload, lazy load, DNS prefetch)
 *
 * @package   TransparentEdge_CDN
 * @author    Transparent Edge Services
 * @copyright Copyright (c) 2025 Transparent Edge Services
 * @license   MIT
 */
declare(strict_types=1);

namespace TransparentEdge\CDN\Plugin;

use TransparentEdge\CDN\Model\HeaderManager;
use TransparentEdge\CDN\Model\HtmlOptimizer;
use Magento\Framework\App\Response\Http as HttpResponse;

class ResponseHeadersPlugin
{
    /**
     * @var HeaderManager
     */
    private HeaderManager $headerManager;

    /**
     * @var HtmlOptimizer
     */
    private HtmlOptimizer $htmlOptimizer;

    /**
     * @param HeaderManager $headerManager
     * @param HtmlOptimizer $htmlOptimizer
     */
    public function __construct(
        HeaderManager $headerManager,
        HtmlOptimizer $htmlOptimizer
    ) {
        $this->headerManager = $headerManager;
        $this->htmlOptimizer = $htmlOptimizer;
    }

    /**
     * Before the response is sent:
     * 1. Inject TE cache headers
     * 2. Optimize HTML (WPO)
     *
     * @param  HttpResponse $subject
     * @return void
     */
    public function beforeSendResponse(HttpResponse $subject): void
    {
        // 1. Cache headers (Surrogate-Key, Cache-Control)
        $this->headerManager->applyHeaders($subject);

        // 2. HTML optimization (preload, lazy load, DNS prefetch)
        $this->htmlOptimizer->optimize($subject);
    }
}
