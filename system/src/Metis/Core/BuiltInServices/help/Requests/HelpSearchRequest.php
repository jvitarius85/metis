<?php
declare(strict_types=1);

namespace Metis\Modules\Help\Requests;

use Metis\Http\Request;

final class HelpSearchRequest {
    private function __construct(
        private readonly string $query,
        private readonly string $category,
        private readonly int $page
    ) {}

    public static function fromRequest( Request $request ): self {
        $queryData = $request->query();

        return new self(
            trim( (string) ( $queryData['q'] ?? $queryData['query'] ?? '' ) ),
            trim( (string) ( $queryData['category'] ?? '' ) ),
            max( 1, (int) ( $queryData['page'] ?? 1 ) )
        );
    }

    public function query(): string {
        return $this->query;
    }

    public function category(): string {
        return $this->category;
    }

    public function page(): int {
        return $this->page;
    }
}
