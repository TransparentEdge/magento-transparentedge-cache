<?php
/**
 * Huella del código del módulo, para saber QUÉ está desplegado.
 *
 * Dos veces en un mismo día desplegamos una versión que no era la que
 * creíamos —un `git checkout` que falló en silencio y un `rsync --delete`
 * detrás que copió el árbol viejo— y las dos veces `setup:di:compile` pasó
 * tan contento, porque el código viejo compila igual de bien. Lo único que
 * lo delató fue un `grep` puesto a mano.
 *
 * Esto convierte ese grep en algo que no depende de que nadie se acuerde:
 * `transparentedge:status` imprime la huella de lo instalado, y la misma
 * función la calcula sobre el repo, así que comparar es mirar dos cadenas.
 *
 * No es una firma: no protege de nada, solo responde a "¿es esto lo que creo
 * que es?". Por eso basta con que sea corta y determinista.
 *
 * @package   TransparentEdge_CDN
 * @author    Transparent Edge Services
 * @copyright Copyright (c) 2025 Transparent Edge Services
 * @license   MIT
 */
declare(strict_types=1);

namespace TransparentEdge\CDN\Model;

class Fingerprint
{
    /**
     * Carpetas que deciden el comportamiento en ejecución.
     *
     * Test/ y .github/ quedan fuera a propósito: viajan en un rsync pero no
     * en una instalación por composer, y si entraran, la misma versión daría
     * huellas distintas según cómo se instalara — que es justo lo contrario
     * de lo que esto tiene que hacer.
     */
    private const DIRS = ['Api', 'Block', 'Console', 'Controller', 'Cron', 'Model',
                          'Observer', 'Plugin', 'Setup', 'etc', 'i18n', 'view'];

    private const EXTS = ['php', 'xml', 'csv', 'phtml', 'js'];

    /**
     * @param  string $baseDir Raíz del módulo.
     * @return string 12 caracteres hex, o '' si no se pudo leer.
     */
    public static function of(string $baseDir): string
    {
        $baseDir = rtrim($baseDir, '/');
        if (!is_dir($baseDir)) {
            return '';
        }

        $ficheros = [];
        foreach (self::DIRS as $dir) {
            $ruta = $baseDir . '/' . $dir;
            if (!is_dir($ruta)) {
                continue;
            }
            $it = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($ruta, \FilesystemIterator::SKIP_DOTS)
            );
            foreach ($it as $f) {
                if (!$f->isFile()) {
                    continue;
                }
                if (!in_array(strtolower($f->getExtension()), self::EXTS, true)) {
                    continue;
                }
                $ficheros[] = $f->getPathname();
            }
        }

        $raiz = $baseDir . '/registration.php';
        if (is_file($raiz)) {
            $ficheros[] = $raiz;
        }

        if ($ficheros === []) {
            return '';
        }

        // Ordenado: el orden de recorrido del sistema de ficheros no es
        // estable entre máquinas, y una huella que depende de eso no compara
        // nada.
        sort($ficheros, SORT_STRING);

        $h = hash_init('sha256');
        foreach ($ficheros as $f) {
            // La ruta relativa entra en el hash: mover un fichero de sitio es
            // un cambio, aunque su contenido sea el mismo.
            hash_update($h, substr($f, strlen($baseDir) + 1));
            $contenido = @file_get_contents($f);
            hash_update($h, $contenido === false ? '' : $contenido);
        }

        return substr(hash_final($h), 0, 12);
    }
}
