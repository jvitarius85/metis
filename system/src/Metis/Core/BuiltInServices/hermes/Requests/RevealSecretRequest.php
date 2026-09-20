<?php
declare(strict_types=1);

namespace Metis\Modules\Hermes\Requests;

final class RevealSecretRequest {
    private function __construct(
        private readonly string $revealToken
    ) {}

    public static function fromGlobals(): self {
        return new self(
            \metis_text_clean( \metis_runtime_unslash( \metis_request_post()['reveal_token'] ?? '' ) )
        );
    }

    public function revealToken(): string {
        return $this->revealToken;
    }
}
