<?php
declare(strict_types=1);

final class MetisUpdateServerApp {
    public function __construct(
        private readonly array $config,
        private readonly string $root
    ) {}

    public function handleHttp(): void {
        $path = (string) parse_url( $this->serverValue( 'REQUEST_URI', '/' ), PHP_URL_PATH );
        $path = $path !== '' ? $path : '/';

        if ( $path === '/' ) {
            $this->json( [
                'application' => 'Metis Update Server',
                'status' => 'online',
                'version' => '1.0.0',
                'timestamp' => gmdate( 'c' ),
            ] );
        }

        if ( $path === '/download.php' ) {
            $this->handleDownload();
            return;
        }

        if ( $path === '/api/installations/register' ) {
            $this->requireMethod( 'POST' );
            $payload = $this->readJsonBody();
            $this->verifyRegistrationSignature( $payload );
            $this->registerInstallation( $payload );
            return;
        }

        if ( $path === '/api/updates/check' ) {
            $this->requireMethod( 'POST' );
            $payload = $this->readJsonBody();
            $installation = $this->verifySignedRequest( $path );
            $this->checkUpdates( $installation, $payload );
            return;
        }

        if ( $path === '/api/installations/cron-probe' ) {
            $this->requireMethod( 'POST' );
            $payload = $this->readJsonBody();
            $installation = $this->verifySignedRequest( $path, $payload );
            $this->probeInstallationCron( $installation, $payload );
            return;
        }

        if ( str_starts_with( $path, '/admin' ) ) {
            $this->handleAdmin( $path );
            return;
        }

        $this->json( [ 'error' => 'Not found.' ], 404 );
    }

    public function installSchema(): void {
        $sql = (string) file_get_contents( $this->root . '/sql/schema.sql' );
        if ( trim( $sql ) === '' ) {
            throw new RuntimeException( 'Schema file is missing.' );
        }

        $pdo = $this->pdo();
        foreach ( array_filter( array_map( 'trim', explode( ';', $sql ) ) ) as $statement ) {
            $pdo->exec( $statement );
        }

        $this->migrateSchema();
    }

    public function ensureAdminUser( string $email, string $password ): void {
        $pdo = $this->pdo();
        $normalizedEmail = strtolower( trim( $email ) );
        $stmt = $pdo->prepare( 'SELECT id, display_name FROM admin_users WHERE email = :email LIMIT 1' );
        $stmt->execute( [ 'email' => $normalizedEmail ] );
        $existing = $stmt->fetch( PDO::FETCH_ASSOC );
        if ( is_array( $existing ) ) {
            if ( trim( (string) ( $existing['display_name'] ?? '' ) ) === '' ) {
                $pdo->prepare( 'UPDATE admin_users SET display_name = :display_name WHERE id = :id' )->execute( [
                    'display_name' => $normalizedEmail,
                    'id' => (int) $existing['id'],
                ] );
            }
            return;
        }

        $insert = $pdo->prepare(
            'INSERT INTO admin_users (email, display_name, password_hash) VALUES (:email, :display_name, :password_hash)'
        );
        $insert->execute( [
            'email' => $normalizedEmail,
            'display_name' => $normalizedEmail,
            'password_hash' => password_hash( $password, PASSWORD_DEFAULT ),
        ] );
    }

    public function assignAdminSystemUser( string $email, string $systemUsername ): array {
        $email = strtolower( trim( $email ) );
        $systemUsername = trim( $systemUsername );
        if ( $email === '' || $systemUsername === '' ) {
            throw new RuntimeException( 'Email and system username are required.' );
        }

        $stmt = $this->pdo()->prepare( 'SELECT * FROM admin_users WHERE email = :email LIMIT 1' );
        $stmt->execute( [ 'email' => $email ] );
        $user = $stmt->fetch( PDO::FETCH_ASSOC );
        if ( ! is_array( $user ) ) {
            throw new RuntimeException( 'Admin user not found.' );
        }

        $this->pdo()->prepare( 'UPDATE admin_users SET system_username = NULL WHERE system_username = :system_username AND id <> :id' )->execute( [
            'system_username' => $systemUsername,
            'id' => (int) $user['id'],
        ] );
        $this->pdo()->prepare( 'UPDATE admin_users SET system_username = :system_username WHERE id = :id' )->execute( [
            'system_username' => $systemUsername,
            'id' => (int) $user['id'],
        ] );

        return $this->adminUserById( (int) $user['id'] ) ?? [];
    }

    public function createAdminLoginLink( string $systemUsername, string $email = '', int $ttlSeconds = 900 ): array {
        $systemUsername = trim( $systemUsername );
        if ( $systemUsername === '' ) {
            throw new RuntimeException( 'System username is required.' );
        }

        $ttlSeconds = max( 60, min( 3600, $ttlSeconds ) );
        $user = $email !== ''
            ? $this->adminUserByEmail( strtolower( trim( $email ) ) )
            : $this->adminUserBySystemUsername( $systemUsername );

        if ( ! is_array( $user ) ) {
            if ( $email !== '' ) {
                throw new RuntimeException( 'No admin account exists for email [' . strtolower( trim( $email ) ) . '].' );
            }
            throw new RuntimeException( 'No admin account is mapped to that system user.' );
        }

        if ( trim( (string) ( $user['system_username'] ?? '' ) ) !== $systemUsername ) {
            throw new RuntimeException( 'That admin account is not mapped to the requested system user.' );
        }

        $token = bin2hex( random_bytes( 32 ) );
        $tokenHash = hash( 'sha256', $token );
        $expiresAt = gmdate( 'Y-m-d H:i:s', time() + $ttlSeconds );

        $this->pdo()->prepare(
            'INSERT INTO admin_login_tokens (admin_user_id, system_username, token_hash, expires_at) VALUES (:admin_user_id, :system_username, :token_hash, :expires_at)'
        )->execute( [
            'admin_user_id' => (int) $user['id'],
            'system_username' => $systemUsername,
            'token_hash' => $tokenHash,
            'expires_at' => $expiresAt,
        ] );

        return [
            'ok' => true,
            'email' => (string) $user['email'],
            'system_username' => $systemUsername,
            'expires_at' => $expiresAt,
            'login_url' => rtrim( (string) $this->config['base_url'], '/' ) . '/admin/login?token=' . rawurlencode( $token ),
        ];
    }

    public function triggerInstallationCrons( string $trigger = 'update_server_scheduler', string $installationId = '' ): array {
        $trigger = trim( $trigger ) !== '' ? trim( $trigger ) : 'update_server_scheduler';
        $installationId = trim( $installationId );

        $params = [];
        $sql = 'SELECT installation_uuid, installation_name, base_url, status FROM installations WHERE status = "active"';
        if ( $installationId !== '' ) {
            $sql .= ' AND installation_uuid = :installation_uuid';
            $params['installation_uuid'] = $installationId;
        }
        $sql .= ' ORDER BY installation_name ASC, installation_uuid ASC';

        $stmt = $this->pdo()->prepare( $sql );
        $stmt->execute( $params );
        $installations = $stmt->fetchAll( PDO::FETCH_ASSOC );
        if ( ! is_array( $installations ) || $installations === [] ) {
            if ( $installationId !== '' ) {
                throw new RuntimeException( 'Active installation not found for cron trigger.' );
            }

            return [
                'ok' => true,
                'trigger' => $trigger,
                'count' => 0,
                'results' => [],
            ];
        }

        $results = [];
        $ok = true;
        foreach ( $installations as $installation ) {
            $results[] = $this->triggerSingleInstallationCron( $installation, $trigger );
            if ( empty( $results[ array_key_last( $results ) ]['ok'] ) ) {
                $ok = false;
            }
        }

        return [
            'ok' => $ok,
            'trigger' => $trigger,
            'count' => count( $results ),
            'results' => $results,
        ];
    }

    public function publishPackage( array $options ): array {
        $type = trim( (string) ( $options['type'] ?? '' ) );
        if ( ! in_array( $type, [ 'core_full', 'core_delta', 'module_full', 'module_delta' ], true ) ) {
            throw new RuntimeException( 'Publish type is invalid.' );
        }

        $sourceDir = rtrim( trim( (string) ( $options['source_dir'] ?? '' ) ), '/\\' );
        if ( $sourceDir === '' || ! is_dir( $sourceDir ) ) {
            throw new RuntimeException( 'Publish source directory is missing.' );
        }

        $fromDir = rtrim( trim( (string) ( $options['from_dir'] ?? '' ) ), '/\\' );
        $version = trim( (string) ( $options['version'] ?? '' ) );
        if ( $version === '' ) {
            throw new RuntimeException( 'Publish version is required.' );
        }

        $verificationProfile = trim( (string) ( $options['verify_profile'] ?? 'publish_gate' ) ) ?: 'publish_gate';
        $skipVerify = ! empty( $options['skip_verify'] );
        $verification = $skipVerify
            ? [
                'ok' => true,
                'status' => 'skipped',
                'profile' => $verificationProfile,
                'repo_root' => '',
                'steps' => [],
                'message' => 'Verification skipped by operator request.',
            ]
            : $this->verifyRelease( $options );
        $targetRef = str_starts_with( $type, 'module_' )
            ? trim( (string) ( $options['module_id'] ?? '' ) ) . '@' . $version
            : ( trim( (string) ( $options['tag'] ?? '' ) ) ?: $version );
        $verificationId = $this->recordVerificationRun(
            str_starts_with( $type, 'module_' ) ? 'module_release' : 'core_release',
            $targetRef,
            $verification
        );
        if ( empty( $verification['ok'] ) ) {
            throw new RuntimeException( 'Release verification failed. Review the recorded verification report before publishing.' );
        }

        $manifest = [
            'package_type' => $type,
            'target_version' => $version,
            'target_tag' => trim( (string) ( $options['tag'] ?? '' ) ),
            'from_version' => trim( (string) ( $options['from_version'] ?? '' ) ),
            'module_id' => trim( (string) ( $options['module_id'] ?? '' ) ),
            'minimum_metis' => trim( (string) ( $options['minimum_metis'] ?? '' ) ),
            'payload_root' => 'payload',
            'files' => [],
            'delete' => [],
            'generated_at' => gmdate( 'c' ),
        ];

        [ $files, $delete ] = $this->buildDeltaManifest( $sourceDir, $fromDir );
        $manifest['files'] = $files;
        $manifest['delete'] = $delete;

        $storageDir = $this->root . '/storage/packages/' . $type;
        if ( ! is_dir( $storageDir ) && ! mkdir( $storageDir, 0775, true ) && ! is_dir( $storageDir ) ) {
            throw new RuntimeException( 'Unable to create package storage directory.' );
        }

        $fileName = $type . '-' . preg_replace( '/[^A-Za-z0-9_.-]/', '-', $manifest['module_id'] !== '' ? $manifest['module_id'] . '-' . $version : $version ) . ( str_starts_with( $type, 'module_' ) ? '.tar.gz' : '.zip' );
        $archivePath = $storageDir . '/' . $fileName;
        if ( str_starts_with( $type, 'module_' ) ) {
            $this->buildTarGzPackage( $archivePath, $manifest, $sourceDir, $fromDir );
        } else {
            $this->buildZipPackage( $archivePath, $manifest, $sourceDir, $fromDir );
        }

        $sha256 = hash_file( 'sha256', $archivePath ) ?: '';
        $pdo = $this->pdo();
        $publishedAt = gmdate( 'Y-m-d H:i:s' );

        if ( str_starts_with( $type, 'core_' ) ) {
            if ( $type === 'core_full' ) {
                $stmt = $pdo->prepare(
                    'INSERT INTO core_releases (tag_name, version, channel, package_type, notes, archive_path, archive_sha256, minimum_php, manifest_json, published_at)
                     VALUES (:tag_name, :version, :channel, :package_type, :notes, :archive_path, :archive_sha256, :minimum_php, :manifest_json, :published_at)
                     ON DUPLICATE KEY UPDATE channel = VALUES(channel), package_type = VALUES(package_type), notes = VALUES(notes), archive_path = VALUES(archive_path), archive_sha256 = VALUES(archive_sha256), minimum_php = VALUES(minimum_php), manifest_json = VALUES(manifest_json), published_at = VALUES(published_at)'
                );
                $stmt->execute( [
                    'tag_name' => trim( (string) ( $options['tag'] ?? '' ) ),
                    'version' => $version,
                    'channel' => trim( (string) ( $options['channel'] ?? 'stable' ) ) ?: 'stable',
                    'package_type' => $type,
                    'notes' => trim( (string) ( $options['notes'] ?? '' ) ),
                    'archive_path' => $archivePath,
                    'archive_sha256' => $sha256,
                    'minimum_php' => trim( (string) ( $options['minimum_php'] ?? '8.1' ) ) ?: '8.1',
                    'manifest_json' => json_encode( $manifest, JSON_UNESCAPED_SLASHES ),
                    'published_at' => $publishedAt,
                ] );
            } else {
                $releaseId = $this->fetchCoreReleaseIdByVersion( $version );
                if ( $releaseId < 1 ) {
                    throw new RuntimeException( 'Publish the core full release before publishing its delta package.' );
                }
                $stmt = $pdo->prepare(
                    'INSERT INTO core_delta_packages (release_id, from_version, archive_path, archive_sha256, manifest_json)
                     VALUES (:release_id, :from_version, :archive_path, :archive_sha256, :manifest_json)
                     ON DUPLICATE KEY UPDATE archive_path = VALUES(archive_path), archive_sha256 = VALUES(archive_sha256), manifest_json = VALUES(manifest_json)'
                );
                $stmt->execute( [
                    'release_id' => $releaseId,
                    'from_version' => trim( (string) ( $options['from_version'] ?? '' ) ),
                    'archive_path' => $archivePath,
                    'archive_sha256' => $sha256,
                    'manifest_json' => json_encode( $manifest, JSON_UNESCAPED_SLASHES ),
                ] );
            }
        } else {
            if ( $type === 'module_full' ) {
                $stmt = $pdo->prepare(
                    'INSERT INTO module_releases (module_id, version, minimum_metis, channel, visibility, installation_uuid, package_type, archive_path, archive_sha256, manifest_json, published_at)
                     VALUES (:module_id, :version, :minimum_metis, :channel, :visibility, :installation_uuid, :package_type, :archive_path, :archive_sha256, :manifest_json, :published_at)
                     ON DUPLICATE KEY UPDATE minimum_metis = VALUES(minimum_metis), channel = VALUES(channel), package_type = VALUES(package_type), archive_path = VALUES(archive_path), archive_sha256 = VALUES(archive_sha256), manifest_json = VALUES(manifest_json), published_at = VALUES(published_at)'
                );
                $stmt->execute( [
                    'module_id' => trim( (string) ( $options['module_id'] ?? '' ) ),
                    'version' => $version,
                    'minimum_metis' => trim( (string) ( $options['minimum_metis'] ?? '' ) ),
                    'channel' => trim( (string) ( $options['channel'] ?? 'stable' ) ) ?: 'stable',
                    'visibility' => trim( (string) ( $options['visibility'] ?? 'public' ) ) ?: 'public',
                    'installation_uuid' => trim( (string) ( $options['installation_id'] ?? '' ) ) ?: null,
                    'package_type' => $type,
                    'archive_path' => $archivePath,
                    'archive_sha256' => $sha256,
                    'manifest_json' => json_encode( $manifest, JSON_UNESCAPED_SLASHES ),
                    'published_at' => $publishedAt,
                ] );
            } else {
                $releaseId = $this->fetchModuleReleaseId( trim( (string) ( $options['module_id'] ?? '' ) ), $version, trim( (string) ( $options['visibility'] ?? 'public' ) ), trim( (string) ( $options['installation_id'] ?? '' ) ) );
                if ( $releaseId < 1 ) {
                    throw new RuntimeException( 'Publish the module full release before publishing its delta package.' );
                }
                $stmt = $pdo->prepare(
                    'INSERT INTO module_delta_packages (module_release_id, from_version, archive_path, archive_sha256, manifest_json)
                     VALUES (:module_release_id, :from_version, :archive_path, :archive_sha256, :manifest_json)
                     ON DUPLICATE KEY UPDATE archive_path = VALUES(archive_path), archive_sha256 = VALUES(archive_sha256), manifest_json = VALUES(manifest_json)'
                );
                $stmt->execute( [
                    'module_release_id' => $releaseId,
                    'from_version' => trim( (string) ( $options['from_version'] ?? '' ) ),
                    'archive_path' => $archivePath,
                    'archive_sha256' => $sha256,
                    'manifest_json' => json_encode( $manifest, JSON_UNESCAPED_SLASHES ),
                ] );
            }
        }

        return [
            'ok' => true,
            'archive_path' => $archivePath,
            'sha256' => $sha256,
            'manifest' => $manifest,
            'verification' => $verification,
            'verification_id' => $verificationId,
        ];
    }

    public function verifyRelease( array $options ): array {
        $type = trim( (string) ( $options['type'] ?? '' ) );
        if ( ! in_array( $type, [ 'core_full', 'core_delta', 'module_full', 'module_delta' ], true ) ) {
            throw new RuntimeException( 'Verification type is invalid.' );
        }

        $sourceDir = rtrim( trim( (string) ( $options['source_dir'] ?? '' ) ), '/\\' );
        if ( $sourceDir === '' || ! is_dir( $sourceDir ) ) {
            throw new RuntimeException( 'Verification source directory is missing.' );
        }

        $version = trim( (string) ( $options['version'] ?? '' ) );
        if ( $version === '' ) {
            throw new RuntimeException( 'Verification version is required.' );
        }

        $verificationProfile = trim( (string) ( $options['verify_profile'] ?? 'publish_gate' ) ) ?: 'publish_gate';

        return $this->runVerification( $options, $type, $version, $verificationProfile );
    }

    private function registerInstallation( array $payload ): void {
        $installationUuid = $this->uuidV4();
        $publicKeySha = trim( (string) ( $payload['public_key_sha256'] ?? '' ) );
        $baseUrl = trim( (string) ( $payload['base_url'] ?? '' ) );
        $fingerprint = trim( (string) ( $payload['server_fingerprint'] ?? '' ) );
        $machineUuid = trim( (string) ( $payload['machine_uuid'] ?? '' ) );
        $remoteIp = $this->remoteAddress();
        if ( $publicKeySha === '' || $baseUrl === '' || $fingerprint === '' || $machineUuid === '' ) {
            $this->json( [ 'error' => 'Registration payload is incomplete.' ], 422 );
        }

        $pdo = $this->pdo();
        $stmt = $pdo->prepare( 'SELECT installation_uuid, base_url, server_fingerprint FROM installations WHERE public_key_sha256 = :public_key_sha256 LIMIT 1' );
        $stmt->execute( [ 'public_key_sha256' => $publicKeySha ] );
        $existing = $stmt->fetch( PDO::FETCH_ASSOC );
        if ( is_array( $existing ) ) {
            if ( (string) $existing['base_url'] !== $baseUrl || (string) $existing['server_fingerprint'] !== $fingerprint ) {
                $this->json( [ 'error' => 'This installation key is already bound to a different server fingerprint or base URL.' ], 409 );
            }
            $this->json( [ 'ok' => true, 'installation_id' => (string) $existing['installation_uuid'] ], 200 );
        }

        $insert = $pdo->prepare(
            'INSERT INTO installations (installation_uuid, installation_name, base_url, channel, metis_version, php_version, machine_uuid, server_fingerprint, public_key_sha256, public_key_pem, status, module_inventory_json, registered_ip, last_seen_ip, contact_name, contact_email, contact_phone, admin_notes, last_seen_at)
             VALUES (:installation_uuid, :installation_name, :base_url, :channel, :metis_version, :php_version, :machine_uuid, :server_fingerprint, :public_key_sha256, :public_key_pem, :status, :module_inventory_json, :registered_ip, :last_seen_ip, :contact_name, :contact_email, :contact_phone, :admin_notes, :last_seen_at)'
        );
        $insert->execute( [
            'installation_uuid' => $installationUuid,
            'installation_name' => trim( (string) ( $payload['installation_name'] ?? 'Metis Installation' ) ),
            'base_url' => $baseUrl,
            'channel' => trim( (string) ( $payload['channel'] ?? 'stable' ) ) ?: 'stable',
            'metis_version' => trim( (string) ( $payload['metis_version'] ?? '' ) ),
            'php_version' => trim( (string) ( $payload['php_version'] ?? '' ) ),
            'machine_uuid' => $machineUuid,
            'server_fingerprint' => $fingerprint,
            'public_key_sha256' => $publicKeySha,
            'public_key_pem' => trim( (string) ( $payload['public_key_pem'] ?? '' ) ),
            'status' => 'active',
            'module_inventory_json' => json_encode( array_values( (array) ( $payload['module_inventory'] ?? [] ) ), JSON_UNESCAPED_SLASHES ),
            'registered_ip' => $remoteIp,
            'last_seen_ip' => $remoteIp,
            'contact_name' => substr( trim( (string) ( $payload['contact_name'] ?? '' ) ), 0, 190 ),
            'contact_email' => substr( strtolower( trim( (string) ( $payload['contact_email'] ?? '' ) ) ), 0, 190 ),
            'contact_phone' => substr( trim( (string) ( $payload['contact_phone'] ?? '' ) ), 0, 64 ),
            'admin_notes' => trim( (string) ( $payload['admin_notes'] ?? '' ) ),
            'last_seen_at' => gmdate( 'Y-m-d H:i:s' ),
        ] );

        $this->audit( null, 'installation_registered', 201, [
            'installation_uuid' => $installationUuid,
            'base_url' => $baseUrl,
        ] );

        $this->json( [ 'ok' => true, 'installation_id' => $installationUuid ], 201 );
    }

    private function checkUpdates( array $installation, array $payload ): void {
        $channel = trim( (string) ( $payload['channel'] ?? $installation['channel'] ?? 'stable' ) ) ?: 'stable';
        $currentVersion = trim( (string) ( $payload['core']['version'] ?? '' ) );
        $modules = array_values( (array) ( $payload['modules'] ?? [] ) );

        $core = $this->resolveCoreRelease( $currentVersion, $channel, (string) $installation['installation_uuid'] );
        $registry = $this->moduleRegistry( $installation, $channel, $currentVersion, $modules );

        $this->audit( (string) $installation['installation_uuid'], 'updates_check', 200, [
            'channel' => $channel,
            'core_current_version' => $currentVersion,
            'module_count' => count( $modules ),
        ] );

        $this->json( [
            'ok' => true,
            'core' => $core,
            'modules' => [
                'registry' => $registry,
            ],
        ] );
    }

    private function resolveCoreRelease( string $currentVersion, string $channel, string $installationUuid ): array {
        $pdo = $this->pdo();
        $stmt = $pdo->prepare( 'SELECT * FROM core_releases WHERE channel = :channel ORDER BY version DESC LIMIT 1' );
        $stmt->execute( [ 'channel' => $channel ] );
        $release = $stmt->fetch( PDO::FETCH_ASSOC );
        if ( ! is_array( $release ) ) {
            return [
                'current_version' => $currentVersion,
                'latest_version' => '',
                'update_available' => false,
            ];
        }

        $latestVersion = (string) $release['version'];
        $downloadUrl = '';
        $sha256 = '';
        $packageType = (string) $release['package_type'];
        if ( $currentVersion !== '' ) {
            $delta = $pdo->prepare( 'SELECT * FROM core_delta_packages WHERE release_id = :release_id AND from_version = :from_version LIMIT 1' );
            $delta->execute( [
                'release_id' => (int) $release['id'],
                'from_version' => $currentVersion,
            ] );
            $deltaRow = $delta->fetch( PDO::FETCH_ASSOC );
            if ( is_array( $deltaRow ) ) {
                $downloadUrl = $this->issueArtifactToken( $installationUuid, 'core_delta', 'core_delta_packages', (int) $deltaRow['id'] );
                $sha256 = (string) $deltaRow['archive_sha256'];
                $packageType = 'core_delta';
            }
        }
        if ( $downloadUrl === '' ) {
            $downloadUrl = $this->issueArtifactToken( $installationUuid, 'core_full', 'core_releases', (int) $release['id'] );
            $sha256 = (string) $release['archive_sha256'];
            $packageType = 'core_full';
        }

        return [
            'current_version' => $currentVersion,
            'latest_version' => $latestVersion,
            'update_available' => $currentVersion !== '' ? version_compare( $latestVersion, $currentVersion, '>' ) : true,
            'release_notes' => (string) $release['notes'],
            'download_url' => $downloadUrl,
            'published_at' => (string) $release['published_at'],
            'name' => (string) $release['tag_name'],
            'tag_name' => (string) $release['tag_name'],
            'sha256' => $sha256,
            'package_type' => $packageType,
        ];
    }

    private function moduleRegistry( array $installation, string $channel, string $currentMetisVersion, array $installedModules ): array {
        $pdo = $this->pdo();
        $stmt = $pdo->prepare(
            'SELECT * FROM module_releases
             WHERE channel = :channel
               AND (
                    visibility = "public"
                    OR (
                        visibility = "installation"
                        AND installation_uuid = :installation_uuid
                        AND EXISTS (
                            SELECT 1
                            FROM installation_module_assignments
                            WHERE installation_module_assignments.installation_uuid = :installation_uuid_assignment
                              AND installation_module_assignments.module_id = module_releases.module_id
                        )
                    )
               )
             ORDER BY module_id ASC, version DESC'
        );
        $stmt->execute( [
            'channel' => $channel,
            'installation_uuid' => (string) $installation['installation_uuid'],
            'installation_uuid_assignment' => (string) $installation['installation_uuid'],
        ] );

        $installedMap = [];
        foreach ( $installedModules as $row ) {
            if ( ! is_array( $row ) ) {
                continue;
            }
            $moduleId = trim( (string) ( $row['id'] ?? $row['module'] ?? '' ) );
            if ( $moduleId !== '' ) {
                $installedMap[ $moduleId ] = trim( (string) ( $row['version'] ?? '' ) );
            }
        }

        $registry = [];
        foreach ( $stmt->fetchAll( PDO::FETCH_ASSOC ) as $row ) {
            $moduleId = (string) $row['module_id'];
            if ( isset( $registry[ $moduleId ] ) ) {
                continue;
            }

            $minimumMetis = trim( (string) $row['minimum_metis'] );
            if ( $minimumMetis !== '' && $currentMetisVersion !== '' && version_compare( $currentMetisVersion, $minimumMetis, '<' ) ) {
                continue;
            }

            $downloadUrl = $this->issueModuleDownload( $installation, $row, $installedMap[ $moduleId ] ?? '' );
            $registry[ $moduleId ] = [
                'id' => $moduleId,
                'name' => $moduleId,
                'description' => '',
                'latest' => (string) $row['version'],
                'minimum_metis' => $minimumMetis,
                'release_channel' => (string) $row['channel'],
                'download_url' => $downloadUrl['url'],
                'sha256' => $downloadUrl['sha256'],
            ];
        }

        return $registry;
    }

    private function issueModuleDownload( array $installation, array $release, string $currentVersion ): array {
        $pdo = $this->pdo();
        $packageType = (string) $release['package_type'];
        $sha256 = (string) $release['archive_sha256'];
        $url = $this->issueArtifactToken( (string) $installation['installation_uuid'], $packageType, 'module_releases', (int) $release['id'] );

        if ( $currentVersion !== '' ) {
            $delta = $pdo->prepare( 'SELECT * FROM module_delta_packages WHERE module_release_id = :module_release_id AND from_version = :from_version LIMIT 1' );
            $delta->execute( [
                'module_release_id' => (int) $release['id'],
                'from_version' => $currentVersion,
            ] );
            $deltaRow = $delta->fetch( PDO::FETCH_ASSOC );
            if ( is_array( $deltaRow ) ) {
                return [
                    'url' => $this->issueArtifactToken( (string) $installation['installation_uuid'], 'module_delta', 'module_delta_packages', (int) $deltaRow['id'] ),
                    'sha256' => (string) $deltaRow['archive_sha256'],
                ];
            }
        }

        return [
            'url' => $url,
            'sha256' => $sha256,
        ];
    }

    private function handleDownload(): void {
        $token = trim( $this->getValue( 'token' ) );
        if ( $token === '' ) {
            $this->json( [ 'error' => 'Download token is required.' ], 400 );
        }

        $pdo = $this->pdo();
        $stmt = $pdo->prepare( 'SELECT * FROM artifact_tokens WHERE token = :token AND expires_at >= UTC_TIMESTAMP() LIMIT 1' );
        $stmt->execute( [ 'token' => $token ] );
        $row = $stmt->fetch( PDO::FETCH_ASSOC );
        if ( ! is_array( $row ) ) {
            $this->json( [ 'error' => 'Download token is invalid or expired.' ], 404 );
        }

        $table = (string) $row['artifact_table_name'];
        $id = (int) $row['artifact_row_id'];
        $artifact = $this->pdo()->query( 'SELECT archive_path FROM ' . $table . ' WHERE id = ' . $id )->fetch( PDO::FETCH_ASSOC );
        if ( ! is_array( $artifact ) ) {
            $this->json( [ 'error' => 'Artifact not found.' ], 404 );
        }

        $path = (string) $artifact['archive_path'];
        if ( ! is_file( $path ) ) {
            $this->json( [ 'error' => 'Artifact file is missing.' ], 404 );
        }

        $mime = str_ends_with( $path, '.zip' ) ? 'application/zip' : 'application/gzip';
        header( 'Content-Type: ' . $mime );
        header( 'Content-Length: ' . (string) filesize( $path ) );
        header( 'Content-Disposition: attachment; filename="' . basename( $path ) . '"' );
        readfile( $path );
        exit;
    }

    private function handleAdmin( string $path ): void {
        session_start();

        if ( $path === '/admin/logout' ) {
            unset( $_SESSION['metis_update_admin_id'], $_SESSION['metis_update_admin_method'], $_SESSION['metis_update_admin_flash'] );
            $this->redirect( '/admin/login' );
            return;
        }

        if ( $path === '/admin/login' ) {
            $token = trim( $this->getValue( 'token' ) );
            if ( $token !== '' ) {
                $user = $this->consumeAdminLoginToken( $token );
                if ( is_array( $user ) ) {
                    $this->loginAdminUser( $user, 'server_link' );
                    $this->setAdminFlash( 'success', 'Server Login Accepted', 'Your one-time server login link has been accepted.', [
                        'system_username' => (string) ( $user['system_username'] ?? '' ),
                    ] );
                    $this->redirect( '/admin/installs' );
                    return;
                }
                $this->renderLogin( 'That server login link is invalid or expired.' );
                return;
            }

            if ( $this->requestMethod() === 'POST' ) {
                $loginMode = trim( $this->postValue( 'login_mode', 'admin_password' ) );
                if ( $loginMode === 'system_password' ) {
                    $systemUsername = trim( $this->postValue( 'system_username' ) );
                    $password = $this->postValue( 'password' );
                    $result = $this->authenticateSystemUser( $systemUsername, $password );
                    if ( ! empty( $result['ok'] ) ) {
                        $user = $this->adminUserBySystemUsername( $systemUsername );
                        if ( is_array( $user ) ) {
                            $this->loginAdminUser( $user, 'pam' );
                            $this->redirect( '/admin/installs' );
                            return;
                        }
                        $this->renderLogin( 'That server account is not mapped to an update-server admin.' );
                        return;
                    }
                    $this->renderLogin( (string) ( $result['message'] ?? 'System authentication failed.' ) );
                    return;
                }

                $email = strtolower( trim( $this->postValue( 'email' ) ) );
                $password = $this->postValue( 'password' );
                $user = $this->adminUserByEmail( $email );
                if ( is_array( $user ) && password_verify( $password, (string) $user['password_hash'] ) ) {
                    $this->loginAdminUser( $user, 'password' );
                    $this->redirect( '/admin/installs' );
                    return;
                }
                $this->renderLogin( 'Invalid credentials.' );
                return;
            }

            $this->renderLogin();
            return;
        }

        $user = $this->currentAdminUser();
        if ( ! is_array( $user ) ) {
            $this->redirect( '/admin/login' );
            return;
        }

        if ( $path === '/admin' ) {
            $this->redirect( '/admin/installs' );
            return;
        }

        $currentTab = $this->adminTabFromPath( $path );

        if ( $this->requestMethod() === 'POST' ) {
            $this->requireAdminCsrf();
            $action = trim( $this->postValue( 'admin_action' ) );
            $redirectTab = $this->normalizeAdminTab( $this->postValue( 'admin_tab', $currentTab ) );
            $redirectPath = trim( $this->postValue( 'admin_redirect' ) );
            try {
                $flash = $this->handleAdminAction( $action, $user );
            } catch ( Throwable $e ) {
                $flash = [
                    'level' => 'error',
                    'title' => 'Action Failed',
                    'message' => $e->getMessage(),
                    'data' => [],
                ];
            }
            $this->setAdminFlash(
                (string) ( $flash['level'] ?? 'success' ),
                (string) ( $flash['title'] ?? 'Action Complete' ),
                (string) ( $flash['message'] ?? '' ),
                (array) ( $flash['data'] ?? [] )
            );
            if ( $redirectPath !== '' && str_starts_with( $redirectPath, '/admin/' ) ) {
                $this->redirect( $redirectPath );
            }
            $this->redirect( $this->adminTabPath( $redirectTab ) );
            return;
        }

        $this->renderAdminDashboard( $user, $this->consumeAdminFlash() );
    }

    private function handleAdminAction( string $action, array $user ): array {
        return match ( $action ) {
            'save_system_user' => $this->handleAdminSaveSystemUser( $user ),
            'save_installation_profile' => $this->handleAdminSaveInstallationProfile( $user ),
            'save_installation_modules' => $this->handleAdminSaveInstallationModules( $user ),
            'save_module_catalog_settings' => $this->handleAdminSaveModuleCatalogSettings( $user ),
            'run_verify' => $this->handleAdminVerify( $user ),
            'run_publish' => $this->handleAdminPublish( $user ),
            'run_github_release' => $this->handleAdminGitHubRelease( $user ),
            default => throw new RuntimeException( 'Unknown admin action.' ),
        };
    }

    private function handleAdminSaveSystemUser( array $user ): array {
        $systemUsername = trim( $this->postValue( 'system_username' ) );
        if ( $systemUsername === '' ) {
            throw new RuntimeException( 'System username is required.' );
        }

        $displayName = trim( $this->postValue( 'display_name', (string) ( $user['display_name'] ?? '' ) ) );
        $this->pdo()->prepare(
            'UPDATE admin_users SET system_username = :system_username, display_name = :display_name WHERE id = :id'
        )->execute( [
            'system_username' => $systemUsername,
            'display_name' => $displayName !== '' ? $displayName : (string) $user['email'],
            'id' => (int) $user['id'],
        ] );

        return [
            'level' => 'success',
            'title' => 'Admin Profile Updated',
            'message' => 'Server-user login is now mapped for this admin account.',
            'data' => [
                'email' => (string) $user['email'],
                'system_username' => $systemUsername,
            ],
        ];
    }

    private function handleAdminSaveInstallationModules( array $user ): array {
        $installationId = trim( $this->postValue( 'installation_id' ) );
        if ( $installationId === '' ) {
            throw new RuntimeException( 'Installation ID is required.' );
        }

        $installation = $this->installationByUuid( $installationId );
        if ( ! is_array( $installation ) ) {
            throw new RuntimeException( 'Installation not found.' );
        }

        $selectedModules = filter_input( INPUT_POST, 'assigned_modules', FILTER_DEFAULT, FILTER_REQUIRE_ARRAY );
        $raw = is_array( $selectedModules ) ? $selectedModules : [];
        $assignments = [];
        foreach ( $raw as $value ) {
            $slug = $this->normalizeModuleId( $value );
            if ( $slug !== '' ) {
                $assignments[ $slug ] = $slug;
            }
        }
        ksort( $assignments );

        $notesByModule = [];
        foreach ( $assignments as $slug ) {
            $note = trim( $this->postValue( 'note_' . $slug ) );
            $notesByModule[ $slug ] = substr( $note, 0, 255 );
        }

        $pdo = $this->pdo();
        $pdo->beginTransaction();
        try {
            $pdo->prepare( 'DELETE FROM installation_module_assignments WHERE installation_uuid = :installation_uuid' )->execute( [
                'installation_uuid' => $installationId,
            ] );
            if ( $assignments !== [] ) {
                $insert = $pdo->prepare(
                    'INSERT INTO installation_module_assignments (installation_uuid, module_id, notes) VALUES (:installation_uuid, :module_id, :notes)'
                );
                foreach ( $assignments as $slug ) {
                    $insert->execute( [
                        'installation_uuid' => $installationId,
                        'module_id' => $slug,
                        'notes' => $notesByModule[ $slug ] ?? '',
                    ] );
                }
            }
            $pdo->commit();
        } catch ( Throwable $e ) {
            $pdo->rollBack();
            throw $e;
        }

        return [
            'level' => 'success',
            'title' => 'Installation Modules Updated',
            'message' => 'Custom module assignments were saved for this installation.',
            'data' => [
                'installation_id' => $installationId,
                'assigned_modules' => array_values( $assignments ),
                'saved_by' => (string) ( $user['email'] ?? '' ),
            ],
        ];
    }

    private function handleAdminSaveInstallationProfile( array $user ): array {
        $installationId = trim( $this->postValue( 'installation_id' ) );
        if ( $installationId === '' ) {
            throw new RuntimeException( 'Installation ID is required.' );
        }

        $installation = $this->installationByUuid( $installationId );
        if ( ! is_array( $installation ) ) {
            throw new RuntimeException( 'Installation not found.' );
        }

        $contactName = substr( trim( $this->postValue( 'contact_name' ) ), 0, 190 );
        $contactEmail = substr( strtolower( trim( $this->postValue( 'contact_email' ) ) ), 0, 190 );
        $contactPhone = substr( trim( $this->postValue( 'contact_phone' ) ), 0, 64 );
        $adminNotes = trim( $this->postValue( 'admin_notes' ) );

        if ( $contactEmail !== '' && filter_var( $contactEmail, FILTER_VALIDATE_EMAIL ) === false ) {
            throw new RuntimeException( 'Contact email must be a valid email address.' );
        }

        $this->pdo()->prepare(
            'UPDATE installations
             SET contact_name = :contact_name,
                 contact_email = :contact_email,
                 contact_phone = :contact_phone,
                 admin_notes = :admin_notes
             WHERE installation_uuid = :installation_uuid'
        )->execute( [
            'contact_name' => $contactName,
            'contact_email' => $contactEmail,
            'contact_phone' => $contactPhone,
            'admin_notes' => $adminNotes,
            'installation_uuid' => $installationId,
        ] );

        return [
            'level' => 'success',
            'title' => 'Installation Profile Updated',
            'message' => 'Contact information and admin notes were saved for this installation.',
            'data' => [
                'installation_id' => $installationId,
                'contact_name' => $contactName,
                'contact_email' => $contactEmail,
                'contact_phone' => $contactPhone,
                'saved_by' => (string) ( $user['email'] ?? '' ),
            ],
        ];
    }

    private function handleAdminSaveModuleCatalogSettings( array $user ): array {
        $moduleId = $this->normalizeModuleId( $this->postValue( 'module_id' ) );
        if ( $moduleId === '' ) {
            throw new RuntimeException( 'Module ID is required.' );
        }

        $isCustom = $this->postValue( 'is_custom' ) === '1';
        $adminNotes = trim( $this->postValue( 'admin_notes' ) );

        $this->pdo()->prepare(
            'INSERT INTO module_catalog_settings (module_id, is_custom, admin_notes)
             VALUES (:module_id, :is_custom, :admin_notes)
             ON DUPLICATE KEY UPDATE is_custom = VALUES(is_custom), admin_notes = VALUES(admin_notes), updated_at = CURRENT_TIMESTAMP'
        )->execute( [
            'module_id' => $moduleId,
            'is_custom' => $isCustom ? 1 : 0,
            'admin_notes' => $adminNotes,
        ] );

        return [
            'level' => 'success',
            'title' => 'Module Catalog Updated',
            'message' => 'Module catalog settings were saved.',
            'data' => [
                'module_id' => $moduleId,
                'is_custom' => $isCustom,
                'saved_by' => (string) ( $user['email'] ?? '' ),
            ],
        ];
    }

    private function handleAdminVerify( array $user ): array {
        $input = [
            'type' => $this->postValue( 'type' ),
            'version' => $this->postValue( 'version' ),
            'source_dir' => $this->postValue( 'source_dir' ),
            'from_dir' => $this->postValue( 'from_dir' ),
            'verify_root' => $this->postValue( 'verify_root' ),
            'verify_profile' => $this->postValue( 'verify_profile', 'publish_gate' ),
            'module_id' => $this->postValue( 'module_id' ),
        ];

        $targetRef = $this->adminTargetRef( (string) $input['type'], $input );
        return $this->runAdminOperation( 'verify', $targetRef, $user, $input, function () use ( $input ): array {
            return $this->verifyRelease( $input );
        } );
    }

    private function handleAdminPublish( array $user ): array {
        $input = [
            'type' => $this->postValue( 'type' ),
            'tag' => $this->postValue( 'tag' ),
            'version' => $this->postValue( 'version' ),
            'from_version' => $this->postValue( 'from_version' ),
            'module_id' => $this->postValue( 'module_id' ),
            'minimum_metis' => $this->postValue( 'minimum_metis' ),
            'channel' => $this->postValue( 'channel', 'stable' ),
            'visibility' => $this->postValue( 'visibility', 'public' ),
            'installation_id' => $this->postValue( 'installation_id' ),
            'minimum_php' => $this->postValue( 'minimum_php', '8.1' ),
            'notes' => $this->postValue( 'notes' ),
            'source_dir' => $this->postValue( 'source_dir' ),
            'from_dir' => $this->postValue( 'from_dir' ),
            'verify_root' => $this->postValue( 'verify_root' ),
            'verify_profile' => $this->postValue( 'verify_profile', 'publish_gate' ),
            'skip_verify' => $this->postValue( 'skip_verify' ) === '1',
        ];

        $publishToGitHub = $this->postValue( 'publish_to_github' ) === '1';
        $githubInput = [
            'repo' => $this->postValue( 'github_repo' ),
            'tag' => $this->postValue( 'github_tag', (string) ( $input['tag'] !== '' ? $input['tag'] : $input['version'] ) ),
            'title' => $this->postValue( 'github_title', (string) ( $input['tag'] !== '' ? $input['tag'] : $input['version'] ) ),
            'notes' => $this->postValue( 'github_notes', (string) $input['notes'] ),
            'target' => $this->postValue( 'github_target' ),
            'draft' => $this->postValue( 'github_draft' ) === '1',
            'prerelease' => $this->postValue( 'github_prerelease' ) === '1',
        ];

        $targetRef = $this->adminTargetRef( (string) $input['type'], $input );
        return $this->runAdminOperation( 'publish', $targetRef, $user, $input + [ 'publish_to_github' => $publishToGitHub, 'github' => $githubInput ], function () use ( $input, $publishToGitHub, $githubInput ): array {
            $result = $this->publishPackage( $input );
            if ( $publishToGitHub ) {
                $githubInput['files'] = [ (string) ( $result['archive_path'] ?? '' ) ];
                $result['github_release'] = $this->createGitHubRelease( $githubInput );
            }
            return $result;
        } );
    }

    private function handleAdminGitHubRelease( array $user ): array {
        $filePaths = preg_split( '/\r\n|\r|\n/', $this->postValue( 'asset_paths' ) ) ?: [];
        $input = [
            'repo' => $this->postValue( 'repo' ),
            'tag' => $this->postValue( 'tag' ),
            'title' => $this->postValue( 'title' ),
            'notes' => $this->postValue( 'notes' ),
            'target' => $this->postValue( 'target' ),
            'draft' => $this->postValue( 'draft' ) === '1',
            'prerelease' => $this->postValue( 'prerelease' ) === '1',
            'files' => array_values( array_filter( array_map( static fn( string $value ): string => trim( $value ), $filePaths ) ) ),
        ];

        return $this->runAdminOperation( 'github_release', (string) $input['tag'], $user, $input, function () use ( $input ): array {
            return $this->createGitHubRelease( $input );
        } );
    }

    private function runAdminOperation( string $operationType, string $targetRef, array $user, array $input, callable $callback ): array {
        $startedAt = gmdate( 'Y-m-d H:i:s' );

        try {
            $result = $callback();
            $status = ! empty( $result['ok'] ) ? 'passed' : 'failed';
            $title = ucfirst( str_replace( '_', ' ', $operationType ) ) . ( $status === 'passed' ? ' Succeeded' : ' Failed' );
            $message = (string) ( $result['message'] ?? ( $status === 'passed' ? 'Operation completed.' : 'Operation failed.' ) );
        } catch ( Throwable $e ) {
            $status = 'failed';
            $result = [
                'ok' => false,
                'message' => $e->getMessage(),
                'exception' => get_class( $e ),
            ];
            $title = ucfirst( str_replace( '_', ' ', $operationType ) ) . ' Failed';
            $message = $e->getMessage();
        }

        $this->recordAdminOperation(
            $operationType,
            $targetRef,
            $status,
            (int) $user['id'],
            (string) ( $user['email'] ?? '' ),
            $input,
            $result,
            $startedAt,
            gmdate( 'Y-m-d H:i:s' )
        );

        return [
            'level' => $status === 'passed' ? 'success' : 'error',
            'title' => $title,
            'message' => $message,
            'data' => $result,
        ];
    }

    private function normalizeAdminTab( string $tab ): string {
        $tab = strtolower( trim( $tab ) );
        return in_array( $tab, [ 'installs', 'modules', 'releases', 'publish' ], true ) ? $tab : 'publish';
    }

    private function adminPathSegments( string $path ): array {
        $path = trim( strtolower( $path ), '/' );
        if ( $path === '' ) {
            return [];
        }

        return array_values( array_filter( explode( '/', $path ), static fn( string $segment ): bool => $segment !== '' ) );
    }

    private function adminTabFromPath( string $path ): string {
        $segments = $this->adminPathSegments( $path );
        if ( $segments === [] || ( $segments[0] ?? '' ) !== 'admin' ) {
            return 'installs';
        }

        if ( count( $segments ) === 1 ) {
            return 'installs';
        }

        return $this->normalizeAdminTab( (string) ( $segments[1] ?? 'installs' ) );
    }

    private function adminTabPath( string $tab ): string {
        return '/admin/' . $this->normalizeAdminTab( $tab );
    }

    private function adminInstallPath( string $installationUuid ): string {
        return '/admin/installs/' . rawurlencode( trim( $installationUuid ) );
    }

    private function adminModulePath( string $moduleId ): string {
        return '/admin/modules/' . rawurlencode( $this->normalizeModuleId( $moduleId ) );
    }

    private function adminContextFromPath( string $path ): array {
        $segments = $this->adminPathSegments( $path );
        $tab = $this->adminTabFromPath( $path );

        return [
            'tab' => $tab,
            'installation_id' => $tab === 'installs' ? trim( rawurldecode( (string) ( $segments[2] ?? '' ) ) ) : '',
            'module_id' => $tab === 'modules' ? $this->normalizeModuleId( rawurldecode( (string) ( $segments[2] ?? '' ) ) ) : '',
        ];
    }

    private function installationDisplayName( array $installation ): string {
        $name = trim( (string) ( $installation['installation_name'] ?? '' ) );
        if ( $name !== '' ) {
            return $name;
        }

        $baseUrl = trim( (string) ( $installation['base_url'] ?? '' ) );
        return $baseUrl !== '' ? $baseUrl : 'Metis installation';
    }

    private function normalizeModuleId( string $value ): string {
        return trim( preg_replace( '/[^a-z0-9_-]/', '', strtolower( trim( $value ) ) ) ?? '' );
    }

    private function installationByUuid( string $installationUuid ): ?array {
        $stmt = $this->pdo()->prepare( 'SELECT * FROM installations WHERE installation_uuid = :installation_uuid LIMIT 1' );
        $stmt->execute( [ 'installation_uuid' => $installationUuid ] );
        $row = $stmt->fetch( PDO::FETCH_ASSOC );
        return is_array( $row ) ? $row : null;
    }

    private function installationAssignmentsMap(): array {
        $rows = $this->pdo()->query( 'SELECT installation_uuid, module_id, notes FROM installation_module_assignments ORDER BY installation_uuid ASC, module_id ASC' )->fetchAll( PDO::FETCH_ASSOC );
        $map = [];
        foreach ( $rows as $row ) {
            $installationUuid = trim( (string) ( $row['installation_uuid'] ?? '' ) );
            $moduleId = $this->normalizeModuleId( (string) ( $row['module_id'] ?? '' ) );
            if ( $installationUuid === '' || $moduleId === '' ) {
                continue;
            }
            $map[ $installationUuid ][ $moduleId ] = [
                'module_id' => $moduleId,
                'notes' => trim( (string) ( $row['notes'] ?? '' ) ),
            ];
        }

        return $map;
    }

    private function moduleCatalogSettingsMap(): array {
        $rows = $this->pdo()->query(
            'SELECT module_id, is_custom, admin_notes
             FROM module_catalog_settings
             ORDER BY module_id ASC'
        )->fetchAll( PDO::FETCH_ASSOC );
        $map = [];
        foreach ( $rows as $row ) {
            $moduleId = $this->normalizeModuleId( (string) ( $row['module_id'] ?? '' ) );
            if ( $moduleId === '' ) {
                continue;
            }
            $map[ $moduleId ] = [
                'module_id' => $moduleId,
                'is_custom' => ! empty( $row['is_custom'] ),
                'admin_notes' => trim( (string) ( $row['admin_notes'] ?? '' ) ),
            ];
        }

        return $map;
    }

    private function installationScopedReleaseMap(): array {
        $rows = $this->pdo()->query(
            'SELECT installation_uuid, module_id, version, published_at
             FROM module_releases
             WHERE visibility = "installation" AND installation_uuid IS NOT NULL
             ORDER BY installation_uuid ASC, module_id ASC, published_at DESC'
        )->fetchAll( PDO::FETCH_ASSOC );
        $map = [];
        foreach ( $rows as $row ) {
            $installationUuid = trim( (string) ( $row['installation_uuid'] ?? '' ) );
            $moduleId = $this->normalizeModuleId( (string) ( $row['module_id'] ?? '' ) );
            if ( $installationUuid === '' || $moduleId === '' || isset( $map[ $installationUuid ][ $moduleId ] ) ) {
                continue;
            }
            $map[ $installationUuid ][ $moduleId ] = [
                'version' => trim( (string) ( $row['version'] ?? '' ) ),
                'published_at' => trim( (string) ( $row['published_at'] ?? '' ) ),
            ];
        }

        return $map;
    }

    private function currentBuildModules(): array {
        $root = $this->root . '/builds/modules/current';
        if ( ! is_dir( $root ) ) {
            return [];
        }

        $settingsMap = $this->moduleCatalogSettingsMap();
        $modules = [];
        foreach ( new DirectoryIterator( $root ) as $item ) {
            if ( ! $item->isDir() || $item->isDot() ) {
                continue;
            }
            $manifestPath = $item->getPathname() . '/module.json';
            if ( ! is_file( $manifestPath ) || ! is_readable( $manifestPath ) ) {
                continue;
            }
            $decoded = json_decode( (string) file_get_contents( $manifestPath ), true );
            if ( ! is_array( $decoded ) ) {
                continue;
            }

            $slug = $this->normalizeModuleId( (string) ( $decoded['slug'] ?? $decoded['name'] ?? $item->getFilename() ) );
            if ( $slug === '' ) {
                continue;
            }

            $modules[ $slug ] = [
                'id' => $slug,
                'name' => trim( (string) ( $decoded['label'] ?? $decoded['title'] ?? $decoded['name'] ?? $slug ) ),
                'description' => trim( (string) ( $decoded['description'] ?? '' ) ),
                'version' => trim( (string) ( $decoded['version'] ?? '' ) ),
                'minimum_metis' => trim( (string) ( $decoded['minimum_metis'] ?? '' ) ),
                'channel' => trim( (string) ( $decoded['release_channel'] ?? 'stable' ) ) ?: 'stable',
                'source_dir' => $item->getPathname(),
                'is_custom' => ! empty( $settingsMap[ $slug ]['is_custom'] ),
                'admin_notes' => trim( (string) ( $settingsMap[ $slug ]['admin_notes'] ?? '' ) ),
            ];
        }
        ksort( $modules );

        return $modules;
    }

    private function moduleReleaseStats(): array {
        $rows = $this->pdo()->query(
            'SELECT module_id, version, visibility, installation_uuid, published_at
             FROM module_releases
             ORDER BY module_id ASC, published_at DESC'
        )->fetchAll( PDO::FETCH_ASSOC );
        $stats = [];
        foreach ( $rows as $row ) {
            $moduleId = $this->normalizeModuleId( (string) ( $row['module_id'] ?? '' ) );
            if ( $moduleId === '' ) {
                continue;
            }
            $stats[ $moduleId ] ??= [
                'public_latest' => '',
                'public_published_at' => '',
                'installation_release_count' => 0,
                'total_releases' => 0,
            ];
            $stats[ $moduleId ]['total_releases']++;
            $visibility = trim( (string) ( $row['visibility'] ?? 'public' ) );
            if ( $visibility === 'public' && $stats[ $moduleId ]['public_latest'] === '' ) {
                $stats[ $moduleId ]['public_latest'] = trim( (string) ( $row['version'] ?? '' ) );
                $stats[ $moduleId ]['public_published_at'] = trim( (string) ( $row['published_at'] ?? '' ) );
            }
            if ( $visibility === 'installation' ) {
                $stats[ $moduleId ]['installation_release_count']++;
            }
        }

        return $stats;
    }

    private function latestCoreReleaseVersion(): string {
        $row = $this->pdo()->query( 'SELECT version FROM core_releases ORDER BY published_at DESC LIMIT 1' )->fetch( PDO::FETCH_ASSOC );
        return is_array( $row ) ? trim( (string) ( $row['version'] ?? '' ) ) : '';
    }

    private function nextDatedVersion( string $latestVersion ): string {
        $today = [ (int) date( 'y' ), (int) date( 'n' ), (int) date( 'j' ) ];
        if ( preg_match( '/^(\d{1,2})\.(\d{1,2})\.(\d{1,2})\.(\d+)$/', $latestVersion, $matches ) === 1 ) {
            $latest = [ (int) $matches[1], (int) $matches[2], (int) $matches[3], (int) $matches[4] ];
            if ( $latest[0] === $today[0] && $latest[1] === $today[1] && $latest[2] === $today[2] ) {
                return implode( '.', [ $today[0], $today[1], $today[2], $latest[3] + 1 ] );
            }
        }

        return implode( '.', [ $today[0], $today[1], $today[2], 1 ] );
    }

    private function adminPackageTypeLabel( string $type ): string {
        return match ( $type ) {
            'core_full' => 'Core full release',
            'core_delta' => 'Core delta update',
            'module_full' => 'Module full release',
            'module_delta' => 'Module delta update',
            default => ucwords( str_replace( '_', ' ', $type ) ),
        };
    }

    private function adminPackageAudienceLabel( string $visibility, string $installationUuid, array $installationsById ): string {
        if ( $visibility !== 'installation' ) {
            return 'Everyone on this channel';
        }

        $installation = is_array( $installationsById[ $installationUuid ] ?? null ) ? (array) $installationsById[ $installationUuid ] : [];
        $name = trim( (string) ( $installation['installation_name'] ?? '' ) );
        if ( $name !== '' ) {
            return $name;
        }

        $baseUrl = trim( (string) ( $installation['base_url'] ?? '' ) );
        return $baseUrl !== '' ? $baseUrl : 'One installation';
    }

    private function renderAdminDashboard( array $user, array $flash ): void {
        $requestPath = (string) parse_url( $this->serverValue( 'REQUEST_URI', '/admin/installs' ), PHP_URL_PATH );
        $context = $this->adminContextFromPath( $requestPath );
        $tab = (string) $context['tab'];
        $selectedInstallationId = (string) $context['installation_id'];
        $selectedModuleId = (string) $context['module_id'];
        $csrf = $this->adminCsrfToken();

        $installations = $this->pdo()->query(
            'SELECT installation_uuid, installation_name, base_url, channel, metis_version, registered_ip, last_seen_ip, last_seen_at,
                    contact_name, contact_email, contact_phone, admin_notes
             FROM installations
             ORDER BY created_at DESC LIMIT 50'
        )->fetchAll( PDO::FETCH_ASSOC );
        $coreReleases = $this->pdo()->query( 'SELECT tag_name, version, package_type, published_at, channel FROM core_releases ORDER BY published_at DESC LIMIT 40' )->fetchAll( PDO::FETCH_ASSOC );
        $moduleReleases = $this->pdo()->query( 'SELECT module_id, version, package_type, visibility, installation_uuid, published_at, channel FROM module_releases ORDER BY published_at DESC LIMIT 120' )->fetchAll( PDO::FETCH_ASSOC );
        $verificationRuns = $this->pdo()->query( 'SELECT target_type, target_ref, verification_profile, status, started_at, completed_at FROM verification_runs ORDER BY id DESC LIMIT 20' )->fetchAll( PDO::FETCH_ASSOC );
        $operationRuns = $this->pdo()->query( 'SELECT operation_type, target_ref, status, requested_by_label, started_at, completed_at FROM admin_operation_runs ORDER BY id DESC LIMIT 20' )->fetchAll( PDO::FETCH_ASSOC );
        $moduleCatalog = $this->currentBuildModules();
        $assignmentMap = $this->installationAssignmentsMap();
        $scopedReleaseMap = $this->installationScopedReleaseMap();
        $moduleStats = $this->moduleReleaseStats();
        $latestCoreVersion = $this->latestCoreReleaseVersion();
        $nextCoreVersion = $this->nextDatedVersion( $latestCoreVersion );
        $customModules = array_filter( $moduleCatalog, static fn( array $module ): bool => ! empty( $module['is_custom'] ) );

        $defaultModuleId = (string) array_key_first( $moduleCatalog );
        $defaultModule = is_array( $moduleCatalog[ $defaultModuleId ] ?? null ) ? $moduleCatalog[ $defaultModuleId ] : [
            'id' => '',
            'version' => '',
            'minimum_metis' => '',
            'source_dir' => '',
            'name' => '',
            'channel' => 'stable',
        ];

        $publishModel = [
            'core_latest_version' => $latestCoreVersion,
            'core_next_version' => $nextCoreVersion,
            'core_next_tag' => 'v' . $nextCoreVersion,
            'modules' => array_values( array_map( static function ( array $module ) use ( $moduleStats ): array {
                $stats = is_array( $moduleStats[ $module['id'] ] ?? null ) ? (array) $moduleStats[ $module['id'] ] : [];
                return [
                    'id' => (string) $module['id'],
                    'name' => (string) $module['name'],
                    'version' => (string) $module['version'],
                    'minimum_metis' => (string) $module['minimum_metis'],
                    'channel' => (string) $module['channel'],
                    'source_dir' => (string) $module['source_dir'],
                    'latest_public' => (string) ( $stats['public_latest'] ?? '' ),
                    'is_custom' => ! empty( $module['is_custom'] ),
                ];
            }, $moduleCatalog ) ),
        ];

        $installationsById = [];
        foreach ( $installations as $installation ) {
            $installationUuid = trim( (string) ( $installation['installation_uuid'] ?? '' ) );
            if ( $installationUuid !== '' ) {
                $installationsById[ $installationUuid ] = $installation;
            }
        }

        $installsWithCustomModules = 0;
        foreach ( $assignmentMap as $assignedModules ) {
            if ( is_array( $assignedModules ) && $assignedModules !== [] ) {
                $installsWithCustomModules++;
            }
        }

        $selectedInstallation = is_array( $installationsById[ $selectedInstallationId ] ?? null ) ? (array) $installationsById[ $selectedInstallationId ] : null;
        $selectedModule = is_array( $moduleCatalog[ $selectedModuleId ] ?? null ) ? (array) $moduleCatalog[ $selectedModuleId ] : null;
        $latestVerification = is_array( $verificationRuns[0] ?? null ) ? (array) $verificationRuns[0] : [];
        $latestOperation = is_array( $operationRuns[0] ?? null ) ? (array) $operationRuns[0] : [];

        header( 'Content-Type: text/html; charset=UTF-8' );
        ob_start();
        ?>
<!doctype html>
<html>
<head>
    <meta charset="utf-8">
    <title>Metis Update Server Admin</title>
    <style>
        body{margin:0;font-family:"Figtree",system-ui,sans-serif;background:linear-gradient(180deg,#f8f4ec 0%,#f2e9dc 52%,#ebe4d7 100%);color:#1f2937}
        .shell{max-width:1280px;margin:0 auto;padding:24px}
        .card{background:rgba(255,255,255,.94);border:1px solid #dccfb7;border-radius:22px;padding:22px;box-shadow:0 16px 45px rgba(42,58,76,.08)}
        .masthead{display:flex;justify-content:space-between;align-items:flex-start;gap:18px;margin-bottom:14px;padding:18px 20px;background:linear-gradient(160deg,#fffdf8 0%,#f7efe2 60%,#f2e6d6 100%)}
        .masthead h1{margin:0 0 6px;font-size:1.85rem;line-height:1.05}
        .masthead p{margin:0;color:#526072;max-width:760px}
        .eyebrow{display:inline-flex;align-items:center;gap:8px;padding:6px 10px;border-radius:999px;background:#efe6d8;color:#6b4d35;font-size:.74rem;font-weight:700;letter-spacing:.06em;text-transform:uppercase;margin-bottom:10px}
        .masthead-side{display:grid;justify-items:end;gap:10px;min-width:280px}
        .summary-pills{display:flex;gap:8px;flex-wrap:wrap;justify-content:flex-end}
        .summary-pills .pill strong{font-size:.95rem}
        .tabbar{display:flex;gap:10px;flex-wrap:wrap;margin:0 0 18px}
        .tabbar a{display:inline-flex;align-items:center;gap:8px;padding:12px 16px;border-radius:999px;background:rgba(255,255,255,.85);color:#334155;border:1px solid #dfd1bb;text-decoration:none;font-weight:600}
        .tabbar a small{display:none}
        .tabbar a.is-active{background:#15384a;color:#fff;border-color:#15384a}
        .quiet-intro{margin:0 0 18px;color:#64748b;font-size:.95rem}
        .flash{margin-bottom:18px;padding:16px 18px;border-radius:16px;border:1px solid}
        .flash-success{background:#edfdf3;border-color:#86efac}
        .flash-error{background:#fff1f2;border-color:#fda4af}
        .grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(320px,1fr));gap:18px;margin-bottom:18px}
        .grid-wide{display:grid;grid-template-columns:1.8fr 1fr;gap:18px;margin-bottom:18px}
        .cards-3{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:12px}
        .cards-2{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:12px}
        .mini-card{padding:16px;border-radius:18px;background:#fff;border:1px solid #eadfcd}
        .mini-card strong{display:block;font-size:1rem}
        .card h2{margin:0 0 10px;font-size:1.12rem}
        .card h3{margin:18px 0 8px;font-size:1rem}
        .section-head{display:flex;justify-content:space-between;gap:16px;align-items:flex-end;margin-bottom:14px}
        .section-head p{margin:0;color:#64748b;max-width:760px}
        .meta{font-size:.92rem;color:#64748b}
        .pills{display:flex;gap:8px;flex-wrap:wrap;margin-top:12px}
        .pill{display:inline-block;padding:6px 10px;border-radius:999px;background:#eef2f7;font-size:.79rem}
        .pill.is-good{background:#dcfce7;color:#166534}
        .pill.is-warn{background:#fef3c7;color:#92400e}
        .wizard-summary{display:grid;gap:4px;font-size:.92rem;color:#475569}
        .focus-panel{display:grid;gap:10px;padding:16px;border-radius:18px;background:#fff;border:1px solid #eadfcd}
        .step-list{display:grid;gap:12px}
        .step{padding:16px 18px;border:1px solid #eadfcd;border-radius:18px;background:#fffdfa}
        .step-number{display:inline-flex;align-items:center;justify-content:center;width:30px;height:30px;border-radius:999px;background:#15384a;color:#fff;font-weight:700;margin-bottom:8px}
        .wizard-header{display:flex;justify-content:space-between;gap:16px;align-items:flex-start;padding:16px 18px;border-radius:18px;background:linear-gradient(160deg,#f7f2e8 0%,#fdfcf8 100%);border:1px solid #e7dcc9;margin-bottom:12px}
        .backlink{display:inline-flex;align-items:center;gap:8px;margin-bottom:14px;color:#15384a;font-weight:600;text-decoration:none}
        .master-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(300px,1fr));gap:16px}
        .list-card{display:grid;gap:12px}
        .list-card .button-row{margin-top:2px}
        .detail-shell{display:grid;gap:16px}
        .detail-card{padding:20px 22px}
        .empty-state{padding:18px;border:1px dashed #d7c8ad;border-radius:18px;background:#fffdf9;color:#526072}
        form{display:grid;gap:10px}
        .row{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:10px}
        .row-3{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:10px}
        .row-4{display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:10px}
        label{display:grid;gap:6px;font-size:.88rem;color:#334155}
        input,textarea,select{width:100%;box-sizing:border-box;padding:11px 12px;border-radius:12px;border:1px solid #cbbba0;background:#fff;color:#111827}
        textarea{min-height:110px;resize:vertical}
        .button-row{display:flex;gap:10px;flex-wrap:wrap}
        .btn{padding:11px 14px;border:0;border-radius:12px;background:#143642;color:#fff;font-weight:600;cursor:pointer;text-decoration:none}
        .btn-alt{background:#8f5f3f}
        .btn-soft{background:#475569}
        .btn-light{background:#f6efe4;color:#1f2937;border:1px solid #dccfb7}
        .checkgrid{display:grid;grid-template-columns:repeat(auto-fit,minmax(220px,1fr));gap:10px}
        .checktile{display:flex;gap:8px;align-items:flex-start;padding:12px;border:1px solid #e7dcc9;border-radius:12px;background:#fbfaf7}
        .checktile input{width:auto;margin-top:3px}
        .help{font-size:.82rem;color:#64748b}
        table{width:100%;border-collapse:collapse;font-size:.88rem}
        th,td{padding:9px 10px;border-bottom:1px solid #e7dcc9;text-align:left;vertical-align:top}
        th{font-size:.76rem;text-transform:uppercase;letter-spacing:.06em;color:#64748b}
        .table-wrap{overflow:auto}
        .code{font-family:"IBM Plex Mono",ui-monospace,monospace;font-size:.8rem;word-break:break-word}
        .pre{white-space:pre-wrap;background:#0f172a;color:#e2e8f0;padding:14px;border-radius:14px;overflow:auto;font-size:.78rem}
        .status-passed,.status-success{color:#166534}.status-failed,.status-error{color:#b91c1c}
        details.advanced{border:1px solid #e7dcc9;border-radius:18px;background:#fcfaf6}
        details.advanced summary{cursor:pointer;list-style:none;padding:16px 18px;font-weight:600}
        details.advanced summary::-webkit-details-marker{display:none}
        details.advanced > div{padding:0 18px 18px}
        @media (max-width:1100px){.grid-wide,.cards-3,.cards-2{grid-template-columns:1fr}.masthead{flex-direction:column}.masthead-side{justify-items:start}.summary-pills{justify-content:flex-start}}
        @media (max-width:980px){.row,.row-3,.row-4{grid-template-columns:1fr}}
    </style>
</head>
<body>
<div class="shell">
    <section class="card masthead">
        <div>
            <span class="eyebrow">Guided operations</span>
            <h1>Metis Update Server</h1>
            <p>Choose an area, then work on one thing at a time. Secondary details stay tucked away until you need them.</p>
        </div>
        <div class="masthead-side">
            <div class="summary-pills">
                <span class="pill"><strong><?php echo htmlspecialchars( (string) count( $installations ) ); ?></strong> installs</span>
                <span class="pill"><strong><?php echo htmlspecialchars( (string) count( $moduleCatalog ) ); ?></strong> modules</span>
                <span class="pill"><strong><?php echo htmlspecialchars( $nextCoreVersion ); ?></strong> next core</span>
            </div>
            <div class="pills" style="margin-top:0;">
                <span class="pill"><?php echo htmlspecialchars( (string) ( $user['email'] ?? '' ) ); ?></span>
                <a class="btn" href="/admin/logout">Log out</a>
            </div>
            <details class="advanced" style="width:100%;">
                <summary>Account tools</summary>
                <div>
                    <form method="post">
                        <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars( $csrf ); ?>">
                        <input type="hidden" name="admin_action" value="save_system_user">
                        <input type="hidden" name="admin_tab" value="<?php echo htmlspecialchars( $tab ); ?>">
                        <input type="hidden" name="admin_redirect" value="<?php echo htmlspecialchars( $requestPath ); ?>">
                        <div class="row">
                            <label>Display name<input type="text" name="display_name" value="<?php echo htmlspecialchars( (string) ( $user['display_name'] ?? '' ) ); ?>"></label>
                            <label>Server user<input type="text" name="system_username" value="<?php echo htmlspecialchars( (string) ( $user['system_username'] ?? '' ) ); ?>" placeholder="jvitarius85"></label>
                        </div>
                        <div class="button-row"><button class="btn-light" type="submit">Save account mapping</button></div>
                    </form>
                    <div class="help" style="margin-top:12px;">One-time browser login link</div>
                    <div class="pre">php cli/admin-link.php --system-user=<?php echo htmlspecialchars( (string) ( $user['system_username'] ?? '<server-user>' ) ); ?> --email=<?php echo htmlspecialchars( (string) ( $user['email'] ?? '' ) ); ?></div>
                </div>
            </details>
        </div>
    </section>

    <div class="tabbar">
        <?php foreach ( [
            'installs' => [ 'label' => 'Installs', 'help' => 'Manage each installation in its own workspace.' ],
            'modules' => [ 'label' => 'Modules', 'help' => 'Set which modules are private and who gets them.' ],
            'releases' => [ 'label' => 'Releases', 'help' => 'Review recent history and checks.' ],
            'publish' => [ 'label' => 'Publish', 'help' => 'Use a guided release workflow.' ],
        ] as $key => $item ) : ?>
            <a href="<?php echo htmlspecialchars( $this->adminTabPath( $key ) ); ?>" class="<?php echo $tab === $key ? 'is-active' : ''; ?>">
                <strong><?php echo htmlspecialchars( (string) $item['label'] ); ?></strong>
                <small><?php echo htmlspecialchars( (string) $item['help'] ); ?></small>
            </a>
        <?php endforeach; ?>
    </div>

    <p class="quiet-intro">
        <?php echo htmlspecialchars( match ( $tab ) {
            'installs' => 'Manage installation ownership, contact details, and private module access.',
            'modules' => 'Decide which modules are public or custom, then review where custom modules are assigned.',
            'releases' => 'Review what shipped recently and whether verification passed.',
            default => 'Walk through a guided publishing flow with sensible defaults.',
        } ); ?>
    </p>

    <?php if ( $flash !== [] ) : ?>
        <div class="flash flash-<?php echo htmlspecialchars( (string) ( $flash['level'] ?? 'success' ) ); ?>">
            <strong><?php echo htmlspecialchars( (string) ( $flash['title'] ?? 'Update' ) ); ?></strong>
            <div><?php echo htmlspecialchars( (string) ( $flash['message'] ?? '' ) ); ?></div>
            <?php $flashDetails = $this->presentFlashDetails( (array) ( $flash['data'] ?? [] ) ); ?>
            <?php if ( $flashDetails !== [] ) : ?>
                <div class="pills">
                    <?php foreach ( $flashDetails as $detail ) : ?>
                        <span class="pill"><?php echo htmlspecialchars( $detail ); ?></span>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </div>
    <?php endif; ?>

    <?php if ( $tab === 'installs' ) : ?>
        <?php if ( is_array( $selectedInstallation ) ) : ?>
            <?php
            $installationId = (string) $selectedInstallation['installation_uuid'];
            $assigned = is_array( $assignmentMap[ $installationId ] ?? null ) ? (array) $assignmentMap[ $installationId ] : [];
            $publishedScoped = is_array( $scopedReleaseMap[ $installationId ] ?? null ) ? (array) $scopedReleaseMap[ $installationId ] : [];
            ?>
            <a class="backlink" href="<?php echo htmlspecialchars( $this->adminTabPath( 'installs' ) ); ?>">&larr; Back to installs</a>
            <div class="detail-shell">
                <section class="card detail-card">
                    <div class="section-head">
                        <div>
                            <h2><?php echo htmlspecialchars( $this->installationDisplayName( $selectedInstallation ) ); ?></h2>
                            <p>Everything here is specific to this installation: who owns it and which custom modules it can receive.</p>
                        </div>
                        <div class="pills">
                            <span class="pill"><?php echo htmlspecialchars( count( $assigned ) . ' custom modules assigned' ); ?></span>
                            <span class="pill"><?php echo htmlspecialchars( 'last seen ' . (string) ( $selectedInstallation['last_seen_at'] ?: 'never' ) ); ?></span>
                        </div>
                    </div>
                    <div class="meta"><?php echo htmlspecialchars( (string) $selectedInstallation['base_url'] ); ?> · Metis <?php echo htmlspecialchars( (string) $selectedInstallation['metis_version'] ); ?> · channel <?php echo htmlspecialchars( (string) $selectedInstallation['channel'] ); ?></div>
                </section>

                <section class="card detail-card">
                        <h2>Installation profile</h2>
                        <p class="meta">This is the human record for the install. Keep it current so releases and support do not depend on shell access.</p>
                        <form method="post">
                            <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars( $csrf ); ?>">
                            <input type="hidden" name="admin_action" value="save_installation_profile">
                            <input type="hidden" name="admin_tab" value="installs">
                            <input type="hidden" name="admin_redirect" value="<?php echo htmlspecialchars( $this->adminInstallPath( $installationId ) ); ?>">
                            <input type="hidden" name="installation_id" value="<?php echo htmlspecialchars( $installationId ); ?>">
                            <div class="row-3">
                                <label>Contact name<input type="text" name="contact_name" value="<?php echo htmlspecialchars( (string) ( $selectedInstallation['contact_name'] ?? '' ) ); ?>"></label>
                                <label>Contact email<input type="email" name="contact_email" value="<?php echo htmlspecialchars( (string) ( $selectedInstallation['contact_email'] ?? '' ) ); ?>"></label>
                                <label>Contact phone<input type="text" name="contact_phone" value="<?php echo htmlspecialchars( (string) ( $selectedInstallation['contact_phone'] ?? '' ) ); ?>"></label>
                            </div>
                            <label>Admin notes<textarea name="admin_notes" placeholder="Owner info, quirks, support notes, or hotfix guidance."><?php echo htmlspecialchars( (string) ( $selectedInstallation['admin_notes'] ?? '' ) ); ?></textarea></label>
                            <div class="button-row"><button class="btn-light" type="submit">Save installation profile</button></div>
                        </form>
                        <details class="advanced" style="margin-top:10px;">
                            <summary>Connection details</summary>
                            <div class="wizard-summary">
                                <span>Installation ID: <span class="code"><?php echo htmlspecialchars( $installationId ); ?></span></span>
                                <span>Registered IP: <?php echo htmlspecialchars( (string) ( $selectedInstallation['registered_ip'] ?: 'unknown' ) ); ?></span>
                                <span>Last seen IP: <?php echo htmlspecialchars( (string) ( $selectedInstallation['last_seen_ip'] ?: 'unknown' ) ); ?></span>
                            </div>
                        </details>
                    </section>

                <section class="card detail-card">
                    <div class="section-head">
                        <div>
                            <h2>Custom module access</h2>
                            <p>Only modules marked custom appear here. Official modules stay available globally and do not need assignment.</p>
                        </div>
                        <span class="pill"><?php echo htmlspecialchars( (string) count( $customModules ) ); ?> custom modules available</span>
                    </div>
                    <form method="post">
                        <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars( $csrf ); ?>">
                        <input type="hidden" name="admin_action" value="save_installation_modules">
                        <input type="hidden" name="admin_tab" value="installs">
                        <input type="hidden" name="admin_redirect" value="<?php echo htmlspecialchars( $this->adminInstallPath( $installationId ) ); ?>">
                        <input type="hidden" name="installation_id" value="<?php echo htmlspecialchars( $installationId ); ?>">
                        <?php if ( $customModules === [] ) : ?>
                            <div class="empty-state">
                                No custom modules are available to assign yet. Mark modules as custom on the Modules page first.
                            </div>
                        <?php else : ?>
                            <div class="checkgrid">
                                <?php foreach ( $customModules as $module ) : ?>
                                    <?php $moduleId = (string) $module['id']; ?>
                                    <label class="checktile">
                                        <input type="checkbox" name="assigned_modules[]" value="<?php echo htmlspecialchars( $moduleId ); ?>" <?php echo isset( $assigned[ $moduleId ] ) ? 'checked' : ''; ?>>
                                        <span>
                                            <strong><?php echo htmlspecialchars( (string) $module['name'] ); ?></strong><br>
                                            <span class="help"><?php echo htmlspecialchars( $moduleId . ' · ' . (string) $module['version'] ); ?></span>
                                            <?php if ( isset( $publishedScoped[ $moduleId ] ) ) : ?>
                                                <br><span class="help">Latest private release: <?php echo htmlspecialchars( (string) $publishedScoped[ $moduleId ]['version'] ); ?></span>
                                            <?php endif; ?>
                                        </span>
                                    </label>
                                <?php endforeach; ?>
                            </div>
                            <div class="button-row" style="margin-top:12px;">
                                <button class="btn" type="submit">Save custom access</button>
                            </div>
                        <?php endif; ?>
                    </form>
                </section>
            </div>
        <?php else : ?>
            <div class="master-grid">
                <?php foreach ( $installations as $installation ) : ?>
                    <?php
                    $installationId = (string) $installation['installation_uuid'];
                    $assigned = is_array( $assignmentMap[ $installationId ] ?? null ) ? (array) $assignmentMap[ $installationId ] : [];
                    ?>
                    <section class="card list-card">
                        <div>
                            <h2><?php echo htmlspecialchars( $this->installationDisplayName( $installation ) ); ?></h2>
                            <div class="meta"><?php echo htmlspecialchars( (string) $installation['base_url'] ); ?> · Metis <?php echo htmlspecialchars( (string) $installation['metis_version'] ); ?> · channel <?php echo htmlspecialchars( (string) $installation['channel'] ); ?></div>
                        </div>
                        <div class="pills">
                            <span class="pill"><?php echo htmlspecialchars( count( $assigned ) . ' custom modules' ); ?></span>
                            <span class="pill"><?php echo htmlspecialchars( 'last seen ' . (string) ( $installation['last_seen_at'] ?: 'never' ) ); ?></span>
                        </div>
                        <div class="wizard-summary"><span><?php echo htmlspecialchars( (string) ( $installation['contact_name'] ?: 'Contact not set yet' ) ); ?></span></div>
                        <div class="button-row">
                            <a class="btn" href="<?php echo htmlspecialchars( $this->adminInstallPath( $installationId ) ); ?>">Manage installation</a>
                        </div>
                    </section>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    <?php elseif ( $tab === 'modules' ) : ?>
        <?php if ( is_array( $selectedModule ) ) : ?>
            <?php
            $moduleStatsRow = is_array( $moduleStats[ $selectedModule['id'] ] ?? null ) ? (array) $moduleStats[ $selectedModule['id'] ] : [];
            $assignedInstallations = [];
            foreach ( $assignmentMap as $installationUuid => $assignedModules ) {
                if ( isset( $assignedModules[ $selectedModule['id'] ] ) && isset( $installationsById[ $installationUuid ] ) ) {
                    $assignedInstallations[] = (array) $installationsById[ $installationUuid ];
                }
            }
            ?>
            <a class="backlink" href="<?php echo htmlspecialchars( $this->adminTabPath( 'modules' ) ); ?>">&larr; Back to modules</a>
            <div class="detail-shell">
                <section class="card detail-card">
                    <div class="section-head">
                        <div>
                            <h2><?php echo htmlspecialchars( (string) $selectedModule['name'] ); ?></h2>
                            <p>Set whether this module is public or custom, then review where custom access is already in use.</p>
                        </div>
                        <div class="pills">
                            <span class="pill <?php echo ! empty( $selectedModule['is_custom'] ) ? 'is-warn' : 'is-good'; ?>"><?php echo ! empty( $selectedModule['is_custom'] ) ? 'Custom module' : 'Public module'; ?></span>
                            <span class="pill"><?php echo htmlspecialchars( (string) $selectedModule['version'] ); ?></span>
                        </div>
                    </div>
                    <div class="pills">
                        <span class="pill">Latest public: <?php echo htmlspecialchars( (string) ( $moduleStatsRow['public_latest'] ?? 'not published yet' ) ); ?></span>
                        <span class="pill"><?php echo htmlspecialchars( (string) count( $assignedInstallations ) ); ?> assigned installs</span>
                        <span class="pill"><?php echo htmlspecialchars( (string) ( $moduleStatsRow['installation_release_count'] ?? 0 ) ); ?> private releases</span>
                    </div>
                </section>

                <section class="card detail-card">
                        <h2>Module settings</h2>
                        <form method="post">
                            <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars( $csrf ); ?>">
                            <input type="hidden" name="admin_action" value="save_module_catalog_settings">
                            <input type="hidden" name="admin_tab" value="modules">
                            <input type="hidden" name="admin_redirect" value="<?php echo htmlspecialchars( $this->adminModulePath( (string) $selectedModule['id'] ) ); ?>">
                            <input type="hidden" name="module_id" value="<?php echo htmlspecialchars( (string) $selectedModule['id'] ); ?>">
                            <label class="pill"><input type="checkbox" name="is_custom" value="1" style="width:auto;margin-right:6px;" <?php echo ! empty( $selectedModule['is_custom'] ) ? 'checked' : ''; ?>>Treat as custom module</label>
                            <label>Admin notes<textarea name="admin_notes" placeholder="Why is this custom, who owns it, or what install family is it for?"><?php echo htmlspecialchars( (string) ( $selectedModule['admin_notes'] ?? '' ) ); ?></textarea></label>
                            <div class="button-row"><button class="btn-light" type="submit">Save module settings</button></div>
                        </form>
                        <details class="advanced" style="margin-top:10px;">
                            <summary>Source details</summary>
                            <div class="wizard-summary">
                                <span>Module ID: <span class="code"><?php echo htmlspecialchars( (string) $selectedModule['id'] ); ?></span></span>
                                <span>Minimum Metis: <?php echo htmlspecialchars( (string) $selectedModule['minimum_metis'] ); ?></span>
                                <span>Source folder: <span class="code"><?php echo htmlspecialchars( (string) $selectedModule['source_dir'] ); ?></span></span>
                            </div>
                        </details>
                    </section>

                <section class="card detail-card">
                    <div class="section-head">
                        <div>
                            <h2>Assignment footprint</h2>
                            <p>If this module is custom, these are the installs currently allowed to receive it.</p>
                        </div>
                    </div>
                    <?php if ( $assignedInstallations === [] ) : ?>
                        <div class="empty-state">This module is not assigned to any installations yet.</div>
                    <?php else : ?>
                        <div class="master-grid">
                            <?php foreach ( $assignedInstallations as $installation ) : ?>
                                <section class="mini-card">
                                    <strong><?php echo htmlspecialchars( $this->installationDisplayName( $installation ) ); ?></strong>
                                    <div class="wizard-summary">
                                        <span><?php echo htmlspecialchars( (string) $installation['base_url'] ); ?></span>
                                        <span>Metis <?php echo htmlspecialchars( (string) $installation['metis_version'] ); ?></span>
                                    </div>
                                </section>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>
                </section>
            </div>
        <?php else : ?>
            <div class="master-grid">
                <?php foreach ( $moduleCatalog as $module ) : ?>
                    <?php
                    $stats = is_array( $moduleStats[ $module['id'] ] ?? null ) ? (array) $moduleStats[ $module['id'] ] : [];
                    $assignedCount = 0;
                    foreach ( $assignmentMap as $assignedModules ) {
                        if ( isset( $assignedModules[ $module['id'] ] ) ) {
                            $assignedCount++;
                        }
                    }
                    ?>
                    <section class="card list-card">
                        <div>
                            <h2><?php echo htmlspecialchars( (string) $module['name'] ); ?></h2>
                            <div class="meta"><?php echo htmlspecialchars( (string) $module['id'] ); ?> · version <?php echo htmlspecialchars( (string) $module['version'] ); ?></div>
                        </div>
                        <div class="pills">
                            <span class="pill <?php echo ! empty( $module['is_custom'] ) ? 'is-warn' : 'is-good'; ?>"><?php echo ! empty( $module['is_custom'] ) ? 'Custom module' : 'Public module'; ?></span>
                        </div>
                        <div class="wizard-summary"><span>Latest public: <?php echo htmlspecialchars( (string) ( $stats['public_latest'] ?? 'not published yet' ) ); ?></span></div>
                        <div class="button-row">
                            <a class="btn" href="<?php echo htmlspecialchars( $this->adminModulePath( (string) $module['id'] ) ); ?>">Manage module</a>
                        </div>
                    </section>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    <?php elseif ( $tab === 'releases' ) : ?>
        <section class="card" style="margin-bottom:18px;">
            <div class="section-head">
                <div>
                    <h2>Release history and checks</h2>
                    <p>This page answers three questions quickly: what shipped recently, whether the last checks passed, and what the admin has done most recently.</p>
                </div>
            </div>
            <div class="cards-3">
                <div class="mini-card">
                    <strong>Last safety check</strong>
                    <div class="wizard-summary">
                        <span>Status: <span class="status-<?php echo htmlspecialchars( (string) ( $latestVerification['status'] ?? 'success' ) ); ?>"><?php echo htmlspecialchars( (string) ( $latestVerification['status'] ?? 'no runs yet' ) ); ?></span></span>
                        <span>Target: <?php echo htmlspecialchars( (string) ( $latestVerification['target_ref'] ?? 'n/a' ) ); ?></span>
                    </div>
                </div>
                <div class="mini-card">
                    <strong>Last admin action</strong>
                    <div class="wizard-summary">
                        <span><?php echo htmlspecialchars( (string) ( $latestOperation['operation_type'] ?? 'no activity yet' ) ); ?></span>
                        <span><?php echo htmlspecialchars( (string) ( $latestOperation['target_ref'] ?? '' ) ); ?></span>
                    </div>
                </div>
                <div class="mini-card">
                    <strong>Suggested next core version</strong>
                    <div class="wizard-summary">
                        <span><?php echo htmlspecialchars( $nextCoreVersion ); ?></span>
                        <span>Latest current release: <?php echo htmlspecialchars( $latestCoreVersion !== '' ? $latestCoreVersion : 'none yet' ); ?></span>
                    </div>
                </div>
            </div>
        </section>
        <div class="grid">
            <section class="card">
                <h2>Recent core releases</h2>
                <div class="table-wrap">
                    <table>
                        <tr><th>Release</th><th>Version</th><th>Channel</th><th>Published</th></tr>
                        <?php foreach ( array_slice( $coreReleases, 0, 8 ) as $row ) : ?>
                            <tr>
                                <td><strong><?php echo htmlspecialchars( $this->adminPackageTypeLabel( (string) $row['package_type'] ) ); ?></strong><div class="help code"><?php echo htmlspecialchars( (string) $row['tag_name'] ); ?></div></td>
                                <td><?php echo htmlspecialchars( (string) $row['version'] ); ?></td>
                                <td><?php echo htmlspecialchars( (string) $row['channel'] ); ?></td>
                                <td><?php echo htmlspecialchars( (string) $row['published_at'] ); ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </table>
                </div>
            </section>
            <section class="card">
                <h2>Recent safety checks</h2>
                <div class="table-wrap">
                    <table>
                        <tr><th>Target</th><th>Profile</th><th>Status</th><th>Finished</th></tr>
                        <?php foreach ( $verificationRuns as $row ) : ?>
                            <tr>
                                <td class="code"><?php echo htmlspecialchars( (string) $row['target_ref'] ); ?></td>
                                <td><?php echo htmlspecialchars( (string) $row['verification_profile'] ); ?></td>
                                <td class="status-<?php echo htmlspecialchars( (string) $row['status'] ); ?>"><?php echo htmlspecialchars( (string) $row['status'] ); ?></td>
                                <td><?php echo htmlspecialchars( (string) ( $row['completed_at'] ?: $row['started_at'] ) ); ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </table>
                </div>
            </section>
        </div>
        <div class="grid">
            <section class="card">
                <h2>Recent module releases</h2>
                <div class="table-wrap">
                    <table>
                        <tr><th>Module</th><th>Release</th><th>Audience</th><th>Version</th><th>Published</th></tr>
                        <?php foreach ( array_slice( $moduleReleases, 0, 12 ) as $row ) : ?>
                            <tr>
                                <td><?php echo htmlspecialchars( (string) $row['module_id'] ); ?></td>
                                <td><?php echo htmlspecialchars( $this->adminPackageTypeLabel( (string) $row['package_type'] ) ); ?></td>
                                <td><?php echo htmlspecialchars( $this->adminPackageAudienceLabel( (string) $row['visibility'], (string) $row['installation_uuid'], $installationsById ) ); ?></td>
                                <td><?php echo htmlspecialchars( (string) $row['version'] ); ?></td>
                                <td><?php echo htmlspecialchars( (string) $row['published_at'] ); ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </table>
                </div>
            </section>
            <section class="card">
                <h2>Recent admin actions</h2>
                <div class="table-wrap">
                    <table>
                        <tr><th>Action</th><th>Target</th><th>Status</th><th>By</th><th>Finished</th></tr>
                        <?php foreach ( $operationRuns as $row ) : ?>
                            <tr>
                                <td><?php echo htmlspecialchars( ucwords( str_replace( '_', ' ', (string) $row['operation_type'] ) ) ); ?></td>
                                <td class="code"><?php echo htmlspecialchars( (string) $row['target_ref'] ); ?></td>
                                <td class="status-<?php echo htmlspecialchars( (string) $row['status'] ); ?>"><?php echo htmlspecialchars( (string) $row['status'] ); ?></td>
                                <td><?php echo htmlspecialchars( (string) $row['requested_by_label'] ); ?></td>
                                <td><?php echo htmlspecialchars( (string) ( $row['completed_at'] ?: $row['started_at'] ) ); ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </table>
                </div>
            </section>
        </div>
    <?php else : ?>
        <div class="grid-wide">
            <section class="card">
                <h2>Guided publish</h2>
                <div class="wizard-header">
                    <div>
                        <strong>Suggested next release</strong>
                        <div class="wizard-summary">
                            <span>Core version: <?php echo htmlspecialchars( $nextCoreVersion ); ?></span>
                            <span>Latest current release: <?php echo htmlspecialchars( $latestCoreVersion !== '' ? $latestCoreVersion : 'none yet' ); ?></span>
                        </div>
                    </div>
                    <div class="pill is-good">Safety checks run before publish by default</div>
                </div>
                <form method="post" id="publish-form">
                    <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars( $csrf ); ?>">
                    <input type="hidden" name="admin_action" value="run_publish">
                    <input type="hidden" name="admin_tab" value="publish">
                    <input type="hidden" name="admin_redirect" value="<?php echo htmlspecialchars( $this->adminTabPath( 'publish' ) ); ?>">
                    <div class="step-list">
                        <div class="step">
                            <div class="step-number">1</div>
                            <h3>Choose what you are releasing</h3>
                            <div class="meta">Pick the release kind first. The rest of the form adapts from this choice.</div>
                            <div class="row-3">
                                <label>Release kind
                                    <select name="type" id="publish-type">
                                        <option value="core_full">Core full release</option>
                                        <option value="core_delta">Core delta update</option>
                                        <option value="module_full">Module full release</option>
                                        <option value="module_delta">Module delta update</option>
                                    </select>
                                </label>
                                <label id="publish-module-wrap">Module
                                    <select name="module_id" id="publish-module-id">
                                        <?php foreach ( $moduleCatalog as $module ) : ?>
                                            <option value="<?php echo htmlspecialchars( (string) $module['id'] ); ?>"><?php echo htmlspecialchars( (string) $module['name'] . ' (' . (string) $module['version'] . ')' ); ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                </label>
                                <label>Release channel<input type="text" name="channel" id="publish-channel" value="<?php echo htmlspecialchars( (string) $defaultModule['channel'] ); ?>"></label>
                            </div>
                        </div>
                        <div class="step">
                            <div class="step-number">2</div>
                            <h3>Choose who should receive it</h3>
                            <div class="row">
                                <label>Audience
                                    <select name="visibility" id="publish-visibility">
                                        <option value="public">Everyone on this channel</option>
                                        <option value="installation">One installation only</option>
                                    </select>
                                </label>
                                <label>Installation
                                    <select name="installation_id" id="publish-installation-id">
                                        <option value="">Choose installation</option>
                                        <?php foreach ( $installations as $installation ) : ?>
                                            <option value="<?php echo htmlspecialchars( (string) $installation['installation_uuid'] ); ?>"><?php echo htmlspecialchars( $this->installationDisplayName( $installation ) ); ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                </label>
                            </div>
                        </div>
                        <div class="step">
                            <div class="step-number">3</div>
                            <h3>Review the release details</h3>
                            <div class="meta">These are filled in for you. Change them only if you need something different.</div>
                            <div class="row-3">
                                <label>Release tag<input type="text" name="tag" id="publish-tag" value="<?php echo htmlspecialchars( 'v' . $nextCoreVersion ); ?>"></label>
                                <label>Release version<input type="text" name="version" id="publish-version" value="<?php echo htmlspecialchars( $nextCoreVersion ); ?>"></label>
                                <label>Update base version<input type="text" name="from_version" id="publish-from-version" value="<?php echo htmlspecialchars( $latestCoreVersion ); ?>"></label>
                            </div>
                            <div class="row">
                                <label>Minimum Metis version<input type="text" name="minimum_metis" id="publish-minimum-metis" value="<?php echo htmlspecialchars( (string) $defaultModule['minimum_metis'] ); ?>"></label>
                                <label>Release summary<textarea name="notes" placeholder="What changed in this release?"></textarea></label>
                            </div>
                        </div>
                        <div class="step">
                            <div class="step-number">4</div>
                            <h3>Optional GitHub delivery</h3>
                            <div class="button-row">
                                <label class="pill"><input type="checkbox" name="publish_to_github" value="1" style="width:auto;margin-right:6px;">Also create a GitHub release</label>
                                <label class="pill"><input type="checkbox" name="github_draft" value="1" style="width:auto;margin-right:6px;">GitHub draft</label>
                                <label class="pill"><input type="checkbox" name="github_prerelease" value="1" style="width:auto;margin-right:6px;">GitHub pre-release</label>
                            </div>
                            <div class="row-4">
                                <label>GitHub repository<input type="text" name="github_repo" placeholder="owner/repo"></label>
                                <label>GitHub tag<input type="text" name="github_tag" id="publish-github-tag" value="<?php echo htmlspecialchars( 'v' . $nextCoreVersion ); ?>"></label>
                                <label>GitHub title<input type="text" name="github_title" id="publish-github-title" value="<?php echo htmlspecialchars( 'Metis ' . $nextCoreVersion ); ?>"></label>
                                <label>Target commit<input type="text" name="github_target" placeholder="stable"></label>
                            </div>
                            <label>GitHub notes<textarea name="github_notes" placeholder="Optional GitHub release notes"></textarea></label>
                        </div>
                    </div>
                    <details class="advanced">
                        <summary>Advanced release controls</summary>
                        <div>
                            <div class="row-4">
                                <label>Minimum PHP<input type="text" name="minimum_php" value="8.1"></label>
                                <label>Verify root<input type="text" name="verify_root" value="/srv/repos/metis"></label>
                                <label>Verify profile<input type="text" name="verify_profile" value="publish_gate"></label>
                                <label>Source folder<input type="text" name="source_dir" id="publish-source-dir" value="<?php echo htmlspecialchars( (string) $defaultModule['source_dir'] ); ?>"></label>
                            </div>
                            <div class="row">
                                <label>Delta base folder<input type="text" name="from_dir" id="publish-from-dir" placeholder="/srv/builds/previous-or-current"></label>
                                <label class="pill" style="align-self:end;"><input type="checkbox" name="skip_verify" value="1" style="width:auto;margin-right:6px;">Skip safety checks before publish</label>
                            </div>
                        </div>
                    </details>
                    <div class="button-row"><button class="btn-alt btn" type="submit">Publish this release</button></div>
                </form>
            </section>
            <section class="card">
                <h2>Before you publish</h2>
                <div class="focus-panel">
                    <strong>Current plan</strong>
                    <div id="publish-plan-summary" class="wizard-summary">
                        <span>Choose a release kind to see the summary here.</span>
                    </div>
                </div>
                <div class="focus-panel">
                    <strong>Detected defaults</strong>
                    <div class="wizard-summary">
                        <span>Latest core release: <?php echo htmlspecialchars( $latestCoreVersion !== '' ? $latestCoreVersion : 'none yet' ); ?></span>
                        <span>Suggested next core version: <?php echo htmlspecialchars( $nextCoreVersion ); ?></span>
                        <span>Default module source: <span class="code"><?php echo htmlspecialchars( (string) $defaultModule['source_dir'] ); ?></span></span>
                    </div>
                </div>
                <h3>Run a safety check first</h3>
                <form method="post">
                    <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars( $csrf ); ?>">
                    <input type="hidden" name="admin_action" value="run_verify">
                    <input type="hidden" name="admin_tab" value="publish">
                    <input type="hidden" name="admin_redirect" value="<?php echo htmlspecialchars( $this->adminTabPath( 'publish' ) ); ?>">
                    <div class="row-3">
                        <label>Release kind
                            <select name="type">
                                <option value="core_full">Core full release</option>
                                <option value="core_delta">Core delta update</option>
                                <option value="module_full">Module full release</option>
                                <option value="module_delta">Module delta update</option>
                            </select>
                        </label>
                        <label>Release version<input type="text" name="version" value="<?php echo htmlspecialchars( $nextCoreVersion ); ?>"></label>
                        <label>Module ID<input type="text" name="module_id" value="<?php echo htmlspecialchars( (string) $defaultModule['id'] ); ?>"></label>
                    </div>
                    <div class="row">
                        <label>Source folder<input type="text" name="source_dir" value="<?php echo htmlspecialchars( (string) $defaultModule['source_dir'] ); ?>"></label>
                        <label>Delta base folder<input type="text" name="from_dir"></label>
                    </div>
                    <div class="row">
                        <label>Verify root<input type="text" name="verify_root" value="/srv/repos/metis"></label>
                        <label>Check profile<input type="text" name="verify_profile" value="publish_gate"></label>
                    </div>
                    <div class="button-row"><button class="btn" type="submit">Run safety check</button></div>
                </form>
                <details class="advanced" style="margin-top:16px;">
                    <summary>Manual GitHub release tool</summary>
                    <div>
                        <form method="post">
                            <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars( $csrf ); ?>">
                            <input type="hidden" name="admin_action" value="run_github_release">
                            <input type="hidden" name="admin_tab" value="publish">
                            <input type="hidden" name="admin_redirect" value="<?php echo htmlspecialchars( $this->adminTabPath( 'publish' ) ); ?>">
                            <div class="row-3">
                                <label>Repository<input type="text" name="repo" placeholder="owner/repo"></label>
                                <label>Tag<input type="text" name="tag" value="<?php echo htmlspecialchars( 'v' . $nextCoreVersion ); ?>"></label>
                                <label>Title<input type="text" name="title" value="<?php echo htmlspecialchars( 'Metis ' . $nextCoreVersion ); ?>"></label>
                            </div>
                            <div class="row">
                                <label>Target commit<input type="text" name="target" placeholder="stable"></label>
                                <label>Asset paths<textarea name="asset_paths" placeholder="/var/www/update.vitarius.org/storage/packages/..."></textarea></label>
                            </div>
                            <label>Notes<textarea name="notes"></textarea></label>
                            <div class="button-row">
                                <label class="pill"><input type="checkbox" name="draft" value="1" style="width:auto;margin-right:6px;">Draft</label>
                                <label class="pill"><input type="checkbox" name="prerelease" value="1" style="width:auto;margin-right:6px;">Pre-release</label>
                            </div>
                            <div class="button-row"><button class="btn-soft btn" type="submit">Create manual GitHub release</button></div>
                        </form>
                    </div>
                </details>
            </section>
        </div>
        <script>
            const publishModel = <?php echo json_encode( $publishModel, JSON_UNESCAPED_SLASHES ); ?>;
            const typeField = document.getElementById('publish-type');
            const moduleField = document.getElementById('publish-module-id');
            const visibilityField = document.getElementById('publish-visibility');
            const installationField = document.getElementById('publish-installation-id');
            const versionField = document.getElementById('publish-version');
            const tagField = document.getElementById('publish-tag');
            const fromVersionField = document.getElementById('publish-from-version');
            const sourceDirField = document.getElementById('publish-source-dir');
            const minimumMetisField = document.getElementById('publish-minimum-metis');
            const channelField = document.getElementById('publish-channel');
            const githubTagField = document.getElementById('publish-github-tag');
            const githubTitleField = document.getElementById('publish-github-title');
            const moduleWrap = document.getElementById('publish-module-wrap');
            const planSummary = document.getElementById('publish-plan-summary');
            const moduleMap = Object.fromEntries((publishModel.modules || []).map(item => [item.id, item]));
            const installationMap = Object.fromEntries(Array.from(installationField.options).map(option => [option.value, option.text]));
            function refreshPublishDefaults() {
                const type = typeField.value;
                const module = moduleMap[moduleField.value] || {};
                const coreNextVersion = publishModel.core_next_version || '';
                const coreLatestVersion = publishModel.core_latest_version || '';
                const isCore = type.startsWith('core_');
                const isDelta = type.endsWith('_delta');
                if (isCore) {
                    versionField.value = coreNextVersion;
                    tagField.value = coreNextVersion ? ('v' + coreNextVersion) : '';
                    fromVersionField.value = isDelta ? coreLatestVersion : '';
                    sourceDirField.value = '';
                    minimumMetisField.value = '';
                    channelField.value = 'stable';
                } else {
                    versionField.value = module.version || '';
                    tagField.value = '';
                    fromVersionField.value = isDelta ? (module.latest_public || '') : '';
                    sourceDirField.value = module.source_dir || '';
                    minimumMetisField.value = module.minimum_metis || '';
                    channelField.value = module.channel || 'stable';
                }
                githubTagField.value = tagField.value || (versionField.value ? ('v' + versionField.value) : '');
                githubTitleField.value = versionField.value ? ('Metis ' + versionField.value) : 'Metis Release';
                installationField.disabled = visibilityField.value !== 'installation';
                if (visibilityField.value !== 'installation') {
                    installationField.value = '';
                }
                moduleWrap.style.display = isCore ? 'none' : 'grid';
                const releaseLabelMap = {
                    core_full: 'a full core release',
                    core_delta: 'a core delta update',
                    module_full: 'a full module release',
                    module_delta: 'a module delta update'
                };
                const audience = visibilityField.value === 'installation' ? (installationMap[installationField.value] || 'one installation') : 'everyone on this channel';
                const subject = isCore ? 'Metis core' : (module.name || moduleField.value || 'selected module');
                const version = versionField.value || 'pending version';
                const baseVersion = fromVersionField.value || 'no base version needed';
                planSummary.innerHTML = ''
                    + '<span>You are preparing <strong>' + (releaseLabelMap[type] || 'a release') + '</strong> for <strong>' + subject + '</strong>.</span>'
                    + '<span>Audience: <strong>' + audience + '</strong>.</span>'
                    + '<span>Version: <strong>' + version + '</strong>' + (isDelta ? ' from <strong>' + baseVersion + '</strong>.' : '.')
                    + '</span>';
            }
            typeField.addEventListener('change', refreshPublishDefaults);
            moduleField.addEventListener('change', refreshPublishDefaults);
            visibilityField.addEventListener('change', refreshPublishDefaults);
            installationField.addEventListener('change', refreshPublishDefaults);
            refreshPublishDefaults();
        </script>
    <?php endif; ?>
</div>
</body>
</html>
        <?php
        echo (string) ob_get_clean();
        exit;
    }

    private function renderLogin( string $error = '' ): void {
        header( 'Content-Type: text/html; charset=UTF-8' );
        $helperPath = trim( (string) ( $this->config['auth']['helper'] ?? '' ) );
        $pamAvailable = $helperPath !== '' && is_file( $helperPath ) && is_executable( $helperPath );
        echo '<!doctype html><html><head><meta charset="utf-8"><title>Metis Update Server Login</title><style>body{margin:0;min-height:100vh;display:grid;place-items:center;background:radial-gradient(circle at top,#efe4ce 0%,#f8f4ea 42%,#e8decf 100%);font-family:"Figtree",system-ui,sans-serif;color:#1f2937}.card{width:min(640px,94vw);padding:32px;border-radius:22px;background:rgba(255,255,255,.92);border:1px solid #d9c8ab;box-shadow:0 24px 50px rgba(20,54,66,.12)}h1{margin:0 0 8px;font-size:2rem}p{margin:0 0 16px;color:#475569}label{display:grid;gap:6px;margin-bottom:12px;font-size:.92rem}input{padding:12px;border:1px solid #cab89a;border-radius:12px}button{padding:12px 14px;border:0;border-radius:12px;background:#143642;color:#fff;font-weight:600;cursor:pointer}.error{margin-bottom:14px;padding:12px;border-radius:12px;background:#fff1f2;border:1px solid #fda4af;color:#9f1239}.help{margin-top:18px;padding-top:16px;border-top:1px solid #eadfcd;color:#475569;font-size:.9rem}.pre{margin-top:10px;background:#0f172a;color:#e2e8f0;padding:12px;border-radius:12px;white-space:pre-wrap;font-size:.8rem}.muted{color:#64748b;font-size:.9rem;margin-bottom:14px}</style></head><body><div class="card">';
        echo '<h1>Metis Update Server</h1><p>Sign in with your server account. This keeps browser access aligned with the same account you already use on the server.</p>';
        if ( $error !== '' ) {
            echo '<div class="error">' . htmlspecialchars( $error ) . '</div>';
        }
        echo '<div class="muted">' . ( $pamAvailable ? 'Use your mapped Linux server username and password through PAM.' : 'PAM helper is not installed yet. Run cli/install-auth-helper.php on the server.' ) . '</div>';
        echo '<form method="post"><input type="hidden" name="login_mode" value="system_password"><label>Server username<input type="text" name="system_username" required></label><label>Password<input type="password" name="password" required></label><button type="submit"' . ( $pamAvailable ? '' : ' disabled' ) . '>Sign In</button></form>';
        echo '<div class="help">One-time login link<div class="pre">php cli/admin-link.php --system-user=&lt;server-user&gt; --email=&lt;admin-email&gt;</div></div>';
        echo '</div></body></html>';
        exit;
    }

    private function authenticateSystemUser( string $systemUsername, string $password ): array {
        $systemUsername = trim( $systemUsername );
        if ( $systemUsername === '' || $password === '' ) {
            return [
                'ok' => false,
                'message' => 'Server username and password are required.',
            ];
        }

        if ( preg_match( '/[^A-Za-z0-9_.-]/', $systemUsername ) === 1 ) {
            return [
                'ok' => false,
                'message' => 'Server username format is invalid.',
            ];
        }

        $helperPath = trim( (string) ( $this->config['auth']['helper'] ?? '' ) );
        if ( $helperPath === '' || ! is_file( $helperPath ) || ! is_executable( $helperPath ) ) {
            return [
                'ok' => false,
                'message' => 'PAM helper is not installed on this server.',
            ];
        }

        $service = trim( (string) ( $this->config['auth']['pam_service'] ?? 'login' ) ) ?: 'login';
        $result = $this->runCommand( $this->root, [ $helperPath, '--service=' . $service, '--username=' . $systemUsername ], $password . "\n" );
        $decoded = json_decode( (string) ( $result['stdout'] ?? '' ), true );
        if ( ! is_array( $decoded ) ) {
            return [
                'ok' => false,
                'message' => 'PAM helper returned an invalid response.',
                'stderr' => (string) ( $result['stderr'] ?? '' ),
            ];
        }

        return [
            'ok' => ! empty( $decoded['ok'] ) && (int) ( $result['exit_code'] ?? 1 ) === 0,
            'message' => (string) ( $decoded['message'] ?? 'Authentication failed.' ),
        ];
    }

    private function loginAdminUser( array $user, string $method ): void {
        $_SESSION['metis_update_admin_id'] = (int) $user['id'];
        $_SESSION['metis_update_admin_method'] = $method;
        $this->pdo()->prepare( 'UPDATE admin_users SET last_login_at = UTC_TIMESTAMP() WHERE id = :id' )->execute( [
            'id' => (int) $user['id'],
        ] );
    }

    private function currentAdminUser(): ?array {
        $id = (int) ( $_SESSION['metis_update_admin_id'] ?? 0 );
        return $id > 0 ? $this->adminUserById( $id ) : null;
    }

    private function adminUserById( int $id ): ?array {
        $stmt = $this->pdo()->prepare( 'SELECT * FROM admin_users WHERE id = :id LIMIT 1' );
        $stmt->execute( [ 'id' => $id ] );
        $user = $stmt->fetch( PDO::FETCH_ASSOC );
        return is_array( $user ) ? $user : null;
    }

    private function adminUserByEmail( string $email ): ?array {
        $stmt = $this->pdo()->prepare( 'SELECT * FROM admin_users WHERE email = :email LIMIT 1' );
        $stmt->execute( [ 'email' => strtolower( trim( $email ) ) ] );
        $user = $stmt->fetch( PDO::FETCH_ASSOC );
        return is_array( $user ) ? $user : null;
    }

    private function adminUserBySystemUsername( string $systemUsername ): ?array {
        $stmt = $this->pdo()->prepare( 'SELECT * FROM admin_users WHERE system_username = :system_username LIMIT 1' );
        $stmt->execute( [ 'system_username' => trim( $systemUsername ) ] );
        $user = $stmt->fetch( PDO::FETCH_ASSOC );
        return is_array( $user ) ? $user : null;
    }

    private function consumeAdminLoginToken( string $token ): ?array {
        $tokenHash = hash( 'sha256', $token );
        $stmt = $this->pdo()->prepare(
            'SELECT t.id AS token_id, u.* FROM admin_login_tokens t INNER JOIN admin_users u ON u.id = t.admin_user_id WHERE t.token_hash = :token_hash AND t.consumed_at IS NULL AND t.expires_at >= UTC_TIMESTAMP() LIMIT 1'
        );
        $stmt->execute( [ 'token_hash' => $tokenHash ] );
        $row = $stmt->fetch( PDO::FETCH_ASSOC );
        if ( ! is_array( $row ) ) {
            return null;
        }

        $this->pdo()->prepare( 'UPDATE admin_login_tokens SET consumed_at = UTC_TIMESTAMP() WHERE id = :id' )->execute( [
            'id' => (int) $row['token_id'],
        ] );

        unset( $row['token_id'] );
        return $row;
    }

    private function adminCsrfToken(): string {
        if ( empty( $_SESSION['metis_update_admin_csrf'] ) || ! is_string( $_SESSION['metis_update_admin_csrf'] ) ) {
            $_SESSION['metis_update_admin_csrf'] = bin2hex( random_bytes( 32 ) );
        }

        return (string) $_SESSION['metis_update_admin_csrf'];
    }

    private function requireAdminCsrf(): void {
        $expected = $this->adminCsrfToken();
        $provided = $this->postValue( 'csrf_token' );
        if ( $provided === '' || ! hash_equals( $expected, $provided ) ) {
            throw new RuntimeException( 'Admin form token is invalid.' );
        }
    }

    private function setAdminFlash( string $level, string $title, string $message, array $data = [] ): void {
        $_SESSION['metis_update_admin_flash'] = [
            'level' => $level,
            'title' => $title,
            'message' => $message,
            'data' => $data,
        ];
    }

    private function consumeAdminFlash(): array {
        $flash = $_SESSION['metis_update_admin_flash'] ?? [];
        unset( $_SESSION['metis_update_admin_flash'] );
        return is_array( $flash ) ? $flash : [];
    }

    private function presentFlashDetails( array $payload ): array {
        if ( $payload === [] ) {
            return [];
        }

        $details = [];
        if ( ! empty( $payload['contact_name'] ) ) {
            $details[] = 'Contact: ' . trim( (string) $payload['contact_name'] );
        }
        if ( ! empty( $payload['contact_email'] ) ) {
            $details[] = 'Email: ' . trim( (string) $payload['contact_email'] );
        }
        if ( ! empty( $payload['module_id'] ) ) {
            $details[] = 'Module: ' . trim( (string) $payload['module_id'] );
        }
        if ( array_key_exists( 'is_custom', $payload ) ) {
            $details[] = ! empty( $payload['is_custom'] ) ? 'Marked as custom' : 'Marked as public';
        }
        if ( ! empty( $payload['assigned_modules'] ) && is_array( $payload['assigned_modules'] ) ) {
            $count = count( $payload['assigned_modules'] );
            $details[] = $count . ' custom module' . ( $count === 1 ? '' : 's' ) . ' assigned';
        }
        if ( ! empty( $payload['tag'] ) ) {
            $details[] = 'Release tag: ' . trim( (string) $payload['tag'] );
        }
        if ( ! empty( $payload['version'] ) ) {
            $details[] = 'Version: ' . trim( (string) $payload['version'] );
        }
        if ( ! empty( $payload['status'] ) ) {
            $details[] = 'Status: ' . trim( (string) $payload['status'] );
        }

        return array_slice( array_values( array_unique( array_filter( $details ) ) ), 0, 5 );
    }

    private function adminTargetRef( string $type, array $input ): string {
        return str_starts_with( $type, 'module_' )
            ? trim( (string) ( $input['module_id'] ?? '' ) ) . '@' . trim( (string) ( $input['version'] ?? '' ) )
            : ( trim( (string) ( $input['tag'] ?? '' ) ) ?: trim( (string) ( $input['version'] ?? '' ) ) );
    }

    private function recordAdminOperation(
        string $operationType,
        string $targetRef,
        string $status,
        int $requestedByAdminId,
        string $requestedByLabel,
        array $input,
        array $result,
        string $startedAt,
        string $completedAt
    ): void {
        $this->pdo()->prepare(
            'INSERT INTO admin_operation_runs (operation_type, target_ref, status, requested_by_admin_id, requested_by_label, input_json, result_json, started_at, completed_at)
             VALUES (:operation_type, :target_ref, :status, :requested_by_admin_id, :requested_by_label, :input_json, :result_json, :started_at, :completed_at)'
        )->execute( [
            'operation_type' => $operationType,
            'target_ref' => $targetRef,
            'status' => $status,
            'requested_by_admin_id' => $requestedByAdminId > 0 ? $requestedByAdminId : null,
            'requested_by_label' => $requestedByLabel,
            'input_json' => json_encode( $input, JSON_UNESCAPED_SLASHES ),
            'result_json' => json_encode( $result, JSON_UNESCAPED_SLASHES ),
            'started_at' => $startedAt,
            'completed_at' => $completedAt,
        ] );
    }

    private function createGitHubRelease( array $options ): array {
        $repo = trim( (string) ( $options['repo'] ?? '' ) );
        $tag = trim( (string) ( $options['tag'] ?? '' ) );
        if ( $repo === '' || $tag === '' ) {
            throw new RuntimeException( 'GitHub repo and tag are required.' );
        }

        $gh = $this->findExecutable( 'gh' );
        if ( $gh === '' ) {
            throw new RuntimeException( 'GitHub CLI is not installed on this server.' );
        }

        $files = [];
        foreach ( (array) ( $options['files'] ?? [] ) as $path ) {
            $candidate = trim( (string) $path );
            if ( $candidate === '' ) {
                continue;
            }
            if ( ! is_file( $candidate ) ) {
                throw new RuntimeException( 'GitHub release asset is missing: ' . $candidate );
            }
            $files[] = $candidate;
        }

        $command = array_merge(
            [ $gh, 'release', 'create', $tag ],
            $files,
            [
                '--repo', $repo,
                '--title', trim( (string) ( $options['title'] ?? '' ) ) !== '' ? trim( (string) $options['title'] ) : $tag,
                '--notes', (string) ( $options['notes'] ?? '' ),
            ]
        );

        $target = trim( (string) ( $options['target'] ?? '' ) );
        if ( $target !== '' ) {
            $command[] = '--target';
            $command[] = $target;
        }
        if ( ! empty( $options['draft'] ) ) {
            $command[] = '--draft';
        }
        if ( ! empty( $options['prerelease'] ) ) {
            $command[] = '--prerelease';
        }

        $result = $this->runCommand( $this->root, $command );
        return [
            'ok' => (int) ( $result['exit_code'] ?? 1 ) === 0,
            'message' => (int) ( $result['exit_code'] ?? 1 ) === 0 ? 'GitHub release created.' : 'GitHub release command failed.',
            'command' => $command,
            'stdout' => (string) ( $result['stdout'] ?? '' ),
            'stderr' => (string) ( $result['stderr'] ?? '' ),
            'repo' => $repo,
            'tag' => $tag,
            'files' => $files,
        ];
    }

    private function prettyJson( array $payload ): string {
        $json = json_encode( $payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES );
        return is_string( $json ) ? $json : '{}';
    }

    private function findExecutable( string $name ): string {
        $paths = preg_split( '/:/', (string) getenv( 'PATH' ) ) ?: [];
        foreach ( $paths as $directory ) {
            $candidate = rtrim( trim( $directory ), '/\\' ) . DIRECTORY_SEPARATOR . $name;
            if ( is_file( $candidate ) && is_executable( $candidate ) ) {
                return $candidate;
            }
        }

        return '';
    }

    private function phpCliBinary(): string {
        $cli = $this->findExecutable( 'php' );
        if ( $cli !== '' ) {
            return $cli;
        }

        $candidates = [
            '/usr/bin/php',
            '/usr/local/bin/php',
            rtrim( PHP_BINDIR, '/\\' ) . '/php',
        ];
        foreach ( $candidates as $candidate ) {
            if ( is_file( $candidate ) && is_executable( $candidate ) ) {
                return $candidate;
            }
        }

        return PHP_BINARY;
    }

    private function verifyRegistrationSignature( array $payload ): void {
        $body = file_get_contents( 'php://input' );
        $publicKey = trim( (string) ( $payload['public_key_pem'] ?? '' ) );
        $signature = base64_decode( trim( $this->serverValue( 'HTTP_X_METIS_SIGNATURE' ) ), true );
        $timestamp = trim( $this->serverValue( 'HTTP_X_METIS_TIMESTAMP' ) );
        $nonce = trim( $this->serverValue( 'HTTP_X_METIS_NONCE' ) );
        $keySha = trim( $this->serverValue( 'HTTP_X_METIS_KEY_SHA256' ) );
        if ( $publicKey === '' || ! is_string( $body ) || $signature === false || $timestamp === '' || $nonce === '' ) {
            $this->json( [ 'error' => 'Registration signature headers are incomplete.' ], 401 );
        }
        if ( $keySha === '' || ! hash_equals( $keySha, hash( 'sha256', $publicKey ) ) ) {
            $this->json( [ 'error' => 'Registration key fingerprint is invalid.' ], 401 );
        }
        $canonical = "POST\n/api/installations/register\n{$timestamp}\n{$nonce}\n" . hash( 'sha256', $body );
        $resource = openssl_pkey_get_public( $publicKey );
        if ( $resource === false || openssl_verify( $canonical, $signature, $resource, OPENSSL_ALGO_SHA256 ) !== 1 ) {
            $this->json( [ 'error' => 'Registration signature verification failed.' ], 401 );
        }
    }

    private function verifySignedRequest( string $path, ?array $payload = null ): array {
        $body = (string) file_get_contents( 'php://input' );
        $installationId = trim( $this->serverValue( 'HTTP_X_METIS_INSTALLATION_ID' ) );
        $signature = base64_decode( trim( $this->serverValue( 'HTTP_X_METIS_SIGNATURE' ) ), true );
        $timestamp = trim( $this->serverValue( 'HTTP_X_METIS_TIMESTAMP' ) );
        $nonce = trim( $this->serverValue( 'HTTP_X_METIS_NONCE' ) );
        if ( $installationId === '' || $signature === false || $timestamp === '' || $nonce === '' ) {
            $this->json( [ 'error' => 'Signed request headers are incomplete.' ], 401 );
        }

        $stmt = $this->pdo()->prepare( 'SELECT * FROM installations WHERE installation_uuid = :installation_uuid AND status = "active" LIMIT 1' );
        $stmt->execute( [ 'installation_uuid' => $installationId ] );
        $installation = $stmt->fetch( PDO::FETCH_ASSOC );
        if ( ! is_array( $installation ) ) {
            $this->json( [ 'error' => 'Installation is not registered.' ], 401 );
        }

        $canonical = $this->requestMethod( 'POST' ) . "\n{$path}\n{$timestamp}\n{$nonce}\n" . hash( 'sha256', $body );
        $resource = openssl_pkey_get_public( (string) $installation['public_key_pem'] );
        if ( $resource === false || openssl_verify( $canonical, $signature, $resource, OPENSSL_ALGO_SHA256 ) !== 1 ) {
            $this->json( [ 'error' => 'Signed request verification failed.' ], 401 );
        }

        $payload = is_array( $payload ) ? $payload : $this->readJsonBody();
        $metisVersion = trim( (string) ( $payload['core']['version'] ?? $payload['metis_version'] ?? '' ) );
        $moduleInventory = [];
        if ( isset( $payload['modules'] ) && is_array( $payload['modules'] ) ) {
            $moduleInventory = array_values( $payload['modules'] );
        } elseif ( isset( $payload['module_inventory'] ) && is_array( $payload['module_inventory'] ) ) {
            $moduleInventory = array_values( $payload['module_inventory'] );
        }

        $update = $this->pdo()->prepare(
            'UPDATE installations SET metis_version = CASE WHEN :metis_version = "" THEN metis_version ELSE :metis_version END, module_inventory_json = CASE WHEN :module_inventory_json = "" THEN module_inventory_json ELSE :module_inventory_json END, last_seen_ip = :last_seen_ip, last_seen_at = UTC_TIMESTAMP() WHERE installation_uuid = :installation_uuid'
        );
        $update->execute( [
            'metis_version' => $metisVersion,
            'module_inventory_json' => $moduleInventory !== [] ? ( json_encode( $moduleInventory, JSON_UNESCAPED_SLASHES ) ?: '' ) : '',
            'last_seen_ip' => $this->remoteAddress(),
            'installation_uuid' => $installationId,
        ] );

        return $installation;
    }

    private function probeInstallationCron( array $installation, array $payload ): void {
        $trigger = trim( (string) ( $payload['trigger'] ?? 'installer_probe' ) ) ?: 'installer_probe';
        $result = $this->triggerSingleInstallationCron( $installation, $trigger );
        $status = ! empty( $result['ok'] ) ? 200 : 422;
        $this->json( [
            'ok' => ! empty( $result['ok'] ),
            'trigger' => $trigger,
            'result' => $result,
        ], $status );
    }

    private function buildDeltaManifest( string $sourceDir, string $fromDir ): array {
        $files = [];
        $delete = [];
        $sourceMap = $this->directoryHashMap( $sourceDir );
        $fromMap = $fromDir !== '' && is_dir( $fromDir ) ? $this->directoryHashMap( $fromDir ) : [];

        foreach ( $sourceMap as $relative => $sourcePath ) {
            $sha = hash_file( 'sha256', $sourcePath ) ?: '';
            $fromSha = isset( $fromMap[ $relative ] ) ? ( hash_file( 'sha256', $fromMap[ $relative ] ) ?: '' ) : '';
            if ( $fromSha !== '' && hash_equals( $sha, $fromSha ) ) {
                continue;
            }
            $files[] = [
                'path' => $relative,
                'sha256' => $sha,
                'size' => (int) filesize( $sourcePath ),
            ];
        }

        foreach ( $fromMap as $relative => $_ ) {
            if ( ! isset( $sourceMap[ $relative ] ) ) {
                $delete[] = $relative;
            }
        }

        sort( $delete );
        usort( $files, static fn ( array $left, array $right ): int => strcmp( (string) $left['path'], (string) $right['path'] ) );

        return [ $files, $delete ];
    }

    private function buildZipPackage( string $archivePath, array $manifest, string $sourceDir, string $fromDir ): void {
        $zip = new ZipArchive();
        if ( $zip->open( $archivePath, ZipArchive::CREATE | ZipArchive::OVERWRITE ) !== true ) {
            throw new RuntimeException( 'Unable to create the core package archive.' );
        }

        $manifestJson = json_encode( $manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES );
        if ( ! is_string( $manifestJson ) ) {
            throw new RuntimeException( 'Unable to encode the package manifest.' );
        }
        $zip->addFromString( 'metis-package.json', $manifestJson . PHP_EOL );
        $zip->addFromString( 'metis-package.sig', $this->sign( $manifestJson ) );

        $sourceMap = $this->directoryHashMap( $sourceDir );
        foreach ( (array) $manifest['files'] as $row ) {
            $relative = (string) ( $row['path'] ?? '' );
            if ( $relative !== '' && isset( $sourceMap[ $relative ] ) ) {
                $zip->addFile( $sourceMap[ $relative ], 'payload/' . $relative );
            }
        }

        $zip->close();
    }

    private function buildTarGzPackage( string $archivePath, array $manifest, string $sourceDir, string $fromDir ): void {
        $buildDir = sys_get_temp_dir() . '/metis-update-server-' . bin2hex( random_bytes( 8 ) );
        if ( ! mkdir( $buildDir, 0775, true ) && ! is_dir( $buildDir ) ) {
            throw new RuntimeException( 'Unable to create the module package build directory.' );
        }

        file_put_contents( $buildDir . '/metis-package.json', json_encode( $manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ) . PHP_EOL );
        file_put_contents( $buildDir . '/metis-package.sig', $this->sign( (string) file_get_contents( $buildDir . '/metis-package.json' ) ) );
        mkdir( $buildDir . '/payload', 0775, true );

        $sourceMap = $this->directoryHashMap( $sourceDir );
        foreach ( (array) $manifest['files'] as $row ) {
            $relative = (string) ( $row['path'] ?? '' );
            if ( $relative === '' || ! isset( $sourceMap[ $relative ] ) ) {
                continue;
            }
            $target = $buildDir . '/payload/' . $relative;
            $targetDir = dirname( $target );
            if ( ! is_dir( $targetDir ) ) {
                mkdir( $targetDir, 0775, true );
            }
            copy( $sourceMap[ $relative ], $target );
        }

        $tarPath = preg_replace( '/\.gz$/', '', $archivePath ) ?: ( $archivePath . '.tar' );
        if ( file_exists( $tarPath ) ) {
            unlink( $tarPath );
        }
        if ( file_exists( $archivePath ) ) {
            unlink( $archivePath );
        }

        $phar = new PharData( $tarPath );
        $phar->buildFromDirectory( $buildDir );
        $phar->compress( Phar::GZ );
        unset( $phar );
        if ( file_exists( $tarPath ) ) {
            unlink( $tarPath );
        }
        rename( $tarPath . '.gz', $archivePath );
        $this->removeDirectory( $buildDir );
    }

    private function fetchCoreReleaseIdByVersion( string $version ): int {
        $stmt = $this->pdo()->prepare( 'SELECT id FROM core_releases WHERE version = :version LIMIT 1' );
        $stmt->execute( [ 'version' => $version ] );
        return (int) $stmt->fetchColumn();
    }

    private function fetchModuleReleaseId( string $moduleId, string $version, string $visibility, string $installationId ): int {
        $stmt = $this->pdo()->prepare(
            'SELECT id FROM module_releases WHERE module_id = :module_id AND version = :version AND visibility = :visibility AND (installation_uuid <=> :installation_uuid) LIMIT 1'
        );
        $stmt->execute( [
            'module_id' => $moduleId,
            'version' => $version,
            'visibility' => $visibility,
            'installation_uuid' => $installationId !== '' ? $installationId : null,
        ] );
        return (int) $stmt->fetchColumn();
    }

    private function issueArtifactToken( string $installationUuid, string $kind, string $table, int $rowId ): string {
        $token = bin2hex( random_bytes( 32 ) );
        $stmt = $this->pdo()->prepare(
            'INSERT INTO artifact_tokens (token, installation_uuid, artifact_kind, artifact_table_name, artifact_row_id, expires_at)
             VALUES (:token, :installation_uuid, :artifact_kind, :artifact_table_name, :artifact_row_id, DATE_ADD(UTC_TIMESTAMP(), INTERVAL 1 DAY))'
        );
        $stmt->execute( [
            'token' => $token,
            'installation_uuid' => $installationUuid,
            'artifact_kind' => $kind,
            'artifact_table_name' => $table,
            'artifact_row_id' => $rowId,
        ] );

        return rtrim( (string) $this->config['base_url'], '/' ) . '/download.php?token=' . rawurlencode( $token );
    }

    private function triggerSingleInstallationCron( array $installation, string $trigger ): array {
        $installationUuid = trim( (string) ( $installation['installation_uuid'] ?? '' ) );
        $baseUrl = rtrim( trim( (string) ( $installation['base_url'] ?? '' ) ), '/' );
        $endpointUrl = $baseUrl !== '' ? $baseUrl . '/e/cj' : '';
        $canonicalPath = '/api/cron';
        $body = json_encode( [ 'trigger' => $trigger ], JSON_UNESCAPED_SLASHES );
        if ( $installationUuid === '' || $endpointUrl === '' || ! is_string( $body ) ) {
            $result = [
                'installation_id' => $installationUuid,
                'installation_name' => (string) ( $installation['installation_name'] ?? '' ),
                'endpoint_url' => $endpointUrl,
                'ok' => false,
                'status' => 0,
                'error' => 'Installation is missing base URL or id.',
                'body' => '',
                'json' => [],
            ];
            $this->audit( $installationUuid !== '' ? $installationUuid : null, 'cron_trigger', 500, $result );
            return $result;
        }

        $headers = $this->signedInstallationHeaders( $installationUuid, 'POST', $canonicalPath, $body );
        $response = $this->httpJsonRequest( 'POST', $endpointUrl, $headers + [ 'Content-Type' => 'application/json' ], $body );
        $status = (int) ( $response['status'] ?? 0 );
        $json = is_array( $response['json'] ?? null ) ? (array) $response['json'] : [];
        $bodyText = (string) ( $response['body'] ?? '' );
        $ok = $status >= 200 && $status < 300;

        $result = [
            'installation_id' => $installationUuid,
            'installation_name' => (string) ( $installation['installation_name'] ?? '' ),
            'endpoint_url' => $endpointUrl,
            'ok' => $ok,
            'status' => $status,
            'error' => $ok ? '' : ( trim( (string) ( $json['error'] ?? '' ) ) !== '' ? trim( (string) ( $json['error'] ?? '' ) ) : trim( $bodyText ) ),
            'body' => $bodyText,
            'json' => $json,
        ];

        $this->audit( $installationUuid, 'cron_trigger', $status > 0 ? $status : 500, $result );
        return $result;
    }

    private function signedInstallationHeaders( string $installationUuid, string $method, string $path, string $body ): array {
        $timestamp = gmdate( 'c' );
        $nonce = bin2hex( random_bytes( 16 ) );
        $canonical = strtoupper( trim( $method ) ) . "\n"
            . trim( $path ) . "\n"
            . $timestamp . "\n"
            . $nonce . "\n"
            . hash( 'sha256', $body );
        $publicKey = (string) @file_get_contents( $this->config['keys']['public'] );

        return [
            'X-Metis-Installation-Id' => $installationUuid,
            'X-Metis-Timestamp' => $timestamp,
            'X-Metis-Nonce' => $nonce,
            'X-Metis-Signature' => $this->signPayloadForHeader( $canonical, 'Unable to sign the installation cron request.' ),
            'X-Metis-Key-Sha256' => $publicKey !== '' ? hash( 'sha256', $publicKey ) : '',
            'Accept' => 'application/json',
            'User-Agent' => 'Metis-Update-Server/1.0',
        ];
    }

    private function httpJsonRequest( string $method, string $url, array $headers, string $body ): array {
        $formatted = [];
        foreach ( $headers as $name => $value ) {
            if ( trim( (string) $name ) === '' || trim( (string) $value ) === '' ) {
                continue;
            }
            $formatted[] = trim( (string) $name ) . ': ' . trim( (string) $value );
        }

        if ( function_exists( 'curl_init' ) ) {
            $ch = curl_init( $url );
            if ( $ch === false ) {
                throw new RuntimeException( 'Unable to initialize cron trigger HTTP client.' );
            }

            curl_setopt_array( $ch, [
                CURLOPT_CUSTOMREQUEST => strtoupper( $method ),
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_FOLLOWLOCATION => false,
                CURLOPT_CONNECTTIMEOUT => 10,
                CURLOPT_TIMEOUT => 30,
                CURLOPT_HTTPHEADER => $formatted,
                CURLOPT_POSTFIELDS => $body,
                CURLOPT_SSL_VERIFYPEER => true,
                CURLOPT_SSL_VERIFYHOST => 2,
            ] );

            $responseBody = curl_exec( $ch );
            $status = (int) curl_getinfo( $ch, CURLINFO_RESPONSE_CODE );
            $error = curl_error( $ch );
            if ( \PHP_VERSION_ID < 80500 ) {
                curl_close( $ch );
            }

            if ( $responseBody === false ) {
                return [
                    'status' => $status > 0 ? $status : 0,
                    'body' => '',
                    'json' => [],
                    'error' => $error !== '' ? $error : 'HTTP request failed.',
                ];
            }

            $responseBody = (string) $responseBody;
            return [
                'status' => $status,
                'body' => $responseBody,
                'json' => json_decode( $responseBody, true ) ?: [],
                'error' => '',
            ];
        }

        $context = stream_context_create( [
            'http' => [
                'method' => strtoupper( $method ),
                'header' => implode( "\r\n", $formatted ) . "\r\n",
                'content' => $body,
                'ignore_errors' => true,
                'timeout' => 30,
                'follow_location' => 0,
                'max_redirects' => 0,
            ],
        ] );

        $responseBody = @file_get_contents( $url, false, $context );
        $meta = $http_response_header ?? [];
        $status = 0;
        if ( isset( $meta[0] ) && preg_match( '/\s(\d{3})\s/', (string) $meta[0], $matches ) ) {
            $status = (int) $matches[1];
        }

        if ( ! is_string( $responseBody ) ) {
            return [
                'status' => $status,
                'body' => '',
                'json' => [],
                'error' => 'HTTP request failed.',
            ];
        }

        return [
            'status' => $status,
            'body' => $responseBody,
            'json' => json_decode( $responseBody, true ) ?: [],
            'error' => '',
        ];
    }

    private function sign( string $manifestJson ): string {
        return $this->signPayload( $manifestJson, 'Unable to sign the package manifest.' );
    }

    private function signPayloadForHeader( string $payload, string $errorMessage ): string {
        $signature = $this->signPayload( $payload, $errorMessage );
        return rtrim( strtr( $signature, '+/', '-_' ), '=' );
    }

    private function signPayload( string $payload, string $errorMessage ): string {
        $privateKey = openssl_pkey_get_private( (string) file_get_contents( $this->config['keys']['private'] ) );
        if ( $privateKey === false ) {
            throw new RuntimeException( 'Server private key is invalid.' );
        }
        $signature = '';
        if ( ! openssl_sign( $payload, $signature, $privateKey, OPENSSL_ALGO_SHA256 ) || $signature === '' ) {
            throw new RuntimeException( $errorMessage );
        }

        return base64_encode( $signature );
    }

    private function directoryHashMap( string $sourceDir ): array {
        $map = [];
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator( $sourceDir, FilesystemIterator::SKIP_DOTS )
        );
        foreach ( $iterator as $item ) {
            if ( ! $item instanceof SplFileInfo || ! $item->isFile() ) {
                continue;
            }
            $path = str_replace( '\\', '/', $item->getPathname() );
            $relative = ltrim( substr( $path, strlen( rtrim( str_replace( '\\', '/', $sourceDir ), '/' ) ) ), '/' );
            $map[ $relative ] = $path;
        }
        ksort( $map );
        return $map;
    }

    private function removeDirectory( string $path ): void {
        if ( ! is_dir( $path ) ) {
            return;
        }

        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator( $path, FilesystemIterator::SKIP_DOTS ),
            RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ( $iterator as $item ) {
            if ( $item->isDir() ) {
                rmdir( $item->getPathname() );
            } else {
                unlink( $item->getPathname() );
            }
        }
        rmdir( $path );
    }

    private function readJsonBody(): array {
        $raw = (string) file_get_contents( 'php://input' );
        $decoded = json_decode( $raw, true );
        return is_array( $decoded ) ? $decoded : [];
    }

    private function requireMethod( string $expected ): void {
        if ( $this->requestMethod() !== strtoupper( $expected ) ) {
            $this->json( [ 'error' => 'Method not allowed.' ], 405 );
        }
    }

    private function requestMethod( string $default = 'GET' ): string {
        return strtoupper( $this->serverValue( 'REQUEST_METHOD', $default ) );
    }

    private function serverValue( string $key, string $default = '' ): string {
        $value = filter_input( INPUT_SERVER, $key, FILTER_UNSAFE_RAW, FILTER_NULL_ON_FAILURE );
        if ( ! is_string( $value ) || $value === '' ) {
            return $default;
        }

        return $value;
    }

    private function remoteAddress(): string {
        $forwarded = trim( $this->serverValue( 'HTTP_X_FORWARDED_FOR' ) );
        if ( $forwarded !== '' ) {
            $first = trim( (string) explode( ',', $forwarded )[0] );
            if ( $first !== '' ) {
                return substr( $first, 0, 64 );
            }
        }

        return substr( trim( $this->serverValue( 'REMOTE_ADDR' ) ), 0, 64 );
    }

    private function getValue( string $key, string $default = '' ): string {
        $value = filter_input( INPUT_GET, $key, FILTER_UNSAFE_RAW, FILTER_NULL_ON_FAILURE );
        if ( ! is_string( $value ) || $value === '' ) {
            return $default;
        }

        return $value;
    }

    private function postValue( string $key, string $default = '' ): string {
        $value = filter_input( INPUT_POST, $key, FILTER_UNSAFE_RAW, FILTER_NULL_ON_FAILURE );
        if ( ! is_string( $value ) || $value === '' ) {
            return $default;
        }

        return $value;
    }

    private function pdo(): PDO {
        static $pdo = null;
        if ( $pdo instanceof PDO ) {
            return $pdo;
        }

        $pdo = new PDO(
            (string) $this->config['dsn'],
            (string) $this->config['db_user'],
            (string) $this->config['db_pass'],
            [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            ]
        );

        return $pdo;
    }

    private function json( array $payload, int $status = 200 ): void {
        http_response_code( $status );
        header( 'Content-Type: application/json' );
        echo json_encode( $payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ) ?: '{"error":"json_encode_failed"}';
        exit;
    }

    private function redirect( string $path ): void {
        header( 'Location: ' . $path );
        exit;
    }

    private function audit( ?string $installationUuid, string $action, int $status, array $details ): void {
        $stmt = $this->pdo()->prepare(
            'INSERT INTO request_audit (installation_uuid, action_type, status_code, details_json) VALUES (:installation_uuid, :action_type, :status_code, :details_json)'
        );
        $stmt->execute( [
            'installation_uuid' => $installationUuid,
            'action_type' => $action,
            'status_code' => $status,
            'details_json' => json_encode( $details, JSON_UNESCAPED_SLASHES ),
        ] );
    }

    private function uuidV4(): string {
        $bytes = random_bytes( 16 );
        $bytes[6] = chr( ( ord( $bytes[6] ) & 0x0f ) | 0x40 );
        $bytes[8] = chr( ( ord( $bytes[8] ) & 0x3f ) | 0x80 );
        $hex = bin2hex( $bytes );
        return sprintf(
            '%s-%s-%s-%s-%s',
            substr( $hex, 0, 8 ),
            substr( $hex, 8, 4 ),
            substr( $hex, 12, 4 ),
            substr( $hex, 16, 4 ),
            substr( $hex, 20, 12 )
        );
    }

    private function migrateSchema(): void {
        $this->ensureColumn(
            'admin_users',
            'display_name',
            "ALTER TABLE admin_users ADD COLUMN display_name VARCHAR(190) NOT NULL DEFAULT '' AFTER email"
        );
        $this->ensureColumn(
            'admin_users',
            'system_username',
            'ALTER TABLE admin_users ADD COLUMN system_username VARCHAR(190) DEFAULT NULL AFTER password_hash'
        );
        $this->ensureIndex(
            'admin_users',
            'uniq_system_username',
            'ALTER TABLE admin_users ADD UNIQUE KEY uniq_system_username (system_username)'
        );
        $this->ensureColumn(
            'installations',
            'registered_ip',
            "ALTER TABLE installations ADD COLUMN registered_ip VARCHAR(64) NOT NULL DEFAULT '' AFTER module_inventory_json"
        );
        $this->ensureColumn(
            'installations',
            'last_seen_ip',
            "ALTER TABLE installations ADD COLUMN last_seen_ip VARCHAR(64) NOT NULL DEFAULT '' AFTER registered_ip"
        );
        $this->ensureColumn(
            'installations',
            'contact_name',
            "ALTER TABLE installations ADD COLUMN contact_name VARCHAR(190) NOT NULL DEFAULT '' AFTER last_seen_ip"
        );
        $this->ensureColumn(
            'installations',
            'contact_email',
            "ALTER TABLE installations ADD COLUMN contact_email VARCHAR(190) NOT NULL DEFAULT '' AFTER contact_name"
        );
        $this->ensureColumn(
            'installations',
            'contact_phone',
            "ALTER TABLE installations ADD COLUMN contact_phone VARCHAR(64) NOT NULL DEFAULT '' AFTER contact_email"
        );
        $this->ensureColumn(
            'installations',
            'admin_notes',
            "ALTER TABLE installations ADD COLUMN admin_notes TEXT NOT NULL AFTER contact_phone"
        );
        $this->pdo()->exec(
            'CREATE TABLE IF NOT EXISTS installation_module_assignments (
                id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
                installation_uuid CHAR(36) NOT NULL,
                module_id VARCHAR(100) NOT NULL,
                notes VARCHAR(255) NOT NULL DEFAULT \'\',
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                UNIQUE KEY uniq_install_module (installation_uuid, module_id),
                KEY idx_module_install (module_id, installation_uuid)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
        );
        $this->pdo()->exec(
            'CREATE TABLE IF NOT EXISTS module_catalog_settings (
                id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
                module_id VARCHAR(100) NOT NULL UNIQUE,
                is_custom TINYINT(1) NOT NULL DEFAULT 0,
                admin_notes TEXT NOT NULL,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                KEY idx_is_custom (is_custom, module_id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
        );
    }

    private function ensureColumn( string $table, string $column, string $sql ): void {
        $stmt = $this->pdo()->prepare( 'SHOW COLUMNS FROM `' . str_replace( '`', '``', $table ) . '` LIKE :column' );
        $stmt->execute( [ 'column' => $column ] );
        if ( $stmt->fetch( PDO::FETCH_ASSOC ) !== false ) {
            return;
        }

        $this->pdo()->exec( $sql );
    }

    private function ensureIndex( string $table, string $index, string $sql ): void {
        $stmt = $this->pdo()->prepare( 'SHOW INDEX FROM `' . str_replace( '`', '``', $table ) . '` WHERE Key_name = :index_name' );
        $stmt->execute( [ 'index_name' => $index ] );
        if ( $stmt->fetch( PDO::FETCH_ASSOC ) !== false ) {
            return;
        }

        $this->pdo()->exec( $sql );
    }

    private function runVerification( array $options, string $type, string $version, string $profile ): array {
        $repoRoot = $this->resolveVerificationRoot( $options );
        if ( $repoRoot === '' ) {
            return [
                'ok' => false,
                'status' => 'verification_root_missing',
                'profile' => $profile,
                'repo_root' => '',
                'steps' => [],
                'message' => 'Publish verification requires a Metis repository root. Pass --verify-root or publish from a path inside the Metis repository.',
            ];
        }

        $startedAt = gmdate( 'Y-m-d H:i:s' );
        $steps = [];
        $failed = false;
        foreach ( $this->verificationCommands( $repoRoot, $profile, $type ) as $step ) {
            $result = $this->runCommand( (string) $repoRoot, (array) $step['command'] );
            $steps[] = [
                'label' => (string) $step['label'],
                'command' => array_values( (array) $step['command'] ),
                'exit_code' => (int) ( $result['exit_code'] ?? 1 ),
                'stdout' => (string) ( $result['stdout'] ?? '' ),
                'stderr' => (string) ( $result['stderr'] ?? '' ),
            ];
            if ( (int) ( $result['exit_code'] ?? 1 ) !== 0 ) {
                $failed = true;
                break;
            }
        }

        return [
            'ok' => ! $failed,
            'status' => $failed ? 'failed' : 'passed',
            'profile' => $profile,
            'repo_root' => $repoRoot,
            'started_at' => $startedAt,
            'completed_at' => gmdate( 'Y-m-d H:i:s' ),
            'steps' => $steps,
            'message' => $failed ? 'Verification failed. Review the failing command output.' : 'Verification passed.',
        ];
    }

    private function resolveVerificationRoot( array $options ): string {
        $explicit = rtrim( trim( (string) ( $options['verify_root'] ?? '' ) ), '/\\' );
        if ( $explicit !== '' ) {
            return $this->isMetisRepoRoot( $explicit ) ? $explicit : '';
        }

        foreach ( [ 'source_dir', 'from_dir' ] as $key ) {
            $path = rtrim( trim( (string) ( $options[ $key ] ?? '' ) ), '/\\' );
            if ( $path === '' ) {
                continue;
            }

            $candidate = is_dir( $path ) ? $path : dirname( $path );
            while ( $candidate !== '' && $candidate !== '/' && $candidate !== '.' ) {
                if ( $this->isMetisRepoRoot( $candidate ) ) {
                    return $candidate;
                }
                $parent = dirname( $candidate );
                if ( $parent === $candidate ) {
                    break;
                }
                $candidate = $parent;
            }
        }

        return '';
    }

    private function isMetisRepoRoot( string $path ): bool {
        return is_file( rtrim( $path, '/\\' ) . '/tools/governance/run-ajax-ui-hardening-regression.php' )
            && is_dir( rtrim( $path, '/\\' ) . '/system/tests' );
    }

    private function verificationCommands( string $repoRoot, string $profile, string $type ): array {
        if ( $profile !== 'publish_gate' ) {
            throw new RuntimeException( 'Unsupported verification profile [' . $profile . '].' );
        }

        $php = $this->phpCliBinary();
        $commands = [
            [
                'label' => 'AJAX/UI hardening regression runner',
                'command' => [ $php, $repoRoot . '/tools/governance/run-ajax-ui-hardening-regression.php' ],
            ],
            [
                'label' => 'People directory service contract',
                'command' => [ $php, $repoRoot . '/system/tests/people_directory_service_contract_test.php' ],
            ],
        ];

        return $commands;
    }

    private function runCommand( string $cwd, array $command, string $stdin = '' ): array {
        $descriptor = [
            0 => [ 'pipe', 'r' ],
            1 => [ 'pipe', 'w' ],
            2 => [ 'pipe', 'w' ],
        ];
        $process = proc_open( $command, $descriptor, $pipes, $cwd );
        if ( ! is_resource( $process ) ) {
            return [
                'exit_code' => 1,
                'stdout' => '',
                'stderr' => 'Failed to start verification command.',
            ];
        }

        if ( $stdin !== '' ) {
            fwrite( $pipes[0], $stdin );
        }
        fclose( $pipes[0] );
        $stdout = stream_get_contents( $pipes[1] );
        $stderr = stream_get_contents( $pipes[2] );
        fclose( $pipes[1] );
        fclose( $pipes[2] );
        $exitCode = proc_close( $process );

        return [
            'exit_code' => is_int( $exitCode ) ? $exitCode : 1,
            'stdout' => is_string( $stdout ) ? $stdout : '',
            'stderr' => is_string( $stderr ) ? $stderr : '',
        ];
    }

    private function recordVerificationRun( string $targetType, string $targetRef, array $verification ): int {
        $stmt = $this->pdo()->prepare(
            'INSERT INTO verification_runs (target_type, target_ref, verification_profile, status, started_at, completed_at, summary_json)
             VALUES (:target_type, :target_ref, :verification_profile, :status, :started_at, :completed_at, :summary_json)'
        );
        $stmt->execute( [
            'target_type' => $targetType,
            'target_ref' => $targetRef,
            'verification_profile' => (string) ( $verification['profile'] ?? 'publish_gate' ),
            'status' => (string) ( $verification['status'] ?? 'unknown' ),
            'started_at' => (string) ( $verification['started_at'] ?? gmdate( 'Y-m-d H:i:s' ) ),
            'completed_at' => (string) ( $verification['completed_at'] ?? gmdate( 'Y-m-d H:i:s' ) ),
            'summary_json' => json_encode( $verification, JSON_UNESCAPED_SLASHES ),
        ] );

        return (int) $this->pdo()->lastInsertId();
    }
}
