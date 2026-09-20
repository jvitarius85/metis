<?php
declare(strict_types=1);

namespace Metis\Modules\Help\Policies;

use Metis\Http\Response;

final class HelpPolicy {
    public static function requireViewAccess(): ?Response {
        if ( ! function_exists( 'metis_user_logged_in' ) || ! \metis_user_logged_in() ) {
            return Response::redirect( self::loginUrl() );
        }

        if ( ! function_exists( 'metis_security_user_can' ) || ! \metis_security_user_can( 'help.view' ) ) {
            return self::permissionDenied( 'You do not have permission to view Help articles.' );
        }

        return null;
    }

    public static function requireManageAccess(): ?Response {
        if ( $redirect = self::requireViewAccess() ) {
            return $redirect;
        }

        if ( ! function_exists( 'metis_security_user_can' ) || ! \metis_security_user_can( 'help.manage' ) ) {
            return self::permissionDenied( 'You do not have permission to manage Help articles.' );
        }

        return null;
    }

    private static function permissionDenied( string $message ): Response {
        return \Metis\Modules\Help\HelpModule::errorResponse( 403, 'Permission Denied', $message );
    }

    private static function loginUrl(): string {
        return function_exists( 'metis_auth_login_url' ) ? (string) \metis_auth_login_url() : '/login';
    }
}
