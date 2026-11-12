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
require __DIR__ . '/stubs-api.php';         // por el doble de CacheInterface
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

function build(
    ?int   $cachingApplication,
    bool   $fpcEnabled = true,
    bool   $moduleEnabled = true,
    // 'fresco' = el cron acaba de correr, que es lo normal y lo que deben
    // suponer las pruebas que no van de esto. 'nunca' = sin latido.
    array|string $latido = 'fresco',
    bool   $warmupEnabled = true,
    ?array $sonda = null,
    ?array $conflicto = null
): Diagnostics {
    $cfg = new \TransparentEdge\CDN\Model\Config();
    $cfg->enabled = $moduleEnabled;
    $cfg->warmupEnabled = $warmupEnabled;
    $cfg->speculation   = true;
    $valores = ['system/full_page_cache/ttl' => 86400];
    if ($cachingApplication !== null) {
        $valores['system/full_page_cache/caching_application'] = $cachingApplication;
    }
    $cache = new \TransparentEdge\CDN\Test\FakeCache();
    if ($latido === 'fresco') {
        $latido = ['at' => time() - 60, 'pending' => 0, 'warmed' => 0];
    }
    if (is_array($latido)) {
        $cache->save((string) json_encode($latido), Diagnostics::WARMUP_HEARTBEAT_KEY);
    }
    if ($sonda !== null) {
        $sonda += ['at' => time()];
        $cache->save((string) json_encode($sonda), Diagnostics::CACHE_BLOCKERS_KEY);
    }
    if ($conflicto !== null) {
        $conflicto += ['at' => time()];
        $cache->save((string) json_encode($conflicto), Diagnostics::SPECULATION_CONFLICT_KEY);
    }
    return new Diagnostics(
        new FakeScopeConfig($valores),
        new FakeCacheState(['full_page' => $fpcEnabled]),
        $cfg,
        $cache
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

// ──────────────────────────────────────────────
// El cron del precalentado: el fallo silencioso que introdujo diferirlo
// ──────────────────────────────────────────────

echo "\n=== el cron del precalentado nunca ha corrido ===\n";
$c = find(build(2, true, true, 'nunca')->run(), 'warmup_cron');
check('aparece el chequeo', $c !== null);
check('es un aviso, no critico', $c['severity'] === Diagnostics::SEVERITY_WARNING, $c['severity'] ?? '');
check('no tumba el estado general', build(2, true, true, 'nunca')->hasCriticalProblem() === false);
check('da una salida', str_contains($c['fix'] ?? '', 'cron'), $c['fix'] ?? '');

echo "\n=== el cron murio hace rato ===\n";
$viejo = ['at' => time() - 3600, 'pending' => 4, 'warmed' => 4];
$c = find(build(2, true, true, $viejo)->run(), 'warmup_cron');
check('avisa', $c['severity'] === Diagnostics::SEVERITY_WARNING, $c['severity'] ?? '');
check('dice cuantos minutos lleva parado', str_contains($c['title'], '60'), $c['title'] ?? '');

echo "\n=== justo en el limite ===\n";
$limite = Diagnostics::WARMUP_HEARTBEAT_STALE_AFTER;
$c = find(build(2, true, true, ['at' => time() - ($limite - 60)])->run(), 'warmup_cron');
check('por debajo del umbral esta OK', $c['severity'] === Diagnostics::SEVERITY_OK, $c['severity'] ?? '');
$c = find(build(2, true, true, ['at' => time() - ($limite + 60)])->run(), 'warmup_cron');
check('por encima del umbral avisa', $c['severity'] === Diagnostics::SEVERITY_WARNING, $c['severity'] ?? '');

echo "\n=== el cron esta vivo ===\n";
$c = find(build(2, true, true, ['at' => time() - 120, 'pending' => 7, 'warmed' => 7])->run(), 'warmup_cron');
check('OK', $c['severity'] === Diagnostics::SEVERITY_OK, $c['severity'] ?? '');
check('un drenado en vacio tambien cuenta como latido',
    find(build(2, true, true, ['at' => time() - 60, 'pending' => 0, 'warmed' => 0])->run(), 'warmup_cron')['severity']
    === Diagnostics::SEVERITY_OK);
check('no sale en la lista de problemas',
    find(build(2, true, true, ['at' => time() - 120])->getProblems(), 'warmup_cron') === null);

echo "\n=== sin precalentado no se comprueba ===\n";
check('el chequeo desaparece',
    find(build(2, true, true, 'nunca', false)->run(), 'warmup_cron') === null);

// ──────────────────────────────────────────────
// La sonda de lo que impide cachear
// ──────────────────────────────────────────────

echo "\n=== sin lectura, no se opina ===\n";
check('el chequeo no aparece', find(build(2)->run(), 'cache_blockers') === null,
    'sin datos no se inventa un veredicto');

echo "\n=== una cookie en una pagina cacheable es critico ===\n";
$c = find(build(2, true, true, 'fresco', true, ['cookies' => ['PHPSESSID'], 'vary' => 'Accept-Encoding'])->run(), 'cache_blockers');
check('es critico', $c['severity'] === Diagnostics::SEVERITY_CRITICAL, $c['severity'] ?? '');
check('nombra la cookie', str_contains($c['detail'], 'PHPSESSID'), $c['detail'] ?? '');
check('y tumba el estado general',
    build(2, true, true, 'fresco', true, ['cookies' => ['PHPSESSID'], 'vary' => ''])->hasCriticalProblem() === true);

echo "\n=== variar por Cookie es un aviso, no un critico ===\n";
// El objeto SI se cachea; lo que pasa es que se guarda una copia por
// visitante y casi nunca se reutiliza. Es grave, pero no es lo mismo.
$c = find(build(2, true, true, 'fresco', true, ['cookies' => [], 'vary' => 'Accept-Encoding, Cookie'])->run(), 'cache_blockers');
check('es aviso', $c['severity'] === Diagnostics::SEVERITY_WARNING, $c['severity'] ?? '');
check('nombra Cookie', str_contains($c['title'], 'Cookie'), $c['title'] ?? '');
$c = find(build(2, true, true, 'fresco', true, ['cookies' => [], 'vary' => 'User-Agent'])->run(), 'cache_blockers');
check('tambien User-Agent', $c['severity'] === Diagnostics::SEVERITY_WARNING, $c['severity'] ?? '');

echo "\n=== un Vary normal no da la alarma ===\n";
$c = find(build(2, true, true, 'fresco', true, ['cookies' => [], 'vary' => 'Accept-Encoding, X-Vary-TCDN'])->run(), 'cache_blockers');
check('OK', $c['severity'] === Diagnostics::SEVERITY_OK, $c['severity'] ?? '');
check('no sale en la lista de problemas',
    find(build(2, true, true, 'fresco', true, ['cookies' => [], 'vary' => 'Accept-Encoding'])->getProblems(), 'cache_blockers') === null);

echo "\n=== una lectura vieja no habla del sitio de hoy ===\n";
$viejo = ['cookies' => ['PHPSESSID'], 'vary' => '', 'at' => time() - Diagnostics::CACHE_BLOCKERS_STALE_AFTER - 60];
check('se descarta', find(build(2, true, true, 'fresco', true, $viejo)->run(), 'cache_blockers') === null,
    'un fallo de hace dos dias no es un fallo de ahora');

// ──────────────────────────────────────────────
// Conflicto de speculation rules
// ──────────────────────────────────────────────

echo "\n=== sin conflicto, no se opina ===\n";
check('no aparece', find(build(2)->run(), 'speculation_conflict') === null);

echo "\n=== con conflicto, avisa y dice de quien ===\n";
$c = find(build(2, true, true, 'fresco', true, null, ['what' => 'quicklink'])->run(), 'speculation_conflict');
check('es aviso', $c['severity'] === Diagnostics::SEVERITY_WARNING, $c['severity'] ?? '');
check('nombra al culpable', str_contains($c['title'], 'quicklink'), $c['title'] ?? '');
check('explica lo de las exclusiones', str_contains($c['detail'], 'checkout'), '');
check('no es critico: la tienda funciona',
    build(2, true, true, 'fresco', true, null, ['what' => 'quicklink'])->hasCriticalProblem() === false);

echo "\n=== una lectura vieja no cuenta ===\n";
$viejo = ['what' => 'quicklink', 'at' => time() - Diagnostics::CACHE_BLOCKERS_STALE_AFTER - 60];
check('se descarta',
    find(build(2, true, true, 'fresco', true, null, $viejo)->run(), 'speculation_conflict') === null);

echo "\n";
printf("RESULTADO: %d correctas, %d fallos\n", $pass, $fail);
exit($fail === 0 ? 0 : 1);
