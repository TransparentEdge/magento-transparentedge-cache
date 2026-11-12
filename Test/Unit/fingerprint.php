<?php
/**
 * Banco de la huella del código desplegado.
 *
 * Lo que se comprueba es que sirva para lo que existe: comparar lo que hay
 * en un servidor con lo que hay en el repo. Si no fuese determinista, o si
 * cambiase por cosas que no son el código, la comparacion daria falsos
 * positivos y la gente dejaria de mirarla.
 *
 * Imprime ademas la huella del repo, para poder compararla a ojo con la que
 * saca `bin/magento transparentedge:status` en el servidor.
 *
 * Uso:  php Test/Unit/fingerprint.php
 */
declare(strict_types=1);

require __DIR__ . '/../../Model/Fingerprint.php';

use TransparentEdge\CDN\Model\Fingerprint;

$pass = 0; $fail = 0;
function check(string $name, bool $ok, string $detail = ''): void {
    global $pass, $fail;
    if ($ok) { $pass++; printf("  OK    %s\n", $name); }
    else { $fail++; printf("  FALLO %s%s\n", $name, $detail !== '' ? "  -> $detail" : ''); }
}

$repo = dirname(__DIR__, 2);

/** Monta un modulo de mentira en un directorio temporal. */
function montar(array $ficheros): string {
    $raiz = sys_get_temp_dir() . '/te-fp-' . bin2hex(random_bytes(6));
    foreach ($ficheros as $ruta => $contenido) {
        $destino = $raiz . '/' . $ruta;
        @mkdir(dirname($destino), 0777, true);
        file_put_contents($destino, $contenido);
    }
    return $raiz;
}
function borrar(string $dir): void {
    if (!is_dir($dir)) { return; }
    $it = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST
    );
    foreach ($it as $f) { $f->isDir() ? @rmdir($f->getPathname()) : @unlink($f->getPathname()); }
    @rmdir($dir);
}

$base = ['Model/A.php' => '<?php class A {}', 'etc/di.xml' => '<config/>'];

echo "=== determinista ===\n";
$d1 = montar($base);
$h1 = Fingerprint::of($d1);
check('devuelve 12 caracteres hex', (bool) preg_match('/^[0-9a-f]{12}$/', $h1), $h1);
check('dos llamadas dan lo mismo', Fingerprint::of($d1) === $h1);

echo "\n=== el mismo codigo en otra ruta da la misma huella ===\n";
// Si dependiera de la ruta absoluta, la del servidor nunca coincidiria con
// la del repo y esto no serviria para nada.
$d2 = montar($base);
check('misma huella en otro directorio', Fingerprint::of($d2) === $h1,
    sprintf('%s vs %s', $h1, Fingerprint::of($d2)));

echo "\n=== cambia cuando cambia el codigo ===\n";
$d3 = montar(['Model/A.php' => '<?php class A { public $x; }', 'etc/di.xml' => '<config/>']);
check('un byte distinto cambia la huella', Fingerprint::of($d3) !== $h1);
$d4 = montar(['Model/B.php' => '<?php class A {}', 'etc/di.xml' => '<config/>']);
check('mover un fichero cambia la huella', Fingerprint::of($d4) !== $h1,
    'la ruta relativa entra en el hash');
$d5 = montar($base + ['Model/C.php' => '<?php class C {}']);
check('anadir un fichero cambia la huella', Fingerprint::of($d5) !== $h1);

echo "\n=== NO cambia por lo que no es codigo de ejecucion ===\n";
// Test/ y .github/ viajan en un rsync pero no en una instalacion por
// composer. Si contaran, la misma version daria huellas distintas segun
// como se instalara, que es lo contrario de lo que esto tiene que hacer.
$d6 = montar($base + [
    'Test/Unit/x.php'           => '<?php // pruebas',
    '.github/workflows/ci.yml'  => 'name: CI',
    'README.md'                 => '# readme',
]);
check('Test/, .github/ y README no cuentan', Fingerprint::of($d6) === $h1,
    sprintf('%s vs %s', $h1, Fingerprint::of($d6)));

echo "\n=== un directorio que no existe no revienta ===\n";
check('devuelve cadena vacia', Fingerprint::of('/no/existe/' . bin2hex(random_bytes(4))) === '');
check('un directorio vacio tambien', Fingerprint::of(montar([])) === '');

foreach ([$d1, $d2, $d3, $d4, $d5, $d6] as $d) { borrar($d); }

echo "\n=== huella de ESTE repo ===\n";
$hRepo = Fingerprint::of($repo);
check('el repo da una huella', (bool) preg_match('/^[0-9a-f]{12}$/', $hRepo), $hRepo);
printf("\n  %s\n  Comparala con la que imprime: bin/magento transparentedge:status\n", $hRepo);

echo "\n";
printf("RESULTADO: %d correctas, %d fallos\n", $pass, $fail);
exit($fail === 0 ? 0 : 1);
