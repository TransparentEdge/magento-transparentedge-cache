<?php
/**
 * Banco de pruebas de Model/Diagnostics.
 *
 * Cubre el fallo que nos costo semanas detectar en el servidor de pruebas:
 * con system/full_page_cache/caching_application en built-in (el valor por
 * defecto de Magento), el CDN no puede cachear nada y nada lo dice.
 *
 * Uso:  php Test/Unit/diagnostics.php
 */
declare(strict_types=1);

$repo = __DIR__ . '/../..';

require __DIR__ . '/stubs.php';
require $repo . '/Model/Diagnostics.php';   // clase real

use TransparentEdge\CDN\Model\Diagnostics;

$pass = 0; $fail = 0;
function check(string $name, bool $ok, string $detail = ''): void {
    global $pass, $fail;
    if ($ok) { $pass++; printf("  OK    %s\n", $name); }
    else { $fail++; printf("  FALLO %s%s\n", $name, $detail !== '' ? "  -> $detail" : ''); }
}

/** Doble de ScopeConfigInterface con valores controlados. */
final class FakeScopeConfig implements \Magento\Framework\App\Config\ScopeConfigInterface
{
    public function __construct(private array $values = []) {}
    public function getValue($path, $scopeType = 'default', $scopeCode = null) { return $this->values[$path] ?? null; }
    public function isSetFlag($path, $scopeType = 'default', $scopeCode = null) { return (bool) $this->getValue($path); }
}

/** Doble del estado de los tipos de cache. */
final class FakeCacheState implements \Magento\Framework\App\Cache\StateInterface
{
    public function __construct(private array $enabled = ['full_page' => true]) {}
    public function isEnabled($cacheType) { return (bool) ($this->enabled[$cacheType] ?? false); }
    public function setEnabled($cacheType, $isEnabled) { $this->enabled[$cacheType] = (bool) $isEnabled; }
    public function persist() {}
}

function build(?int $cachingApplication, bool $fpcEnabled = true, bool $moduleEnabled = true): Diagnostics {
    $cfg = new \TransparentEdge\CDN\Model\Config();
    $cfg->enabled = $moduleEnabled;
    $valores = ['system/full_page_cache/ttl' => 86400];
    if ($cachingApplication !== null) {
        $valores['system/full_page_cache/caching_application'] = $cachingApplication;
    }
    return new Diagnostics(
        new FakeScopeConfig($valores),
        new FakeCacheState(['full_page' => $fpcEnabled]),
        $cfg
    );
}

function find(array $checks, string $id): ?array {
    foreach ($checks as $c) { if ($c['id'] === $id) { return $c; } }
    return null;
}

echo "=== caching_application sin valor (el defecto de Magento: built-in) ===\n";
$d = build(null);
$c = find($d->run(), 'caching_application');
check('se detecta como critico', $c !== null && $c['severity'] === Diagnostics::SEVERITY_CRITICAL,
    $c['severity'] ?? '(no encontrado)');
check('hasCriticalProblem() lo refleja', $d->hasCriticalProblem());
check('el aviso propone el comando exacto',
    $c !== null && str_contains($c['fix'], 'config:set system/full_page_cache/caching_application 2'),
    $c['fix'] ?? '');
check('el aviso incluye el cache:flush', $c !== null && str_contains($c['fix'], 'cache:flush'));
check('el texto no culpa al comercio',
    $c !== null && str_contains($c['detail'], "Magento's default value"),
    'debe decir que es el valor por defecto de Magento');

echo "\n=== caching_application = 1 (built-in explicito) ===\n";
$d = build(Diagnostics::FPC_BUILT_IN);
$c = find($d->run(), 'caching_application');
check('se detecta como critico', $c !== null && $c['severity'] === Diagnostics::SEVERITY_CRITICAL);
check('aparece en getProblems()', find($d->getProblems(), 'caching_application') !== null);

echo "\n=== caching_application = 2 (Varnish, lo correcto) ===\n";
$d = build(Diagnostics::FPC_VARNISH);
$c = find($d->run(), 'caching_application');
check('queda en OK', $c !== null && $c['severity'] === Diagnostics::SEVERITY_OK, $c['severity'] ?? '');
check('sin problemas que mostrar', $d->getProblems() === [], json_encode($d->getProblems()));
check('hasCriticalProblem() es false', !$d->hasCriticalProblem());

echo "\n=== tipo de cache full_page deshabilitado ===\n";
$d = build(Diagnostics::FPC_VARNISH, false);
$c = find($d->run(), 'full_page_cache_type');
check('se detecta como critico', $c !== null && $c['severity'] === Diagnostics::SEVERITY_CRITICAL);
check('propone habilitarlo', $c !== null && str_contains($c['fix'], 'cache:enable full_page'), $c['fix'] ?? '');

echo "\n=== los dos a la vez ===\n";
$d = build(Diagnostics::FPC_BUILT_IN, false);
check('se reportan los dos problemas', count($d->getProblems()) === 2, (string) count($d->getProblems()));

echo "\n=== con el modulo desactivado no se molesta al comercio ===\n";
$d = build(Diagnostics::FPC_BUILT_IN, false, false);
check('isRelevant() es false', !$d->isRelevant());

echo "\n=== forma de los datos, que la consumen tres sitios ===\n";
$d = build(null);
foreach ($d->run() as $c) {
    $faltan = array_diff(['id', 'severity', 'title', 'detail', 'fix'], array_keys($c));
    check("'{$c['id']}' trae todas las claves", $faltan === [], implode(',', $faltan));
    check("'{$c['id']}' tiene severidad conocida",
        in_array($c['severity'], [Diagnostics::SEVERITY_OK, Diagnostics::SEVERITY_WARNING, Diagnostics::SEVERITY_CRITICAL], true),
        $c['severity']);
    check("'{$c['id']}' tiene titulo", trim($c['title']) !== '');
}

echo "\n";
printf("RESULTADO: %d correctas, %d fallos\n", $pass, $fail);
exit($fail === 0 ? 0 : 1);
