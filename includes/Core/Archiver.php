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
     * Récupère l'URL publique du dossier de stockage.
     *
     * @return string
     */
    public static function getStorageUrl() {
        $uploadDir = wp_upload_dir();
        return trailingslashit($uploadDir['baseurl']) . 'eg-backup-data/';
    }

    /**
     * Récupère le chemin du dossier des liens de téléchargement éphémères.
     *
     * @return string
     */
    public static function getDownloadTempDir() {
        return trailingslashit(self::getStorageDir()) . 'dl/';
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

        // Fichier .htaccess pour Apache.
        $htaccessFile = $storageDir . '.htaccess';
        if (!file_exists($htaccessFile)) {
            $rules = "# EG Backup & Restore - Protection des archives\n";
            $rules .= "<IfModule mod_authz_core.c>\n    Require all denied\n</IfModule>\n";
            $rules .= "<IfModule !mod_authz_core.c>\n    Order deny,allow\n    Deny from all\n</IfModule>\n";
            file_put_contents($htaccessFile, $rules);
        }

        // Fichier index.html vide dans le dossier temporaire.
        $tempIndexFile = $tempDir . 'index.html';
        if (!file_exists($tempIndexFile)) {
            file_put_contents($tempIndexFile, '<!DOCTYPE html><html><head><title>403 Forbidden</title></head><body><h1>Directory access is forbidden.</h1></body></html>');
        }

        // Nettoyage opportuniste des dossiers de travail temporaires expirés (plus de 2h).
        self::purgeOldTempDirectories();

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

            $timestamp = !empty($manifestData['timestamp']) ? (int) $manifestData['timestamp'] : $mtime;
            $dateFormat = get_option('date_format') . ' ' . get_option('time_format');
            $formattedDate = function_exists('wp_date')
                ? wp_date($dateFormat, $timestamp)
                : date_i18n($dateFormat, $timestamp + (int) (get_option('gmt_offset') * HOUR_IN_SECONDS));

            $backups[] = [
                'filename'    => $filename,
                'path'        => $file,
                'size'        => $size,
                'size_human'  => FileScanner::formatSize($size),
                'date'        => $formattedDate,
                'timestamp'   => $timestamp,
                'manifest'    => $manifestData,
            ];
        }

        // Trier par timestamp décroissant (les plus récents d'abord)
        usort($backups, function($a, $b) {
            return ($b['timestamp'] ?? 0) - ($a['timestamp'] ?? 0);
        });

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

        // Purge les liens de téléchargement éphémères pour libérer l'inode disque immédiatement.
        self::purgeOldDownloadLinks(0);

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
     * Extrait les métadonnées et la base de données d'un ZIP pour initialiser la restauration en < 1 seconde.
     *
     * @param string $zipPath Chemin absolu du fichier ZIP.
     * @param string $tempDir Répertoire temporaire de travail du job.
     * @return array|false Tableau avec 'total_files' et 'num_entries' ou false en cas d'erreur.
     */
    public static function initRestoreJob($zipPath, $tempDir) {
        if (!class_exists('ZipArchive') || !file_exists($zipPath)) {
            return false;
        }

        $zip = new \ZipArchive();
        if ($zip->open($zipPath) !== true) {
            return false;
        }

        wp_mkdir_p($tempDir);

        $rootEntriesToExtract = [];
        $totalFiles = 0;
        $numFiles = $zip->numFiles;

        for ($i = 0; $i < $numFiles; $i++) {
            $name = $zip->getNameIndex($i);
            if ($name === false) continue;

            $lower = strtolower($name);
            if ($lower === 'manifest.json' || $lower === 'database.sql' || $lower === 'theme_settings.json') {
                $rootEntriesToExtract[] = $name;
                continue;
            }

            if (substr($name, -1) !== '/' && (strpos($name, 'files/') === 0 || strpos($name, 'uploads/') === 0 || strpos($name, 'themes/') === 0 || strpos($name, 'gallery/') === 0)) {
                $totalFiles++;
            }
        }

        if (!empty($rootEntriesToExtract)) {
            $zip->extractTo($tempDir, $rootEntriesToExtract);
        }

        $zip->close();

        return [
            'total_files' => $totalFiles,
            'num_entries' => $numFiles,
        ];
    }

    /**
     * Extrait un lot de fichiers depuis l'archive ZIP directement vers WP_CONTENT_DIR (anti-timeout et sans double écriture).
     *
     * @param string $zipPath Chemin de l'archive ZIP.
     * @param int $startIndex Index de départ dans le ZIP.
     * @param int $batchCount Nombre maximum de fichiers par passe.
     * @param int $maxBytes Volume maximum en octets par passe.
     * @return array
     */
    public static function restoreFilesChunk($zipPath, $startIndex, $batchCount = 150, $maxBytes = 16777216) {
        if (!class_exists('ZipArchive') || !file_exists($zipPath)) {
            return ['error' => 'Archive introuvable ou module ZipArchive absent.'];
        }

        $zip = new \ZipArchive();
        if ($zip->open($zipPath) !== true) {
            return ['error' => 'Impossible d\'ouvrir l\'archive ZIP pour l\'extraction.'];
        }

        $contentDir = wp_normalize_path(WP_CONTENT_DIR);
        $numFiles = $zip->numFiles;
        $processedFiles = 0;
        $processedBytes = 0;
        $currentIndex = $startIndex;
        $startTime = microtime(true);
        $maxSeconds = 4.0; // 4 secondes maximum par lot AJAX pour garantir zéro timeout

        while ($currentIndex < $numFiles) {
            $entryName = $zip->getNameIndex($currentIndex);
            $currentIndex++;

            if ($entryName === false) {
                continue;
            }

            if (substr($entryName, -1) === '/') {
                continue;
            }

            $lower = strtolower($entryName);
            if ($lower === 'manifest.json' || $lower === 'database.sql' || $lower === 'theme_settings.json') {
                continue;
            }

            if (strpos($entryName, 'files/') === 0) {
                $relTarget = substr($entryName, 6);
            } else {
                $relTarget = $entryName;
            }

            $relTarget = ltrim(wp_normalize_path($relTarget), '/');
            $fullTarget = $contentDir . '/' . $relTarget;
            $targetDir = dirname($fullTarget);

            if (!is_dir($targetDir)) {
                wp_mkdir_p($targetDir);
            }

            $stream = $zip->getStream($entryName);
            if ($stream) {
                $outFp = @fopen($fullTarget, 'wb');
                if ($outFp) {
                    $copied = stream_copy_to_stream($stream, $outFp);
                    fclose($outFp);
                    if ($copied !== false) {
                        $processedBytes += (int) $copied;
                    }
                }
                fclose($stream);
                $processedFiles++;
            }

            if ($processedFiles >= $batchCount || $processedBytes >= $maxBytes || (microtime(true) - $startTime) >= $maxSeconds) {
                break;
            }
        }

        $zip->close();

        $eof = ($currentIndex >= $numFiles);
        $progress = $numFiles > 0 ? min(100, round(($currentIndex / $numFiles) * 100)) : 100;
        if ($eof) {
            $progress = 100;
        }

        return [
            'new_offset'      => $currentIndex,
            'processed_files' => $processedFiles,
            'processed_bytes' => $processedBytes,
            'total_entries'   => $numFiles,
            'eof'             => $eof,
            'progress'        => $progress,
        ];
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
        $mode = file_exists($zipPath) ? 0 : \ZipArchive::CREATE;

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
     * Vérifie si l'utilitaire binaire zip est disponible sur le système (Linux ou Windows).
     *
     * @return bool
     */
    public static function isCliZipAvailable() {
        if (!function_exists('exec')) {
            return false;
        }

        $disabledFunctions = explode(',', (string) ini_get('disable_functions'));
        $disabledFunctions = array_map('trim', $disabledFunctions);
        if (in_array('exec', $disabledFunctions, true)) {
            return false;
        }

        $output = [];
        $returnVar = -1;
        $checkCmd = (stripos(PHP_OS, 'WIN') === 0) ? 'where zip' : 'which zip';
        @exec($checkCmd, $output, $returnVar);

        return ($returnVar === 0 && !empty($output[0]));
    }

    /**
     * Lance l'archivage CLI de manière asynchrone et non-bloquante (anti-timeout HTTP).
     *
     * @param string $tempDir Répertoire temporaire contenant manifest.json et database.sql.
     * @param string $zipFilePath Chemin final du fichier ZIP.
     * @param array $selectedFolders Liste des dossiers relatifs à WP_CONTENT_DIR.
     * @param bool $excludeThumbs Si vrai, ignore les vignettes générées WP et NextGEN.
     * @param array $extraExcludes Règles d'exclusions supplémentaires (ex: provenant de hooks).
     * @return bool Vrai si le processus d'arrière-plan a été initié avec succès.
     */
    public static function startAsyncCliZip($tempDir, $zipFilePath, array $selectedFolders, $excludeThumbs = false, array $extraExcludes = []) {
        $contentDir = wp_normalize_path(WP_CONTENT_DIR);
        $tempFilesSymlink = $tempDir . 'files';

        if (file_exists($tempFilesSymlink) || is_link($tempFilesSymlink)) {
            @unlink($tempFilesSymlink);
        }

        // Création du lien symbolique files -> WP_CONTENT_DIR pour préserver la structure.
        if (!@symlink($contentDir, $tempFilesSymlink)) {
            return false;
        }

        // 1. Ajout préalable des fichiers racines (manifest, settings, db éventuelle) en direct
        $rootFiles = [];
        if (file_exists($tempDir . 'manifest.json')) $rootFiles[] = 'manifest.json';
        if (file_exists($tempDir . 'database.sql')) $rootFiles[] = 'database.sql';
        if (file_exists($tempDir . 'theme_settings.json')) $rootFiles[] = 'theme_settings.json';

        if (!empty($rootFiles)) {
            $cmdBase = "cd " . escapeshellarg($tempDir) . " && zip -q -0 " . escapeshellarg($zipFilePath) . " " . implode(' ', $rootFiles);
            @exec($cmdBase, $outBase, $retBase);
        }

        // 2. Dossiers sélectionnés
        $targetPaths = [];
        foreach ($selectedFolders as $relFolder) {
            $relFolder = ltrim(wp_normalize_path($relFolder), '/');
            if (file_exists($contentDir . '/' . $relFolder)) {
                $targetPaths[] = escapeshellarg('files/' . $relFolder);
            }
        }

        $doneFile = $tempDir . 'cli_done.flag';
        $logFile = $tempDir . 'zip_output.log';

        if (file_exists($doneFile)) {
            @unlink($doneFile);
        }

        if (empty($targetPaths)) {
            // Aucun dossier média sélectionné, marquage immédiat comme terminé
            @unlink($tempFilesSymlink);
            file_put_contents($doneFile, '0');
            return true;
        }

        $excludes = [
            '"files/*cache/*"',
            '"files/*wpcache/*"',
            '"files/*et-cache/*"',
            '"files/*eg-backup-*"',
            '"files/*node_modules/*"',
            '"files/*.git/*"',
            '"files/*.svn/*"',
        ];

        if ($excludeThumbs) {
            $excludes[] = '"*-[0-9]*x[0-9]*.*"';
            $excludes[] = '"*-scaled.*"';
            $excludes[] = '"*/thumbs/*"';
            $excludes[] = '"*thumbs_*"';
            $excludes[] = '"*/dynamic/*"';
        }

        foreach ($extraExcludes as $ex) {
            $excludes[] = escapeshellarg("files/*" . trim($ex, '/') . "*");
        }

        $excludeClause = '-x ' . implode(' ', $excludes);
        // Exécution sans -q pour consigner chaque fichier ajouté dans zip_output.log et suivre la progression
        $cmdFiles = "zip -r -0 " . escapeshellarg($zipFilePath) . " " . implode(' ', $targetPaths) . " " . $excludeClause;

        // Lancement en arrière-plan détaché selon l'OS pour éviter le timeout du serveur HTTP
        if (stripos(PHP_OS, 'WIN') === 0) {
            $batFile = $tempDir . 'run_zip.bat';
            $batContent = "@echo off\r\n";
            $batContent .= "cd /d " . escapeshellarg($tempDir) . "\r\n";
            $batContent .= $cmdFiles . " > " . escapeshellarg($logFile) . " 2>&1\r\n";
            $batContent .= "echo %ERRORLEVEL% > " . escapeshellarg($doneFile) . "\r\n";
            file_put_contents($batFile, $batContent);

            @exec("start /B cmd /c " . escapeshellarg($batFile) . " > NUL 2>&1");
            return true;
        } else {
            $shFile = $tempDir . 'run_zip.sh';
            $shContent = "#!/bin/sh\n";
            $shContent .= "cd " . escapeshellarg($tempDir) . "\n";
            $shContent .= $cmdFiles . " > " . escapeshellarg($logFile) . " 2>&1\n";
            $shContent .= "echo $? > " . escapeshellarg($doneFile) . "\n";
            file_put_contents($shFile, $shContent);
            @chmod($shFile, 0755);

            @exec("/bin/sh " . escapeshellarg($shFile) . " > /dev/null 2>&1 &");
            return true;
        }
    }

    /**
     * Vérifie l'état d'avancement d'un archivage CLI asynchrone (fichiers traités et taille en direct).
     *
     * @param string $tempDir Répertoire temporaire de travail.
     * @param string $zipFilePath Chemin final du fichier ZIP.
     * @return array
     */
    public static function checkCliZipStatus($tempDir, $zipFilePath) {
        $doneFile = $tempDir . 'cli_done.flag';
        $logFile = $tempDir . 'zip_output.log';
        $tempFilesSymlink = $tempDir . 'files';

        // 1. Détection de la volumétrie réelle écrite sur le disque (y compris fichier temporaire zi* d'Info-ZIP)
        $storageDir = dirname($zipFilePath);
        $tempCandidates = array_merge(
            glob($storageDir . '/zi*') ?: [],
            glob($tempDir . 'zi*') ?: []
        );
        $currentSize = 0;
        foreach ($tempCandidates as $tf) {
            if (is_file($tf)) {
                clearstatcache(true, $tf);
                $sz = (int) filesize($tf);
                if ($sz > $currentSize) {
                    $currentSize = $sz;
                }
            }
        }
        if ($currentSize === 0 && file_exists($zipFilePath)) {
            clearstatcache(true, $zipFilePath);
            $currentSize = (int) filesize($zipFilePath);
        }

        // 2. Décompte des fichiers traités et dernier fichier ajouté depuis zip_output.log
        $processedFiles = 0;
        $lastFile = '';
        if (file_exists($logFile)) {
            clearstatcache(true, $logFile);
            $logSize = (int) filesize($logFile);
            if ($logSize > 0) {
                $fp = @fopen($logFile, 'rb');
                if ($fp) {
                    while (!feof($fp)) {
                        $buf = fread($fp, 65536);
                        if ($buf !== false) {
                            $processedFiles += substr_count($buf, "\n");
                        }
                    }

                    // Récupérer le nom du dernier fichier ajouté depuis la fin du log
                    $readTail = min(2048, $logSize);
                    fseek($fp, -$readTail, SEEK_END);
                    $tail = fread($fp, $readTail);
                    fclose($fp);

                    if ($tail !== false) {
                        $lines = explode("\n", trim($tail));
                        for ($li = count($lines) - 1; $li >= 0; $li--) {
                            $line = trim($lines[$li]);
                            if (preg_match('/adding:\s+(.+?)\s+\(/i', $line, $m)) {
                                $lastFile = basename($m[1]);
                                break;
                            }
                        }
                    }
                }
            }
        }

        if (file_exists($doneFile)) {
            $rawStatus = trim((string) @file_get_contents($doneFile));
            $exitCode = is_numeric($rawStatus) ? (int) $rawStatus : 0;

            if (file_exists($tempFilesSymlink) || is_link($tempFilesSymlink)) {
                @unlink($tempFilesSymlink);
            }

            clearstatcache(true, $zipFilePath);
            $finalSize = file_exists($zipFilePath) ? (int) filesize($zipFilePath) : $currentSize;

            if ($exitCode === 0 && file_exists($zipFilePath) && $finalSize > 0) {
                return [
                    'finished'        => true,
                    'success'         => true,
                    'size'            => $finalSize,
                    'size_human'      => FileScanner::formatSize($finalSize),
                    'processed_files' => $processedFiles,
                    'message'         => sprintf('Archivage ultra-rapide CLI terminé avec succès (%s).', FileScanner::formatSize($finalSize)),
                ];
            } else {
                $logContent = file_exists($logFile) ? (string) @file_get_contents($logFile) : '';
                return [
                    'finished'        => true,
                    'success'         => false,
                    'size'            => $finalSize,
                    'processed_files' => $processedFiles,
                    'error'           => !empty($logContent) ? substr($logContent, -400) : 'Erreur lors de l\'archivage CLI en arrière-plan.',
                ];
            }
        }

        // Toujours en cours d'écriture
        return [
            'finished'        => false,
            'success'         => false,
            'size'            => $currentSize,
            'size_human'      => FileScanner::formatSize($currentSize),
            'processed_files' => $processedFiles,
            'last_file'       => $lastFile,
            'message'         => $processedFiles > 0
                ? sprintf('Archivage en cours : %d fichiers (%s)...', $processedFiles, FileScanner::formatSize($currentSize))
                : sprintf('Archivage en cours : %s écrits sur le disque...', FileScanner::formatSize($currentSize)),
        ];
    }

    /**
     * Archive les fichiers via l'utilitaire système zip (mode synchrone, principalement pour WP-CLI).
     *
     * @param string $tempDir Répertoire temporaire contenant manifest.json et database.sql.
     * @param string $zipFilePath Chemin final du fichier ZIP.
     * @param array $selectedFolders Liste des dossiers relatifs à WP_CONTENT_DIR.
     * @param bool $excludeThumbs Si vrai, ignore les vignettes générées WP et NextGEN.
     * @param array $extraExcludes Règles d'exclusions supplémentaires (ex: provenant de hooks).
     * @return bool
     */
    public static function archiveWithCliZip($tempDir, $zipFilePath, array $selectedFolders, $excludeThumbs = false, array $extraExcludes = []) {
        $contentDir = wp_normalize_path(WP_CONTENT_DIR);
        $tempFilesSymlink = $tempDir . 'files';

        if (file_exists($tempFilesSymlink) || is_link($tempFilesSymlink)) {
            @unlink($tempFilesSymlink);
        }

        // Création du lien symbolique files -> WP_CONTENT_DIR pour préserver la structure.
        if (!@symlink($contentDir, $tempFilesSymlink)) {
            return false;
        }

        // Ajout des fichiers à la racine du ZIP (manifest, settings, db éventuelle).
        $rootFiles = [];
        if (file_exists($tempDir . 'manifest.json')) $rootFiles[] = 'manifest.json';
        if (file_exists($tempDir . 'database.sql')) $rootFiles[] = 'database.sql';
        if (file_exists($tempDir . 'theme_settings.json')) $rootFiles[] = 'theme_settings.json';

        if (!empty($rootFiles)) {
            $cmdBase = "cd " . escapeshellarg($tempDir) . " && zip -q -0 " . escapeshellarg($zipFilePath) . " " . implode(' ', $rootFiles);
            @exec($cmdBase, $outBase, $retBase);
        }

        // Ajout des dossiers sélectionnés sous le préfixe files/.
        $targetPaths = [];
        foreach ($selectedFolders as $relFolder) {
            $relFolder = ltrim(wp_normalize_path($relFolder), '/');
            if (file_exists($contentDir . '/' . $relFolder)) {
                $targetPaths[] = escapeshellarg('files/' . $relFolder);
            }
        }

        if (!empty($targetPaths)) {
            $excludes = [
                '"files/*cache/*"',
                '"files/*wpcache/*"',
                '"files/*et-cache/*"',
                '"files/*eg-backup-*"',
                '"files/*node_modules/*"',
                '"files/*.git/*"',
                '"files/*.svn/*"',
            ];

            if ($excludeThumbs) {
                $excludes[] = '"*-[0-9]*x[0-9]*.*"';
                $excludes[] = '"*-scaled.*"';
                $excludes[] = '"*/thumbs/*"';
                $excludes[] = '"*thumbs_*"';
                $excludes[] = '"*/dynamic/*"';
            }

            foreach ($extraExcludes as $ex) {
                $excludes[] = escapeshellarg("files/*" . trim($ex, '/') . "*");
            }

            $excludeClause = '-x ' . implode(' ', $excludes);
            $cmdFiles = "cd " . escapeshellarg($tempDir) . " && zip -q -r -0 " . escapeshellarg($zipFilePath)
                . " " . implode(' ', $targetPaths) . " " . $excludeClause;
            @exec($cmdFiles, $outFiles, $retFiles);
        }

        @unlink($tempFilesSymlink);

        return file_exists($zipFilePath) && filesize($zipFilePath) > 0;
    }

    /**
     * Nettoie récursivement un dossier de travail temporaire.
     *
     * @param string $dir Chemin absolu du dossier à nettoyer.
     * @return bool
     */
    public static function cleanDirectory($dir) {
        $cleanDir = rtrim($dir, '/\\');
        if (!is_dir($cleanDir)) {
            return false;
        }

        $items = scandir($cleanDir);
        if ($items === false) {
            return false;
        }

        $files = array_diff($items, ['.', '..']);
        foreach ($files as $file) {
            $path = $cleanDir . DIRECTORY_SEPARATOR . $file;
            if (is_link($path)) {
                @unlink($path);
            } elseif (is_dir($path)) {
                self::cleanDirectory($path);
            } else {
                @unlink($path);
            }
        }

        return @rmdir($cleanDir);
    }

    /**
     * Purge les dossiers temporaires orphelins ou expirés dans le dossier temp.
     *
     * @param int $maxAgeSeconds Âge maximal en secondes avant purge (défaut : 2 heures).
     * @return int Nombre de dossiers purgés.
     */
    public static function purgeOldTempDirectories($maxAgeSeconds = 7200) {
        $tempBase = rtrim(self::getTempDir(), '/\\');
        if (!is_dir($tempBase)) {
            return 0;
        }

        $items = scandir($tempBase);
        if ($items === false) {
            return 0;
        }

        $now = time();
        $purgedCount = 0;

        foreach ($items as $item) {
            if ($item === '.' || $item === '..' || $item === 'index.html' || $item === '.htaccess') {
                continue;
            }

            $itemPath = $tempBase . DIRECTORY_SEPARATOR . $item;

            // Fichiers et liens symboliques orphelins directs à la racine de temp/.
            if (is_link($itemPath)) {
                @unlink($itemPath);
                continue;
            }

            if (!is_dir($itemPath)) {
                $mtime = filemtime($itemPath);
                if ($mtime !== false && ($now - $mtime) > $maxAgeSeconds) {
                    @unlink($itemPath);
                }
                continue;
            }

            // Dossiers de travail (ex: job_xxxx).
            $mtime = filemtime($itemPath);
            $stateFile = $itemPath . DIRECTORY_SEPARATOR . 'state.json';
            if (file_exists($stateFile)) {
                $stateMtime = filemtime($stateFile);
                if ($stateMtime !== false && $stateMtime > $mtime) {
                    $mtime = $stateMtime;
                }
            }

            if ($mtime !== false && ($now - $mtime) > $maxAgeSeconds) {
                if (self::cleanDirectory($itemPath)) {
                    $purgedCount++;
                }
            }
        }

        // Purge également les liens de téléchargement éphémères expirés (plus d'1 heure).
        $purgedCount += self::purgeOldDownloadLinks(min($maxAgeSeconds, 3600));

        return $purgedCount;
    }

    /**
     * Génère une URL de téléchargement statique directe protégée par un token cryptographique éphémère.
     * Permet au serveur web (Apache/LiteSpeed/Nginx) de servir directement le fichier ZIP depuis le disque
     * sans passer par PHP, contournant ainsi toute coupure FastCGI/LiteSpeed à 512 Mo.
     *
     * @param string $filePath Chemin absolu du fichier ZIP.
     * @return string|null URL directe ou null en cas d'impossibilité.
     */
    public static function prepareDirectDownloadUrl($filePath) {
        if (!file_exists($filePath) || !is_readable($filePath)) {
            return null;
        }

        // Nettoyage des liens éphémères expirés (plus d'1 heure).
        self::purgeOldDownloadLinks(3600);

        $dlBaseDir = self::getDownloadTempDir();
        if (!is_dir($dlBaseDir)) {
            wp_mkdir_p($dlBaseDir);
        }

        // Protection anti-listage du dossier dl/.
        $indexFile = $dlBaseDir . 'index.html';
        if (!file_exists($indexFile)) {
            file_put_contents($indexFile, '<!DOCTYPE html><html><head><title>403 Forbidden</title></head><body><h1>Directory access is forbidden.</h1></body></html>');
        }

        // Autorisation du téléchargement statique direct par le serveur web dans dl/.
        $htaccessFile = $dlBaseDir . '.htaccess';
        if (!file_exists($htaccessFile)) {
            $rules = "# EG Backup & Restore - Téléchargement statique par token éphémère\n";
            $rules .= "<IfModule mod_authz_core.c>\n    Require all granted\n</IfModule>\n";
            $rules .= "<IfModule !mod_authz_core.c>\n    Order allow,deny\n    Allow from all\n</IfModule>\n";
            $rules .= "<IfModule mod_headers.c>\n    Header set Content-Disposition \"attachment\"\n</IfModule>\n";
            file_put_contents($htaccessFile, $rules);
        }

        try {
            $token = bin2hex(random_bytes(24));
        } catch (\Exception $e) {
            $token = wp_generate_password(48, false, false);
        }

        $tokenDir = $dlBaseDir . $token . '/';
        if (!wp_mkdir_p($tokenDir)) {
            return null;
        }

        file_put_contents($tokenDir . 'index.html', '<!DOCTYPE html><html><head><title>403 Forbidden</title></head><body><h1>Directory access is forbidden.</h1></body></html>');

        $filename = basename($filePath);
        $linkPath = $tokenDir . $filename;

        // Création d'un lien physique (hardlink : 0 octet supplémentaire, instantané, servi nativement par Apache/LiteSpeed).
        $linked = @link($filePath, $linkPath);
        if (!$linked && function_exists('symlink')) {
            $linked = @symlink($filePath, $linkPath);
        }

        if (!$linked || !file_exists($linkPath)) {
            self::cleanDirectory($tokenDir);
            return null;
        }

        if (function_exists('wp_schedule_single_event') && !wp_next_scheduled('eg_backup_cleanup_temp_event')) {
            wp_schedule_single_event(time() + 3600, 'eg_backup_cleanup_temp_event');
        }

        return self::getStorageUrl() . 'dl/' . $token . '/' . rawurlencode($filename);
    }

    /**
     * Purge les dossiers de téléchargement éphémères expirés dans dl/.
     *
     * @param int $maxAgeSeconds Âge maximal en secondes avant purge (défaut : 1 heure).
     * @return int Nombre de dossiers purgés.
     */
    public static function purgeOldDownloadLinks($maxAgeSeconds = 3600) {
        $dlBase = rtrim(self::getDownloadTempDir(), '/\\');
        if (!is_dir($dlBase)) {
            return 0;
        }

        $items = scandir($dlBase);
        if ($items === false) {
            return 0;
        }

        $now = time();
        $purgedCount = 0;

        foreach ($items as $item) {
            if ($item === '.' || $item === '..' || $item === 'index.html' || $item === '.htaccess') {
                continue;
            }

            $itemPath = $dlBase . DIRECTORY_SEPARATOR . $item;
            if (!is_dir($itemPath)) {
                continue;
            }

            $mtime = filemtime($itemPath);
            if ($maxAgeSeconds <= 0 || ($mtime !== false && ($now - $mtime) >= $maxAgeSeconds)) {
                if (self::cleanDirectory($itemPath)) {
                    $purgedCount++;
                }
            }
        }

        return $purgedCount;
    }

    /**
     * Diffuse une tranche précise (chunk) d'un fichier en HTTP 200 OK.
     * Permet le téléchargement segmenté côté navigateur (Chunked Fetch) afin de ne jamais dépasser
     * la limite de 512 Mo par réponse HTTP imposée par certains hébergeurs et proxys.
     *
     * @param string $filePath     Chemin absolu du fichier.
     * @param int    $chunkStart   Octet de départ (inclus, base 0).
     * @param int    $chunkEnd     Octet de fin (inclus, base 0).
     * @param bool   $cleanBuffers Nettoie et ferme ou non les tampons de sortie actifs.
     * @return bool
     */
    public static function streamFileChunk($filePath, $chunkStart, $chunkEnd, $cleanBuffers = true) {
        if (!file_exists($filePath) || !is_readable($filePath)) {
            return false;
        }

        if (session_status() === PHP_SESSION_ACTIVE) {
            session_write_close();
        }

        if (function_exists('ini_set')) {
            @ini_set('memory_limit', '-1');
            @ini_set('zlib.output_compression', 'Off');
        }
        if (function_exists('set_time_limit')) {
            @set_time_limit(0);
        }
        if (function_exists('apache_setenv')) {
            @apache_setenv('no-gzip', '1');
        }

        if ($cleanBuffers) {
            while (ob_get_level() > 0) {
                @ob_end_clean();
            }
        }

        $fileSize = (int) filesize($filePath);
        if ($fileSize <= 0 || $chunkStart < 0 || $chunkStart >= $fileSize) {
            return false;
        }

        $start = $chunkStart;
        $end = min($chunkEnd, $fileSize - 1);
        if ($end < $start) {
            return false;
        }

        $length = $end - $start + 1;

        if ($cleanBuffers) {
            http_response_code(200);
            header('X-LiteSpeed-Buffer: no');
            header('X-Accel-Buffering: no');
            header('Content-Type: application/octet-stream');
            header('Content-Transfer-Encoding: binary');
            header('Cache-Control: no-store, no-cache, must-revalidate');
            header('Pragma: no-cache');
            header('X-EG-Total-Size: ' . (string) $fileSize);
            header('Content-Length: ' . (string) $length);
        }

        $handle = fopen($filePath, 'rb');
        if ($handle === false) {
            return false;
        }

        if ($start > 0) {
            fseek($handle, $start);
        }

        $bufferSize = 1048576;
        $bytesRemaining = $length;

        while ($bytesRemaining > 0 && !feof($handle)) {
            $readSize = min($bufferSize, $bytesRemaining);
            $buffer = fread($handle, $readSize);
            if ($buffer === false || $buffer === '') {
                break;
            }

            echo $buffer;

            if ($cleanBuffers) {
                if (ob_get_level() > 0) {
                    ob_flush();
                }
                flush();
            }

            $bytesRemaining -= strlen($buffer);

            if (connection_status() !== CONNECTION_NORMAL) {
                break;
            }
        }

        fclose($handle);
        return true;
    }

    /**
     * Diffuse un fichier vers la sortie HTTP par morceaux sans saturer la mémoire RAM.
     *
     * @param string $filePath     Chemin absolu du fichier.
     * @param int    $chunkSize    Taille de chaque morceau en octets (par défaut : 1 Mo).
     * @param bool   $cleanBuffers Nettoie et ferme ou non les tampons de sortie actifs.
     * @return bool
     */
    public static function streamFileToOutput($filePath, $chunkSize = 1048576, $cleanBuffers = true) {
        if (!file_exists($filePath) || !is_readable($filePath)) {
            return false;
        }

        // 1. Libère les verrous de session pour éviter de bloquer les requêtes concurrentes.
        if (session_status() === PHP_SESSION_ACTIVE) {
            session_write_close();
        }

        // 2. Désactive les limites mémoire et temps pour les gros téléchargements.
        if (function_exists('ini_set')) {
            @ini_set('memory_limit', '-1');
            @ini_set('zlib.output_compression', 'Off');
        }
        if (function_exists('set_time_limit')) {
            @set_time_limit(0);
        }
        if (function_exists('apache_setenv')) {
            @apache_setenv('no-gzip', '1');
        }

        // 3. Ferme et vide tous les tampons de sortie actifs avant d'envoyer les en-têtes.
        if ($cleanBuffers) {
            while (ob_get_level() > 0) {
                @ob_end_clean();
            }
        }

        $fileSize = (int) filesize($filePath);
        $start = 0;
        $end = $fileSize - 1;

        // 4. Prise en charge des requêtes HTTP Range (reprise de téléchargement interrompu).
        $isRange = false;
        if (isset($_SERVER['HTTP_RANGE']) && preg_match('/bytes=\s*(\d+)-(\d*)/i', (string) $_SERVER['HTTP_RANGE'], $matches)) {
            $start = (int) $matches[1];
            if (!empty($matches[2])) {
                $end = min((int) $matches[2], $fileSize - 1);
            }
            if ($start <= $end && $start < $fileSize) {
                $isRange = true;
            } else {
                $start = 0;
                $end = $fileSize - 1;
            }
        }

        $length = $end - $start + 1;

        // 5. Envoi des en-têtes HTTP anti-buffering et de streaming binaire.
        if ($cleanBuffers) {
            if ($isRange) {
                http_response_code(206);
                header("Content-Range: bytes {$start}-{$end}/{$fileSize}");
            } else {
                http_response_code(200);
            }

            // Désactive le buffering intermédiaire sur LiteSpeed et Nginx.
            header('X-LiteSpeed-Buffer: no');
            header('X-Accel-Buffering: no');
            header('Content-Description: File Transfer');
            header('Content-Type: application/zip');
            header('Content-Disposition: attachment; filename="' . basename($filePath) . '"');
            header('Content-Transfer-Encoding: binary');
            header('Expires: 0');
            header('Cache-Control: must-revalidate, post-check=0, pre-check=0');
            header('Pragma: public');
            header('Accept-Ranges: bytes');
            header('Content-Length: ' . (string) $length);
        }

        $handle = fopen($filePath, 'rb');
        if ($handle === false) {
            return false;
        }

        if ($start > 0) {
            fseek($handle, $start);
        }

        $bytesRemaining = $length;
        while ($bytesRemaining > 0 && !feof($handle)) {
            $readSize = min($chunkSize, $bytesRemaining);
            $buffer = fread($handle, $readSize);
            if ($buffer === false) {
                break;
            }

            echo $buffer;

            if ($cleanBuffers) {
                if (ob_get_level() > 0) {
                    ob_flush();
                }
                flush();
            }

            $bytesRemaining -= strlen($buffer);

            // Interrompt le streaming si le client a fermé sa connexion.
            if (connection_status() !== CONNECTION_NORMAL) {
                break;
            }
        }

        fclose($handle);
        return true;
    }
}
