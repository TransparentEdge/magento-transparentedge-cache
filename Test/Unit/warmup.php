<?php
/**
 * Banco de pruebas del precalentado reescrito.
 *
 * Lo que se comprueba es que el precalentado YA NO pide paginas al sitio:
 * delega en la plataforma con una invalidacion suave y refetch. Eso arregla
 * de una vez las tres cosas que medimos:
 *   - cargaba el origen una vez por URL,
 *   - solo calentaba el nodo que respondia,
 *   - y en un sitio con Bot Management se comia un 403 en silencio.
 *
 * Uso:  php Test/Unit/warmup.php
 */
declare(strict_types=1);

$repo = __DIR__ . '/../..';

require __DIR__ . '/stubs.php';
require __DIR__ . '/stubs-api.php';
require __DIR__ . '/stubs-warmup.php';
require $repo . '/Api/ApiClient.php';
require $repo . '/Model/Diagnostics.php';   // Warmup sella ahi su latido
require $repo . '/Model/Warmup.php';

use TransparentEdge\CDN\Api\ApiClient;
use TransparentEdge\CDN\Model\Warmup;

$pass = 0; $fail = 0;
function check(string $name, bool $ok, string $detail = ''): void {
    global $pass, $fail;
    if ($ok) { $pass++; printf("  OK    %s\n", $name); }
    else { $fail++; printf("  FALLO %s%s\n", $name, $detail !== '' ? "  -> $detail" : ''); }
}

/** Espia del ApiClient: registra cada llamada sin salir a la red. */
final class SpyApi extends ApiClient
{
    public array $llamadas = [];
    public bool $falla = false;

    protected function post(string $url, array $payload): array
    {
        $this->llamadas[] = ['url' => $url, 'payload' => $payload];
        return $this->falla
            ? ['success' => false, 'status' => 500, 'message' => 'boom']
            : ['success' => true, 'status' => 200, 'message' => 'ok'];
    }
}

/** Cliente HTTP espia: si el precalentado pide paginas, aqui se ve. */
final class SpyHttp extends \GuzzleHttp\ClientFactory
{
    public static array $peticiones = [];
    public function create(array $data = []) { return new SpyHttpClient(); }
}
final class SpyHttpClient extends \GuzzleHttp\Client
{
    public function request($method, $uri = '', array $options = []) {
        SpyHttp::$peticiones[] = $method . ' ' . $uri;
        throw new \GuzzleHttp\Exception\GuzzleException('sin red en el banco de pruebas');
    }
}

/**
 * @param  int                 $rateLimit
 * @param  ?object             $cache  caché compartida, para simular varias peticiones
 * @return array{0:Warmup,1:SpyApi,2:object,3:object}
 */
function warmup(int $rateLimit = 3, ?object $cache = null): array {
    SpyHttp::$peticiones = [];
    $cfg = new \TransparentEdge\CDN\Model\Config();
    $cfg->warmupRateLimit = $rateLimit;
    $cache = $cache ?? new \TransparentEdge\CDN\Test\FakeCache();
    $api = new SpyApi(
        $cfg,
        new \GuzzleHttp\ClientFactory(),
        new \TransparentEdge\CDN\Test\FakeCache(),
        new \TransparentEdge\CDN\Test\FakeLogger()
    );
    $w = new Warmup(
        $cfg,
        new SpyHttp(),
        new \TransparentEdge\CDN\Test\FakeCategoryCollectionFactory(),
        new \TransparentEdge\CDN\Test\FakeStoreManager(),
        new \TransparentEdge\CDN\Test\FakeEmulation(),
        new \TransparentEdge\CDN\Test\FakeLogger(),
        $api,
        $cache
    );
    return [$w, $api, $cfg, $cache];
}

echo "=== el precalentado NO pide paginas al sitio ===\n";
[$w, $api] = warmup();
for ($i = 0; $i < 20; $i++) { $w->queueUrl("https://t.test/p$i.html"); }
$r = $w->processQueue();
check('no se hizo ninguna peticion HTTP al sitio', SpyHttp::$peticiones === [], implode(' | ', SpyHttp::$peticiones));
check('se llamo al API', count($api->llamadas) === 1, (string) count($api->llamadas));
check('20 URLs en una sola llamada', count($api->llamadas[0]['payload']['urls'] ?? []) === 20);
check('va al endpoint de invalidacion por URL',
    str_contains($api->llamadas[0]['url'], '/invalidate/'), $api->llamadas[0]['url']);

echo "\n=== la llamada es suave y con refetch ===\n";
$p = $api->llamadas[0]['payload'];
check('"soft" a true', ($p['soft'] ?? null) === true, json_encode(array_keys($p)));
check('"refetch" a true', ($p['refetch'] ?? null) === true, json_encode(array_keys($p)));

echo "\n=== resultado informado ===\n";
check('total = 20', $r['total'] === 20, (string) $r['total']);
check('success = 20', $r['success'] === 20, (string) $r['success']);
check('failed = 0', $r['failed'] === 0, (string) $r['failed']);
check('calls = 1', $r['calls'] === 1, (string) $r['calls']);

echo "\n=== lotes de 100 ===\n";
[$w, $api] = warmup();
for ($i = 0; $i < 250; $i++) { $w->queueUrl("https://t.test/x$i.html"); }
$r = $w->processQueue();
check('250 URLs -> tope de 200', $r['total'] === 200, (string) $r['total']);
check('200 URLs -> 2 llamadas', $r['calls'] === 2, (string) $r['calls']);
check('primer lote de 100', count($api->llamadas[0]['payload']['urls']) === 100);
check('segundo lote de 100', count($api->llamadas[1]['payload']['urls']) === 100);

echo "\n=== canonicalizacion de URLs ===\n";
[$w] = warmup();
$w->queueUrl('https://t.test/a.html#seccion');
$w->queueUrl('https://t.test/a.html');
$w->queueUrl('https://t.test/a.html?___store=default');
$w->queueUrl('https://t.test/a.html?___from_store=es&SID=abc');
check('las cuatro variantes son una sola URL', $w->getQueueSize() === 1,
    json_encode($w->getQueue()));
check('la URL resultante no lleva decoracion',
    ($w->getQueue()[0] ?? '') === 'https://t.test/a.html', $w->getQueue()[0] ?? '');

[$w] = warmup();
$w->queueUrl('https://t.test/b.html?p=2');
check('un parametro que SI cambia la pagina se conserva',
    ($w->getQueue()[0] ?? '') === 'https://t.test/b.html?p=2', $w->getQueue()[0] ?? '');

[$w] = warmup();
foreach (['', '   ', 'no-es-una-url', '/solo/ruta', 'javascript:alert(1)'] as $malo) {
    $w->queueUrl($malo);
}
check('las URLs invalidas se descartan', $w->getQueueSize() === 0, json_encode($w->getQueue()));

echo "\n=== fallo del API: se informa, no se traga ===\n";
[$w, $api] = warmup();
$api->falla = true;
$w->queueUrl('https://t.test/a.html');
$r = $w->processQueue();
check('se cuenta como fallo', $r['failed'] === 1 && $r['success'] === 0, json_encode($r));

echo "\n=== la cola se vacia tras procesarla ===\n";
[$w] = warmup();
$w->queueUrl('https://t.test/a.html');
$w->processQueue();
check('cola vacia', $w->getQueueSize() === 0, (string) $w->getQueueSize());
check('procesar una cola vacia no llama al API', $w->processQueue()['calls'] === 0);

echo "\n=== el ritmo se respeta entre lotes ===\n";
[$w] = warmup(1);                       // 1 req/s -> 1s de espera entre lotes
for ($i = 0; $i < 150; $i++) { $w->queueUrl("https://t.test/y$i.html"); }
$t0 = microtime(true);
$r = $w->processQueue();
$ms = (microtime(true) - $t0) * 1000;
printf("  2 lotes a 1 req/s -> %.0f ms\n", $ms);
check('espero aproximadamente 1 segundo entre los dos lotes', $ms >= 950 && $ms < 2000, sprintf('%.0f ms', $ms));

[$w] = warmup(10);                      // 10 req/s -> 100ms
for ($i = 0; $i < 150; $i++) { $w->queueUrl("https://t.test/z$i.html"); }
$t0 = microtime(true);
$w->processQueue();
$ms = (microtime(true) - $t0) * 1000;
printf("  2 lotes a 10 req/s -> %.0f ms\n", $ms);
check('a mas ritmo, menos espera', $ms >= 90 && $ms < 400, sprintf('%.0f ms', $ms));

// ──────────────────────────────────────────────
// Amplificacion: una edicion no puede disparar un precalentado
// ──────────────────────────────────────────────

echo "\n=== una edicion no calienta nada en el acto ===\n";
[$w, $api] = warmup();
$w->queueUrl('https://t.test/a.html');
$w->queueUrl('https://t.test/b.html');
$pendientes = $w->deferQueue();
check('no se llamo al API al diferir', $api->llamadas === [], (string) count($api->llamadas));
check('las dos URLs quedan pendientes', $pendientes === 2, (string) $pendientes);
check('la cola en memoria queda vacia', $w->getQueueSize() === 0, (string) $w->getQueueSize());

echo "\n=== la rafaga de ediciones colapsa en un solo precalentado ===\n";
// 60 guardados (3 editores x 20) tocando las mismas 5 paginas entre dos drenados.
$cache = new \TransparentEdge\CDN\Test\FakeCache();
for ($guardado = 0; $guardado < 60; $guardado++) {
    [$w] = warmup(3, $cache);                       // cada guardado es otra peticion
    for ($i = 0; $i < 5; $i++) { $w->queueUrl("https://t.test/c$i.html"); }
    $w->deferQueue();
}
[$w, $api] = warmup(3, $cache);
check('60 guardados dejan 5 URLs, no 300', $w->getDeferredSize() === 5, (string) $w->getDeferredSize());
$r = $w->drainDeferred();
check('se calientan 5 URLs', $r['total'] === 5, (string) $r['total']);
check('en una sola llamada al API', $r['calls'] === 1, (string) $r['calls']);

echo "\n=== el drenado no se repite ===\n";
[$w2, $api2] = warmup(3, $cache);
check('la cola diferida quedo vacia', $w2->getDeferredSize() === 0, (string) $w2->getDeferredSize());
check('drenar en vacio no llama al API', $w2->drainDeferred()['calls'] === 0);
check('no se llamo al API', $api2->llamadas === [], (string) count($api2->llamadas));

echo "\n=== lo diferido se deduplica y se canonicaliza ===\n";
[$w] = warmup();
$w->defer([
    'https://t.test/d.html',
    'https://t.test/d.html#abajo',
    'https://t.test/d.html?___store=default',
    'no-es-una-url',
    '',
]);
check('una sola URL pendiente', $w->getDeferredSize() === 1, (string) $w->getDeferredSize());

// ──────────────────────────────────────────────
// Presupuesto horario
// ──────────────────────────────────────────────

echo "\n=== el presupuesto horario recorta y lo dice ===\n";
[$w, $api, $cfg] = warmup();
$cfg->hourlyBudget = 10;
for ($i = 0; $i < 40; $i++) { $w->queueUrl("https://t.test/e$i.html"); }
$r = $w->processQueue();
check('solo salen 10 URLs', $r['total'] === 10, (string) $r['total']);
check('se informan 30 descartadas', ($r['skipped'] ?? 0) === 30, (string) ($r['skipped'] ?? 0));
check('el API recibio 10', count($api->llamadas[0]['payload']['urls']) === 10);

echo "\n=== el gasto se acumula dentro de la misma hora ===\n";
$cache = new \TransparentEdge\CDN\Test\FakeCache();
[$w, $api, $cfg] = warmup(3, $cache);
$cfg->hourlyBudget = 10;
for ($i = 0; $i < 8; $i++) { $w->queueUrl("https://t.test/f$i.html"); }
$r1 = $w->processQueue();
[$w, $api, $cfg] = warmup(3, $cache);           // otra peticion, misma hora
$cfg->hourlyBudget = 10;
for ($i = 0; $i < 8; $i++) { $w->queueUrl("https://t.test/g$i.html"); }
$r2 = $w->processQueue();
check('la primera tanda sale entera', $r1['total'] === 8, (string) $r1['total']);
check('la segunda solo gasta lo que queda', $r2['total'] === 2, (string) $r2['total']);
check('y lo declara', ($r2['skipped'] ?? 0) === 6, (string) ($r2['skipped'] ?? 0));

[$w, $api, $cfg] = warmup(3, $cache);
$cfg->hourlyBudget = 10;
$w->queueUrl('https://t.test/h.html');
$r3 = $w->processQueue();
check('agotado el presupuesto no se llama al API', $api->llamadas === [], (string) count($api->llamadas));
check('total 0 y skipped 1', $r3['total'] === 0 && ($r3['skipped'] ?? 0) === 1, json_encode($r3));

echo "\n=== presupuesto 0 = sin tope ===\n";
[$w, $api, $cfg] = warmup();
$cfg->hourlyBudget = 0;
for ($i = 0; $i < 150; $i++) { $w->queueUrl("https://t.test/i$i.html"); }
$r = $w->processQueue();
check('salen las 150', $r['total'] === 150, (string) $r['total']);
check('nada descartado', ($r['skipped'] ?? 0) === 0, (string) ($r['skipped'] ?? 0));

echo "\n=== la cola diferida tiene tope ===\n";
[$w] = warmup();
$lote = [];
for ($i = 0; $i < 2500; $i++) { $lote[] = "https://t.test/j$i.html"; }
$w->defer($lote);
check('se corta en 2000', $w->getDeferredSize() === 2000, (string) $w->getDeferredSize());

echo "\n=== el drenado deja latido para que se note si el cron muere ===\n";
$cache = new \TransparentEdge\CDN\Test\FakeCache();
$clave = \TransparentEdge\CDN\Model\Diagnostics::WARMUP_HEARTBEAT_KEY;
check('sin drenar no hay latido', $cache->load($clave) === false);

[$w] = warmup(3, $cache);
$w->defer(['https://t.test/k.html', 'https://t.test/l.html']);
[$w] = warmup(3, $cache);
$w->drainDeferred();
$latido = json_decode((string) $cache->load($clave), true);
check('el drenado sella el latido', is_array($latido) && isset($latido['at']), (string) $cache->load($clave));
check('el latido es de ahora', abs(time() - (int) $latido['at']) <= 5, (string) ($latido['at'] ?? 0));
check('dice cuantas habia pendientes', ($latido['pending'] ?? null) === 2, json_encode($latido));
check('y cuantas calento', ($latido['warmed'] ?? null) === 2, json_encode($latido));

$cache2 = new \TransparentEdge\CDN\Test\FakeCache();
[$w] = warmup(3, $cache2);
$w->drainDeferred();                           // nada que hacer
$latido = json_decode((string) $cache2->load($clave), true);
check('un drenado en vacio tambien sella: prueba que el cron vive',
    is_array($latido) && isset($latido['at']), (string) $cache2->load($clave));

// ──────────────────────────────────────────────
// Lo que decide dirigido vs masivo
// ──────────────────────────────────────────────

echo "\n=== solo se calientan las categorias invalidadas ===\n";
require $repo . '/Model/Invalidator.php';
$refInv = new ReflectionClass(\TransparentEdge\CDN\Model\Invalidator::class);
$inv    = $refInv->newInstanceWithoutConstructor();
$extrae = $refInv->getMethod('extractCategoryIds');
$extrae->setAccessible(true);

$ids = $extrae->invoke($inv, ['te-category-12', 'category-7', 'te-category-12']);
check('saca los ids con y sin prefijo te-, sin repetir', $ids === [12, 7], json_encode($ids));

$ids = $extrae->invoke($inv, ['te-categories', 'te-product-9', 'te-all', 'category-abc', 'categorybad-3']);
check('no confunde otras claves con una categoria', $ids === [], json_encode($ids));

echo "\n";
printf("RESULTADO: %d correctas, %d fallos\n", $pass, $fail);
exit($fail === 0 ? 0 : 1);
