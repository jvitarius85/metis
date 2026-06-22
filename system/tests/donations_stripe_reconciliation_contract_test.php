<?php
declare(strict_types=1);

if ( PHP_SAPI !== 'cli' ) {
    fwrite( STDERR, "This test must be run from the command line.\n" );
    exit( 1 );
}

$root = dirname( __DIR__ );
$failures = [];

$assert = static function ( bool $condition, string $message ) use ( &$failures ): void {
    if ( ! $condition ) {
        $failures[] = $message;
    }
};

$source = file_get_contents( $root . '/src/Metis/Modules/Donations/StripeReconciliationService.php' );
$source = $source === false ? '' : $source;

$run_nightly_start = strpos( $source, 'public static function runNightly' );
$import_call = strpos( $source, 'self::importRecentTransactions( $stripe, $summary, $transaction_limit );' );
$reconcile_call = strpos( $source, 'self::reconcileTransactions( $stripe, $summary, $transaction_limit );' );

$assert(
    $run_nightly_start !== false
    && $import_call !== false
    && $reconcile_call !== false
    && $import_call > $run_nightly_start
    && $import_call < $reconcile_call,
    'Nightly Stripe reconciliation must import recent transactions before reconciling existing records.'
);

$assert(
    str_contains( $source, "'transactions_imported' => 0" )
    && str_contains( $source, "'transactions_skipped' => 0" ),
    'Nightly Stripe reconciliation summary must report imported and skipped transaction counts.'
);

$assert(
    str_contains( $source, "'donor_email' => \$profile['email'] !== '' ? \$profile['email'] : null" )
    && str_contains( $source, "'stripe_customer_id' => \$customer_id !== '' ? \$customer_id : null" ),
    'Imported Stripe transactions must persist donor email and Stripe customer ID for donor reconciliation.'
);

$assert(
    str_contains( $source, 'private static function transactionImportStartTimestamp(): int' )
    && str_contains( $source, "SELECT MAX(tran_date) FROM ' . \\Metis_Tables::get( 'transactions' ) . \" WHERE platform IN ('ST', 'stripe')\"" )
    && str_contains( $source, "return max( \$fallback, (int) \$latest_ts - ( 7 * DAY_IN_SECONDS ) );" ),
    'Nightly Stripe import window must anchor off recent Stripe transaction history instead of a fixed full-history sync.'
);

if ( $failures !== [] ) {
    fwrite( STDERR, implode( PHP_EOL, $failures ) . PHP_EOL );
    exit( 1 );
}

fwrite( STDOUT, "Donations Stripe reconciliation contract checks passed.\n" );
