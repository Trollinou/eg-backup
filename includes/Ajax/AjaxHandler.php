<?php
namespace EGBackup\Ajax;

if (!defined('ABSPATH')) {
    exit;
}

use EGBackup\Core\Archiver;
use EGBackup\Core\DatabaseExporter;
use EGBackup\Core\DatabaseImporter;
use EGBackup\Core\FileScanner;
use EGBackup\Core\MaintenanceMode;
use EGBackup\Core\ManifestManager;

/**
 * Gestionnaire des requêtes AJAX sécurisées pour la sauvegarde et restauration.
 */
class AjaxHandler {
    /**
     * Enregistrement des actions AJAX.
     */
    public function init() {
        $actions = [
            'eg_backup_scan_files',
            'eg_backup_start',
            'eg_backup_step_db',
            'eg_backup_prepare_files',
            'eg_backup_step_files',
            'eg_backup_finalize',
            'eg_backup_delete',
            'eg_restore_inspect',
            'eg_restore_install_plugin',
            'eg_restore_start',
            'eg_restore_step_db',
            'eg_restore_step_replace',
            'eg_restore_step_files',
            'eg_restore_finalize',
            'eg_restore_cancel',
        ];

        foreach ($actions as $action) {
            add_action("wp_ajax_{$action}", [$this, $action]);
        }

        // Actions de restauration autorisées sans session WordPress active (sécurisées par restore_token).
        // Indispensable car l'écrasement de la base de données déconnecte l'administrateur en cours.
        $noprivRestoreActions = [
            'eg_restore_step_db',
            'eg_restore_step_replace',
            'eg_restore_step_files',
            'eg_restore_finalize',
            'eg_restore_cancel',
        ];

        foreach ($noprivRestoreActions as $action) {
            add_action("wp_ajax_nopriv_{$action}", [$this, $action]);
        }
    }

    /**
     * Vérification des permissions et du nonce de sécurité.
     */
    private function verifySecurity() {
        if (!current_user_can('manage_options')) {
            wp_send_json_error(['message' => 'Autorisation refusée.'], 403);
        }
        check_ajax_referer('eg_backup_nonce', 'nonce');
    }

    /**
     * Scanne l'arborescence de wp-content et renvoie l'arborescence catégorisée et arborescente.
     */
    public function eg_backup_scan_files() {
        $this->verifySecurity();
        $tree = FileScanner::scanContentDirectory(3);
        $categorized = FileScanner::scanCategorizedData();
        $registeredLocations = FileScanner::getRegisteredLocations();
        $isCliZip = Archiver::isCliZipAvailable();

        wp_send_json_success([
            'tree'                 => $tree,
            'categorized'          => $categorized,
            'registered_locations' => array_values($registeredLocations),
            'is_cli_zip'           => $isCliZip,
        ]);
    }

    /**
     * Initialise un job de sauvegarde (Cœur, Médias ou Complet).
     */
    public function eg_backup_start() {
        $this->verifySecurity();
        Archiver::ensureStorageDirectory();

        $backupType = sanitize_key($_POST['backup_type'] ?? 'core');
        if (!in_array($backupType, ['core', 'media', 'full'], true)) {
            $backupType = 'core';
        }

        $excludeThumbs = !empty($_POST['exclude_thumbs']);
        $selectedFolders = isset($_POST['selected_folders']) ? (array) $_POST['selected_folders'] : [];
        $selectedFolders = array_values(array_unique(array_map('sanitize_text_field', $selectedFolders)));

        // Si sauvegarde Cœur et aucun dossier spécifié, cibler les thèmes actifs/enfants
        if ($backupType === 'core' && empty($selectedFolders)) {
            $activeTheme = wp_get_theme();
            $selectedFolders[] = 'themes/' . $activeTheme->get_stylesheet();
            if ($activeTheme->parent()) {
                $selectedFolders[] = 'themes/' . $activeTheme->get_template();
            }
            $selectedFolders = array_values(array_unique($selectedFolders));
        }

        $jobId = 'job_' . gmdate('Ymd_His') . '_' . wp_generate_password(6, false);
        $tempDir = Archiver::getTempDir() . $jobId . '/';
        wp_mkdir_p($tempDir);

        $tables = [];
        $sqlFile = $tempDir . 'database.sql';

        // Pour les sauvegardes Cœur et Complète, préparer l'export SQL
        if ($backupType !== 'media') {
            $tables = DatabaseExporter::getTables();
            DatabaseExporter::initSqlFile($sqlFile);
        }

        // Sauvegarde de l'état du travail dans un fichier d'état
        $state = [
            'job_id'           => $jobId,
            'backup_type'      => $backupType,
            'exclude_thumbs'   => $excludeThumbs,
            'temp_dir'         => $tempDir,
            'sql_file'         => $sqlFile,
            'tables'           => $tables,
            'total_tables'     => count($tables),
            'selected_folders' => $selectedFolders,
            'cli_completed'    => false,
            'created_at'       => time(),
        ];
        file_put_contents($tempDir . 'state.json', wp_json_encode($state));

        wp_send_json_success([
            'job_id'       => $jobId,
            'backup_type'  => $backupType,
            'total_tables' => count($tables),
            'tables'       => $tables,
            'skip_db'      => ($backupType === 'media'),
        ]);
    }

    /**
     * Étape d'exportation de la base de données par morceaux.
     */
    public function eg_backup_step_db() {
        $this->verifySecurity();

        $jobId = sanitize_text_field($_POST['job_id'] ?? '');
        $tableIndex = (int) ($_POST['table_index'] ?? 0);
        $rowOffset = (int) ($_POST['row_offset'] ?? 0);
        $chunkSize = 1000;

        $tempDir = Archiver::getTempDir() . $jobId . '/';
        $stateFile = $tempDir . 'state.json';

        if (!file_exists($stateFile)) {
            wp_send_json_error(['message' => 'Job de sauvegarde introuvable.']);
        }

        $state = json_decode(file_get_contents($stateFile), true);
        $tables = $state['tables'];
        $totalTables = count($tables);

        if ($totalTables === 0 || $tableIndex >= $totalTables) {
            // Terminé pour la BDD (ou aucun tableau à exporter)
            if (file_exists($state['sql_file'])) {
                DatabaseExporter::finalizeSqlFile($state['sql_file']);
            }
            wp_send_json_success([
                'db_finished'  => true,
                'progress'     => 100,
                'message'      => 'Base de données exportée avec succès.',
            ]);
        }

        $currentTable = $tables[$tableIndex];

        // Si on commence une table, exporter la structure
        if ($rowOffset === 0) {
            DatabaseExporter::exportTableStructure($currentTable, $state['sql_file']);
        }

        $totalRowsInTable = DatabaseExporter::countTableRows($currentTable);
        $exportedRows = 0;

        if ($totalRowsInTable > 0) {
            $exportedRows = DatabaseExporter::exportTableChunk($currentTable, $rowOffset, $chunkSize, $state['sql_file']);
        }

        $newOffset = $rowOffset + $chunkSize;
        $tableFinished = ($newOffset >= $totalRowsInTable) || ($totalRowsInTable === 0);

        if ($tableFinished) {
            $nextTableIndex = $tableIndex + 1;
            $nextOffset = 0;
        } else {
            $nextTableIndex = $tableIndex;
            $nextOffset = $newOffset;
        }

        $overallProgress = round(($tableIndex / max(1, $totalTables)) * 100, 1);

        wp_send_json_success([
            'db_finished'    => ($nextTableIndex >= $totalTables),
            'current_table'  => $currentTable,
            'table_index'    => $nextTableIndex,
            'row_offset'     => $nextOffset,
            'progress'       => $overallProgress,
            'message'        => sprintf('Exportation de la table %s (%d/%d)...', $currentTable, $tableIndex + 1, $totalTables),
        ]);
    }

    /**
     * Indexe les fichiers à sauvegarder, initialise l'archive ZIP avec BDD et manifeste.
     */
    public function eg_backup_prepare_files() {
        $this->verifySecurity();
        @set_time_limit(180);

        $jobId = sanitize_text_field($_POST['job_id'] ?? '');
        $tempDir = Archiver::getTempDir() . $jobId . '/';
        $stateFile = $tempDir . 'state.json';

        if (!file_exists($stateFile)) {
            wp_send_json_error(['message' => 'Job introuvable.']);
        }

        $state = json_decode(file_get_contents($stateFile), true);
        $selectedFolders = $state['selected_folders'] ?? [];
        $backupType = $state['backup_type'] ?? 'core';
        $excludeThumbs = !empty($state['exclude_thumbs']);

        // Récupérer les règles d'exclusion supplémentaires des emplacements déclarés
        $registeredLocations = FileScanner::getRegisteredLocations();
        $extraExcludes = [];
        $includedRegistered = [];
        foreach ($registeredLocations as $locId => $locData) {
            if (in_array($locData['relative_path'], $selectedFolders, true)) {
                $includedRegistered[] = $locData;
                if (!empty($locData['exclude'])) {
                    $extraExcludes = array_merge($extraExcludes, (array) $locData['exclude']);
                }
            }
        }

        // 1. Collecter tous les fichiers à sauvegarder
        $filesQueue = $this->collectFilesToArchive($selectedFolders, $excludeThumbs, $extraExcludes);
        $totalFiles = count($filesQueue);

        file_put_contents($tempDir . 'files_queue.json', wp_json_encode($filesQueue));

        // 2. Génération du manifest.json
        $manifest = ManifestManager::generateManifest([
            'backup_type'          => $backupType,
            'thumbs_excluded'      => $excludeThumbs,
            'files_included'       => $selectedFolders,
            'registered_locations' => $includedRegistered,
            'tables_included'      => $state['tables'] ?? [],
        ]);
        ManifestManager::saveToFile($tempDir . 'manifest.json', $manifest);

        // 2b. Export dédié des réglages de thème (pour Cœur et Complet)
        if ($backupType !== 'media') {
            $this->exportThemeSettings($tempDir . 'theme_settings.json');
        }

        // 3. Initialiser le nom de l'archive ZIP
        $prefix = ($backupType === 'core') ? 'backup_core_' : (($backupType === 'media') ? 'backup_media_' : 'backup_full_');
        $zipFilename = $prefix . gmdate('Y-m-d_H-i-s') . '_' . sanitize_title(get_bloginfo('name')) . '.zip';
        $zipFilePath = Archiver::getStorageDir() . $zipFilename;

        // Tenter l'archivage CLI ultra-rapide si disponible
        if (Archiver::isCliZipAvailable()) {
            $cliSuccess = Archiver::archiveWithCliZip($tempDir, $zipFilePath, $selectedFolders, $excludeThumbs, $extraExcludes);
            if ($cliSuccess) {
                $state['cli_completed'] = true;
                $state['zip_filename']  = $zipFilename;
                $state['zip_filepath']  = $zipFilePath;
                $state['total_files']   = $totalFiles;
                file_put_contents($stateFile, wp_json_encode($state));

                wp_send_json_success([
                    'total_files'   => $totalFiles,
                    'cli_completed' => true,
                    'message'       => sprintf('Archivage ultra-rapide CLI réussi (%d fichiers archivés).', $totalFiles),
                ]);
                return;
            }
        }

        // Sinon, initialiser via PHP ZipArchive
        $zip = new \ZipArchive();
        if ($zip->open($zipFilePath, \ZipArchive::CREATE | \ZipArchive::OVERWRITE) !== true) {
            wp_send_json_error(['message' => 'Impossible d\'initialiser l\'archive ZIP.']);
        }

        $zip->addFile($tempDir . 'manifest.json', 'manifest.json');
        if (file_exists($tempDir . 'theme_settings.json')) {
            $zip->addFile($tempDir . 'theme_settings.json', 'theme_settings.json');
        }
        if (file_exists($state['sql_file'])) {
            $zip->addFile($state['sql_file'], 'database.sql');
        }
        $zip->close();

        // Mettre à jour l'état
        $state['zip_filename'] = $zipFilename;
        $state['zip_filepath'] = $zipFilePath;
        $state['total_files']  = $totalFiles;
        file_put_contents($stateFile, wp_json_encode($state));

        wp_send_json_success([
            'total_files'   => $totalFiles,
            'cli_completed' => false,
            'message'       => sprintf('%d fichier(s) répertorié(s) pour l\'archivage.', $totalFiles),
        ]);
    }

    /**
     * Ajoute un lot de fichiers à l'archive ZIP (chunking anti-timeout).
     */
    public function eg_backup_step_files() {
        $this->verifySecurity();
        @ini_set('memory_limit', '512M');
        @set_time_limit(180);

        $jobId = sanitize_text_field($_POST['job_id'] ?? '');
        $offset = (int) ($_POST['offset'] ?? 0);

        $tempDir = Archiver::getTempDir() . $jobId . '/';
        $stateFile = $tempDir . 'state.json';
        $queueFile = $tempDir . 'files_queue.json';

        if (!file_exists($stateFile) || !file_exists($queueFile)) {
            wp_send_json_error(['message' => 'Job introuvable.']);
        }

        $state = json_decode(file_get_contents($stateFile), true);
        $filesQueue = json_decode(file_get_contents($queueFile), true);
        $totalFiles = count($filesQueue);

        if (!empty($state['cli_completed'])) {
            wp_send_json_success([
                'finished' => true,
                'offset'   => $totalFiles,
                'total'    => $totalFiles,
                'progress' => 100,
                'message'  => 'Archivage ultra-rapide déjà finalisé par CLI.',
            ]);
            return;
        }

        if ($totalFiles === 0 || $offset >= $totalFiles) {
            wp_send_json_success([
                'finished' => true,
                'offset'   => $totalFiles,
                'total'    => $totalFiles,
                'progress' => 100,
                'message'  => 'Archivage des fichiers terminé.',
            ]);
            return;
        }

        $zipFilePath = $state['zip_filepath'];

        $zip = new \ZipArchive();
        if ($zip->open($zipFilePath) !== true) {
            wp_send_json_error(['message' => 'Impossible d\'ouvrir l\'archive ZIP pour ajouter des fichiers.']);
        }

        // Extensions de fichiers déjà compressés : aucun calcul CPU Deflate inutile.
        $noCompressExts = [
            'jpg', 'jpeg', 'png', 'webp', 'gif', 'ico', 'avif',
            'mp4', 'm4v', 'mov', 'avi', 'mp3', 'ogg',
            'zip', 'gz', 'bz2', 'tar', '7z', 'rar',
            'woff', 'woff2', 'pdf',
        ];

        $maxBatchFiles = 200;
        $maxBatchBytes = 16 * 1024 * 1024; // 16 Mo maximum par passe.
        $startTime = microtime(true);
        $maxSeconds = 4; // 4 secondes maximum par passe

        $processedCount = 0;
        $processedBytes = 0;

        for ($i = $offset; $i < $totalFiles; $i++) {
            $item = $filesQueue[$i];
            $disk = $item['disk'];
            $rel = $item['zip'];

            if (is_file($disk) && is_readable($disk)) {
                $fileSize = (int) filesize($disk);
                $zipEntryName = 'files/' . $rel;

                if ($zip->addFile($disk, $zipEntryName)) {
                    $ext = strtolower(pathinfo($disk, PATHINFO_EXTENSION));
                    if (in_array($ext, $noCompressExts, true) && method_exists($zip, 'setCompressionName')) {
                        $zip->setCompressionName($zipEntryName, \ZipArchive::CM_STORE);
                    }
                    $processedBytes += $fileSize;
                }
            }

            $processedCount++;

            // Conditions d'arrêt du lot
            if ($processedCount >= $maxBatchFiles) {
                break;
            }
            if ($processedBytes >= $maxBatchBytes) {
                break;
            }
            if ((microtime(true) - $startTime) >= $maxSeconds) {
                break;
            }
        }

        $zip->close();

        $newOffset = $offset + $processedCount;
        $isFinished = ($newOffset >= $totalFiles);
        $progress = $totalFiles > 0 ? round(($newOffset / $totalFiles) * 100) : 100;

        wp_send_json_success([
            'finished' => $isFinished,
            'offset'   => $newOffset,
            'total'    => $totalFiles,
            'progress' => $progress,
            'message'  => sprintf('Archivage des fichiers : %d / %d (%d%%)...', $newOffset, $totalFiles, $progress),
        ]);
    }

    /**
     * Finalise la sauvegarde et nettoie les fichiers temporaires.
     */
    public function eg_backup_finalize() {
        $this->verifySecurity();

        $jobId = sanitize_text_field($_POST['job_id'] ?? '');
        $tempDir = Archiver::getTempDir() . $jobId . '/';
        $stateFile = $tempDir . 'state.json';

        if (!file_exists($stateFile)) {
            wp_send_json_error(['message' => 'Job introuvable.']);
        }

        $state = json_decode(file_get_contents($stateFile), true);
        $zipFilePath = $state['zip_filepath'];
        $zipFilename = $state['zip_filename'];

        // Nettoyer le dossier temporaire
        Archiver::cleanDirectory($tempDir);

        $size = file_exists($zipFilePath) ? filesize($zipFilePath) : 0;
        $downloadUrl = wp_nonce_url(
            admin_url('admin.php?page=eg-backup-list&action=eg_backup_download&file=' . urlencode($zipFilename)),
            'eg_backup_download_action',
            'nonce'
        );

        wp_send_json_success([
            'filename'     => $zipFilename,
            'download_url' => $downloadUrl,
            'size'         => $size,
            'size_human'   => FileScanner::formatSize($size),
            'message'      => 'Sauvegarde terminée avec succès !',
        ]);
    }

    /**
     * Suppression d'une archive de sauvegarde.
     */
    public function eg_backup_delete() {
        $this->verifySecurity();
        $filename = sanitize_file_name($_POST['filename'] ?? '');

        if (empty($filename)) {
            wp_send_json_error(['message' => 'Nom de fichier invalide.']);
        }

        $deleted = Archiver::deleteBackup($filename);
        if ($deleted) {
            wp_send_json_success(['message' => 'Sauvegarde supprimée avec succès.']);
        } else {
            wp_send_json_error(['message' => 'Impossible de supprimer cette sauvegarde.']);
        }
    }

    /**
     * Inspecte une archive de sauvegarde (locale ou uploadée) et extrait son manifeste.
     */
    public function eg_restore_inspect() {
        $this->verifySecurity();

        $zipPath = '';

        if (!empty($_FILES['backup_file']['tmp_name'])) {
            $uploaded = $_FILES['backup_file'];
            if (pathinfo($uploaded['name'], PATHINFO_EXTENSION) !== 'zip') {
                wp_send_json_error(['message' => 'Le fichier doit être une archive .zip valide.']);
            }
            $targetPath = Archiver::getStorageDir() . sanitize_file_name($uploaded['name']);
            move_uploaded_file($uploaded['tmp_name'], $targetPath);
            $zipPath = $targetPath;
        } elseif (!empty($_POST['filename'])) {
            $filename = sanitize_file_name($_POST['filename']);
            $zipPath = Archiver::getStorageDir() . $filename;
        }

        if (!file_exists($zipPath)) {
            wp_send_json_error(['message' => 'Fichier de sauvegarde introuvable.']);
        }

        $manifestContent = Archiver::extractFileContentFromZip($zipPath, 'manifest.json');
        if (!$manifestContent) {
            wp_send_json_error(['message' => 'Ce fichier ne semble pas être une sauvegarde EG Backup valide (manifest.json introuvable).']);
        }

        $manifest = json_decode($manifestContent, true);
        if (!$manifest) {
            wp_send_json_error(['message' => 'Impossible de lire le manifeste de la sauvegarde.']);
        }

        $comparison = ManifestManager::compareWithCurrentEnvironment($manifest);

        wp_send_json_success([
            'filename'    => basename($zipPath),
            'manifest'    => $manifest,
            'comparison'  => $comparison,
        ]);
    }

    /**
     * Télécharge et installe un plugin officiel depuis WordPress.org en un clic.
     */
    public function eg_restore_install_plugin() {
        $this->verifySecurity();

        $slug = sanitize_text_field($_POST['slug'] ?? '');
        if (empty($slug)) {
            wp_send_json_error(['message' => 'Slug de plugin manquant.']);
        }

        require_once ABSPATH . 'wp-admin/includes/plugin-install.php';
        require_once ABSPATH . 'wp-admin/includes/class-wp-upgrader.php';
        require_once ABSPATH . 'wp-admin/includes/plugin.php';

        $api = plugins_api('plugin_information', [
            'slug'   => $slug,
            'fields' => ['sections' => false],
        ]);

        if (is_wp_error($api)) {
            wp_send_json_error(['message' => 'Impossible d\'obtenir les informations de l\'extension : ' . $api->get_error_message()]);
        }

        $upgrader = new \Plugin_Upgrader(new \Automatic_Upgrader_Skin());
        $installed = $upgrader->install($api->download_link);

        if (is_wp_error($installed)) {
            wp_send_json_error(['message' => 'Erreur lors de l\'installation : ' . $installed->get_error_message()]);
        }

        if (!$installed) {
            wp_send_json_error(['message' => 'Échec de l\'installation du plugin.']);
        }

        wp_send_json_success(['message' => sprintf('Extension %s installée avec succès !', esc_html($slug))]);
    }

    /**
     * Démarre le processus de restauration : extrait l'archive et active le mode maintenance.
     */
    public function eg_restore_start() {
        $this->verifySecurity();

        $filename = sanitize_file_name($_POST['filename'] ?? '');
        $zipPath = Archiver::getStorageDir() . $filename;

        if (!file_exists($zipPath)) {
            wp_send_json_error(['message' => 'Archive introuvable.']);
        }

        $jobId = 'restore_' . gmdate('Ymd_His') . '_' . wp_generate_password(6, false);
        $tempDir = Archiver::getTempDir() . $jobId . '/';
        wp_mkdir_p($tempDir);

        // Extraction de l'archive
        $extracted = Archiver::extractZip($zipPath, $tempDir);
        if (!$extracted) {
            wp_send_json_error(['message' => 'Échec de l\'extraction de l\'archive.']);
        }

        $manifestContent = file_exists($tempDir . 'manifest.json') ? file_get_contents($tempDir . 'manifest.json') : '';
        $manifest = $manifestContent ? json_decode($manifestContent, true) : null;
        $isMediaOnly = ($manifest && ($manifest['backup_type'] ?? '') === 'media');

        // Activation du mode maintenance uniquement pour les restaurations incluant la BDD
        if (!$isMediaOnly) {
            MaintenanceMode::enable();
        }

        // Génération d'un jeton secret de restauration hors base de données.
        $restoreToken = wp_generate_password(48, false);
        file_put_contents($tempDir . '.restore_token', $restoreToken);

        $sqlPath = $tempDir . 'database.sql';
        $sqlSize = file_exists($sqlPath) ? filesize($sqlPath) : 0;

        wp_send_json_success([
            'job_id'        => $jobId,
            'restore_token' => $restoreToken,
            'sql_size'      => $sqlSize,
            'has_sql'       => file_exists($sqlPath),
            'is_media_only' => $isMediaOnly,
            'message'       => $isMediaOnly ? 'Archive extraite. Prêt pour la restauration des fichiers.' : 'Archive extraite. Mode maintenance activé.',
        ]);
    }

    /**
     * Vérifie la sécurité des requêtes de restauration via le jeton de job ou les permissions WordPress.
     * Permet aux étapes de restauration de continuer même si l'écrasement de wp_users/wp_usermeta
     * invalide la session active de l'administrateur.
     */
    private function verifyRestoreSecurity() {
        $jobId = sanitize_text_field($_POST['job_id'] ?? '');
        $restoreToken = sanitize_text_field($_POST['restore_token'] ?? '');

        if (!empty($jobId) && !empty($restoreToken) && preg_match('/^restore_[a-zA-Z0-9_-]+$/', $jobId)) {
            $tokenFile = Archiver::getTempDir() . $jobId . '/.restore_token';
            if (file_exists($tokenFile) && is_readable($tokenFile)) {
                $storedToken = trim((string) file_get_contents($tokenFile));
                if (!empty($storedToken) && hash_equals($storedToken, $restoreToken)) {
                    return;
                }
            }
        }

        // Repli sur la vérification standard si le jeton est manquant ou invalide.
        $this->verifySecurity();
    }

    /**
     * Importe le fichier SQL par lots.
     */
    public function eg_restore_step_db() {
        $this->verifyRestoreSecurity();

        $jobId = sanitize_text_field($_POST['job_id'] ?? '');
        $offset = (int) ($_POST['offset'] ?? 0);
        $tempDir = Archiver::getTempDir() . $jobId . '/';
        $sqlPath = $tempDir . 'database.sql';

        if (!file_exists($sqlPath)) {
            wp_send_json_success([
                'eof'      => true,
                'offset'   => 0,
                'progress' => 100,
                'message'  => 'Aucune base de données à importer (archive médias/documents).',
            ]);
            return;
        }

        $fileSize = filesize($sqlPath);
        $result = DatabaseImporter::importSqlChunk($sqlPath, $offset, 2097152); // 2 Mo par passe

        if (isset($result['error'])) {
            wp_send_json_error(['message' => $result['error']]);
        }

        $newOffset = $result['new_offset'];
        $progress = $fileSize > 0 ? min(99, round(($newOffset / $fileSize) * 100)) : 100;

        if ($result['eof']) {
            $progress = 100;
        }

        wp_send_json_success([
            'eof'      => $result['eof'],
            'offset'   => $newOffset,
            'progress' => $progress,
            'message'  => sprintf('Importation BDD en cours (%d%%)...', $progress),
        ]);
    }

    /**
     * Exécute le Search & Replace pour la migration de domaine.
     */
    public function eg_restore_step_replace() {
        $this->verifyRestoreSecurity();

        $oldUrl = sanitize_text_field($_POST['old_url'] ?? '');
        $newUrl = sanitize_text_field($_POST['new_url'] ?? '');

        if (!empty($oldUrl) && !empty($newUrl) && $oldUrl !== $newUrl) {
            $stats = DatabaseImporter::searchAndReplace($oldUrl, $newUrl);
            wp_send_json_success([
                'message' => sprintf('Remplacement d\'URLs terminé (%d éléments mis à jour).', $stats['total_updates']),
            ]);
        } else {
            wp_send_json_success(['message' => 'Aucun remplacement d\'URL requis.']);
        }
    }

    /**
     * Restaure les fichiers vers wp-content.
     */
    public function eg_restore_step_files() {
        $this->verifyRestoreSecurity();

        $jobId = sanitize_text_field($_POST['job_id'] ?? '');
        $tempDir = Archiver::getTempDir() . $jobId . '/';
        $filesDir = $tempDir . 'files/';

        if (is_dir($filesDir)) {
            $this->copyDirectory($filesDir, WP_CONTENT_DIR);
        } else {
            // Prise en charge de tous les dossiers extraits (uploads, gallery, club-documents, etc.)
            $items = scandir($tempDir);
            if ($items !== false) {
                foreach ($items as $item) {
                    if ($item === '.' || $item === '..' || in_array($item, ['manifest.json', 'database.sql', 'theme_settings.json', '.restore_token', 'state.json'], true)) {
                        continue;
                    }
                    $candidate = $tempDir . $item . '/';
                    if (is_dir($candidate)) {
                        $this->copyDirectory($candidate, trailingslashit(WP_CONTENT_DIR) . $item . '/');
                    }
                }
            }
        }

        wp_send_json_success(['message' => 'Fichiers restaurés avec succès.']);
    }

    /**
     * Finalise la restauration : désactivation du mode maintenance et nettoyage temp.
     */
    public function eg_restore_finalize() {
        $this->verifyRestoreSecurity();

        $jobId = sanitize_text_field($_POST['job_id'] ?? '');
        $tempDir = Archiver::getTempDir() . $jobId . '/';

        $manifestFile = $tempDir . 'manifest.json';
        $manifestData = file_exists($manifestFile) ? json_decode(file_get_contents($manifestFile), true) : null;
        $isMediaOnly = ($manifestData && ($manifestData['backup_type'] ?? '') === 'media');
        $thumbsExcluded = !empty($manifestData['media_options']['thumbs_excluded']);

        if (!$isMediaOnly) {
            // 1. Restauration dédiée des réglages de thème si le fichier JSON est présent.
            $themeSettingsFile = $tempDir . 'theme_settings.json';
            if (file_exists($themeSettingsFile)) {
                $this->restoreThemeSettings($themeSettingsFile);
            }

            // 2. Synchronisation de secours entre thème parent et enfant (ex: Blocksy / Blocksy Child).
            $activeTheme = wp_get_theme();
            $curStylesheet = $activeTheme->get_stylesheet();
            $curTemplate = $activeTheme->get_template();
            if ($curStylesheet !== $curTemplate) {
                $parentMods = get_option("theme_mods_{$curTemplate}");
                $childMods = get_option("theme_mods_{$curStylesheet}");
                if (!empty($parentMods) && (empty($childMods) || count((array) $childMods) <= 1)) {
                    update_option("theme_mods_{$curStylesheet}", $parentMods);
                } elseif (!empty($childMods) && (empty($parentMods) || count((array) $parentMods) <= 1)) {
                    update_option("theme_mods_{$curTemplate}", $childMods);
                }
            }

            // 3. Purge des transitoires de cache de style dynamique de Blocksy.
            global $wpdb;
            $wpdb->query("DELETE FROM {$wpdb->options} WHERE option_name LIKE '_transient_blocksy_%' OR option_name LIKE '_transient_timeout_blocksy_%'");

            // 4. Vider le cache objet de WordPress pour forcer la prise en compte immédiate.
            wp_cache_flush();
            if (function_exists('wp_clean_themes_cache')) {
                wp_clean_themes_cache();
            }
        }

        Archiver::cleanDirectory($tempDir);
        MaintenanceMode::disable();

        wp_send_json_success([
            'message'         => $isMediaOnly ? 'Médias et documents restaurés avec succès !' : 'Restauration terminée avec succès ! Le site est à nouveau en ligne.',
            'is_media_only'   => $isMediaOnly,
            'thumbs_excluded' => $thumbsExcluded,
        ]);
    }

    /**
     * Annule la restauration en cas d'erreur : désactivation maintenance et nettoyage temp.
     */
    public function eg_restore_cancel() {
        $this->verifyRestoreSecurity();

        $jobId = sanitize_text_field($_POST['job_id'] ?? '');
        if (!empty($jobId)) {
            $tempDir = Archiver::getTempDir() . $jobId . '/';
            Archiver::cleanDirectory($tempDir);
        }
        MaintenanceMode::disable();

        wp_send_json_success([
            'message' => 'Mode maintenance désactivé avec succès.',
        ]);
    }

    /**
     * Copie récursivement un répertoire source vers une destination.
     */
    private function copyDirectory($src, $dst) {
        if (!is_dir($src)) {
            return;
        }
        wp_mkdir_p($dst);

        $dir = opendir($src);
        while (($file = readdir($dir)) !== false) {
            if ($file === '.' || $file === '..') {
                continue;
            }
            $srcPath = $src . '/' . $file;
            $dstPath = $dst . '/' . $file;

            if (is_dir($srcPath)) {
                $this->copyDirectory($srcPath, $dstPath);
            } else {
                @copy($srcPath, $dstPath);
            }
        }
        closedir($dir);
    }

    /**
     * Ajoute un répertoire récursivement dans une archive ZIP.
     */
    private function addDirToZip(\ZipArchive $zip, $dir, $zipPrefix = '') {
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::SELF_FIRST
        );

        foreach ($iterator as $file) {
            $filePath = $file->getRealPath();
            $relativePath = substr($filePath, strlen($dir));
            $zipPath = $zipPrefix . '/' . ltrim(str_replace('\\', '/', $relativePath), '/');

            if ($file->isDir()) {
                $zip->addEmptyDir($zipPath);
            } elseif ($file->isFile()) {
                $zip->addFile($filePath, $zipPath);
            }
        }
    }

    /**
     * Collecte récursivement tous les fichiers à sauvegarder en excluant les dossiers sensibles/caches et miniatures.
     *
     * @param array $selectedFolders
     * @param bool $excludeThumbs Si vrai, ignore les vignettes générées WP et NextGEN
     * @param array $extraExcludes Règles d'exclusion personnalisées (ex: déclarées par plugins tiers)
     * @return array Liste de fichiers ['disk' => string, 'zip' => string]
     */
    private function collectFilesToArchive(array $selectedFolders, $excludeThumbs = false, array $extraExcludes = []) {
        $contentDir = wp_normalize_path(WP_CONTENT_DIR);
        $filesQueue = [];

        $excludedNames = [
            'eg-backup-data',
            'eg-backup-temp',
            'cache',
            'wpcache',
            'et-cache',
            'wphb-cache',
            'wp-rocket-config',
            '.git',
            '.svn',
            'node_modules',
        ];

        foreach ($selectedFolders as $relFolder) {
            $relFolder = ltrim(wp_normalize_path($relFolder), '/');
            $folderPath = $contentDir . '/' . $relFolder;

            if (is_file($folderPath)) {
                $filename = basename($folderPath);
                if ($excludeThumbs && FileScanner::isThumbnailFile($filename, $folderPath)) {
                    continue;
                }
                $filesQueue[] = [
                    'disk' => $folderPath,
                    'zip'  => $relFolder,
                ];
                continue;
            }

            if (!is_dir($folderPath) || !is_readable($folderPath)) {
                continue;
            }

            try {
                $directoryIterator = new \RecursiveDirectoryIterator($folderPath, \FilesystemIterator::SKIP_DOTS | \FilesystemIterator::FOLLOW_SYMLINKS);
                $filterIterator = new \RecursiveCallbackFilterIterator($directoryIterator, function($current) use ($excludedNames) {
                    $filename = strtolower($current->getFilename());
                    if (in_array($filename, $excludedNames, true)) {
                        return false;
                    }
                    return true;
                });

                $iterator = new \RecursiveIteratorIterator($filterIterator, \RecursiveIteratorIterator::LEAVES_ONLY);

                foreach ($iterator as $file) {
                    if ($file->isFile()) {
                        $filename = $file->getFilename();
                        $diskPath = wp_normalize_path($file->getRealPath());
                        $zipPath = ltrim(str_replace($contentDir, '', $diskPath), '/');

                        // Exclure les miniatures et vignettes générées si demandé
                        if ($excludeThumbs && FileScanner::isThumbnailFile($filename, $diskPath)) {
                            continue;
                        }

                        // Exclusions spécifiques (patterns extraExcludes)
                        if (!empty($extraExcludes)) {
                            $skip = false;
                            foreach ($extraExcludes as $pattern) {
                                if (fnmatch('*' . trim($pattern, '/*') . '*', $zipPath)) {
                                    $skip = true;
                                    break;
                                }
                            }
                            if ($skip) {
                                continue;
                            }
                        }

                        $filesQueue[$diskPath] = [
                            'disk' => $diskPath,
                            'zip'  => $zipPath,
                        ];
                    }
                }
            } catch (\Exception $e) {
                // Ignore permissions or broken symlinks.
            }
        }

        return array_values($filesQueue);
    }

    /**
     * Exporte un instantané JSON dédié des réglages du thème actif (Blocksy, etc.).
     *
     * @param string $targetFile Chemin absolu du fichier JSON cible.
     */
    private function exportThemeSettings($targetFile) {
        global $wpdb;

        $activeTheme = wp_get_theme();
        $stylesheet = $activeTheme->get_stylesheet();
        $template = $activeTheme->get_template();

        $themeData = [
            'stylesheet' => $stylesheet,
            'template'   => $template,
            'theme_mods' => get_theme_mods(),
            'options'    => [],
        ];

        // Collecter les options associées aux thèmes populaires (Blocksy, Astra, etc.).
        $rows = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT option_name FROM {$wpdb->options} 
                 WHERE option_name LIKE %s 
                    OR option_name LIKE %s 
                    OR option_name LIKE 'blocksy_%' 
                    OR option_name LIKE 'astra_%' 
                    OR option_name LIKE 'kadence_%'",
                'theme_mods_' . $stylesheet,
                'theme_mods_' . $template
            ),
            ARRAY_A
        );

        if (!empty($rows)) {
            foreach ($rows as $row) {
                $optName = $row['option_name'];
                $themeData['options'][$optName] = get_option($optName);
            }
        }

        file_put_contents($targetFile, wp_json_encode($themeData, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
    }

    /**
     * Restaure les réglages de thème et assure la synchronisation des palettes et styles.
     *
     * @param string $filePath Chemin du fichier JSON de réglages.
     */
    private function restoreThemeSettings($filePath) {
        $raw = file_get_contents($filePath);
        if (!$raw) {
            return;
        }

        $themeData = json_decode($raw, true);
        if (!is_array($themeData)) {
            return;
        }

        $activeTheme = wp_get_theme();
        $currentStylesheet = $activeTheme->get_stylesheet();
        $currentTemplate = $activeTheme->get_template();

        // 1. Restauration des options spécifiques (blocksy_*, etc.).
        if (!empty($themeData['options']) && is_array($themeData['options'])) {
            foreach ($themeData['options'] as $name => $val) {
                update_option($name, $val);
            }
        }

        // 2. Restauration des theme_mods.
        if (!empty($themeData['theme_mods']) && is_array($themeData['theme_mods'])) {
            update_option("theme_mods_{$currentStylesheet}", $themeData['theme_mods']);
            if ($currentStylesheet !== $currentTemplate) {
                update_option("theme_mods_{$currentTemplate}", $themeData['theme_mods']);
            }
        }
    }
}

