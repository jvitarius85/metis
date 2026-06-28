<?php
declare(strict_types=1);

namespace Metis\Core\Runtime;

final class ModuleSchemaRuntimeBridge {
    /**
     * @return array<string,callable():void>
     */
    public static function installers(): array {
        return [
            'people' => static function (): void { \Metis\Modules\People\SchemaManager::ensureSchema(); },
            'hermes' => static function (): void { \Metis\Modules\Hermes\SchemaManager::ensureSchema(); },
            'communications_inbound' => static function (): void { \Metis\Modules\CommunicationsInbound\SchemaManager::ensureSchema(); },
            'drive' => static function (): void {
                if ( class_exists( '\Metis\Modules\Drive\DriveModule' ) && \method_exists( '\Metis\Modules\Drive\DriveModule', 'ensureRuntimeSchema' ) ) {
                    \Metis\Modules\Drive\DriveModule::ensureRuntimeSchema();
                }
            },
            'recovery' => static function (): void { \Metis\Core\Recovery\RecoverySchema::ensureSchema(); },
            'entity_id_service' => static function (): void {
                if ( function_exists( 'metis_entity_id_service' ) ) {
                    metis_entity_id_service()->ensureSchema();
                }
            },
            'backup_service' => static function (): void {
                if ( function_exists( 'metis_backup_service' ) ) {
                    metis_backup_service()->ensureSchema();
                }
            },
            'help_search_store' => static function (): void {
                if ( class_exists( '\Metis\Core\HelpSearchStore' ) ) {
                    ( new \Metis\Core\HelpSearchStore() )->ensureSchema();
                }
            },
        ];
    }

    public static function installer( string $step ): ?callable {
        $installers = self::installers();
        return isset( $installers[ $step ] ) ? $installers[ $step ] : null;
    }
}
