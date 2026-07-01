<?php
declare(strict_types=1);

if ( PHP_SAPI !== 'cli' ) {
    fwrite( STDERR, "This installer must run from the CLI.\n" );
    exit( 1 );
}

require_once dirname(__DIR__) . '/src/bootstrap.php';

function metis_update_server_cli_option(array $argv, string $name, string $default = ''): string {
    $prefix = '--' . $name . '=';
    foreach ($argv as $arg) {
        if (str_starts_with((string) $arg, $prefix)) {
            return trim(substr((string) $arg, strlen($prefix)));
        }
    }
    return $default;
}

$dsn = metis_update_server_cli_option($argv, 'dsn');
$dbUser = metis_update_server_cli_option($argv, 'db-user');
$dbPass = metis_update_server_cli_option($argv, 'db-pass');
$baseUrl = rtrim(metis_update_server_cli_option($argv, 'base-url'), '/');
$adminEmail = strtolower(metis_update_server_cli_option($argv, 'admin-email'));
$adminPassword = metis_update_server_cli_option($argv, 'admin-password');
$systemUser = metis_update_server_cli_option($argv, 'system-user');
$authHelper = metis_update_server_cli_option($argv, 'auth-helper', '/usr/local/bin/metis-update-auth-helper');
$pamService = metis_update_server_cli_option($argv, 'pam-service', 'login');
$authExecGroup = metis_update_server_cli_option($argv, 'auth-exec-group', 'metis-update-auth');

if ($dsn === '' || $dbUser === '' || $baseUrl === '' || $adminEmail === '' || $adminPassword === '') {
    fwrite(STDERR, "Usage: php cli/install.php --dsn=... --db-user=... --db-pass=... --base-url=... --admin-email=... --admin-password=...\n");
    exit(1);
}

$storage = metis_update_server_storage();
$keysDir = $storage . '/keys';
foreach ([$storage, $keysDir, $storage . '/packages'] as $directory) {
    if (!is_dir($directory) && !mkdir($directory, 0775, true) && !is_dir($directory)) {
        throw new RuntimeException('Unable to create storage directory: ' . $directory);
    }
}

$privateKeyPath = $keysDir . '/server-private.pem';
$publicKeyPath = $keysDir . '/server-public.pem';
if (!is_file($privateKeyPath) || !is_file($publicKeyPath)) {
    $resource = openssl_pkey_new([
        'private_key_bits' => 2048,
        'private_key_type' => OPENSSL_KEYTYPE_RSA,
    ]);
    if ($resource === false) {
        throw new RuntimeException('Unable to generate the server signing keypair.');
    }
    $privateKey = '';
    openssl_pkey_export($resource, $privateKey);
    $details = openssl_pkey_get_details($resource);
    $publicKey = is_array($details) ? (string) ($details['key'] ?? '') : '';
    if ($privateKey === '' || $publicKey === '') {
        throw new RuntimeException('Unable to export the server signing keypair.');
    }
    file_put_contents($privateKeyPath, $privateKey);
    file_put_contents($publicKeyPath, $publicKey);
}

$config = [
    'dsn' => $dsn,
    'db_user' => $dbUser,
    'db_pass' => $dbPass,
    'base_url' => $baseUrl,
    'keys' => [
        'private' => $privateKeyPath,
        'public' => $publicKeyPath,
    ],
    'auth' => [
        'helper' => $authHelper,
        'pam_service' => $pamService,
        'exec_group' => $authExecGroup,
    ],
];
$configPhp = "<?php\nreturn " . var_export($config, true) . ";\n";
file_put_contents(metis_update_server_config_path(), $configPhp);

$app = metis_update_server_app();
$app->installSchema();
$app->ensureAdminUser($adminEmail, $adminPassword);
if ($systemUser !== '') {
    $app->assignAdminSystemUser($adminEmail, $systemUser);
}

echo json_encode([
    'ok' => true,
    'config' => metis_update_server_config_path(),
    'public_key' => $publicKeyPath,
], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL;
