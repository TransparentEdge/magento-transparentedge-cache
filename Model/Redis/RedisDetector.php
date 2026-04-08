<?php
/**
 * Redis auto-detector
 *
 * Discovers Redis instances by checking common configurations:
 * - Default localhost:6379
 * - Unix socket /var/run/redis/redis-server.sock
 * - Already configured in env.php
 * - Custom host/port from plugin settings
 *
 * Tests connectivity and AUTH, reports available databases.
 *
 * @package   TransparentEdge_CDN
 * @author    Transparent Edge Services
 * @copyright Copyright (c) 2025 Transparent Edge Services
 * @license   MIT
 */
declare(strict_types=1);

namespace TransparentEdge\CDN\Model\Redis;

use Psr\Log\LoggerInterface;

class RedisDetector
{
    /**
     * Common Redis locations to probe
     */
    private const PROBE_LOCATIONS = [
        ['host' => '127.0.0.1', 'port' => 6379],
        ['host' => 'localhost', 'port' => 6379],
        ['host' => 'redis', 'port' => 6379],          // Docker
        ['host' => '/var/run/redis/redis-server.sock'], // Unix socket
        ['host' => '/tmp/redis.sock'],                  // Alternative socket
    ];

    /**
     * @var LoggerInterface
     */
    private LoggerInterface $logger;

    /**
     * @param LoggerInterface $logger
     */
    public function __construct(LoggerInterface $logger)
    {
        $this->logger = $logger;
    }

    /**
     * Auto-detect Redis and return connection info
     *
     * @param  string|null $host     Custom host to try first
     * @param  int|null    $port     Custom port
     * @param  string|null $password AUTH password
     * @return array{available: bool, host: string, port: int, password: string, version: string, message: string}
     */
    public function detect(?string $host = null, ?int $port = null, ?string $password = null): array
    {
        $result = [
            'available' => false,
            'host'      => '',
            'port'      => 6379,
            'password'  => $password ?? '',
            'version'   => '',
            'memory'    => '',
            'databases' => [],
            'message'   => '',
        ];

        // If custom host provided, try that first
        if (!empty($host)) {
            $locations = [['host' => $host, 'port' => $port ?: 6379]];
        } else {
            $locations = self::PROBE_LOCATIONS;
        }

        foreach ($locations as $location) {
            $testHost = $location['host'];
            $testPort = $location['port'] ?? 6379;

            $info = $this->testConnection($testHost, $testPort, $password);

            if ($info['available']) {
                $result = array_merge($result, $info);
                $result['host'] = $testHost;
                $result['port'] = $testPort;
                $result['message'] = sprintf(
                    'Redis %s found at %s:%d (%s used)',
                    $info['version'],
                    $testHost,
                    $testPort,
                    $info['memory']
                );

                $this->logger->info('TransparentEdge: Redis detected', [
                    'host'    => $testHost,
                    'port'    => $testPort,
                    'version' => $info['version'],
                ]);

                return $result;
            }
        }

        $result['message'] = 'Redis not found. Check that Redis is installed and running.';
        return $result;
    }

    /**
     * Test connection to a specific Redis instance
     *
     * @param  string      $host
     * @param  int         $port
     * @param  string|null $password
     * @return array
     */
    public function testConnection(string $host, int $port, ?string $password = null): array
    {
        $result = [
            'available' => false,
            'version'   => '',
            'memory'    => '',
            'databases' => [],
        ];

        try {
            // Connect using raw socket (no Redis extension required)
            $isSocket = (strpos($host, '/') === 0);

            if ($isSocket) {
                $fp = @fsockopen('unix://' . $host, 0, $errno, $errstr, 3);
            } else {
                $fp = @fsockopen($host, $port, $errno, $errstr, 3);
            }

            if (!$fp) {
                return $result;
            }

            stream_set_timeout($fp, 3);

            // AUTH if password provided
            if (!empty($password)) {
                fwrite($fp, "AUTH {$password}\r\n");
                $authResponse = $this->readResponse($fp);
                if (strpos($authResponse, '+OK') === false && strpos($authResponse, '-NOAUTH') === false) {
                    fclose($fp);
                    return $result;
                }
            }

            // PING
            fwrite($fp, "PING\r\n");
            $pingResponse = $this->readResponse($fp);
            if (strpos($pingResponse, '+PONG') === false) {
                // Might need AUTH
                fclose($fp);
                return $result;
            }

            // INFO server (get version)
            fwrite($fp, "INFO server\r\n");
            $serverInfo = $this->readBulkResponse($fp);

            if (preg_match('/redis_version:(\S+)/', $serverInfo, $m)) {
                $result['version'] = $m[1];
            }

            // INFO memory
            fwrite($fp, "INFO memory\r\n");
            $memInfo = $this->readBulkResponse($fp);

            if (preg_match('/used_memory_human:(\S+)/', $memInfo, $m)) {
                $result['memory'] = $m[1];
            }

            // INFO keyspace (get databases in use)
            fwrite($fp, "INFO keyspace\r\n");
            $keyInfo = $this->readBulkResponse($fp);

            if (preg_match_all('/db(\d+):keys=(\d+)/', $keyInfo, $m)) {
                for ($i = 0; $i < count($m[0]); $i++) {
                    $result['databases'][] = [
                        'db'   => (int) $m[1][$i],
                        'keys' => (int) $m[2][$i],
                    ];
                }
            }

            fclose($fp);

            $result['available'] = true;
            return $result;

        } catch (\Exception $e) {
            $this->logger->debug('TransparentEdge: Redis probe failed', [
                'host'  => $host,
                'port'  => $port,
                'error' => $e->getMessage(),
            ]);
            return $result;
        }
    }

    /**
     * Read a single-line response from Redis
     *
     * @param  resource $fp
     * @return string
     */
    private function readResponse($fp): string
    {
        return trim((string) fgets($fp, 512));
    }

    /**
     * Read a bulk response from Redis (INFO commands)
     *
     * @param  resource $fp
     * @return string
     */
    private function readBulkResponse($fp): string
    {
        $line = fgets($fp, 512);
        if ($line === false || $line[0] !== '$') {
            return '';
        }

        $len = (int) substr($line, 1);
        if ($len <= 0) {
            return '';
        }

        $data = '';
        $remaining = $len + 2; // +2 for \r\n
        while ($remaining > 0) {
            $chunk = fread($fp, min($remaining, 8192));
            if ($chunk === false) {
                break;
            }
            $data .= $chunk;
            $remaining -= strlen($chunk);
        }

        return $data;
    }

    /**
     * Get recommended database assignments
     *
     * Returns which Redis DB to use for each Magento backend,
     * avoiding databases already in use.
     *
     * @param  array $usedDatabases Array of ['db' => int, 'keys' => int]
     * @return array{cache: int, fpc: int, session: int}
     */
    public function recommendDatabases(array $usedDatabases): array
    {
        $usedDbs = array_column($usedDatabases, 'db');

        // Magento recommended defaults
        $defaults = [
            'cache'   => 0,
            'fpc'     => 2,
            'session' => 3,
        ];

        // If defaults are not in use, use them
        $recommendations = [];
        foreach ($defaults as $type => $db) {
            $recommendations[$type] = $db;
        }

        return $recommendations;
    }
}
