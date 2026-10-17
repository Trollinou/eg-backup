<?php
/**
 * Plugin Name: EG Backup & Restore
 * Plugin URI: https://github.com/eg-backup/eg-backup
 * Description: Sauvegarde et restauration intelligente de WordPress : base de données complète, sélection granulaire des données de plugins hors BDD, manifeste des dépendances (plugins/thèmes) et assistant de restauration interactif.
 * Version: 1.0.7
 * Author: Etienne Gagnon
 * Author URI: https://github.com/Trollinou
 * License: GPL-2.0+
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: eg-backup
 * Domain Path: /languages
 * Requires at least: 7.1
 * Requires PHP: 8.4
 */

// Interdiction d'accès direct.
if (!defined('ABSPATH')) {
    exit;
}

// Définition des constantes du plugin.
define('EG_BACKUP_VERSION', '1.0.7');
define('EG_BACKUP_PLUGIN_FILE', __FILE__);
define('EG_BACKUP_PLUGIN_DIR', plugin_dir_path(__FILE__));
define('EG_BACKUP_PLUGIN_URL', plugin_dir_url(__FILE__));
define('EG_BACKUP_PLUGIN_BASENAME', plugin_basename(__FILE__));

// Chargement de l'autoloader.
require_once EG_BACKUP_PLUGIN_DIR . 'includes/Autoloader.php';

use EGBackup\Autoloader;
use EGBackup\Admin\AdminController;
use EGBackup\Ajax\AjaxHandler;
use EGBackup\Cli\BackupCommand;

// Enregistrement de l'autoloader pour le namespace EGBackup.
Autoloader::register();

/**
 * Initialisation du plugin.
 */
function eg_backup_init() {
    // Initialisation de l'administration si dans l'espace admin.
    if (is_admin()) {
        $admin = new AdminController();
        $admin->init();

        $ajax = new AjaxHandler();
        $ajax->init();
    }

    // Tâche planifiée WP-Cron pour la purge automatique des dossiers temporaires orphelins.
    add_action('eg_backup_cleanup_temp_event', ['\\EGBackup\\Core\\Archiver', 'purgeOldTempDirectories']);

    // Intégration WP-CLI si disponible.
    if (defined('WP_CLI') && WP_CLI) {
        BackupCommand::register();
    }
}
add_action('plugins_loaded', 'eg_backup_init');

/**
 * Hook d'activation : sécurisation du stockage et planification du nettoyage automatique.
 */
register_activation_hook(__FILE__, function() {
    require_once EG_BACKUP_PLUGIN_DIR . 'includes/Core/Archiver.php';
    \EGBackup\Core\Archiver::ensureStorageDirectory();

    if (!wp_next_scheduled('eg_backup_cleanup_temp_event')) {
        wp_schedule_event(time(), 'daily', 'eg_backup_cleanup_temp_event');
    }
});

/**
 * Hook de désactivation : suppression de la tâche planifiée de nettoyage.
 */
register_deactivation_hook(__FILE__, function() {
    wp_clear_scheduled_hook('eg_backup_cleanup_temp_event');
});
