<?php
/**
 * Bootstrap pour les tests PHPUnit et l'analyse PHPStan de EG Backup.
 */

if (!defined('ABSPATH')) {
    define('ABSPATH', dirname(__DIR__) . '/');
}
if (!defined('WP_CONTENT_DIR')) {
    define('WP_CONTENT_DIR', ABSPATH . 'wp-content');
}
if (!defined('HOUR_IN_SECONDS')) {
    define('HOUR_IN_SECONDS', 3600);
}
if (!defined('DAY_IN_SECONDS')) {
    define('DAY_IN_SECONDS', 86400);
}
if (!defined('ARRAY_A')) {
    define('ARRAY_A', 'ARRAY_A');
}
if (!defined('ARRAY_N')) {
    define('ARRAY_N', 'ARRAY_N');
}
if (!defined('OBJECT')) {
    define('OBJECT', 'OBJECT');
}
if (!defined('OBJECT_K')) {
    define('OBJECT_K', 'OBJECT_K');
}
if (!defined('EG_BACKUP_VERSION')) {
    define('EG_BACKUP_VERSION', '1.0.7');
}
if (!defined('EG_BACKUP_PLUGIN_FILE')) {
    define('EG_BACKUP_PLUGIN_FILE', dirname(__DIR__) . '/eg-backup.php');
}
if (!defined('EG_BACKUP_PLUGIN_DIR')) {
    define('EG_BACKUP_PLUGIN_DIR', dirname(__DIR__) . '/');
}
if (!defined('EG_BACKUP_PLUGIN_URL')) {
    define('EG_BACKUP_PLUGIN_URL', 'https://example.com/wp-content/plugins/eg-backup/');
}
if (!defined('EG_BACKUP_PLUGIN_BASENAME')) {
    define('EG_BACKUP_PLUGIN_BASENAME', 'eg-backup/eg-backup.php');
}
if (!defined('WP_CLI')) {
    define('WP_CLI', true);
}

// Chargement de l'autoloader
if (file_exists(dirname(__DIR__) . '/vendor/autoload.php')) {
    require_once dirname(__DIR__) . '/vendor/autoload.php';
} else {
    require_once dirname(__DIR__) . '/includes/Autoloader.php';
    \EGBackup\Autoloader::register();
}

// Définition des fonctions WordPress courantes si absentes lors des tests unitaires.
if (!function_exists('trailingslashit')) {
    function trailingslashit($string) {
        return rtrim((string) $string, '/\\') . '/';
    }
}
if (!function_exists('untrailingslashit')) {
    function untrailingslashit($string) {
        return rtrim((string) $string, '/\\');
    }
}
if (!function_exists('wp_normalize_path')) {
    function wp_normalize_path($path) {
        $path = str_replace('\\', '/', (string) $path);
        return (string) preg_replace('|(?<=.)/+|', '/', $path);
    }
}
if (!function_exists('wp_upload_dir')) {
    function wp_upload_dir() {
        return [
            'basedir' => sys_get_temp_dir() . '/eg_backup_test_uploads',
            'baseurl' => 'https://example.com/wp-content/uploads',
        ];
    }
}
