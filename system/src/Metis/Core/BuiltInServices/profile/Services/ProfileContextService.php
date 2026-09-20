<?php
declare(strict_types=1);

namespace Metis\Modules\Profile\Services;

use Metis\Modules\People\MfaService;
use Metis\Modules\People\PersonProfileService;

final class ProfileContextService {
    public static function ensureReady(): void {
        if ( function_exists( 'metis_people_ensure_schema' ) ) {
            \metis_people_ensure_schema();
        }
        if ( function_exists( 'metis_people_seed_permissions_and_roles' ) ) {
            \metis_people_seed_permissions_and_roles();
        }
    }

    public static function currentPerson(): ?array {
        static $loaded = false;
        static $cached = null;

        if ( $loaded ) {
            return is_array( $cached ) ? $cached : null;
        }

        if ( ! function_exists( 'metis_user_logged_in' ) || ! \metis_user_logged_in() ) {
            $loaded = true;
            return null;
        }

        if ( function_exists( 'metis_auth_current_person_id' ) ) {
            $person_id = (int) \metis_auth_current_person_id();
            if ( $person_id > 0 ) {
                $person = PersonProfileService::getById( $person_id );
                if ( is_array( $person ) ) {
                    $cached = $person;
                    $loaded = true;
                    return $person;
                }
            }
        }

        $person_id = function_exists( 'metis_people_get_current_person_id' ) ? (int) \metis_people_get_current_person_id() : 0;
        if ( $person_id > 0 ) {
            $person = PersonProfileService::getById( $person_id );
            if ( is_array( $person ) ) {
                $cached = $person;
                $loaded = true;
                return $person;
            }
        }

        $loaded = true;
        return null;
    }

    public static function personPayload( array $person ): array {
        $auth_user = function_exists( 'metis_auth_find_user' ) ? \metis_auth_find_user( 'person_id', (int) ( $person['id'] ?? 0 ) ) : null;
        $local_password_available = function_exists( 'metis_auth_password_hash_for_authentication' )
            && is_array( $auth_user )
            && \metis_auth_password_hash_for_authentication( $auth_user, $person ) !== '';
        $workspace_password_available = ! empty( $person['is_workspace_user'] ) || \metis_email_is_valid( (string) ( $person['workspace_email'] ?? '' ) );
        $passkeys = \Metis_Tables::has( 'people_passkeys' )
            ? MfaService::activePasskeys( (int) ( $person['id'] ?? 0 ) )
            : [];

        $notification_prefs = [];
        if ( ! empty( $person['notification_prefs_json'] ) ) {
            $decoded = json_decode( (string) $person['notification_prefs_json'], true );
            if ( is_array( $decoded ) ) {
                $notification_prefs = $decoded;
            }
        }

        $avatar_name = trim( (string) ( $person['display_name'] ?? '' ) );
        if ( $avatar_name === '' ) {
            $avatar_name = trim( (string) ( $person['first_name'] ?? '' ) . ' ' . (string) ( $person['last_name'] ?? '' ) );
        }
        $avatar_src = \metis_avatar_url( $avatar_name, (string) ( $person['avatar_url'] ?? '' ), 160, (string) ( $person['pid'] ?? '' ) );
        $carddav_tokens = function_exists( 'metis_contacts_carddav_list_tokens' )
            ? (array) \metis_contacts_carddav_list_tokens( \metis_current_user_id() )
            : [];
        $carddav_endpoint = function_exists( 'metis_contacts_carddav_endpoint_url' )
            ? (string) \metis_contacts_carddav_endpoint_url( 'addressbooks/' )
            : '';
        $current_user = function_exists( 'metis_runtime_current_user' ) ? \metis_runtime_current_user() : null;
        $carddav_username = $current_user instanceof \MetisUser
            ? (string) $current_user->user_login
            : (string) ( $person['email'] ?? '' );

        return [
            'id' => (int) ( $person['id'] ?? 0 ),
            'pid' => (string) ( $person['pid'] ?? '' ),
            'first_name' => (string) ( $person['first_name'] ?? '' ),
            'last_name' => (string) ( $person['last_name'] ?? '' ),
            'display_name' => (string) ( $person['display_name'] ?? '' ),
            'email' => (string) ( $person['email'] ?? '' ),
            'auth_provider' => (string) ( $person['auth_provider'] ?? 'metis' ),
            'department' => (string) ( $person['department'] ?? '' ),
            'manager_pid' => (string) ( $person['manager_pid'] ?? '' ),
            'lifecycle_status' => (string) ( $person['lifecycle_status'] ?? 'active' ),
            'public_slug' => (string) ( $person['public_slug'] ?? '' ),
            'public_tagline' => (string) ( $person['public_tagline'] ?? '' ),
            'public_bio_html' => (string) ( $person['public_bio_html'] ?? '' ),
            'public_visibility' => (string) ( $person['public_visibility'] ?? 'private' ),
            'email_notifications' => ! isset( $person['email_notifications'] ) || (int) $person['email_notifications'] === 1,
            'requires_2fa' => ! empty( $person['requires_2fa'] ),
            'mfa_method' => (string) ( $person['mfa_method'] ?? 'none' ),
            'totp_enabled' => ! empty( $person['totp_enabled'] ),
            'passkey_enabled' => ! empty( $person['passkey_enabled'] ),
            'has_metis_password' => $local_password_available,
            'has_workspace_password' => $workspace_password_available,
            'passkeys' => $passkeys,
            'notification_prefs' => $notification_prefs,
            'avatar_url' => $avatar_src,
            'updated_at' => (string) ( $person['updated_at'] ?? '' ),
            'carddav_tokens' => $carddav_tokens,
            'carddav_endpoint' => $carddav_endpoint,
            'carddav_username' => $carddav_username,
        ];
    }
}
