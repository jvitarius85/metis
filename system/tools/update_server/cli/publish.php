<?php
declare(strict_types=1);

if ( PHP_SAPI !== 'cli' ) {
    fwrite( STDERR, "This publisher must run from the CLI.\n" );
    exit( 1 );
}

require_once dirname(__DIR__) . '/src/bootstrap.php';

function metis_update_server_publish_option(array $argv, string $name, string $default = ''): string {
    $prefix = '--' . $name . '=';
    foreach ($argv as $arg) {
        if (str_starts_with((string) $arg, $prefix)) {
            return trim(substr((string) $arg, strlen($prefix)));
        }
    }
    return $default;
}

$options = [
    'type' => metis_update_server_publish_option($argv, 'type'),
    'tag' => metis_update_server_publish_option($argv, 'tag'),
    'version' => metis_update_server_publish_option($argv, 'version'),
    'from_version' => metis_update_server_publish_option($argv, 'from-version'),
    'module_id' => metis_update_server_publish_option($argv, 'module-id'),
    'minimum_metis' => metis_update_server_publish_option($argv, 'minimum-metis'),
    'channel' => metis_update_server_publish_option($argv, 'channel', 'stable'),
    'visibility' => metis_update_server_publish_option($argv, 'visibility', 'public'),
    'installation_id' => metis_update_server_publish_option($argv, 'installation-id'),
    'minimum_php' => metis_update_server_publish_option($argv, 'minimum-php', '8.1'),
    'notes' => metis_update_server_publish_option($argv, 'notes'),
    'source_dir' => metis_update_server_publish_option($argv, 'source-dir'),
    'from_dir' => metis_update_server_publish_option($argv, 'from-dir'),
    'verify_root' => metis_update_server_publish_option($argv, 'verify-root'),
    'verify_profile' => metis_update_server_publish_option($argv, 'verify-profile', 'publish_gate'),
    'skip_verify' => metis_update_server_publish_option($argv, 'skip-verify'),
];

if ($options['type'] === '' || $options['version'] === '' || $options['source_dir'] === '') {
    fwrite(STDERR, "Usage: php cli/publish.php --type=... --version=... --source-dir=... [--tag=...] [--from-version=...] [--from-dir=...] [--module-id=...] [--minimum-metis=...] [--verify-root=...] [--verify-profile=publish_gate] [--skip-verify=1]\n");
    exit(1);
}

$options['skip_verify'] = in_array(strtolower((string) $options['skip_verify']), ['1', 'true', 'yes'], true);

$result = metis_update_server_app()->publishPackage($options);
echo json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL;
