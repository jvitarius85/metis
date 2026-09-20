<?php
declare(strict_types=1);

namespace Metis\Modules\Help\Requests;

use Metis\Http\Request;

final class HelpArticleRequest {
    private function __construct(
        private readonly string $slug
    ) {}

    public static function fromRequest( Request $request ): self {
        return new self(
            trim( (string) $request->attribute( 'slug', '' ) )
        );
    }

    public function slug(): string {
        return $this->slug;
    }
}
