<?php
/**
 * Banco de pruebas de la v2.0.7.
 *
 * Carga las clases REALES del plugin (TagResolver y HeaderManager) sobre
 * dobles de Magento, y ejercita cada correccion de la auditoria.
 */
declare(strict_types=1);

$repo = __DIR__ . '/../..';

require __DIR__ . '/stubs.php';
require __DIR__ . '/stubs-api.php';   // por el doble de CacheInterface
require $repo . '/Model/TagResolver.php';     // clase real, sin dependencias
require $repo . '/Model/CacheProfile.php';    // clase real
require $repo . '/Model/Diagnostics.php';   // HeaderManager sella ahi su sonda
require $repo . '/Model/HeaderManager.php';   // clase real

use TransparentEdge\CDN\Model\CacheProfile;
use TransparentEdge\CDN\Model\Config;
use TransparentEdge\CDN\Model\HeaderManager;
use TransparentEdge\CDN\Model\TagResolver;
use TransparentEdge\CDN\Test\FakeLogger;
use TransparentEdge\CDN\Test\FakeRequest;
use Magento\Framework\App\Response\Http as Response;

$pass = 0; $fail = 0;
function check(string $name, bool $ok, string $detail = ''): void {
    global $pass, $fail;
    if ($ok) { $pass++; printf("  OK    %s\n", $name); }
    else { $fail++; printf("  FALLO %s%s\n", $name, $detail !== '' ? "  -> $detail" : ''); }
}

/**
 * Doble de ScopeConfigInterface para el perfil de cache. Sin valores, el
 * perfil queda desactivado y HeaderManager usa el perfil global de siempre,
 * que es lo que comprueban las pruebas de abajo.
 */
final class HeaderScope implements \Magento\Framework\App\Config\ScopeConfigInterface
{
    public function __construct(private array $v = []) {}
    public function getValue($p, $s = 'default', $c = null) { return $this->v[$p] ?? null; }
    public function isSetFlag($p, $s = 'default', $c = null) { return (bool) $this->getValue($p); }
}

function build(string $uri = '/', string $method = 'GET', string $accion = ''): array {
    $config   = new Config();
    $resolver = new TagResolver();
    $logger   = new FakeLogger();
    $profile  = new CacheProfile(
        new HeaderScope(),
        new \Magento\Framework\App\Request\Http($accion),
        $config
    );
    $cache    = new \TransparentEdge\CDN\Test\FakeCache();
    $manager  = new HeaderManager($config, $resolver, new FakeRequest($method, $uri), $logger, $profile, $cache);
    return [$manager, $config, $logger, $cache];
}

echo "=== A2: el tag FPC se traduce a te-fpc ===\n";
$r = new TagResolver();
check('FPC  -> te-fpc', $r->resolveTag('FPC') === 'te-fpc', var_export($r->resolveTag('FPC'), true));
check('fpc  -> te-fpc', $r->resolveTag('fpc') === 'te-fpc', var_export($r->resolveTag('fpc'), true));

echo "\n=== A3: store_group_N deja de ser rama muerta ===\n";
check('store_group_5 -> store-group-5', $r->resolveTag('store_group_5') === 'store-group-5', var_export($r->resolveTag('store_group_5'), true));
check('store_1 sigue -> store-1',        $r->resolveTag('store_1') === 'store-1', var_export($r->resolveTag('store_1'), true));
check('store_group (global) -> te-store', $r->resolveTag('store_group') === 'te-store', var_export($r->resolveTag('store_group'), true));

echo "\n=== A2/A3: no hay regresion en las tags habituales ===\n";
foreach (['cat_p_123' => 'product-123', 'cat_c_45' => 'category-45', 'cms_p_6' => 'page-6',
          'cms_b_7' => 'block-7', 'cat_p' => 'te-products', 'cat_c' => 'te-categories'] as $in => $exp) {
    check("$in -> $exp", $r->resolveTag($in) === $exp, var_export($r->resolveTag($in), true));
}
$flood = []; for ($i = 0; $i < 2000; $i++) { $flood[] = 'paycomet_token_' . (100000 + $i); }
check('2000 tags de paycomet siguen descartadas', count($r->resolve($flood)) === 0);

echo "\n=== A5: tope de tamano de la cabecera Surrogate-Keys ===\n";
foreach ([12, 36, 300, 600, 1000, 5000] as $n) {
    $tags = ['cat_c_3', 'cat_c', 'cat_p'];
    for ($i = 0; $i < $n; $i++) { $tags[] = 'cat_p_' . (10000 + $i); }
    $hdr = $r->buildSurrogateKeyHeader($tags);
    $len = strlen($hdr);
    $degraded = strpos($hdr, 'te-truncated') !== false;
    printf("  %5d productos -> %6d bytes  %s\n", $n, $len, $degraded ? '(degradada a claves globales)' : '');
    check("  n=$n cabe en el limite", $len <= TagResolver::MAX_HEADER_BYTES);
    if ($degraded) {
        check("  n=$n conserva te-products", strpos($hdr, 'te-products') !== false, $hdr);
        check("  n=$n conserva te-all",      strpos($hdr, 'te-all') !== false, $hdr);
        check("  n=$n no deja claves parciales de producto", strpos($hdr, 'product-') === false, substr($hdr, 0, 120));
    }
}

echo "\n=== A4: una respuesta de error nunca recibe TTL de CDN ===\n";
foreach ([500, 503, 502, 403, 429, 400] as $code) {
    [$m] = build('/alguna-pagina');
    $res = new Response();
    $res->setHttpResponseCode($code);
    $res->setHeader('Content-Type', 'text/html');
    $res->setHeader('X-Magento-Cache-Control', 'max-age=86400, public'); // Magento la marcaba cacheable
    $m->applyHeaders($res);
    $cc = $res->getHeader('Cache-Control');
    $cc = $cc ? $cc->getFieldValue() : '';
    check("HTTP $code -> no-store", strpos($cc, 'no-store') !== false, $cc);
    check("HTTP $code -> sin s-maxage", stripos($cc, 's-maxage') === false, $cc);
}
[$m] = build('/una-categoria.html');
$res = new Response();
$res->setHttpResponseCode(404);
$res->setHeader('Content-Type', 'text/html');
$res->setHeader('X-Magento-Cache-Control', 'max-age=86400, public');
$m->applyHeaders($res);
$cc = $res->getHeader('Cache-Control')->getFieldValue();
check('HTTP 404 sigue siendo cacheable (lo afina el VCL)', stripos($cc, 's-maxage') !== false, $cc);

[$m] = build('/una-categoria.html');
$res = new Response();
$res->setHttpResponseCode(200);
$res->setHeader('Content-Type', 'text/html');
$res->setHeader('X-Magento-Cache-Control', 'max-age=86400, public');
$res->setHeader('X-Magento-Tags', 'cat_c_3,cat_p_10,cat_p_11');
$m->applyHeaders($res);
$cc = $res->getHeader('Cache-Control')->getFieldValue();
$sk = $res->getHeader('Surrogate-Keys')->getFieldValue();
check('HTTP 200 recibe el TTL de HTML', stripos($cc, 's-maxage=172800') !== false, $cc);
check('HTTP 200 recibe sus Surrogate-Keys', $sk === 'category-3 product-10 product-11 te-all', $sk);

echo "\n=== A1: no se pisan las cabeceras de nuestros propios controladores ===\n";
[$m] = build('/transparentedge/speculationRules/index');
$res = new Response();
$res->setHttpResponseCode(200);
$res->setHeader('Content-Type', 'application/speculationrules+json');
$res->setHeader('Cache-Control', 'public, max-age=3600, s-maxage=86400');
$res->setHeader('Surrogate-Keys', 'te-speculation-rules');
$res->setHeader(HeaderManager::HEADER_TE_OWN, '1');
$m->applyHeaders($res);
$h = $res->dumpHeaders();
check('conserva su Surrogate-Keys',    ($h['surrogate-keys'] ?? '') === 'te-speculation-rules', $h['surrogate-keys'] ?? '(ninguna)');
check('conserva su Cache-Control',     ($h['cache-control'] ?? '') === 'public, max-age=3600, s-maxage=86400', $h['cache-control'] ?? '(ninguna)');
check('la marca interna no sale fuera', !isset($h['x-te-own-headers']), implode(',', array_keys($h)));

echo "\n=== A1: una Surrogate-Keys ajena se fusiona, no se destruye ===\n";
[$m] = build('/una-pagina');
$res = new Response();
$res->setHttpResponseCode(200);
$res->setHeader('Content-Type', 'text/html');
$res->setHeader('X-Magento-Cache-Control', 'max-age=86400, public');
$res->setHeader('X-Magento-Tags', 'cat_p_99');
$res->setHeader('Surrogate-Keys', 'modulo-de-un-tercero');
$m->applyHeaders($res);
$sk = $res->getHeader('Surrogate-Keys')->getFieldValue();
check('conserva la clave ajena', strpos($sk, 'modulo-de-un-tercero') !== false, $sk);
check('anade las nuestras',      strpos($sk, 'product-99') !== false && strpos($sk, 'te-all') !== false, $sk);

echo "\n=== B7: un fallo deja rastro en vez de desaparecer ===\n";
$config   = new Config();
$logger   = new FakeLogger();
$roto     = new class extends TagResolver {
    public function buildSurrogateKeyHeader(array $magentoTags): string {
        throw new \RuntimeException('fallo simulado del resolutor');
    }
};
$m = new HeaderManager(
    $config,
    $roto,
    new FakeRequest('GET', '/x'),
    $logger,
    new CacheProfile(new HeaderScope(), new \Magento\Framework\App\Request\Http(''), $config),
    new \TransparentEdge\CDN\Test\FakeCache()
);
$res = new Response();
$res->setHttpResponseCode(200);
$res->setHeader('Content-Type', 'text/html');
$res->setHeader('X-Magento-Tags', 'cat_p_1');
$m->applyHeaders($res);   // no debe propagar
check('no propaga la excepcion', true);
check('registra un warning', count($logger->lines) === 1 && $logger->lines[0][0] === 'warning',
    json_encode($logger->lines));
check('el mensaje nombra la excepcion',
    ($logger->lines[0][2]['error'] ?? '') === 'fallo simulado del resolutor',
    json_encode($logger->lines[0][2] ?? []));

echo "\n=== el perfil por tipo de pagina llega a la respuesta real ===\n";
$config  = new Config();
$profile = new CacheProfile(
    new HeaderScope(['transparentedge/cache_profiles/enabled' => 1]),
    new \Magento\Framework\App\Request\Http('cms_index_index'),
    $config
);
$m = new HeaderManager($config, new TagResolver(), new FakeRequest('GET', '/'), new FakeLogger(), $profile,
    new \TransparentEdge\CDN\Test\FakeCache());
$res = new Response();
$res->setHttpResponseCode(200);
$res->setHeader('Content-Type', 'text/html');
$res->setHeader('X-Magento-Cache-Control', 'max-age=86400, public');
$res->setHeader('X-Magento-Tags', 'cat_p,cms_p_2');
$m->applyHeaders($res);
$cc = $res->getHeader('Cache-Control')->getFieldValue();
printf("  portada: %s\n", $cc);
check('la portada recibe s-maxage=300', str_contains($cc, 's-maxage=300'), $cc);
check('conserva el stale-while-revalidate largo', str_contains($cc, 'stale-while-revalidate=86400'), $cc);
check('sigue emitiendo sus Surrogate-Keys',
    $res->getHeader('Surrogate-Keys')->getFieldValue() === 'page-2 te-all te-products',
    $res->getHeader('Surrogate-Keys')->getFieldValue());

// Una categoria, con el mismo perfil activo, no debe cambiar.
$profile = new CacheProfile(
    new HeaderScope(['transparentedge/cache_profiles/enabled' => 1]),
    new \Magento\Framework\App\Request\Http('catalog_category_view'),
    $config
);
$m = new HeaderManager($config, new TagResolver(), new FakeRequest('GET', '/c.html'), new FakeLogger(), $profile,
    new \TransparentEdge\CDN\Test\FakeCache());
$res = new Response();
$res->setHttpResponseCode(200);
$res->setHeader('Content-Type', 'text/html');
$res->setHeader('X-Magento-Cache-Control', 'max-age=86400, public');
$m->applyHeaders($res);
$cc = $res->getHeader('Cache-Control')->getFieldValue();
printf("  categoria: %s\n", $cc);
check('la categoria recibe su propio s-maxage=3600', str_contains($cc, 's-maxage=3600'), $cc);


// ──────────────────────────────────────────────
// Sonda de lo que impide cachear
//
// Existe por lo que tardamos en ver el fallo anterior: el plugin emitia
// cabeceras publicas correctas y el edge no cacheaba nada, porque cada
// respuesta llevaba un Set-Cookie. Desde dentro de Magento todo parecia
// bien. Ahora el origen se da cuenta solo.
// ──────────────────────────────────────────────

echo "\n=== la sonda registra una respuesta cacheable limpia ===\n";
[$m, $cfg, $log, $cache] = build('/');
$res = new Response();
$res->setHttpResponseCode(200);
$res->setHeader('Content-Type', 'text/html');
$res->setHeader('X-Magento-Cache-Control', 'max-age=86400, public');
$res->setHeader('Vary', 'Accept-Encoding');
$m->applyHeaders($res);
$lectura = json_decode((string) $cache->load(\TransparentEdge\CDN\Model\Diagnostics::CACHE_BLOCKERS_KEY), true);
check('queda lectura', is_array($lectura), var_export($lectura, true));
check('sin cookies', ($lectura['cookies'] ?? null) === [], json_encode($lectura['cookies'] ?? null));
check('con el Vary del origen', ($lectura['vary'] ?? '') === 'Accept-Encoding', $lectura['vary'] ?? '');

echo "\n=== ve el Set-Cookie de la respuesta ===\n";
[$m, $cfg, $log, $cache] = build('/');
$res = new Response();
$res->setHttpResponseCode(200);
$res->setHeader('Content-Type', 'text/html');
$res->setHeader('X-Magento-Cache-Control', 'max-age=86400, public');
$res->setHeader('Set-Cookie', 'PHPSESSID=abc123def456; path=/; HttpOnly');
$m->applyHeaders($res);
$lectura = json_decode((string) $cache->load(\TransparentEdge\CDN\Model\Diagnostics::CACHE_BLOCKERS_KEY), true);
check('detecta la cookie', ($lectura['cookies'] ?? []) === ['PHPSESSID'], json_encode($lectura['cookies'] ?? []));
check('NO guarda el valor de la cookie',
    strpos(json_encode($lectura), 'abc123def456') === false,
    'un id de sesion es una credencial: la lectura se muestra en el panel y en /health');

echo "\n=== una respuesta NO cacheable no deja lectura ===\n";
// Un 500 o una ruta excluida salen por setNoCacheHeaders antes de la sonda:
// ahi un Set-Cookie es correcto y no debe dar la alarma.
[$m, $cfg, $log, $cache] = build('/');
$res = new Response();
$res->setHttpResponseCode(500);
$res->setHeader('Content-Type', 'text/html');
$res->setHeader('Set-Cookie', 'PHPSESSID=xyz; path=/');
$m->applyHeaders($res);
check('no hay lectura', $cache->load(\TransparentEdge\CDN\Model\Diagnostics::CACHE_BLOCKERS_KEY) === false);

echo "\n=== no se escribe en cada peticion ===\n";
[$m, $cfg, $log, $cache] = build('/');
$hacer = function () use ($m) {
    $r = new Response();
    $r->setHttpResponseCode(200);
    $r->setHeader('Content-Type', 'text/html');
    $r->setHeader('X-Magento-Cache-Control', 'max-age=86400, public');
    $r->setHeader('Vary', 'Accept-Encoding');
    $m->applyHeaders($r);
};
$hacer();
$primera = json_decode((string) $cache->load(\TransparentEdge\CDN\Model\Diagnostics::CACHE_BLOCKERS_KEY), true);
$escrituras = $cache->escrituras ?? null;
$hacer(); $hacer(); $hacer();
$ultima = json_decode((string) $cache->load(\TransparentEdge\CDN\Model\Diagnostics::CACHE_BLOCKERS_KEY), true);
check('la lectura no cambia si el cuadro es el mismo',
    $primera['at'] === $ultima['at'], sprintf('%s -> %s', $primera['at'], $ultima['at']));


echo "\n";
printf("RESULTADO: %d correctas, %d fallos\n", $pass, $fail);
exit($fail === 0 ? 0 : 1);