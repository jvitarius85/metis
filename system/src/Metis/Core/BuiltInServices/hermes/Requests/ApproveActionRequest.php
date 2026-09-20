<?php
declare(strict_types=1);

namespace Metis\Modules\Hermes\Requests;

final class ApproveActionRequest {
    private function __construct(
        private readonly string $actionCode,
        private readonly string $note
    ) {}

    public static function fromGlobals( ?string $note = null ): self {
        return new self(
            \metis_text_clean( \metis_runtime_unslash( \metis_request_post()['action_code'] ?? '' ) ),
            $note ?? \metis_textarea_clean( \metis_runtime_unslash( \metis_request_post()['note'] ?? '' ) )
        );
    }

    public function actionCode(): string {
        return $this->actionCode;
    }

    public function note(): string {
        return $this->note;
    }
}
