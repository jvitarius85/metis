<?php
declare(strict_types=1);

namespace Metis\Modules\Hermes\Requests;

final class ReleaseActionRequest {
    private function __construct(
        private readonly string $actionCode,
        private readonly string $progressToken
    ) {}

    public static function fromGlobals(): self {
        return new self(
            \metis_text_clean( \metis_runtime_unslash( \metis_request_post()['action_code'] ?? '' ) ),
            \metis_text_clean( \metis_runtime_unslash( \metis_request_post()['progress_token'] ?? '' ) )
        );
    }

    public function actionCode(): string {
        return $this->actionCode;
    }

    public function progressToken(): string {
        return $this->progressToken;
    }
}
