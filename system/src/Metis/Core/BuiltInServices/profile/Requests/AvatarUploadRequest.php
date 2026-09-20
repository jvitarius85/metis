<?php
declare(strict_types=1);

namespace Metis\Modules\Profile\Requests;

final class AvatarUploadRequest {
    private function __construct(
        private readonly string $avatarBase64
    ) {}

    public static function fromGlobals(): self {
        return new self(
            isset( \metis_request_post()['avatar_base64'] ) ? (string) \metis_runtime_unslash( \metis_request_post()['avatar_base64'] ) : ''
        );
    }

    public function avatarBase64(): string {
        return $this->avatarBase64;
    }
}
