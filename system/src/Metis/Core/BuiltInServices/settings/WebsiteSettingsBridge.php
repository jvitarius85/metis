<?php
declare(strict_types=1);

namespace Metis\Core\BuiltInServices\settings;

final class WebsiteSettingsBridge {
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

        if (
            ! class_exists( '\Metis\Modules\Website\Services\HomepageService' )
            || ! class_exists( '\Metis\Modules\Website\Services\PageService' )
        ) {
            return [
                'saved' => false,
                'error' => '',
            ];
        }

        $page = \Metis\Modules\Website\Services\PageService::getById( $homepageId );
        if ( $page === null || $page->status !== 'published' ) {
            return [
                'saved' => false,
                'error' => 'Homepage must reference a published website page.',
            ];
        }

        if ( ! \Metis\Modules\Website\Services\HomepageService::setHomepagePageId( $homepageId ) ) {
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
        if ( ! $shouldLoad || ! class_exists( '\Metis\Modules\Website\Services\PageService' ) ) {
            return [];
        }

        return array_values(
            \Metis\Modules\Website\Services\PageService::getAll( [ 'status' => 'published' ] )
        );
    }
}
