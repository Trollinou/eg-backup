<?php
namespace EGBackup;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Autoloader PSR-4 simple pour le namespace EGBackup.
 */
class Autoloader {
    /**
     * Enregistre l'autoloader avec spl_autoload_register.
     */
    public static function register() {
        spl_autoload_register([__CLASS__, 'autoload']);
    }

    /**
     * Charge automatiquement les classes du plugin.
     *
     * @param string $class Nom complet de la classe avec namespace.
     */
    public static function autoload($class) {
        $prefix = 'EGBackup\\';
        $baseDir = EG_BACKUP_PLUGIN_DIR . 'includes/';

        $len = strlen($prefix);
        if (strncmp($prefix, $class, $len) !== 0) {
            return;
        }

        $relativeClass = substr($class, $len);
        $file = $baseDir . str_replace('\\', '/', $relativeClass) . '.php';

        if (file_exists($file)) {
            require_once $file;
        }
    }
}
