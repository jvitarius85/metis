<?php
declare(strict_types=1);

namespace Metis\Core\Services;

use Metis\Core\Cache\CacheService;
use Metis\Core\Version;

final class UpdateServerClient {
    private const CORE_CACHE_KEY = 'update_server.core';
    private const MODULE_CACHE_KEY = 'update_server.modules';
    private const CACHE_TTL = 21600;

    public function __construct(
        private readonly HttpClient $http = new HttpClient(),
        private readonly ConfigService $config = new ConfigService(),
        private readonly FileService $files = new FileService(),
        private readonly LoggerService $logger = new LoggerService(),
        private readonly UpdateServerIdentityService $identity = new UpdateServerIdentityService()
    ) {}

    public function isEnabled(): bool {
        $settings = $this->settings();
        return strtolower(trim((string) ($settings['source'] ?? 'github'))) === 'update_server';
    }

    public function settings(): array {
        $fileConfig = $this->config->loadFile('config/update.php', []);
        $server = is_array($fileConfig['update_server'] ?? null) ? (array) $fileConfig['update_server'] : [];
        return [
            'source' => (string) ($fileConfig['source'] ?? 'github'),
            'base_url' => rtrim(trim((string) ($server['base_url'] ?? '')), '/'),
            'channel' => trim((string) ($server['channel'] ?? 'stable')) ?: 'stable',
            'server_public_key' => trim((string) ($server['server_public_key'] ?? '')),
            'connect_timeout' => max(1, (int) ($server['connect_timeout'] ?? 10)),
            'timeout' => max(1, (int) ($server['timeout'] ?? 30)),
            'verify_registration' => array_key_exists('verify_registration', $server) ? (bool) $server['verify_registration'] : true,
        ];
    }

    public function registerInstallation(array $context = []): array {
        $settings = $this->settings();
        if ($settings['base_url'] === '') {
            throw new \RuntimeException('Update server base URL is not configured.');
        }

        $payload = $this->identity->registrationPayload($context + [
            'channel' => $settings['channel'],
            'metis_version' => Version::current(),
        ]);
        $body = json_encode($payload, JSON_UNESCAPED_SLASHES);
        if (!is_string($body)) {
            throw new \RuntimeException('Unable to encode the update-server registration payload.');
        }

        $path = '/api/installations/register';
        $headers = $this->identity->registrationHeaders('POST', $path, $body, $context);
        $response = $this->http->request(
            'POST',
            $settings['base_url'] . $path,
            $headers + [ 'Content-Type' => 'application/json' ],
            $body,
            [
                'timeout' => $settings['timeout'],
                'connect_timeout' => $settings['connect_timeout'],
            ]
        );
        if ((int) ($response['status'] ?? 0) >= 400) {
            $message = trim((string) (($response['json']['error'] ?? '') ?: ($response['body'] ?? '')));
            throw new \RuntimeException($message !== '' ? $message : 'Update server registration failed.');
        }

        $json = is_array($response['json'] ?? null) ? (array) $response['json'] : [];
        $this->identity->markRegistered($json, $context + [ 'channel' => $settings['channel'] ]);
        $this->logger->activity('update_server_registered', [
            'installation_id' => (string) ($json['installation_id'] ?? ''),
            'base_url' => $settings['base_url'],
        ]);

        return $json;
    }

    public function ensureRegistered(array $context = []): array {
        $state = $this->identity->state();
        if (trim((string) ($state['installation_id'] ?? '')) !== '') {
            return $state;
        }

        $response = $this->registerInstallation($context);
        return $this->identity->state() + [ 'registration_response' => $response ];
    }

    public function checkForUpdates(bool $forceRefresh = false, array $moduleInventory = []): array {
        if (!$forceRefresh) {
            $cached = CacheService::get(self::CORE_CACHE_KEY);
            if (is_array($cached)) {
                return $cached;
            }
        } else {
            CacheService::forget(self::CORE_CACHE_KEY);
            CacheService::forget(self::MODULE_CACHE_KEY);
        }

        $settings = $this->settings();
        $this->ensureRegistered([
            'channel' => $settings['channel'],
            'module_inventory' => $moduleInventory,
        ]);

        $payload = [
            'channel' => $settings['channel'],
            'core' => [
                'version' => Version::current(),
            ],
            'modules' => array_values($moduleInventory),
            'php_version' => PHP_VERSION,
        ];
        $body = json_encode($payload, JSON_UNESCAPED_SLASHES);
        if (!is_string($body)) {
            throw new \RuntimeException('Unable to encode the update-server update-check payload.');
        }

        $path = '/api/updates/check';
        $headers = $this->identity->signedHeaders('POST', $path, $body, [
            'channel' => $settings['channel'],
        ]);
        $response = $this->http->request(
            'POST',
            $settings['base_url'] . $path,
            $headers + [ 'Content-Type' => 'application/json' ],
            $body,
            [
                'timeout' => $settings['timeout'],
                'connect_timeout' => $settings['connect_timeout'],
            ]
        );
        if ((int) ($response['status'] ?? 0) >= 400) {
            $message = trim((string) (($response['json']['error'] ?? '') ?: ($response['body'] ?? '')));
            throw new \RuntimeException($message !== '' ? $message : 'Update server update check failed.');
        }

        $json = is_array($response['json'] ?? null) ? (array) $response['json'] : [];
        $core = is_array($json['core'] ?? null) ? (array) $json['core'] : [];
        $modules = is_array($json['modules'] ?? null) ? (array) $json['modules'] : [];
        $normalized = [
            'current_version' => Version::current(),
            'latest_version' => trim((string) ($core['latest_version'] ?? '')),
            'update_available' => !empty($core['update_available']),
            'release_notes' => (string) ($core['release_notes'] ?? ''),
            'download_url' => (string) ($core['download_url'] ?? ''),
            'published_at' => (string) ($core['published_at'] ?? ''),
            'name' => (string) ($core['name'] ?? ''),
            'tag_name' => (string) ($core['tag_name'] ?? ''),
            'sha256' => (string) ($core['sha256'] ?? ''),
            'package_type' => (string) ($core['package_type'] ?? ''),
            'modules' => $modules,
        ];

        CacheService::set(self::CORE_CACHE_KEY, $normalized, self::CACHE_TTL);
        CacheService::set(self::MODULE_CACHE_KEY, $modules, self::CACHE_TTL);
        $this->identity->touchLastSeen();

        return $normalized;
    }

    public function requestCronProbe(string $trigger = 'installer_probe'): array {
        $settings = $this->settings();
        if ($settings['base_url'] === '') {
            throw new \RuntimeException('Update server base URL is not configured.');
        }

        $body = json_encode([
            'trigger' => trim($trigger) !== '' ? trim($trigger) : 'installer_probe',
            'core' => [
                'version' => Version::current(),
            ],
        ], JSON_UNESCAPED_SLASHES);
        if (!is_string($body)) {
            throw new \RuntimeException('Unable to encode the update-server cron probe payload.');
        }

        $path = '/api/installations/cron-probe';
        $headers = $this->identity->signedHeaders('POST', $path, $body, [
            'channel' => $settings['channel'],
        ]);
        $response = $this->http->request(
            'POST',
            $settings['base_url'] . $path,
            $headers + [ 'Content-Type' => 'application/json' ],
            $body,
            [
                'timeout' => $settings['timeout'],
                'connect_timeout' => $settings['connect_timeout'],
            ]
        );
        if ((int) ($response['status'] ?? 0) >= 400) {
            $message = trim((string) (($response['json']['error'] ?? '') ?: ($response['body'] ?? '')));
            throw new \RuntimeException($message !== '' ? $message : 'Update server cron probe failed.');
        }

        return is_array($response['json'] ?? null) ? (array) $response['json'] : [];
    }

    public function moduleRegistry(bool $forceRefresh = false, array $moduleInventory = []): array {
        if (!$forceRefresh) {
            $cached = CacheService::get(self::MODULE_CACHE_KEY);
            if (is_array($cached)) {
                return [
                    'status' => 'ready',
                    'generated_at' => gmdate('c'),
                    'modules' => is_array($cached['registry'] ?? null)
                        ? (array) $cached['registry']
                        : (is_array($cached['modules'] ?? null) ? (array) $cached['modules'] : []),
                ];
            }
        }

        $payload = $this->checkForUpdates($forceRefresh, $moduleInventory);
        $modules = is_array($payload['modules'] ?? null) ? (array) $payload['modules'] : [];

        return [
            'status' => 'ready',
            'generated_at' => gmdate('c'),
            'modules' => is_array($modules['registry'] ?? null) ? (array) $modules['registry'] : [],
        ];
    }

    public function manifestReleases(bool $forceRefresh = false, array $moduleInventory = []): array {
        $payload = $this->checkForUpdates($forceRefresh, $moduleInventory);
        $release = [
            'tag' => (string) ($payload['tag_name'] ?? ''),
            'version' => (string) ($payload['latest_version'] ?? ''),
            'published_at' => (string) ($payload['published_at'] ?? ''),
            'download_url' => (string) ($payload['download_url'] ?? ''),
            'sha256' => (string) ($payload['sha256'] ?? ''),
            'package_type' => (string) ($payload['package_type'] ?? ''),
            'source' => 'update_server',
        ];

        return trim($release['tag']) !== '' && trim($release['version']) !== '' ? [ $release ] : [];
    }

    public function semanticTagReleases(bool $forceRefresh = false, array $moduleInventory = []): array {
        return $this->manifestReleases($forceRefresh, $moduleInventory);
    }

    public function pollConfiguredRepositories(bool $forceRefresh = false, array $moduleInventory = []): array {
        $settings = $this->settings();
        $snapshot = [
            'checked_at' => gmdate('c'),
            'ok' => false,
            'core' => [
                'repository' => 'update_server_core',
                'owner' => 'update_server',
                'repo' => 'core',
                'ref' => $settings['channel'],
                'status' => 'unconfigured',
                'ok' => false,
                'release_status' => 'unavailable',
                'tag_status' => 'unavailable',
                'latest_version' => '',
                'latest_tag' => '',
                'release_count' => 0,
                'error' => '',
            ],
            'metadata' => [
                'repository' => 'update_server_modules',
                'owner' => 'update_server',
                'repo' => 'modules',
                'ref' => $settings['channel'],
                'status' => 'unconfigured',
                'ok' => false,
                'registry_status' => 'unavailable',
                'manifest_status' => 'unavailable',
                'module_count' => 0,
                'release_count' => 0,
                'generated_at' => '',
                'error' => '',
            ],
        ];

        if ($settings['base_url'] === '') {
            $snapshot['core']['error'] = 'Update server base URL is not configured.';
            $snapshot['metadata']['error'] = 'Update server base URL is not configured.';
            return $snapshot;
        }

        try {
            $payload = $this->checkForUpdates($forceRefresh, $moduleInventory);
            $modules = is_array($payload['modules'] ?? null) ? (array) $payload['modules'] : [];
            $registry = is_array($modules['registry'] ?? null) ? (array) $modules['registry'] : [];
            $snapshot['core']['status'] = 'ready';
            $snapshot['core']['ok'] = true;
            $snapshot['core']['release_status'] = 'ready';
            $snapshot['core']['tag_status'] = 'ready';
            $snapshot['core']['latest_version'] = (string) ($payload['latest_version'] ?? '');
            $snapshot['core']['latest_tag'] = (string) ($payload['tag_name'] ?? '');
            $snapshot['core']['release_count'] = $snapshot['core']['latest_tag'] !== '' ? 1 : 0;
            $snapshot['metadata']['status'] = 'ready';
            $snapshot['metadata']['ok'] = true;
            $snapshot['metadata']['registry_status'] = 'ready';
            $snapshot['metadata']['manifest_status'] = 'ready';
            $snapshot['metadata']['module_count'] = count($registry);
            $snapshot['metadata']['release_count'] = $snapshot['core']['release_count'];
            $snapshot['metadata']['generated_at'] = gmdate('c');
            $snapshot['ok'] = true;
        } catch (\Throwable $exception) {
            $snapshot['core']['status'] = 'failed';
            $snapshot['core']['error'] = $exception->getMessage();
            $snapshot['metadata']['status'] = 'failed';
            $snapshot['metadata']['error'] = $exception->getMessage();
        }

        return $snapshot;
    }

    public function downloadReleaseArchive(string $tag, string $destination, array $moduleInventory = []): array {
        $manifest = $this->manifestReleases(false, $moduleInventory);
        foreach ($manifest as $release) {
            if (trim((string) ($release['tag'] ?? '')) === trim($tag)) {
                return $this->downloadRemoteFile((string) ($release['download_url'] ?? ''), $destination);
            }
        }

        throw new \RuntimeException(sprintf('Update server does not have a downloadable core archive for [%s].', $tag));
    }

    public function downloadModuleArchive(string $downloadUrl, string $destination): array {
        return $this->downloadRemoteFile($downloadUrl, $destination);
    }

    private function downloadRemoteFile(string $downloadUrl, string $destination): array {
        $downloadUrl = trim($downloadUrl);
        if ($downloadUrl === '') {
            throw new \RuntimeException('Update package download URL is missing.');
        }

        $directory = dirname($destination);
        if (!is_dir($directory) && !mkdir($directory, 0775, true) && !is_dir($directory)) {
            throw new \RuntimeException('Unable to create update download directory.');
        }

        if (\function_exists('curl_init')) {
            $fp = @fopen($destination, 'wb');
            if (!is_resource($fp)) {
                throw new \RuntimeException('Unable to create the update download file.');
            }

            $ch = curl_init($downloadUrl);
            if ($ch === false) {
                fclose($fp);
                throw new \RuntimeException('Unable to initialize the update download.');
            }

            curl_setopt_array($ch, [
                CURLOPT_FILE => $fp,
                CURLOPT_FOLLOWLOCATION => true,
                CURLOPT_MAXREDIRS => 3,
                CURLOPT_TIMEOUT => 120,
                CURLOPT_SSL_VERIFYPEER => true,
                CURLOPT_SSL_VERIFYHOST => 2,
            ]);
            if (\defined('CURLOPT_PROTOCOLS') && \defined('CURLPROTO_HTTP') && \defined('CURLPROTO_HTTPS')) {
                curl_setopt($ch, CURLOPT_PROTOCOLS, CURLPROTO_HTTP | CURLPROTO_HTTPS);
            }
            if (\defined('CURLOPT_REDIR_PROTOCOLS') && \defined('CURLPROTO_HTTP') && \defined('CURLPROTO_HTTPS')) {
                curl_setopt($ch, CURLOPT_REDIR_PROTOCOLS, CURLPROTO_HTTP | CURLPROTO_HTTPS);
            }

            $ok = curl_exec($ch);
            $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
            $error = curl_error($ch);
            if (\PHP_VERSION_ID < 80500) {
                curl_close($ch);
            }
            fclose($fp);

            if ($ok !== true || $status >= 400 || !is_file($destination) || filesize($destination) < 1) {
                @unlink($destination);
                throw new \RuntimeException($error !== '' ? $error : sprintf('Update package download failed with status [%d].', $status));
            }
        } else {
            $data = @file_get_contents($downloadUrl);
            if (!is_string($data) || $data === '') {
                throw new \RuntimeException('Update package download failed.');
            }
            if (@file_put_contents($destination, $data, LOCK_EX) === false) {
                throw new \RuntimeException('Unable to save the update package.');
            }
        }

        return [
            'url' => $downloadUrl,
            'path' => $destination,
            'bytes' => (int) filesize($destination),
            'sha256' => (string) hash_file('sha256', $destination),
        ];
    }
}
