<?php
/**
 * Observer for tag-based cache invalidation
 *
 * Intercepts Magento's clean_cache_by_tags event and entity save events
 * to queue surgical tag-based CDN invalidation via Surrogate-Keys.
 *
 * @package   TransparentEdge_CDN
 * @author    Transparent Edge Services
 * @copyright Copyright (c) 2025 Transparent Edge Services
 * @license   MIT
 */
declare(strict_types=1);

namespace TransparentEdge\CDN\Observer;

use TransparentEdge\CDN\Model\Config;
use TransparentEdge\CDN\Model\Invalidator;
use Magento\Framework\Event\Observer;
use Magento\Framework\Event\ObserverInterface;
use Magento\Framework\App\Cache\Tag\Resolver as TagResolverInterface;
use Psr\Log\LoggerInterface;

class InvalidateTagsObserver implements ObserverInterface
{
    /**
     * @var Config
     */
    private Config $config;

    /**
     * @var Invalidator
     */
    private Invalidator $invalidator;

    /**
     * @var LoggerInterface
     */
    private LoggerInterface $logger;

    /**
     * @param Config          $config
     * @param Invalidator     $invalidator
     * @param LoggerInterface $logger
     */
    public function __construct(
        Config          $config,
        Invalidator     $invalidator,
        LoggerInterface $logger
    ) {
        $this->config      = $config;
        $this->invalidator = $invalidator;
        $this->logger      = $logger;
    }

    /**
     * Execute observer
     *
     * Handles multiple event types:
     * - clean_cache_by_tags: Magento's generic tag cleaning event
     * - catalog_product_save_after: Product saved
     * - catalog_category_save_after: Category saved
     * - cms_page_save_after: CMS page saved
     * - cms_block_save_after: CMS block saved
     *
     * @param Observer $observer
     */
    public function execute(Observer $observer): void
    {
        if (!$this->config->isConfigured()) {
            return;
        }

        $event     = $observer->getEvent();
        $eventName = $event->getName();

        switch ($eventName) {
            case 'clean_cache_by_tags':
                $this->handleCleanCacheByTags($event);
                break;

            case 'catalog_product_save_after':
                $this->handleProductSave($event);
                break;

            case 'catalog_category_save_after':
                $this->handleCategorySave($event);
                break;

            case 'cms_page_save_after':
                $this->handlePageSave($event);
                break;

            case 'cms_block_save_after':
                $this->handleBlockSave($event);
                break;

            case 'catalog_product_delete_after':
                $this->handleProductDelete($event);
                break;

            case 'catalog_category_delete_after':
                $this->handleCategoryDelete($event);
                break;

            default:
                // Handle entity save events generically
                $this->handleGenericSave($event);
                break;
        }
    }

    /**
     * Handle the clean_cache_by_tags event
     *
     * This is the primary invalidation entry point. Magento fires this event
     * with the cache tags that need to be cleaned.
     *
     * @param \Magento\Framework\Event $event
     */
    private function handleCleanCacheByTags($event): void
    {
        $object = $event->getObject();

        if ($object && method_exists($object, 'getIdentities')) {
            $tags = $object->getIdentities();
            if (!empty($tags)) {
                $this->invalidator->queueTags($tags);
                $this->logger->debug('TransparentEdge: Queued tags from clean_cache_by_tags', [
                    'tags' => $tags,
                ]);
            }
        }
    }

    /**
     * Handle product save event
     *
     * @param \Magento\Framework\Event $event
     */
    private function handleProductSave($event): void
    {
        $product = $event->getProduct();
        if (!$product) {
            return;
        }

        $tags = [];

        // Product-specific tag
        $tags[] = 'cat_p_' . $product->getId();

        // Also invalidate all category pages where this product appears
        $categoryIds = $product->getCategoryIds();
        if (is_array($categoryIds)) {
            foreach ($categoryIds as $categoryId) {
                $tags[] = 'cat_c_' . $categoryId;
            }
        }

        // Global product tag (for listings that show "all products")
        $tags[] = 'cat_p';

        $this->invalidator->queueTags($tags);

        $this->logger->info('TransparentEdge: Product save invalidation queued', [
            'product_id'   => $product->getId(),
            'category_ids' => $categoryIds,
            'tags'         => $tags,
        ]);
    }

    /**
     * Handle category save event
     *
     * @param \Magento\Framework\Event $event
     */
    private function handleCategorySave($event): void
    {
        $category = $event->getCategory();
        if (!$category) {
            return;
        }

        $tags = [];

        // Category-specific tag
        $tags[] = 'cat_c_' . $category->getId();

        // Parent categories (category navigation might change)
        $parentIds = $category->getParentIds();
        if (is_array($parentIds)) {
            foreach ($parentIds as $parentId) {
                // Skip root categories (ID 1 and 2 in default Magento)
                if ((int) $parentId > 2) {
                    $tags[] = 'cat_c_' . $parentId;
                }
            }
        }

        // Global category tag
        $tags[] = 'cat_c';

        $this->invalidator->queueTags($tags);

        $this->logger->info('TransparentEdge: Category save invalidation queued', [
            'category_id' => $category->getId(),
            'tags'        => $tags,
        ]);
    }

    /**
     * Handle CMS page save event
     *
     * @param \Magento\Framework\Event $event
     */
    private function handlePageSave($event): void
    {
        $page = $event->getObject();
        if (!$page || !$page->getId()) {
            return;
        }

        $tags = [
            'cms_p_' . $page->getId(),
            'cms_p',
        ];

        $this->invalidator->queueTags($tags);

        $this->logger->info('TransparentEdge: CMS page save invalidation queued', [
            'page_id' => $page->getId(),
        ]);
    }

    /**
     * Handle CMS block save event
     *
     * @param \Magento\Framework\Event $event
     */
    private function handleBlockSave($event): void
    {
        $block = $event->getObject();
        if (!$block || !$block->getId()) {
            return;
        }

        $tags = [
            'cms_b_' . $block->getId(),
            'cms_b',
        ];

        $this->invalidator->queueTags($tags);

        $this->logger->info('TransparentEdge: CMS block save invalidation queued', [
            'block_id' => $block->getId(),
        ]);
    }

    /**
     * Handle generic model save events
     *
     * Extracts cache identities from any model that implements getIdentities().
     *
     * @param \Magento\Framework\Event $event
     */
    private function handleGenericSave($event): void
    {
        $object = $event->getObject();
        if ($object && method_exists($object, 'getIdentities')) {
            $tags = $object->getIdentities();
            if (!empty($tags)) {
                $this->invalidator->queueTags($tags);
            }
        }
    }

    /**
     * Handle product delete event
     *
     * @param \Magento\Framework\Event $event
     */
    private function handleProductDelete($event): void
    {
        $product = $event->getProduct();
        if (!$product) {
            return;
        }

        $tags = ['cat_p_' . $product->getId()];

        // Also invalidate categories where this product appeared
        $categoryIds = $product->getCategoryIds();
        if (is_array($categoryIds)) {
            foreach ($categoryIds as $categoryId) {
                $tags[] = 'cat_c_' . $categoryId;
            }
        }

        $tags[] = 'cat_p';

        $this->invalidator->queueTags($tags);

        $this->logger->info('TransparentEdge: Product delete invalidation queued', [
            'product_id' => $product->getId(),
        ]);
    }

    /**
     * Handle category delete event
     *
     * @param \Magento\Framework\Event $event
     */
    private function handleCategoryDelete($event): void
    {
        $category = $event->getCategory();
        if (!$category) {
            return;
        }

        $tags = [
            'cat_c_' . $category->getId(),
            'cat_c',
        ];

        $this->invalidator->queueTags($tags);

        $this->logger->info('TransparentEdge: Category delete invalidation queued', [
            'category_id' => $category->getId(),
        ]);
    }
}
