<?php
declare(strict_types=1);

namespace Metis\Modules\Help\Requests;

use Metis\Http\Request;

final class HelpAdminListRequest {
    private function __construct(
        private readonly string $search,
        private readonly string $category,
        private readonly string $status,
        private readonly int $page
    ) {}

    public static function fromRequest( Request $request ): self {
        $queryData = $request->query();

        return new self(
            trim( (string) ( $queryData['q'] ?? '' ) ),
            trim( (string) ( $queryData['category'] ?? '' ) ),
            trim( (string) ( $queryData['status'] ?? '' ) ),
            max( 1, (int) ( $queryData['page'] ?? 1 ) )
        );
    }

    public function search(): string {
        return $this->search;
    }

    public function category(): string {
        return $this->category;
    }

    public function status(): string {
        return $this->status;
    }

    public function page(): int {
        return $this->page;
    }
}
