<?php
namespace EGBackup\Core;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Explorateur et analyseur de fichiers pour wp-content.
 * Permet à l'utilisateur de visualiser et sélectionner les données hors BDD.
 */
class FileScanner {
    /**
     * Noms de dossiers toujours exclus de l'exploration.
     */
    private static $excludedDirectories = [
        'plugins',
        'themes',
        'upgrade',
        'cache',
        'wpcache',
        'et-cache',
        'wphb-cache',
        'wp-rocket-config',
        'eg-backup-data',
        'eg-backup-temp',
        '.git',
        '.svn',
        'node_modules',
    ];

    /**
     * Analyse l'arborescence de wp-content jusqu'à une profondeur donnée.
     *
     * @param int $maxDepth Profondeur maximale d'arborescence (par défaut 2 pour la réactivité).
     * @return array
     */
    public static function scanContentDirectory($maxDepth = 2) {
        $contentDir = wp_normalize_path(WP_CONTENT_DIR);
        $tree = self::scanRecursive($contentDir, $contentDir, 0, $maxDepth);
        return $tree;
    }

    /**
     * Scanne récursivement un répertoire en respectant les exclusions et profondeurs.
     *
     * @param string $currentPath Chemin absolu du dossier à explorer.
     * @param string $basePath Chemin de référence (WP_CONTENT_DIR).
     * @param int $currentDepth Profondeur actuelle.
     * @param int $maxDepth Profondeur limite.
     * @return array
     */
    private static function scanRecursive($currentPath, $basePath, $currentDepth, $maxDepth) {
        $result = [];

        if (!is_dir($currentPath) || !is_readable($currentPath)) {
            return $result;
        }

        $items = scandir($currentPath);
        if ($items === false) {
            return $result;
        }

        foreach ($items as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }

            // Vérification de la liste des répertoires exclus
            if (in_array(strtolower($item), self::$excludedDirectories, true)) {
                continue;
            }

            $fullPath = wp_normalize_path($currentPath . '/' . $item);
            $relativePath = ltrim(str_replace($basePath, '', $fullPath), '/');

            if (is_dir($fullPath)) {
                $dirStats = self::getDirectoryStats($fullPath);
                
                $node = [
                    'name'          => $item,
                    'relative_path' => $relativePath,
                    'full_path'     => $fullPath,
                    'is_dir'        => true,
                    'size'          => $dirStats['size'],
                    'size_human'    => self::formatSize($dirStats['size']),
                    'files_count'   => $dirStats['files_count'],
                    'is_recommended'=> self::isRecommendedFolder($relativePath),
                    'children'      => [],
                ];

                if ($currentDepth < $maxDepth) {
                    $node['children'] = self::scanRecursive($fullPath, $basePath, $currentDepth + 1, $maxDepth);
                }

                $result[] = $node;
            }
        }

        // Tri alphabétique avec dossiers recommandés en priorité
        usort($result, function($a, $b) {
            if ($a['is_recommended'] !== $b['is_recommended']) {
                return $a['is_recommended'] ? -1 : 1;
            }
            return strcasecmp($a['name'], $b['name']);
        });

        return $result;
    }

    /**
     * Calcule la taille cumulée et le nombre de fichiers d'un dossier.
     *
     * @param string $dir Chemin absolu.
     * @return array ['size' => int, 'files_count' => int]
     */
    public static function getDirectoryStats($dir) {
        $size = 0;
        $filesCount = 0;

        if (!is_dir($dir) || !is_readable($dir)) {
            return ['size' => 0, 'files_count' => 0];
        }

        try {
            $iterator = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS | \FilesystemIterator::FOLLOW_SYMLINKS),
                \RecursiveIteratorIterator::LEAVES_ONLY
            );

            foreach ($iterator as $file) {
                if ($file->isFile()) {
                    $size += $file->getSize();
                    $filesCount++;
                }
            }
        } catch (\Exception $e) {
            // En cas d'erreur de permission sur certains fichiers
        }

        return ['size' => $size, 'files_count' => $filesCount];
    }

    /**
     * Détermine si un dossier est un répertoire de données externe fréquemment utile
     * (ex: uploads, galleries photos NextGEN, photothèques, etc.).
     *
     * @param string $relativePath Chemin relatif à wp-content.
     * @return bool
     */
    private static function isRecommendedFolder($relativePath) {
        $lower = strtolower($relativePath);
        if ($lower === 'uploads' || strpos($lower, 'gallery') !== false || strpos($lower, 'galleries') !== false) {
            return true;
        }
        return false;
    }

    /**
     * Formate une taille en octets sous forme lisible.
     *
     * @param int $bytes
     * @return string
     */
    public static function formatSize($bytes) {
        if ($bytes <= 0) {
            return '0 o';
        }
        $units = ['o', 'Ko', 'Mo', 'Go', 'To'];
        $i = floor(log($bytes, 1024));
        $i = min($i, count($units) - 1);
        $size = round($bytes / pow(1024, $i), 2);
        return $size . ' ' . $units[$i];
    }
}
