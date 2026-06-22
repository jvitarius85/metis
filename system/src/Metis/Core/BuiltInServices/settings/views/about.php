<?php
if ( ! defined( 'METIS_ROOT' ) ) exit;
require_once __DIR__ . '/_settings_bootstrap.php';
$ctx = metis_settings_bootstrap( 'about' );
if ( empty( $ctx['allowed'] ) ) return;
extract( $ctx, EXTR_SKIP );

$release_current = is_array( $release_status['current'] ?? null ) ? $release_status['current'] : [];
$release_latest = is_array( $release_status['latest'] ?? null ) ? $release_status['latest'] : [];
$release_installed_version = (string) ( $release_current['version'] ?? $release_status['installed_version'] ?? ( $system_version['metis_version'] ?? 'unknown' ) );
$module_count = is_array( $system_version['modules'] ?? null ) ? count( $system_version['modules'] ) : 0;
$module_versions = is_array( $system_version['modules'] ?? null ) ? $system_version['modules'] : [];
$module_details = is_array( $system_version['module_details'] ?? null ) ? $system_version['module_details'] : [];
$module_failures = is_array( $system_version['module_failures'] ?? null ) ? $system_version['module_failures'] : [];
$failed_module_count = count( $module_failures );
$loaded_module_count = max( 0, $module_count - $failed_module_count );
if ( $module_details === [] && $module_versions !== [] ) {
    foreach ( $module_versions as $module_slug => $module_version ) {
        $module_details[] = [
            'slug' => (string) $module_slug,
            'version' => (string) $module_version,
            'status' => 'loaded',
            'reason' => '',
        ];
    }
}

$module_registry = function_exists( 'metis_github_update_service' )
    ? (array) metis_github_update_service()->moduleRegistry()
    : [];
$module_update_status = function_exists( 'metis_module_update_status_snapshot' )
    ? (array) metis_module_update_status_snapshot()
    : ( function_exists( 'metis_module_update_status' ) ? (array) metis_module_update_status( false ) : [] );
$registry_modules = is_array( $module_registry['modules'] ?? null ) ? (array) $module_registry['modules'] : [];
$module_update_rows = is_array( $module_update_status['modules'] ?? null ) ? (array) $module_update_status['modules'] : [];
$module_update_map = [];
foreach ( $module_update_rows as $module_update_row ) {
    if ( ! is_array( $module_update_row ) ) {
        continue;
    }
    $update_id = metis_key_clean( (string) ( $module_update_row['id'] ?? '' ) );
    if ( $update_id !== '' ) {
        $module_update_map[ $update_id ] = $module_update_row;
    }
}

$installed_module_map = [];
if ( function_exists( 'metis_module_update_service' ) ) {
    foreach ( (array) metis_module_update_service()->discoverInstalledModules() as $installed_module ) {
        if ( ! is_array( $installed_module ) ) {
            continue;
        }
        $installed_id = metis_key_clean( (string) ( $installed_module['id'] ?? '' ) );
        if ( $installed_id !== '' ) {
            $installed_module_map[ $installed_id ] = $installed_module;
        }
    }
}

$display_datetime = static function ( string $value ): string {
    return function_exists( 'metis_runtime_format_datetime' )
        ? metis_runtime_format_datetime( $value, null, null, null, '' )
        : $value;
};

$module_update_count = (int) ( $module_update_status['update_count'] ?? 0 );
$update_notice_count = ( ! empty( $release_status['update_available'] ) ? 1 : 0 ) + $module_update_count;
$release_checked_at = trim( (string) ( $release_status['last_checked_at'] ?? '' ) );
$release_checked_display = $release_checked_at !== '' ? $display_datetime( $release_checked_at ) : 'Never checked';
$module_checked_at = trim( (string) ( $module_update_status['checked_at'] ?? '' ) );
$module_checked_display = $module_checked_at !== '' ? $display_datetime( $module_checked_at ) : 'Never checked';
usort( $module_details, static function ( array $a, array $b ): int {
    return strcmp( (string) ( $a['slug'] ?? '' ), (string) ( $b['slug'] ?? '' ) );
} );

$module_refresh_icon = metis_navigation_svg_icon_markup( 'refresh' );
$module_update_icon = metis_navigation_svg_icon_markup( 'update' );
$module_install_icon = metis_navigation_svg_icon_markup( 'download' );
$module_loading_icon = metis_navigation_svg_icon_markup( 'loading-circle' );
$module_arrow_icon = metis_navigation_svg_icon_markup( 'arrow-right' );
$release_apply_tag = trim( (string) ( $release_latest['tag'] ?? $release_latest['version'] ?? '' ) );
?>
<h1 class="metis-page-title"><?php echo metis_escape_html( metis_current_module_view_title( 'Settings' ) ); ?></h1>
<p class="metis-subtitle">Review Metis version, update status, and loaded modules.</p>
<?php metis_settings_render_messages( $saved, $errors ); ?>
<?php metis_settings_render_section_nav( 'about' ); ?>

<div data-settings-live-root="about">
<div class="metis-settings-card" data-settings-about-summary>
    <div class="metis-settings-header">
        <h2>About</h2>
        <div class="metis-module-actions-row">
            <button
                type="button"
                class="metis-module-action metis-module-action--reinstall"
                data-release-check-updates="1"
            >
                <span class="metis-module-action__label">Refresh Updates</span>
                <span class="metis-module-action__icon" aria-hidden="true"><?php echo $module_refresh_icon; ?></span>
                <span class="metis-module-action__spinner" aria-hidden="true"><?php echo $module_loading_icon; ?></span>
            </button>
            <span class="metis-settings-status <?php echo $update_notice_count > 0 ? 'is-warning' : 'is-ok'; ?>"><?php echo metis_escape_html( (string) $update_notice_count ); ?></span>
        </div>
    </div>
    <div class="metis-settings-body">
        <div class="metis-settings-stats-grid">
            <div class="metis-settings-stat-card<?php echo ! empty( $release_status['update_available'] ) ? ' is-warning' : ' is-ok'; ?>">
                <div class="metis-settings-stat-card__label">Metis Update</div>
                <div class="metis-settings-stat-card__value">
                    <?php if ( ! empty( $release_status['update_available'] ) ) : ?>
                        <span class="metis-settings-version-flow">
                            <span><?php echo metis_escape_html( $release_installed_version ); ?></span>
                            <span class="metis-settings-version-flow__icon" aria-hidden="true"><?php echo $module_arrow_icon; ?></span>
                            <span><?php echo metis_escape_html( (string) ( $release_latest['version'] ?? $release_latest['tag'] ?? '' ) ); ?></span>
                        </span>
                    <?php else : ?>
                        Current
                    <?php endif; ?>
                </div>
                <div class="metis-settings-stat-card__note"><?php echo metis_escape_html( $release_checked_display ); ?></div>
                <?php if ( $is_system_admin && ! empty( $release_status['update_available'] ) && $release_apply_tag !== '' ) : ?>
                    <div class="metis-settings-stat-card__actions">
                        <div class="metis-module-action-group">
                            <button
                                type="button"
                                class="metis-module-action metis-module-action--install metis-module-action--icon"
                                data-release-apply-tag="<?php echo metis_escape_attr( $release_apply_tag ); ?>"
                                title="Install Metis Update"
                                aria-label="Install Metis Update"
                            >
                                <span class="metis-module-action__label">Install Update</span>
                                <span class="metis-module-action__icon" aria-hidden="true"><?php echo $module_install_icon; ?></span>
                                <span class="metis-module-action__spinner" aria-hidden="true"><?php echo $module_loading_icon; ?></span>
                            </button>
                        </div>
                    </div>
                <?php endif; ?>
            </div>
            <div class="metis-settings-stat-card<?php echo $module_update_count > 0 ? ' is-warning' : ' is-ok'; ?>">
                <div class="metis-settings-stat-card__label">Module Updates</div>
                <div class="metis-settings-stat-card__value"><?php echo metis_escape_html( (string) $module_update_count ); ?></div>
                <div class="metis-settings-stat-card__note"><?php echo metis_escape_html( $module_checked_display ); ?></div>
                <?php if ( $is_system_admin && $module_update_count > 0 ) : ?>
                    <div class="metis-settings-stat-card__actions">
                        <div class="metis-module-action-group">
                            <button
                                type="button"
                                class="metis-module-action metis-module-action--install metis-module-action--icon"
                                data-module-update-all="1"
                                title="Install Module Updates"
                                aria-label="Install Module Updates"
                            >
                                <span class="metis-module-action__label">Install Updates</span>
                                <span class="metis-module-action__icon" aria-hidden="true"><?php echo $module_install_icon; ?></span>
                                <span class="metis-module-action__spinner" aria-hidden="true"><?php echo $module_loading_icon; ?></span>
                            </button>
                        </div>
                    </div>
                <?php endif; ?>
            </div>
            <div class="metis-settings-stat-card is-ok">
                <div class="metis-settings-stat-card__label">Loaded Modules</div>
                <div class="metis-settings-stat-card__value"><?php echo metis_escape_html( (string) $loaded_module_count ); ?></div>
                <div class="metis-settings-stat-card__note"><?php echo metis_escape_html( $failed_module_count > 0 ? $failed_module_count . ' failed' : 'All loaded cleanly' ); ?></div>
            </div>
            <div class="metis-settings-stat-card is-ok">
                <div class="metis-settings-stat-card__label">Metis Version</div>
                <div class="metis-settings-stat-card__value"><?php echo metis_escape_html( (string) ( $system_version['metis_version'] ?? 'unknown' ) ); ?></div>
                <div class="metis-settings-stat-card__note">Build <?php echo metis_escape_html( (string) ( $system_version['build'] ?? 'unknown' ) ); ?></div>
            </div>
        </div>
        <div class="metis-settings-progress-stack">
            <div class="metis-release-progress" data-about-progress-panel="core" hidden>
                <div class="metis-release-progress__head">
                    <strong data-about-progress-title="core">Metis Update</strong>
                    <span data-about-progress-percent="core">0%</span>
                </div>
                <div class="metis-release-progress__bar" aria-hidden="true"><span data-about-progress-bar="core"></span></div>
                <div class="metis-release-progress__status" data-about-progress-status="core">Preparing update...</div>
            </div>
            <div class="metis-release-progress" data-about-progress-panel="modules" hidden>
                <div class="metis-release-progress__head">
                    <strong data-about-progress-title="modules">Module Updates</strong>
                    <span data-about-progress-percent="modules">0%</span>
                </div>
                <div class="metis-release-progress__bar" aria-hidden="true"><span data-about-progress-bar="modules"></span></div>
                <div class="metis-release-progress__status" data-about-progress-status="modules">Preparing module updates...</div>
            </div>
        </div>
        <?php if ( ! empty( $module_update_status['registry_error'] ) ) : ?>
            <p class="metis-help" style="color:#b91c1c; margin-top:16px;"><?php echo metis_escape_html( (string) $module_update_status['registry_error'] ); ?></p>
        <?php endif; ?>
    </div>
</div>

<?php if ( $module_details !== [] ) : ?>
    <div class="metis-settings-card" data-settings-about-modules>
        <div class="metis-settings-header"><h2>Loaded Modules</h2></div>
        <div class="metis-settings-body">
            <div class="metis-module-grid">
                <?php foreach ( $module_details as $module_detail ) : ?>
                    <?php
                    $module_slug = metis_key_clean( (string) ( $module_detail['slug'] ?? '' ) );
                    if ( $module_slug === '' ) {
                        continue;
                    }
                    $installed_module = is_array( $installed_module_map[ $module_slug ] ?? null ) ? (array) $installed_module_map[ $module_slug ] : [];
                    $module_update = is_array( $module_update_map[ $module_slug ] ?? null ) ? (array) $module_update_map[ $module_slug ] : [];
                    $module_version = (string) ( $module_detail['version'] ?? 'unknown' );
                    $module_status = metis_key_clean( (string) ( $module_detail['status'] ?? 'loaded' ) );
                    $module_reason = trim( (string) ( $module_detail['reason'] ?? '' ) );
                    $module_name = trim( (string) ( $installed_module['name'] ?? $module_update['name'] ?? ucwords( str_replace( [ '_', '-' ], ' ', (string) $module_slug ) ) ) );
                    $module_description = trim( (string) ( $installed_module['description'] ?? $module_update['description'] ?? $registry_modules[ $module_slug ]['description'] ?? '' ) );
                    $has_module_update = ! empty( $module_update['update_available'] );
                    ?>
                    <div class="metis-module-card is-available">
                        <div class="metis-module-card__head">
                            <div>
                                <div class="metis-module-card__title"><?php echo metis_escape_html( $module_name ); ?></div>
                                <p class="metis-module-card__version">
                                    <?php echo metis_escape_html( (string) $module_version ); ?>
                                    <?php if ( $has_module_update ) : ?>
                                        <span class="metis-settings-version-flow">
                                            <span class="metis-settings-version-flow__icon" aria-hidden="true"><?php echo $module_arrow_icon; ?></span>
                                            <span><?php echo metis_escape_html( (string) ( $module_update['latest'] ?? '' ) ); ?></span>
                                        </span>
                                    <?php endif; ?>
                                </p>
                            </div>
                        </div>
                        <?php if ( $module_description !== '' ) : ?>
                            <p class="metis-module-card__description"><?php echo metis_escape_html( $module_description ); ?></p>
                        <?php endif; ?>
                        <?php if ( $has_module_update && ! empty( $module_update['minimum_metis'] ) ) : ?>
                            <p class="metis-module-card__note is-warning">Requires Metis <?php echo metis_escape_html( (string) $module_update['minimum_metis'] ); ?>+</p>
                        <?php endif; ?>
                        <?php if ( $module_status === 'failed' && $module_reason !== '' ) : ?>
                            <p class="metis-module-card__note is-warning"><?php echo metis_escape_html( $module_reason ); ?></p>
                        <?php endif; ?>
                    </div>
                <?php endforeach; ?>
            </div>
        </div>
    </div>
<?php endif; ?>
<div class="metis-settings-live-feedback" data-settings-live-feedback="about"></div>
</div>
