<?php
declare(strict_types=1);

namespace Metis\Core\Runtime;

final class ModuleSchemaRuntimeBridge {
    /**
     * @return array<string,callable():void>
     */
    public static function installers(): array {
        return [
            'contacts' => static function (): void { \Metis\Modules\Contacts\SchemaManager::ensureSchema(); },
            'people' => static function (): void { \Metis\Modules\People\SchemaManager::ensureSchema(); },
            'forms' => static function (): void { \Metis\Modules\Forms\SchemaManager::ensureSchema(); },
            'newsletter' => static function (): void { \Metis\Modules\Newsletter\SchemaManager::ensureSchema(); },
            'board' => static function (): void { \Metis\Modules\Board\SchemaManager::ensureSchema(); },
            'calendar' => static function (): void { \Metis\Modules\Calendar\SyncStore::ensureSchema(); },
            'finance' => static function (): void { \Metis\Modules\Finance\SchemaManager::ensureSchema(); },
            'hermes' => static function (): void { \Metis\Modules\Hermes\SchemaManager::ensureSchema(); },
            'website' => static function (): void { \Metis\Modules\Website\SchemaManager::ensureSchema(); },
            'import' => static function (): void { \Metis\Modules\Import\SchemaManager::ensureSchema(); },
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
