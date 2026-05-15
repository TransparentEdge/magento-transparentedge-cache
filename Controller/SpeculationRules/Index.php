<?php
declare(strict_types=1);

namespace TransparentEdge\CDN\Controller\SpeculationRules;

use TransparentEdge\CDN\Model\Config;
use TransparentEdge\CDN\Model\SpeculationRules\Generator;
use Magento\Framework\App\Action\HttpGetActionInterface;
use Magento\Framework\App\ResponseInterface;
use Magento\Framework\Controller\ResultFactory;
use Magento\Framework\Controller\Result\Raw;

/**
 * Public endpoint serving the Speculation Rules JSON.
 *
 * URL: /transparentedge/speculationRules/index
 *
 * The response is cacheable by the CDN with its own Surrogate-Key,
 * allowing surgical invalidation when the configuration changes.
 */
class Index implements HttpGetActionInterface
{
    private Config $config;
    private Generator $generator;
    private ResultFactory $resultFactory;

    public function __construct(
        Config $config,
        Generator $generator,
        ResultFactory $resultFactory
    ) {
        $this->config        = $config;
        $this->generator     = $generator;
        $this->resultFactory = $resultFactory;
    }

    /**
     * Serve Speculation Rules JSON
     *
     * @return ResponseInterface|Raw
     */
    public function execute()
    {
        /** @var Raw $result */
        $result = $this->resultFactory->create(ResultFactory::TYPE_RAW);

        if (!$this->config->isSpeculationEnabled()) {
            $result->setHttpResponseCode(404);
            $result->setHeader('Content-Type', 'application/json', true);
            $result->setContents('{"error":"Speculation Rules disabled"}');
            return $result;
        }

        $rules = $this->generator->generate();
        $json = json_encode($rules, JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);

        $result->setHttpResponseCode(200);
        $result->setHeader('Content-Type', 'application/speculationrules+json', true);
        $result->setHeader('Cache-Control', 'public, max-age=3600, s-maxage=86400', true);
        $result->setHeader('Surrogate-Keys', 'te-speculation-rules', true);
        $result->setHeader('X-Content-Type-Options', 'nosniff', true);
        $result->setContents($json);

        return $result;
    }
}
