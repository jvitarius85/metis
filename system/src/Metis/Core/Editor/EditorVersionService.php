<?php
declare(strict_types=1);

namespace Metis\Core\Editor;

final class EditorVersionService {
    /**
     * @param array<string,mixed> $payload
     */
    public static function checkpoint( string $entityType, int $entityId, array $payload, string $note = '' ): bool {
        return WebsiteEditorRuntimeBridge::checkpoint( $entityType, $entityId, $payload, $note );
    }
}
