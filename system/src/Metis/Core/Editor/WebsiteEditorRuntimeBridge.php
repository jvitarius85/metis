<?php
declare(strict_types=1);

namespace Metis\Core\Editor;

use Metis\Modules\Website\Services\PageService;
use Metis\Modules\Website\Services\PostService;
use Metis\Modules\Website\Services\RevisionTimelineService;
use Metis\Modules\Website\Services\WebsiteRenderer;

final class WebsiteEditorRuntimeBridge {
    /**
     * @param array<string,mixed> $data
     */
    public static function saveDraft( string $entityType, int $entityId, array $data ): bool {
        if ( $entityType === 'page' ) {
            return PageService::update( $entityId, [
                'title' => $data['title'] ?? null,
                'slug' => $data['slug'] ?? null,
                'draft_layout_json' => $data['layout_json'] ?? null,
                'status' => 'draft',
            ] );
        }

        if ( $entityType === 'post' ) {
            return PostService::update( $entityId, [
                'title' => $data['title'] ?? null,
                'slug' => $data['slug'] ?? null,
                'draft_content_json' => $data['content_json'] ?? null,
                'excerpt' => $data['excerpt'] ?? null,
                'status' => 'draft',
            ] );
        }

        return false;
    }

    /**
     * @param array<string,mixed> $options
     * @return array{document_html:string,content_html:string,context:array<string,mixed>}
     */
    public static function renderPreview( array $options = [] ): array {
        $layout_json = isset( $options['layout_json'] ) && is_string( $options['layout_json'] )
            ? $options['layout_json']
            : '';

        return WebsiteRenderer::renderStructuredEditorPreview( $layout_json, $options );
    }

    /**
     * @param array<string,mixed> $payload
     */
    public static function checkpoint( string $entityType, int $entityId, array $payload, string $note = '' ): bool {
        if ( $entityId < 1 ) {
            return false;
        }

        return RevisionTimelineService::save( $entityType, $entityId, $payload, $note );
    }
}
