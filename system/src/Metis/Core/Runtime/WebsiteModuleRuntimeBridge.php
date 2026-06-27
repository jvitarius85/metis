<?php
declare(strict_types=1);

namespace Metis\Core\Runtime;

final class WebsiteModuleRuntimeBridge {
    public static function createPost( array $request ): array {
        return (array) RuntimeModuleEntryResolver::callStatic( 'website', 'createPost', $request );
    }

    public static function publishPost( array $request ): array {
        return (array) RuntimeModuleEntryResolver::callStatic( 'website', 'publishPost', $request );
    }

    /**
     * @param array<string,mixed> $data
     */
    public static function saveDraft( string $entityType, int $entityId, array $data ): bool {
        return (bool) RuntimeModuleEntryResolver::callStatic( 'website', 'saveDraft', $entityType, $entityId, $data );
    }

    /**
     * @param array<string,mixed> $options
     * @return array{document_html:string,content_html:string,context:array<string,mixed>}
     */
    public static function renderPreview( array $options = [] ): array {
        return (array) RuntimeModuleEntryResolver::callStatic( 'website', 'renderEditorPreview', $options );
    }

    /**
     * @param array<string,mixed> $payload
     */
    public static function checkpoint( string $entityType, int $entityId, array $payload, string $note = '' ): bool {
        return (bool) RuntimeModuleEntryResolver::callStatic( 'website', 'checkpoint', $entityType, $entityId, $payload, $note );
    }

    public static function saveHomepageSelection( int $homepageId ): array {
        return (array) RuntimeModuleEntryResolver::callStatic( 'website', 'saveHomepageSelection', $homepageId );
    }

    /**
     * @return array<int,mixed>
     */
    public static function publishedHomepagePages( bool $shouldLoad ): array {
        return (array) RuntimeModuleEntryResolver::callStatic( 'website', 'publishedHomepagePages', $shouldLoad );
    }
}
