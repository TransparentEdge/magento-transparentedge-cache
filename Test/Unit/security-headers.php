<?php
/**
 * Banco de las cabeceras de seguridad que acaban en el VCL generado.
 *
 * Ejercita Model/Config::getSecurityHeaders() de verdad, no un doble: lo
 * que se comprueba aqui son decisiones de criterio, y una decision de
 * criterio que nadie vigila se revierte sola en la siguiente refactorizacion.
 *
 * Uso:  php Test/Unit/security-headers.php
 */
declare(strict_types=1);

namespace Magento\Store\Model {
    if (!interface_exists(ScopeInterface::class)) {
        interface ScopeInterface { public const SCOPE_STORE = 'store'; }
    }
}

namespace Magento\Framework\App\Config {
    if (!interface_exists(ScopeConfigInterface::class)) {
        interface ScopeConfigInterface {
            public function getValue($path, $scopeType = 'default', $scopeCode = null);
            public function isSetFlag($path, $scopeType = 'default', $scopeCode = null);
        }
    }
}

namespace Magento\Framework\App\Cache {
    if (!interface_exists(StateInterface::class)) {
        interface StateInterface {
            public function isEnabled($cacheType);
            public function setEnabled($cacheType, $isEnabled);
            public function persist();
        }
    }
}

namespace Magento\Store\Model {
    if (!interface_exists(StoreManagerInterface::class)) {
        interface StoreManagerInterface {
            public function getStore($storeId = null);
            public function getDefaultStoreView();
            public function getStores($withDefault = false, $codeKey = false);
        }
    }
}

namespace Magento\Framework\Encryption {
    if (!interface_exists(EncryptorInterface::class)) {
        interface EncryptorInterface {
            public function encrypt($data);
            public function decrypt($data);
        }
    }
}

namespace TransparentEdge\CDN\Test {
    final class Scope implements \Magento\Framework\App\Config\ScopeConfigInterface
    {
        public function __construct(private array $v = []) {}
        public function getValue($p, $s = 'default', $c = null) { return $this->v[$p] ?? null; }
        public function isSetFlag($p, $s = 'default', $c = null)
        {
            $x = $this->v[$p] ?? null;

            return $x === true || $x === 1 || $x === '1';
        }
    }
}

namespace {
    require __DIR__ . '/../../Model/Config.php';

    $pass = 0; $fail = 0;
    function check(string $name, bool $ok, string $detail = ''): void {
        global $pass, $fail;
        if ($ok) { $pass++; printf("  OK    %s\n", $name); }
        else { $fail++; printf("  FALLO %s%s\n", $name, $detail !== '' ? "  -> $detail" : ''); }
    }

    /** Construye el Config real con solo el scope sembrado. */
    function cfg(array $valores): \TransparentEdge\CDN\Model\Config {
        $r = new ReflectionClass(\TransparentEdge\CDN\Model\Config::class);
        $o = $r->newInstanceWithoutConstructor();
        $p = $r->getProperty('scopeConfig');
        $p->setAccessible(true);
        $p->setValue($o, new \TransparentEdge\CDN\Test\Scope($valores));

        return $o;
    }

    $P = \TransparentEdge\CDN\Model\Config::SECURITY_PATH;

    echo "=== nada encendido, nada se escribe ===\n";
    check('sin cabeceras', cfg([])->getSecurityHeaders() === []);

    echo "\n=== las inofensivas ===\n";
    $h = cfg([$P . 'nosniff' => 1, $P . 'frame_options' => 1, $P . 'referrer_policy' => 1])->getSecurityHeaders();
    check('nosniff', ($h['X-Content-Type-Options'] ?? '') === 'nosniff');
    check('X-Frame-Options usa SAMEORIGIN por defecto', ($h['X-Frame-Options'] ?? '') === 'SAMEORIGIN',
        $h['X-Frame-Options'] ?? '');
    check('Referrer-Policy por defecto',
        ($h['Referrer-Policy'] ?? '') === 'strict-origin-when-cross-origin', $h['Referrer-Policy'] ?? '');
    $h = cfg([$P . 'frame_options' => 1, $P . 'frame_options_value' => 'DENY'])->getSecurityHeaders();
    check('se respeta el valor configurado', ($h['X-Frame-Options'] ?? '') === 'DENY');

    echo "\n=== HSTS: lo que no se puede deshacer ===\n";
    $h = cfg([$P . 'hsts' => 1])->getSecurityHeaders();
    check('max-age de un ano por defecto',
        ($h['Strict-Transport-Security'] ?? '') === 'max-age=31536000', $h['Strict-Transport-Security'] ?? '');
    $h = cfg([$P . 'hsts' => 1, $P . 'hsts_max_age' => 86400, $P . 'hsts_subdomains' => 1])->getSecurityHeaders();
    check('con subdominios',
        ($h['Strict-Transport-Security'] ?? '') === 'max-age=86400; includeSubDomains',
        $h['Strict-Transport-Security'] ?? '');

    // preload sin includeSubDomains no lo acepta el proceso de alta, asi que
    // enviarlo solo sirve para creerse protegido sin estarlo.
    $h = cfg([$P . 'hsts' => 1, $P . 'hsts_preload' => 1])->getSecurityHeaders();
    check('preload NO sale sin subdominios',
        strpos($h['Strict-Transport-Security'] ?? '', 'preload') === false,
        $h['Strict-Transport-Security'] ?? '');
    $h = cfg([$P . 'hsts' => 1, $P . 'hsts_subdomains' => 1, $P . 'hsts_preload' => 1])->getSecurityHeaders();
    check('con subdominios si sale',
        strpos($h['Strict-Transport-Security'] ?? '', '; preload') !== false,
        $h['Strict-Transport-Security'] ?? '');

    echo "\n=== Cross-Origin-Opener-Policy ===\n";
    check('apagada no sale', !isset(cfg([])->getSecurityHeaders()['Cross-Origin-Opener-Policy']));
    $h = cfg([$P . 'coop' => 1])->getSecurityHeaders();
    // allow-popups y no same-origin: same-origin corta el enlace con la
    // ventana que abre una pasarela de pago o un login social.
    check('por defecto permite popups',
        ($h['Cross-Origin-Opener-Policy'] ?? '') === 'same-origin-allow-popups',
        $h['Cross-Origin-Opener-Policy'] ?? '');
    $h = cfg([$P . 'coop' => 1, $P . 'coop_value' => 'same-origin'])->getSecurityHeaders();
    check('se respeta el valor configurado',
        ($h['Cross-Origin-Opener-Policy'] ?? '') === 'same-origin');

    echo "\n=== ninguna de las de E11 se queda fuera ===\n";
    // El bloque escrito a mano en el VCL de un cliente traia estas siete.
    // Si el plugin no sabe generar alguna, sustituir ese bloque por el
    // generado deja el sitio con menos proteccion que antes, y en silencio.
    $todas = cfg([
        $P . 'nosniff' => 1, $P . 'frame_options' => 1, $P . 'referrer_policy' => 1,
        $P . 'permissions_policy' => 1, $P . 'permissions_policy_value' => 'camera=()',
        $P . 'coop' => 1,
        $P . 'hsts' => 1, $P . 'hsts_subdomains' => 1, $P . 'hsts_preload' => 1,
        $P . 'csp' => 1, $P . 'csp_value' => "default-src 'self'",
    ])->getSecurityHeaders();
    foreach ([
        'Strict-Transport-Security', 'X-Content-Type-Options', 'X-Frame-Options',
        'Referrer-Policy', 'Permissions-Policy', 'Cross-Origin-Opener-Policy',
        'Content-Security-Policy-Report-Only',
    ] as $nombre) {
        check(sprintf('el plugin sabe generar %s', $nombre), isset($todas[$nombre]),
            implode(', ', array_keys($todas)));
    }

    echo "\n=== CSP: en modo informe salvo que se pida lo contrario ===\n";
    // Una politica ligeramente mal deja la tienda sin JavaScript. En modo
    // informe solo llena un log.
    $h = cfg([$P . 'csp' => 1, $P . 'csp_value' => "default-src 'self'"])->getSecurityHeaders();
    check('sale como Report-Only', isset($h['Content-Security-Policy-Report-Only']),
        implode(',', array_keys($h)));
    check('y NO como la que bloquea', !isset($h['Content-Security-Policy']));

    $h = cfg([$P . 'csp' => 1, $P . 'csp_enforce' => 1, $P . 'csp_value' => "default-src 'self'"])->getSecurityHeaders();
    check('solo bloquea si se pide expresamente', isset($h['Content-Security-Policy']),
        implode(',', array_keys($h)));

    echo "\n=== encendida sin politica no se escribe ===\n";
    // Una CSP vacia no protege de nada y confunde al leer el VCL.
    check('CSP sin valor se omite', cfg([$P . 'csp' => 1])->getSecurityHeaders() === []);
    check('Permissions-Policy sin valor se omite',
        cfg([$P . 'permissions_policy' => 1, $P . 'permissions_policy_value' => ''])->getSecurityHeaders() === []);

    echo "\n=== orden estable ===\n";
    // El VCL generado se compara con el desplegado: un orden que baila
    // produce diffs falsos en cada regeneracion.
    $todo = [$P . 'nosniff' => 1, $P . 'frame_options' => 1, $P . 'referrer_policy' => 1, $P . 'hsts' => 1];
    check('mismo orden en dos llamadas',
        array_keys(cfg($todo)->getSecurityHeaders()) === array_keys(cfg($todo)->getSecurityHeaders()));

    echo "\n";
    printf("RESULTADO: %d correctas, %d fallos\n", $pass, $fail);
    exit($fail === 0 ? 0 : 1);
}
