<?php
declare(strict_types=1);

namespace Metis\Release;

/**
 * Stores release progress in runtime storage so the web request and the
 * code-owner CLI worker can communicate without granting PHP-FPM write access
 * to the deployable application tree.
 */
final class ReleaseProgress {
    public static function createToken(): string {
        return bin2hex( random_bytes( 16 ) );
    }

    public static function normalizeToken( string $token ): string {
        return preg_match( '/^[A-Za-z0-9_-]{16,64}$/', $token ) === 1 ? $token : '';
    }

    public static function write( string $token, array $payload ): void {
        $token = self::normalizeToken( $token );
        if ( $token === '' ) {
            return;
        }

        $directory = rtrim( (string) \METIS_PATH, '/\\' ) . '/storage/runtime/release/progress';
        if ( ! is_dir( $directory ) && ! mkdir( $directory, 0775, true ) && ! is_dir( $directory ) ) {
            throw new \RuntimeException( 'Unable to create the release progress directory.' );
        }

        $payload['token'] = $token;
        $payload['updated_at'] = (string) ( $payload['updated_at'] ?? \metis_current_time( 'mysql' ) );
        $encoded = json_encode( $payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES );
        if ( ! is_string( $encoded ) || file_put_contents( $directory . '/' . $token . '.json', $encoded, LOCK_EX ) === false ) {
            throw new \RuntimeException( 'Unable to write release progress.' );
        }
    }
}
