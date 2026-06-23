<?php
declare(strict_types=1);

namespace Metis\Core\Runtime;

use Metis\Modules\Website\WebsiteModule;

final class WebsiteModuleRuntimeBridge {
    public static function createPost( array $request ): array {
        return WebsiteModule::createPost( $request );
    }

    public static function publishPost( array $request ): array {
        return WebsiteModule::publishPost( $request );
    }

    /**
     * @param array<string,mixed> $data
     */
    public static function saveDraft( string $entityType, int $entityId, array $data ): bool {
        return WebsiteModule::saveDraft( $entityType, $entityId, $data );
    }

    /**
     * @param array<string,mixed> $options
     * @return array{document_html:string,content_html:string,context:array<string,mixed>}
     */
    public static function renderPreview( array $options = [] ): array {
        return WebsiteModule::renderEditorPreview( $options );
    }

    /**
     * @param array<string,mixed> $payload
     */
    public static function checkpoint( string $entityType, int $entityId, array $payload, string $note = '' ): bool {
        return WebsiteModule::checkpoint( $entityType, $entityId, $payload, $note );
    }

    public static function saveHomepageSelection( int $homepageId ): array {
        return WebsiteModule::saveHomepageSelection( $homepageId );
    }

    /**
     * @return array<int,mixed>
     */
    public static function publishedHomepagePages( bool $shouldLoad ): array {
        return WebsiteModule::publishedHomepagePages( $shouldLoad );
    }
}
