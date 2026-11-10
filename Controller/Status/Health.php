<?php
declare(strict_types=1);

namespace TransparentEdge\CDN\Controller\Status;

use TransparentEdge\CDN\Model\Config;
use TransparentEdge\CDN\Model\Diagnostics;
use TransparentEdge\CDN\Model\HeaderManager;
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
    private Diagnostics $diagnostics;

    public function __construct(
        Config $config,
        RedisManager $redisManager,
        ResultFactory $resultFactory,
        RequestInterface $request,
        Diagnostics $diagnostics
    ) {
        $this->config        = $config;
        $this->redisManager  = $redisManager;
        $this->resultFactory = $resultFactory;
        $this->request       = $request;
        $this->diagnostics   = $diagnostics;
    }

    public function execute()
    {
        $result = $this->resultFactory->create(ResultFactory::TYPE_RAW);
        $result->setHeader('Content-Type', 'application/json', true);
        $result->setHeader('Cache-Control', 'no-cache, no-store', true);
        // These headers are deliberate — keep HeaderManager off this response.
        $result->setHeader(HeaderManager::HEADER_TE_OWN, '1', true);

        $configuredToken = (string) $this->config->getHealthToken();
        $providedToken   = (string) $this->request->getParam('token', '');

        // Secure by default. Without a configured token this endpoint used to
        // publish company_id, the exact module version and whether the object
        // cache, the FPC and sessions run on Redis or on files — free
        // reconnaissance on every install that never set a token. Now the
        // detail requires a token; without one, only liveness is reported.
        //
        // hash_equals() compares in constant time: a plain !== leaks how many
        // leading characters of the token were right.
        $detailAllowed = $configuredToken !== ''
            && $providedToken !== ''
            && hash_equals($configuredToken, $providedToken);

        if (!$detailAllowed) {
            $result->setContents(json_encode([
                'status' => $this->config->isConfigured() ? 'ok' : 'unconfigured',
            ]));
            return $result;
        }

        $redisStatus = $this->redisManager->getStatus();
        $health = [
            'status'            => $this->config->isConfigured() ? 'ok' : 'unconfigured',
            'version'           => Config::VERSION,
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

        // Surface the environment checks here too: a merchant debugging "the
        // CDN is not caching" reaches for this endpoint before the admin panel.
        $problems = $this->diagnostics->getProblems();
        $health['diagnostics'] = [
            'ok'       => $problems === [],
            'problems' => array_map(
                static fn(array $p): array => [
                    'id'       => $p['id'],
                    'severity' => $p['severity'],
                    'title'    => $p['title'],
                    'fix'      => $p['fix'],
                ],
                $problems
            ),
        ];

        if ($this->diagnostics->hasCriticalProblem()) {
            $health['status'] = 'degraded';
        }

        $result->setContents(json_encode($health, JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT));
        return $result;
    }
}
