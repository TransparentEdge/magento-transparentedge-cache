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

namespace Magento\Framework\App\Request {
    /**
     * Doble de la peticion. Solo necesitamos getFullActionName(), que en
     * Magento 2.4.7 devuelve ruta_controlador_accion.
     */
    class Http
    {
        public function __construct(private string $fullActionName = '') {}
        public function getFullActionName($delimiter = '_') { return $this->fullActionName; }
    }
}

namespace Magento\Store\Model {
    interface ScopeInterface
    {
        public const SCOPE_STORE = 'store';
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
        public bool $softPurge = true;
        public int $warmupRateLimit = 3;
        public bool $refetch = false;
        public string $speculationInjection = 'php';
        public array $excludeUrls = [];

        public function isEnabled(): bool { return $this->enabled; }
        public function isDebugMode(): bool { return $this->debug; }
        public function isSpeculationEnabled(): bool { return $this->speculation; }
        public function isSoftPurgeEnabled(): bool { return $this->softPurge; }
        public function getWarmupRateLimit(): int { return $this->warmupRateLimit; }
        public function isWarmupSslVerify(): bool { return true; }
        public bool $warmupEnabled = true;
        public function isWarmupEnabled(): bool { return $this->warmupEnabled; }
        public function isWarmupHomepage(): bool { return true; }
        public function isWarmupCategories(): bool { return false; }
        public function isWarmupSitemap(): bool { return false; }
        public bool $scheduledWarmup = false;
        public int $hourlyBudget = 300;
        public function isScheduledWarmupEnabled(): bool { return $this->scheduledWarmup; }
        public function getWarmupHourlyBudget(): int { return max(0, $this->hourlyBudget); }
        public function getWarmupSitemapUrl(): string { return ''; }
        public function getWarmupCustomUrls(): array { return []; }
        public function getBaseUrl(): string { return 'https://t.test'; }
        public function shouldLog(string $level): bool { return false; }
        public function isRefetchEnabled(): bool { return $this->refetch; }
        public function getInvalidateEndpoint(): string { return 'https://api.test/v1/companies/426/tag_invalidate/'; }
        public function getUrlInvalidateEndpoint(): string { return 'https://api.test/v1/companies/426/invalidate/'; }
        public function getSpeculationInjection(): string { return $this->speculationInjection; }
        public function getSpeculationRulesUrl(): string { return 'https://tienda.test/transparentedge/speculationRules/index'; }
        public function getExcludeUrls(): array { return $this->excludeUrls; }
        // Valores por defecto reales de etc/config.xml.
        public int $htmlTtl = 172800;
        public int $htmlBrowserTtl = 3600;
        public int $staticTtl = 2592000;
        public int $staticBrowserTtl = 86400;
        public int $swr = 86400;
        public int $sie = 86400;

        public function getHtmlTtl(): int { return $this->htmlTtl; }
        public function getHtmlBrowserTtl(): int { return $this->htmlBrowserTtl; }
        public function getStaticTtl(): int { return $this->staticTtl; }
        public function getStaticBrowserTtl(): int { return $this->staticBrowserTtl; }
        public function getStaleWhileRevalidate(): int { return $this->swr; }
        public function getStaleIfError(): int { return $this->sie; }

        // Mismo orden de directivas que Model/Config::buildHtmlCacheControl().
        public function buildHtmlCacheControl(): string
        {
            return implode(', ', [
                'public',
                sprintf('s-maxage=%d', $this->getHtmlTtl()),
                sprintf('max-age=%d', $this->getHtmlBrowserTtl()),
                sprintf('stale-while-revalidate=%d', $this->getStaleWhileRevalidate()),
                sprintf('stale-if-error=%d', $this->getStaleIfError()),
            ]);
        }
        public function buildStaticCacheControl(): string
        {
            return implode(', ', [
                'public',
                sprintf('s-maxage=%d', $this->getStaticTtl()),
                sprintf('max-age=%d', $this->getStaticBrowserTtl()),
            ]);
        }
    }
}
