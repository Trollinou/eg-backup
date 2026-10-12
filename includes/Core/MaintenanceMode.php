<?php
namespace EGBackup\Core;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Gestionnaire du mode maintenance WordPress (.maintenance).
 */
class MaintenanceMode {
    /**
     * Active le mode maintenance natif de WordPress.
     * Autorise les requêtes de restauration EG Backup afin d'éviter les erreurs 503 sur admin-ajax.php.
     *
     * @return bool
     */
    public static function enable() {
        $maintenanceFile = ABSPATH . '.maintenance';
        $time = time();
        $content = "<?php\n"
            . "// Mode maintenance EG Backup.\n"
            . "if (isset(\$_REQUEST['action']) && strpos((string) \$_REQUEST['action'], 'eg_restore_') === 0) {\n"
            . "    \$upgrading = 0;\n"
            . "} else {\n"
            . "    \$upgrading = {$time};\n"
            . "}\n";

        return (bool) file_put_contents($maintenanceFile, $content);
    }

    /**
     * Désactive le mode maintenance en supprimant le fichier .maintenance.
     *
     * @return bool
     */
    public static function disable() {
        $maintenanceFile = ABSPATH . '.maintenance';
        if (file_exists($maintenanceFile)) {
            return @unlink($maintenanceFile);
        }
        return true;
    }

    /**
     * Vérifie si le mode maintenance est actuellement actif.
     *
     * @return bool
     */
    public static function isActive() {
        return file_exists(ABSPATH . '.maintenance');
    }
}
