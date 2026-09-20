<?php
declare(strict_types=1);

namespace Metis\Modules\Profile\Policies;

final class ProfilePolicy {
    /**
     * @return array<string, string>
     */
    public static function ajaxPermissions(): array {
        return [
            'metis_profile_get' => 'view',
            'metis_profile_save' => 'edit',
            'metis_profile_change_workspace_password' => 'edit',
            'metis_profile_change_password' => 'edit',
            'metis_profile_save_avatar' => 'edit',
            'metis_profile_generate_totp_secret' => 'edit',
            'metis_profile_verify_totp_secret' => 'edit',
            'metis_profile_begin_passkey_registration' => 'edit',
            'metis_profile_complete_passkey_registration' => 'edit',
            'metis_profile_revoke_passkey' => 'edit',
            'metis_profile_carddav_issue_token' => 'edit',
            'metis_profile_carddav_revoke_token' => 'edit',
        ];
    }
}
