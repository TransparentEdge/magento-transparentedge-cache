<?php
/**
 * Dobles de las dependencias de Magento que necesita Model/Warmup.
 */
declare(strict_types=1);

namespace Magento\Framework\App {
    class Area
    {
        public const AREA_FRONTEND = 'frontend';
    }
}

namespace Magento\Catalog\Model\ResourceModel\Category {
    class CollectionFactory
    {
        public function create() { return new \ArrayObject([]); }
    }
}

namespace Magento\Store\Model\App {
    class Emulation
    {
        public function startEnvironmentEmulation($storeId, $area = 'frontend', $force = false) {}
        public function stopEnvironmentEmulation() {}
    }
}

namespace Magento\Store\Model {
    interface StoreManagerInterface
    {
        public function getStore($storeId = null);
        public function getDefaultStoreView();
        public function getStores($withDefault = false, $codeKey = false);
    }
}

namespace TransparentEdge\CDN\Test {
    class FakeCategoryCollectionFactory extends \Magento\Catalog\Model\ResourceModel\Category\CollectionFactory {}

    class FakeEmulation extends \Magento\Store\Model\App\Emulation {}

    class FakeStoreManager implements \Magento\Store\Model\StoreManagerInterface
    {
        public function getStore($storeId = null) { return null; }
        public function getDefaultStoreView() { return null; }
        public function getStores($withDefault = false, $codeKey = false) { return []; }
    }
}
