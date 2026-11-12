<?php
/**
 * Banco del detector de conflictos de speculation rules.
 *
 * El navegador FUSIONA todos los conjuntos de reglas que encuentra en una
 * pagina: no elige uno. Asi que anadir las nuestras sobre las de otro no
 * las sustituye, hace que el visitante se descargue las dos listas. Por eso
 * el plugin se aparta, y por eso hay que avisar.
 *
 * Uso:  php Test/Unit/speculation-conflict.php
 */
declare(strict_types=1);

namespace Psr\Log {
    if (!interface_exists(LoggerInterface::class)) {
        interface LoggerInterface {
            public function warning($message, array $context = []);
            public function debug($message, array $context = []);
            public function info($message, array $context = []);
            public function error($message, array $context = []);
        }
    }
}

namespace Magento\Framework\App {
    if (!interface_exists(CacheInterface::class)) {
        interface CacheInterface {
            public function load($identifier);
            public function save($data, $identifier, $tags = [], $lifeTime = null);
            public function remove($identifier);
            public function clean($tags = []);
            public function getFrontend();
        }
    }
}

namespace TransparentEdge\CDN\Model {
    class Diagnostics
    {
        public const SPECULATION_CONFLICT_KEY = 'te_cdn_speculation_conflict';
    }

    class Config
    {
        public bool $speculation = true;
        public string $mode = 'moderate';
        public function isSpeculationEnabled(): bool { return $this->speculation; }
        public function getSpeculationMode(): string { return $this->mode; }
        public function getBaseUrl(): string { return 'https://t.test'; }
        public function shouldLog(string $n): bool { return false; }
        public function getExcludeUrls(): array { return []; }
    }
}

namespace TransparentEdge\CDN\Model\SpeculationRules {
    class ExclusionCollector
    {
        public function collect(): array { return []; }
        public function getExclusions(): array { return []; }
    }
}

namespace TransparentEdge\CDN\Test {
    class Cache implements \Magento\Framework\App\CacheInterface
    {
        public array $data = [];
        public int $escrituras = 0;
        public function load($identifier) { return $this->data[$identifier] ?? false; }
        public function save($data, $identifier, $tags = [], $lifeTime = null) {
            $this->escrituras++; $this->data[$identifier] = $data; return true;
        }
        public function remove($identifier) { unset($this->data[$identifier]); return true; }
        public function clean($tags = []) { $this->data = []; return true; }
        public function getFrontend() { return null; }
    }

    class Logger implements \Psr\Log\LoggerInterface
    {
        public function warning($message, array $context = []) {}
        public function debug($message, array $context = []) {}
        public function info($message, array $context = []) {}
        public function error($message, array $context = []) {}
    }
}

namespace {
    require __DIR__ . '/../../Model/Optimizer/SpeculationInlineOptimizer.php';

    use TransparentEdge\CDN\Model\Optimizer\SpeculationInlineOptimizer;

    $pass = 0; $fail = 0;
    function check(string $name, bool $ok, string $detail = ''): void {
        global $pass, $fail;
        if ($ok) { $pass++; printf("  OK    %s\n", $name); }
        else { $fail++; printf("  FALLO %s%s\n", $name, $detail !== '' ? "  -> $detail" : ''); }
    }

    function hacer(): array {
        $cache = new \TransparentEdge\CDN\Test\Cache();
        $o = new SpeculationInlineOptimizer(
            new \TransparentEdge\CDN\Model\Config(),
            new \TransparentEdge\CDN\Model\SpeculationRules\ExclusionCollector(),
            new \TransparentEdge\CDN\Test\Logger(),
            $cache
        );
        return [$o, $cache];
    }

    function conflictoDe(string $html): ?string {
        [$o, $cache] = hacer();
        $o->process($html);
        $r = $cache->load(\TransparentEdge\CDN\Model\Diagnostics::SPECULATION_CONFLICT_KEY);
        if (!is_string($r)) { return null; }
        $d = json_decode($r, true);
        return $d['what'] ?? null;
    }

    $pagina = function (string $extra): string {
        return "<html><head><title>x</title>{$extra}</head><body>"
             . '<a href="https://t.test/a.html">a</a><a href="https://t.test/b.html">b</a>'
             . '</body></html>';
    };

    echo "=== una pagina limpia no da conflicto ===\n";
    check('sin conflicto', conflictoDe($pagina('')) === null);

    echo "\n=== otro conjunto de speculation rules ===\n";
    check('lo detecta',
        conflictoDe($pagina('<script type="speculationrules">{"prerender":[]}</script>')) === 'another speculationrules script');
    check('con comillas simples tambien',
        conflictoDe($pagina("<script type='speculationrules'>{}</script>")) === 'another speculationrules script');
    check('con espacios alrededor del igual',
        conflictoDe($pagina('<script type = "speculationrules">{}</script>')) === 'another speculationrules script');

    echo "\n=== librerias de prefetch conocidas ===\n";
    check('quicklink por src',
        conflictoDe($pagina('<script src="/js/quicklink.umd.js"></script>')) === 'quicklink');
    check('instant.page por src',
        conflictoDe($pagina('<script src="https://cdn.x/instant.page/5.1.js"></script>')) === 'instant.page');
    check('flying-pages por src',
        conflictoDe($pagina('<script src="/static/flying-pages.min.js"></script>')) === 'flying-pages');

    echo "\n=== no confundir el texto de la pagina con un script ===\n";
    // "quicklink" en una descripcion de producto no es un conflicto: apartarse
    // por eso dejaria la tienda sin speculation sin motivo.
    check('la palabra en el body no cuenta',
        conflictoDe($pagina('') . '<p>Compra el quicklink original</p>') === null);
    check('tampoco en un titulo',
        conflictoDe('<html><head><title>instant.page review</title></head><body>'
            . '<a href="https://t.test/a.html">a</a></body></html>') === null);

    echo "\n=== nuestras propias reglas no son un conflicto ===\n";
    // Una pagina que ya paso por aqui lleva nuestro marcador Y un script de
    // speculationrules. Sin reconocer el marcador, la segunda pasada la
    // tomaria por conflicto ajeno y el plugin se apartaria de si mismo.
    $nuestro = $pagina(
        '<!-- TE CDN: Contextual Speculation Rules (product) -->'
        . '<script type="speculationrules">{"prerender":[]}</script>'
    );
    check('el script solo ya seria conflicto',
        conflictoDe($pagina('<script type="speculationrules">{"prerender":[]}</script>')) !== null,
        'si esto no diera conflicto, la prueba de abajo no probaria nada');
    check('con nuestro marcador NO lo es', conflictoDe($nuestro) === null,
        'nuestro marcador tiene que reconocerse');

    echo "\n=== al detectar conflicto NO se inyecta ===\n";
    [$o] = hacer();
    $html = $pagina('<script src="/js/quicklink.umd.js"></script>');
    $salida = $o->process($html);
    check('el html sale igual', $salida === $html);
    check('no se anadieron reglas nuestras',
        strpos($salida, 'TE CDN: Contextual Speculation Rules') === false);

    echo "\n=== no se escribe en cada render ===\n";
    [$o, $cache] = hacer();
    $html = $pagina('<script src="/js/quicklink.umd.js"></script>');
    $o->process($html); $o->process($html); $o->process($html);
    check('una sola escritura', $cache->escrituras === 1, (string) $cache->escrituras);

    echo "\n";
    printf("RESULTADO: %d correctas, %d fallos\n", $pass, $fail);
    exit($fail === 0 ? 0 : 1);
}
