<?php
declare(strict_types=1);

namespace Metis\Modules\Profile\Requests;

final class VerifyTotpRequest {
    private function __construct(
        private readonly string $secret,
        private readonly string $code
    ) {}

    public static function fromGlobals(): self {
        return new self(
            isset( \metis_request_post()['secret'] ) ? strtoupper( \metis_text_clean( \metis_runtime_unslash( \metis_request_post()['secret'] ) ) ) : '',
            isset( \metis_request_post()['code'] ) ? (string) preg_replace( '/\D+/', '', (string) \metis_runtime_unslash( \metis_request_post()['code'] ) ) : ''
        );
    }

    public function secret(): string {
        return $this->secret;
    }

    public function code(): string {
        return $this->code;
    }
}
