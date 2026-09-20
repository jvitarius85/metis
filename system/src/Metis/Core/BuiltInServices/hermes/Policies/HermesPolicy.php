<?php
declare(strict_types=1);

namespace Metis\Modules\Hermes\Policies;

use Metis\Modules\Hermes\Access;

final class HermesPolicy {
    public static function canView(): bool {
        return Access::canView();
    }

    public static function canManage(): bool {
        return Access::canManage();
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    public static function ajaxControllers(): array {
        return [
            'metis_hermes_bootstrap' => [
                'permission' => 'view',
                'nonce_action' => \metis_ajax_nonce_action( 'metis_hermes_bootstrap' ),
                'schema' => [],
            ],
            'metis_hermes_query' => [
                'permission' => 'view',
                'nonce_action' => \metis_ajax_nonce_action( 'metis_hermes_query' ),
                'schema' => [
                    'query' => [ 'type' => 'string', 'required' => true ],
                    'session_code' => [ 'type' => 'string', 'required' => false ],
                    'current_route' => [ 'type' => 'string', 'required' => false ],
                    'current_module' => [ 'type' => 'string', 'required' => false ],
                    'current_topic' => [ 'type' => 'string', 'required' => false ],
                ],
            ],
            'metis_hermes_diagnostics' => [
                'permission' => 'view',
                'nonce_action' => \metis_ajax_nonce_action( 'metis_hermes_diagnostics' ),
                'schema' => [
                    'query' => [ 'type' => 'string', 'required' => false ],
                    'session_code' => [ 'type' => 'string', 'required' => false ],
                ],
            ],
            'metis_hermes_preview_action' => [
                'permission' => 'view',
                'nonce_action' => \metis_ajax_nonce_action( 'metis_hermes_preview_action' ),
                'schema' => [
                    'action_code' => [ 'type' => 'string', 'required' => true ],
                ],
            ],
            'metis_hermes_approve_action' => [
                'permission' => 'edit',
                'nonce_action' => \metis_ajax_nonce_action( 'metis_hermes_approve_action' ),
                'schema' => [
                    'action_code' => [ 'type' => 'string', 'required' => true ],
                    'note' => [ 'type' => 'string', 'required' => false ],
                ],
            ],
            'metis_hermes_execute_action' => [
                'permission' => 'edit',
                'nonce_action' => \metis_ajax_nonce_action( 'metis_hermes_execute_action' ),
                'schema' => [
                    'action_code' => [ 'type' => 'string', 'required' => true ],
                ],
            ],
            'metis_hermes_execute_release_action' => [
                'permission' => 'edit',
                'nonce_action' => \metis_ajax_nonce_action( 'metis_hermes_execute_action' ),
                'schema' => [
                    'action_code' => [ 'type' => 'string', 'required' => true ],
                    'progress_token' => [ 'type' => 'string', 'required' => true ],
                ],
            ],
            'metis_hermes_release_progress' => [
                'permission' => 'edit',
                'nonce_action' => \metis_ajax_nonce_action( 'metis_hermes_execute_action' ),
                'schema' => [
                    'progress_token' => [ 'type' => 'string', 'required' => true ],
                ],
            ],
            'metis_hermes_reveal_secret' => [
                'permission' => 'edit',
                'nonce_action' => \metis_ajax_nonce_action( 'metis_hermes_reveal_secret' ),
                'schema' => [
                    'reveal_token' => [ 'type' => 'string', 'required' => true ],
                ],
            ],
        ];
    }
}
