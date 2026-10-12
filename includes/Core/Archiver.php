<?php
namespace EGBackup\Core;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Gestionnaire d'archives ZIP et du répertoire de stockage sécurisé.
 */
class Archiver {
    /**
     * Récupère le chemin du répertoire de stockage des sauvegardes.
     *
     * @return string
     */
    public static function getStorageDir() {
        $uploadDir = wp_upload_dir();
        $baseDir = wp_normalize_path($uploadDir['basedir']);
        return trailingslashit($baseDir) . 'eg-backup-data/';
    }

    /**
     * Récupère le chemin du dossier temporaire de travail.
     *
     * @return string
     */
    public static function getTempDir() {
        return trailingslashit(self::getStorageDir()) . 'temp/';
    }

    /**
     * S'assure de l'existence et de la protection du dossier de stockage.
     *
     * @return bool
     */
    public static function ensureStorageDirectory() {
        $storageDir = self::getStorageDir();
        $tempDir = self::getTempDir();

        if (!is_dir($storageDir)) {
            wp_mkdir_p($storageDir);
        }

        if (!is_dir($tempDir)) {
            wp_mkdir_p($tempDir);
        }

        // Fichier index.html vide pour empêcher le listage Apache/Nginx
        $indexFile = $storageDir . 'index.html';
        if (!file_exists($indexFile)) {
            file_put_contents($indexFile, '<!DOCTYPE html><html><head><title>403 Forbidden</title></head><body><h1>Directory access is forbidden.</h1></body></html>');
        }

        // Fichier .htaccess pour Apache
        $htaccessFile = $storageDir . '.htaccess';
        if (!file_exists($htaccessFile)) {
            $rules = "# EG Backup & Restore - Protection des archives\n";
            $rules .= "<IfModule mod_authz_core.c>\n    Require all denied\n</IfModule>\n";
            $rules .= "<IfModule !mod_authz_core.c>\n    Order deny,allow\n    Deny from all\n</IfModule>\n";
            file_put_contents($htaccessFile, $rules);
        }

        return is_dir($storageDir) && is_writable($storageDir);
    }

    /**
     * Retourne la liste des fichiers de sauvegarde existants.
     *
     * @return array
     */
    public static function getBackupsList() {
        self::ensureStorageDirectory();
        $storageDir = self::getStorageDir();
        $files = glob($storageDir . '*.zip');
        $backups = [];

        if (empty($files)) {
            return $backups;
        }

        // Trier par date décroissante (les plus récents d'abord)
        usort($files, function($a, $b) {
            return filemtime($b) - filemtime($a);
        });

        foreach ($files as $file) {
            $filename = basename($file);
            $size = filesize($file);
            $mtime = filemtime($file);

            // Tenter de lire le manifest.json embarqué dans le zip
            $manifest = self::extractFileContentFromZip($file, 'manifest.json');
            $manifestData = $manifest ? json_decode($manifest, true) : null;

            $backups[] = [
                'filename'    => $filename,
                'path'        => $file,
                'size'        => $size,
                'size_human'  => FileScanner::formatSize($size),
                'date'        => date_i18n(get_option('date_format') . ' ' . get_option('time_format'), $mtime),
                'timestamp'   => $mtime,
                'manifest'    => $manifestData,
            ];
        }

        return $backups;
    }

    /**
     * Supprime un fichier de sauvegarde.
     *
     * @param string $filename Nom du fichier zip.
     * @return bool
     */
    public static function deleteBackup($filename) {
        $storageDir = self::getStorageDir();
        $safeFilename = sanitize_file_name($filename);
        $filePath = $storageDir . $safeFilename;

        if (file_exists($filePath) && strtolower(pathinfo($filePath, PATHINFO_EXTENSION)) === 'zip') {
            return unlink($filePath);
        }

        return false;
    }

    /**
     * Lit le contenu d'un fichier présent dans une archive ZIP sans extraire tout le zip.
     *
     * @param string $zipPath Chemin absolu du zip.
     * @param string $internalPath Chemin du fichier dans l'archive.
     * @return string|null
     */
    public static function extractFileContentFromZip($zipPath, $internalPath) {
        if (!class_exists('ZipArchive')) {
            return null;
        }

        $zip = new \ZipArchive();
        if ($zip->open($zipPath) === true) {
            $content = $zip->getFromName($internalPath);
            $zip->close();
            return $content !== false ? $content : null;
        }

        return null;
    }

    /**
     * Extrait une archive ZIP vers un dossier cible.
     *
     * @param string $zipPath Chemin du fichier zip.
     * @param string $extractTo Chemin du dossier de destination.
     * @return bool
     */
    public static function extractZip($zipPath, $extractTo) {
        if (!class_exists('ZipArchive')) {
            return false;
        }

        $zip = new \ZipArchive();
        if ($zip->open($zipPath) === true) {
            wp_mkdir_p($extractTo);
            $result = $zip->extractTo($extractTo);
            $zip->close();
            return $result;
        }

        return false;
    }

    /**
     * Ajoute des fichiers et répertoires à une archive ZIP.
     *
     * @param string $zipPath Chemin du fichier ZIP cible.
     * @param array $filesToZip Tableau associatif ['chemin/dans/le/zip' => 'chemin/reel/sur/disque'].
     * @return bool
     */
    public static function addFilesToZip($zipPath, array $filesToZip) {
        if (!class_exists('ZipArchive')) {
            return false;
        }

        $zip = new \ZipArchive();
        $mode = file_exists($zipPath) ? \ZipArchive::OPEN_EXISTING : \ZipArchive::CREATE;

        if ($zip->open($zipPath, $mode) !== true) {
            return false;
        }

        foreach ($filesToZip as $zipName => $diskPath) {
            if (is_file($diskPath)) {
                $zip->addFile($diskPath, $zipName);
            }
        }

        return $zip->close();
    }

    /**
     * Nettoie récursivement un dossier de travail temporaire.
     *
     * @param string $dir
     */
    public static function cleanDirectory($dir) {
        if (!is_dir($dir)) {
            return;
        }

        $files = array_diff(scandir($dir), ['.', '..']);
        foreach ($files as $file) {
            $path = "$dir/$file";
            if (is_dir($path)) {
                self::cleanDirectory($path);
            } else {
                @unlink($path);
            }
        }
        @rmdir($dir);
    }
}
