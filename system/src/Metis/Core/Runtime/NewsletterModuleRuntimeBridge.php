<?php
declare(strict_types=1);

namespace Metis\Core\Runtime;

final class NewsletterModuleRuntimeBridge {
    public static function createCampaign( array $request ): array {
        return RuntimeModuleEntryResolver::callStatic( 'newsletter', 'createCampaign', $request );
    }

    public static function updateCampaign( array $request ): array {
        return RuntimeModuleEntryResolver::callStatic( 'newsletter', 'updateCampaign', $request );
    }

    public static function sendCampaign( array $request ): array {
        return RuntimeModuleEntryResolver::callStatic( 'newsletter', 'sendCampaign', $request );
    }

    public static function scheduleCampaign( array $request ): array {
        return RuntimeModuleEntryResolver::callStatic( 'newsletter', 'scheduleCampaign', $request );
    }

    public static function cancelCampaign( array $request ): array {
        return RuntimeModuleEntryResolver::callStatic( 'newsletter', 'cancelCampaign', $request );
    }

    public static function archiveCampaign( array $request ): array {
        return RuntimeModuleEntryResolver::callStatic( 'newsletter', 'archiveCampaign', $request );
    }

    public static function deleteCampaign( array $request ): array {
        return RuntimeModuleEntryResolver::callStatic( 'newsletter', 'deleteCampaign', $request );
    }
}
