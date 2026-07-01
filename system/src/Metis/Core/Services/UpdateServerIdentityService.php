<?php
declare(strict_types=1);

namespace Metis\Core\Services;

use Metis\Core\Version;

final class UpdateServerIdentityService {
    private const IDENTITY_PATH = 'storage/private-records/update-server/identity.json';

    public function __construct(
        private readonly ConfigService $config = new ConfigService(),
        private readonly FileService $files = new FileService(),
        private readonly LoggerService $logger = new LoggerService()
    ) {}

    public function state(): array {
        return $this->files->readJson($this->files->rootPath(self::IDENTITY_PATH), []);
    }

    public function ensureIdentity(array $context = []): array {
        $state = $this->state();
        if ($this->hasUsableIdentity($state)) {
            return $state;
        }

        if (!\function_exists('openssl_pkey_new')) {
            throw new \RuntimeException('OpenSSL is required to generate the update-server installation keypair.');
        }

        $resource = \openssl_pkey_new([
            'private_key_bits' => 2048,
            'private_key_type' => OPENSSL_KEYTYPE_RSA,
        ]);
        if ($resource === false) {
            throw new \RuntimeException('Unable to generate the update-server installation keypair.');
        }

        $privateKey = '';
        if (!\openssl_pkey_export($resource, $privateKey) || trim($privateKey) === '') {
            throw new \RuntimeException('Unable to export the update-server installation private key.');
        }

        $details = \openssl_pkey_get_details($resource);
        $publicKey = is_array($details) ? trim((string) ($details['key'] ?? '')) : '';
        if ($publicKey === '') {
            throw new \RuntimeException('Unable to export the update-server installation public key.');
        }

        $payload = [
            'machine_uuid' => $this->uuidV4(),
            'installation_id' => '',
            'installation_name' => $this->installationName($context),
            'channel' => trim((string) ($context['channel'] ?? 'stable')) ?: 'stable',
            'base_url' => $this->baseUrl($context),
            'server_fingerprint' => $this->serverFingerprint($context),
            'public_key_pem' => $publicKey,
            'private_key_pem' => $privateKey,
            'public_key_sha256' => hash('sha256', $publicKey),
            'registered_at' => '',
            'last_registered_at' => '',
            'last_seen_at' => '',
            'metis_version' => (string) ($context['metis_version'] ?? Version::current()),
        ];

        $this->files->writeJson($this->files->rootPath(self::IDENTITY_PATH), $payload);
        $this->logger->activity('update_server_identity_generated', [
            'machine_uuid' => $payload['machine_uuid'],
            'public_key_sha256' => $payload['public_key_sha256'],
        ]);

        return $payload;
    }

    public function signedHeaders(string $method, string $path, string $body, array $context = []): array {
        $identity = $this->ensureIdentity($context);
        $installationId = trim((string) ($identity['installation_id'] ?? ''));
        if ($installationId === '') {
            throw new \RuntimeException('Update-server installation has not been registered yet.');
        }

        $timestamp = gmdate('c');
        $nonce = bin2hex(random_bytes(16));
        $canonical = $this->canonicalPayload($method, $path, $timestamp, $nonce, $body);
        $signature = $this->sign($canonical, (string) $identity['private_key_pem']);

        return [
            'X-Metis-Installation-Id' => $installationId,
            'X-Metis-Timestamp' => $timestamp,
            'X-Metis-Nonce' => $nonce,
            'X-Metis-Signature' => $signature,
            'X-Metis-Key-Sha256' => (string) ($identity['public_key_sha256'] ?? ''),
        ];
    }

    public function registrationHeaders(string $method, string $path, string $body, array $context = []): array {
        $identity = $this->ensureIdentity($context);
        $timestamp = gmdate('c');
        $nonce = bin2hex(random_bytes(16));
        $canonical = $this->canonicalPayload($method, $path, $timestamp, $nonce, $body);
        $signature = $this->sign($canonical, (string) $identity['private_key_pem']);

        return [
            'X-Metis-Timestamp' => $timestamp,
            'X-Metis-Nonce' => $nonce,
            'X-Metis-Signature' => $signature,
            'X-Metis-Key-Sha256' => (string) ($identity['public_key_sha256'] ?? ''),
        ];
    }

    public function registrationPayload(array $context = []): array {
        $identity = $this->ensureIdentity($context);

        return [
            'installation_name' => $this->installationName($context),
            'base_url' => $this->baseUrl($context),
            'channel' => trim((string) ($context['channel'] ?? $identity['channel'] ?? 'stable')) ?: 'stable',
            'machine_uuid' => (string) ($identity['machine_uuid'] ?? ''),
            'server_fingerprint' => $this->serverFingerprint($context),
            'public_key_pem' => (string) ($identity['public_key_pem'] ?? ''),
            'public_key_sha256' => (string) ($identity['public_key_sha256'] ?? ''),
            'metis_version' => (string) ($context['metis_version'] ?? Version::current()),
            'php_version' => PHP_VERSION,
            'module_inventory' => array_values((array) ($context['module_inventory'] ?? [])),
        ];
    }

    public function markRegistered(array $response, array $context = []): array {
        $identity = $this->ensureIdentity($context);
        $installationId = trim((string) ($response['installation_id'] ?? ''));
        if ($installationId === '') {
            throw new \RuntimeException('Update server registration response is missing the installation id.');
        }

        $identity['installation_id'] = $installationId;
        $identity['registered_at'] = trim((string) ($identity['registered_at'] ?? '')) ?: gmdate('c');
        $identity['last_registered_at'] = gmdate('c');
        $identity['last_seen_at'] = gmdate('c');
        $identity['base_url'] = $this->baseUrl($context);
        $identity['server_fingerprint'] = $this->serverFingerprint($context);
        $identity['channel'] = trim((string) ($context['channel'] ?? $identity['channel'] ?? 'stable')) ?: 'stable';
        $identity['installation_name'] = $this->installationName($context);
        $identity['metis_version'] = (string) ($context['metis_version'] ?? Version::current());

        $this->files->writeJson($this->files->rootPath(self::IDENTITY_PATH), $identity);
        return $identity;
    }

    public function touchLastSeen(): void {
        $state = $this->state();
        if ($state === []) {
            return;
        }

        $state['last_seen_at'] = gmdate('c');
        $this->files->writeJson($this->files->rootPath(self::IDENTITY_PATH), $state);
    }

    private function hasUsableIdentity(array $state): bool {
        return trim((string) ($state['machine_uuid'] ?? '')) !== ''
            && trim((string) ($state['public_key_pem'] ?? '')) !== ''
            && trim((string) ($state['private_key_pem'] ?? '')) !== ''
            && trim((string) ($state['public_key_sha256'] ?? '')) !== '';
    }

    private function sign(string $payload, string $privateKeyPem): string {
        $privateKey = \openssl_pkey_get_private($privateKeyPem);
        if ($privateKey === false) {
            throw new \RuntimeException('Update-server installation private key could not be loaded.');
        }

        $signature = '';
        if (!\openssl_sign($payload, $signature, $privateKey, OPENSSL_ALGO_SHA256) || $signature === '') {
            throw new \RuntimeException('Update-server request signing failed.');
        }

        return base64_encode($signature);
    }

    private function canonicalPayload(string $method, string $path, string $timestamp, string $nonce, string $body): string {
        return strtoupper(trim($method)) . "\n"
            . trim($path) . "\n"
            . trim($timestamp) . "\n"
            . trim($nonce) . "\n"
            . hash('sha256', $body);
    }

    private function installationName(array $context): string {
        $explicit = trim((string) ($context['installation_name'] ?? ''));
        if ($explicit !== '') {
            return $explicit;
        }

        $host = parse_url($this->baseUrl($context), PHP_URL_HOST);
        if (is_string($host) && $host !== '') {
            return $host;
        }

        return (string) (gethostname() ?: 'metis-installation');
    }

    private function baseUrl(array $context): string {
        $explicit = trim((string) ($context['base_url'] ?? ''));
        if ($explicit !== '') {
            return rtrim($explicit, '/');
        }

        if (\function_exists('metis_runtime_config_get')) {
            $runtime = trim((string) \metis_runtime_config_get('base_url', ''));
            if ($runtime !== '') {
                return rtrim($runtime, '/');
            }
        }

        $databaseConfig = $this->config->loadFile('config/database.php', []);
        $configured = trim((string) ($databaseConfig['base_url'] ?? ''));
        return $configured !== '' ? rtrim($configured, '/') : '';
    }

    private function serverFingerprint(array $context): string {
        $parts = [
            $this->baseUrl($context),
            (string) (gethostname() ?: php_uname('n')),
            php_uname('m'),
            $this->files->rootPath(),
        ];

        $databaseConfig = $this->config->loadFile('config/database.php', []);
        $parts[] = trim((string) ($databaseConfig['host'] ?? ''));
        $parts[] = trim((string) ($databaseConfig['database'] ?? ''));

        return hash('sha256', implode('|', $parts));
    }

    private function uuidV4(): string {
        $bytes = random_bytes(16);
        $bytes[6] = chr((ord($bytes[6]) & 0x0f) | 0x40);
        $bytes[8] = chr((ord($bytes[8]) & 0x3f) | 0x80);
        $hex = bin2hex($bytes);

        return sprintf(
            '%s-%s-%s-%s-%s',
            substr($hex, 0, 8),
            substr($hex, 8, 4),
            substr($hex, 12, 4),
            substr($hex, 16, 4),
            substr($hex, 20, 12)
        );
    }
}
