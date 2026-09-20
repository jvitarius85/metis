<?php
declare(strict_types=1);

namespace Metis\Modules\Help\Requests;

use Metis\Http\Request;

final class HelpCategoryRequest {
    private function __construct(
        private readonly string $slug,
        private readonly int $page
    ) {}

    public static function fromRequest( Request $request ): self {
        return new self(
            trim( (string) $request->attribute( 'slug', '' ) ),
            max( 1, (int) ( $request->query()['page'] ?? 1 ) )
        );
    }

    public function slug(): string {
        return $this->slug;
    }

    public function page(): int {
        return $this->page;
    }
}
