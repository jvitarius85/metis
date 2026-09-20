<?php
declare(strict_types=1);

namespace Metis\Modules\Profile\Requests;

final class CarddavIssueTokenRequest {
    private function __construct(
        private readonly string $label
    ) {}

    public static function fromGlobals(): self {
        $label = isset( \metis_request_post()['label'] )
            ? \metis_text_clean( (string) \metis_runtime_unslash( \metis_request_post()['label'] ) )
            : 'CardDAV device';
        $label = trim( $label ) !== '' ? trim( $label ) : 'CardDAV device';

        return new self( $label );
    }

    public function label(): string {
        return $this->label;
    }
}
