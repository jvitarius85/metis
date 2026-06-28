<?php
declare(strict_types=1);

if ( PHP_SAPI !== 'cli' ) {
    fwrite( STDERR, "This test must be run from the command line.\n" );
    exit( 1 );
}

$root = dirname( __DIR__ );
$failures = [];
require_once __DIR__ . '/_support/module_path_resolver.php';

$resolve_relative = static fn ( string $relative ): string => metis_test_resolve_relative( $root, $relative );

$assert = static function ( bool $condition, string $message ) use ( &$failures ): void {
    if ( ! $condition ) {
        $failures[] = $message;
    }
};

$read = static function ( string $relative ) use ( $resolve_relative ): string {
    $contents = file_get_contents( $resolve_relative( $relative ) );
    return $contents === false ? '' : $contents;
};

$moduleSchemaBridge = $read( 'src/Metis/Core/Runtime/ModuleSchemaRuntimeBridge.php' );
$entryResolver = $read( 'src/Metis/Core/Runtime/RuntimeModuleEntryResolver.php' );
$websiteBridge = $read( 'src/Metis/Core/Runtime/WebsiteModuleRuntimeBridge.php' );
$newsletterBridge = $read( 'src/Metis/Core/Runtime/NewsletterModuleRuntimeBridge.php' );
$websiteModule = $read( 'modules/website/Module.php' );
$newsletterModule = $read( 'modules/newsletter/Module.php' );

$assert(
    str_contains( $moduleSchemaBridge, "'people' => static function (): void { \\Metis\\Modules\\People\\SchemaManager::ensureSchema();" )
    && str_contains( $moduleSchemaBridge, "'hermes' => static function (): void { \\Metis\\Modules\\Hermes\\SchemaManager::ensureSchema();" )
    && str_contains( $moduleSchemaBridge, "'communications_inbound' => static function (): void { \\Metis\\Modules\\CommunicationsInbound\\SchemaManager::ensureSchema();" )
    && str_contains( $moduleSchemaBridge, "'recovery' => static function (): void { \\Metis\\Core\\Recovery\\RecoverySchema::ensureSchema();" )
    && str_contains( $moduleSchemaBridge, "'help_search_store' => static function (): void {" ),
    'Installer schema bridge must be limited to core-owned and built-in runtime schema installers.'
);

$assert(
    ! str_contains( $moduleSchemaBridge, "'contacts' =>" )
    && ! str_contains( $moduleSchemaBridge, "'forms' =>" )
    && ! str_contains( $moduleSchemaBridge, "'newsletter' =>" )
    && ! str_contains( $moduleSchemaBridge, "'board' =>" )
    && ! str_contains( $moduleSchemaBridge, "'calendar' =>" )
    && ! str_contains( $moduleSchemaBridge, "'finance' =>" )
    && ! str_contains( $moduleSchemaBridge, "'website' =>" )
    && ! str_contains( $moduleSchemaBridge, "'import' =>" )
    && ! str_contains( $moduleSchemaBridge, "'cms' =>" ),
    'Installer schema bridge must not directly enumerate store-managed or stale schema steps.'
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
