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

    echo "\n=== ninguna respuesta de error se cachea ===\n";
    foreach ([
        'rama para 4xx y 5xx'       => 'beresp.status >= 400 && beresp.status != 404',
        'los marca uncacheable'     => 'set beresp.uncacheable = true;',
        'rama para 3xx'             => 'beresp.status >= 300 && beresp.status < 400',
        'rama de 404 con TTL corto' => 'beresp.status == 404',
        'HTML respeta el s-maxage del origen' => 'beresp.http.Cache-Control !~ "s-maxage"',
    ] as $nombre => $aguja) {
        $check($nombre, strpos($vcl, $aguja) !== false);
    }

    // El 404 tiene su propio TTL corto; si se evaluase DESPUES del bloque
    // generico de errores, ese bloque lo marcaria uncacheable y perderiamos
    // la proteccion contra tormentas de 404.
    $p404 = strpos($vcl, 'beresp.status == 404');
    $perr = strpos($vcl, 'beresp.status >= 400 && beresp.status != 404');
    $check('el 404 se evalua antes que el bloque generico de errores',
        $p404 !== false && $perr !== false && $p404 < $perr);

    echo "\n=== el edge no pisa el TTL que manda el origen ===\n";
    // Los perfiles por tipo de pagina viven en el origen: la portada sale con
    // s-maxage=300 y el resto con el global. Si el VCL fijase Cache-Control o
    // beresp.ttl sin condicion, ese perfil no llegaria nunca al edge y la
    // mitigacion del fallo de block_html seria inutil.
    $i = strpos($vcl, '# HTML pages');
    $bloqueHtml = $i !== false ? substr($vcl, $i, 900) : '';
    $guarda = strpos($bloqueHtml, 'if (beresp.http.Cache-Control !~ "s-maxage")');
    $setCc  = strpos($bloqueHtml, 'set beresp.http.Cache-Control');
    $setTtl = strpos($bloqueHtml, 'set beresp.ttl');
    $check('el bloque HTML tiene la guarda de s-maxage', $guarda !== false);
    $check('el Cache-Control solo se fija dentro de la guarda',
        $guarda !== false && $setCc !== false && $setCc > $guarda);
    $check('el beresp.ttl solo se fija dentro de la guarda',
        $guarda !== false && $setTtl !== false && $setTtl > $guarda,
        'un beresp.ttl incondicional anularia el s-maxage del origen');

    echo "\n=== bypass basico ===\n";
    foreach ([
        'metodos no GET/HEAD'   => 'req.method !~ "^(GET|HEAD)$"',
        'cookie de admin'       => TransparentEdge\CDN\Model\Config::ADMIN_COOKIE_NAME,
        'rutas no cacheables'   => '^/(checkout|customer|wishlist',
    ] as $nombre => $aguja) {
        $check($nombre, strpos($vcl, $aguja) !== false);
    }

    echo "\n";
    printf("RESULTADO: %d correctas, %d fallos\n", $pass, $fail);
    exit($fail === 0 ? 0 : 1);
}
