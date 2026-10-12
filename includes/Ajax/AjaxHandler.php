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
        ];

        foreach ($actions as $action) {
            add_action("wp_ajax_{$action}", [$this, $action]);
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
     * Scanne l'arborescence de wp-content et renvoie l'arborescence avec tailles.
     */
    public function eg_backup_scan_files() {
        $this->verifySecurity();
        $tree = FileScanner::scanContentDirectory(3);
        wp_send_json_success(['tree' => $tree]);
    }

    /**
     * Initialise un job de sauvegarde.
     */
    public function eg_backup_start() {
        $this->verifySecurity();
        Archiver::ensureStorageDirectory();

        $selectedFolders = isset($_POST['selected_folders']) ? (array) $_POST['selected_folders'] : [];
        $selectedFolders = array_map('sanitize_text_field', $selectedFolders);

        $jobId = 'job_' . gmdate('Ymd_His') . '_' . wp_generate_password(6, false);
        $tempDir = Archiver::getTempDir() . $jobId . '/';
        wp_mkdir_p($tempDir);

        $tables = DatabaseExporter::getTables();
        $sqlFile = $tempDir . 'database.sql';
        DatabaseExporter::initSqlFile($sqlFile);

        // Sauvegarde de l'état du travail dans un fichier d'état
        $state = [
            'job_id'           => $jobId,
            'temp_dir'         => $tempDir,
            'sql_file'         => $sqlFile,
            'tables'           => $tables,
            'total_tables'     => count($tables),
            'selected_folders' => $selectedFolders,
            'created_at'       => time(),
        ];
        file_put_contents($tempDir . 'state.json', wp_json_encode($state));

        wp_send_json_success([
            'job_id'       => $jobId,
            'total_tables' => count($tables),
            'tables'       => $tables,
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

        if ($tableIndex >= $totalTables) {
            // Terminé pour la BDD
            DatabaseExporter::finalizeSqlFile($state['sql_file']);
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

        // 1. Collecter tous les fichiers à sauvegarder avec exclusions strictes
        $filesQueue = $this->collectFilesToArchive($selectedFolders);
        $totalFiles = count($filesQueue);

        // Sauvegarder la liste des fichiers
        file_put_contents($tempDir . 'files_queue.json', wp_json_encode($filesQueue));

        // 2. Génération du manifest.json
        $manifest = ManifestManager::generateManifest([
            'files_included'  => $selectedFolders,
            'tables_included' => $state['tables'],
        ]);
        ManifestManager::saveToFile($tempDir . 'manifest.json', $manifest);

        // 3. Initialiser le fichier ZIP final
        $zipFilename = 'backup_' . gmdate('Y-m-d_H-i-s') . '_' . sanitize_title(get_bloginfo('name')) . '.zip';
        $zipFilePath = Archiver::getStorageDir() . $zipFilename;

        $zip = new \ZipArchive();
        if ($zip->open($zipFilePath, \ZipArchive::CREATE | \ZipArchive::OVERWRITE) !== true) {
            wp_send_json_error(['message' => 'Impossible d\'initialiser l\'archive ZIP.']);
        }

        $zip->addFile($tempDir . 'manifest.json', 'manifest.json');
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
            'total_files' => $totalFiles,
            'message'     => sprintf('%d fichier(s) répertorié(s) pour l\'archivage.', $totalFiles),
        ]);
    }

    /**
     * Ajoute un lot de fichiers à l'archive ZIP (chunking anti-timeout).
     */
    public function eg_backup_step_files() {
        $this->verifySecurity();
        @set_time_limit(180);

        $jobId = sanitize_text_field($_POST['job_id'] ?? '');
        $offset = (int) ($_POST['offset'] ?? 0);
        $batchSize = 150; // 150 fichiers par requête

        $tempDir = Archiver::getTempDir() . $jobId . '/';
        $stateFile = $tempDir . 'state.json';
        $queueFile = $tempDir . 'files_queue.json';

        if (!file_exists($stateFile) || !file_exists($queueFile)) {
            wp_send_json_error(['message' => 'Job introuvable.']);
        }

        $state = json_decode(file_get_contents($stateFile), true);
        $filesQueue = json_decode(file_get_contents($queueFile), true);
        $totalFiles = count($filesQueue);

        if ($totalFiles === 0 || $offset >= $totalFiles) {
            wp_send_json_success([
                'finished' => true,
                'offset'   => $totalFiles,
                'total'    => $totalFiles,
                'progress' => 100,
                'message'  => 'Archivage des fichiers terminé.',
            ]);
        }

        $batch = array_slice($filesQueue, $offset, $batchSize);
        $zipFilePath = $state['zip_filepath'];

        $zip = new \ZipArchive();
        if ($zip->open($zipFilePath, \ZipArchive::OPEN_EXISTING) !== true) {
            wp_send_json_error(['message' => 'Impossible d\'ouvrir l\'archive ZIP pour ajouter des fichiers.']);
        }

        foreach ($batch as $item) {
            $disk = $item['disk'];
            $rel = $item['zip'];
            if (is_file($disk) && is_readable($disk)) {
                $zip->addFile($disk, 'files/' . $rel);
            }
        }

        $zip->close();

        $newOffset = $offset + count($batch);
        $isFinished = ($newOffset >= $totalFiles);
        $progress = $totalFiles > 0 ? round(($newOffset / $totalFiles) * 100) : 100;

        wp_send_json_success([
            'finished'   => $isFinished,
            'offset'     => $newOffset,
            'total'      => $totalFiles,
            'progress'   => $progress,
            'message'    => sprintf('Archivage des fichiers : %d / %d (%d%%)...', $newOffset, $totalFiles, $progress),
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

        wp_send_json_success([
            'filename'   => $zipFilename,
            'size'       => $size,
            'size_human' => FileScanner::formatSize($size),
            'message'    => 'Sauvegarde terminée avec succès !',
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

        // Activation du mode maintenance
        MaintenanceMode::enable();

        $sqlPath = $tempDir . 'database.sql';
        $sqlSize = file_exists($sqlPath) ? filesize($sqlPath) : 0;

        wp_send_json_success([
            'job_id'   => $jobId,
            'sql_size' => $sqlSize,
            'has_sql'  => file_exists($sqlPath),
            'message'  => 'Archive extraite. Mode maintenance activé.',
        ]);
    }

    /**
     * Importe le fichier SQL par lots.
     */
    public function eg_restore_step_db() {
        $this->verifySecurity();

        $jobId = sanitize_text_field($_POST['job_id'] ?? '');
        $offset = (int) ($_POST['offset'] ?? 0);
        $tempDir = Archiver::getTempDir() . $jobId . '/';
        $sqlPath = $tempDir . 'database.sql';

        if (!file_exists($sqlPath)) {
            wp_send_json_success(['eof' => true, 'progress' => 100]);
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
        $this->verifySecurity();

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
        $this->verifySecurity();

        $jobId = sanitize_text_field($_POST['job_id'] ?? '');
        $tempDir = Archiver::getTempDir() . $jobId . '/';
        $filesDir = $tempDir . 'files/';

        if (is_dir($filesDir)) {
            $this->copyDirectory($filesDir, WP_CONTENT_DIR);
        }

        wp_send_json_success(['message' => 'Fichiers restaurés avec succès.']);
    }

    /**
     * Finalise la restauration : désactivation du mode maintenance et nettoyage temp.
     */
    public function eg_restore_finalize() {
        $this->verifySecurity();

        $jobId = sanitize_text_field($_POST['job_id'] ?? '');
        $tempDir = Archiver::getTempDir() . $jobId . '/';

        Archiver::cleanDirectory($tempDir);
        MaintenanceMode::disable();

        wp_send_json_success([
            'message' => 'Restauration terminée avec succès ! Le site est à nouveau en ligne.',
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
     * Collecte récursivement tous les fichiers à sauvegarder en excluant les dossiers sensibles/caches.
     *
     * @param array $selectedFolders
     * @return array Liste de fichiers ['disk' => string, 'zip' => string]
     */
    private function collectFilesToArchive(array $selectedFolders) {
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
                        $diskPath = wp_normalize_path($file->getRealPath());
                        $zipPath = ltrim(str_replace($contentDir, '', $diskPath), '/');

                        $filesQueue[] = [
                            'disk' => $diskPath,
                            'zip'  => $zipPath,
                        ];
                    }
                }
            } catch (\Exception $e) {
                // Ignore permissions or broken symlinks
            }
        }

        return $filesQueue;
    }
}

