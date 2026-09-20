<?php
if (!defined('METIS_ROOT')) exit;

function metis_profile_ajax_verify(): void {
    \Metis\Modules\Profile\Services\ProfileContextService::ensureReady();
}

function metis_profile_current_person(): ?array {
    return \Metis\Modules\Profile\Services\ProfileContextService::currentPerson();
}

function metis_profile_person_payload(array $person): array {
    return \Metis\Modules\Profile\Services\ProfileContextService::personPayload($person);
}

function metis_profile_register_ajax_controllers(): void {
    $actions = \Metis\Modules\Profile\Policies\ProfilePolicy::ajaxPermissions();

    foreach ($actions as $action => $permission) {
        metis_ajax_register_controller($action, [
            'module' => 'profile',
            'permission' => $permission,
            'nonce_action' => metis_ajax_nonce_action($action),
        ]);
    }
}

metis_profile_register_ajax_controllers();

metis_ajax_register_handler( 'metis_profile_get', [ \Metis\Modules\Profile\Controllers\AjaxController::class, 'getProfile' ] );
metis_ajax_register_handler( 'metis_profile_carddav_issue_token', [ \Metis\Modules\Profile\Controllers\AjaxController::class, 'issueCarddavToken' ] );
metis_ajax_register_handler( 'metis_profile_carddav_revoke_token', [ \Metis\Modules\Profile\Controllers\AjaxController::class, 'revokeCarddavToken' ] );
metis_ajax_register_handler( 'metis_profile_save', [ \Metis\Modules\Profile\Controllers\AjaxController::class, 'saveProfile' ] );
metis_ajax_register_handler( 'metis_profile_change_workspace_password', [ \Metis\Modules\Profile\Controllers\AjaxController::class, 'changeWorkspacePassword' ] );
metis_ajax_register_handler( 'metis_profile_change_password', [ \Metis\Modules\Profile\Controllers\AjaxController::class, 'changePassword' ] );
metis_ajax_register_handler( 'metis_profile_save_avatar', [ \Metis\Modules\Profile\Controllers\AjaxController::class, 'saveAvatar' ] );
metis_ajax_register_handler( 'metis_profile_generate_totp_secret', [ \Metis\Modules\Profile\Controllers\AjaxController::class, 'generateTotpSecret' ] );
metis_ajax_register_handler( 'metis_profile_verify_totp_secret', [ \Metis\Modules\Profile\Controllers\AjaxController::class, 'verifyTotpSecret' ] );
metis_ajax_register_handler( 'metis_profile_begin_passkey_registration', [ \Metis\Modules\Profile\Controllers\AjaxController::class, 'beginPasskeyRegistration' ] );
metis_ajax_register_handler( 'metis_profile_complete_passkey_registration', [ \Metis\Modules\Profile\Controllers\AjaxController::class, 'completePasskeyRegistration' ] );
metis_ajax_register_handler( 'metis_profile_revoke_passkey', [ \Metis\Modules\Profile\Controllers\AjaxController::class, 'revokePasskey' ] );
