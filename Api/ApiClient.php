<?php
/**
 * OAuth2 API client for Transparent Edge CDN
 *
 * Handles authentication with token caching and all API operations:
 * tag invalidation, URL invalidation, and soft purge/refetch.
 *
 * @package   TransparentEdge_CDN
 * @author    Transparent Edge Services
 * @copyright Copyright (c) 2025 Transparent Edge Services
 * @license   MIT
 */
declare(strict_types=1);

namespace TransparentEdge\CDN\Api;

use TransparentEdge\CDN\Model\Config;
use GuzzleHttp\Client;
use GuzzleHttp\ClientFactory;
use GuzzleHttp\Exception\GuzzleException;
use Magento\Framework\App\CacheInterface;
use Psr\Log\LoggerInterface;

class ApiClient
{
    /**
     * Cache key for OAuth2 token
     */
    private const TOKEN_CACHE_KEY = 'transparentedge_oauth2_token';
    private const TOKEN_CACHE_TAG = 'TRANSPARENTEDGE_API';

    /**
     * Token expiry safety margin (seconds) — refresh 5 min before expiry
     */
    private const TOKEN_SAFETY_MARGIN = 300;

    /**
     * @var Config
     */
    private Config $config;

    /**
     * @var ClientFactory
     */
    private ClientFactory $clientFactory;

    /**
     * @var CacheInterface
     */
    private CacheInterface $cache;

    /**
     * @var LoggerInterface
     */
    private LoggerInterface $logger;

    /**
     * @var string|null In-memory token for the current request
     */
    private ?string $token = null;

    /**
     * @param Config          $config
     * @param ClientFactory   $clientFactory
     * @param CacheInterface  $cache
     * @param LoggerInterface $logger
     */
    public function __construct(
        Config          $config,
        ClientFactory   $clientFactory,
        CacheInterface  $cache,
        LoggerInterface $logger
    ) {
        $this->config        = $config;
        $this->clientFactory = $clientFactory;
        $this->cache         = $cache;
        $this->logger        = $logger;
    }

    // ──────────────────────────────────────────────
    // Public API methods
    // ──────────────────────────────────────────────

    /**
     * Invalidate cache by Surrogate-Key tags
     *
     * @param  array $tags Array of Surrogate-Key tag strings
     * @return array{success: bool, status: int, message: string}
     */
    public function invalidateByTags(array $tags): array
    {
        if (empty($tags)) {
            return ['success' => false, 'status' => 0, 'message' => 'No tags provided'];
        }

        $endpoint = $this->config->getInvalidateEndpoint();
        $payload  = ['tags' => $tags];

        // Soft purge support
        if ($this->config->isSoftPurgeEnabled()) {
            $payload['soft_purge'] = true;
        }

        // Refetch support
        if ($this->config->isRefetchEnabled()) {
            $payload['refetch'] = true;
        }

        $this->logger->info('TransparentEdge: Invalidating tags', [
            'tags'  => $tags,
            'count' => count($tags),
        ]);

        return $this->post($endpoint, $payload);
    }

    /**
     * Invalidate cache by URLs (fallback method)
     *
     * @param  array $urls Array of full URL strings
     * @return array{success: bool, status: int, message: string}
     */
    public function invalidateByUrls(array $urls): array
    {
        if (empty($urls)) {
            return ['success' => false, 'status' => 0, 'message' => 'No URLs provided'];
        }

        $endpoint = $this->config->getUrlInvalidateEndpoint();
        $payload  = ['urls' => $urls];

        $this->logger->info('TransparentEdge: Invalidating URLs', [
            'urls'  => $urls,
            'count' => count($urls),
        ]);

        return $this->post($endpoint, $payload);
    }

    /**
     * Ban all CDN cache for the company
     *
     * Uses tag-based invalidation (ban) with the global 'te-all' tag.
     * This is a ban, not a purge: it marks all objects as stale instantly
     * and appears as a ban in the Transparent Edge Dashboard.
     *
     * Falls back to URL-based purge only if ban fails (e.g., for objects
     * cached before the plugin started injecting Surrogate-Key headers).
     *
     * @return array{success: bool, status: int, message: string}
     */
    public function purgeAll(): array
    {
        $this->logger->warning('TransparentEdge: Full cache ban requested');

        // Primary: ban via tag_invalidate with global tag
        $result = $this->invalidateByTags(['te-all']);

        if ($result['success']) {
            return $result;
        }

        // Fallback: URL-based purge (catches pre-plugin cached objects)
        $this->logger->warning('TransparentEdge: Tag ban failed, trying URL-based purge fallback');
        return $this->invalidateByUrls([$this->config->getBaseUrl() . '/']);
    }

    /**
     * Test API connection with current credentials
     *
     * @return array{success: bool, message: string, token_preview: string}
     */
    public function testConnection(): array
    {
        try {
            $token = $this->fetchNewToken();
            if ($token) {
                return [
                    'success'       => true,
                    'message'       => 'Connection successful. OAuth2 token obtained.',
                    'token_preview' => substr($token, 0, 12) . '...',
                ];
            }
            return [
                'success'       => false,
                'message'       => 'Could not obtain OAuth2 token. Check your credentials.',
                'token_preview' => '',
            ];
        } catch (\Exception $e) {
            return [
                'success'       => false,
                'message'       => 'Connection failed: ' . $e->getMessage(),
                'token_preview' => '',
            ];
        }
    }

    // ──────────────────────────────────────────────
    // OAuth2 token management
    // ──────────────────────────────────────────────

    /**
     * Get a valid OAuth2 access token (from cache or fresh)
     *
     * @return string|null
     */
    public function getToken(): ?string
    {
        // 1. In-memory cache (same PHP request)
        if ($this->token !== null) {
            return $this->token;
        }

        // 2. Magento cache backend (Redis/file)
        $cached = $this->cache->load(self::TOKEN_CACHE_KEY);
        if ($cached) {
            $this->token = $cached;
            return $this->token;
        }

        // 3. Fetch new token
        $token = $this->fetchNewToken();
        if ($token) {
            $this->token = $token;
            return $this->token;
        }

        return null;
    }

    /**
     * Fetch a new OAuth2 token from the API
     *
     * @return string|null
     */
    private function fetchNewToken(): ?string
    {
        $client = $this->createClient();

        try {
            $response = $client->request('POST', $this->config->getTokenEndpoint(), [
                'form_params' => [
                    'grant_type'    => 'client_credentials',
                    'client_id'     => $this->config->getClientId(),
                    'client_secret' => $this->config->getClientSecret(),
                ],
            ]);

            if ($response->getStatusCode() === 200) {
                $body = json_decode($response->getBody()->getContents(), true);

                if (isset($body['access_token'])) {
                    $token    = $body['access_token'];
                    $lifetime = (int) ($body['expires_in'] ?? 3600) - self::TOKEN_SAFETY_MARGIN;
                    $lifetime = max($lifetime, 60); // At least 60 seconds

                    // Store in Magento cache
                    $this->cache->save(
                        $token,
                        self::TOKEN_CACHE_KEY,
                        [self::TOKEN_CACHE_TAG],
                        $lifetime
                    );

                    $this->logger->debug('TransparentEdge: OAuth2 token obtained', [
                        'expires_in' => $body['expires_in'] ?? 'unknown',
                        'cache_ttl'  => $lifetime,
                    ]);

                    return $token;
                }
            }

            $this->logger->error('TransparentEdge: Failed to obtain OAuth2 token', [
                'status' => $response->getStatusCode(),
            ]);
        } catch (GuzzleException $e) {
            $this->logger->error('TransparentEdge: OAuth2 request failed', [
                'error' => $e->getMessage(),
            ]);
        }

        return null;
    }

    /**
     * Clear the cached token (for re-auth scenarios)
     */
    public function clearToken(): void
    {
        $this->token = null;
        $this->cache->remove(self::TOKEN_CACHE_KEY);
    }

    // ──────────────────────────────────────────────
    // HTTP helpers
    // ──────────────────────────────────────────────

    /**
     * Make an authenticated POST request to the TE API
     *
     * @param  string $url     Full endpoint URL
     * @param  array  $payload Request body
     * @return array{success: bool, status: int, message: string}
     */
    private function post(string $url, array $payload): array
    {
        $token = $this->getToken();
        if (!$token) {
            return [
                'success' => false,
                'status'  => 0,
                'message' => 'Could not obtain OAuth2 token',
            ];
        }

        $client = $this->createClient();

        try {
            $response = $client->request('POST', $url, [
                'headers' => [
                    'Authorization' => 'Bearer ' . $token,
                    'Content-Type'  => 'application/json',
                    'Accept'        => 'application/json',
                ],
                'json' => $payload,
            ]);

            $status = $response->getStatusCode();
            $body   = $response->getBody()->getContents();

            if ($status >= 200 && $status < 300) {
                $this->logger->info('TransparentEdge: API request successful', [
                    'url'    => $url,
                    'status' => $status,
                ]);

                return [
                    'success' => true,
                    'status'  => $status,
                    'message' => $body,
                ];
            }

            // Token might be expired — retry once
            if ($status === 401) {
                $this->clearToken();
                $newToken = $this->getToken();
                if ($newToken) {
                    $retryResponse = $client->request('POST', $url, [
                        'headers' => [
                            'Authorization' => 'Bearer ' . $newToken,
                            'Content-Type'  => 'application/json',
                            'Accept'        => 'application/json',
                        ],
                        'json' => $payload,
                    ]);

                    $retryStatus = $retryResponse->getStatusCode();
                    if ($retryStatus >= 200 && $retryStatus < 300) {
                        return [
                            'success' => true,
                            'status'  => $retryStatus,
                            'message' => $retryResponse->getBody()->getContents(),
                        ];
                    }
                }
            }

            $this->logger->error('TransparentEdge: API request failed', [
                'url'    => $url,
                'status' => $status,
                'body'   => $body,
            ]);

            return [
                'success' => false,
                'status'  => $status,
                'message' => $body,
            ];
        } catch (GuzzleException $e) {
            $this->logger->error('TransparentEdge: API request exception', [
                'url'   => $url,
                'error' => $e->getMessage(),
            ]);

            return [
                'success' => false,
                'status'  => $e->getCode(),
                'message' => $e->getMessage(),
            ];
        }
    }

    /**
     * Create a Guzzle HTTP client instance
     *
     * @return Client
     */
    private function createClient(): Client
    {
        return $this->clientFactory->create([
            'config' => [
                'timeout'         => 30,
                'connect_timeout' => 10,
                'http_errors'     => false,
            ],
        ]);
    }
}
