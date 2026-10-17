<?php
namespace EGBackup\Cli;

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
 * Commandes WP-CLI pour EG Backup & Restore.
 */
class BackupCommand {
    /**
     * Enregistre la commande auprès de WP-CLI.
     */
    public static function register() {
        if (!class_exists('WP_CLI')) {
            return;
        }

        \WP_CLI::add_command('eg-backup', [__CLASS__, 'run']);
        \WP_CLI::add_command('eg-backup create', [__CLASS__, 'create']);
        \WP_CLI::add_command('eg-backup list', [__CLASS__, 'listBackups']);
        \WP_CLI::add_command('eg-backup inspect', [__CLASS__, 'inspect']);
        \WP_CLI::add_command('eg-backup restore', [__CLASS__, 'restore']);
        \WP_CLI::add_command('eg-backup purge-temp', [__CLASS__, 'purgeTemp']);
    }

    /**
     * Commande par défaut ou aide.
     */
    public static function run($args, $assocArgs) {
        \WP_CLI::line("Commandes EG Backup disponibles :");
        \WP_CLI::line("  wp eg-backup list                     - Liste les sauvegardes");
        \WP_CLI::line("  wp eg-backup create [--type=core|media|full] [--no-thumbs] [--folders=<f>] [--include-locations=<l>]");
        \WP_CLI::line("  wp eg-backup inspect <filename>       - Inspecte le manifeste");
        \WP_CLI::line("  wp eg-backup restore <filename>       - Restaure une sauvegarde");
        \WP_CLI::line("  wp eg-backup purge-temp [--max-age=<sec>] - Purge les dossiers temporaires orphelins");
    }

    /**
     * Crée une nouvelle sauvegarde (Cœur, Médias ou Complète).
     */
    public static function create($args, $assocArgs) {
        \WP_CLI::line("Initialisation de la sauvegarde...");
        Archiver::ensureStorageDirectory();

        $type = $assocArgs['type'] ?? 'core';
        if (!in_array($type, ['core', 'media', 'full'], true)) {
            $type = 'core';
        }

        $noThumbs = isset($assocArgs['no-thumbs']) || !isset($assocArgs['with-thumbs']);
        $selectedFolders = [];

        if (!empty($assocArgs['folders'])) {
            $selectedFolders = array_map('trim', explode(',', $assocArgs['folders']));
        } elseif ($type === 'core') {
            $activeTheme = wp_get_theme();
            $selectedFolders[] = 'themes/' . $activeTheme->get_stylesheet();
            if ($activeTheme->parent()) {
                $selectedFolders[] = 'themes/' . $activeTheme->get_template();
            }
        } elseif ($type === 'media') {
            $selectedFolders = ['uploads'];
            if (is_dir(WP_CONTENT_DIR . '/gallery')) {
                $selectedFolders[] = 'gallery';
            }
        } elseif ($type === 'full') {
            $selectedFolders = ['uploads'];
            if (is_dir(WP_CONTENT_DIR . '/gallery')) {
                $selectedFolders[] = 'gallery';
            }
            $activeTheme = wp_get_theme();
            $selectedFolders[] = 'themes/' . $activeTheme->get_stylesheet();
            if ($activeTheme->parent()) {
                $selectedFolders[] = 'themes/' . $activeTheme->get_template();
            }
        }

        // Gestion des emplacements enregistrés via hook
        $registeredLocations = FileScanner::getRegisteredLocations();
        $extraExcludes = [];
        $includedRegistered = [];

        if (!empty($assocArgs['include-locations'])) {
            $locIds = array_map('trim', explode(',', $assocArgs['include-locations']));
            foreach ($locIds as $lid) {
                if (isset($registeredLocations[$lid])) {
                    $selectedFolders[] = $registeredLocations[$lid]['relative_path'];
                    $includedRegistered[] = $registeredLocations[$lid];
                    if (!empty($registeredLocations[$lid]['exclude'])) {
                        $extraExcludes = array_merge($extraExcludes, (array) $registeredLocations[$lid]['exclude']);
                    }
                }
            }
        }

        $selectedFolders = array_values(array_unique($selectedFolders));

        $jobId = 'cli_' . wp_date('Ymd_His');
        $tempDir = Archiver::getTempDir() . $jobId . '/';
        wp_mkdir_p($tempDir);

        $tables = [];
        $sqlFile = $tempDir . 'database.sql';

        if ($type !== 'media') {
            $tables = DatabaseExporter::getTables();
            DatabaseExporter::initSqlFile($sqlFile);

            \WP_CLI::line(sprintf("Exportation de %d tables BDD...", count($tables)));
            $progress = \WP_CLI\Utils\make_progress_bar('Export BDD', count($tables));

            foreach ($tables as $table) {
                DatabaseExporter::exportTableStructure($table, $sqlFile);
                $totalRows = DatabaseExporter::countTableRows($table);
                $offset = 0;
                $limit = 2000;
                while ($offset < $totalRows) {
                    DatabaseExporter::exportTableChunk($table, $offset, $limit, $sqlFile);
                    $offset += $limit;
                }
                $progress->tick();
            }
            $progress->finish();
            DatabaseExporter::finalizeSqlFile($sqlFile);
        }

        \WP_CLI::line("Génération du manifeste...");
        $manifest = ManifestManager::generateManifest([
            'backup_type'          => $type,
            'thumbs_excluded'      => ($type !== 'core' && $noThumbs),
            'files_included'       => $selectedFolders,
            'registered_locations' => $includedRegistered,
            'tables_included'      => $tables,
        ]);
        ManifestManager::saveToFile($tempDir . 'manifest.json', $manifest);

        $prefix = ($type === 'core') ? 'backup_core_' : (($type === 'media') ? 'backup_media_' : 'backup_full_');
        $zipFilename = $prefix . wp_date('Y-m-d_H-i-s') . '_cli.zip';
        $zipFilePath = Archiver::getStorageDir() . $zipFilename;

        \WP_CLI::line("Création de l'archive ZIP...");
        if (Archiver::isCliZipAvailable()) {
            \WP_CLI::line("Utilisation du moteur binaire CLI zip...");
            $success = Archiver::archiveWithCliZip($tempDir, $zipFilePath, $selectedFolders, $noThumbs, $extraExcludes);
            if (!$success) {
                \WP_CLI::warning("Échec de zip CLI, repli sur PHP ZipArchive...");
            }
        }

        if (!file_exists($zipFilePath) || filesize($zipFilePath) === 0) {
            $filesDir = $tempDir . 'files/';
            wp_mkdir_p($filesDir);
            $contentDir = wp_normalize_path(WP_CONTENT_DIR);

            foreach ($selectedFolders as $relFolder) {
                $src = $contentDir . '/' . trim($relFolder, '/');
                $dst = $filesDir . trim($relFolder, '/');
                if (is_dir($src)) {
                    self::copyRecursive($src, $dst, $noThumbs);
                }
            }

            $zip = new \ZipArchive();
            $zip->open($zipFilePath, \ZipArchive::CREATE | \ZipArchive::OVERWRITE);
            $zip->addFile($tempDir . 'manifest.json', 'manifest.json');
            if (file_exists($sqlFile)) {
                $zip->addFile($sqlFile, 'database.sql');
            }

            if (is_dir($filesDir)) {
                $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($filesDir, \FilesystemIterator::SKIP_DOTS));
                foreach ($iterator as $f) {
                    if ($f->isFile()) {
                        $rel = substr($f->getRealPath(), strlen($filesDir));
                        $zip->addFile($f->getRealPath(), 'files/' . ltrim(str_replace('\\', '/', $rel), '/'));
                    }
                }
            }
            $zip->close();
        }

        Archiver::cleanDirectory($tempDir);

        \WP_CLI::success(sprintf("Sauvegarde [%s] créée avec succès : %s (%s)", strtoupper($type), $zipFilename, FileScanner::formatSize(filesize($zipFilePath))));
    }

    /**
     * Liste les sauvegardes existantes.
     */
    public static function listBackups($args, $assocArgs) {
        $backups = Archiver::getBackupsList();
        if (empty($backups)) {
            \WP_CLI::line("Aucune sauvegarde trouvée.");
            return;
        }

        $items = [];
        foreach ($backups as $b) {
            $items[] = [
                'Fichier' => $b['filename'],
                'Date'    => $b['date'],
                'Taille'  => $b['size_human'],
            ];
        }
        \WP_CLI\Utils\format_items('table', $items, ['Fichier', 'Date', 'Taille']);
    }

    /**
     * Inspecte une sauvegarde.
     */
    public static function inspect($args, $assocArgs) {
        if (empty($args[0])) {
            \WP_CLI::error("Veuillez spécifier le nom du fichier zip à inspecter.");
        }
        $filename = sanitize_file_name($args[0]);
        $zipPath = Archiver::getStorageDir() . $filename;

        if (!file_exists($zipPath)) {
            \WP_CLI::error("Archive introuvable : " . $filename);
        }

        $manifestContent = Archiver::extractFileContentFromZip($zipPath, 'manifest.json');
        if (!$manifestContent) {
            \WP_CLI::error("Fichier manifest.json introuvable dans cette archive.");
        }

        $manifest = json_decode($manifestContent, true);
        $comp = ManifestManager::compareWithCurrentEnvironment($manifest);

        \WP_CLI::line("=== Informations du site source ===");
        \WP_CLI::line("Site : " . ($manifest['site']['name'] ?? 'Inconnu'));
        \WP_CLI::line("Date : " . ($manifest['created_at_formatted'] ?? $manifest['created_at']));
        \WP_CLI::line("URL source : " . ($manifest['environment']['siteurl'] ?? ''));
        \WP_CLI::line("Version WP : " . ($manifest['environment']['version'] ?? ''));

        \WP_CLI::line("\n=== État des extensions requises ===");
        $pItems = [];
        foreach ($comp['plugins'] as $p) {
            $pItems[] = [
                'Extension' => $p['name'],
                'Requise'   => $p['required_version'],
                'Actuelle'  => $p['installed_version'] ?: 'Non installée',
                'Statut'    => $p['status'],
            ];
        }
        \WP_CLI\Utils\format_items('table', $pItems, ['Extension', 'Requise', 'Actuelle', 'Statut']);
    }

    /**
     * Restaure une sauvegarde.
     */
    public static function restore($args, $assocArgs) {
        if (empty($args[0])) {
            \WP_CLI::error("Veuillez spécifier le nom du fichier zip à restaurer.");
        }
        $filename = sanitize_file_name($args[0]);
        $zipPath = Archiver::getStorageDir() . $filename;

        if (!file_exists($zipPath)) {
            \WP_CLI::error("Archive introuvable : " . $filename);
        }

        $manifest = Archiver::extractFileContentFromZip($zipPath, 'manifest.json');
        $manifestData = $manifest ? json_decode($manifest, true) : null;
        $isMediaOnly = ($manifestData && ($manifestData['backup_type'] ?? '') === 'media');

        if ($isMediaOnly) {
            \WP_CLI::confirm("Restaurer les fichiers médias et documents de cette archive ?");
        } else {
            \WP_CLI::confirm("Êtes-vous sûr de vouloir écraser la base de données actuelle pour restaurer cette archive ?");
            \WP_CLI::line("Activation du mode maintenance...");
            MaintenanceMode::enable();
        }

        $jobId = 'cli_restore_' . wp_date('Ymd_His');
        $tempDir = Archiver::getTempDir() . $jobId . '/';
        wp_mkdir_p($tempDir);

        \WP_CLI::line("Décompression de l'archive...");
        Archiver::extractZip($zipPath, $tempDir);

        $sqlPath = $tempDir . 'database.sql';
        if (file_exists($sqlPath) && !$isMediaOnly) {
            \WP_CLI::line("Importation de la base de données...");
            $offset = 0;
            while (true) {
                $res = DatabaseImporter::importSqlChunk($sqlPath, $offset, 4194304);
                if (isset($res['error'])) {
                    MaintenanceMode::disable();
                    \WP_CLI::error($res['error']);
                }
                $offset = $res['new_offset'];
                if ($res['eof']) {
                    break;
                }
            }
        }

        // Remplacement d'URL optionnel
        if (!$isMediaOnly && !empty($assocArgs['search']) && !empty($assocArgs['replace'])) {
            \WP_CLI::line(sprintf("Search & Replace sérialisé (%s -> %s)...", $assocArgs['search'], $assocArgs['replace']));
            DatabaseImporter::searchAndReplace($assocArgs['search'], $assocArgs['replace']);
        }

        // Fichiers
        $filesDir = $tempDir . 'files/';
        if (is_dir($filesDir)) {
            \WP_CLI::line("Restauration des fichiers vers wp-content...");
            self::copyRecursive($filesDir, WP_CONTENT_DIR);
        } else {
            // Dossiers à la racine
            $items = scandir($tempDir);
            if ($items !== false) {
                foreach ($items as $item) {
                    if ($item === '.' || $item === '..' || in_array($item, ['manifest.json', 'database.sql', 'theme_settings.json'], true)) continue;
                    $srcCandidate = $tempDir . $item;
                    if (is_dir($srcCandidate)) {
                        self::copyRecursive($srcCandidate, trailingslashit(WP_CONTENT_DIR) . $item);
                    }
                }
            }
        }

        Archiver::cleanDirectory($tempDir);
        if (!$isMediaOnly) {
            MaintenanceMode::disable();
        }

        \WP_CLI::success("Restauration terminée avec succès !");

        if (!empty($manifestData['media_options']['thumbs_excluded'])) {
            \WP_CLI::line("");
            \WP_CLI::line("💡 Note : Les miniatures étaient exclues de cette archive.");
            \WP_CLI::line("Pour régénérer les vignettes WordPress : wp media regenerate --yes");
        }
    }

    private static function copyRecursive($src, $dst, $noThumbs = false) {
        if (!is_dir($src)) return;
        wp_mkdir_p($dst);
        $dir = opendir($src);
        while (($file = readdir($dir)) !== false) {
            if ($file === '.' || $file === '..') continue;
            $s = "$src/$file";
            $d = "$dst/$file";
            if (is_dir($s)) {
                if ($noThumbs && (strpos($file, 'thumbs') !== false || strpos($file, 'dynamic') !== false)) {
                    continue;
                }
                self::copyRecursive($s, $d, $noThumbs);
            } else {
                if ($noThumbs && FileScanner::isThumbnailFile($file, $s)) {
                    continue;
                }
                @copy($s, $d);
            }
        }
        closedir($dir);
    }

    /**
     * Purge les dossiers et fichiers temporaires orphelins.
     *
     * ## OPTIONS
     *
     * [--max-age=<seconds>]
     * : Âge minimal en secondes avant purge (par défaut : 7200, soit 2h).
     */
    public static function purgeTemp($args, $assocArgs) {
        $maxAge = isset($assocArgs['max-age']) ? (int) $assocArgs['max-age'] : 7200;
        \WP_CLI::line("Purge des dossiers temporaires orphelins (âge > {$maxAge}s)...");
        $purged = Archiver::purgeOldTempDirectories($maxAge);
        \WP_CLI::success(sprintf('%d dossier(s) temporaire(s) purgé(s).', $purged));
    }
}
