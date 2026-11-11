<?php
/**
 * Estructura del VCL generado.
 *
 * No valida VCL (eso requiere varnishd), pero si comprueba lo que se nos ha
 * roto de verdad alguna vez: llaves descompensadas, la version anunciada, y
 * que ninguna respuesta de error pueda quedarse cacheada en el edge.
 *
 * Uso:  php dev/tests/vcl-structure.php
 */
declare(strict_types=1);

namespace TransparentEdge\CDN\Model {
    /** Doble de Config con los valores por defecto de etc/config.xml. */
    class Config {
        public const VERSION = '2.0.7';
        public const ADMIN_COOKIE_NAME = 'te-admin-bypass';
        public const ADMIN_COOKIE_VALUE = '1';
        public function getBaseUrl(): string { return 'https://tienda.test'; }
        public function getDashboardUrl(): string { return 'https://dashboard.transparentcdn.com/0/invalidation'; }
        public function getExcludeUrls(): array { return []; }
        public function getHtmlTtl(): int { return 172800; }
        public function getHtmlBrowserTtl(): int { return 3600; }
        public function getStaticTtl(): int { return 2592000; }
        public function getStaticBrowserTtl(): int { return 86400; }
        public function getStaleWhileRevalidate(): int { return 86400; }
        public function getStaleIfError(): int { return 86400; }
        public function isAdminBypassEnabled(): bool { return true; }
        public function isI3Enabled(): bool { return true; }
        public function getI3Quality(): int { return 85; }
        public function getI3MaxWidth(): int { return 2560; }
        public function isI3AutoWebp(): bool { return true; }
        public function isSpeculationEnabled(): bool { return true; }
        public function getSpeculationInjection(): string { return 'vcl'; }
        public function getSpeculationRulesUrl(): string { return 'https://tienda.test/transparentedge/speculationRules/index'; }
    }
}

namespace {
    require __DIR__ . '/../../Model/VclGenerator.php';

    $pass = 0; $fail = 0;
    $check = function (string $name, bool $ok, string $detail = '') use (&$pass, &$fail): void {
        if ($ok) { $pass++; printf("  OK    %s\n", $name); }
        else { $fail++; printf("  FALLO %s%s\n", $name, $detail !== '' ? "  -> $detail" : ''); }
    };

    $gen = new TransparentEdge\CDN\Model\VclGenerator(new TransparentEdge\CDN\Model\Config());
    $vcl = $gen->generateFull();

    echo "=== estructura ===\n";
    $check('las llaves estan compensadas', substr_count($vcl, '{') === substr_count($vcl, '}'),
        sprintf('%d abiertas / %d cerradas', substr_count($vcl, '{'), substr_count($vcl, '}')));
    $check('los parentesis estan compensados', substr_count($vcl, '(') === substr_count($vcl, ')'),
        sprintf('%d / %d', substr_count($vcl, '('), substr_count($vcl, ')')));
    // Esto comprueba que el generador LEE Config::VERSION en vez de llevar la
    // version escrita a mano (ya nos paso: el VCL seguia diciendo 1.0 tras
    // varias releases). Que Config::VERSION coincida con el tag de git lo
    // comprueba dev/tests/packaging.php, que si ve el fichero real.
    $check('toma la version de Config::VERSION, no de una cadena fija',
        (bool) preg_match('/Plugin version: ' . preg_quote(TransparentEdge\CDN\Model\Config::VERSION, '/') . '\s/', $vcl),
        'el VCL no anuncia la version que expone Config');

    // ──────────────────────────────────────────────────────────
    // Reglas de plataforma, portadas del linter de George
    // (george~g2/scripts/vcl_linter.py y references/vcl-filosofia.md).
    //
    // Ese linter vive en el plugin de George y no corre en GitHub Actions,
    // asi que sus reglas se comprueban aqui. Todas estas salieron de fallos
    // REALES que llegamos a desplegar; no son estilo.
    // ──────────────────────────────────────────────────────────

    echo "\n=== anti-resiliencia: PROHIBIDO (lo bloquea el linter) ===\n";
    // Forzar ttl 0 / no-store en un error impide servir grace y
    // stale-if-error justo durante una caida del origen, y manda a cada
    // visitante al origen que ya esta fallando. Lo escribimos una vez
    // creyendo que endurecia el VCL. La plataforma ya no cachea errores.
    $check('sin rama que fuerce no-store en >= 400',
        preg_match('/beresp\.status\s*>=\s*400[^}]*(no-store|beresp\.ttl\s*=\s*0)/s', $vcl) === 0,
        'anti-resiliencia: quita la rama o usa un TTL corto POSITIVO');
    $check('ningun beresp.ttl = 0s en todo el VCL',
        strpos($vcl, 'beresp.ttl = 0s') === false);
    $check('ningun set beresp.uncacheable',
        strpos($vcl, 'set beresp.uncacheable') === false,
        'marcar uncacheable a mano sobra: la plataforma ya no cachea Set-Cookie ni errores');
    $check('sin beresp.grace (lo gestiona la plataforma)',
        strpos($vcl, 'beresp.grace') === false);

    echo "\n=== respetamos el Cache-Control del origen ===\n";
    // Regla 1 de George: somos una CDN, el origen decide. El plugin ya manda
    // un Cache-Control por tipo de pagina; si el VCL lo reescribe, ese perfil
    // no llega nunca al edge. Y una pagina que el origen marco no-store tiene
    // que seguir siendo no-store: la guarda vieja miraba solo "s-maxage", asi
    // que un no-store (que no lo lleva) se sobrescribia con un TTL publico.
    foreach (['# HTML pages', '# Static assets'] as $titulo) {
        $i = strpos($vcl, $titulo);
        $bloque = $i !== false ? substr($vcl, $i, 1400) : '';
        $guarda = strpos($bloque, 'if (!beresp.http.Cache-Control)');
        $setCc  = strpos($bloque, 'set beresp.http.Cache-Control');
        $setTtl = strpos($bloque, 'set beresp.ttl');
        $check(sprintf('%s: guarda por ausencia de Cache-Control', $titulo), $guarda !== false,
            'tiene que ser !beresp.http.Cache-Control, no una guarda por s-maxage');
        $check(sprintf('%s: el Cache-Control se fija dentro de la guarda', $titulo),
            $guarda !== false && $setCc !== false && $setCc > $guarda);
        $check(sprintf('%s: el beresp.ttl se fija dentro de la guarda', $titulo),
            $guarda !== false && $setTtl !== false && $setTtl > $guarda);
    }
    $check('no queda ninguna guarda por s-maxage',
        strpos($vcl, 'Cache-Control !~ "s-maxage"') === false,
        'esa guarda deja pasar un no-store y lo sobrescribe con un TTL publico');
    $check('el 404 solo pone TTL si el origen callo',
        strpos($vcl, 'beresp.status == 404 && !beresp.http.Cache-Control') !== false);

    echo "\n=== Set-Cookie: lo unico que hace que se cachee algo ===\n";
    // Magento manda PHPSESSID en cada HTML y la plataforma no cachea una
    // respuesta con Set-Cookie: medido en toysrus, tp-cache=pass y
    // tcdn-hits=0 en todas las peticiones.
    $i = strpos($vcl, '# HTML pages');
    $fin = strpos($vcl, '# i3 images', (int) $i);
    $bloqueHtml = $i !== false ? substr($vcl, $i, ($fin !== false ? $fin - $i : 2600)) : '';
    $check('el HTML cacheable limpia Set-Cookie',
        strpos($bloqueHtml, 'cookieplus.setcookie_delete_regex(".*")') !== false);
    $guardaUnc = strpos($bloqueHtml, 'if (!beresp.uncacheable)');
    $limpia    = strpos($bloqueHtml, 'cookieplus.setcookie_delete_regex(".*")');
    $check('y solo cuando la respuesta es cacheable',
        $guardaUnc !== false && $limpia !== false && $limpia > $guardaUnc,
        'sin la guarda, una pagina pasada pierde la sesion y el carrito deja de persistir');

    echo "\n=== codigo que sobra ===\n";
    // El linter lo marca como "codigo que sobra": la plataforma ya lo hace.
    $check('sin bypass por metodo (solo se cachea GET/HEAD)',
        strpos($vcl, 'req.method !~ "^(GET|HEAD)$"') === false);
    // Ojo: la palabra PHPSESSID aparece en un comentario del VCL que explica
    // por que NO se bypasea. Lo que se prohibe es la regla, no la palabra.
    $check('sin bypass por PHPSESSID',
        preg_match('/req\.http\.Cookie\s*~\s*"\(?PHPSESSID/', $vcl) === 0,
        'una GET con cookie se cachea; lo que no se cachea es la respuesta con Set-Cookie');
    $check('catalogsearch ya no se bypasea',
        strpos($vcl, 'catalogsearch') === false,
        'las paginas de busqueda son cacheables; bypasearlas regalaba offload');

    echo "\n=== bypass que SI mantenemos, a proposito ===\n";
    $check('cookie de admin', strpos($vcl, TransparentEdge\CDN\Model\Config::ADMIN_COOKIE_NAME) !== false);
    $check('rutas personalizadas', strpos($vcl, '^/(checkout|customer|wishlist') !== false);

    echo "\n=== higiene de cabeceras en deliver ===\n";
    foreach ([
        'Surrogate-Keys'  => 'unset resp.http.Surrogate-Keys;',
        'X-Magento-Tags'  => 'unset resp.http.X-Magento-Tags;',
    ] as $nombre => $aguja) {
        $check(sprintf('no se publica %s', $nombre), strpos($vcl, $aguja) !== false);
    }
    // Las de depuracion SI salen: solo existen con el modo depuracion
    // encendido, y existen justamente para leerlas desde fuera, en el edge.
    $check('las cabeceras de depuracion no se quitan',
        strpos($vcl, 'unset resp.http.X-TE-Page-Type') === false,
        'quitarlas deja el modo depuracion util solo contra el origen');

    echo "\n=== restricciones duras de la plataforma ===\n";
    foreach ([
        'sin vcl 4.1'            => '/^\s*vcl\s+4\.\d\s*;/m',
        'sin import'             => '/^\s*import\s+\w+/m',
        'sin return()'           => '/\breturn\s*\(/',
        'sin definicion de ACLs' => '/^\s*acl\s+\w+\s*\{/m',
    ] as $nombre => $patron) {
        $check($nombre, preg_match($patron, $vcl) === 0);
    }
    // Solo valen las funciones call de la plataforma.
    preg_match_all('/call\s+([a-z_]+)\s*;/', $vcl, $m);
    $permitidas = ['deny_request', 'redirect_request', 'redirect_https', 'bypass_cache',
                   'rate_limit', 'under_attack', 'show_captcha', 'show_jschallenge'];
    $invalidas = array_diff(array_unique($m[1] ?? []), $permitidas);
    $check('todas las funciones call son de la plataforma', $invalidas === [],
        implode(', ', $invalidas));
    // Y solo las subrutinas permitidas.
    preg_match_all('/sub\s+(vcl_[a-z_]+)\s*\{/', $vcl, $m2);
    $invalidasSub = array_diff(array_unique($m2[1] ?? []),
        ['vcl_recv', 'vcl_backend_fetch', 'vcl_backend_response', 'vcl_deliver']);
    $check('todas las subrutinas son de la plataforma', $invalidasSub === [],
        implode(', ', $invalidasSub));

    echo "\n";
    printf("RESULTADO: %d correctas, %d fallos\n", $pass, $fail);
    exit($fail === 0 ? 0 : 1);
}
