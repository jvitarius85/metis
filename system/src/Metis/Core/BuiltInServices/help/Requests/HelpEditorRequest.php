<?php
declare(strict_types=1);

namespace Metis\Modules\Help\Requests;

use Metis\Http\Request;

final class HelpEditorRequest {
    private function __construct(
        private readonly int $articleId,
        private readonly bool $previewRequested
    ) {}

    public static function fromCreateRequest( Request $request ): self {
        return new self( 0, self::previewRequestedFrom( $request ) );
    }

    public static function fromEditRequest( Request $request ): self {
        return new self(
            max( 0, (int) $request->attribute( 'id', 0 ) ),
            self::previewRequestedFrom( $request )
        );
    }

    public function articleId(): int {
        return $this->articleId;
    }

    public function previewRequested(): bool {
        return $this->previewRequested;
    }

    private static function previewRequestedFrom( Request $request ): bool {
        return (string) ( $request->query()['preview'] ?? '' ) === '1';
    }
}
