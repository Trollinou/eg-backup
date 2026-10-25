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
     * Identifiants des pages d'administration enregistrées (hook suffixes).
     *
     * @var array<string, string>
     */
    private array $hookSuffixes = [];

    /**
     * Initialise les hooks WordPress d'administration.
     */
    public function init(): void {
        add_action('admin_menu', [$this, 'registerMenus']);
        add_action('admin_enqueue_scripts', [$this, 'enqueueAssets']);
        add_action('admin_init', [$this, 'handleDownload']);
    }

    /**
     * Enregistre les menus du plugin dans l'interface WordPress.
     */
    public function registerMenus(): void {
        $parentSlug = 'eg-backup';

        $this->hookSuffixes['backup'] = (string) add_menu_page(
            'EG Backup',
            'EG Backup',
            'manage_options',
            $parentSlug,
            [$this, 'renderBackupPage'],
            'dashicons-database-export',
            80
        );

        $this->hookSuffixes['backup_sub'] = (string) add_submenu_page(
            $parentSlug,
            'Créer une sauvegarde',
            'Nouvelle sauvegarde',
            'manage_options',
            $parentSlug,
            [$this, 'renderBackupPage']
        );

        $this->hookSuffixes['list'] = (string) add_submenu_page(
            $parentSlug,
            'Sauvegardes existantes',
            'Mes sauvegardes',
            'manage_options',
            'eg-backup-list',
            [$this, 'renderListPage']
        );

        $this->hookSuffixes['restore'] = (string) add_submenu_page(
            $parentSlug,
            'Assistant de restauration',
            'Restaurer',
            'manage_options',
            'eg-backup-restore',
            [$this, 'renderRestorePage']
        );
    }

    /**
     * Enregistre l'ensemble des styles et scripts du plugin (séparation stricte Register vs Enqueue).
     */
    private function registerAssets(): void {
        $cssFile = EG_BACKUP_PLUGIN_DIR . 'assets/css/admin.css';
        $cssVer  = file_exists($cssFile) ? EG_BACKUP_VERSION . '.' . (string) filemtime($cssFile) : EG_BACKUP_VERSION;

        // Enregistrement des feuilles de style d'administration
        wp_register_style(
            'eg-backup-admin',
            EG_BACKUP_PLUGIN_URL . 'assets/css/admin.css',
            ['dashicons'],
            $cssVer
        );
        wp_style_add_data('eg-backup-admin', 'rtl', 'replace');

        // Stratégie moderne de chargement asynchrone / différé (WordPress 6.3+)
        $scriptArgs = [
            'strategy'  => 'defer',
            'in_footer' => true,
        ];

        $backupJsFile = EG_BACKUP_PLUGIN_DIR . 'assets/js/backup.js';
        $backupJsVer  = file_exists($backupJsFile) ? EG_BACKUP_VERSION . '.' . (string) filemtime($backupJsFile) : EG_BACKUP_VERSION;

        // 1. Script du module de création de sauvegarde (Scan d'arborescence, dump BDD par lots, ZIP)
        wp_register_script(
            'eg-backup-create',
            EG_BACKUP_PLUGIN_URL . 'assets/js/backup.js',
            [],
            $backupJsVer,
            $scriptArgs
        );

        $listJsFile = EG_BACKUP_PLUGIN_DIR . 'assets/js/list.js';
        $listJsVer  = file_exists($listJsFile) ? EG_BACKUP_VERSION . '.' . (string) filemtime($listJsFile) : EG_BACKUP_VERSION;

        // 2. Script léger du module de gestion de la liste des archives (Suppression AJAX réactive)
        wp_register_script(
            'eg-backup-list',
            EG_BACKUP_PLUGIN_URL . 'assets/js/list.js',
            [],
            $listJsVer,
            $scriptArgs
        );

        $restoreJsFile = EG_BACKUP_PLUGIN_DIR . 'assets/js/restore.js';
        $restoreJsVer  = file_exists($restoreJsFile) ? EG_BACKUP_VERSION . '.' . (string) filemtime($restoreJsFile) : EG_BACKUP_VERSION;

        // 3. Script du module de restauration (Inspection du manifeste, BDD, réécriture URLs)
        wp_register_script(
            'eg-backup-restore',
            EG_BACKUP_PLUGIN_URL . 'assets/js/restore.js',
            [],
            $restoreJsVer,
            $scriptArgs
        );
    }

    /**
     * Charge contextuellement les styles et scripts nécessaires sur les écrans du plugin.
     *
     * @param string $hook Le hook_suffix de la page d'administration courante.
     */
    public function enqueueAssets(string $hook): void {
        // Chargement contextuel strict : abandonner immédiatement si nous ne sommes pas sur une page du plugin.
        if (!in_array($hook, $this->hookSuffixes, true)) {
            return;
        }

        // Étape 1 : Enregistrement découplé des ressources
        $this->registerAssets();

        // Étape 2 : Feuille de style requise sur les écrans d'administration du plugin
        wp_enqueue_style('eg-backup-admin');

        // Étape 3 : Chargement granulaire à la demande selon la page active
        if ($hook === $this->hookSuffixes['restore']) {
            wp_enqueue_script('eg-backup-restore');
            wp_localize_script('eg-backup-restore', 'egBackupData', [
                'ajaxUrl' => admin_url('admin-ajax.php'),
                'nonce'   => wp_create_nonce('eg_backup_nonce'),
            ]);
        } elseif ($hook === $this->hookSuffixes['list']) {
            wp_enqueue_script('eg-backup-list');
            wp_localize_script('eg-backup-list', 'egBackupData', [
                'ajaxUrl' => admin_url('admin-ajax.php'),
                'nonce'   => wp_create_nonce('eg_backup_nonce'),
            ]);
        } elseif ($hook === $this->hookSuffixes['backup'] || $hook === $this->hookSuffixes['backup_sub']) {
            wp_enqueue_script('eg-backup-create');
            wp_localize_script('eg-backup-create', 'egBackupData', [
                'ajaxUrl'       => admin_url('admin-ajax.php'),
                'nonce'         => wp_create_nonce('eg_backup_nonce'),
                'downloadNonce' => wp_create_nonce('eg_backup_download_action'),
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

        $nonce = sanitize_text_field(wp_unslash($_GET['nonce'] ?? $_GET['_wpnonce'] ?? ''));
        if (!wp_verify_nonce($nonce, 'eg_backup_download_action') && !wp_verify_nonce($nonce, 'eg_backup_nonce')) {
            wp_die('Le lien suivi est expiré.');
        }

        $filename = sanitize_file_name($_GET['file'] ?? '');
        $filePath = Archiver::getStorageDir() . $filename;

        if (empty($filename) || !file_exists($filePath) || strtolower(pathinfo($filePath, PATHINFO_EXTENSION)) !== 'zip') {
            wp_die('Fichier de sauvegarde introuvable.');
        }

        // Mode 1 : Téléchargement segmenté par tranches de 64 Mo (Chunked Fetch JS).
        // Permet de franchir tout plafond de 512 Mo par réponse HTTP imposé par le proxy/pare-feu de l'hébergeur.
        if (isset($_GET['chunk_start']) && isset($_GET['chunk_end'])) {
            $chunkStart = max(0, (int) $_GET['chunk_start']);
            $chunkEnd   = max($chunkStart, (int) $_GET['chunk_end']);
            Archiver::streamFileChunk($filePath, $chunkStart, $chunkEnd);
            exit;
        }

        // Mode 2 : Téléchargement statique direct servi par le serveur web (via hardlink éphémère sécurisé).
        $directUrl = Archiver::prepareDirectDownloadUrl($filePath);
        if (!empty($directUrl)) {
            wp_redirect($directUrl, 302);
            exit;
        }

        // Mode 3 (Fallback) : Diffusion binaire PHP par morceaux avec reprise HTTP Range.
        Archiver::streamFileToOutput($filePath);
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
