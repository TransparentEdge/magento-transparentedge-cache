<?php
/**
 * Banco del logger que no puede tumbar lo que observa.
 *
 * Hay dos cosas que comprobar, y la segunda es la que de verdad da miedo:
 *
 *  1. Que al fallar la escritura NO suba la excepcion. Si sube, una purga
 *     se queda sin hacer porque no se pudo anotar que se iba a hacer.
 *
 *  2. Que la firma variadica de addRecord() sea compatible con Monolog 2
 *     (int $level) Y con Monolog 3 (Level $level). Esto no se puede
 *     comprobar en el mismo proceso: una firma incompatible es un error
 *     FATAL al cargar la clase, no una excepcion que se pueda capturar. Asi
 *     que cada version se prueba en un proceso aparte, y lo que se mira es
 *     si el proceso sobrevivio.
 *
 * Nosotros no elegimos la version de Monolog: la elige el Magento donde se
 * instale el modulo. Por eso se prueban las dos.
 *
 * Uso:  php Test/Unit/safe-logger.php
 */
declare(strict_types=1);

// ───────────────────────────────────────────────────────────────
// Modo hijo: se invoca a si mismo con la forma de Monolog a imitar.
// ───────────────────────────────────────────────────────────────
if (($argv[1] ?? '') !== '') {
    $forma = $argv[1];

    $firma = $forma === 'm3'
        // Monolog 3: el nivel es un objeto y hay un cuarto parametro.
        ? 'public function addRecord($level, string $message, array $context = [], $datetime = null): bool'
        // Monolog 2: el nivel es un entero y son tres parametros.
        : 'public function addRecord(int $level, string $message, array $context = []): bool';

    eval('
    namespace Magento\Framework\Logger {
        class Monolog {
            public static bool $revienta = false;
            public array $visto = [];
            ' . $firma . '
            {
                if (self::$revienta) {
                    throw new \RuntimeException("no se pudo abrir el fichero de log");
                }
                $this->visto[] = $message;
                return true;
            }
            // info() y compania desembocan en addRecord(): ese es el embudo
            // que envolvemos, y esto lo demuestra en vez de suponerlo.
            public function info($message, array $context = []) {
                return $this->addRecord(200, (string) $message, $context);
            }
        }
    }');

    require __DIR__ . '/../../Model/SafeLogger.php';

    $r = new ReflectionClass(\TransparentEdge\CDN\Model\SafeLogger::class);
    $l = $r->newInstanceWithoutConstructor();

    // Con el log sano: pasa y queda constancia.
    $ok     = $l->addRecord(200, 'linea normal', []);
    $okInfo = $l->info('por el embudo');
    $vistas = count($l->visto);

    // Con el log roto: NO sube la excepcion.
    \Magento\Framework\Logger\Monolog::$revienta = true;
    $subio = false;
    try {
        $roto = $l->addRecord(200, 'esta se pierde', []);
    } catch (\Throwable $e) {
        $subio = true; $roto = null;
    }
    // Y tampoco por los metodos de conveniencia.
    $subioInfo = false;
    try {
        $l->info('esta tambien se pierde');
    } catch (\Throwable $e) {
        $subioInfo = true;
    }

    echo json_encode([
        'cargo'      => true,
        'ok'         => $ok === true,
        'okInfo'     => $okInfo === true,
        'vistas'     => $vistas,
        'subio'      => $subio,
        'subioInfo'  => $subioInfo,
        'devuelve'   => $roto,
    ]);
    exit(0);
}

// ───────────────────────────────────────────────────────────────
// Modo padre
// ───────────────────────────────────────────────────────────────
$pass = 0; $fail = 0;
function check(string $name, bool $ok, string $detail = ''): void {
    global $pass, $fail;
    if ($ok) { $pass++; printf("  OK    %s\n", $name); }
    else { $fail++; printf("  FALLO %s%s\n", $name, $detail !== '' ? "  -> $detail" : ''); }
}

foreach (['m2' => 'Monolog 2 (int $level)', 'm3' => 'Monolog 3 (Level $level)'] as $forma => $titulo) {
    printf("\n=== %s ===\n", $titulo);

    $cmd = sprintf('%s %s %s 2>&1',
        escapeshellarg(PHP_BINARY), escapeshellarg(__FILE__), escapeshellarg($forma));
    $salida = shell_exec($cmd);
    $d = json_decode((string) $salida, true);

    // Si la firma fuese incompatible, PHP aborta al cargar la clase y aqui
    // no llega un JSON, llega el texto del error fatal.
    if (!is_array($d)) {
        check('la clase carga con esta version', false, trim((string) $salida));
        continue;
    }

    check('la clase carga con esta version', $d['cargo'] === true);
    check('una linea normal se registra', $d['ok'] === true);
    check('info() pasa por addRecord()', $d['okInfo'] === true && $d['vistas'] === 2,
        'vistas=' . $d['vistas']);
    check('con el log roto NO sube la excepcion', $d['subio'] === false);
    check('tampoco por info()', $d['subioInfo'] === false);
    check('devuelve false, como Monolog cuando no maneja', $d['devuelve'] === false,
        var_export($d['devuelve'], true));
}

echo "\n";
printf("RESULTADO: %d correctas, %d fallos\n", $pass, $fail);
exit($fail === 0 ? 0 : 1);
