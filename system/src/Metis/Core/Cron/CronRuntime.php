<?php
if ( ! defined( 'METIS_ROOT' ) ) exit;

use Metis\Core\Cache\CacheService;

final class Metis_Cron_Manager {
    private const ENDPOINT_PATH     = '/api/cron';
    private const SECRET_HEADER     = 'x-metis-cron-secret';
    private const FALLBACK_HEADER   = 'x-cron-secret';
    private const SIGNED_INSTALLATION_HEADER = 'x-metis-installation-id';
    private const SIGNED_SIGNATURE_HEADER = 'x-metis-signature';
    private const SIGNED_TIMESTAMP_HEADER = 'x-metis-timestamp';
    private const SIGNED_NONCE_HEADER = 'x-metis-nonce';
    private const SIGNED_KEY_SHA_HEADER = 'x-metis-key-sha256';
    private const SIGNED_TIMESTAMP_TTL = 300;
    private const SIGNED_NONCE_TTL = 600;
    private const OPERATION         = 'system.cron.execute';
    private const LOCK_TTL          = 900;
    private const DEFAULT_INTERVAL  = 300;
    private const CRON_JOB_TYPE     = 'system.cron.task';
    private const DRAIN_BATCH_LIMIT = 25;
    private const DRAIN_MAX_BATCHES = 4;
    private const INTENSIVE_WINDOW_START_HOUR = 0;
    private const INTENSIVE_WINDOW_END_HOUR = 6;
    private const INTENSIVE_TASKS = [
        'integrity_scan', 'recovery_integrity_check', 'cache_cleanup',
        'data_retention_cleanup', 'security_audit_digest', 'release_update_check',
        'release_auto_update', 'module_compliance_audit',
    ];

    /** @var array<string,array{callback:callable,label:string,interval:int,lock_ttl:int,module:string,intensive:bool}> */
    private static array $tasks = [];
    private static bool $booted = false;
    private static bool $drain_registered = false;

    public static function init(): void {
        if ( self::$booted ) {
            return;
        }

        self::register_worker();
        self::register_policy();
        self::register_task(
            'integrity_scan',
            static function (): array {
                return Metis_Integrity_Manager::scan_and_heal( 'system_cron' );
            },
            [
                'label'    => 'Integrity Scan',
                'interval' => HOUR_IN_SECONDS,
                'lock_ttl' => 20 * MINUTE_IN_SECONDS,
                'module'   => 'core',
            ]
        );

        self::register_task(
            'recovery_integrity_check',
            static function (): array {
                $service = new \Metis\Core\Recovery\PrebootIntegrityService();
                $snapshot = $service->dashboardSnapshot();
                $status = (string) ( $snapshot['status'] ?? 'unknown' );
                return [
                    'status' => $status === 'critical' ? 'failed' : 'ok',
                    'message' => $status === 'critical'
                        ? 'Recovery integrity check detected critical issues.'
                        : 'Recovery integrity check completed.',
                    'snapshot' => [
                        'status' => $status,
                        'manifest' => $snapshot['manifest'] ?? [],
                        'backup' => $snapshot['backup'] ?? [],
                        'git' => $snapshot['git'] ?? [],
                    ],
                ];
            },
            [
                'label'    => 'Recovery Integrity Check',
                'interval' => HOUR_IN_SECONDS,
                'lock_ttl' => 20 * MINUTE_IN_SECONDS,
                'module'   => 'core',
            ]
        );

        self::register_task(
            'cache_cleanup',
            [ self::class, 'run_cache_cleanup' ],
            [
                'label'    => 'Cache Cleanup',
                'interval' => HOUR_IN_SECONDS,
                'lock_ttl' => 10 * MINUTE_IN_SECONDS,
                'module'   => 'core',
            ]
        );

        self::register_task(
            'data_retention_cleanup',
            static function (): array {
                if ( ! \Metis\Core\Application::has_service( 'data_retention' ) ) {
                    \metis_register_core_services();
                }

                if ( ! \function_exists( 'metis_data_retention' ) ) {
                    return [
                        'status'  => 'skipped',
                        'message' => 'Data retention service is not available.',
                    ];
                }

                // Retention is already bounded inside the service. Use the
                // largest safe batch here so a single scheduled run makes
                // meaningful progress against accumulated expired history.
                return \metis_data_retention()->run( [ 'batch_limit' => 10000 ] );
            },
            [
                'label'    => 'Data Retention Cleanup',
                'interval' => DAY_IN_SECONDS,
                'lock_ttl' => 10 * MINUTE_IN_SECONDS,
                'module'   => 'core',
            ]
        );

        self::register_task(
            'security_audit_digest',
            [ self::class, 'run_security_audit_digest' ],
            [
                'label'    => 'Security Audit Digest',
                'interval' => DAY_IN_SECONDS,
                'lock_ttl' => 10 * MINUTE_IN_SECONDS,
                'module'   => 'security',
            ]
        );

        self::register_task(
            'release_update_check',
            static function (): array {
                if ( ! function_exists( 'metis_update_service' ) ) {
                    return [
                        'status'  => 'skipped',
                        'message' => 'Update services are not available.',
                    ];
                }

                try {
                    $result = metis_update_service()->refreshUpdateState( true, 'system_cron' );
                    return [
                        'status' => ! empty( $result['updates_available'] ) ? 'updates_available' : 'current',
                        'message' => 'Core and module updates checked.',
                        'repositories' => (array) ( $result['repositories'] ?? [] ),
                        'core' => (array) ( $result['core'] ?? [] ),
                        'modules' => (array) ( $result['modules'] ?? [] ),
                    ];
                } catch ( \Throwable $exception ) {
                    if ( class_exists( 'Metis_Logger' ) ) {
                        \Metis_Logger::error( 'Scheduled update check failed', [
                            'message' => $exception->getMessage(),
                        ] );
                    }

                    return [
                        'status' => 'failed',
                        'message' => $exception->getMessage(),
                    ];
                }
            },
            [
                'label'    => 'Core + Module Update Check',
                'interval' => 6 * HOUR_IN_SECONDS,
                'lock_ttl' => 30 * MINUTE_IN_SECONDS,
                'module'   => 'core',
            ]
        );

        self::register_task(
            'release_auto_update',
            static function (): array {
                if ( ! function_exists( 'metis_release_auto_update' ) ) {
                    return [
                        'status'  => 'skipped',
                        'message' => 'Release auto-update service is not available.',
                    ];
                }

                return metis_release_auto_update( 'system_cron' );
            },
            [
                'label'    => 'Release Auto Update',
                'interval' => 6 * HOUR_IN_SECONDS,
                'lock_ttl' => 30 * MINUTE_IN_SECONDS,
                'module'   => 'core',
            ]
        );

        self::register_task(
            'module_compliance_audit',
            static function (): array {
                if ( ! function_exists( 'metis_module_compliance_report' ) ) {
                    return [
                        'status'  => 'skipped',
                        'message' => 'Module compliance report service is unavailable.',
                    ];
                }

                $report = (array) metis_module_compliance_report( true );
                $summary = is_array( $report['summary'] ?? null ) ? $report['summary'] : [];
                $failed = (int) ( $summary['failed'] ?? 0 );
                $checked = (int) ( $summary['checked'] ?? 0 );
                $results = is_array( $report['results'] ?? null ) ? $report['results'] : [];
                $failures = array_values(
                    array_filter(
                        $results,
                        static fn ( mixed $row ): bool => is_array( $row ) && (string) ( $row['status'] ?? '' ) === 'failed'
                    )
                );

                if ( $failed > 0 ) {
                    Metis_Logger::error( 'Module compliance audit detected failures', [
                        'checked' => $checked,
                        'failed' => $failed,
                        'failures' => $failures,
                    ] );
                }

                return [
                    'status' => $failed > 0 ? 'failed' : 'ok',
                    'message' => $failed > 0
                        ? sprintf( 'Module compliance audit failed for %d module(s).', $failed )
                        : sprintf( 'All %d modules passed compliance.', $checked ),
                    'summary' => $summary,
                    'failures' => $failures,
                ];
            },
            [
                'label'    => 'Module Compliance Audit',
                'interval' => HOUR_IN_SECONDS,
                'lock_ttl' => 10 * MINUTE_IN_SECONDS,
                'module'   => 'core',
            ]
        );

        self::register_task(
            'drive_listing_sync',
            static function (): array {
                if ( ! \Metis\Core\Application::has_service( 'operations' ) ) {
                    \metis_register_core_services();
                }
                if ( ! \function_exists( 'metis_operations' ) ) {
                    return [
                        'status'  => 'skipped',
                        'message' => 'Operations service is not available.',
                    ];
                }

                $queued = \metis_operations()->queueOperation(
                    'drive.sync',
                    [],
                    [
                        'created_by' => 0,
                        'dedupe_key' => 'operation:drive.sync',
                    ]
                );
                if ( empty( $queued['ok'] ) ) {
                    return [
                        'status'  => 'failed',
                        'message' => (string) ( $queued['message'] ?? 'Drive sync could not be queued.' ),
                    ];
                }

                return [
                    'status'   => ! empty( $queued['duplicate'] ) ? 'duplicate' : 'queued',
                    'message'  => ! empty( $queued['duplicate'] ) ? 'Drive sync is already queued.' : 'Drive sync queued.',
                    'job_id'   => (int) ( $queued['job_id'] ?? 0 ),
                    'job_code' => (string) ( $queued['job_code'] ?? '' ),
                ];
            },
            [
                'label'    => 'Drive Listing Sync',
                'interval' => \function_exists( 'metis_drive_cron_interval' ) ? \metis_drive_cron_interval() : HOUR_IN_SECONDS,
                'lock_ttl' => 20 * MINUTE_IN_SECONDS,
                'module'   => 'drive',
            ]
        );

        self::register_task(
            'background_job_processing',
            static function (): array {
                if ( ! \Metis\Core\Application::has_service( 'jobs' ) ) {
                    \metis_register_core_services();
                }

                return self::drain_job_queue( 'system_cron' );
            },
            [
                'label'    => 'Background Job Processing',
                'interval' => 60,
                'lock_ttl' => 10 * MINUTE_IN_SECONDS,
                'module'   => 'core',
            ]
        );

        self::$booted = true;
    }

    public static function register_task( string $slug, callable $callback, array $config = [] ): void {
        $slug = metis_key_clean( $slug );
        if ( $slug === '' ) {
            return;
        }

        self::$tasks[ $slug ] = [
            'slug'     => $slug,
            'callback' => $callback,
            'label'    => (string) ( $config['label'] ?? ucwords( str_replace( '_', ' ', $slug ) ) ),
            'interval' => self::resolved_interval( $slug, (int) ( $config['interval'] ?? self::DEFAULT_INTERVAL ) ),
            'default_interval' => max( 60, (int) ( $config['interval'] ?? self::DEFAULT_INTERVAL ) ),
            'lock_ttl' => max( 60, (int) ( $config['lock_ttl'] ?? self::LOCK_TTL ) ),
            'module'   => metis_key_clean( (string) ( $config['module'] ?? 'core' ) ),
            'intensive' => ! empty( $config['intensive'] ) || in_array( $slug, self::INTENSIVE_TASKS, true ),
        ];
    }

    public static function endpoint_path(): string {
        return self::ENDPOINT_PATH;
    }

    public static function endpoint_url(): string {
        return metis_home_url( self::ENDPOINT_PATH );
    }

    public static function registered_tasks(): array {
        self::init();

        $tasks = [];
        foreach ( self::$tasks as $slug => $task ) {
            $tasks[ $slug ] = [
                'label'    => $task['label'],
                'interval' => (int) $task['interval'],
                'default_interval' => (int) ( $task['default_interval'] ?? $task['interval'] ),
                'lock_ttl' => (int) $task['lock_ttl'],
                'module'   => $task['module'],
                'intensive' => self::task_is_intensive( $task ),
                'overnight_only' => self::task_is_intensive( $task ),
                'enabled'  => self::task_enabled( $slug ),
            ];
        }

        return $tasks;
    }

    public static function task_enabled( string $slug ): bool {
        $slug = metis_key_clean( $slug );
        if ( $slug === '' ) {
            return false;
        }

        $disabled = Core_Settings_Service::get( 'system_cron_disabled_tasks', [] );
        if ( ! is_array( $disabled ) ) {
            $disabled = [];
        }

        return ! in_array( $slug, array_map( 'metis_key_clean', $disabled ), true );
    }

    public static function configured_secret_masked(): string {
        $secret = self::configured_secret();
        if ( $secret === '' ) {
            return '';
        }

        if ( strlen( $secret ) <= 8 ) {
            return str_repeat( '•', strlen( $secret ) );
        }

        return substr( $secret, 0, 4 ) . str_repeat( '•', max( 8, strlen( $secret ) - 8 ) ) . substr( $secret, -4 );
    }

    public static function matches_request( Metis_Http_Request $request ): bool {
        $path = rtrim( $request->path(), '/' );
        if ( $path === '' ) {
            $path = '/';
        }

        if ( $path === self::ENDPOINT_PATH ) {
            return true;
        }

        // Tolerate duplicated canonical paths from misconfigured schedulers.
        if ( $path === self::ENDPOINT_PATH . self::ENDPOINT_PATH ) {
            return true;
        }

        return false;
    }

    public static function authorize_request( Metis_Http_Request $request ): array {
        self::register_policy();

        $context = self::authorize_with_secret( $request );
        if ( $context === null ) {
            $context = self::authorize_with_update_server_signature( $request );
        }

        if ( $context === null ) {
            if ( self::configured_secret() === '' && self::expected_update_server_installation_id() === '' ) {
                throw new Metis_Security_Enclave_Exception(
                    'System cron secret is not configured.',
                    'cron_secret_missing',
                    503
                );
            }

            throw new Metis_Security_Enclave_Exception(
                'Invalid cron scheduler secret.',
                'invalid_cron_secret',
                403
            );
        }

        metis_security_enclave()->execute(
            self::OPERATION,
            $context,
            static function () {
                return true;
            }
        );

        return $context;
    }

    public static function handle_request( Metis_Http_Request $request ): Metis_Http_Response {
        $input      = $request->input();
        $request_id = metis_audit_request_id();
        $auth_source = metis_key_clean( (string) $request->attribute( 'system_cron_auth_source', '' ) );
        $update_server_response = self::handle_update_server_admin_request( $input, $request_id, $auth_source );
        if ( $update_server_response !== null ) {
            $queued_operation = is_array( $update_server_response['queued'] ?? null )
                ? (array) $update_server_response['queued']
                : [];
            if ( (string) ( $update_server_response['mode'] ?? '' ) === 'backup_remediation'
                && ! empty( $queued_operation['ok'] ) ) {
                // The update server is an operator-initiated recovery path.
                // Start its queued worker after the response, rather than
                // waiting for the next scheduled cron tick.
                self::register_post_response_drain( $request_id );
            }

            $success = self::update_server_response_success( $update_server_response );
            $status = $success ? 200 : 207;
            if ( (string) ( $update_server_response['status'] ?? '' ) === 'failed' ) {
                $status = 500;
            }

            return Metis_Http_Response::json(
                [
                    'success' => $success,
                    'data' => $update_server_response,
                ],
                $status,
                [
                    'Cache-Control' => 'no-store, no-cache, must-revalidate, max-age=0',
                    'X-Metis-Request-Id' => $request_id,
                ]
            );
        }

        $force_all  = ! empty( $input['force'] );
        $trigger    = metis_key_clean( (string) ( $input['trigger'] ?? 'cloudflare_worker' ) );
        $selected   = self::normalize_requested_tasks( $input['tasks'] ?? [] );
        $results    = self::queue_due_tasks( $selected, $force_all, $trigger, $request_id );

        if ( ! empty( $results['summary']['queued'] ) || ! empty( $results['summary']['duplicate'] ) ) {
            self::register_post_response_drain( $request_id );
        }

        $status = ( ! empty( $results['summary']['queued'] ) || ! empty( $results['summary']['duplicate'] ) ) ? 202 : 200;
        if ( ! empty( $results['summary']['failed'] ) ) {
            $status = 207;
        }

        return Metis_Http_Response::json(
            [
                'success' => empty( $results['summary']['failed'] ),
                'data'    => $results,
            ],
            $status,
            [
                'Cache-Control'     => 'no-store, no-cache, must-revalidate, max-age=0',
                'X-Metis-Request-Id' => $request_id,
            ]
        );
    }

    private static function handle_update_server_admin_request( array $input, string $request_id, string $auth_source = '' ): ?array {
        $mode = trim( (string) ( $input['update_server_mode'] ?? '' ) );
        if ( $mode === '' ) {
            return null;
        }

        if ( ! in_array( $auth_source, [ 'update_server', 'shared_secret' ], true ) ) {
            return [
                'mode' => $mode,
                'status' => 'failed',
                'message' => 'Update-server administrative actions require authenticated cron access.',
            ];
        }

        $trigger = metis_key_clean( (string) ( $input['trigger'] ?? 'update_server_admin' ) );
        return match ( $mode ) {
            'diagnostics_snapshot' => self::build_update_server_diagnostics_snapshot( $trigger, $request_id ),
            'final_recovery_attempt' => self::run_update_server_final_recovery( $trigger, $request_id ),
            'backup_remediation' => self::run_update_server_backup_remediation( $trigger, $request_id ),
            default => [
                'mode' => $mode,
                'status' => 'failed',
                'message' => 'Unknown update-server admin request.',
                'generated_at' => gmdate( 'c' ),
            ],
        };
    }

    private static function build_update_server_diagnostics_snapshot( string $trigger, string $request_id ): array {
        self::init();

        $version = \Metis\Core\Application::has_service( 'system_version' )
            ? (array) \Metis\Core\Application::service( 'system_version' )->current()
            : [];
        $release = \Metis\Core\Application::has_service( 'release' )
            ? (array) \Metis\Core\Application::service( 'release' )->status( false )
            : [];
        $queue = \Metis\Core\Application::has_service( 'operations' )
            ? (array) \Metis\Core\Application::service( 'operations' )->queueSummary()
            : [];
        $integrity = \Metis\Core\Application::has_service( 'integrity_service' )
            ? (array) \Metis\Core\Application::service( 'integrity_service' )->verifyBaseline()
            : [];
        $recovery = ( new \Metis\Core\Recovery\PrebootIntegrityService() )->dashboardSnapshot();

        $update_state = [];
        if ( function_exists( 'metis_update_service' ) ) {
            try {
                $update_state = (array) metis_update_service()->refreshUpdateState( true, 'system_cron' );
            } catch ( \Throwable $throwable ) {
                $update_state = [
                    'status' => 'failed',
                    'message' => $throwable->getMessage(),
                ];
            }
        }

        $module_compliance = [];
        if ( function_exists( 'metis_module_compliance_report' ) ) {
            try {
                $module_compliance = (array) metis_module_compliance_report( true );
            } catch ( \Throwable $throwable ) {
                $module_compliance = [
                    'status' => 'failed',
                    'message' => $throwable->getMessage(),
                ];
            }
        }

        $backup = [
            'pause_status' => function_exists( 'metis_backup_pause_status' ) ? (array) metis_backup_pause_status() : [],
            'runs' => function_exists( 'metis_backup_list_runs' ) ? (array) metis_backup_list_runs( 3 ) : [],
        ];
        $findings = self::build_update_server_findings( $release, $queue, $integrity, $recovery, $update_state, $module_compliance, $backup );
        $highest = self::highest_update_server_severity( $findings );

        return [
            'mode' => 'diagnostics_snapshot',
            'status' => $highest,
            'message' => $highest === 'ok'
                ? 'No critical recovery blockers were detected.'
                : 'Diagnostics found conditions that may require recovery review.',
            'generated_at' => gmdate( 'c' ),
            'request_id' => $request_id,
            'trigger' => $trigger,
            'system' => [
                'metis_version' => (string) ( $version['metis_version'] ?? '' ),
                'build' => (string) ( $version['build'] ?? '' ),
                'php_version' => PHP_VERSION,
                'release' => [
                    'status' => (string) ( $release['status'] ?? '' ),
                    'installed_version' => (string) ( $release['installed_version'] ?? '' ),
                    'installed_tag' => (string) ( $release['installed_tag'] ?? '' ),
                    'latest_tag' => (string) ( $release['latest']['tag'] ?? '' ),
                    'update_available' => ! empty( $release['update_available'] ),
                    'last_checked_at' => (string) ( $release['last_checked_at'] ?? '' ),
                ],
                'queue_summary' => $queue,
            ],
            'integrity' => $integrity,
            'recovery' => $recovery,
            'backup' => $backup,
            'updates' => $update_state,
            'module_compliance' => $module_compliance,
            'findings' => $findings,
            'finding_count' => count( $findings ),
        ];
    }

    private static function run_update_server_final_recovery( string $trigger, string $request_id ): array {
        $repair = function_exists( 'metis_self_healing_service' )
            ? (array) metis_self_healing_service()->repairSystem( $trigger !== '' ? $trigger : 'update_server_manual_recovery' )
            : [
                'status' => 'unavailable',
                'message' => 'Self-healing service is unavailable.',
            ];

        $postcheck = self::build_update_server_diagnostics_snapshot( 'update_server_recovery_postcheck', $request_id );
        $repair_status = trim( (string) ( $repair['status'] ?? '' ) );
        $status = in_array( $repair_status, [ 'pass', 'warning', 'recovered', 'completed', 'ok' ], true )
            ? (string) ( $postcheck['status'] ?? 'ok' )
            : 'critical';

        return [
            'mode' => 'final_recovery_attempt',
            'status' => $status,
            'message' => $repair_status !== ''
                ? 'Final recovery attempt finished with status [' . $repair_status . '].'
                : 'Final recovery attempt completed.',
            'generated_at' => gmdate( 'c' ),
            'request_id' => $request_id,
            'trigger' => $trigger,
            'repair' => $repair,
            'postcheck' => $postcheck,
        ];
    }

    private static function run_update_server_backup_remediation( string $trigger, string $request_id ): array {
        if ( ! \Metis\Core\Application::has_service( 'operations' ) ) {
            metis_register_core_services();
        }

        $queued = \Metis\Core\Application::service( 'operations' )->queueOperation(
            'backup.run',
            [],
            [
                'created_by' => 0,
                'dedupe_key' => 'operation:backup.run:update-server-remediation',
            ]
        );
        $postcheck = self::build_update_server_diagnostics_snapshot( 'update_server_backup_postcheck', $request_id );

        return [
            'mode' => 'backup_remediation',
            // Queueing is the only synchronous outcome of this request. The
            // post-check intentionally still reports the pre-existing failed
            // backup state until the worker has run, so it must not turn a
            // successfully queued remediation into a failed API response.
            'status' => ! empty( $queued['ok'] ) ? 'queued' : 'failed',
            'message' => ! empty( $queued['ok'] )
                ? 'Backup remediation queued successfully. The installation will report the completed result after its worker runs.'
                : 'Backup remediation could not be queued.',
            'generated_at' => gmdate( 'c' ),
            'request_id' => $request_id,
            'trigger' => $trigger,
            'queued' => $queued,
            'postcheck' => $postcheck,
        ];
    }

    private static function build_update_server_findings(
        array $release,
        array $queue,
        array $integrity,
        array $recovery,
        array $update_state,
        array $module_compliance,
        array $backup = []
    ): array {
        $findings = [];

        if ( ! empty( $release['update_available'] ) ) {
            $findings[] = [
                'severity' => 'info',
                'title' => 'Trusted release update available',
                'summary' => 'A newer trusted release is available for this installation.',
            ];
        }

        $queue_failed = (int) ( $queue['failed_count'] ?? 0 );
        if ( $queue_failed > 0 ) {
            $findings[] = [
                'severity' => 'warning',
                'title' => 'Queued work has failures',
                'summary' => sprintf( '%d queued operation(s) are currently marked failed.', $queue_failed ),
            ];
        }

        $integrity_ok = $integrity['ok'] ?? null;
        if ( $integrity_ok === false ) {
            $findings[] = [
                'severity' => 'critical',
                'title' => 'Integrity verification is blocking',
                'summary' => trim( (string) ( $integrity['message'] ?? 'Integrity verification did not pass.' ) ),
            ];
        }

        $recovery_status = trim( (string) ( $recovery['status'] ?? '' ) );
        if ( in_array( $recovery_status, [ 'critical', 'maintenance', 'failed' ], true ) ) {
            $findings[] = [
                'severity' => 'critical',
                'title' => 'Recovery state needs intervention',
                'summary' => 'Recovery integrity reported a critical or failed state.',
            ];
        }

        $module_failed = (int) ( $module_compliance['summary']['failed'] ?? 0 );
        if ( $module_failed > 0 ) {
            $findings[] = [
                'severity' => 'warning',
                'title' => 'Module compliance failures detected',
                'summary' => sprintf( '%d module(s) failed compliance checks.', $module_failed ),
            ];
        }

        if ( (string) ( $update_state['status'] ?? '' ) === 'failed' ) {
            $findings[] = [
                'severity' => 'warning',
                'title' => 'Update refresh failed',
                'summary' => trim( (string) ( $update_state['message'] ?? 'Update state refresh could not complete.' ) ),
            ];
        }

        $pause_status = is_array( $backup['pause_status'] ?? null ) ? (array) $backup['pause_status'] : [];
        $latest_backup = is_array( $backup['runs'][0] ?? null ) ? (array) $backup['runs'][0] : [];
        if ( ! empty( $pause_status['paused'] ) ) {
            $findings[] = [
                'severity' => ! empty( $pause_status['escalated'] ) ? 'critical' : 'warning',
                'title' => ! empty( $pause_status['escalated'] ) ? 'Backups require manual remediation' : 'Backups are paused pending retry',
                'summary' => trim( (string) ( $pause_status['reason'] ?? 'Backup automation is paused.' ) ),
            ];
        } elseif ( in_array( (string) ( $latest_backup['status'] ?? '' ), [ 'failed', 'error' ], true ) ) {
            $findings[] = [
                'severity' => 'warning',
                'title' => 'Latest backup run failed',
                'summary' => trim( (string) ( $latest_backup['last_error'] ?? 'The most recent backup did not complete.' ) ),
            ];
        }

        return $findings;
    }

    private static function highest_update_server_severity( array $findings ): string {
        $order = [ 'ok' => 0, 'info' => 1, 'warning' => 2, 'critical' => 3 ];
        $highest = 'ok';
        foreach ( $findings as $finding ) {
            $severity = trim( (string) ( $finding['severity'] ?? 'info' ) );
            if ( ( $order[ $severity ] ?? 0 ) > ( $order[ $highest ] ?? 0 ) ) {
                $highest = $severity;
            }
        }

        return $highest;
    }

    private static function update_server_response_success( array $payload ): bool {
        $status = trim( (string) ( $payload['status'] ?? '' ) );
        return ! in_array( $status, [ 'failed', 'critical' ], true );
    }

    public static function queue_due_tasks( array $selected = [], bool $force_all = false, string $trigger = 'manual', string $request_id = '', bool $ignore_disabled = false ): array {
        self::init();

        $request_id = $request_id !== '' ? $request_id : metis_audit_request_id();
        $summary = [
            'queued'    => [],
            'duplicate' => [],
            'skipped'   => [],
            'failed'    => [],
        ];
        $results = [];
        $task_slugs = empty( $selected ) ? array_keys( self::$tasks ) : $selected;
        $now = time();

        foreach ( $task_slugs as $slug ) {
            if ( ! isset( self::$tasks[ $slug ] ) ) {
                $summary['skipped'][] = $slug;
                $results[ $slug ] = [
                    'status'  => 'skipped',
                    'message' => 'Task is not registered.',
                ];
                continue;
            }

            if ( ! $ignore_disabled && ! self::task_enabled( $slug ) ) {
                $summary['skipped'][] = $slug;
                $results[ $slug ] = [
                    'status'  => 'skipped',
                    'message' => 'Task is disabled.',
                ];
                continue;
            }

            $task  = self::$tasks[ $slug ];
            $state = self::task_state( $slug );

            if ( ! $force_all && self::should_defer_intensive_task( $task, $trigger, $now ) ) {
                $summary['skipped'][] = $slug;
                $results[ $slug ] = [
                    'status'   => 'skipped',
                    'message'  => 'Intensive task deferred until the midnight maintenance window.',
                    'next_due' => self::next_intensive_window_timestamp( $now ),
                ];
                continue;
            }

            if ( ! $force_all && ! self::task_is_due( $state, (int) $task['interval'], $now, $task ) ) {
                $summary['skipped'][] = $slug;
                $results[ $slug ] = [
                    'status'   => 'skipped',
                    'message'  => 'Task is not due.',
                    'next_due' => self::next_due_timestamp( $state, (int) $task['interval'], $now, $task ),
                ];
                continue;
            }

            $queued = metis_job_queue()->enqueue(
                self::CRON_JOB_TYPE,
                [
                    'task'            => $slug,
                    'force'           => $force_all,
                    'trigger'         => $trigger,
                    'request_id'      => $request_id,
                    'ignore_disabled' => $ignore_disabled,
                ],
                [
                    'queue'       => 'system',
                    // Make the nightly backup claim ahead of other cron
                    // tasks so updates have a fresh restore point first.
                    'priority'    => $slug === 'system_backup_snapshot' ? 20 : 10,
                    'max_attempts'=> 3,
                    'dedupe_key'  => 'system_cron_task:' . $slug,
                ]
            );

            if ( empty( $queued['ok'] ) ) {
                $summary['failed'][] = $slug;
                $results[ $slug ] = [
                    'status'  => 'failed',
                    'message' => (string) ( $queued['message'] ?? 'Failed to queue task.' ),
                ];
                continue;
            }

            if ( ! empty( $queued['duplicate'] ) ) {
                $summary['duplicate'][] = $slug;
                $results[ $slug ] = [
                    'status'   => 'queued',
                    'message'  => 'Task is already queued.',
                    'job_id'   => (int) ( $queued['job_id'] ?? 0 ),
                    'job_code' => (string) ( $queued['job_code'] ?? '' ),
                ];
                continue;
            }

            $summary['queued'][] = $slug;
            $results[ $slug ] = [
                'status'   => 'queued',
                'message'  => 'Task queued for asynchronous execution.',
                'job_id'   => (int) ( $queued['job_id'] ?? 0 ),
                'job_code' => (string) ( $queued['job_code'] ?? '' ),
            ];
        }

        Metis_Logger::info( 'System cron tasks queued', [
            'trigger'    => $trigger,
            'request_id' => $request_id,
            'summary'    => $summary,
        ] );

        return [
            'trigger'    => $trigger,
            'request_id' => $request_id,
            'summary'    => $summary,
            'results'    => $results,
        ];
    }

    public static function run_due_tasks( array $selected = [], bool $force_all = false, string $trigger = 'manual', string $request_id = '', bool $ignore_disabled = false ): array {
        self::init();

        $request_id = $request_id !== '' ? $request_id : metis_audit_request_id();
        $summary = [
            'ran'     => [],
            'skipped' => [],
            'failed'  => [],
        ];
        $results = [];
        $task_slugs = empty( $selected ) ? array_keys( self::$tasks ) : $selected;

        foreach ( $task_slugs as $slug ) {
            if ( ! isset( self::$tasks[ $slug ] ) ) {
                $summary['skipped'][] = $slug;
                $results[ $slug ] = [
                    'status'  => 'skipped',
                    'message' => 'Task is not registered.',
                ];
                continue;
            }

            if ( ! $ignore_disabled && ! self::task_enabled( $slug ) ) {
                $summary['skipped'][] = $slug;
                $results[ $slug ] = [
                    'status'  => 'skipped',
                    'message' => 'Task is disabled.',
                ];
                continue;
            }

            $result = self::run_task( $slug, $force_all, $trigger, $request_id );
            $results[ $slug ] = $result;

            if ( $result['status'] === 'ok' ) {
                $summary['ran'][] = $slug;
            } elseif ( $result['status'] === 'failed' ) {
                $summary['failed'][] = $slug;
            } else {
                $summary['skipped'][] = $slug;
            }
        }

        Metis_Logger::info( 'System cron runner completed', [
            'trigger'    => $trigger,
            'request_id' => $request_id,
            'summary'    => $summary,
        ] );

        return [
            'trigger'    => $trigger,
            'request_id' => $request_id,
            'summary'    => $summary,
            'results'    => $results,
        ];
    }

    public static function run_task_now( string $slug, string $trigger = 'manual_ui', string $request_id = '' ): array {
        $slug = metis_key_clean( $slug );
        if ( $slug === '' ) {
            return [
                'summary' => [
                    'ran' => [],
                    'skipped' => [ $slug ],
                    'failed' => [],
                ],
                'results' => [
                    $slug => [
                        'status' => 'skipped',
                        'message' => 'Task slug is invalid.',
                    ],
                ],
                'trigger' => $trigger,
                'request_id' => $request_id !== '' ? $request_id : metis_audit_request_id(),
            ];
        }

        return self::run_due_tasks( [ $slug ], true, $trigger, $request_id, true );
    }

    public static function run_queued_task( array $payload = [] ): array {
        self::init();

        $slug            = metis_key_clean( (string) ( $payload['task'] ?? '' ) );
        $force           = ! empty( $payload['force'] );
        $trigger         = metis_key_clean( (string) ( $payload['trigger'] ?? 'queued' ) );
        $request_id      = (string) ( $payload['request_id'] ?? metis_audit_request_id() );
        $ignore_disabled = ! empty( $payload['ignore_disabled'] );

        if ( $slug === '' ) {
            throw new RuntimeException( 'Queued cron task is missing a slug.' );
        }

        $result = self::run_due_tasks( [ $slug ], $force, $trigger, $request_id, $ignore_disabled );
        return (array) ( $result['results'][ $slug ] ?? [ 'status' => 'skipped', 'message' => 'Task result missing.' ] );
    }

    public static function drain_job_queue( string $worker_name = 'system_cron_async' ): array {
        self::init();

        if ( function_exists( 'metis_register_core_services' ) ) {
            metis_register_core_services();
        }

        if ( class_exists( \Metis\Core\Application::class ) && \Metis\Core\Application::has_service( 'operations' ) ) {
            \Metis\Core\Application::service( 'operations' );
        }

        $summary = [
            'processed' => 0,
            'completed' => 0,
            'failed'    => 0,
            'batches'   => 0,
        ];

        for ( $i = 0; $i < self::DRAIN_MAX_BATCHES; $i++ ) {
            $batch = metis_job_queue()->process( self::DRAIN_BATCH_LIMIT, $worker_name );
            $summary['batches']++;
            $summary['processed'] += (int) ( $batch['processed'] ?? 0 );
            $summary['completed'] += (int) ( $batch['completed'] ?? 0 );
            $summary['failed'] += (int) ( $batch['failed'] ?? 0 );

            if ( (int) ( $batch['processed'] ?? 0 ) < 1 ) {
                break;
            }
        }

        return $summary;
    }

    private static function run_task( string $slug, bool $force, string $trigger, string $request_id ): array {
        $task  = self::$tasks[ $slug ];
        $state = self::task_state( $slug );
        $now   = time();

        if ( ! $force && self::should_defer_intensive_task( $task, $trigger, $now ) ) {
            return [
                'status'   => 'skipped',
                'message'  => 'Intensive task deferred until the midnight maintenance window.',
                'next_due' => self::next_intensive_window_timestamp( $now ),
            ];
        }

        if ( ! $force && ! self::task_is_due( $state, (int) $task['interval'], $now, $task ) ) {
            return [
                'status'   => 'skipped',
                'message'  => 'Task is not due.',
                'next_due' => self::next_due_timestamp( $state, (int) $task['interval'], $now, $task ),
            ];
        }

        if ( ! self::acquire_lock( $slug, (int) $task['lock_ttl'] ) ) {
            return [
                'status'  => 'skipped',
                'message' => 'Task is already running.',
            ];
        }

        $started_at = metis_current_time( 'mysql' );
        self::update_task_state( $slug, array_merge( $state, [
            'last_started_at' => $started_at,
            'last_request_id' => $request_id,
            'last_trigger'    => $trigger,
            'running'         => true,
        ] ) );

        Metis_Logger::info( 'Cron task started', [
            'task'       => $slug,
            'label'      => $task['label'],
            'trigger'    => $trigger,
            'request_id' => $request_id,
        ] );

        try {
            $payload = call_user_func( $task['callback'] );
            $result_payload = is_array( $payload ) ? $payload : [ 'result' => $payload ];
            $result_status = strtolower( trim( (string) ( $result_payload['status'] ?? 'ok' ) ) );
            $task_failed = in_array( $result_status, [ 'failed', 'error' ], true );

            self::update_task_state( $slug, array_merge( self::task_state( $slug ), [
                'last_finished_at' => metis_current_time( 'mysql' ),
                'last_status'      => $task_failed ? 'failed' : 'ok',
                'running'          => false,
                'last_error'       => $task_failed ? (string) ( $result_payload['message'] ?? 'Task reported failure.' ) : '',
            ] ) );

            if ( $task_failed ) {
                metis_audit_log_security( 'system_cron_task_failed', [
                    'module'     => $task['module'],
                    'request_id' => $request_id,
                    'severity'   => 'warning',
                    'outcome'    => 'error',
                    'resource'   => [
                        'type'  => 'cron_task',
                        'id'    => $slug,
                        'label' => $task['label'],
                    ],
                    'context'    => [
                        'trigger' => $trigger,
                        'result'  => $result_payload,
                    ],
                ] );

                Metis_Logger::error( 'Cron task reported failure', [
                    'task'       => $slug,
                    'request_id' => $request_id,
                    'result'     => $result_payload,
                ] );

                return [
                    'status' => 'failed',
                    'result' => $result_payload,
                    'message' => (string) ( $result_payload['message'] ?? 'Task reported failure.' ),
                ];
            }

            if ( self::verbose_operational_audit_enabled() ) {
                metis_audit_log_activity( 'system_cron_task_completed', [
                    'module'     => $task['module'],
                    'request_id' => $request_id,
                    'resource'   => [
                        'type'  => 'cron_task',
                        'id'    => $slug,
                        'label' => $task['label'],
                    ],
                    'context'    => [
                        'trigger' => $trigger,
                        'result'  => $result_payload,
                    ],
                ] );
            }

            Metis_Logger::info( 'Cron task completed', [
                'task'       => $slug,
                'request_id' => $request_id,
                'result'     => $result_payload,
            ] );

            return [
                'status' => 'ok',
                'result' => $result_payload,
            ];
        } catch ( Throwable $e ) {
            self::update_task_state( $slug, array_merge( self::task_state( $slug ), [
                'last_finished_at' => metis_current_time( 'mysql' ),
                'last_status'      => 'failed',
                'running'          => false,
                'last_error'       => 'Task failed. Review logs for details.',
            ] ) );

            metis_audit_log_security( 'system_cron_task_failed', [
                'module'     => $task['module'],
                'request_id' => $request_id,
                'severity'   => 'warning',
                'outcome'    => 'error',
                'resource'   => [
                    'type'  => 'cron_task',
                    'id'    => $slug,
                    'label' => $task['label'],
                ],
                'context'    => [
                    'trigger' => $trigger,
                    'error'   => $e->getMessage(),
                ],
            ] );

            Metis_Logger::error( 'Cron task failed', [
                'task'       => $slug,
                'request_id' => $request_id,
                'error'      => $e->getMessage(),
                'file'       => $e->getFile(),
                'line'       => $e->getLine(),
            ] );

            return [
                'status'  => 'failed',
                'message' => 'Task failed. Review logs for details.',
            ];
        } finally {
            self::release_lock( $slug );
        }
    }

    private static function register_worker(): void {
        if ( ! function_exists( 'metis_job_workers' ) ) {
            return;
        }

        metis_job_workers()->register(
            self::CRON_JOB_TYPE,
            static function ( array $payload ): array {
                return self::run_queued_task( $payload );
            }
        );
    }

    private static function register_post_response_drain( string $request_id ): void {
        if ( self::$drain_registered ) {
            return;
        }

        self::$drain_registered = true;

        register_shutdown_function(
            static function () use ( $request_id ): void {
                self::finish_client_response();

                $summary = self::drain_job_queue();
                Metis_Logger::info( 'Async cron drain completed', [
                    'request_id' => $request_id,
                    'summary'    => $summary,
                ] );
            }
        );
    }

    private static function finish_client_response(): void {
        if ( function_exists( 'session_status' ) && session_status() === PHP_SESSION_ACTIVE ) {
            session_write_close();
        }

        ignore_user_abort( true );

        if ( function_exists( 'fastcgi_finish_request' ) ) {
            fastcgi_finish_request();
            return;
        }

        while ( ob_get_level() > 0 ) {
            @ob_end_flush();
        }

        @flush();
    }

    private static function task_is_due( array $state, int $interval, int $now, array $task = [] ): bool {
        $last_finished = self::timestamp_from_state( $state['last_finished_at'] ?? '' );
        $run_time = self::task_run_time( $task );
        if ( $run_time !== null && $interval % DAY_IN_SECONDS === 0 ) {
            $timezone = function_exists( 'metis_runtime_timezone' ) ? metis_runtime_timezone() : new DateTimeZone( 'UTC' );
            $local_now = ( new DateTimeImmutable( '@' . $now ) )->setTimezone( $timezone );
            $target_today = $local_now->setTime( $run_time[0], $run_time[1], 0 );
            if ( $local_now < $target_today ) {
                return false;
            }
            if ( $last_finished > 0 ) {
                $last_local = ( new DateTimeImmutable( '@' . $last_finished ) )->setTimezone( $timezone );
                if ( $last_local->format( 'Y-m-d' ) === $local_now->format( 'Y-m-d' ) && $last_local >= $target_today ) {
                    return false;
                }
            }
            return true;
        }
        if ( $last_finished < 1 ) {
            return true;
        }

        return ( $now - $last_finished ) >= $interval;
    }

    private static function should_defer_intensive_task( array $task, string $trigger, int $now ): bool {
        if ( ! self::task_is_intensive( $task ) || in_array( $trigger, [ 'manual', 'manual_ui', 'hermes', 'admin_ui' ], true ) ) {
            return false;
        }

        $hour = (int) ( new DateTimeImmutable( '@' . $now ) )
            ->setTimezone( function_exists( 'metis_runtime_timezone' ) ? metis_runtime_timezone() : new DateTimeZone( 'UTC' ) )
            ->format( 'G' );

        return $hour < self::INTENSIVE_WINDOW_START_HOUR || $hour >= self::INTENSIVE_WINDOW_END_HOUR;
    }

    private static function task_is_intensive( array $task ): bool {
        $slug = metis_key_clean( (string) ( $task['slug'] ?? '' ) );
        $overrides = Core_Settings_Service::get( 'system_cron_overnight_tasks', [] );
        if ( is_array( $overrides ) && $slug !== '' && array_key_exists( $slug, $overrides ) ) {
            return ! empty( $overrides[ $slug ] );
        }

        return ! empty( $task['intensive'] );
    }

    private static function next_intensive_window_timestamp( int $now ): int {
        $timezone = function_exists( 'metis_runtime_timezone' ) ? metis_runtime_timezone() : new DateTimeZone( 'UTC' );
        $local = ( new DateTimeImmutable( '@' . $now ) )->setTimezone( $timezone );
        if ( (int) $local->format( 'G' ) < self::INTENSIVE_WINDOW_START_HOUR ) {
            return $local->setTime( self::INTENSIVE_WINDOW_START_HOUR, 0, 0 )->getTimestamp();
        }

        return $local->modify( '+1 day' )->setTime( self::INTENSIVE_WINDOW_START_HOUR, 0, 0 )->getTimestamp();
    }

    private static function next_due_timestamp( array $state, int $interval, int $now, array $task = [] ): int {
        $run_time = self::task_run_time( $task );
        if ( $run_time !== null && $interval % DAY_IN_SECONDS === 0 ) {
            $timezone = function_exists( 'metis_runtime_timezone' ) ? metis_runtime_timezone() : new DateTimeZone( 'UTC' );
            $local_now = ( new DateTimeImmutable( '@' . $now ) )->setTimezone( $timezone );
            $candidate = $local_now->setTime( $run_time[0], $run_time[1], 0 );
            if ( $local_now >= $candidate ) {
                $candidate = $candidate->modify( '+1 day' );
            }
            return $candidate->getTimestamp();
        }
        $last_finished = self::timestamp_from_state( $state['last_finished_at'] ?? '' );
        if ( $last_finished < 1 ) {
            return $now;
        }

        return $last_finished + $interval;
    }

    private static function task_run_time( array $task ): ?array {
        $slug = metis_key_clean( (string) ( $task['slug'] ?? '' ) );
        $run_times = Core_Settings_Service::get( 'system_cron_task_run_times', [] );
        $value = is_array( $run_times ) && $slug !== '' ? trim( (string) ( $run_times[ $slug ] ?? '' ) ) : '';
        if ( ! preg_match( '/^(?:[01]\d|2[0-3]):[0-5]\d$/', $value ) ) {
            return null;
        }
        return [ (int) substr( $value, 0, 2 ), (int) substr( $value, 3, 2 ) ];
    }

    private static function task_state( string $slug ): array {
        $state = metis_get_option( 'metis_cron_task_state_' . $slug, [] );
        return is_array( $state ) ? $state : [];
    }

    private static function update_task_state( string $slug, array $state ): void {
        metis_update_option( 'metis_cron_task_state_' . $slug, $state, false );
    }

    private static function acquire_lock( string $slug, int $ttl ): bool {
        $key = 'metis_cron_lock_' . $slug;
        if ( metis_get_transient( $key ) ) {
            return false;
        }

        metis_set_transient( $key, 1, $ttl );
        return true;
    }

    private static function release_lock( string $slug ): void {
        metis_delete_transient( 'metis_cron_lock_' . $slug );
    }

    private static function timestamp_from_state( mixed $value ): int {
        if ( ! is_string( $value ) || $value === '' ) {
            return 0;
        }

        $timestamp = strtotime( $value );
        return $timestamp !== false ? (int) $timestamp : 0;
    }

    private static function request_context( Metis_Http_Request $request, string $source = 'shared_secret' ): array {
        $actor_id = $source === 'update_server' ? 'update-server' : 'cloudflare-worker';
        $permissions = $source === 'update_server' ? [ 'cron', 'update_server' ] : [ 'cron' ];
        $installation_id = $source === 'update_server'
            ? trim( $request->header( self::SIGNED_INSTALLATION_HEADER ) )
            : '';

        return [
            'actor' => [
                'id'          => $actor_id,
                'roles'       => [ 'system' ],
                'permissions' => $permissions,
                'session_id'  => '',
            ],
            'meta' => [
                'ip'         => metis_audit_ip_address(),
                'user_agent' => metis_audit_user_agent(),
                'request_id' => metis_audit_request_id(),
                'auth_source' => $source,
                'installation_id' => $installation_id,
            ],
            'input' => $request->input(),
        ];
    }

    private static function authorize_with_secret( Metis_Http_Request $request ): ?array {
        $secret = self::configured_secret();
        $provided = self::request_secret( $request );

        if ( $secret === '' || $provided === '' ) {
            return null;
        }

        if ( ! hash_equals( $secret, $provided ) ) {
            return null;
        }

        return self::request_context( $request, 'shared_secret' );
    }

    private static function authorize_with_update_server_signature( Metis_Http_Request $request ): ?array {
        $installation_id = trim( $request->header( self::SIGNED_INSTALLATION_HEADER ) );
        $signature_base64 = trim( $request->header( self::SIGNED_SIGNATURE_HEADER ) );
        $timestamp = trim( $request->header( self::SIGNED_TIMESTAMP_HEADER ) );
        $nonce = trim( $request->header( self::SIGNED_NONCE_HEADER ) );
        $key_sha = trim( $request->header( self::SIGNED_KEY_SHA_HEADER ) );

        if ( $installation_id === '' || $signature_base64 === '' || $timestamp === '' || $nonce === '' ) {
            return null;
        }

        $expected_installation_id = self::expected_update_server_installation_id();
        $public_key = self::configured_update_server_public_key();
        if ( $expected_installation_id === '' || $public_key === '' ) {
            return null;
        }

        if ( ! hash_equals( $expected_installation_id, $installation_id ) ) {
            return null;
        }

        if ( $key_sha !== '' && ! hash_equals( hash( 'sha256', $public_key ), $key_sha ) ) {
            return null;
        }

        $timestamp_unix = strtotime( $timestamp );
        if ( $timestamp_unix === false || abs( time() - (int) $timestamp_unix ) > self::SIGNED_TIMESTAMP_TTL ) {
            return null;
        }

        $nonce_key = 'system.cron.update_server_nonce.' . hash( 'sha256', $installation_id . '|' . $nonce );
        if ( CacheService::get( $nonce_key ) !== null ) {
            return null;
        }

        $signature = self::decode_signature_header( $signature_base64 );
        if ( $signature === false ) {
            return null;
        }

        $canonical = strtoupper( trim( (string) $request->method() ) ) . "\n"
            . self::ENDPOINT_PATH . "\n"
            . $timestamp . "\n"
            . $nonce . "\n"
            . hash( 'sha256', $request->body() );
        $resource = openssl_pkey_get_public( $public_key );
        if ( $resource === false || openssl_verify( $canonical, $signature, $resource, OPENSSL_ALGO_SHA256 ) !== 1 ) {
            return null;
        }

        CacheService::set( $nonce_key, 1, self::SIGNED_NONCE_TTL );
        return self::request_context( $request, 'update_server' );
    }

    private static function request_secret( Metis_Http_Request $request ): string {
        $secret = trim( $request->header( self::SECRET_HEADER ) );
        if ( $secret !== '' ) {
            return $secret;
        }

        return trim( $request->header( self::FALLBACK_HEADER ) );
    }

    private static function configured_secret(): string {
        if ( defined( 'METIS_CRON_SECRET' ) ) {
            return trim( (string) constant( 'METIS_CRON_SECRET' ) );
        }

        $secret = Core_Settings_Service::get( 'system_cron_secret', '' );
        return is_string( $secret ) ? trim( $secret ) : '';
    }

    private static function decode_signature_header( string $signature ): string|false {
        $decoded = base64_decode( $signature, true );
        if ( $decoded !== false ) {
            return $decoded;
        }

        $normalized = strtr( trim( $signature ), '-_', '+/' );
        $padding = strlen( $normalized ) % 4;
        if ( $padding > 0 ) {
            $normalized .= str_repeat( '=', 4 - $padding );
        }

        return base64_decode( $normalized, true );
    }

    private static function expected_update_server_installation_id(): string {
        if ( ! class_exists( '\Metis\Core\Application' ) || ! \Metis\Core\Application::has_service( 'update_server_identity' ) ) {
            return self::direct_update_server_installation_id();
        }

        try {
            $identity = \Metis\Core\Application::service( 'update_server_identity' );
            if ( ! is_object( $identity ) || ! method_exists( $identity, 'state' ) ) {
                return self::direct_update_server_installation_id();
            }

            $state = (array) $identity->state();
            $installation_id = trim( (string) ( $state['installation_id'] ?? '' ) );
            return $installation_id !== '' ? $installation_id : self::direct_update_server_installation_id();
        } catch ( \Throwable ) {
            return self::direct_update_server_installation_id();
        }
    }

    private static function configured_update_server_public_key(): string {
        if ( ! class_exists( '\Metis\Core\Application' ) || ! \Metis\Core\Application::has_service( 'update_server_client' ) ) {
            return self::direct_update_server_public_key();
        }

        try {
            $client = \Metis\Core\Application::service( 'update_server_client' );
            if ( ! is_object( $client ) || ! method_exists( $client, 'settings' ) ) {
                return self::direct_update_server_public_key();
            }

            $settings = (array) $client->settings();
            $public_key = trim( (string) ( $settings['server_public_key'] ?? '' ) );
            return $public_key !== '' ? $public_key : self::direct_update_server_public_key();
        } catch ( \Throwable ) {
            return self::direct_update_server_public_key();
        }
    }

    private static function direct_update_server_installation_id(): string {
        $path = self::project_root_path() . '/storage/private-records/update-server/identity.json';
        if ( ! is_file( $path ) || ! is_readable( $path ) ) {
            return '';
        }

        $raw = @file_get_contents( $path );
        if ( ! is_string( $raw ) || trim( $raw ) === '' ) {
            return '';
        }

        $decoded = json_decode( $raw, true );
        return is_array( $decoded ) ? trim( (string) ( $decoded['installation_id'] ?? '' ) ) : '';
    }

    private static function direct_update_server_public_key(): string {
        $path = self::system_root_path() . '/config/update.php';
        if ( ! is_file( $path ) || ! is_readable( $path ) ) {
            return '';
        }

        $config = require $path;
        if ( ! is_array( $config ) ) {
            return '';
        }

        $server = is_array( $config['update_server'] ?? null ) ? (array) $config['update_server'] : [];
        return trim( (string) ( $server['server_public_key'] ?? '' ) );
    }

    private static function system_root_path(): string {
        return dirname( __DIR__, 4 );
    }

    private static function project_root_path(): string {
        return dirname( __DIR__, 5 );
    }

    private static function register_policy(): void {
        $enclave = metis_security_enclave();
        if ( $enclave->has_policy( self::OPERATION ) ) {
            return;
        }

        $enclave->register_policy(
            new Metis_Security_Policy(
                self::OPERATION,
                null,
                'execute',
                false,
                false,
                false,
                null,
                12,
                60
            )
        );
    }

    private static function normalize_requested_tasks( mixed $value ): array {
        if ( is_string( $value ) ) {
            $value = array_filter( array_map( 'trim', explode( ',', $value ) ) );
        }

        if ( ! is_array( $value ) ) {
            return [];
        }

        $tasks = [];
        foreach ( $value as $slug ) {
            $slug = metis_key_clean( (string) $slug );
            if ( $slug !== '' && ! in_array( $slug, $tasks, true ) ) {
                $tasks[] = $slug;
            }
        }

        return $tasks;
    }

    private static function resolved_interval( string $slug, int $default_interval ): int {
        $default_interval = max( 60, $default_interval );
        $overrides = Core_Settings_Service::get( 'system_cron_task_intervals', [] );
        if ( ! is_array( $overrides ) ) {
            return $default_interval;
        }

        $override = isset( $overrides[ $slug ] ) ? (int) $overrides[ $slug ] : 0;
        return $override >= 60 ? $override : $default_interval;
    }

    private static function env_int( string $name, int $default ): int {
        $value = getenv( $name );
        if ( $value === false ) {
            return $default;
        }

        $value = trim( (string) $value );
        if ( $value === '' || ! preg_match( '/^-?\d+$/', $value ) ) {
            return $default;
        }

        return (int) $value;
    }

    private static function run_cache_cleanup(): array {
        CacheService::clearGroup( 'query' );
        CacheService::clearGroup( 'fragments' );
        CacheService::clearGroup( 'hermes' );
        $reports_cache_cleared = false;
        if ( \function_exists( 'metis_reports_clear_cache' ) ) {
            \metis_reports_clear_cache();
            $reports_cache_cleared = true;
        }
        $release_cleanup = \function_exists( 'metis_release_cleanup_artifacts' )
            ? \metis_release_cleanup_artifacts( 'cache_cleanup' )
            : [ 'status' => 'skipped', 'message' => 'Release manager is not available.' ];
        $job_queue_cleanup = \function_exists( 'metis_job_queue' ) && \method_exists( \metis_job_queue(), 'cleanupHistory' )
            ? \metis_job_queue()->cleanupHistory( [ 'limit' => 5000 ] )
            : [ 'status' => 'skipped', 'message' => 'Job queue cleanup is not available.' ];
        $audit_compaction = \function_exists( 'metis_audit_compact' )
            ? \metis_audit_compact( 10000 )
            : [ 'status' => 'skipped', 'message' => 'Audit compaction is not available.' ];

        return [
            'deleted_rows' => 0,
            'reports_cache_cleared' => $reports_cache_cleared,
            'cache_groups_cleared' => [ 'query', 'fragments', 'hermes' ],
            'release_artifact_cleanup' => $release_cleanup,
            'job_queue_history_cleanup' => $job_queue_cleanup,
            'audit_context_compaction' => $audit_compaction,
        ];
    }

    private static function verbose_operational_audit_enabled(): bool {
        if ( ! class_exists( 'Core_Settings_Service' ) ) {
            return false;
        }

        $value = Core_Settings_Service::get( 'audit_verbose_operational_events', false );
        if ( is_bool( $value ) ) {
            return $value;
        }

        return in_array( strtolower( trim( (string) $value ) ), [ '1', 'true', 'yes', 'on' ], true );
    }

    private static function run_security_audit_digest(): array {
        $table = Metis_Tables::get( 'audit_security' );
        $window_hours = max( 1, min( 168, self::env_int( 'METIS_SECURITY_DIGEST_WINDOW_HOURS', 24 ) ) );

        $total = (int) metis_db()->scalar(
            "SELECT COUNT(*) FROM {$table} WHERE created_at >= DATE_SUB(UTC_TIMESTAMP(), INTERVAL %d HOUR)",
            [ $window_hours ]
        );

        $top_actions = metis_db()->fetchAll(
            "SELECT action_type, COUNT(*) AS total
             FROM {$table}
             WHERE created_at >= DATE_SUB(UTC_TIMESTAMP(), INTERVAL %d HOUR)
             GROUP BY action_type
             ORDER BY total DESC
             LIMIT 10",
            [ $window_hours ]
        );

        $severity_rows = metis_db()->fetchAll(
            "SELECT severity, COUNT(*) AS total
             FROM {$table}
             WHERE created_at >= DATE_SUB(UTC_TIMESTAMP(), INTERVAL %d HOUR)
             GROUP BY severity",
            [ $window_hours ]
        );

        $outcome_rows = metis_db()->fetchAll(
            "SELECT outcome, COUNT(*) AS total
             FROM {$table}
             WHERE created_at >= DATE_SUB(UTC_TIMESTAMP(), INTERVAL %d HOUR)
             GROUP BY outcome",
            [ $window_hours ]
        );

        $digest = [
            'window_hours'  => $window_hours,
            'generated_at'  => gmdate( 'Y-m-d H:i:s' ),
            'total_events'  => $total,
            'top_actions'   => is_array( $top_actions ) ? $top_actions : [],
            'by_severity'   => is_array( $severity_rows ) ? $severity_rows : [],
            'by_outcome'    => is_array( $outcome_rows ) ? $outcome_rows : [],
        ];

        metis_update_option( 'metis_security_audit_digest_last', $digest, false );

        Metis_Logger::info( 'Security audit digest generated', [
            'window_hours' => $window_hours,
            'total_events' => $total,
            'top_actions'  => array_slice( (array) $digest['top_actions'], 0, 3 ),
        ] );

        return $digest;
    }

}

Metis_Cron_Manager::init();
