<?php
declare(strict_types=1);

namespace Metis\Modules\Profile\Requests;

final class CarddavRevokeTokenRequest {
    private function __construct(
        private readonly int $tokenId
    ) {}

    public static function fromGlobals(): self {
        return new self(
            isset( \metis_request_post()['token_id'] ) ? (int) \metis_runtime_unslash( \metis_request_post()['token_id'] ) : 0
        );
    }

    public function tokenId(): int {
        return $this->tokenId;
    }
}
