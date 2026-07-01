<?php
declare(strict_types=1);

if ( PHP_SAPI !== 'cli' ) {
    fwrite( STDERR, "This verifier must run from the CLI.\n" );
    exit( 1 );
}

require_once dirname(__DIR__) . '/src/bootstrap.php';

function metis_update_server_verify_option(array $argv, string $name, string $default = ''): string {
    $prefix = '--' . $name . '=';
    foreach ($argv as $arg) {
        if (str_starts_with((string) $arg, $prefix)) {
            return trim(substr((string) $arg, strlen($prefix)));
        }
    }
    return $default;
}

$type = metis_update_server_verify_option($argv, 'type');
$version = metis_update_server_verify_option($argv, 'version');
$sourceDir = metis_update_server_verify_option($argv, 'source-dir');
$fromDir = metis_update_server_verify_option($argv, 'from-dir');
$verifyRoot = metis_update_server_verify_option($argv, 'verify-root');
$profile = metis_update_server_verify_option($argv, 'verify-profile', 'publish_gate');
$moduleId = metis_update_server_verify_option($argv, 'module-id');

if ($type === '' || $version === '' || $sourceDir === '') {
    fwrite(STDERR, "Usage: php cli/verify.php --type=... --version=... --source-dir=... [--from-dir=...] [--verify-root=...] [--verify-profile=publish_gate] [--module-id=...]\n");
    exit(1);
}

$app = metis_update_server_app();
$result = $app->verifyRelease([
    'type' => $type,
    'version' => $version,
    'source_dir' => $sourceDir,
    'from_dir' => $fromDir,
    'verify_root' => $verifyRoot,
    'verify_profile' => $profile,
    'module_id' => $moduleId,
]);

echo json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL;
exit(!empty($result['ok']) ? 0 : 1);
