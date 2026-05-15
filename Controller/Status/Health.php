<?php
declare(strict_types=1);

namespace TransparentEdge\CDN\Controller\Status;

use TransparentEdge\CDN\Model\Config;
use TransparentEdge\CDN\Model\Redis\RedisManager;
use Magento\Framework\App\Action\HttpGetActionInterface;
use Magento\Framework\Controller\ResultFactory;
use Magento\Framework\Controller\Result\Raw;
use Magento\Framework\App\RequestInterface;

class Health implements HttpGetActionInterface
{
    private Config $config;
    private RedisManager $redisManager;
    private ResultFactory $resultFactory;
    private RequestInterface $request;

    public function __construct(Config $config, RedisManager $redisManager, ResultFactory $resultFactory, RequestInterface $request)
    {
        $this->config        = $config;
        $this->redisManager  = $redisManager;
        $this->resultFactory = $resultFactory;
        $this->request       = $request;
    }

    public function execute()
    {
        $result = $this->resultFactory->create(ResultFactory::TYPE_RAW);
        $result->setHeader('Content-Type', 'application/json', true);
        $result->setHeader('Cache-Control', 'no-cache, no-store', true);

        $configuredToken = $this->config->getHealthToken();
        $providedToken = $this->request->getParam('token', '');

        if (!empty($configuredToken) && $providedToken !== $configuredToken) {
            $result->setContents(json_encode(['status' => $this->config->isConfigured() ? 'ok' : 'unconfigured']));
            return $result;
        }

        $redisStatus = $this->redisManager->getStatus();
        $health = [
            'status'            => $this->config->isConfigured() ? 'ok' : 'unconfigured',
            'version'           => '2.0.0',
            'cdn_enabled'       => $this->config->isEnabled(),
            'company_id'        => $this->config->getCompanyId(),
            'speculation_rules' => $this->config->isSpeculationEnabled() ? $this->config->getSpeculationMode() : 'disabled',
            'wpo'               => ['enabled' => $this->config->isWpoEnabled()],
            'warmup_enabled'    => $this->config->isWarmupEnabled(),
            'i3_enabled'        => $this->config->isI3Enabled(),
            'cache_backend'     => ['object' => $redisStatus['cache'] ? 'redis' : 'files', 'fpc' => $redisStatus['fpc'] ? 'redis' : 'files', 'sessions' => $redisStatus['session'] ? 'redis' : 'files'],
            'log_level'         => $this->config->getLogLevel(),
            'timestamp'         => date('c'),
        ];

        $result->setContents(json_encode($health, JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT));
        return $result;
    }
}
