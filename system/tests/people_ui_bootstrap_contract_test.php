<?php
declare(strict_types=1);

if ( PHP_SAPI !== 'cli' ) {
    fwrite( STDERR, "This test must be run from the command line.\n" );
    exit( 1 );
}

$root = dirname( __DIR__ );
$shell_source = (string) file_get_contents( $root . '/src/Metis/Core/BuiltInServices/people/assets/js/profile-shell.js' );

$failures = [];
$assert = static function ( bool $condition, string $message ) use ( &$failures ): void {
    if ( ! $condition ) {
        $failures[] = $message;
    }
};

$assert(
    str_contains( $shell_source, '.metis-people, .metis-people-ops, .metis-people-dashboard' ),
    'People shell must initialize on list, bulk actions, and dashboard views.'
);
$assert(
    str_contains( $shell_source, "document.getElementById('metis-people-add-form')" )
        && str_contains( $shell_source, "post('metis_people_save_person'")
        && str_contains( $shell_source, "showAlert('Person created.', 'success');" ),
    'People shell must wire the add-person modal through the shared People save endpoint.'
);
$assert(
    str_contains( $shell_source, "document.getElementById('metis-bulk-role-form')" )
        && str_contains( $shell_source, "post('metis_people_bulk_role_action'")
        && str_contains( $shell_source, "post('metis_people_bulk_workspace_user_action'"),
    'People shell must keep bulk action forms bound through the shared AJAX request layer.'
);

if ( $failures !== [] ) {
    fwrite( STDERR, implode( PHP_EOL, $failures ) . PHP_EOL );
    exit( 1 );
}

fwrite( STDOUT, "People UI bootstrap contracts passed.\n" );
