<?php
declare(strict_types=1);

namespace Metis\Core\Editor;

use Metis\Core\Runtime\WebsiteModuleRuntimeBridge;

final class WebsiteEditorRuntimeBridge {
    /**
     * @param array<string,mixed> $data
     */
    public static function saveDraft( string $entityType, int $entityId, array $data ): bool {
        return WebsiteModuleRuntimeBridge::saveDraft( $entityType, $entityId, $data );
    }

    /**
     * @param array<string,mixed> $options
     * @return array{document_html:string,content_html:string,context:array<string,mixed>}
     */
    public static function renderPreview( array $options = [] ): array {
        return WebsiteModuleRuntimeBridge::renderPreview( $options );
    }

    /**
     * @param array<string,mixed> $payload
     */
    public static function checkpoint( string $entityType, int $entityId, array $payload, string $note = '' ): bool {
        return WebsiteModuleRuntimeBridge::checkpoint( $entityType, $entityId, $payload, $note );
    }
}
