<?php
if ( ! defined( 'METIS_ROOT' ) ) exit;

function metis_hermes_ajax_verify( bool $manage = false ): void {
    if ( ! metis_hermes_can_view() ) {
        metis_runtime_send_json_error( [ 'message' => 'Unauthorized.' ], 403 );
    }
    if ( $manage && ! metis_hermes_can_manage() ) {
        metis_runtime_send_json_error( [ 'message' => 'Unauthorized.' ], 403 );
    }
    metis_hermes_ensure_schema();
}

function metis_hermes_ajax_handle( callable $callback ): void {
    try {
        metis_runtime_send_json_success( $callback() );
    } catch ( Throwable $throwable ) {
        if ( class_exists( 'Metis_Logger' ) ) {
            Metis_Logger::error( 'hermes.ajax.failed', [
                'exception' => get_class( $throwable ),
                'message' => $throwable->getMessage(),
            ] );
        }
        metis_runtime_send_json_error( [
            'message' => 'Hermes request failed.',
        ], 500 );
    }
}

function metis_hermes_release_progress_token( string $token ): string {
    $token = preg_replace( '/[^a-z0-9_-]/i', '', strtolower( trim( $token ) ) ) ?? '';
    return substr( $token, 0, 64 );
}

function metis_hermes_release_progress_store_file( string $token ): string {
    return 'hermes/release-progress/' . metis_hermes_release_progress_token( $token ) . '.json';
}

function metis_hermes_approval_note_from_request(): string {
    return metis_textarea_clean( metis_runtime_unslash( metis_request_post()['note'] ?? '' ) );
}

function metis_hermes_register_ajax_controllers(): void {
    static $registered = false;

    if ( $registered || ! function_exists( 'metis_ajax_register_controller' ) ) {
        return;
    }

    $registered = true;

    foreach ( \Metis\Modules\Hermes\Policies\HermesPolicy::ajaxControllers() as $action => $config ) {
        metis_ajax_register_controller( $action, [
            'module' => 'hermes',
            'permission' => $config['permission'],
            'nonce_action' => $config['nonce_action'],
            'schema' => $config['schema'],
        ] );
    }
}

metis_ajax_register_handler( 'metis_hermes_bootstrap', [ \Metis\Modules\Hermes\Controllers\AjaxController::class, 'bootstrap' ] );
metis_ajax_register_handler( 'metis_hermes_query', [ \Metis\Modules\Hermes\Controllers\AjaxController::class, 'query' ] );
metis_ajax_register_handler( 'metis_hermes_diagnostics', [ \Metis\Modules\Hermes\Controllers\AjaxController::class, 'diagnostics' ] );
metis_ajax_register_handler( 'metis_hermes_preview_action', [ \Metis\Modules\Hermes\Controllers\AjaxController::class, 'previewAction' ] );
metis_ajax_register_handler( 'metis_hermes_approve_action', [ \Metis\Modules\Hermes\Controllers\AjaxController::class, 'approveAction' ] );
metis_ajax_register_handler( 'metis_hermes_execute_action', [ \Metis\Modules\Hermes\Controllers\AjaxController::class, 'executeAction' ] );
metis_ajax_register_handler( 'metis_hermes_execute_release_action', [ \Metis\Modules\Hermes\Controllers\AjaxController::class, 'executeReleaseAction' ] );
metis_ajax_register_handler( 'metis_hermes_release_progress', [ \Metis\Modules\Hermes\Controllers\AjaxController::class, 'releaseProgress' ] );
metis_ajax_register_handler( 'metis_hermes_reveal_secret', [ \Metis\Modules\Hermes\Controllers\AjaxController::class, 'revealSecret' ] );

if ( function_exists( 'metis_ajax_register_controller' ) ) {
    metis_hermes_register_ajax_controllers();
}
