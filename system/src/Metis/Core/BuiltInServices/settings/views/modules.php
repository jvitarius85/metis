<?php
if ( ! defined( 'METIS_ROOT' ) ) exit;
require_once __DIR__ . '/_settings_bootstrap.php';
$ctx = metis_settings_bootstrap( 'modules' );
if ( empty( $ctx['allowed'] ) ) return;
extract( $ctx, EXTR_SKIP );

$module_registry = function_exists( 'metis_github_update_service' )
    ? (array) metis_github_update_service()->moduleRegistry()
    : [];
$module_update_status = function_exists( 'metis_module_update_status_snapshot' )
    ? (array) metis_module_update_status_snapshot()
    : [];
$registry_rows = is_array( $module_registry['modules'] ?? null ) ? (array) $module_registry['modules'] : [];
$update_rows = is_array( $module_update_status['modules'] ?? null ) ? (array) $module_update_status['modules'] : [];
$update_map = [];
foreach ( $update_rows as $row ) {
    if ( ! is_array( $row ) ) {
        continue;
    }
    $row_id = metis_key_clean( (string) ( $row['id'] ?? '' ) );
    if ( $row_id !== '' ) {
        $update_map[ $row_id ] = $row;
    }
}

$current_metis_version = (string) ( $system_version['metis_version'] ?? '' );
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

$modules = [];
foreach ( $registry_rows as $module_id => $registry_row ) {
    if ( ! is_array( $registry_row ) ) {
        continue;
    }
    $module_id = metis_key_clean( (string) $module_id );
    if ( $module_id === '' ) {
        continue;
    }
    $update_row = is_array( $update_map[ $module_id ] ?? null ) ? (array) $update_map[ $module_id ] : [];
    $installed_row = is_array( $installed_module_map[ $module_id ] ?? null ) ? (array) $installed_module_map[ $module_id ] : [];
    $current_version = trim( (string) ( $update_row['current'] ?? '' ) );
    $name = trim( (string) ( $update_row['name'] ?? $installed_row['name'] ?? $registry_row['name'] ?? '' ) );
    if ( $name === '' ) {
        $name = ucwords( str_replace( [ '_', '-' ], ' ', $module_id ) );
    }
    $minimum_metis = trim( (string) ( $registry_row['minimum_metis'] ?? '' ) );
    $requires_newer_metis = $minimum_metis !== '' && $current_metis_version !== '' && version_compare( $current_metis_version, $minimum_metis, '<' );
    $description = trim( (string) ( $update_row['description'] ?? $installed_row['description'] ?? $registry_row['description'] ?? '' ) );
    $modules[] = [
        'id' => $module_id,
        'name' => $name,
        'description' => $description,
        'latest' => trim( (string) ( $registry_row['latest'] ?? '' ) ),
        'minimum_metis' => $minimum_metis,
        'release_channel' => trim( (string) ( $registry_row['release_channel'] ?? 'stable' ) ) ?: 'stable',
        'download_url' => trim( (string) ( $registry_row['download_url'] ?? '' ) ),
        'installed' => $current_version !== '',
        'current' => $current_version,
        'update_available' => ! empty( $update_row['update_available'] ),
        'status' => trim( (string) ( $update_row['status'] ?? ( $current_version !== '' ? 'installed' : 'available' ) ) ),
        'reason' => trim( (string) ( $update_row['reason'] ?? '' ) ),
        'requires_newer_metis' => $requires_newer_metis,
        'runtime_contract_status' => trim( (string) ( $installed_row['runtime_contract_status'] ?? '' ) ),
        'runtime_contract_note' => trim( (string) ( $installed_row['runtime_contract_note'] ?? '' ) ),
    ];
}

usort( $modules, static fn ( array $left, array $right ): int => strcmp( (string) $left['name'], (string) $right['name'] ) );
$installed_modules = array_values( array_filter( $modules, static fn ( array $module ): bool => ! empty( $module['installed'] ) ) );
$available_modules = array_values( array_filter( $modules, static fn ( array $module ): bool => empty( $module['installed'] ) ) );
$display_datetime = static function ( string $value ): string {
    return function_exists( 'metis_runtime_format_datetime' )
        ? metis_runtime_format_datetime( $value, null, null, null, '' )
        : $value;
};
$store_generated_at = trim( (string) ( $module_registry['generated_at'] ?? $module_update_status['registry_generated_at'] ?? '' ) );
$store_generated_display = $store_generated_at !== '' ? $display_datetime( $store_generated_at ) : 'Never refreshed';
$module_refresh_icon = metis_navigation_svg_icon_markup( 'refresh' );
$module_update_icon = metis_navigation_svg_icon_markup( 'update' );
$module_install_icon = metis_navigation_svg_icon_markup( 'download' );
$module_uninstall_icon = metis_navigation_svg_icon_markup( 'close-outline' );
$module_loading_icon = metis_navigation_svg_icon_markup( 'loading-circle' );
$module_arrow_icon = metis_navigation_svg_icon_markup( 'arrow-right' );
?>
<h1 class="metis-page-title">Modules Store</h1>
<p class="metis-subtitle">Browse modules, manage installed packages, and install updates immediately.</p>
<?php metis_settings_render_messages( $saved, $errors ); ?>

<div data-settings-live-root="modules">
<div class="metis-settings-card" data-settings-modules-store>
    <div class="metis-settings-header">
        <h2>Modules Store</h2>
        <div class="metis-module-actions-row">
            <span class="metis-settings-status <?php echo $modules === [] ? 'is-missing' : 'is-ok'; ?>"><?php echo metis_escape_html( (string) count( $modules ) ); ?></span>
            <button type="button" class="metis-module-action metis-module-action--reinstall metis-module-action--icon" data-release-check-updates title="Refresh Module Store" aria-label="Refresh Module Store">
                <span class="metis-module-action__label">Refresh Module Store</span>
                <span class="metis-module-action__icon" aria-hidden="true"><?php echo $module_refresh_icon; ?></span>
                <span class="metis-module-action__spinner" aria-hidden="true"><?php echo $module_loading_icon; ?></span>
            </button>
            <span class="metis-help"><?php echo metis_escape_html( $store_generated_display ); ?></span>
        </div>
    </div>
    <div class="metis-settings-body">
        <?php if ( ! empty( $module_registry['error'] ) ) : ?>
            <p class="metis-help" style="color:#b91c1c;"><?php echo metis_escape_html( (string) $module_registry['error'] ); ?></p>
        <?php endif; ?>
        <?php if ( $modules === [] ) : ?>
            <p class="metis-help">No registry modules are currently available.</p>
        <?php else : ?>
            <?php
            $render_module_grid = static function ( array $module_rows ) use ( $module_install_icon, $module_loading_icon, $module_uninstall_icon, $module_update_icon, $module_arrow_icon ): void {
                ?>
                <div class="metis-module-grid">
                    <?php foreach ( $module_rows as $module ) : ?>
                        <?php
                        $action_label = ! empty( $module['installed'] )
                            ? ( ! empty( $module['update_available'] ) ? 'Update' : 'Reinstall' )
                            : 'Install';
                        $action_kind = ! empty( $module['update_available'] )
                            ? 'update'
                            : ( ! empty( $module['installed'] ) ? 'reinstall' : 'install' );
                        $action_icon = $action_kind === 'install' ? $module_install_icon : $module_update_icon;
                        $card_state = ! empty( $module['update_available'] )
                            ? ' is-update-available'
                            : ( ! empty( $module['installed'] ) ? ' is-installed' : ' is-available' );
                        ?>
                        <div class="metis-module-card<?php echo $card_state; ?>">
                            <div class="metis-module-card__head">
                                <div>
                                    <div class="metis-module-card__title"><?php echo metis_escape_html( (string) $module['name'] ); ?></div>
                                    <p class="metis-module-card__version">
                                        <?php if ( ! empty( $module['installed'] ) ) : ?>
                                            <?php if ( ! empty( $module['update_available'] ) ) : ?>
                                                <span class="metis-settings-version-flow">
                                                    <span><?php echo metis_escape_html( (string) $module['current'] ); ?></span>
                                                    <span class="metis-settings-version-flow__icon" aria-hidden="true"><?php echo $module_arrow_icon; ?></span>
                                                    <span><?php echo metis_escape_html( (string) $module['latest'] ); ?></span>
                                                </span>
                                            <?php else : ?>
                                                <?php echo metis_escape_html( (string) $module['current'] ); ?>
                                            <?php endif; ?>
                                        <?php else : ?>
                                            <?php echo metis_escape_html( (string) $module['latest'] ); ?>
                                        <?php endif; ?>
                                    </p>
                                </div>
                                <div class="metis-module-card__actions">
                                        <div class="metis-module-action-group<?php echo empty( $module['installed'] ) ? ' metis-module-action-group--single' : ''; ?>">
                                            <button
                                                type="button"
                                                class="metis-module-action metis-module-action--<?php echo metis_escape_attr( $action_kind ); ?> metis-module-action--icon"
                                            data-module-install-id="<?php echo metis_escape_attr( (string) $module['id'] ); ?>"
                                            data-module-install-name="<?php echo metis_escape_attr( (string) $module['name'] ); ?>"
                                            data-module-install-version="<?php echo metis_escape_attr( ! empty( $module['installed'] ) ? (string) $module['latest'] : (string) $module['latest'] ); ?>"
                                            data-module-action-kind="<?php echo metis_escape_attr( $action_kind ); ?>"
                                            title="<?php echo metis_escape_attr( $action_label . ' ' . (string) $module['name'] ); ?>"
                                            aria-label="<?php echo metis_escape_attr( $action_label . ' ' . (string) $module['name'] ); ?>"
                                            >
                                                <span class="metis-module-action__label"><?php echo metis_escape_html( $action_label ); ?></span>
                                                <span class="metis-module-action__icon" aria-hidden="true"><?php echo $action_icon; ?></span>
                                                <span class="metis-module-action__spinner" aria-hidden="true"><?php echo $module_loading_icon; ?></span>
                                            </button>
                                        <?php if ( ! empty( $module['installed'] ) ) : ?>
                                            <button
                                                type="button"
                                                class="metis-module-action metis-module-action--danger metis-module-action--icon"
                                                data-module-install-id="<?php echo metis_escape_attr( (string) $module['id'] ); ?>"
                                                data-module-install-name="<?php echo metis_escape_attr( (string) $module['name'] ); ?>"
                                                data-module-install-version="<?php echo metis_escape_attr( (string) $module['current'] ); ?>"
                                                data-module-action-kind="uninstall"
                                                title="<?php echo metis_escape_attr( 'Uninstall ' . (string) $module['name'] ); ?>"
                                                aria-label="<?php echo metis_escape_attr( 'Uninstall ' . (string) $module['name'] ); ?>"
                                            >
                                                <span class="metis-module-action__label">Uninstall</span>
                                                <span class="metis-module-action__icon" aria-hidden="true"><?php echo $module_uninstall_icon; ?></span>
                                                <span class="metis-module-action__spinner" aria-hidden="true"><?php echo $module_loading_icon; ?></span>
                                            </button>
                                        <?php else : ?>
                                            <span class="metis-module-action metis-module-action--placeholder" aria-hidden="true"></span>
                                        <?php endif; ?>
                                        </div>
                                </div>
                            </div>
                            <?php if ( ! empty( $module['description'] ) ) : ?>
                                <p class="metis-module-card__description"><?php echo metis_escape_html( (string) $module['description'] ); ?></p>
                            <?php endif; ?>
                            <?php if ( ! empty( $module['requires_newer_metis'] ) ) : ?>
                                <p class="metis-module-card__note is-warning">Requires Metis <?php echo metis_escape_html( (string) $module['minimum_metis'] ); ?>+</p>
                            <?php endif; ?>
                            <?php if ( ! empty( $module['runtime_contract_note'] ) ) : ?>
                                <?php $runtime_note_warning = in_array( (string) ( $module['runtime_contract_status'] ?? '' ), [ 'source_backed_entry', 'missing_entry', 'unreadable_entry', 'unknown_entry_contract' ], true ); ?>
                                <?php if ( $runtime_note_warning ) : ?>
                                    <p class="metis-module-card__note is-warning"><?php echo metis_escape_html( (string) $module['runtime_contract_note'] ); ?></p>
                                <?php endif; ?>
                            <?php endif; ?>
                            <?php if ( ! empty( $module['reason'] ) ) : ?>
                                <p class="metis-module-card__note is-warning"><?php echo metis_escape_html( (string) $module['reason'] ); ?></p>
                            <?php endif; ?>
                        </div>
                    <?php endforeach; ?>
                </div>
                <?php
            };
            ?>
            <?php if ( $installed_modules !== [] ) : ?>
                <div class="metis-settings-card" style="margin-top:16px;">
                    <div class="metis-settings-header">
                        <h2>Installed Modules</h2>
                        <span class="metis-settings-status is-ok"><?php echo metis_escape_html( (string) count( $installed_modules ) ); ?></span>
                    </div>
                    <div class="metis-settings-body">
                        <?php $render_module_grid( $installed_modules ); ?>
                    </div>
                </div>
            <?php endif; ?>
            <?php if ( $available_modules !== [] ) : ?>
                <div class="metis-settings-card" style="margin-top:16px;">
                    <div class="metis-settings-header">
                        <h2>Available Modules</h2>
                        <span class="metis-settings-status is-ok"><?php echo metis_escape_html( (string) count( $available_modules ) ); ?></span>
                    </div>
                    <div class="metis-settings-body">
                        <?php $render_module_grid( $available_modules ); ?>
                    </div>
                </div>
            <?php endif; ?>
        <?php endif; ?>
    </div>
</div>
<div class="metis-settings-live-feedback" data-settings-live-feedback="modules"></div>
</div>
