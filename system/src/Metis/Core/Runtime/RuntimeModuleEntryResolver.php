<?php
declare(strict_types=1);

namespace Metis\Core\Runtime;

use Metis\Core\Application;

final class RuntimeModuleEntryResolver {
    /**
     * @return class-string|null
     */
    public static function resolve( string $slug ): ?string {
        $slug = \metis_key_clean( $slug );
        if ( $slug === '' ) {
            return null;
        }

        if ( Application::has_service( 'modules' ) ) {
            $module = Application::service( 'modules' )->get( $slug );
            $moduleClass = is_array( $module ) ? (string) ( $module['config']['_module_class'] ?? '' ) : '';
            if ( $moduleClass !== '' && class_exists( $moduleClass ) ) {
                return $moduleClass;
            }
        }

        $fallback = self::fallbackClass( $slug );
        return $fallback !== '' && class_exists( $fallback ) ? $fallback : null;
    }

    public static function callStatic( string $slug, string $method, mixed ...$arguments ): mixed {
        $moduleClass = self::resolve( $slug );
        if ( $moduleClass === null || ! method_exists( $moduleClass, $method ) ) {
            throw new \RuntimeException(
                sprintf( 'Runtime module entry for [%s] does not expose [%s].', $slug, $method )
            );
        }

        return $moduleClass::$method( ...$arguments );
    }

    public static function supportsStatic( string $slug, string $method ): bool {
        $moduleClass = self::resolve( $slug );
        return $moduleClass !== null && method_exists( $moduleClass, $method );
    }

    /**
     * @return class-string|string
     */
    private static function fallbackClass( string $slug ): string {
        $moduleNames = [
            'board' => 'Board',
            'calendar' => 'Calendar',
            'communications_inbound' => 'CommunicationsInbound',
            'contacts' => 'Contacts',
            'donations' => 'Donations',
            'drive' => 'Drive',
            'finance' => 'Finance',
            'forms' => 'Forms',
            'grandys_stash' => 'GrandyStash',
            'help' => 'Help',
            'hermes' => 'Hermes',
            'import' => 'Import',
            'media' => 'Media',
            'modules' => 'Modules',
            'newsletter' => 'Newsletter',
            'people' => 'People',
            'portal' => 'Portal',
            'profile' => 'Profile',
            'resources' => 'Resources',
            'settings' => 'Settings',
            'testimonies' => 'Testimonies',
            'website' => 'Website',
        ];

        $moduleName = (string) ( $moduleNames[ $slug ] ?? '' );
        if ( $moduleName === '' ) {
            return '';
        }

        return implode( '\\', [ 'Metis', 'Modules', $moduleName, $moduleName . 'Module' ] );
    }
}
