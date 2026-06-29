<?php
declare(strict_types=1);

namespace Metis\Modules\People {
    final class PersonProfileService {
        public static function getById( int $person_id ): ?array {
            return match ( $person_id ) {
                18 => [
                    'id' => 18,
                    'pid' => 'PPL-018',
                    'email' => 'jamie@example.test',
                    'first_name' => 'Jamie',
                    'last_name' => 'Writer',
                    'display_name' => '',
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

    final class PeopleDirectoryServiceTestDb {
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
    }

    function metis_db(): PeopleDirectoryServiceTestDb {
        static $db = null;
        if ( $db instanceof PeopleDirectoryServiceTestDb ) {
            return $db;
        }
        $db = new PeopleDirectoryServiceTestDb();
        return $db;
    }

    function metis_auth_find_user( string $field, string|int $value ): ?array {
        if ( $field === 'id' && (int) $value === 7 ) {
            return [ 'id' => 7, 'person_id' => 14, 'user_email' => 'casey@example.test' ];
        }
        if ( $field === 'person_id' && (int) $value === 14 ) {
            return [ 'id' => 7, 'person_id' => 14, 'user_email' => 'casey@example.test' ];
        }
        return null;
    }

    function metis_auth_upsert_user_from_person( array $person, ?array $auth_user = null, string $password_hash = '' ): array {
        return [ 'id' => 118, 'person_id' => (int) ( $person['id'] ?? 0 ), 'user_email' => (string) ( $person['email'] ?? '' ) ];
    }

    require_once dirname( __DIR__ ) . '/src/Metis/Core/BuiltInServices/people/PeopleDirectoryService.php';

    $failures = [];
    $assert = static function ( bool $condition, string $message ) use ( &$failures ): void {
        if ( ! $condition ) {
            $failures[] = $message;
        }
    };

    $snapshot = \Metis\Modules\People\PeopleDirectoryService::find([ 'page' => 1, 'per_page' => 50 ]);
    $idOptions = \Metis\Modules\People\PeopleDirectoryService::options([ 'value_field' => 'id', 'limit' => 50 ]);
    $authOptions = \Metis\Modules\People\PeopleDirectoryService::options([ 'value_field' => 'auth_user_id', 'ensure_auth_user' => true, 'limit' => 50 ]);
    $resolvedPerson = \Metis\Modules\People\PeopleDirectoryService::resolvePersonByAuthUserId( 7 );
    $resolvedAuthId = \Metis\Modules\People\PeopleDirectoryService::resolveAuthUserIdForPerson( 18 );

    $assert( (int) ( $snapshot['total_people'] ?? 0 ) === 2, 'People directory service should paginate People records.' );
    $assert( ( $idOptions[0]['value'] ?? '' ) === '14' && ( $idOptions[1]['value'] ?? '' ) === '18', 'People directory options should support person-id values.' );
    $assert( ( $authOptions[0]['value'] ?? '' ) === '7' && ( $authOptions[1]['value'] ?? '' ) === '118', 'People directory options should resolve auth ids when requested.' );
    $assert( is_array( $resolvedPerson ) && (int) ( $resolvedPerson['id'] ?? 0 ) === 14, 'People directory service should resolve a person from an auth user id.' );
    $assert( $resolvedAuthId === 118, 'People directory service should provision an auth user id for a person when needed.' );

    if ( $failures !== [] ) {
        fwrite( STDERR, implode( PHP_EOL, $failures ) . PHP_EOL );
        exit( 1 );
    }

    fwrite( STDOUT, "People directory service contract checks passed.\n" );
}
