<?php
declare(strict_types=1);

namespace Metis\Core\Recovery;

final class ReleaseRecoveryService {
    public function __construct(
        private readonly RecoveryPolicyService $policy = new RecoveryPolicyService()
    ) {}

    /** @return array<string,mixed> */
    public function recoverPendingReleaseIfNeeded( string $trigger = 'preboot' ): array {
        if ( ! $this->policy->automaticReleaseRollbackEnabled() ) {
            return [ 'status' => 'disabled' ];
        }

        if ( ! class_exists( '\Metis\Release\ReleaseManager' ) ) {
            return [ 'status' => 'unavailable' ];
        }

        $manager = new \Metis\Release\ReleaseManager();
        $state = $manager->releaseRecoveryState();
        $transaction = is_array( $state['pending_release_transaction'] ?? null )
            ? (array) $state['pending_release_transaction']
            : [];

        if ( $transaction === [] ) {
            return [ 'status' => 'none' ];
        }

        $status = trim( (string) ( $transaction['status'] ?? '' ) );
        $rollbackRequested = ! empty( $transaction['rollback_requested'] );
        if ( $rollbackRequested ) {
            return $manager->recoverPendingReleaseTransaction( $trigger );
        }

        if ( $status === 'in_progress' ) {
            $graceSeconds = $this->policy->releaseInProgressGraceSeconds();
            $startedAt = strtotime( (string) ( $transaction['started_at'] ?? '' ) );
            $ageSeconds = $startedAt === false ? $graceSeconds : max( 0, time() - $startedAt );
            if ( $ageSeconds < $graceSeconds ) {
                return [
                    'status' => 'in_progress',
                    'grace_remaining' => $graceSeconds - $ageSeconds,
                ];
            }

            return $manager->recoverPendingReleaseTransaction( $trigger );
        }

        return $manager->confirmHealthyBootForPendingRelease( $trigger );
    }

    /**
     * @param array<string,mixed> $context
     */
    public function recordFatalBootFailure( array $context = [] ): void {
        if ( ! $this->policy->automaticReleaseRollbackEnabled() ) {
            return;
        }

        if ( ! class_exists( '\Metis\Release\ReleaseManager' ) ) {
            return;
        }

        try {
            ( new \Metis\Release\ReleaseManager() )->flagPendingReleaseFailure( $context );
        } catch ( \Throwable ) {
        }
    }
}
