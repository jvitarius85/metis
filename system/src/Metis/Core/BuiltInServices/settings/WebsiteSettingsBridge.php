<?php
declare(strict_types=1);

namespace Metis\Core\BuiltInServices\settings;

use Metis\Core\Runtime\WebsiteModuleRuntimeBridge;

final class WebsiteSettingsBridge {
    public static function saveHomepageSelection( int $homepageId ): array {
        return WebsiteModuleRuntimeBridge::saveHomepageSelection( $homepageId );
    }

    /**
     * @return array<int,mixed>
     */
    public static function publishedHomepagePages( bool $shouldLoad ): array {
        return WebsiteModuleRuntimeBridge::publishedHomepagePages( $shouldLoad );
    }
}
