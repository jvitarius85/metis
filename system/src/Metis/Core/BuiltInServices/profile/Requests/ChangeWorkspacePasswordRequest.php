<?php
declare(strict_types=1);

namespace Metis\Modules\Profile\Requests;

final class ChangeWorkspacePasswordRequest {
    private function __construct(
        private readonly string $newPassword,
        private readonly string $confirmPassword
    ) {}

    public static function fromGlobals(): self {
        return new self(
            isset( \metis_request_post()['new_password'] ) ? (string) \metis_runtime_unslash( \metis_request_post()['new_password'] ) : '',
            isset( \metis_request_post()['confirm_password'] ) ? (string) \metis_runtime_unslash( \metis_request_post()['confirm_password'] ) : ''
        );
    }

    public function newPassword(): string {
        return $this->newPassword;
    }

    public function confirmPassword(): string {
        return $this->confirmPassword;
    }
}
