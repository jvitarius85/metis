<?php
declare(strict_types=1);

namespace Metis\Modules\Profile\Requests;

final class SaveProfileRequest {
    private function __construct(
        private readonly string $firstName,
        private readonly string $lastName,
        private readonly string $displayName,
        private readonly int $emailNotifications,
        private readonly int $requires2fa,
        private readonly string $mfaMethod,
        private readonly string $publicSlug,
        private readonly string $publicTagline,
        private readonly string $publicVisibility,
        private readonly string $publicBioHtml,
        private readonly ?string $notificationPrefsJson
    ) {}

    public static function fromGlobals(): self {
        return new self(
            isset( \metis_request_post()['first_name'] ) ? \metis_text_clean( \metis_runtime_unslash( \metis_request_post()['first_name'] ) ) : '',
            isset( \metis_request_post()['last_name'] ) ? \metis_text_clean( \metis_runtime_unslash( \metis_request_post()['last_name'] ) ) : '',
            isset( \metis_request_post()['display_name'] ) ? \metis_text_clean( \metis_runtime_unslash( \metis_request_post()['display_name'] ) ) : '',
            ! empty( \metis_request_post()['email_notifications'] ) ? 1 : 0,
            ! empty( \metis_request_post()['requires_2fa'] ) ? 1 : 0,
            isset( \metis_request_post()['mfa_method'] ) ? \metis_key_clean( \metis_runtime_unslash( \metis_request_post()['mfa_method'] ) ) : 'none',
            isset( \metis_request_post()['public_slug'] ) ? \metis_text_clean( \metis_runtime_unslash( \metis_request_post()['public_slug'] ) ) : '',
            isset( \metis_request_post()['public_tagline'] ) ? \metis_text_clean( \metis_runtime_unslash( \metis_request_post()['public_tagline'] ) ) : '',
            isset( \metis_request_post()['public_visibility'] ) ? \metis_key_clean( \metis_runtime_unslash( \metis_request_post()['public_visibility'] ) ) : 'private',
            isset( \metis_request_post()['public_bio_html'] ) ? (string) \metis_runtime_unslash( \metis_request_post()['public_bio_html'] ) : '',
            self::normalizeNotificationPrefsJson()
        );
    }

    public function firstName(): string {
        return $this->firstName;
    }

    public function lastName(): string {
        return $this->lastName;
    }

    public function displayName(): string {
        return $this->displayName;
    }

    public function emailNotifications(): int {
        return $this->emailNotifications;
    }

    public function requires2fa(): int {
        return $this->requires2fa;
    }

    public function mfaMethod(): string {
        return $this->mfaMethod;
    }

    public function publicSlug(): string {
        return $this->publicSlug;
    }

    public function publicTagline(): string {
        return $this->publicTagline;
    }

    public function publicVisibility(): string {
        return $this->publicVisibility;
    }

    public function publicBioHtml(): string {
        return $this->publicBioHtml;
    }

    public function notificationPrefsJson(): ?string {
        return $this->notificationPrefsJson;
    }

    private static function normalizeNotificationPrefsJson(): ?string {
        if ( ! isset( \metis_request_post()['notification_prefs_json'] ) ) {
            return null;
        }

        $decoded_notify = json_decode( (string) \metis_runtime_unslash( \metis_request_post()['notification_prefs_json'] ), true );
        if ( ! is_array( $decoded_notify ) ) {
            return null;
        }

        $clean_notify = [];
        foreach ( $decoded_notify as $event_key => $channels ) {
            $ek = \metis_key_clean( (string) $event_key );
            if ( $ek === '' || ! is_array( $channels ) ) {
                continue;
            }
            $clean_notify[ $ek ] = [
                'email' => ! empty( $channels['email'] ),
                'in_app' => ! empty( $channels['in_app'] ),
            ];
        }

        return \metis_json_encode( $clean_notify );
    }
}
