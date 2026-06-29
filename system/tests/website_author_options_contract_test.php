<?php
declare(strict_types=1);

namespace Metis\Modules\Donations {
    final class CampaignService {
        public static function getActiveCampaignOptions( int $limit = 200 ): array { return []; }
        public static function getActiveCampaigns( int $limit = 200 ): array { return []; }
    }
}

namespace Metis\Modules\Media {
    final class MediaLibraryService {
        public static function listItems( string $search = '', string $folder = '', string $mime = '', string $visibility = '', string $sort = 'created_desc', int $limit = 200 ): array {
            return [];
        }
    }
}

namespace Metis\Modules\People {
    final class PersonProfileService {
        public static function getById( int $person_id ): ?array {
            return match ( $person_id ) {
                14 => [
                    'id' => 14,
                    'pid' => 'PPL-014',
                    'email' => 'casey@example.test',
                    'first_name' => 'Casey',
                    'last_name' => 'Rivera',
                    'display_name' => 'Casey Rivera',
                    'status' => 'active',
                ],
                default => null,
            };
        }
    }
}

namespace {
    if ( PHP_SAPI !== 'cli' ) {
        fwrite( STDERR, "This test must be run from the command line.\n" );
        exit( 1 );
    }

    function metis_key_clean( string $value ): string {
        $value = strtolower( trim( $value ) );
        return preg_replace( '/[^a-z0-9_]+/', '_', $value ) ?? '';
    }

    final class Metis_Tables {
        public static function has( string $key ): bool {
            return in_array( $key, [ 'people', 'people_roles', 'people_user_roles', 'auth_users' ], true );
        }

        public static function get( string $key ): string {
            return match ( $key ) {
                'people' => 'metis_people',
                'people_roles' => 'metis_people_roles',
                'people_user_roles' => 'metis_people_user_roles',
                'auth_users' => 'metis_auth_users',
                default => '',
            };
        }
    }

    final class WebsiteAuthorOptionsTestDb {
        public function fetchAll( string $sql, array $params = [] ): array {
            if ( str_contains( $sql, 'SELECT p.id, p.pid' ) && str_contains( $sql, 'FROM metis_people p' ) ) {
                return [
                    [
                        'id' => 14,
                        'pid' => 'PPL-014',
                        'auth_provider' => 'metis',
                        'email' => 'casey@example.test',
                        'first_name' => 'Casey',
                        'last_name' => 'Rivera',
                        'display_name' => 'Casey Rivera',
                        'linked_donor_id' => '',
                        'is_workspace_user' => 1,
                        'workspace_email' => 'casey@example.test',
                        'workspace_role' => '',
                        'stripe_role' => '',
                        'status' => 'active',
                        'lifecycle_status' => 'active',
                        'public_visibility' => 'all',
                        'is_staff' => 1,
                        'is_board' => 0,
                        'is_volunteer' => 0,
                        'staff_position' => 'Editor',
                        'board_position' => '',
                        'volunteer_position' => '',
                        'auth_user_id' => 7,
                    ],
                    [
                        'id' => 18,
                        'pid' => 'PPL-018',
                        'auth_provider' => 'metis',
                        'email' => 'jamie@example.test',
                        'first_name' => 'Jamie',
                        'last_name' => 'Writer',
                        'display_name' => '',
                        'linked_donor_id' => '',
                        'is_workspace_user' => 0,
                        'workspace_email' => '',
                        'workspace_role' => '',
                        'stripe_role' => '',
                        'status' => 'active',
                        'lifecycle_status' => 'active',
                        'public_visibility' => 'private',
                        'is_staff' => 0,
                        'is_board' => 0,
                        'is_volunteer' => 1,
                        'staff_position' => '',
                        'board_position' => '',
                        'volunteer_position' => 'Writer',
                        'auth_user_id' => 0,
                    ],
                ];
            }

            if ( str_contains( $sql, 'FROM metis_people_roles' ) && str_contains( $sql, "role_domain = 'metis'" ) ) {
                return [
                    [ 'id' => 3, 'role_key' => 'administrator', 'role_name' => 'Administrator', 'role_domain' => 'metis' ],
                ];
            }

            if ( str_contains( $sql, 'FROM metis_people_user_roles ur' ) ) {
                return [
                    [ 'person_id' => 14, 'role_key' => 'administrator' ],
                ];
            }

            return [];
        }

        public function scalar( string $sql, array $params = [] ): string|int|null {
            if ( str_contains( $sql, 'COUNT(*) FROM metis_people p' ) ) {
                return 2;
            }
            return null;
        }

        public function prefix(): string {
            return '';
        }
    }

    function metis_db(): WebsiteAuthorOptionsTestDb {
        static $db = null;
        if ( $db instanceof WebsiteAuthorOptionsTestDb ) {
            return $db;
        }
        $db = new WebsiteAuthorOptionsTestDb();
        return $db;
    }

    $root = dirname( __DIR__ );
    require_once __DIR__ . '/_support/module_path_resolver.php';
    $resolve_relative = static fn ( string $relative ): string => metis_test_resolve_relative( $root, $relative );
    require_once dirname( __DIR__ ) . '/src/Metis/Core/BuiltInServices/people/PeopleDirectoryService.php';
    require_once $resolve_relative( 'modules/website/Services/EditorOptionsService.php' );

    $failures = [];
    $assert = static function ( bool $condition, string $message ) use ( &$failures ): void {
        if ( ! $condition ) {
            $failures[] = $message;
        }
    };

    $options = \Metis\Modules\Website\Services\EditorOptionsService::authorOptions();

    $assert( count( $options ) === 2, 'Website author options should return active People records.' );
    $assert( ( $options[0]['value'] ?? '' ) === '14', 'Website author options should use person ids as select values.' );
    $assert( ( $options[0]['label'] ?? '' ) === 'Casey Rivera', 'Website author options should label rows with People names.' );
    $assert( ( $options[1]['value'] ?? '' ) === '18', 'Website author options should include people even when no auth user exists yet.' );
    $assert( ( $options[1]['label'] ?? '' ) === 'Jamie Writer', 'Website author options should preserve the People directory label.' );

    if ( $failures !== [] ) {
        fwrite( STDERR, implode( PHP_EOL, $failures ) . PHP_EOL );
        exit( 1 );
    }

    fwrite( STDOUT, "Website author options contract checks passed.\n" );
}
