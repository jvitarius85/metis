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
$entryResolver = $read( 'src/Metis/Core/Runtime/RuntimeModuleEntryResolver.php' );
$websiteBridge = $read( 'src/Metis/Core/Runtime/WebsiteModuleRuntimeBridge.php' );
$newsletterBridge = $read( 'src/Metis/Core/Runtime/NewsletterModuleRuntimeBridge.php' );
$websiteModule = $read( 'src/Metis/Modules/Website/WebsiteModule.php' );
$newsletterModule = $read( 'src/Metis/Modules/Newsletter/NewsletterModule.php' );

$assert(
    str_contains( $moduleSchemaBridge, "RuntimeModuleEntryResolver::callStatic( 'contacts', 'ensureRuntimeSchema'" )
    && str_contains( $moduleSchemaBridge, "RuntimeModuleEntryResolver::callStatic( 'forms', 'ensureRuntimeSchema'" )
    && str_contains( $moduleSchemaBridge, "RuntimeModuleEntryResolver::callStatic( 'newsletter', 'ensureRuntimeSchema'" )
    && str_contains( $moduleSchemaBridge, "RuntimeModuleEntryResolver::callStatic( 'board', 'ensureRuntimeSchema'" )
    && str_contains( $moduleSchemaBridge, "RuntimeModuleEntryResolver::callStatic( 'finance', 'ensureRuntimeSchema'" )
    && str_contains( $moduleSchemaBridge, "RuntimeModuleEntryResolver::callStatic( 'website', 'ensureRuntimeSchema'" )
    && str_contains( $moduleSchemaBridge, "RuntimeModuleEntryResolver::callStatic( 'import', 'ensureRuntimeSchema'" ),
    'Module schema bridge must prefer dynamically resolved module entry classes for store-managed schema bootstrapping.'
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
    str_contains( $websiteBridge, "RuntimeModuleEntryResolver::callStatic( 'website', 'createPost'" )
    && str_contains( $websiteBridge, "RuntimeModuleEntryResolver::callStatic( 'website', 'publishPost'" )
    && str_contains( $websiteBridge, "RuntimeModuleEntryResolver::callStatic( 'website', 'saveDraft'" )
    && str_contains( $websiteBridge, "RuntimeModuleEntryResolver::callStatic( 'website', 'renderEditorPreview'" )
    && str_contains( $websiteBridge, "RuntimeModuleEntryResolver::callStatic( 'website', 'checkpoint'" )
    && str_contains( $websiteBridge, "RuntimeModuleEntryResolver::callStatic( 'website', 'saveHomepageSelection'" )
    && str_contains( $websiteBridge, "RuntimeModuleEntryResolver::callStatic( 'website', 'publishedHomepagePages'" ),
    'Website runtime bridge must delegate through dynamically resolved website module entry methods.'
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
    str_contains( $newsletterBridge, "RuntimeModuleEntryResolver::callStatic( 'newsletter', 'createCampaign'" )
    && str_contains( $newsletterBridge, "RuntimeModuleEntryResolver::callStatic( 'newsletter', 'updateCampaign'" )
    && str_contains( $newsletterBridge, "RuntimeModuleEntryResolver::callStatic( 'newsletter', 'sendCampaign'" )
    && str_contains( $newsletterBridge, "RuntimeModuleEntryResolver::callStatic( 'newsletter', 'scheduleCampaign'" )
    && str_contains( $newsletterBridge, "RuntimeModuleEntryResolver::callStatic( 'newsletter', 'cancelCampaign'" )
    && str_contains( $newsletterBridge, "RuntimeModuleEntryResolver::callStatic( 'newsletter', 'archiveCampaign'" )
    && str_contains( $newsletterBridge, "RuntimeModuleEntryResolver::callStatic( 'newsletter', 'deleteCampaign'" ),
    'Newsletter runtime bridge must delegate through dynamically resolved newsletter module entry methods.'
);

$assert(
    ! str_contains( $newsletterBridge, 'CampaignService' )
    && ! str_contains( $newsletterBridge, 'QueueService' )
    && ! str_contains( $newsletterBridge, 'NewsletterModule::ensureSchema' ),
    'Newsletter runtime bridge must not reach directly into newsletter source internals.'
);

$assert(
    str_contains( $entryResolver, "Application::service( 'modules' )->get( \$slug )" )
    && str_contains( $entryResolver, "['config']['_module_class']" )
    && str_contains( $entryResolver, 'fallbackClass' ),
    'Runtime module entry resolver must prefer the loaded module registry class and only fall back to a conventional class name when necessary.'
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
    str_contains( $newsletterModule, 'CampaignService::save(' )
    && str_contains( $newsletterModule, 'QueueService::queueCampaignMessages(' )
    && str_contains( $newsletterModule, 'CampaignService::archive(' )
    && str_contains( $newsletterModule, 'CampaignService::delete(' ),
    'NewsletterModule entry operations must own the campaign workflow so runtime callers can resolve the module entry directly.'
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
