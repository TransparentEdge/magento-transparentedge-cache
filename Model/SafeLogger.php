<?php
/**
 * Un logger que no puede tumbar lo que está observando.
 *
 * Medido en un servidor real: el fichero de log quedó con un propietario
 * distinto al del proceso, y `transparentedge:status` informó de
 * "Connection failed". La conexión había funcionado — el token OAuth2 se
 * obtuvo — y lo que falló fue ESCRIBIR la línea que lo contaba. La
 * excepción subió, el catch de arriba la leyó como un fallo de red, y el
 * diagnóstico pasó a decir exactamente lo contrario de la verdad.
 *
 * Eso era lo visible. Lo serio estaba una capa más abajo:
 *
 *     $this->logger->info('TransparentEdge: Invalidating tags', [...]);
 *     return $this->post($endpoint, $payload);     // ← nunca se llega
 *
 * Con el log no escribible, la purga no se hace. Y no se hace en silencio,
 * porque lo que ha fallado es justo el mecanismo que lo contaría: el cliente
 * sirve contenido viejo desde el edge sin un solo error en ninguna parte.
 *
 * Un log es un observador. Un observador que puede romper lo observado no
 * es un observador, es un riesgo. Así que aquí se traga cualquier Throwable
 * y devuelve false, que es lo que Monolog devuelve cuando el registro no se
 * maneja. Se pierde una línea de log; no se pierde la purga.
 *
 * Por qué se envuelve addRecord() y no cada método: info(), warning(),
 * error() y compañía desembocan todos ahí, así que es el único embudo. Y
 * por qué variádico: la firma de addRecord() cambió entre Monolog 2
 * (int $level) y Monolog 3 (Level $level), y un parámetro variádico es
 * compatible con las dos. Este módulo no elige la versión de Monolog, la
 * elige el Magento donde se instale.
 *
 * @package   TransparentEdge_CDN
 * @author    Transparent Edge Services
 * @copyright Copyright (c) 2025 Transparent Edge Services
 * @license   MIT
 */
declare(strict_types=1);

namespace TransparentEdge\CDN\Model;

use Magento\Framework\Logger\Monolog;

class SafeLogger extends Monolog
{
    /**
     * @param  mixed ...$args Lo que acepte addRecord() en esta versión.
     * @return bool           false si no se pudo registrar.
     */
    public function addRecord(...$args): bool
    {
        try {
            return parent::addRecord(...$args);
        } catch (\Throwable $e) {
            // A propósito en silencio. El único sitio al que se podría
            // informar de que el log no funciona es el log.
            return false;
        }
    }
}
