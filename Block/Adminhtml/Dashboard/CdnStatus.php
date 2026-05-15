<?php
declare(strict_types=1);

namespace TransparentEdge\CDN\Block\Adminhtml\Dashboard;

use TransparentEdge\CDN\Model\Config;
use TransparentEdge\CDN\Model\Redis\RedisManager;
use Magento\Backend\Block\Template;
use Magento\Backend\Block\Template\Context;

class CdnStatus extends Template
{
    protected $_template = 'TransparentEdge_CDN::dashboard/cdn_status.phtml';
    private Config $config;
    private RedisManager $redisManager;

    public function __construct(Context $context, Config $config, RedisManager $redisManager, array $data = [])
    {
        $this->config       = $config;
        $this->redisManager = $redisManager;
        parent::__construct($context, $data);
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
