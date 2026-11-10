<?php
declare(strict_types=1);

namespace TransparentEdge\CDN\Block\Adminhtml\Dashboard;

use TransparentEdge\CDN\Model\Config;
use TransparentEdge\CDN\Model\Diagnostics;
use TransparentEdge\CDN\Model\Redis\RedisManager;
use Magento\Backend\Block\Template;
use Magento\Backend\Block\Template\Context;

class CdnStatus extends Template
{
    protected $_template = 'TransparentEdge_CDN::dashboard/cdn_status.phtml';
    private Config $config;
    private RedisManager $redisManager;
    private Diagnostics $diagnostics;

    public function __construct(
        Context $context,
        Config $config,
        RedisManager $redisManager,
        Diagnostics $diagnostics,
        array $data = []
    ) {
        $this->config       = $config;
        $this->redisManager = $redisManager;
        $this->diagnostics  = $diagnostics;
        parent::__construct($context, $data);
    }

    /**
     * Findings that need the merchant to act. Empty when everything is fine.
     *
     * @return array<int, array{id:string, severity:string, title:string, detail:string, fix:string}>
     */
    public function getProblems(): array
    {
        if (!$this->config->isEnabled()) {
            return [];
        }

        return $this->diagnostics->getProblems();
    }

    public function hasCriticalProblem(): bool
    {
        return $this->config->isEnabled() && $this->diagnostics->hasCriticalProblem();
    }

    public function isConfigured(): bool { return $this->config->isConfigured(); }
    public function isEnabled(): bool { return $this->config->isEnabled(); }
    public function getCompanyId(): string { return $this->config->getCompanyId(); }

    public function getActiveFeatures(): array
    {
        $f = [];
        if ($this->config->isWpoEnabled()) $f[] = 'WPO';
        if ($this->config->isI3Enabled()) $f[] = 'i3';
        if ($this->config->isWarmupEnabled()) $f[] = 'Warm-up';
        if ($this->config->isSpeculationEnabled()) $f[] = 'Speculation (' . $this->config->getSpeculationMode() . ')';
        if ($this->config->isSoftPurgeEnabled()) $f[] = 'Soft Purge';
        return $f;
    }

    public function getCacheBackend(): string
    {
        $s = $this->redisManager->getStatus();
        return $s['cache'] ? 'Redis' : 'Files';
    }

    public function getConfigUrl(): string { return $this->getUrl('adminhtml/system_config/edit', ['section' => 'transparentedge']); }
    public function getPurgeUrl(): string { return $this->getUrl('transparentedge/cache/purge'); }
    public function getDashboardUrl(): string { return 'https://dashboard.transparentcdn.com/' . $this->getCompanyId() . '/invalidation'; }
}
