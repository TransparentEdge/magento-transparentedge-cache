<?php
/**
 * Redis manager for Magento 2 env.php
 *
 * Manages Redis configuration in app/etc/env.php for three backends:
 * - Default cache (object cache)
 * - Full Page Cache (FPC)
 * - Sessions
 *
 * Creates automatic backups before any change and supports rollback
 * if Redis becomes unavailable.
 *
 * @package   TransparentEdge_CDN
 * @author    Transparent Edge Services
 * @copyright Copyright (c) 2025 Transparent Edge Services
 * @license   MIT
 */
declare(strict_types=1);

namespace TransparentEdge\CDN\Model\Redis;

use Magento\Framework\App\Filesystem\DirectoryList;
use Psr\Log\LoggerInterface;

class RedisManager
{
    /**
     * @var DirectoryList
     */
    private DirectoryList $directoryList;

    /**
     * @var RedisDetector
     */
    private RedisDetector $detector;

    /**
     * @var LoggerInterface
     */
    private LoggerInterface $logger;

    /**
     * @param DirectoryList   $directoryList
     * @param RedisDetector   $detector
     * @param LoggerInterface $logger
     */
    public function __construct(
        DirectoryList   $directoryList,
        RedisDetector   $detector,
        LoggerInterface $logger
    ) {
        $this->directoryList = $directoryList;
        $this->detector      = $detector;
        $this->logger        = $logger;
    }

    /**
     * Get the current Redis status for all three backends
     *
     * @return array{cache: bool, fpc: bool, session: bool, details: array}
     */
    public function getStatus(): array
    {
        $env = $this->readEnvPhp();

        return [
            'cache'   => $this->isCacheRedis($env),
            'fpc'     => $this->isFpcRedis($env),
            'session' => $this->isSessionRedis($env),
            'details' => [
                'cache'   => $this->getCacheBackendInfo($env),
                'fpc'     => $this->getFpcBackendInfo($env),
                'session' => $this->getSessionBackendInfo($env),
            ],
        ];
    }

    /**
     * Enable Redis for default cache (object cache)
     *
     * @param  string $host
     * @param  int    $port
     * @param  string $password
     * @param  int    $database
     * @return array{success: bool, message: string}
     */
    public function enableCache(string $host, int $port, string $password = '', int $database = 0): array
    {
        return $this->enableBackend('cache', $host, $port, $password, $database);
    }

    /**
     * Enable Redis for Full Page Cache
     *
     * @param  string $host
     * @param  int    $port
     * @param  string $password
     * @param  int    $database
     * @return array{success: bool, message: string}
     */
    public function enableFpc(string $host, int $port, string $password = '', int $database = 2): array
    {
        return $this->enableBackend('fpc', $host, $port, $password, $database);
    }

    /**
     * Enable Redis for sessions
     *
     * @param  string $host
     * @param  int    $port
     * @param  string $password
     * @param  int    $database
     * @return array{success: bool, message: string}
     */
    public function enableSession(string $host, int $port, string $password = '', int $database = 3): array
    {
        return $this->enableBackend('session', $host, $port, $password, $database);
    }

    /**
     * Disable Redis for a specific backend (revert to file-based)
     *
     * @param  string $type cache|fpc|session
     * @return array{success: bool, message: string}
     */
    public function disable(string $type): array
    {
        try {
            $env = $this->readEnvPhp();
            $this->createBackup();

            switch ($type) {
                case 'cache':
                    unset($env['cache']['frontend']['default']['backend']);
                    unset($env['cache']['frontend']['default']['backend_options']);
                    if (empty($env['cache']['frontend']['default'])) {
                        unset($env['cache']['frontend']['default']);
                    }
                    break;

                case 'fpc':
                    unset($env['cache']['frontend']['page_cache']['backend']);
                    unset($env['cache']['frontend']['page_cache']['backend_options']);
                    if (empty($env['cache']['frontend']['page_cache'])) {
                        unset($env['cache']['frontend']['page_cache']);
                    }
                    break;

                case 'session':
                    $env['session'] = [
                        'save' => 'files',
                    ];
                    break;
            }

            $this->writeEnvPhp($env);

            $this->logger->info('TransparentEdge: Redis disabled', ['type' => $type]);

            return [
                'success' => true,
                'message' => sprintf('Redis %s disabled. Reverted to file-based storage.', $type),
            ];
        } catch (\Exception $e) {
            $this->logger->error('TransparentEdge: Failed to disable Redis', [
                'type'  => $type,
                'error' => $e->getMessage(),
            ]);
            return [
                'success' => false,
                'message' => 'Failed: ' . $e->getMessage(),
            ];
        }
    }

    /**
     * Rollback env.php to the last backup
     *
     * @return array{success: bool, message: string}
     */
    public function rollback(): array
    {
        try {
            $backupFile = $this->getEnvPath() . '.te-backup';
            if (!file_exists($backupFile)) {
                return ['success' => false, 'message' => 'No backup found.'];
            }

            $envPath = $this->getEnvPath();
            copy($backupFile, $envPath);

            $this->logger->warning('TransparentEdge: env.php rolled back from backup');

            return [
                'success' => true,
                'message' => 'env.php restored from backup successfully.',
            ];
        } catch (\Exception $e) {
            return [
                'success' => false,
                'message' => 'Rollback failed: ' . $e->getMessage(),
            ];
        }
    }

    // ──────────────────────────────────────────────────────────
    // Private helpers
    // ──────────────────────────────────────────────────────────

    /**
     * Enable Redis for a specific backend
     *
     * @param  string $type     cache|fpc|session
     * @param  string $host
     * @param  int    $port
     * @param  string $password
     * @param  int    $database
     * @return array{success: bool, message: string}
     */
    private function enableBackend(string $type, string $host, int $port, string $password, int $database): array
    {
        // Verify Redis is reachable
        $test = $this->detector->testConnection($host, $port, $password ?: null);
        if (!$test['available']) {
            return [
                'success' => false,
                'message' => sprintf('Cannot connect to Redis at %s:%d. Check that Redis is running.', $host, $port),
            ];
        }

        try {
            $env = $this->readEnvPhp();
            $this->createBackup();

            $redisConfig = [
                'server'               => $host,
                'port'                 => (string) $port,
                'database'             => (string) $database,
                'compress_data'        => '1',
                'compress_tags'        => '1',
                'compress_threshold'   => '20480',
                'compression_lib'      => 'gzip',
            ];

            if (!empty($password)) {
                $redisConfig['password'] = $password;
            }

            switch ($type) {
                case 'cache':
                    $env['cache']['frontend']['default'] = [
                        'backend'         => \Magento\Framework\Cache\Backend\Redis::class,
                        'backend_options' => $redisConfig,
                    ];
                    break;

                case 'fpc':
                    $fpcConfig = $redisConfig;
                    $fpcConfig['compress_data'] = '0'; // FPC stores large blobs, compression is slower
                    $env['cache']['frontend']['page_cache'] = [
                        'backend'         => \Magento\Framework\Cache\Backend\Redis::class,
                        'backend_options' => $fpcConfig,
                    ];
                    break;

                case 'session':
                    $sessionConfig = [
                        'save'                  => 'redis',
                        'redis'                 => [
                            'host'                => $host,
                            'port'                => (string) $port,
                            'database'            => (string) $database,
                            'max_concurrency'     => '6',
                            'break_after_frontend' => '5',
                            'break_after_adminhtml' => '30',
                            'first_lifetime'      => '600',
                            'bot_first_lifetime'  => '60',
                            'bot_lifetime'        => '7200',
                            'disable_locking'     => '0',
                            'min_lifetime'        => '60',
                            'max_lifetime'        => '2592000',
                        ],
                    ];
                    if (!empty($password)) {
                        $sessionConfig['redis']['password'] = $password;
                    }
                    $env['session'] = $sessionConfig;
                    break;

                default:
                    return ['success' => false, 'message' => 'Unknown backend type: ' . $type];
            }

            $this->writeEnvPhp($env);

            $this->logger->info('TransparentEdge: Redis enabled', [
                'type'     => $type,
                'host'     => $host,
                'port'     => $port,
                'database' => $database,
            ]);

            return [
                'success' => true,
                'message' => sprintf('Redis %s enabled (db%d at %s:%d).', $type, $database, $host, $port),
            ];
        } catch (\Exception $e) {
            // Attempt rollback
            $this->rollback();

            $this->logger->error('TransparentEdge: Failed to enable Redis, rolled back', [
                'type'  => $type,
                'error' => $e->getMessage(),
            ]);

            return [
                'success' => false,
                'message' => 'Failed: ' . $e->getMessage() . '. env.php has been restored.',
            ];
        }
    }

    /**
     * Read env.php as an array
     *
     * @return array
     */
    private function readEnvPhp(): array
    {
        $path = $this->getEnvPath();
        if (!file_exists($path)) {
            throw new \RuntimeException('env.php not found at: ' . $path);
        }

        $env = include $path;
        if (!is_array($env)) {
            throw new \RuntimeException('env.php does not return an array');
        }

        return $env;
    }

    /**
     * Write env.php from an array
     *
     * @param array $env
     */
    private function writeEnvPhp(array $env): void
    {
        $path = $this->getEnvPath();
        $content = "<?php\nreturn " . var_export($env, true) . ";\n";

        // PHP var_export produces stdClass for empty arrays — fix it
        $content = preg_replace('/stdClass::__set_state\(array\(\s*\)\)/', '[]', $content);

        $result = file_put_contents($path, $content, LOCK_EX);
        if ($result === false) {
            throw new \RuntimeException('Cannot write to env.php. Check file permissions.');
        }
    }

    /**
     * Create a backup of env.php
     */
    private function createBackup(): void
    {
        $path = $this->getEnvPath();
        $backup = $path . '.te-backup';
        copy($path, $backup);

        $this->logger->debug('TransparentEdge: env.php backup created');
    }

    /**
     * Get the full path to env.php
     *
     * @return string
     */
    private function getEnvPath(): string
    {
        return $this->directoryList->getPath(DirectoryList::CONFIG) . '/env.php';
    }

    /**
     * Check if default cache is using Redis
     *
     * @param  array $env
     * @return bool
     */
    private function isCacheRedis(array $env): bool
    {
        $backend = $env['cache']['frontend']['default']['backend'] ?? '';
        return stripos($backend, 'Redis') !== false;
    }

    /**
     * Check if FPC is using Redis
     *
     * @param  array $env
     * @return bool
     */
    private function isFpcRedis(array $env): bool
    {
        $backend = $env['cache']['frontend']['page_cache']['backend'] ?? '';
        return stripos($backend, 'Redis') !== false;
    }

    /**
     * Check if sessions are using Redis
     *
     * @param  array $env
     * @return bool
     */
    private function isSessionRedis(array $env): bool
    {
        return ($env['session']['save'] ?? '') === 'redis';
    }

    /**
     * Get cache backend info for display
     *
     * @param  array $env
     * @return string
     */
    private function getCacheBackendInfo(array $env): string
    {
        if ($this->isCacheRedis($env)) {
            $opts = $env['cache']['frontend']['default']['backend_options'] ?? [];
            return sprintf('Redis db%s (%s:%s)',
                $opts['database'] ?? '?',
                $opts['server'] ?? '?',
                $opts['port'] ?? '?'
            );
        }
        return (string) __('File-based (default)');
    }

    /**
     * Get FPC backend info for display
     *
     * @param  array $env
     * @return string
     */
    private function getFpcBackendInfo(array $env): string
    {
        if ($this->isFpcRedis($env)) {
            $opts = $env['cache']['frontend']['page_cache']['backend_options'] ?? [];
            return sprintf('Redis db%s (%s:%s)',
                $opts['database'] ?? '?',
                $opts['server'] ?? '?',
                $opts['port'] ?? '?'
            );
        }
        return (string) __('File-based (default)');
    }

    /**
     * Get session backend info for display
     *
     * @param  array $env
     * @return string
     */
    private function getSessionBackendInfo(array $env): string
    {
        if ($this->isSessionRedis($env)) {
            $redis = $env['session']['redis'] ?? [];
            return sprintf('Redis db%s (%s:%s)',
                $redis['database'] ?? '?',
                $redis['host'] ?? '?',
                $redis['port'] ?? '?'
            );
        }
        return (string) __('File-based (default)');
    }
}
