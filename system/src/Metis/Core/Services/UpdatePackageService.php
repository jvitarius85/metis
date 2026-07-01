<?php
declare(strict_types=1);

namespace Metis\Core\Services;

final class UpdatePackageService {
    private const MANIFEST_FILE = 'metis-package.json';
    private const SIGNATURE_FILE = 'metis-package.sig';

    public function __construct(
        private readonly FileService $files = new FileService()
    ) {}

    public function inspectExtractedPackage(string $extractRoot): array {
        $extractRoot = rtrim(str_replace('\\', '/', $extractRoot), '/');
        $manifestPath = $extractRoot . '/' . self::MANIFEST_FILE;
        $signaturePath = $extractRoot . '/' . self::SIGNATURE_FILE;
        if (!is_file($manifestPath) || !is_file($signaturePath)) {
            return [];
        }

        $manifest = $this->files->readJson($manifestPath, []);
        if ($manifest === []) {
            return [];
        }

        $payloadRoot = $extractRoot . '/' . trim((string) ($manifest['payload_root'] ?? 'payload'), '/');
        return [
            'manifest' => $manifest,
            'manifest_path' => $manifestPath,
            'signature_path' => $signaturePath,
            'payload_root' => $payloadRoot,
        ];
    }

    public function verifyExtractedPackage(array $package, string $publicKeyPem): void {
        $manifestPath = (string) ($package['manifest_path'] ?? '');
        $signaturePath = (string) ($package['signature_path'] ?? '');
        if ($manifestPath === '' || $signaturePath === '') {
            throw new \RuntimeException('Update package signature files are missing.');
        }

        $manifestRaw = $this->files->read($manifestPath);
        $signatureEncoded = trim($this->files->read($signaturePath));
        $signature = base64_decode($signatureEncoded, true);
        if ($signature === false || $signature === '') {
            throw new \RuntimeException('Update package signature is invalid.');
        }

        $publicKey = \openssl_pkey_get_public($publicKeyPem);
        if ($publicKey === false) {
            throw new \RuntimeException('Configured update-server public key is invalid.');
        }

        $verified = \openssl_verify($manifestRaw, $signature, $publicKey, OPENSSL_ALGO_SHA256);
        if ($verified !== 1) {
            throw new \RuntimeException('Update package signature verification failed.');
        }
    }

    public function applyPayload(string $payloadRoot, string $targetRoot, array $manifest, ?callable $pathGuard = null): array {
        $payloadRoot = rtrim(str_replace('\\', '/', $payloadRoot), '/');
        $targetRoot = rtrim(str_replace('\\', '/', $targetRoot), '/');
        $copied = 0;
        $deleted = 0;

        foreach ((array) ($manifest['files'] ?? []) as $row) {
            if (!is_array($row)) {
                continue;
            }

            $relative = $this->sanitizeRelativePath((string) ($row['path'] ?? ''));
            if ($relative === '') {
                continue;
            }
            if ($pathGuard !== null && $pathGuard($relative) !== true) {
                throw new \RuntimeException(sprintf('Update package attempted to touch a protected path [%s].', $relative));
            }

            $source = $payloadRoot . '/' . $relative;
            if (!is_file($source)) {
                throw new \RuntimeException(sprintf('Update package is missing payload file [%s].', $relative));
            }

            $expectedHash = strtolower(trim((string) ($row['sha256'] ?? '')));
            if ($expectedHash !== '' && !hash_equals($expectedHash, strtolower($this->files->hashFile($source)))) {
                throw new \RuntimeException(sprintf('Update package payload hash did not match for [%s].', $relative));
            }

            $destination = $targetRoot . '/' . $relative;
            $directory = dirname($destination);
            if (!is_dir($directory)) {
                $this->files->ensureDirectory($directory);
            }
            if (!@copy($source, $destination)) {
                throw new \RuntimeException(sprintf('Unable to apply update package file [%s].', $relative));
            }
            $copied++;
        }

        foreach ((array) ($manifest['delete'] ?? []) as $row) {
            $relative = $this->sanitizeRelativePath((string) $row);
            if ($relative === '') {
                continue;
            }
            if ($pathGuard !== null && $pathGuard($relative) !== true) {
                throw new \RuntimeException(sprintf('Update package attempted to delete a protected path [%s].', $relative));
            }

            $target = $targetRoot . '/' . $relative;
            if (is_dir($target) && !is_link($target)) {
                $this->files->remove($target);
                $deleted++;
                continue;
            }
            if (is_file($target) || is_link($target)) {
                $this->files->remove($target);
                $deleted++;
            }
        }

        return [
            'ok' => true,
            'copied' => $copied,
            'deleted' => $deleted,
            'package_type' => trim((string) ($manifest['package_type'] ?? '')),
        ];
    }

    private function sanitizeRelativePath(string $relative): string {
        $relative = ltrim(str_replace('\\', '/', trim($relative)), '/');
        if ($relative === '' || str_contains($relative, '/../') || str_starts_with($relative, '../')) {
            return '';
        }

        return $relative;
    }
}
