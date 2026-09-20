<?php
declare(strict_types=1);

namespace Metis\Modules\Profile\Controllers;

use Metis\Modules\People\MfaService;
use Metis\Modules\People\PersonProfileService;
use Metis\Modules\Profile\Requests\AvatarUploadRequest;
use Metis\Modules\Profile\Requests\CarddavIssueTokenRequest;
use Metis\Modules\Profile\Requests\CarddavRevokeTokenRequest;
use Metis\Modules\Profile\Requests\ChangePasswordRequest;
use Metis\Modules\Profile\Requests\ChangeWorkspacePasswordRequest;
use Metis\Modules\Profile\Requests\CompletePasskeyRegistrationRequest;
use Metis\Modules\Profile\Requests\RevokePasskeyRequest;
use Metis\Modules\Profile\Requests\SaveProfileRequest;
use Metis\Modules\Profile\Requests\VerifyTotpRequest;
use Metis\Modules\Profile\Services\ProfileContextService;

final class AjaxController {
    public static function getProfile(): void {
        $person = self::currentPersonOrFail();

        \metis_runtime_send_json_success( [
            'person' => ProfileContextService::personPayload( $person ),
        ] );
    }

    public static function issueCarddavToken(): void {
        $person = self::currentPersonOrFail();
        if ( ! function_exists( 'metis_contacts_carddav_issue_token' ) ) {
            \metis_runtime_send_json_error( 'CardDAV token service unavailable.', 500 );
        }

        $request = CarddavIssueTokenRequest::fromGlobals();
        $issued = \metis_contacts_carddav_issue_token( \metis_current_user_id(), $request->label() );
        if ( empty( $issued['ok'] ) ) {
            \metis_runtime_send_json_error( 'Unable to generate CardDAV token.', 500 );
        }

        \metis_runtime_send_json_success( [
            'issued' => $issued,
            'person' => ProfileContextService::personPayload( $person ),
        ] );
    }

    public static function revokeCarddavToken(): void {
        $person = self::currentPersonOrFail();
        if ( ! function_exists( 'metis_contacts_carddav_revoke_token' ) ) {
            \metis_runtime_send_json_error( 'CardDAV token service unavailable.', 500 );
        }

        $request = CarddavRevokeTokenRequest::fromGlobals();
        if ( $request->tokenId() < 1 || ! \metis_contacts_carddav_revoke_token( $request->tokenId(), \metis_current_user_id() ) ) {
            \metis_runtime_send_json_error( 'Unable to revoke CardDAV token.', 400 );
        }

        \metis_runtime_send_json_success( [
            'person' => ProfileContextService::personPayload( $person ),
        ] );
    }

    public static function saveProfile(): void {
        $person = self::currentPersonOrFail();
        $request = SaveProfileRequest::fromGlobals();

        $first_name = $request->firstName();
        $last_name = $request->lastName();
        $display_name = $request->displayName();
        $mfa_method = $request->mfaMethod();
        $allow_name_edit = (int) \Core_Settings_Service::get( 'profile_allow_name_edit', 0 ) === 1;

        if ( ! in_array( $mfa_method, [ 'none', 'totp', 'passkey', 'passkey_or_totp', 'passkey_and_totp' ], true ) ) {
            $mfa_method = (string) ( $person['mfa_method'] ?? 'none' );
        }

        if ( ! $allow_name_edit ) {
            $first_name = (string) ( $person['first_name'] ?? '' );
            $last_name = (string) ( $person['last_name'] ?? '' );
            $display_name = (string) ( $person['display_name'] ?? '' );
        } elseif ( $display_name === '' ) {
            $display_name = trim( $first_name . ' ' . $last_name );
        }

        if ( $display_name === '' ) {
            \metis_runtime_send_json_error( 'Display name is required.', 400 );
        }

        $updated = PersonProfileService::updateSelfProfile( (int) $person['id'], [
            'first_name' => $first_name,
            'last_name' => $last_name,
            'display_name' => $display_name,
            'public_slug' => $request->publicSlug(),
            'public_tagline' => $request->publicTagline(),
            'public_visibility' => $request->publicVisibility(),
            'public_bio_html' => $request->publicBioHtml(),
            'email_notifications' => $request->emailNotifications(),
            'requires_2fa' => $request->requires2fa(),
            'mfa_method' => $mfa_method,
            'notification_prefs_json' => $request->notificationPrefsJson(),
        ] );

        self::logActivity( (int) $person['id'], 'profile_saved', 'Updated self profile settings', [] );

        \metis_runtime_send_json_success( [
            'person' => ProfileContextService::personPayload( $updated ?: $person ),
        ] );
    }

    public static function changeWorkspacePassword(): void {
        $person = self::currentPersonOrFail();
        $request = ChangeWorkspacePasswordRequest::fromGlobals();

        if ( strlen( $request->newPassword() ) < 12 ) {
            \metis_runtime_send_json_error( 'Password must be at least 12 characters.', 400 );
        }
        if ( ! hash_equals( $request->newPassword(), $request->confirmPassword() ) ) {
            \metis_runtime_send_json_error( 'Password confirmation does not match.', 400 );
        }

        $workspace_email = strtolower( trim( (string) ( $person['workspace_email'] ?? '' ) ) );
        if ( ! \metis_email_is_valid( $workspace_email ) && ! empty( $person['is_workspace_user'] ) ) {
            $workspace_email = strtolower( trim( (string) ( $person['email'] ?? '' ) ) );
        }
        if ( ! \metis_email_is_valid( $workspace_email ) ) {
            \metis_runtime_send_json_error( 'No linked Workspace account found for this profile.', 400 );
        }

        if ( ! function_exists( 'metis_people_workspace_sync_settings' ) || ! function_exists( 'metis_people_workspace_google_request' ) ) {
            \metis_runtime_send_json_error( 'Workspace integration is not available.', 500 );
        }

        $cfg = \metis_people_workspace_sync_settings();
        if ( empty( $cfg['ok'] ) ) {
            \metis_runtime_send_json_error( 'Workspace integration is not configured.', 500 );
        }

        $resp = \metis_people_workspace_google_request( 'PUT', 'users/' . rawurlencode( $workspace_email ), [
            'password' => $request->newPassword(),
            'changePasswordAtNextLogin' => false,
        ], $cfg );

        if ( empty( $resp['ok'] ) ) {
            \metis_runtime_send_json_error( 'Workspace password update failed.', 500 );
        }

        self::logActivity( (int) ( $person['id'] ?? 0 ), 'workspace_password_changed_self', 'Changed own Workspace password', [
            'workspace_email' => $workspace_email,
        ] );

        \metis_runtime_send_json_success( [ 'ok' => 1 ] );
    }

    public static function changePassword(): void {
        $person = self::currentPersonOrFail();
        $request = ChangePasswordRequest::fromGlobals();

        try {
            $person_id = (int) ( $person['id'] ?? 0 );
            $auth_user = function_exists( 'metis_auth_find_user' ) ? \metis_auth_find_user( 'person_id', $person_id ) : null;
            $has_password = function_exists( 'metis_auth_password_hash_for_authentication' )
                && is_array( $auth_user )
                && \metis_auth_password_hash_for_authentication( $auth_user, $person ) !== '';
            $auth_method = function_exists( 'metis_auth_current_method' ) ? \metis_auth_current_method() : '';
            $can_set_from_session = in_array( $auth_method, [ 'passkey', 'google_workspace', 'password_mfa' ], true );

            $result = ( $has_password && ! $can_set_from_session )
                ? \metis_auth_change_password_for_person(
                    $person_id,
                    $request->currentPassword(),
                    $request->newPassword(),
                    $request->confirmPassword()
                )
                : ( $has_password
                    ? \metis_auth_set_session_password_for_person(
                        $person_id,
                        $request->newPassword(),
                        $request->confirmPassword()
                    )
                    : \metis_auth_set_initial_password_for_person(
                        $person_id,
                        $request->newPassword(),
                        $request->confirmPassword()
                    ) );

            if ( function_exists( 'metis_auth_set_flash_notice' ) ) {
                \metis_auth_set_flash_notice( ( $has_password && ! $can_set_from_session ) ? 'Password updated. Please sign in again.' : 'Password set. Please sign in again.', 'success' );
            }

            if ( function_exists( 'metis_auth_logout' ) ) {
                \metis_auth_logout();
            }

            \metis_runtime_send_json_success( [
                'reauthenticate' => true,
                'redirect_url' => function_exists( 'metis_auth_login_url' ) ? \metis_auth_login_url() : '/',
                'user_id' => (int) ( $result['user']['id'] ?? 0 ),
                'created' => ! $has_password,
                'session_set' => $has_password && $can_set_from_session,
            ] );
        } catch ( \Throwable $throwable ) {
            if ( class_exists( 'Metis_Logger' ) ) {
                \Metis_Logger::warn( 'profile.password_change_failed', [
                    'error' => $throwable->getMessage(),
                ] );
            }
            \metis_runtime_send_json_error( 'Unable to change password right now.', 400 );
        }
    }

    public static function saveAvatar(): void {
        $person = self::currentPersonOrFail();
        $request = AvatarUploadRequest::fromGlobals();

        $decoded = \metis_avatar_decode_base64_payload( $request->avatarBase64() );
        if ( empty( $decoded['ok'] ) ) {
            \metis_runtime_send_json_error( 'Invalid image payload.', 400 );
        }

        $upload = \metis_avatar_store_cropped_image( (string) ( $person['pid'] ?? '' ), (string) ( $decoded['binary'] ?? '' ) );
        if ( empty( $upload['ok'] ) ) {
            \metis_runtime_send_json_error( 'Failed to store image.', 500 );
        }

        $avatar_url = isset( $upload['url'] ) ? \metis_url_clean( (string) $upload['url'] ) : '';
        if ( $avatar_url === '' ) {
            \metis_runtime_send_json_error( 'Image URL unavailable.', 500 );
        }

        PersonProfileService::updateAvatar( (int) $person['id'], $avatar_url );
        self::logActivity( (int) $person['id'], 'avatar_updated', 'Updated self profile photo', [] );

        \metis_runtime_send_json_success( [ 'avatar_url' => $avatar_url ] );
    }

    public static function generateTotpSecret(): void {
        $person = self::currentPersonOrFail();
        if ( ! function_exists( 'metis_people_totp_generate_secret' ) ) {
            \metis_runtime_send_json_error( 'TOTP service unavailable.', 500 );
        }

        $label = trim( (string) ( $person['display_name'] ?? '' ) );
        if ( $label === '' ) {
            $label = (string) ( $person['email'] ?? '' );
        }
        $issuer = 'Metis';
        $secret = \metis_people_totp_generate_secret( 32 );
        $uri = 'otpauth://totp/' . rawurlencode( $issuer . ':' . $label )
            . '?secret=' . rawurlencode( $secret )
            . '&issuer=' . rawurlencode( $issuer )
            . '&algorithm=SHA1&digits=6&period=30';

        \metis_runtime_send_json_success( [
            'secret' => $secret,
            'provisioning_uri' => $uri,
        ] );
    }

    public static function verifyTotpSecret(): void {
        $person = self::currentPersonOrFail();
        if ( ! function_exists( 'metis_people_totp_now' ) || ! function_exists( 'metis_people_encrypt_secret' ) ) {
            \metis_runtime_send_json_error( 'TOTP service unavailable.', 500 );
        }

        $request = VerifyTotpRequest::fromGlobals();
        if ( $request->secret() === '' || strlen( $request->code() ) !== 6 ) {
            \metis_runtime_send_json_error( 'Secret and 6-digit code are required.', 400 );
        }

        $valid = false;
        $now = time();
        for ( $i = -1; $i <= 1; $i++ ) {
            if ( hash_equals( \metis_people_totp_now( $request->secret(), 30, 6, $now + ( $i * 30 ) ), $request->code() ) ) {
                $valid = true;
                break;
            }
        }
        if ( ! $valid ) {
            \metis_runtime_send_json_error( 'Code is not valid for this secret.', 400 );
        }

        $enc = \metis_people_encrypt_secret( $request->secret() );
        if ( $enc === '' ) {
            \metis_runtime_send_json_error( 'Failed to secure secret.', 500 );
        }

        MfaService::storeTotpSecret( (int) $person['id'], $enc );
        self::logActivity( (int) $person['id'], 'totp_enabled', 'Enabled authenticator app MFA (self)', [] );

        \metis_runtime_send_json_success( [ 'ok' => 1 ] );
    }

    public static function beginPasskeyRegistration(): void {
        $person = self::currentPersonOrFail();
        if ( ! function_exists( 'metis_people_create_challenge' ) || ! function_exists( 'metis_people_b64url_encode' ) ) {
            \metis_runtime_send_json_error( 'Passkey service unavailable.', 500 );
        }

        $person_id = (int) $person['id'];
        $challenge = \metis_people_create_challenge( $person_id, 'passkey_register', 600 );
        $exclude_ids = MfaService::activePasskeyCredentialIds( $person_id );

        $exclude = [];
        foreach ( $exclude_ids as $credential_id ) {
            $exclude[] = [
                'id' => (string) $credential_id,
                'type' => 'public-key',
            ];
        }

        $display_name = trim( (string) ( $person['display_name'] ?? '' ) );
        if ( $display_name === '' ) {
            $display_name = (string) ( $person['email'] ?? '' );
        }

        $user_handle = \metis_people_b64url_encode( 'metis-person-' . $person_id );

        \metis_runtime_send_json_success( [
            'challenge_key' => (string) ( $challenge['challenge_key'] ?? '' ),
            'public_key' => [
                'rp' => [
                    'name' => 'Metis',
                    'id' => \metis_runtime_parse_url( \metis_home_url(), PHP_URL_HOST ),
                ],
                'user' => [
                    'id' => $user_handle,
                    'name' => (string) ( $person['email'] ?? '' ),
                    'displayName' => $display_name,
                ],
                'challenge' => (string) ( $challenge['challenge_value'] ?? '' ),
                'pubKeyCredParams' => [
                    [ 'type' => 'public-key', 'alg' => -7 ],
                    [ 'type' => 'public-key', 'alg' => -257 ],
                ],
                'timeout' => 60000,
                'attestation' => 'none',
                'excludeCredentials' => $exclude,
                'authenticatorSelection' => [
                    'residentKey' => 'preferred',
                    'userVerification' => 'preferred',
                ],
            ],
        ] );
    }

    public static function completePasskeyRegistration(): void {
        $person = self::currentPersonOrFail();
        if ( ! function_exists( 'metis_people_consume_challenge' ) || ! function_exists( 'metis_people_origin_allowed' ) ) {
            \metis_runtime_send_json_error( 'Passkey service unavailable.', 500 );
        }

        $request = CompletePasskeyRegistrationRequest::fromGlobals();
        if ( $request->challengeKey() === '' || $request->credentialId() === '' || $request->clientDataJson() === '' || $request->attestationObject() === '' ) {
            \metis_runtime_send_json_error( 'Missing registration payload.', 400 );
        }

        $person_id = (int) $person['id'];
        $challenge = \metis_people_consume_challenge( $request->challengeKey(), 'passkey_register', $person_id );
        if ( ! $challenge ) {
            \metis_runtime_send_json_error( 'Registration challenge expired or invalid.', 400 );
        }

        $client_data_json = $request->clientDataJson();
        if ( function_exists( 'metis_people_b64url_decode' ) ) {
            $client_data_json = \metis_people_b64url_decode( $client_data_json );
        } else {
            $client_data_json = base64_decode( strtr( $client_data_json, '-_', '+/' ), true ) ?: '';
        }

        if ( $client_data_json === '' ) {
            \metis_runtime_send_json_error( 'Invalid client data.', 400 );
        }

        $client_data = json_decode( $client_data_json, true );
        if ( ! is_array( $client_data ) ) {
            \metis_runtime_send_json_error( 'Malformed client data payload.', 400 );
        }

        $type = (string) ( $client_data['type'] ?? '' );
        $origin = (string) ( $client_data['origin'] ?? '' );
        $challenge_value = (string) ( $client_data['challenge'] ?? '' );
        if ( $type !== 'webauthn.create' ) {
            \metis_runtime_send_json_error( 'Unexpected WebAuthn response type.', 400 );
        }
        if ( ! \metis_people_origin_allowed( $origin ) ) {
            \metis_runtime_send_json_error( 'Passkey origin mismatch.', 400 );
        }
        if ( ! hash_equals( (string) ( $challenge['challenge_value'] ?? '' ), $challenge_value ) ) {
            \metis_runtime_send_json_error( 'Challenge mismatch.', 400 );
        }

        if ( MfaService::passkeyExistsByCredentialId( $request->credentialId() ) ) {
            \metis_runtime_send_json_error( 'Passkey already registered.', 400 );
        }

        $passkey = MfaService::registerPasskey(
            $person_id,
            $request->credentialId(),
            $request->attestationObject(),
            $request->transportsJson(),
            $request->label(),
            $person_id
        );

        self::logActivity( $person_id, 'passkey_registered', 'Registered passkey credential (self)', [
            'label' => $request->label() !== '' ? $request->label() : 'Passkey',
        ] );

        \metis_runtime_send_json_success( [
            'passkey' => $passkey,
        ] );
    }

    public static function revokePasskey(): void {
        $person = self::currentPersonOrFail();
        $request = RevokePasskeyRequest::fromGlobals();
        if ( $request->passkeyId() < 1 ) {
            \metis_runtime_send_json_error( 'Invalid passkey id.', 400 );
        }

        $row = MfaService::getPasskeyById( $request->passkeyId() );
        if ( ! $row || (int) ( $row['person_id'] ?? 0 ) !== (int) $person['id'] ) {
            \metis_runtime_send_json_error( 'Passkey not found.', 404 );
        }
        if ( ! empty( $row['revoked_at'] ) ) {
            \metis_runtime_send_json_error( 'Passkey already revoked.', 400 );
        }

        MfaService::revokePasskey( $request->passkeyId() );
        $active_count = MfaService::activePasskeyCount( (int) $person['id'] );
        if ( $active_count < 1 ) {
            MfaService::disablePasskeyFlag( (int) $person['id'] );
        }

        self::logActivity( (int) $person['id'], 'passkey_revoked', 'Revoked passkey credential (self)', [
            'label' => (string) ( $row['label'] ?? '' ),
        ] );

        \metis_runtime_send_json_success( [ 'active_count' => $active_count ] );
    }

    private static function currentPersonOrFail(): array {
        ProfileContextService::ensureReady();
        $person = ProfileContextService::currentPerson();
        if ( ! $person ) {
            \metis_runtime_send_json_error( 'Profile not found.', 404 );
        }

        return $person;
    }

    private static function logActivity( int $person_id, string $event, string $message, array $context ): void {
        if ( function_exists( 'metis_people_log_activity' ) ) {
            \metis_people_log_activity( $person_id, $event, $message, $context );
        }
    }
}
