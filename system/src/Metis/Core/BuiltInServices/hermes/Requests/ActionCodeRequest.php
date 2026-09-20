<?php
declare(strict_types=1);

namespace Metis\Modules\Hermes\Requests;

final class ActionCodeRequest {
    private function __construct(
        private readonly string $actionCode
    ) {}

    public static function fromGlobals(): self {
        return new self(
            \metis_text_clean( \metis_runtime_unslash( \metis_request_post()['action_code'] ?? '' ) )
        );
    }

    public function actionCode(): string {
        return $this->actionCode;
    }
}
