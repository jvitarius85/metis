<?php
declare(strict_types=1);

require_once __DIR__ . '/App.php';

function metis_update_server_root(): string {
    return dirname(__DIR__);
}

function metis_update_server_storage(): string {
    return metis_update_server_root() . '/storage';
}

function metis_update_server_config_path(): string {
    return metis_update_server_storage() . '/config.php';
}

function metis_update_server_load_config(): array {
    $path = metis_update_server_config_path();
    if ( ! is_file( $path ) ) {
        throw new RuntimeException( 'Update server config is missing. Run cli/install.php first.' );
    }

    $config = require $path;
    if ( ! is_array( $config ) ) {
        throw new RuntimeException( 'Update server config is invalid.' );
    }

    return $config;
}

function metis_update_server_app(): MetisUpdateServerApp {
    return new MetisUpdateServerApp(
        metis_update_server_load_config(),
        metis_update_server_root()
    );
}
