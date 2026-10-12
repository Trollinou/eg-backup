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
     *
     * @return bool
     */
    public static function enable() {
        $maintenanceFile = ABSPATH . '.maintenance';
        $content = "<?php \$upgrading = " . time() . "; ?>";
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
