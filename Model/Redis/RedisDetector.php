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
     * Allowed hostnames/IPs for Redis connections (SSRF protection).
     * Only these hosts can be probed via the admin panel.
     */
    private const ALLOWED_HOSTS = [
        '127.0.0.1', 'localhost', '::1',
        'redis', 'redis-master', 'redis-slave', 'redis-sentinel',
    ];

    /**
     * Allowed port range for Redis connections
     */
    private const MIN_PORT = 1024;
    private const MAX_PORT = 65535;
    private const DEFAULT_PORT = 6379;

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

        // If custom host provided, validate and try that first
        if (!empty($host)) {
            if (!$this->isAllowedHost($host, $port ?: self::DEFAULT_PORT)) {
                $result['message'] = 'Host not allowed. Only local Redis instances are supported.';
                $this->logger->warning('TransparentEdge: Redis probe blocked (SSRF protection)', [
                    'host' => $host,
                    'port' => $port,
                ]);
                return $result;
            }
            $locations = [['host' => $host, 'port' => $port ?: self::DEFAULT_PORT]];
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
     * Validate that a host is safe to connect to (SSRF protection)
     *
     * Only allows loopback addresses, known Docker service names,
     * private RFC1918 addresses, and Unix sockets.
     * Blocks metadata endpoints (169.254.x.x) and public IPs.
     *
     * @param  string $host
     * @param  int    $port
     * @return bool
     */
    private function isAllowedHost(string $host, int $port): bool
    {
        // Unix sockets are always local
        if (strpos($host, '/') === 0) {
            return true;
        }

        // Check against whitelist
        if (in_array(strtolower($host), self::ALLOWED_HOSTS, true)) {
            return true;
        }

        // Allow private RFC1918 IPs (10.x, 172.16-31.x, 192.168.x)
        if (filter_var($host, FILTER_VALIDATE_IP)) {
            // Block link-local / metadata (169.254.x.x — AWS/GCP metadata endpoint)
            if (strpos($host, '169.254.') === 0) {
                return false;
            }
            // Block 0.0.0.0
            if ($host === '0.0.0.0') {
                return false;
            }
            // Allow private ranges
            if (!filter_var($host, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) {
                return true;
            }
            // Block public IPs
            return false;
        }

        // Validate port range
        if ($port < self::MIN_PORT || $port > self::MAX_PORT) {
            if ($port !== self::DEFAULT_PORT) {
                return false;
            }
        }

        // Block anything with dots (external domains)
        if (strpos($host, '.') !== false) {
            return false;
        }

        // Allow single-word hostnames (Docker service names like 'redis-cache')
        return (bool) preg_match('/^[a-zA-Z][a-zA-Z0-9_-]*$/', $host);
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
