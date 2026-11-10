<?php
/**
 * Banco de pruebas del contrato de payload con la plataforma.
 *
 * Los nombres de campo son un contrato. Una errata en uno no da error: la
 * plataforma ignora el campo desconocido y el comportamiento cambia en
 * silencio. Nos paso con "soft_purge" en vez de "soft": durante meses cada
 * invalidacion por tags fue una purga DURA en lugar de marcar el objeto como
 * rancio, con soft purge activado por defecto.
 *
 * Campos correctos, segun docs.transparentedge.eu y segun el plugin de
 * WordPress que lleva en produccion desde el principio:
 *   POST /v1/companies/{id}/tag_invalidate/  { tags: [...], soft?, refetch? }
 *   POST /v1/companies/{id}/invalidate/      { urls: [...], soft?, refetch? }
 *
 * Uso:  php Test/Unit/api-payload.php
 */
declare(strict_types=1);

$repo = __DIR__ . '/../..';

require __DIR__ . '/stubs.php';
require __DIR__ . '/stubs-api.php';
require $repo . '/Api/ApiClient.php';

use TransparentEdge\CDN\Api\ApiClient;

$pass = 0; $fail = 0;
function check(string $name, bool $ok, string $detail = ''): void {
    global $pass, $fail;
    if ($ok) { $pass++; printf("  OK    %s\n", $name); }
    else { $fail++; printf("  FALLO %s%s\n", $name, $detail !== '' ? "  -> $detail" : ''); }
}

/** Captura el payload en vez de enviarlo. */
final class SpyApiClient extends ApiClient
{
    public array $ultimoPayload = [];
    public string $ultimoEndpoint = '';

    protected function post(string $url, array $payload): array
    {
        $this->ultimoEndpoint = $url;
        $this->ultimoPayload  = $payload;
        return ['success' => true, 'status' => 200, 'message' => 'ok'];
    }
}

function cliente(bool $soft = true, bool $refetch = false): SpyApiClient {
    $cfg = new \TransparentEdge\CDN\Model\Config();
    $cfg->softPurge = $soft;
    $cfg->refetch   = $refetch;
    return new SpyApiClient(
        $cfg,
        new \GuzzleHttp\ClientFactory(),
        new \TransparentEdge\CDN\Test\FakeCache(),
        new \TransparentEdge\CDN\Test\FakeLogger()
    );
}

echo "=== invalidacion por tags ===\n";
$c = cliente(true, false);
$c->invalidateByTags(['product-1', 'te-all']);
$p = $c->ultimoPayload;
check('usa el campo "tags"', isset($p['tags']) && $p['tags'] === ['product-1', 'te-all'], json_encode($p));
check('usa "soft", no "soft_purge"', array_key_exists('soft', $p), implode(',', array_keys($p)));
check('NO manda "soft_purge"', !array_key_exists('soft_purge', $p), implode(',', array_keys($p)));
check('"soft" vale true literal', ($p['soft'] ?? null) === true, var_export($p['soft'] ?? null, true));
check('sin refetch cuando esta desactivado', !array_key_exists('refetch', $p), implode(',', array_keys($p)));

echo "\n=== soft desactivado ===\n";
$c = cliente(false, false);
$c->invalidateByTags(['product-1']);
check('no manda "soft"', !array_key_exists('soft', $c->ultimoPayload), implode(',', array_keys($c->ultimoPayload)));

echo "\n=== refetch activado ===\n";
$c = cliente(true, true);
$c->invalidateByTags(['product-1']);
check('manda "refetch" a true', ($c->ultimoPayload['refetch'] ?? null) === true, json_encode($c->ultimoPayload));

echo "\n=== invalidacion por URLs ===\n";
$c = cliente(true, false);
$c->invalidateByUrls(['https://t.test/a', 'https://t.test/b']);
$p = $c->ultimoPayload;
check('usa el campo "urls"', isset($p['urls']) && count($p['urls']) === 2, json_encode($p));
check('hereda "soft" del ajuste', array_key_exists('soft', $p), implode(',', array_keys($p)));
check('las urls van como lista, sin huecos', array_keys($p['urls']) === [0, 1], json_encode(array_keys($p['urls'])));

echo "\n=== warmUrls: soft + refetch, sin depender de los ajustes ===\n";
$c = cliente(false, false);   // ambos desactivados a proposito
$c->warmUrls(['https://t.test/portada']);
$p = $c->ultimoPayload;
check('fuerza "soft"', ($p['soft'] ?? null) === true, json_encode($p));
check('fuerza "refetch"', ($p['refetch'] ?? null) === true, json_encode($p));
check('manda las urls', ($p['urls'] ?? []) === ['https://t.test/portada'], json_encode($p));

echo "\n=== nada de campos inventados ===\n";
$c = cliente(true, true);
$c->invalidateByTags(['x']);
$permitidos = ['tags', 'soft', 'refetch'];
$sobran = array_diff(array_keys($c->ultimoPayload), $permitidos);
check('tag_invalidate solo manda campos documentados', $sobran === [], implode(',', $sobran));
$c->invalidateByUrls(['https://t.test/a']);
$permitidos = ['urls', 'soft', 'refetch'];
$sobran = array_diff(array_keys($c->ultimoPayload), $permitidos);
check('invalidate solo manda campos documentados', $sobran === [], implode(',', $sobran));

echo "\n=== listas vacias no llegan a la plataforma ===\n";
$c = cliente();
$r = $c->invalidateByTags([]);
check('tags vacias: no se envia', $c->ultimoPayload === [] && $r['success'] === false);
$r = $c->invalidateByUrls([]);
check('urls vacias: no se envia', $c->ultimoPayload === [] && $r['success'] === false);

echo "\n";
printf("RESULTADO: %d correctas, %d fallos\n", $pass, $fail);
exit($fail === 0 ? 0 : 1);
