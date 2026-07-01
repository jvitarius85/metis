<?php
declare(strict_types=1);

if ( PHP_SAPI !== 'cli' ) {
    fwrite( STDERR, "This test must be run from the command line.\n" );
    exit( 1 );
}

$root = dirname( __DIR__ );
$releaseManager = (string) file_get_contents( $root . '/src/Metis/Release/ReleaseManager.php' );
$moduleInstall = (string) file_get_contents( $root . '/src/Metis/Core/Services/ModuleInstallService.php' );
$updateProvider = (string) file_get_contents( $root . '/src/Metis/Core/Services/GitHubUpdateService.php' );
$registry = (string) file_get_contents( $root . '/src/Metis/Core/ServiceRegistryRuntime.php' );

$failures = [];
$assert = static function ( bool $condition, string $message ) use ( &$failures ): void {
    if ( ! $condition ) {
        $failures[] = $message;
    }
};

$assert(
    str_contains( $releaseManager, '$package = $this->updatePackageService()->inspectExtractedPackage( $source_root );' )
        && str_contains( $releaseManager, 'private function applyManagedReleasePackage( array $package, array $release, array $previous ): array' )
        && str_contains( $releaseManager, "trim( (string) ( \$this->updateServerClient()->settings()['server_public_key'] ?? '' ) )" )
        && str_contains( $releaseManager, "'core_delta', 'core_full'" ),
    'Release manager archive mode must detect signed managed packages and support core full and delta package application.'
);

$assert(
    str_contains( $moduleInstall, 'private function stageManagedPackage(' )
        && str_contains( $moduleInstall, "if (\$packageType === 'module_delta')" )
        && str_contains( $moduleInstall, "} elseif (\$packageType === 'module_full') {" )
        && str_contains( $moduleInstall, '$this->updatePackageService()->verifyExtractedPackage($package, $publicKey);' )
        && str_contains( $moduleInstall, '$this->updatePackageService()->applyPayload($payloadRoot, $stagedDestination, $manifest);' ),
    'Module installer must support signed full and delta module packages from the update server.'
);

$assert(
    str_contains( $updateProvider, 'private readonly ?UpdateServerClient $updateServer = null' )
        && str_contains( $updateProvider, 'private function isUpdateServerProvider(): bool' )
        && str_contains( $updateProvider, 'return $this->updateServer()->checkForUpdates($forceRefresh, $this->installedModuleInventory());' )
        && str_contains( $updateProvider, 'return $this->updateServer()->pollConfiguredRepositories($forceRefresh, $this->installedModuleInventory());' ),
    'Update provider service must delegate core/module metadata and polling to the update server when configured.'
);

$assert(
    str_contains( $registry, "singleton(\n            'update_server_identity'" )
        && str_contains( $registry, "singleton(\n            'update_package_service'" )
        && str_contains( $registry, "singleton(\n            'update_server_client'" ),
    'Service registry must register update-server identity, package, and client services.'
);

if ( $failures !== [] ) {
    fwrite( STDERR, implode( PHP_EOL, $failures ) . PHP_EOL );
    exit( 1 );
}

fwrite( STDOUT, "Update server package contract checks passed.\n" );
