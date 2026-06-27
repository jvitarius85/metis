<?php
declare(strict_types=1);

namespace Metis\Core\Editor;

final class EditorAutosaveService {
    public const DEBOUNCE_MS = 2000;

    /**
     * @param array<string,mixed> $data
     */
    public static function saveDraft( string $entityType, int $entityId, array $data ): bool {
        return WebsiteEditorRuntimeBridge::saveDraft( $entityType, $entityId, $data );
    }
}
