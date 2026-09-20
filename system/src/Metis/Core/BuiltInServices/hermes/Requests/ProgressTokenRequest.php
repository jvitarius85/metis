<?php
declare(strict_types=1);

namespace Metis\Modules\Hermes\Requests;

final class ProgressTokenRequest {
    private function __construct(
        private readonly string $progressToken
    ) {}

    public static function fromGlobals(): self {
        return new self(
            \metis_text_clean( \metis_runtime_unslash( \metis_request_post()['progress_token'] ?? '' ) )
        );
    }

    public function progressToken(): string {
        return $this->progressToken;
    }
}
