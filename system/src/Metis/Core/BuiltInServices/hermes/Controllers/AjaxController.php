<?php
declare(strict_types=1);

namespace Metis\Modules\Hermes\Controllers;

use Metis\Modules\Hermes\Policies\HermesPolicy;
use Metis\Modules\Hermes\Requests\ActionCodeRequest;
use Metis\Modules\Hermes\Requests\ApproveActionRequest;
use Metis\Modules\Hermes\Requests\ConversationRequest;
use Metis\Modules\Hermes\Requests\DiagnosticsRequest;
use Metis\Modules\Hermes\Requests\ProgressTokenRequest;
use Metis\Modules\Hermes\Requests\ReleaseActionRequest;
use Metis\Modules\Hermes\Requests\RevealSecretRequest;

final class AjaxController {
    public static function bootstrap(): void {
        self::verifyAccess( false );
        \metis_hermes_ajax_handle( static fn (): array => \metis_hermes_dashboard_payload() );
    }

    public static function query(): void {
        self::verifyAccess( false );
        $request = ConversationRequest::fromGlobals();
        \metis_hermes_ajax_handle( static fn (): array => \metis_hermes_gateway()->converse( $request->query(), $request->sessionCode(), $request->runtimeContext() ) );
    }

    public static function diagnostics(): void {
        self::verifyAccess( false );
        $request = DiagnosticsRequest::fromGlobals();
        \metis_hermes_ajax_handle( static fn (): array => \metis_hermes_gateway()->diagnostics( $request->query(), $request->sessionCode() ) );
    }

    public static function previewAction(): void {
        self::verifyAccess( false );
        $request = ActionCodeRequest::fromGlobals();
        \metis_hermes_ajax_handle( static fn (): array => \metis_hermes_gateway()->previewAction( $request->actionCode() ) );
    }

    public static function approveAction(): void {
        self::verifyAccess( true );
        $request = ApproveActionRequest::fromGlobals( \metis_hermes_approval_note_from_request() );
        \metis_hermes_ajax_handle( static fn (): array => [ 'action' => \metis_hermes_gateway()->approveAction( $request->actionCode(), $request->note() ) ] );
    }

    public static function executeAction(): void {
        self::verifyAccess( true );
        $request = ActionCodeRequest::fromGlobals();
        \metis_hermes_ajax_handle( static fn (): array => \metis_hermes_gateway()->executeAction( $request->actionCode() ) );
    }

    public static function executeReleaseAction(): void {
        self::verifyAccess( true );
        $request = ReleaseActionRequest::fromGlobals();
        \metis_hermes_ajax_handle( static fn (): array => \metis_hermes_gateway()->executeReleaseAction( $request->actionCode(), $request->progressToken() ) );
    }

    public static function releaseProgress(): void {
        self::verifyAccess( true );
        $request = ProgressTokenRequest::fromGlobals();
        $token = \metis_hermes_release_progress_token( $request->progressToken() );
        if ( $token === '' ) {
            \metis_runtime_send_json_error( [ 'message' => 'A progress token is required.' ], 400 );
        }

        $progress = function_exists( 'metis_runtime_json_store_read' )
            ? \metis_runtime_json_store_read( \metis_hermes_release_progress_store_file( $token ) )
            : [];

        \metis_runtime_send_json_success( [
            'message' => 'Release progress loaded.',
            'progress' => is_array( $progress ) ? $progress : [],
        ] );
    }

    public static function revealSecret(): void {
        self::verifyAccess( true );
        $request = RevealSecretRequest::fromGlobals();
        \metis_hermes_ajax_handle( static fn (): array => \metis_hermes_gateway()->revealSecret( $request->revealToken() ) );
    }

    private static function verifyAccess( bool $manage ): void {
        if ( ! HermesPolicy::canView() ) {
            \metis_runtime_send_json_error( [ 'message' => 'Unauthorized.' ], 403 );
        }
        if ( $manage && ! HermesPolicy::canManage() ) {
            \metis_runtime_send_json_error( [ 'message' => 'Unauthorized.' ], 403 );
        }
        \metis_hermes_ensure_schema();
    }
}
