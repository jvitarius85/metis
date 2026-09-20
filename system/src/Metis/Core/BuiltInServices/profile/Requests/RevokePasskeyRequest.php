<?php
declare(strict_types=1);

namespace Metis\Modules\Profile\Requests;

final class RevokePasskeyRequest {
    private function __construct(
        private readonly int $passkeyId
    ) {}

    public static function fromGlobals(): self {
        return new self(
            isset( \metis_request_post()['passkey_id'] ) ? (int) \metis_runtime_unslash( \metis_request_post()['passkey_id'] ) : 0
        );
    }

    public function passkeyId(): int {
        return $this->passkeyId;
    }
}
