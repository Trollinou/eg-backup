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
    }

    /**
     * Commande par défaut ou aide.
     */
    public static function run($args, $assocArgs) {
        \WP_CLI::line("Commandes EG Backup disponibles :");
        \WP_CLI::line("  wp eg-backup list                     - Liste les sauvegardes");
        \WP_CLI::line("  wp eg-backup create [--folders=<f>]   - Crée une sauvegarde");
        \WP_CLI::line("  wp eg-backup inspect <filename>       - Inspecte le manifeste");
        \WP_CLI::line("  wp eg-backup restore <filename>       - Restaure une sauvegarde");
    }

    /**
     * Crée une nouvelle sauvegarde.
     */
    public static function create($args, $assocArgs) {
        \WP_CLI::line("Initialisation de la sauvegarde...");
        Archiver::ensureStorageDirectory();

        $selectedFolders = [];
        if (!empty($assocArgs['folders'])) {
            $selectedFolders = explode(',', $assocArgs['folders']);
        } else {
            $selectedFolders = ['uploads']; // Par défaut
        }

        $jobId = 'cli_' . gmdate('Ymd_His');
        $tempDir = Archiver::getTempDir() . $jobId . '/';
        wp_mkdir_p($tempDir);

        $tables = DatabaseExporter::getTables();
        $sqlFile = $tempDir . 'database.sql';
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

        \WP_CLI::line("Copie des fichiers sélectionnés...");
        $filesDir = $tempDir . 'files/';
        wp_mkdir_p($filesDir);
        $contentDir = wp_normalize_path(WP_CONTENT_DIR);

        foreach ($selectedFolders as $relFolder) {
            $src = $contentDir . '/' . trim($relFolder, '/');
            $dst = $filesDir . trim($relFolder, '/');
            if (is_dir($src)) {
                self::copyRecursive($src, $dst);
            }
        }

        \WP_CLI::line("Génération du manifeste et compression ZIP...");
        $manifest = ManifestManager::generateManifest([
            'files_included'  => $selectedFolders,
            'tables_included' => $tables,
        ]);
        ManifestManager::saveToFile($tempDir . 'manifest.json', $manifest);

        $zipFilename = 'backup_' . gmdate('Y-m-d_H-i-s') . '_cli.zip';
        $zipFilePath = Archiver::getStorageDir() . $zipFilename;

        $zip = new \ZipArchive();
        $zip->open($zipFilePath, \ZipArchive::CREATE | \ZipArchive::OVERWRITE);
        $zip->addFile($tempDir . 'manifest.json', 'manifest.json');
        $zip->addFile($sqlFile, 'database.sql');

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
        Archiver::cleanDirectory($tempDir);

        \WP_CLI::success(sprintf("Sauvegarde créée avec succès : %s (%s)", $zipFilename, FileScanner::formatSize(filesize($zipFilePath))));
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
        \WP_CLI::line("Date : " . $manifest['created_at']);
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

        \WP_CLI::confirm("Êtes-vous sûr de vouloir écraser la base de données actuelle pour restaurer cette archive ?");

        \WP_CLI::line("Activation du mode maintenance...");
        MaintenanceMode::enable();

        $jobId = 'cli_restore_' . gmdate('Ymd_His');
        $tempDir = Archiver::getTempDir() . $jobId . '/';
        wp_mkdir_p($tempDir);

        \WP_CLI::line("Décompression de l'archive...");
        Archiver::extractZip($zipPath, $tempDir);

        $sqlPath = $tempDir . 'database.sql';
        if (file_exists($sqlPath)) {
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
        if (!empty($assocArgs['search']) && !empty($assocArgs['replace'])) {
            \WP_CLI::line(sprintf("Search & Replace sérialisé (%s -> %s)...", $assocArgs['search'], $assocArgs['replace']));
            DatabaseImporter::searchAndReplace($assocArgs['search'], $assocArgs['replace']);
        }

        // Fichiers
        $filesDir = $tempDir . 'files/';
        if (is_dir($filesDir)) {
            \WP_CLI::line("Restauration des fichiers vers wp-content...");
            self::copyRecursive($filesDir, WP_CONTENT_DIR);
        }

        Archiver::cleanDirectory($tempDir);
        MaintenanceMode::disable();

        \WP_CLI::success("Restauration terminée avec succès !");
    }

    private static function copyRecursive($src, $dst) {
        if (!is_dir($src)) return;
        wp_mkdir_p($dst);
        $dir = opendir($src);
        while (($file = readdir($dir)) !== false) {
            if ($file === '.' || $file === '..') continue;
            $s = "$src/$file";
            $d = "$dst/$file";
            if (is_dir($s)) {
                self::copyRecursive($s, $d);
            } else {
                @copy($s, $d);
            }
        }
        closedir($dir);
    }
}
