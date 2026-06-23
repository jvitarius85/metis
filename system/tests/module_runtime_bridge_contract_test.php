<?php
declare(strict_types=1);

if ( PHP_SAPI !== 'cli' ) {
    fwrite( STDERR, "This test must be run from the command line.\n" );
    exit( 1 );
}

$root = dirname( __DIR__ );
$failures = [];

$assert = static function ( bool $condition, string $message ) use ( &$failures ): void {
    if ( ! $condition ) {
        $failures[] = $message;
    }
};

$read = static function ( string $relative ) use ( $root ): string {
    $contents = file_get_contents( $root . '/' . ltrim( $relative, '/\\' ) );
    return $contents === false ? '' : $contents;
};

$moduleSchemaBridge = $read( 'src/Metis/Core/Runtime/ModuleSchemaRuntimeBridge.php' );
$websiteBridge = $read( 'src/Metis/Core/Runtime/WebsiteModuleRuntimeBridge.php' );
$newsletterBridge = $read( 'src/Metis/Core/Runtime/NewsletterModuleRuntimeBridge.php' );
$websiteModule = $read( 'src/Metis/Modules/Website/WebsiteModule.php' );
$newsletterModule = $read( 'src/Metis/Modules/Newsletter/NewsletterModule.php' );

$assert(
    str_contains( $moduleSchemaBridge, 'ContactsModule::ensureRuntimeSchema' )
    && str_contains( $moduleSchemaBridge, 'FormsModule::ensureRuntimeSchema' )
    && str_contains( $moduleSchemaBridge, 'NewsletterModule::ensureRuntimeSchema' )
    && str_contains( $moduleSchemaBridge, 'BoardModule::ensureRuntimeSchema' )
    && str_contains( $moduleSchemaBridge, 'FinanceModule::ensureRuntimeSchema' )
    && str_contains( $moduleSchemaBridge, 'WebsiteModule::ensureRuntimeSchema' )
    && str_contains( $moduleSchemaBridge, 'ImportModule::ensureRuntimeSchema' ),
    'Module schema bridge must prefer module entry classes for store-managed schema bootstrapping.'
);

$assert(
    ! str_contains( $moduleSchemaBridge, '\\Metis\\Modules\\Contacts\\SchemaManager::ensureSchema();' )
    && ! str_contains( $moduleSchemaBridge, '\\Metis\\Modules\\Forms\\SchemaManager::ensureSchema();' )
    && ! str_contains( $moduleSchemaBridge, '\\Metis\\Modules\\Newsletter\\SchemaManager::ensureSchema();' )
    && ! str_contains( $moduleSchemaBridge, '\\Metis\\Modules\\Board\\SchemaManager::ensureSchema();' )
    && ! str_contains( $moduleSchemaBridge, '\\Metis\\Modules\\Calendar\\SyncStore::ensureSchema();' )
    && ! str_contains( $moduleSchemaBridge, '\\Metis\\Modules\\Finance\\SchemaManager::ensureSchema();' )
    && ! str_contains( $moduleSchemaBridge, '\\Metis\\Modules\\Website\\SchemaManager::ensureSchema();' )
    && ! str_contains( $moduleSchemaBridge, '\\Metis\\Modules\\Import\\SchemaManager::ensureSchema();' ),
    'Module schema bridge must not reach directly into store-managed schema internals once module entry facades exist.'
);

$assert(
    str_contains( $websiteBridge, 'WebsiteModule::createPost' )
    && str_contains( $websiteBridge, 'WebsiteModule::publishPost' )
    && str_contains( $websiteBridge, 'WebsiteModule::saveDraft' )
    && str_contains( $websiteBridge, 'WebsiteModule::renderEditorPreview' )
    && str_contains( $websiteBridge, 'WebsiteModule::checkpoint' )
    && str_contains( $websiteBridge, 'WebsiteModule::saveHomepageSelection' )
    && str_contains( $websiteBridge, 'WebsiteModule::publishedHomepagePages' ),
    'Website runtime bridge must delegate through WebsiteModule entry methods.'
);

$assert(
    ! str_contains( $websiteBridge, 'Services\\PostService' )
    && ! str_contains( $websiteBridge, 'Services\\PageService' )
    && ! str_contains( $websiteBridge, 'Services\\HomepageService' )
    && ! str_contains( $websiteBridge, 'Services\\RevisionTimelineService' )
    && ! str_contains( $websiteBridge, 'Services\\WebsiteRenderer' ),
    'Website runtime bridge must not reach directly into website service internals.'
);

$assert(
    str_contains( $newsletterModule, 'public static function createCampaign' )
    && str_contains( $newsletterModule, 'public static function updateCampaign' )
    && str_contains( $newsletterModule, 'public static function sendCampaign' )
    && str_contains( $newsletterModule, 'public static function scheduleCampaign' )
    && str_contains( $newsletterModule, 'public static function cancelCampaign' )
    && str_contains( $newsletterModule, 'public static function archiveCampaign' )
    && str_contains( $newsletterModule, 'public static function deleteCampaign' ),
    'NewsletterModule must expose campaign lifecycle entry methods for runtime callers.'
);

$assert(
    str_contains( $newsletterModule, 'NewsletterModuleRuntimeBridge::createCampaign' )
    && str_contains( $newsletterModule, 'NewsletterModuleRuntimeBridge::updateCampaign' )
    && str_contains( $newsletterModule, 'NewsletterModuleRuntimeBridge::sendCampaign' )
    && str_contains( $newsletterModule, 'NewsletterModuleRuntimeBridge::scheduleCampaign' )
    && str_contains( $newsletterModule, 'NewsletterModuleRuntimeBridge::cancelCampaign' )
    && str_contains( $newsletterModule, 'NewsletterModuleRuntimeBridge::archiveCampaign' )
    && str_contains( $newsletterModule, 'NewsletterModuleRuntimeBridge::deleteCampaign' ),
    'NewsletterModule entry operations must currently route through the centralized newsletter runtime bridge.'
);

$assert(
    str_contains( $websiteModule, 'public static function createPost' )
    && str_contains( $websiteModule, 'public static function publishPost' )
    && str_contains( $websiteModule, 'public static function saveDraft' )
    && str_contains( $websiteModule, 'public static function renderEditorPreview' )
    && str_contains( $websiteModule, 'public static function checkpoint' )
    && str_contains( $websiteModule, 'public static function saveHomepageSelection' )
    && str_contains( $websiteModule, 'public static function publishedHomepagePages' ),
    'WebsiteModule must expose entry methods for post, editor, and homepage runtime callers.'
);

if ( $failures !== [] ) {
    fwrite( STDERR, implode( PHP_EOL, $failures ) . PHP_EOL );
    exit( 1 );
}

fwrite( STDOUT, "Module runtime bridge contract checks passed.\n" );
