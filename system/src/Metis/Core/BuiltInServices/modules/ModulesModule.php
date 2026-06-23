<?php
declare(strict_types=1);

namespace Metis\Modules\Modules;

final class ModulesModule {
    private static bool $booted = false;

    public static function boot(): void {
        if ( self::$booted ) {
            return;
        }

        self::$booted = true;
        \Metis_Logger::info( 'Modules bootstrap loaded' );
    }
}
