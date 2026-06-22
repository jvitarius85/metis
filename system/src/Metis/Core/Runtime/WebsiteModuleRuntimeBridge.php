<?php
declare(strict_types=1);

namespace Metis\Core\Runtime;

use Metis\Modules\Website\Services\HomepageService;
use Metis\Modules\Website\Services\PageService;
use Metis\Modules\Website\Services\PostService;
use Metis\Modules\Website\Services\RevisionTimelineService;
use Metis\Modules\Website\Services\WebsiteRenderer;

final class WebsiteModuleRuntimeBridge {
    public static function createPost( array $request ): array {
        $title = trim( (string) ( $request['title'] ?? '' ) );
        $status = \metis_key_clean( (string) ( $request['status'] ?? 'draft' ) );
        if ( $status === '' ) {
            $status = 'draft';
        }

        if ( $title === '' ) {
            throw new \RuntimeException( 'A post title is required.' );
        }

        $post = PostService::create( [
            'title' => $title,
            'slug' => (string) ( $request['slug'] ?? '' ),
            'excerpt' => (string) ( $request['excerpt'] ?? '' ),
            'status' => in_array( $status, [ 'draft', 'published', 'archived' ], true ) ? $status : 'draft',
        ] );

        if ( $post === null ) {
            throw new \RuntimeException( 'Failed to create website post.' );
        }

        return [
            'status' => 'success',
            'post' => self::postSummary( $post, $title ),
            'message' => sprintf( 'Created post "%s".', (string) ( $post->title ?? $title ) ),
        ];
    }

    public static function publishPost( array $request ): array {
        $subject = trim( (string) ( $request['subject'] ?? '' ) );
        if ( $subject === '' ) {
            throw new \RuntimeException( 'Specify a post title, slug, or post code to publish.' );
        }

        $post = self::resolvePost( $subject );
        if ( $post === null || (int) ( $post->id ?? 0 ) < 1 ) {
            throw new \RuntimeException( 'No matching post was found.' );
        }

        if ( ! PostService::publish( (int) $post->id ) ) {
            throw new \RuntimeException( 'Failed to publish post.' );
        }

        $fresh = PostService::getById( (int) $post->id );

        return [
            'status' => 'success',
            'post' => [
                'id' => (int) ( $fresh->id ?? $post->id ?? 0 ),
                'post_code' => (string) ( $fresh->post_code ?? $post->post_code ?? '' ),
                'title' => (string) ( $fresh->title ?? $post->title ?? '' ),
                'slug' => (string) ( $fresh->slug ?? $post->slug ?? '' ),
                'status' => (string) ( $fresh->status ?? 'published' ),
                'publish_date' => (string) ( $fresh->publish_date ?? '' ),
            ],
            'message' => sprintf( 'Published post "%s".', (string) ( $fresh->title ?? $post->title ?? 'post' ) ),
        ];
    }

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

    public static function saveHomepageSelection( int $homepageId ): array {
        if ( $homepageId < 1 ) {
            if ( class_exists( '\Core_Settings_Service' ) ) {
                \Core_Settings_Service::delete( 'site_homepage_page_id' );
            }

            return [
                'saved' => true,
                'error' => '',
            ];
        }

        $page = PageService::getById( $homepageId );
        if ( $page === null || $page->status !== 'published' ) {
            return [
                'saved' => false,
                'error' => 'Homepage must reference a published website page.',
            ];
        }

        if ( ! HomepageService::setHomepagePageId( $homepageId ) ) {
            return [
                'saved' => false,
                'error' => 'Unable to save homepage selection.',
            ];
        }

        return [
            'saved' => true,
            'error' => '',
        ];
    }

    /**
     * @return array<int,mixed>
     */
    public static function publishedHomepagePages( bool $shouldLoad ): array {
        if ( ! $shouldLoad ) {
            return [];
        }

        return array_values( PageService::getAll( [ 'status' => 'published' ] ) );
    }

    private static function resolvePost( string $subject ): ?object {
        $subject = trim( $subject );
        if ( $subject === '' ) {
            return null;
        }

        if ( preg_match( '/^WBP[A-Z0-9]+$/i', $subject ) ) {
            $table = \Metis_Tables::get( 'website_posts' );
            $row = \metis_db()->fetchOne(
                "SELECT id
                 FROM {$table}
                 WHERE post_code = %s
                 LIMIT 1",
                [ strtoupper( $subject ) ]
            );
            if ( is_array( $row ) && (int) ( $row['id'] ?? 0 ) > 0 ) {
                return PostService::getById( (int) $row['id'] );
            }
        }

        $bySlug = PostService::getBySlug( $subject );
        if ( $bySlug !== null ) {
            return $bySlug;
        }

        $table = \Metis_Tables::get( 'website_posts' );
        $row = \metis_db()->fetchOne(
            "SELECT id
             FROM {$table}
             WHERE LOWER(COALESCE(title, '')) = %s
             ORDER BY id DESC
             LIMIT 1",
            [ strtolower( $subject ) ]
        );

        if ( is_array( $row ) && (int) ( $row['id'] ?? 0 ) > 0 ) {
            return PostService::getById( (int) $row['id'] );
        }

        return null;
    }

    private static function postSummary( ?object $post, string $fallbackTitle = '' ): array {
        return [
            'id' => (int) ( $post->id ?? 0 ),
            'post_code' => (string) ( $post->post_code ?? '' ),
            'title' => (string) ( $post->title ?? $fallbackTitle ),
            'slug' => (string) ( $post->slug ?? '' ),
            'status' => (string) ( $post->status ?? 'draft' ),
        ];
    }
}
