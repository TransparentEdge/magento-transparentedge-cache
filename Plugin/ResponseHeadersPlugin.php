<?php
/**
 * Plugin (interceptor) for HTTP response processing
 *
 * Hooks into Magento's HTTP response lifecycle to:
 * 1. Add Surrogate-Key and Cache-Control headers (for CDN)
 * 2. Optimize HTML (WPO: preload, lazy load, DNS prefetch)
 *
 * IMPORTANT: This plugin fires on EVERY sendResponse(), including
 * pub/get.php (static files) and pub/errors/404.php where the Magento
 * application is not fully bootstrapped (no area code, no session).
 * We must guard against this to prevent SessionException crashes.
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
use Magento\Framework\App\State as AppState;

class ResponseHeadersPlugin
{
    private HeaderManager $headerManager;
    private HtmlOptimizer $htmlOptimizer;
    private AppState $appState;

    public function __construct(
        HeaderManager $headerManager,
        HtmlOptimizer $htmlOptimizer,
        AppState $appState
    ) {
        $this->headerManager = $headerManager;
        $this->htmlOptimizer = $htmlOptimizer;
        $this->appState      = $appState;
    }

    /**
     * Before the response is sent:
     * 1. Inject TE cache headers
     * 2. Optimize HTML (WPO)
     *
     * Guards:
     * - Skip if area code not set (pub/get.php, pub/errors/404.php)
     * - Skip if not in frontend or adminhtml area
     *
     * @param  HttpResponse $subject
     * @return void
     */
    public function beforeSendResponse(HttpResponse $subject): void
    {
        // Guard: skip in contexts where Magento is not fully bootstrapped
        // (static file serving, error pages, CLI). Without an area code,
        // any config/store/session access throws SessionException.
        //
        // The dependencies below are injected as Proxies (see etc/di.xml), so
        // returning here means the real objects are never built — which is what
        // keeps pub/get.php and pub/errors/404.php safe.
        try {
            $areaCode = $this->appState->getAreaCode();
        } catch (\Throwable $e) {
            return;
        }

        // Only act in frontend and adminhtml areas
        if (!in_array($areaCode, ['frontend', 'adminhtml'], true)) {
            return;
        }

        try {
            // 1. Cache headers (Surrogate-Key, Cache-Control)
            $this->headerManager->applyHeaders($subject);

            // 2. HTML optimization (preload, lazy load, DNS prefetch)
            $this->htmlOptimizer->optimize($subject);
        } catch (\Throwable $e) {
            // The CDN is an accelerator, not a critical component. Never let a
            // failure here break the response the customer is waiting for.
            return;
        }
    }
}
