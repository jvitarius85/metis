<?php
declare(strict_types=1);

namespace Metis\Services;

use Metis\Core\Runtime\NewsletterModuleRuntimeBridge;

final class HermesNewsletterAdminService {
    public function createCampaign( mixed $request = null ): array {
        return NewsletterModuleRuntimeBridge::createCampaign( is_array( $request ) ? $request : [] );
    }

    public function updateCampaign( mixed $request = null ): array {
        return NewsletterModuleRuntimeBridge::updateCampaign( is_array( $request ) ? $request : [] );
    }

    public function sendCampaign( mixed $request = null ): array {
        return NewsletterModuleRuntimeBridge::sendCampaign( is_array( $request ) ? $request : [] );
    }

    public function scheduleCampaign( mixed $request = null ): array {
        return NewsletterModuleRuntimeBridge::scheduleCampaign( is_array( $request ) ? $request : [] );
    }

    public function cancelCampaign( mixed $request = null ): array {
        return NewsletterModuleRuntimeBridge::cancelCampaign( is_array( $request ) ? $request : [] );
    }

    public function archiveCampaign( mixed $request = null ): array {
        return NewsletterModuleRuntimeBridge::archiveCampaign( is_array( $request ) ? $request : [] );
    }

    public function deleteCampaign( mixed $request = null ): array {
        return NewsletterModuleRuntimeBridge::deleteCampaign( is_array( $request ) ? $request : [] );
    }
}
