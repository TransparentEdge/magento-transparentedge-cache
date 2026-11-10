<?php
/**
 * Banco de pruebas de Model/CacheProfile.
 *
 * El perfil por tipo de pagina es la mitigacion del fallo de block_html:
 * cuando el HTML de un bloque sale de esa cache, getIdentities() devuelve
 * menos identidades y la portada cacheada en el edge se queda sin las claves
 * de producto. Con ventana fresca corta y stale-while-revalidate largo, la
 * portada se refresca sola sin depender de que el tag la alcance.
 *
 * Uso:  php Test/Unit/cache-profile.php
 */
declare(strict_types=1);

$repo = __DIR__ . '/../..';

require __DIR__ . '/stubs.php';
require $repo . '/Model/CacheProfile.php';

use TransparentEdge\CDN\Model\CacheProfile;

$pass = 0; $fail = 0;
function check(string $name, bool $ok, string $detail = ''): void {
    global $pass, $fail;
    if ($ok) { $pass++; printf("  OK    %s\n", $name); }
    else { $fail++; printf("  FALLO %s%s\n", $name, $detail !== '' ? "  -> $detail" : ''); }
}

final class ProfileScope implements \Magento\Framework\App\Config\ScopeConfigInterface
{
    public function __construct(private array $v = []) {}
    public function getValue($p, $s = 'default', $c = null) { return $this->v[$p] ?? null; }
    public function isSetFlag($p, $s = 'default', $c = null) { return (bool) $this->getValue($p); }
}

function profile(string $accion, array $extra = [], bool $enabled = true): CacheProfile {
    $valores = ['transparentedge/cache_profiles/enabled' => $enabled ? 1 : 0] + $extra;
    return new CacheProfile(
        new ProfileScope($valores),
        new \Magento\Framework\App\Request\Http($accion),
        new \TransparentEdge\CDN\Model\Config()
    );
}

/** Extrae una directiva del Cache-Control. */
function dcc(string $cc, string $nombre): ?int {
    return preg_match('/\b' . preg_quote($nombre, '/') . '=(\d+)/', $cc, $m) ? (int) $m[1] : null;
}

echo "=== deteccion del tipo de pagina ===\n";
foreach ([
    'cms_index_index'       => CacheProfile::TYPE_HOME,
    'catalog_category_view' => CacheProfile::TYPE_CATEGORY,
    'catalog_product_view'  => CacheProfile::TYPE_PRODUCT,
    'cms_page_view'         => CacheProfile::TYPE_CMS,
    'checkout_cart_index'   => CacheProfile::TYPE_DEFAULT,
    'customer_account_login'=> CacheProfile::TYPE_DEFAULT,
    ''                      => CacheProfile::TYPE_DEFAULT,
] as $accion => $esperado) {
    $got = profile($accion)->getPageType();
    check(sprintf('%-24s -> %s', $accion !== '' ? $accion : '(vacia)', $esperado), $got === $esperado, $got);
}

echo "\n=== la portada: ventana fresca corta, ventana rancia larga ===\n";
$cc = profile('cms_index_index')->buildHtmlCacheControl();
printf("  %s\n", $cc);
check('s-maxage = 300', dcc($cc, 's-maxage') === 300, (string) dcc($cc, 's-maxage'));
check('stale-while-revalidate = 86400', dcc($cc, 'stale-while-revalidate') === 86400, (string) dcc($cc, 'stale-while-revalidate'));
check('es publica', str_contains($cc, 'public'));
check('stale-if-error se hereda del global', dcc($cc, 'stale-if-error') === 86400, (string) dcc($cc, 'stale-if-error'));
check('max-age del navegador no supera al del edge',
    dcc($cc, 'max-age') !== null && dcc($cc, 'max-age') <= dcc($cc, 's-maxage'),
    'max-age=' . dcc($cc, 'max-age') . ' s-maxage=' . dcc($cc, 's-maxage'));

echo "\n=== cada tipo con su propio perfil ===\n";
$global = (new \TransparentEdge\CDN\Model\Config())->buildHtmlCacheControl();
foreach ([
    'catalog_category_view' => 3600,
    'catalog_product_view'  => 86400,
    'cms_page_view'         => 172800,
] as $accion => $esperado) {
    $cc = profile($accion)->buildHtmlCacheControl();
    printf("  %-24s %s\n", $accion, $cc);
    check("$accion -> s-maxage=$esperado", dcc($cc, 's-maxage') === $esperado, (string) dcc($cc, 's-maxage'));
    // La ventana rancia siempre debe cubrir de sobra la fresca.
    check("$accion: swr >= ttl", dcc($cc, 'stale-while-revalidate') >= dcc($cc, 's-maxage'),
        sprintf('swr=%d ttl=%d', dcc($cc, 'stale-while-revalidate'), dcc($cc, 's-maxage')));
}
$cc = profile('checkout_cart_index')->buildHtmlCacheControl();
check('un tipo no contemplado mantiene el perfil global', $cc === $global, $cc);

echo "\n=== la portada es la mas corta de las cuatro ===\n";
$ttls = [];
foreach (['cms_index_index', 'catalog_category_view', 'catalog_product_view', 'cms_page_view'] as $accion) {
    $ttls[$accion] = dcc(profile($accion)->buildHtmlCacheControl(), 's-maxage');
}
check('la portada tiene el TTL mas bajo', $ttls['cms_index_index'] === min($ttls), json_encode($ttls));
check('las paginas CMS el mas alto', $ttls['cms_page_view'] === max($ttls), json_encode($ttls));

echo "\n=== con los perfiles desactivados todo vuelve al global ===\n";
foreach (['cms_index_index', 'catalog_category_view'] as $accion) {
    $cc = profile($accion, [], false)->buildHtmlCacheControl();
    check("$accion usa el perfil global", $cc === $global, $cc);
}

echo "\n=== valores configurados a mano ===\n";
$cc = profile('catalog_category_view', [
    'transparentedge/cache_profiles/category_ttl' => 600,
    'transparentedge/cache_profiles/category_swr' => 7200,
])->buildHtmlCacheControl();
printf("  %s\n", $cc);
check('s-maxage = 600', dcc($cc, 's-maxage') === 600, (string) dcc($cc, 's-maxage'));
check('swr = 7200', dcc($cc, 'stale-while-revalidate') === 7200, (string) dcc($cc, 'stale-while-revalidate'));

echo "\n=== un TTL a cero vuelve al global, no cachea cero segundos ===\n";
$cc = profile('cms_index_index', ['transparentedge/cache_profiles/home_ttl' => 0])->buildHtmlCacheControl();
check('cae al perfil global', $cc === $global, $cc);

echo "\n=== un swr vacio hereda, pero el ttl propio se respeta ===\n";
$cc = profile('cms_index_index', [
    'transparentedge/cache_profiles/home_ttl' => 120,
    'transparentedge/cache_profiles/home_swr' => '',
])->buildHtmlCacheControl();
printf("  %s\n", $cc);
check('s-maxage = 120', dcc($cc, 's-maxage') === 120, (string) dcc($cc, 's-maxage'));
check('swr heredado = 86400', dcc($cc, 'stale-while-revalidate') === 86400, (string) dcc($cc, 'stale-while-revalidate'));

echo "\n=== el escenario que motiva todo esto ===\n";
// La portada cacheada en el edge sin claves de producto: con el perfil, el
// objeto deja de estar fresco a los 5 minutos aunque ninguna invalidacion
// lo alcance, y el visitante no espera nunca gracias al swr.
$p = profile('cms_index_index');
$cc = $p->buildHtmlCacheControl();
$ttl = dcc($cc, 's-maxage');
$swr = dcc($cc, 'stale-while-revalidate');
check('la portada deja de estar fresca en 10 minutos o menos', $ttl !== null && $ttl <= 600, (string) $ttl);
check('la ventana rancia cubre al menos una hora', $swr !== null && $swr >= 3600, (string) $swr);
check('la ventana rancia es mucho mayor que la fresca', $ttl > 0 && $swr / $ttl >= 10,
    sprintf('swr/ttl = %.1f', $ttl > 0 ? $swr / $ttl : 0));

echo "\n";
printf("RESULTADO: %d correctas, %d fallos\n", $pass, $fail);
exit($fail === 0 ? 0 : 1);
