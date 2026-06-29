<?php
declare(strict_types=1);

if ( PHP_SAPI !== 'cli' ) {
    fwrite( STDERR, "This test must be run from the command line.\n" );
    exit( 1 );
}

$root = dirname( __DIR__ );
$githubUpdateService = (string) file_get_contents( $root . '/src/Metis/Core/Services/GitHubUpdateService.php' );
$updateService = (string) file_get_contents( $root . '/src/Metis/Core/Services/UpdateService.php' );
$cronRuntime = (string) file_get_contents( $root . '/src/Metis/Core/Cron/CronRuntime.php' );
$settingsAjax = (string) file_get_contents( $root . '/src/Metis/Core/BuiltInServices/settings/assets/settings.ajax.php' );

$failures = [];
$assert = static function ( bool $condition, string $message ) use ( &$failures ): void {
    if ( ! $condition ) {
        $failures[] = $message;
    }
};

$assert(
    str_contains( $githubUpdateService, 'public function pollConfiguredRepositories(bool $forceRefresh = false): array' ),
    'GitHubUpdateService must expose a shared repository polling entrypoint.'
);
$assert(
    str_contains( $githubUpdateService, 'coreRepositoryPollSnapshot' ) && str_contains( $githubUpdateService, 'metadataRepositoryPollSnapshot' ),
    'GitHubUpdateService repository polling must cover both core and metadata repositories.'
);
$assert(
    str_contains( $updateService, '$repositories = $this->githubUpdates->pollConfiguredRepositories($forceRefresh);' )
        && str_contains( $updateService, "'repositories' => \$repositories" ),
    'UpdateService refresh must force-refresh and return shared repository polling state.'
);
$assert(
    str_contains( $cronRuntime, "'repositories' => (array) ( \$result['repositories'] ?? [] )" ),
    'Scheduled update checks must retain repository polling status in cron results.'
);
$assert(
    str_contains( $settingsAjax, "'repository_poll_status' => (array) ( \$summary['repositories'] ?? [] )" ),
    'Settings update refresh must expose repository polling status to the UI response.'
);

if ( $failures !== [] ) {
    fwrite( STDERR, implode( PHP_EOL, $failures ) . PHP_EOL );
    exit( 1 );
}

fwrite( STDOUT, "Update repository polling contract checks passed.\n" );
