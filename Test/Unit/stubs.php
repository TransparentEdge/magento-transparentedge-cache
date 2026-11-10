<?php
/**
 * Dobles minimos de las clases de Magento necesarias para ejercitar
 * HeaderManager de verdad (no una copia de su logica).
 *
 * IMPORTANTE: este fichero declara clases en el espacio de nombres de
 * Magento, asi que NO puede vivir en cualquier sitio del modulo.
 * setup:di:compile incluye todos los .php bajo app/code/<Vendor>/<Module>
 * y solo excluye las rutas <Modulo>/Test y <Modulo>/tests, ancladas en la
 * raiz del modulo. Fuera de ahi, la compilacion muere con
 * "Cannot declare class ..., because the name is already in use".
 * Por eso el banco vive en Test/Unit, y Test/Unit/packaging.php vigila
 * que no vuelva a salirse de ahi.
 */
declare(strict_types=1);

namespace Magento\Framework\HTTP\PhpEnvironment {
    class HeaderValue
    {
        public function __construct(private string $value) {}
        public function getFieldValue(): string { return $this->value; }
    }
}

namespace Magento\Framework\App\Response {
    use Magento\Framework\HTTP\PhpEnvironment\HeaderValue;

    class Http
    {
        private array $headers = [];
        private int $status = 200;
        private string $body = '';

        public function setHeader($name, $value, $replace = false)
        {
            $this->headers[strtolower((string) $name)] = new HeaderValue((string) $value);
            return $this;
        }

        /** Magento devuelve false cuando la cabecera no existe. */
        public function getHeader($name)
        {
            return $this->headers[strtolower((string) $name)] ?? false;
        }

        public function clearHeader($name)
        {
            unset($this->headers[strtolower((string) $name)]);
            return $this;
        }

        public function setHttpResponseCode($code) { $this->status = (int) $code; return $this; }
        public function getHttpResponseCode() { return $this->status; }
        public function setBody($body) { $this->body = (string) $body; return $this; }
        public function getBody() { return $this->body; }

        /** Solo para el banco de pruebas. */
        public function dumpHeaders(): array
        {
            $out = [];
            foreach ($this->headers as $k => $v) { $out[$k] = $v->getFieldValue(); }
            ksort($out);
            return $out;
        }
    }
}

namespace Magento\Framework\App {
    interface RequestInterface
    {
        public function getMethod();
        public function getRequestUri();
        public function isAjax();
    }
}

namespace Magento\Framework\App\Config {
    interface ScopeConfigInterface
    {
        public function getValue($path, $scopeType = 'default', $scopeCode = null);
        public function isSetFlag($path, $scopeType = 'default', $scopeCode = null);
    }
}

namespace Magento\Framework\App\Cache {
    interface StateInterface
    {
        public function isEnabled($cacheType);
        public function setEnabled($cacheType, $isEnabled);
        public function persist();
    }
}

namespace {
    // Magento's translation helper. Diagnostics builds its messages with it,
    // so the harness needs a stand-in that just returns the interpolated text.
    if (!function_exists('__')) {
        function __($text, ...$args)
        {
            foreach ($args as $i => $arg) {
                $text = str_replace('%' . ($i + 1), (string) $arg, $text);
            }
            return $text;
        }
    }
}

namespace Psr\Log {
    interface LoggerInterface
    {
        public function warning($message, array $context = []);
        public function debug($message, array $context = []);
        public function info($message, array $context = []);
        public function error($message, array $context = []);
    }
}

namespace TransparentEdge\CDN\Test {
    class FakeRequest implements \Magento\Framework\App\RequestInterface
    {
        public function __construct(
            private string $method = 'GET',
            private string $uri = '/',
            private bool $ajax = false
        ) {}
        public function getMethod() { return $this->method; }
        public function getRequestUri() { return $this->uri; }
        public function isAjax() { return $this->ajax; }
    }

    class FakeLogger implements \Psr\Log\LoggerInterface
    {
        public array $lines = [];
        public function warning($message, array $context = []) { $this->lines[] = ['warning', $message, $context]; }
        public function debug($message, array $context = []) { $this->lines[] = ['debug', $message, $context]; }
        public function info($message, array $context = []) { $this->lines[] = ['info', $message, $context]; }
        public function error($message, array $context = []) { $this->lines[] = ['error', $message, $context]; }
    }
}

namespace TransparentEdge\CDN\Model {
    /**
     * Doble de Config: valores por defecto reales tomados de etc/config.xml.
     */
    class Config
    {
        public const VERSION = '2.0.7';

        public bool $enabled = true;
        public bool $debug = false;
        public bool $speculation = false;
        public string $speculationInjection = 'php';
        public array $excludeUrls = [];

        public function isEnabled(): bool { return $this->enabled; }
        public function isDebugMode(): bool { return $this->debug; }
        public function isSpeculationEnabled(): bool { return $this->speculation; }
        public function getSpeculationInjection(): string { return $this->speculationInjection; }
        public function getSpeculationRulesUrl(): string { return 'https://tienda.test/transparentedge/speculationRules/index'; }
        public function getExcludeUrls(): array { return $this->excludeUrls; }
        public function buildHtmlCacheControl(): string
        {
            return 'max-age=3600, s-maxage=172800, stale-while-revalidate=86400, stale-if-error=86400';
        }
        public function buildStaticCacheControl(): string
        {
            return 'max-age=86400, s-maxage=2592000';
        }
    }
}
