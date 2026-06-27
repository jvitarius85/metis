<?php
declare(strict_types=1);

namespace Metis\Core\Runtime;

final class ModuleSchemaRuntimeBridge {
    /**
     * @return array<string,callable():void>
     */
    public static function installers(): array {
        return [
            'contacts' => static function (): void { RuntimeModuleEntryResolver::callStatic( 'contacts', 'ensureRuntimeSchema' ); },
            'people' => static function (): void { \Metis\Modules\People\SchemaManager::ensureSchema(); },
            'forms' => static function (): void { RuntimeModuleEntryResolver::callStatic( 'forms', 'ensureRuntimeSchema' ); },
            'newsletter' => static function (): void { RuntimeModuleEntryResolver::callStatic( 'newsletter', 'ensureRuntimeSchema' ); },
            'board' => static function (): void { RuntimeModuleEntryResolver::callStatic( 'board', 'ensureRuntimeSchema' ); },
            'calendar' => static function (): void { RuntimeModuleEntryResolver::callStatic( 'calendar', 'ensureSchema' ); },
            'finance' => static function (): void { RuntimeModuleEntryResolver::callStatic( 'finance', 'ensureRuntimeSchema' ); },
            'hermes' => static function (): void { \Metis\Modules\Hermes\SchemaManager::ensureSchema(); },
            'website' => static function (): void { RuntimeModuleEntryResolver::callStatic( 'website', 'ensureRuntimeSchema' ); },
            'import' => static function (): void { RuntimeModuleEntryResolver::callStatic( 'import', 'ensureRuntimeSchema' ); },
            'communications_inbound' => static function (): void { \Metis\Modules\CommunicationsInbound\SchemaManager::ensureSchema(); },
            'grandy_stash' => static function (): void { \Metis\Modules\GrandyStash\GrandyStashSchemaManager::ensureSchema(); },
            'drive' => static function (): void {
                if ( function_exists( 'metis_drive_ensure_schema' ) ) {
                    metis_drive_ensure_schema();
                }
            },
            'recovery' => static function (): void { \Metis\Core\Recovery\RecoverySchema::ensureSchema(); },
            'backup_service' => static function (): void {
                if ( function_exists( 'metis_backup_service' ) ) {
                    metis_backup_service()->ensureSchema();
                }
            },
            'entity_id_service' => static function (): void {
                if ( function_exists( 'metis_entity_id_service' ) ) {
                    metis_entity_id_service()->ensureSchema();
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
