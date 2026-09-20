<?php
declare(strict_types=1);

namespace Metis\Modules\Hermes\Requests;

final class ConversationRequest {
    private function __construct(
        private readonly string $query,
        private readonly string $sessionCode,
        private readonly array $runtimeContext
    ) {}

    public static function fromGlobals(): self {
        return new self(
            \metis_text_clean( \metis_runtime_unslash( \metis_request_post()['query'] ?? '' ) ),
            \metis_text_clean( \metis_runtime_unslash( \metis_request_post()['session_code'] ?? '' ) ),
            [
                'current_route' => \metis_text_clean( \metis_runtime_unslash( \metis_request_post()['current_route'] ?? '' ) ),
                'current_module' => \metis_text_clean( \metis_runtime_unslash( \metis_request_post()['current_module'] ?? '' ) ),
                'current_topic' => \metis_text_clean( \metis_runtime_unslash( \metis_request_post()['current_topic'] ?? '' ) ),
            ]
        );
    }

    public function query(): string {
        return $this->query;
    }

    public function sessionCode(): string {
        return $this->sessionCode;
    }

    public function runtimeContext(): array {
        return $this->runtimeContext;
    }
}
