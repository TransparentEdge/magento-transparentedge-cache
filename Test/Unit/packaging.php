<?php
/**
 * Comprobaciones de empaquetado y distribucion.
 *
 * Cada una de estas corresponde a un fallo que YA nos ocurrio con un cliente:
 *  - el campo "version" en composer.json fijaba todas las releases a la misma
 *    (el cliente nunca recibia las correcciones),
 *  - un XML mal formado o un parche versionado por error,
 *  - Config::VERSION anunciando una version distinta a la del tag,
 *  - un "use Magento\X" sin su entrada en require.
 *
 * Uso:  php dev/tests/packaging.php  [tag]
 */
declare(strict_types=1);

$root = dirname(__DIR__, 2);
$expectedTag = $argv[1] ?? getenv('TE_EXPECTED_TAG') ?: '';

$pass = 0; $fail = 0;
function check(string $name, bool $ok, string $detail = ''): void {
    global $pass, $fail;
    if ($ok) { $pass++; printf("  OK    %s\n", $name); }
    else { $fail++; printf("  FALLO %s%s\n", $name, $detail !== '' ? "  -> $detail" : ''); }
}

echo "=== composer.json ===\n";
$composerRaw = file_get_contents($root . '/composer.json');
$composer = json_decode($composerRaw, true);
check('es JSON valido', $composer !== null, json_last_error_msg());
check('NO declara un campo "version"', !isset($composer['version']),
    'un campo version anula el tag de git y pina todas las releases a ese numero');
check('declara require', !empty($composer['require']));

echo "\n=== sintaxis PHP ===\n";
$php = [];
$it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS));
foreach ($it as $f) {
    $p = $f->getPathname();
    if (strpos($p, '/.git/') !== false) { continue; }
    if (preg_match('/\.(php|phtml)$/', $p)) { $php[] = $p; }
}
$bad = [];
foreach ($php as $p) {
    exec('php -l ' . escapeshellarg($p) . ' 2>&1', $out, $rc);
    if ($rc !== 0) { $bad[] = basename($p); }
    $out = [];
}
check(sprintf('php -l pasa en los %d ficheros', count($php)), $bad === [], implode(', ', $bad));

echo "\n=== XML ===\n";
$xmlBad = [];
foreach (glob($root . '/etc/**/*.xml') ?: [] as $x) { $xmlFiles[] = $x; }
$xmlFiles = array_merge(
    glob($root . '/etc/*.xml') ?: [],
    glob($root . '/etc/*/*.xml') ?: [],
    glob($root . '/view/*/layout/*.xml') ?: []
);
foreach ($xmlFiles as $x) {
    libxml_use_internal_errors(true);
    if (simplexml_load_file($x) === false) { $xmlBad[] = basename($x); }
    libxml_clear_errors();
}
check(sprintf('los %d XML estan bien formados', count($xmlFiles)), $xmlBad === [], implode(', ', $xmlBad));

echo "\n=== higiene del repositorio ===\n";
$patches = array_merge(glob($root . '/*.patch') ?: [], glob($root . '/*/*.patch') ?: []);
check('no hay ficheros .patch versionados', $patches === [], implode(', ', array_map('basename', $patches)));
check('existe registration.php', file_exists($root . '/registration.php'));
// Se comprueba el ATRIBUTO, no la palabra: etc/module.xml menciona
// setup_version en el comentario que explica por que no lo declara.
libxml_use_internal_errors(true);
$moduleXml = simplexml_load_file($root . '/etc/module.xml');
libxml_clear_errors();
$hasSetupVersion = false;
if ($moduleXml !== false) {
    foreach ($moduleXml->xpath('//module') ?: [] as $node) {
        if (isset($node['setup_version'])) { $hasSetupVersion = true; }
    }
}
check('etc/module.xml NO declara el atributo setup_version', !$hasSetupVersion,
    'setup_version hace que Magento compare codigo contra BD y tumbe la tienda tras un rollback');

// setup:di:compile incluye TODOS los .php bajo app/code/<Vendor>/<Module>,
// y solo excluye dos rutas, ancladas en la raiz del modulo:
//     #^<base>/<Vendor>/<Module>/Test#
//     #^<base>/<Vendor>/<Module>/tests#
// (setup/src/Magento/Setup/Console/Command/DiCompileCommand.php)
//
// Un fichero fuera de ahi que declare un espacio de nombres ajeno tumba la
// compilacion con "Cannot declare class X, because the name is already in
// use". Nos paso con los dobles de prueba en dev/tests: por eso viven en
// Test/Unit y por eso existe esta comprobacion.
$intrusos = [];
foreach ($php as $p) {
    $rel = ltrim(str_replace($root, '', $p), '/');
    if (strpos($rel, 'Test/') === 0 || strpos($rel, 'tests/') === 0) { continue; }
    $src = (string) file_get_contents($p);
    if (preg_match_all('/^\s*namespace\s+((?!TransparentEdge\\\\)[A-Za-z_][A-Za-z0-9_\\\\]*)/m', $src, $m)) {
        foreach ($m[1] as $ns) { $intrusos[] = $rel . ' declara ' . $ns; }
    }
}
check('ningun .php fuera de Test/ declara un espacio de nombres ajeno',
    $intrusos === [], implode('; ', $intrusos));

echo "\n=== dependencias declaradas ===\n";
$map = static function (string $ns): ?string {
    if (!preg_match('/^Magento\\\\([A-Za-z0-9]+)/', $ns, $m)) { return null; }
    $mod = $m[1];
    if ($mod === 'Framework') { return 'magento/framework'; }
    $kebab = strtolower(preg_replace('/(?<!^)[A-Z]/', '-$0', $mod));
    return 'magento/module-' . $kebab;
};
$needed = [];
foreach ($php as $p) {
    if (preg_match_all('/^use\s+(Magento\\\\[A-Za-z0-9_\\\\]+)/m', (string) file_get_contents($p), $m)) {
        foreach ($m[1] as $ns) {
            $pkg = $map($ns);
            if ($pkg !== null) { $needed[$pkg][] = basename($p); }
        }
    }
}
ksort($needed);
$declared = array_keys($composer['require'] ?? []);
$missing = [];
foreach ($needed as $pkg => $users) {
    if (!in_array($pkg, $declared, true)) { $missing[] = $pkg . ' (' . $users[0] . ')'; }
}
foreach ($needed as $pkg => $users) {
    printf("  usado: %-34s (%d ficheros)\n", $pkg, count(array_unique($users)));
}
check(sprintf('los %d paquetes de Magento usados estan en require', count($needed)),
    $missing === [], implode(', ', $missing));

echo "\n=== version ===\n";
$configSrc = (string) file_get_contents($root . '/Model/Config.php');
preg_match("/const VERSION\s*=\s*'([^']+)'/", $configSrc, $m);
$version = $m[1] ?? '(no encontrada)';
printf("  Config::VERSION = %s\n", $version);
check('Config::VERSION tiene forma de semver', (bool) preg_match('/^\d+\.\d+\.\d+$/', $version), $version);
if ($expectedTag !== '') {
    check('Config::VERSION coincide con el tag', ltrim($expectedTag, 'v') === $version,
        "tag=$expectedTag, codigo=$version");
} else {
    echo "  (sin tag que comparar; pasa el tag como argumento o en TE_EXPECTED_TAG)\n";
}

echo "\n";
printf("RESULTADO: %d correctas, %d fallos\n", $pass, $fail);
exit($fail === 0 ? 0 : 1);
