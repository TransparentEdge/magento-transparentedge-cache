<?php
/**
 * Dobles extra para ejercitar Api/ApiClient sin red.
 *
 * Van aparte de stubs.php porque solo los necesita el banco de payload, y
 * porque stubs.php lo cargan todos los demas.
 */
declare(strict_types=1);

namespace GuzzleHttp {
    class Client
    {
        public function request($method, $uri = '', array $options = []) { return null; }
    }

    class ClientFactory
    {
        public function create(array $data = []) { return new Client(); }
    }
}

namespace GuzzleHttp\Exception {
    class GuzzleException extends \RuntimeException {}
}

namespace Magento\Framework\App {
    interface CacheInterface
    {
        public function load($identifier);
        public function save($data, $identifier, $tags = [], $lifeTime = null);
        public function remove($identifier);
        public function clean($tags = []);
        public function getFrontend();
    }
}

namespace TransparentEdge\CDN\Test {
    class FakeCache implements \Magento\Framework\App\CacheInterface
    {
        private array $data = [];
        public function load($identifier) { return $this->data[$identifier] ?? false; }
        public function save($data, $identifier, $tags = [], $lifeTime = null) { $this->data[$identifier] = $data; return true; }
        public function remove($identifier) { unset($this->data[$identifier]); return true; }
        public function clean($tags = []) { $this->data = []; return true; }
        public function getFrontend() { return null; }
    }
}
