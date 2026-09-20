<?php
declare(strict_types=1);

namespace Metis\Modules\Hermes\Requests;

final class DiagnosticsRequest {
    private function __construct(
        private readonly string $query,
        private readonly string $sessionCode
    ) {}

    public static function fromGlobals(): self {
        return new self(
            \metis_text_clean( \metis_runtime_unslash( \metis_request_post()['query'] ?? '' ) ),
            \metis_text_clean( \metis_runtime_unslash( \metis_request_post()['session_code'] ?? '' ) )
        );
    }

    public function query(): string {
        return $this->query;
    }

    public function sessionCode(): string {
        return $this->sessionCode;
    }
}
