<?php
namespace EGBackup\Admin;

if (!defined('ABSPATH')) {
    exit;
}

use EGBackup\Core\Archiver;

/**
 * Contrôleur d'administration pour les menus, pages et assets.
 */
class AdminController {
    /**
     * Initialise les hooks WordPress d'administration.
     */
    public function init() {
        add_action('admin_menu', [$this, 'registerMenus']);
        add_action('admin_enqueue_scripts', [$this, 'enqueueAssets']);
        add_action('admin_init', [$this, 'handleDownload']);
    }

    /**
     * Enregistre les menus du plugin dans l'interface WordPress.
     */
    public function registerMenus() {
        $parentSlug = 'eg-backup';

        add_menu_page(
            'EG Backup',
            'EG Backup',
            'manage_options',
            $parentSlug,
            [$this, 'renderBackupPage'],
            'dashicons-database-export',
            80
        );

        add_submenu_page(
            $parentSlug,
            'Créer une sauvegarde',
            'Nouvelle sauvegarde',
            'manage_options',
            $parentSlug,
            [$this, 'renderBackupPage']
        );

        add_submenu_page(
            $parentSlug,
            'Sauvegardes existantes',
            'Mes sauvegardes',
            'manage_options',
            'eg-backup-list',
            [$this, 'renderListPage']
        );

        add_submenu_page(
            $parentSlug,
            'Assistant de restauration',
            'Restaurer',
            'manage_options',
            'eg-backup-restore',
            [$this, 'renderRestorePage']
        );
    }

    /**
     * Charge les feuilles de style et scripts nécessaires.
     */
    public function enqueueAssets($hook) {
        if (strpos($hook, 'eg-backup') === false) {
            return;
        }

        // CSS d'administration
        wp_enqueue_style(
            'eg-backup-admin',
            EG_BACKUP_PLUGIN_URL . 'assets/css/admin.css',
            [],
            EG_BACKUP_VERSION
        );

        // Scripts JS
        if (strpos($hook, 'eg-backup-restore') !== false) {
            wp_enqueue_script(
                'eg-backup-restore',
                EG_BACKUP_PLUGIN_URL . 'assets/js/restore.js',
                ['jquery'],
                EG_BACKUP_VERSION,
                true
            );
            wp_localize_script('eg-backup-restore', 'egBackupData', [
                'ajaxUrl' => admin_url('admin-ajax.php'),
                'nonce'   => wp_create_nonce('eg_backup_nonce'),
            ]);
        } else {
            wp_enqueue_script(
                'eg-backup-admin',
                EG_BACKUP_PLUGIN_URL . 'assets/js/backup.js',
                ['jquery'],
                EG_BACKUP_VERSION,
                true
            );
            wp_localize_script('eg-backup-admin', 'egBackupData', [
                'ajaxUrl' => admin_url('admin-ajax.php'),
                'nonce'   => wp_create_nonce('eg_backup_nonce'),
            ]);
        }
    }

    /**
     * Gère le téléchargement direct et sécurisé d'une archive de sauvegarde.
     */
    public function handleDownload() {
        if (!isset($_GET['action']) || $_GET['action'] !== 'eg_backup_download') {
            return;
        }

        if (!current_user_can('manage_options')) {
            wp_die('Autorisation refusée.');
        }

        check_admin_referer('eg_backup_download_action', 'nonce');

        $filename = sanitize_file_name($_GET['file'] ?? '');
        $filePath = Archiver::getStorageDir() . $filename;

        if (empty($filename) || !file_exists($filePath) || strtolower(pathinfo($filePath, PATHINFO_EXTENSION)) !== 'zip') {
            wp_die('Fichier de sauvegarde introuvable.');
        }

        // Envoi des en-têtes HTTP de téléchargement
        header('Content-Description: File Transfer');
        header('Content-Type: application/zip');
        header('Content-Disposition: attachment; filename="' . basename($filePath) . '"');
        header('Expires: 0');
        header('Cache-Control: must-revalidate');
        header('Pragma: public');
        header('Content-Length: ' . filesize($filePath));
        flush();
        readfile($filePath);
        exit;
    }

    /**
     * Affiche la page de création de sauvegarde.
     */
    public function renderBackupPage() {
        require_once EG_BACKUP_PLUGIN_DIR . 'includes/Admin/Views/backup-page.php';
    }

    /**
     * Affiche la page des sauvegardes existantes.
     */
    public function renderListPage() {
        $backups = Archiver::getBackupsList();
        require_once EG_BACKUP_PLUGIN_DIR . 'includes/Admin/Views/list-page.php';
    }

    /**
     * Affiche la page de restauration.
     */
    public function renderRestorePage() {
        $backups = Archiver::getBackupsList();
        $selectedFile = sanitize_file_name($_GET['file'] ?? '');
        require_once EG_BACKUP_PLUGIN_DIR . 'includes/Admin/Views/restore-page.php';
    }
}
