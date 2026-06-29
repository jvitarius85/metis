<?php
declare(strict_types=1);

namespace Metis\Modules\People;

final class PeopleDirectoryService {
    /**
     * @param array<string,mixed> $filters
     * @return array<string,mixed>
     */
    public static function find( array $filters = [] ): array {
        $db = \metis_db();
        $people_table = \Metis_Tables::get( 'people' );
        $roles_table = \Metis_Tables::get( 'people_roles' );
        $user_roles_table = \Metis_Tables::get( 'people_user_roles' );
        $auth_users_table = \Metis_Tables::get( 'auth_users' );

        $page = max( 1, (int) ( $filters['page'] ?? 1 ) );
        $per_page = max( 1, min( 500, (int) ( $filters['per_page'] ?? 100 ) ) );
        $offset = max( 0, ( $page - 1 ) * $per_page );

        $where = [ '1=1' ];
        $params = [];

        $person_ids = self::normalizeIntList( $filters['person_ids'] ?? $filters['ids'] ?? [] );
        if ( $person_ids !== [] ) {
            $where[] = 'p.id IN (' . implode( ',', $person_ids ) . ')';
        }

        $role_keys = self::normalizeStringList( $filters['role_keys'] ?? $filters['roles'] ?? [] );
        if ( $role_keys !== [] ) {
            $where[] = 'EXISTS (
                SELECT 1
                FROM ' . $user_roles_table . ' ur
                INNER JOIN ' . $roles_table . ' r ON r.id = ur.role_id
                WHERE ur.person_id = p.id
                  AND r.role_key IN (' . self::placeholders( count( $role_keys ) ) . ')
            )';
            array_push( $params, ...$role_keys );
        }

        $position_terms = self::normalizeStringList( $filters['positions'] ?? ( isset( $filters['position'] ) ? [ $filters['position'] ] : [] ) );
        if ( $position_terms !== [] ) {
            $position_where = [];
            foreach ( $position_terms as $term ) {
                $position_where[] = '(LOWER(COALESCE(p.staff_position, \'\')) = LOWER(%s) OR LOWER(COALESCE(p.board_position, \'\')) = LOWER(%s) OR LOWER(COALESCE(p.volunteer_position, \'\')) = LOWER(%s))';
                $params[] = $term;
                $params[] = $term;
                $params[] = $term;
            }
            $where[] = '(' . implode( ' OR ', $position_where ) . ')';
        }

        if ( array_key_exists( 'status', $filters ) ) {
            $status = trim( (string) $filters['status'] );
            if ( $status !== '' ) {
                $where[] = 'p.status = %s';
                $params[] = $status;
            }
        }

        if ( array_key_exists( 'public_visibility', $filters ) ) {
            $visibility = trim( (string) $filters['public_visibility'] );
            if ( $visibility !== '' ) {
                $where[] = 'p.public_visibility = %s';
                $params[] = $visibility;
            }
        }

        foreach ( [ 'is_workspace_user', 'is_staff', 'is_board', 'is_volunteer' ] as $flag ) {
            if ( array_key_exists( $flag, $filters ) ) {
                $where[] = 'p.' . $flag . ' = %d';
                $params[] = ! empty( $filters[ $flag ] ) ? 1 : 0;
            }
        }

        if ( array_key_exists( 'has_auth_user', $filters ) ) {
            $where[] = ! empty( $filters['has_auth_user'] ) ? 'au.auth_user_id IS NOT NULL' : 'au.auth_user_id IS NULL';
        }

        $search = trim( (string) ( $filters['search'] ?? '' ) );
        if ( $search !== '' ) {
            $where[] = '(p.pid LIKE %s OR p.email LIKE %s OR p.workspace_email LIKE %s OR p.display_name LIKE %s OR p.first_name LIKE %s OR p.last_name LIKE %s OR p.linked_donor_id LIKE %s)';
            $needle = '%' . $search . '%';
            $params[] = $needle;
            $params[] = $needle;
            $params[] = $needle;
            $params[] = $needle;
            $params[] = $needle;
            $params[] = $needle;
            $params[] = $needle;
        }

        $where_sql = implode( ' AND ', $where );
        $auth_join = 'LEFT JOIN (
            SELECT person_id, MIN(id) AS auth_user_id
            FROM ' . $auth_users_table . '
            WHERE is_active = 1
            GROUP BY person_id
        ) au ON au.person_id = p.id';

        $total_people = (int) $db->scalar(
            'SELECT COUNT(*) FROM ' . $people_table . ' p ' . $auth_join . ' WHERE ' . $where_sql,
            $params
        );
        $total_pages = max( 1, (int) ceil( $total_people / $per_page ) );
        if ( $page > $total_pages ) {
            $page = $total_pages;
            $offset = max( 0, ( $page - 1 ) * $per_page );
        }

        $people_rows = $db->fetchAll(
            'SELECT p.id, p.pid, p.auth_provider, p.email, p.first_name, p.last_name, p.display_name, p.linked_donor_id,
                    p.is_workspace_user, p.workspace_email, p.workspace_role, p.stripe_role, p.status, p.lifecycle_status,
                    p.public_visibility, p.is_staff, p.is_board, p.is_volunteer, p.staff_position, p.board_position, p.volunteer_position,
                    au.auth_user_id
             FROM ' . $people_table . ' p
             ' . $auth_join . '
             WHERE ' . $where_sql . '
             ' . self::sortSql( (string) ( $filters['sort'] ?? 'display_name_asc' ) ) . '
             LIMIT %d OFFSET %d',
            array_merge( $params, [ $per_page, $offset ] )
        ) ?: [];

        $role_by_key = self::roleMap();
        $role_keys_by_person = self::roleKeysByPerson( self::normalizeIntList( array_column( $people_rows, 'id' ) ) );

        $people = [];
        foreach ( $people_rows as $person_row ) {
            if ( ! is_array( $person_row ) ) {
                continue;
            }
            $person_id = (int) ( $person_row['id'] ?? 0 );
            if ( $person_id < 1 ) {
                continue;
            }
            $people[] = self::personPayload( $person_row, $role_keys_by_person[ $person_id ] ?? [] );
        }

        return [
            'page' => $page,
            'per_page' => $per_page,
            'total_people' => $total_people,
            'total_pages' => $total_pages,
            'people' => $people,
            'role_by_key' => $role_by_key,
        ];
    }

    /**
     * @param array<string,mixed> $filters
     * @return array<int,array{value:string,label:string}>
     */
    public static function options( array $filters = [] ): array {
        $limit = max( 1, min( 500, (int) ( $filters['limit'] ?? $filters['per_page'] ?? 250 ) ) );
        $snapshot = self::find( array_merge( $filters, [ 'page' => 1, 'per_page' => $limit ] ) );
        $rows = is_array( $snapshot['people'] ?? null ) ? $snapshot['people'] : [];
        $value_field = trim( (string) ( $filters['value_field'] ?? 'auth_user_id' ) );
        if ( ! in_array( $value_field, [ 'auth_user_id', 'id', 'pid', 'email' ], true ) ) {
            $value_field = 'auth_user_id';
        }

        $options = [];
        $seen = [];
        foreach ( $rows as $row ) {
            if ( ! is_array( $row ) ) {
                continue;
            }
            $person_id = (int) ( $row['id'] ?? 0 );
            if ( $person_id < 1 ) {
                continue;
            }
            $auth_user_id = (int) ( $row['auth_user_id'] ?? 0 );
            if ( $value_field === 'auth_user_id' && $auth_user_id < 1 && ! empty( $filters['ensure_auth_user'] ) ) {
                $auth_user_id = self::resolveAuthUserIdForPerson( $person_id );
            }
            $value = self::optionValueForField( $row, $value_field, $auth_user_id );
            if ( $value === '' || isset( $seen[ $value ] ) ) {
                continue;
            }
            $label = self::personLabel( $row );
            if ( $label === '' ) {
                continue;
            }
            $seen[ $value ] = true;
            $options[] = [
                'value' => $value,
                'label' => $label,
            ];
        }

        return $options;
    }

    public static function resolveAuthUserIdForPerson( int $person_id ): int {
        if ( $person_id < 1 || ! function_exists( 'metis_auth_find_user' ) ) {
            return 0;
        }

        $auth_user = \metis_auth_find_user( 'person_id', $person_id );
        if ( is_array( $auth_user ) ) {
            return (int) ( $auth_user['id'] ?? 0 );
        }

        $person = PersonProfileService::getById( $person_id );
        if ( ! is_array( $person ) ) {
            return 0;
        }

        $email = strtolower( trim( (string) ( $person['email'] ?? '' ) ) );
        if ( $email !== '' ) {
            $auth_user = \metis_auth_find_user( 'email', $email );
            if ( is_array( $auth_user ) ) {
                return (int) ( $auth_user['id'] ?? 0 );
            }
        }

        if ( function_exists( 'metis_auth_upsert_user_from_person' ) ) {
            $created = \metis_auth_upsert_user_from_person( $person );
            if ( is_array( $created ) ) {
                return (int) ( $created['id'] ?? 0 );
            }
        }

        return 0;
    }

    /**
     * @return array<string,mixed>|null
     */
    public static function resolvePersonByAuthUserId( int $auth_user_id ): ?array {
        if ( $auth_user_id < 1 || ! function_exists( 'metis_auth_find_user' ) ) {
            return null;
        }

        $auth_user = \metis_auth_find_user( 'id', $auth_user_id );
        if ( ! is_array( $auth_user ) ) {
            return null;
        }

        $person_id = (int) ( $auth_user['person_id'] ?? 0 );
        if ( $person_id > 0 ) {
            $person = PersonProfileService::getById( $person_id );
            if ( is_array( $person ) ) {
                return $person;
            }
        }

        $email = strtolower( trim( (string) ( $auth_user['user_email'] ?? '' ) ) );
        if ( $email === '' ) {
            return null;
        }

        $snapshot = self::find( [
            'search' => $email,
            'per_page' => 10,
        ] );
        foreach ( (array) ( $snapshot['people'] ?? [] ) as $person ) {
            if ( ! is_array( $person ) ) {
                continue;
            }
            if ( strtolower( trim( (string) ( $person['email'] ?? '' ) ) ) === $email ) {
                return $person;
            }
        }

        return null;
    }

    /**
     * @param array<string,mixed> $row
     * @param array<int,string> $role_keys
     * @return array<string,mixed>
     */
    private static function personPayload( array $row, array $role_keys ): array {
        return [
            'id' => (int) ( $row['id'] ?? 0 ),
            'pid' => (string) ( $row['pid'] ?? '' ),
            'auth_provider' => (string) ( $row['auth_provider'] ?? 'metis' ),
            'email' => (string) ( $row['email'] ?? '' ),
            'first_name' => (string) ( $row['first_name'] ?? '' ),
            'last_name' => (string) ( $row['last_name'] ?? '' ),
            'display_name' => (string) ( $row['display_name'] ?? '' ),
            'full_name' => self::personLabel( $row ),
            'linked_donor_id' => (string) ( $row['linked_donor_id'] ?? '' ),
            'is_workspace_user' => ! empty( $row['is_workspace_user'] ) ? 1 : 0,
            'workspace_email' => (string) ( $row['workspace_email'] ?? '' ),
            'workspace_role' => (string) ( $row['workspace_role'] ?? '' ),
            'stripe_role' => (string) ( $row['stripe_role'] ?? '' ),
            'status' => (string) ( $row['status'] ?? '' ),
            'lifecycle_status' => (string) ( $row['lifecycle_status'] ?? '' ),
            'public_visibility' => (string) ( $row['public_visibility'] ?? '' ),
            'is_staff' => ! empty( $row['is_staff'] ) ? 1 : 0,
            'is_board' => ! empty( $row['is_board'] ) ? 1 : 0,
            'is_volunteer' => ! empty( $row['is_volunteer'] ) ? 1 : 0,
            'staff_position' => (string) ( $row['staff_position'] ?? '' ),
            'board_position' => (string) ( $row['board_position'] ?? '' ),
            'volunteer_position' => (string) ( $row['volunteer_position'] ?? '' ),
            'auth_user_id' => (int) ( $row['auth_user_id'] ?? 0 ),
            'roles' => array_values( array_unique( array_filter( array_map( 'strval', $role_keys ) ) ) ),
        ];
    }

    /**
     * @return array<string,array<string,mixed>>
     */
    private static function roleMap(): array {
        $db = \metis_db();
        $roles_table = \Metis_Tables::get( 'people_roles' );
        $roles_rows = $db->fetchAll( "SELECT * FROM {$roles_table} WHERE role_domain = 'metis' ORDER BY role_name ASC" ) ?: [];

        $role_by_key = [];
        foreach ( $roles_rows as $role_row ) {
            if ( ! is_array( $role_row ) ) {
                continue;
            }
            $key = (string) ( $role_row['role_key'] ?? '' );
            if ( $key !== '' ) {
                $role_by_key[ $key ] = $role_row;
            }
        }

        return $role_by_key;
    }

    /**
     * @param array<int,int> $person_ids
     * @return array<int,array<int,string>>
     */
    private static function roleKeysByPerson( array $person_ids ): array {
        if ( $person_ids === [] ) {
            return [];
        }

        $db = \metis_db();
        $roles_table = \Metis_Tables::get( 'people_roles' );
        $user_roles_table = \Metis_Tables::get( 'people_user_roles' );
        $rows = $db->fetchAll(
            'SELECT ur.person_id, r.role_key
             FROM ' . $user_roles_table . ' ur
             INNER JOIN ' . $roles_table . ' r ON r.id = ur.role_id
             WHERE ur.person_id IN (' . implode( ',', $person_ids ) . ')',
        ) ?: [];

        $role_keys_by_person = [];
        foreach ( $rows as $row ) {
            if ( ! is_array( $row ) ) {
                continue;
            }
            $person_id = (int) ( $row['person_id'] ?? 0 );
            $role_key = (string) ( $row['role_key'] ?? '' );
            if ( $person_id < 1 || $role_key === '' ) {
                continue;
            }
            if ( ! isset( $role_keys_by_person[ $person_id ] ) ) {
                $role_keys_by_person[ $person_id ] = [];
            }
            $role_keys_by_person[ $person_id ][] = $role_key;
        }

        return $role_keys_by_person;
    }

    /**
     * @param array<string,mixed> $row
     */
    private static function personLabel( array $row ): string {
        $full_name = trim( (string) ( $row['first_name'] ?? '' ) . ' ' . (string) ( $row['last_name'] ?? '' ) );
        if ( $full_name !== '' ) {
            return $full_name;
        }

        $display_name = trim( (string) ( $row['display_name'] ?? '' ) );
        if ( $display_name !== '' ) {
            return $display_name;
        }

        return trim( (string) ( $row['email'] ?? '' ) );
    }

    /**
     * @param array<int|string,mixed> $values
     * @return array<int,int>
     */
    private static function normalizeIntList( array $values ): array {
        return array_values(
            array_unique(
                array_filter(
                    array_map( 'intval', $values ),
                    static fn ( int $value ): bool => $value > 0
                )
            )
        );
    }

    /**
     * @param array<int|string,mixed> $values
     * @return array<int,string>
     */
    private static function normalizeStringList( array $values ): array {
        return array_values(
            array_unique(
                array_filter(
                    array_map(
                        static fn ( mixed $value ): string => trim( (string) $value ),
                        $values
                    ),
                    static fn ( string $value ): bool => $value !== ''
                )
            )
        );
    }

    private static function placeholders( int $count ): string {
        return implode( ',', array_fill( 0, max( 1, $count ), '%s' ) );
    }

    private static function sortSql( string $sort ): string {
        return match ( trim( $sort ) ) {
            'email_asc' => 'ORDER BY p.email ASC, p.display_name ASC',
            'name_desc' => 'ORDER BY p.display_name DESC, p.email DESC',
            default => 'ORDER BY p.display_name ASC, p.email ASC',
        };
    }

    /**
     * @param array<string,mixed> $row
     */
    private static function optionValueForField( array $row, string $value_field, int $auth_user_id ): string {
        return match ( $value_field ) {
            'id' => (int) ( $row['id'] ?? 0 ) > 0 ? (string) (int) $row['id'] : '',
            'pid' => trim( (string) ( $row['pid'] ?? '' ) ),
            'email' => trim( (string) ( $row['email'] ?? '' ) ),
            default => $auth_user_id > 0 ? (string) $auth_user_id : '',
        };
    }
}
