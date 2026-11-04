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
    define('EG_BACKUP_VERSION', '1.0.10');
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
if (!function_exists('wp_mkdir_p')) {
    function wp_mkdir_p($target) {
        if (is_dir($target)) {
            return true;
        }
        return @mkdir($target, 0777, true);
    }
}
if (!function_exists('wp_generate_password')) {
    function wp_generate_password($length = 12) {
        return substr(bin2hex(random_bytes(max(1, (int) ceil($length / 2)))), 0, (int) $length);
    }
}

// Variables globales de stockage pour les tests unitaires.
$GLOBALS['wp_test_options'] = [];
$GLOBALS['wp_test_rewrite_flushed'] = false;

if (!function_exists('get_option')) {
    function get_option($option, $default = false) {
        global $wp_test_options;
        return $wp_test_options[$option] ?? $default;
    }
}

if (!function_exists('update_option')) {
    function update_option($option, $value, $autoload = null) {
        global $wp_test_options;
        $wp_test_options[$option] = $value;
        return true;
    }
}

if (!function_exists('flush_rewrite_rules')) {
    function flush_rewrite_rules($hard = true) {
        global $wp_test_rewrite_flushed;
        $wp_test_rewrite_flushed = $hard;
    }
}

if (!function_exists('wp_cache_delete')) {
    function wp_cache_delete($key, $group = '') {
        return true;
    }
}

if (!class_exists('WP_CLI')) {
    class WP_CLI {
        /**
         * @var array<string, mixed>
         */
        public static $commands = [];

        public static function add_command($name, $callable, $args = []) {
            self::$commands[$name] = $callable;
        }

        public static function line($message) {}
        public static function success($message) {}
        public static function error($message) {}
        public static function warning($message) {}
    }
}
