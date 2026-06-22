<?php
declare(strict_types=1);

namespace Metis\Services;

use Metis\Core\Runtime\WebsiteModuleRuntimeBridge;

final class HermesWebsiteAdminService {
    public function createPost( mixed $request = null ): array {
        return WebsiteModuleRuntimeBridge::createPost( is_array( $request ) ? $request : [] );
    }

    public function publishPost( mixed $request = null ): array {
        return WebsiteModuleRuntimeBridge::publishPost( is_array( $request ) ? $request : [] );
    }
}
