<?php
declare(strict_types=1);

namespace Metis\Core\Editor;

final class EditorPreviewService {
    /**
     * @param array<int,mixed> $blocks
     * @param array<string,mixed> $options
     * @return array{document_html:string,content_html:string,context:array<string,mixed>}
     */
    public static function render( array $blocks, array $options = [] ): array {
        return WebsiteEditorRuntimeBridge::renderPreview( $options );
    }
}
