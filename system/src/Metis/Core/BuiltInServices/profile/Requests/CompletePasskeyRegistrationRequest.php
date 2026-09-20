<?php
declare(strict_types=1);

namespace Metis\Modules\Profile\Requests;

final class CompletePasskeyRegistrationRequest {
    private function __construct(
        private readonly string $challengeKey,
        private readonly string $credentialId,
        private readonly string $clientDataJson,
        private readonly string $attestationObject,
        private readonly string $transportsJson,
        private readonly string $label
    ) {}

    public static function fromGlobals(): self {
        return new self(
            isset( \metis_request_post()['challenge_key'] ) ? \metis_text_clean( \metis_runtime_unslash( \metis_request_post()['challenge_key'] ) ) : '',
            isset( \metis_request_post()['credential_id'] ) ? \metis_text_clean( \metis_runtime_unslash( \metis_request_post()['credential_id'] ) ) : '',
            isset( \metis_request_post()['client_data_json'] ) ? (string) \metis_runtime_unslash( \metis_request_post()['client_data_json'] ) : '',
            isset( \metis_request_post()['attestation_object'] ) ? (string) \metis_runtime_unslash( \metis_request_post()['attestation_object'] ) : '',
            isset( \metis_request_post()['transports_json'] ) ? \metis_text_clean( \metis_runtime_unslash( \metis_request_post()['transports_json'] ) ) : '',
            isset( \metis_request_post()['label'] ) ? \metis_text_clean( \metis_runtime_unslash( \metis_request_post()['label'] ) ) : ''
        );
    }

    public function challengeKey(): string {
        return $this->challengeKey;
    }

    public function credentialId(): string {
        return $this->credentialId;
    }

    public function clientDataJson(): string {
        return $this->clientDataJson;
    }

    public function attestationObject(): string {
        return $this->attestationObject;
    }

    public function transportsJson(): string {
        return $this->transportsJson;
    }

    public function label(): string {
        return $this->label;
    }
}
