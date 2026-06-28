<?php
declare(strict_types=1);

namespace Metis\Core\Services;

use Metis\Core\Application;
use Metis\Core\Cache\CacheService;
use Metis\Core\Modules\ModuleValidator;
use Metis\Core\ModulePathRegistry;
use Metis\Core\Recovery\RecoveryVerifier;
use Metis\Core\Version;

final class ModuleInstallService {
    private const SEMVER_PATTERN = '/^(0|[1-9]\d*)\.(0|[1-9]\d*)\.(0|[1-9]\d*)$/';
    private const RUNTIME_WARNING_STATUSES = [
        'source_backed_entry',
        'missing_entry',
        'unreadable_entry',
        'unknown_entry_contract',
    ];

    public function __construct(
        private readonly GitHubUpdateService $githubUpdates,
        private readonly ModuleUpdateService $moduleUpdates,
        private readonly FileService $files = new FileService(),
        private readonly LoggerService $logger = new LoggerService()
    ) {}

    public function installLatest(string $moduleId, bool $forceRefresh = true, ?callable $progressReporter = null): array {
        $moduleId = metis_key_clean($moduleId);
        if ($moduleId === '') {
            return $this->failure('invalid_module', 'A valid module ID is required.');
        }

        $this->emitProgress($progressReporter, 'registry', 'Loading module registry metadata.', 4, [
            'module' => $moduleId,
        ]);
        $registry = $this->githubUpdates->moduleRegistry($forceRefresh);
        if (($registry['status'] ?? '') !== 'ready') {
            return $this->failure(
                'registry_unavailable',
                (string) ($registry['error'] ?? 'Module registry is unavailable.')
            );
        }

        $entry = is_array($registry['modules'][$moduleId] ?? null) ? (array) $registry['modules'][$moduleId] : [];
        if ($entry === []) {
            return $this->failure('missing_module', sprintf('Module [%s] is not present in the registry.', $moduleId));
        }

        $latestVersion = trim((string) ($entry['latest'] ?? ''));
        $minimumMetis = trim((string) ($entry['minimum_metis'] ?? ''));
        $downloadUrl = trim((string) ($entry['download_url'] ?? ''));
        $sha256 = strtolower(trim((string) ($entry['sha256'] ?? '')));
        if (preg_match(self::SEMVER_PATTERN, $latestVersion) !== 1) {
            return $this->failure('invalid_version', sprintf('Registry version [%s] is not valid semantic versioning.', $latestVersion));
        }
        if ($downloadUrl === '') {
            return $this->failure('missing_download', sprintf('Module [%s] does not have a download archive configured.', $moduleId));
        }
        if ($minimumMetis !== '' && version_compare(Version::current(), $minimumMetis, '<')) {
            return $this->failure('requires_newer_metis', sprintf('Module requires Metis %s or newer.', $minimumMetis));
        }

        $installedMap = [];
        foreach ($this->moduleUpdates->discoverInstalledModules() as $installedModule) {
            $installedId = metis_key_clean((string) ($installedModule['id'] ?? ''));
            if ($installedId !== '') {
                $installedMap[$installedId] = $installedModule;
            }
        }
        $current = is_array($installedMap[$moduleId] ?? null) ? (array) $installedMap[$moduleId] : [];
        $currentVersion = trim((string) ($current['version'] ?? ''));
        $moduleName = trim((string) ($current['name'] ?? ucwords(str_replace(['_', '-'], ' ', $moduleId))));
        $isUpdate = $current !== [];

        $runtimeRoot = $this->files->ensureDirectory($this->files->rootPath('storage/runtime/module_updates'));
        $workspace = $this->files->ensureDirectory($runtimeRoot . '/' . $moduleId . '-' . gmdate('YmdHis'));
        $archivePath = $workspace . '/' . $moduleId . '.' . $latestVersion . '.tar.gz';
        $extractPath = $workspace . '/extract';

        try {
            $this->emitProgress($progressReporter, 'download', sprintf('Downloading %s %s.', $moduleName, $latestVersion), 12, [
                'module' => $moduleId,
                'module_name' => $moduleName,
                'latest' => $latestVersion,
            ]);
            $this->githubUpdates->downloadModuleArchive($downloadUrl, $archivePath);
            if ($sha256 !== '') {
                $this->emitProgress($progressReporter, 'checksum', sprintf('Verifying package integrity for %s.', $moduleName), 22, [
                    'module' => $moduleId,
                    'module_name' => $moduleName,
                ]);
                $archiveHash = strtolower($this->files->hashFile($archivePath));
                if (!hash_equals($sha256, $archiveHash)) {
                    throw new \RuntimeException('Downloaded archive checksum did not match the registry sha256.');
                }
            }

            $this->emitProgress($progressReporter, 'extract', sprintf('Extracting %s package.', $moduleName), 32, [
                'module' => $moduleId,
                'module_name' => $moduleName,
            ]);
            $this->extractArchive($archivePath, $extractPath);
            $moduleSource = $this->locateModuleSource($extractPath, $moduleId);
            if ($moduleSource === '') {
                throw new \RuntimeException(sprintf('Unable to locate module.json for [%s] inside the archive.', $moduleId));
            }

            $this->emitProgress($progressReporter, 'validate_manifest', sprintf('Validating %s manifest.', $moduleName), 44, [
                'module' => $moduleId,
                'module_name' => $moduleName,
            ]);
            $manifest = $this->files->readJson($moduleSource . '/module.json', []);
            $manifestId = metis_key_clean((string) ($manifest['id'] ?? $manifest['slug'] ?? basename($moduleSource)));
            $manifestVersion = trim((string) ($manifest['version'] ?? ''));
            if ($manifestId !== $moduleId) {
                throw new \RuntimeException(sprintf('Archive manifest ID [%s] does not match requested module [%s].', $manifestId, $moduleId));
            }
            if (preg_match(self::SEMVER_PATTERN, $manifestVersion) !== 1 || version_compare($manifestVersion, $latestVersion, '!=')) {
                throw new \RuntimeException(sprintf('Archive version [%s] does not match registry version [%s].', $manifestVersion, $latestVersion));
            }
            $manifest = (new ModuleValidator())->validateModule($moduleSource, $manifest, $moduleId);

            $destination = rtrim(ModulePathRegistry::moduleRootPath(), '/\\') . '/' . $moduleId;
            $stagedDestination = $workspace . '/runtime-module';
            $this->emitProgress($progressReporter, 'stage_runtime', sprintf('Staging runtime files for %s.', $moduleName), 56, [
                'module' => $moduleId,
                'module_name' => $moduleName,
            ]);
            $this->copyDirectory($moduleSource, $stagedDestination);
            $this->verifyInstalledRuntimeContract($stagedDestination, $manifest, $moduleId);

            $previousDestination = '';
            if (is_dir($destination)) {
                $backupRoot = $this->files->ensureDirectory($runtimeRoot . '/backups');
                $this->copyDirectory($destination, $backupRoot . '/' . $moduleId . '-' . gmdate('YmdHis'));
                $previousDestination = $workspace . '/previous-runtime';
                if (is_dir($previousDestination)) {
                    $this->files->remove($previousDestination);
                }
                if (!@rename($destination, $previousDestination)) {
                    throw new \RuntimeException(sprintf('Unable to stage the existing runtime module [%s] for replacement.', $moduleId));
                }
            }

            $this->emitProgress($progressReporter, 'apply_runtime', sprintf('Applying runtime files for %s.', $moduleName), 68, [
                'module' => $moduleId,
                'module_name' => $moduleName,
            ]);
            try {
                if (!@rename($stagedDestination, $destination)) {
                    throw new \RuntimeException(sprintf('Unable to promote the staged runtime module [%s] into place.', $moduleId));
                }
            } catch (\Throwable $exception) {
                if ($previousDestination !== '' && !is_dir($destination) && is_dir($previousDestination)) {
                    @rename($previousDestination, $destination);
                }
                throw $exception;
            }

            $this->emitProgress($progressReporter, 'refresh_runtime', sprintf('Refreshing runtime state for %s.', $moduleName), 78, [
                'module' => $moduleId,
                'module_name' => $moduleName,
            ]);
            $this->refreshRuntimeState();
            $this->emitProgress($progressReporter, 'refresh_protection', sprintf('Refreshing integrity and recovery state for %s.', $moduleName), 86, [
                'module' => $moduleId,
                'module_name' => $moduleName,
            ]);
            $protectionRefresh = $this->refreshProtectionState('module_install:' . $moduleId . ':' . $latestVersion);
            $this->emitProgress($progressReporter, 'refresh_updates', sprintf('Refreshing module update status for %s.', $moduleName), 92, [
                'module' => $moduleId,
                'module_name' => $moduleName,
            ]);
            $status = $this->moduleUpdates->checkForUpdates(true);
            $this->emitProgress($progressReporter, 'verify_install', sprintf('Verifying installed state for %s.', $moduleName), 97, [
                'module' => $moduleId,
                'module_name' => $moduleName,
            ]);
            $verification = $this->verifyInstalledModuleState(
                $moduleId,
                $latestVersion,
                $moduleName,
                $status
            );

            $result = [
                'ok' => true,
                'status' => $isUpdate ? 'updated' : 'installed',
                'message' => sprintf('%s %s installed.', $moduleName, $latestVersion),
                'module' => $moduleId,
                'name' => $moduleName,
                'current' => $currentVersion,
                'latest' => $latestVersion,
                'minimum_metis' => $minimumMetis,
                'download_url' => $downloadUrl,
                'module_status' => $verification['module_status'],
                'protection_refresh' => $protectionRefresh,
                'verification' => $verification,
                'postflight_steps' => [
                    'refresh_runtime',
                    'refresh_protection',
                    'refresh_updates',
                    'verify_install',
                ],
            ];

            $this->emitProgress($progressReporter, 'complete', sprintf('%s %s is ready.', $moduleName, $latestVersion), 100, [
                'module' => $moduleId,
                'module_name' => $moduleName,
                'latest' => $latestVersion,
            ]);

            $this->logger->activity('module_install_completed', [
                'module' => $moduleId,
                'status' => $result['status'],
                'current' => $currentVersion,
                'latest' => $latestVersion,
            ]);

            return $result;
        } catch (\Throwable $exception) {
            $this->emitProgress($progressReporter, 'failed', $exception->getMessage(), 100, [
                'module' => $moduleId,
                'module_name' => $moduleName,
                'latest' => $latestVersion,
            ]);
            $this->logger->error('module_install_failed', [
                'module' => $moduleId,
                'version' => $latestVersion,
                'message' => $exception->getMessage(),
            ]);

            return $this->failure('install_failed', $exception->getMessage(), [
                'module' => $moduleId,
                'name' => $moduleName,
                'current' => $currentVersion,
                'latest' => $latestVersion,
            ]);
        }
    }

    public function uninstall(string $moduleId): array {
        $moduleId = metis_key_clean($moduleId);
        if ($moduleId === '') {
            return $this->failure('invalid_module', 'A valid module ID is required.');
        }

        if (ModulePathRegistry::isCoreServiceSlug($moduleId)) {
            return $this->failure('protected_module', 'Built-in services cannot be uninstalled.');
        }

        $installedMap = [];
        foreach ($this->moduleUpdates->discoverInstalledModules() as $installedModule) {
            $installedId = metis_key_clean((string) ($installedModule['id'] ?? ''));
            if ($installedId !== '') {
                $installedMap[$installedId] = $installedModule;
            }
        }

        $current = is_array($installedMap[$moduleId] ?? null) ? (array) $installedMap[$moduleId] : [];
        if ($current === []) {
            return $this->failure('missing_module', sprintf('Module [%s] is not installed.', $moduleId));
        }

        $manifestPath = (string) ($current['manifest_path'] ?? '');
        $modulePath = $manifestPath !== '' ? dirname($manifestPath) : '';
        if ($modulePath === '' || !is_dir($modulePath)) {
            return $this->failure('missing_path', sprintf('Module [%s] could not be located on disk.', $moduleId));
        }

        if (ModulePathRegistry::packageTypeForPath($modulePath) !== 'module') {
            return $this->failure('protected_module', 'Only registry modules can be uninstalled.');
        }

        $moduleName = trim((string) ($current['name'] ?? ucwords(str_replace(['_', '-'], ' ', $moduleId))));
        $currentVersion = trim((string) ($current['version'] ?? ''));
        $runtimeRoot = $this->files->ensureDirectory($this->files->rootPath('storage/runtime/module_updates'));

        try {
            $backupRoot = $this->files->ensureDirectory($runtimeRoot . '/backups');
            $this->copyDirectory($modulePath, $backupRoot . '/' . $moduleId . '-uninstall-' . gmdate('YmdHis'));
            $this->files->remove($modulePath);
            $this->refreshRuntimeState();
            $protectionRefresh = $this->refreshProtectionState('module_uninstall:' . $moduleId . ':' . ($currentVersion !== '' ? $currentVersion : 'removed'));
            $status = $this->moduleUpdates->checkForUpdates(true);

            $result = [
                'ok' => true,
                'status' => 'uninstalled',
                'message' => sprintf('%s %s uninstalled.', $moduleName, $currentVersion !== '' ? $currentVersion : $moduleId),
                'module' => $moduleId,
                'name' => $moduleName,
                'current' => $currentVersion,
                'module_update_status' => $status,
                'protection_refresh' => $protectionRefresh,
            ];

            $this->logger->activity('module_uninstall_completed', [
                'module' => $moduleId,
                'current' => $currentVersion,
            ]);

            return $result;
        } catch (\Throwable $exception) {
            $this->logger->error('module_uninstall_failed', [
                'module' => $moduleId,
                'version' => $currentVersion,
                'message' => $exception->getMessage(),
            ]);

            return $this->failure('uninstall_failed', $exception->getMessage(), [
                'module' => $moduleId,
                'name' => $moduleName,
                'current' => $currentVersion,
            ]);
        }
    }

    private function extractArchive(string $archivePath, string $destination): void {
        $this->files->ensureDirectory($destination);
        $tarPath = preg_replace('/\.gz$/', '', $archivePath) ?: $archivePath . '.tar';
        if (is_file($tarPath)) {
            @unlink($tarPath);
        }

        try {
            $archive = new \PharData($archivePath);
            $archive->decompress();
            $tar = new \PharData($tarPath);
            $tar->extractTo($destination, null, true);
        } finally {
            if (is_file($tarPath)) {
                @unlink($tarPath);
            }
        }
    }

    private function locateModuleSource(string $extractPath, string $moduleId): string {
        $direct = $extractPath . '/' . $moduleId;
        if (is_file($direct . '/module.json')) {
            return $direct;
        }

        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($extractPath, \FilesystemIterator::SKIP_DOTS)
        );

        foreach ($iterator as $file) {
            if (!$file->isFile() || $file->getFilename() !== 'module.json') {
                continue;
            }

            $candidate = $file->getPath();
            $payload = $this->files->readJson($candidate . '/module.json', []);
            $candidateId = metis_key_clean((string) ($payload['id'] ?? $payload['slug'] ?? basename($candidate)));
            if ($candidateId === $moduleId) {
                return $candidate;
            }
        }

        return '';
    }

    private function copyDirectory(string $source, string $destination): void {
        $this->files->ensureDirectory($destination);

        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($source, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::SELF_FIRST
        );

        foreach ($iterator as $item) {
            $target = $destination . '/' . $iterator->getSubPathName();
            if ($item->isDir()) {
                $this->files->ensureDirectory($target);
                continue;
            }

            $this->files->copy($item->getPathname(), $target);
        }
    }

    private function verifyInstalledRuntimeContract(string $modulePath, array $manifest, string $moduleId): void {
        $modulePath = rtrim($modulePath, '/\\');
        if (!is_file($modulePath . '/module.json')) {
            throw new \RuntimeException(sprintf('Installed module runtime [%s] is missing module.json after staging.', $moduleId));
        }

        $entry = ltrim((string) ($manifest['entry'] ?? 'Module.php'), '/\\');
        if ($entry !== '' && !is_file($modulePath . '/' . $entry)) {
            throw new \RuntimeException(sprintf('Installed module runtime [%s] is missing entry file [%s] after staging.', $moduleId, $entry));
        }

        $bootstrap = ltrim((string) ($manifest['bootstrap'] ?? 'bootstrap.php'), '/\\');
        if ($bootstrap !== '' && !is_file($modulePath . '/' . $bootstrap)) {
            throw new \RuntimeException(sprintf('Installed module runtime [%s] is missing bootstrap file [%s] after staging.', $moduleId, $bootstrap));
        }
    }

    private function refreshRuntimeState(): void {
        CacheService::forget('updates.modules');
        CacheService::clearGroup('modules');
        CacheService::clearGroup('fragments');

        if (Application::has_service('modules')) {
            $modules = Application::service('modules');
            if (is_object($modules) && method_exists($modules, 'reload')) {
                $modules->reload();
            }
        }

        if (function_exists('metis_standalone_invalidate_config_cache')) {
            metis_standalone_invalidate_config_cache();
        }

        CacheService::rebuildSystemCaches();
    }

    private function refreshProtectionState(string $reason): array {
        ModulePathRegistry::retireLegacySourceModuleTree();
        $result = [
            'baseline_built' => true,
            'baseline_signed' => true,
            'signature_required' => false,
            'recovery_manifest' => [
                'status' => 'skipped',
            ],
            'module_compliance' => [
                'status' => 'unavailable',
                'summary' => [
                    'checked' => 0,
                    'failed' => 0,
                    'passed' => 0,
                ],
                'failures' => [],
            ],
        ];

        if (class_exists('Metis_Integrity_Manager')) {
            \Metis_Integrity_Manager::ensure_runtime();
            $result['baseline_built'] = \Metis_Integrity_Manager::build_baseline($reason);
            $verification = (array) \Metis_Integrity_Manager::verify_baseline();
            $result['signature_required'] = !empty($verification['signature_required']);
            $result['baseline_signed'] = !$result['signature_required'] ? true : \Metis_Integrity_Manager::sign_baseline();
        }

        if (class_exists(RecoveryVerifier::class)) {
            $result['recovery_manifest'] = (new RecoveryVerifier())->rebuildManifest($reason);
        }

        if (function_exists('metis_module_compliance_report')) {
            $result['module_compliance'] = (array) metis_module_compliance_report(true);
        }

        $complianceSummary = is_array($result['module_compliance']['summary'] ?? null) ? (array) $result['module_compliance']['summary'] : [];
        $complianceFailed = (int) ($complianceSummary['failed'] ?? 0);

        if (
            empty($result['baseline_built'])
            || empty($result['baseline_signed'])
            || (string) ($result['recovery_manifest']['status'] ?? '') !== 'success'
            || $complianceFailed > 0
        ) {
            throw new \RuntimeException('Module update completed, but integrity or module compliance state could not be fully refreshed.');
        }

        return $result;
    }

    private function verifyInstalledModuleState(string $moduleId, string $latestVersion, string $moduleName, array $status): array {
        $installedModules = [];
        foreach ($this->moduleUpdates->discoverInstalledModules() as $installedModule) {
            $installedId = metis_key_clean((string) ($installedModule['id'] ?? ''));
            if ($installedId !== '') {
                $installedModules[$installedId] = $installedModule;
            }
        }

        $installed = is_array($installedModules[$moduleId] ?? null) ? (array) $installedModules[$moduleId] : [];
        if ($installed === []) {
            throw new \RuntimeException(sprintf('%s was copied into place, but the runtime module could not be rediscovered.', $moduleName));
        }

        $installedVersion = trim((string) ($installed['version'] ?? ''));
        if ($installedVersion === '' || version_compare($installedVersion, $latestVersion, '!=')) {
            throw new \RuntimeException(sprintf('%s still reports version [%s] after install; expected [%s].', $moduleName, $installedVersion !== '' ? $installedVersion : 'unknown', $latestVersion));
        }

        $runtimeStatus = trim((string) ($installed['runtime_contract_status'] ?? ''));
        if (in_array($runtimeStatus, self::RUNTIME_WARNING_STATUSES, true)) {
            throw new \RuntimeException(sprintf(
                '%s was installed, but the runtime contract is not healthy: %s',
                $moduleName,
                trim((string) ($installed['runtime_contract_note'] ?? $runtimeStatus))
            ));
        }

        $moduleStatus = [];
        foreach ((array) ($status['modules'] ?? []) as $row) {
            if (is_array($row) && metis_key_clean((string) ($row['id'] ?? '')) === $moduleId) {
                $moduleStatus = $row;
                break;
            }
        }

        if ($moduleStatus === []) {
            throw new \RuntimeException(sprintf('%s was installed, but update status could not be refreshed.', $moduleName));
        }

        if (!empty($moduleStatus['update_available'])) {
            throw new \RuntimeException(sprintf('%s still shows an available update after install verification.', $moduleName));
        }

        $moduleUpdateStatus = trim((string) ($moduleStatus['status'] ?? ''));
        if ($moduleUpdateStatus !== '' && $moduleUpdateStatus !== 'current') {
            throw new \RuntimeException(sprintf(
                '%s completed installation, but the refreshed module status is [%s].',
                $moduleName,
                $moduleUpdateStatus
            ));
        }

        return [
            'installed' => $installed,
            'module_status' => $moduleStatus,
        ];
    }

    private function emitProgress(?callable $progressReporter, string $stage, string $message, int $percent, array $context = []): void {
        if (!is_callable($progressReporter)) {
            return;
        }

        try {
            $progressReporter([
                'stage' => $stage,
                'message' => $message,
                'percent' => max(0, min(100, $percent)),
                'context' => $context,
            ]);
        } catch (\Throwable) {
        }
    }

    private function failure(string $status, string $message, array $payload = []): array {
        return array_merge([
            'ok' => false,
            'status' => $status,
            'message' => $message,
        ], $payload);
    }
}
