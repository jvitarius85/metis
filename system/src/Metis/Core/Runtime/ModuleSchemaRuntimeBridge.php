<?php
declare(strict_types=1);

namespace Metis\Core\Runtime;

use Metis\Modules\Board\BoardModule;
use Metis\Modules\Calendar\CalendarModule;
use Metis\Modules\Contacts\ContactsModule;
use Metis\Modules\Finance\FinanceModule;
use Metis\Modules\Forms\FormsModule;
use Metis\Modules\Import\ImportModule;
use Metis\Modules\Newsletter\NewsletterModule;
use Metis\Modules\Website\WebsiteModule;

final class ModuleSchemaRuntimeBridge {
    /**
     * @return array<string,callable():void>
     */
    public static function installers(): array {
        return [
            'contacts' => static function (): void { ContactsModule::ensureRuntimeSchema(); },
            'people' => static function (): void { \Metis\Modules\People\SchemaManager::ensureSchema(); },
            'forms' => static function (): void { FormsModule::ensureRuntimeSchema(); },
            'newsletter' => static function (): void { NewsletterModule::ensureRuntimeSchema(); },
            'board' => static function (): void { BoardModule::ensureRuntimeSchema(); },
            'calendar' => static function (): void { CalendarModule::ensureSchema(); },
            'finance' => static function (): void { FinanceModule::ensureRuntimeSchema(); },
            'hermes' => static function (): void { \Metis\Modules\Hermes\SchemaManager::ensureSchema(); },
            'website' => static function (): void { WebsiteModule::ensureRuntimeSchema(); },
            'import' => static function (): void { ImportModule::ensureRuntimeSchema(); },
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
