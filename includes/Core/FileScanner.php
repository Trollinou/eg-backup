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
     * Noms de dossiers d'optimiseurs d'images ou caches secondaires.
     */
    private static $optimizerDirectories = [
        'shortpixel-backups',
        'ewww',
        'imagify-backup',
        'smush-backups',
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
     * Retourne une analyse catégorisée des données pour une sélection fine.
     *
     * @return array
     */
    public static function scanCategorizedData() {
        $contentDir = wp_normalize_path(WP_CONTENT_DIR);
        $uploadDir = wp_upload_dir();
        $uploadsBase = wp_normalize_path($uploadDir['basedir']);

        $categories = [
            'core_themes'          => [],
            'wp_media'             => [],
            'galleries'            => [],
            'registered_locations' => self::getRegisteredLocations(),
            'optimizers'           => [],
            'other'                => [],
        ];

        // 1. Thèmes
        $themesDir = $contentDir . '/themes';
        if (is_dir($themesDir)) {
            $activeTheme = wp_get_theme();
            $activeSlug = strtolower($activeTheme->get_stylesheet());
            $parentSlug = strtolower($activeTheme->get_template());

            $themeFolders = scandir($themesDir);
            if ($themeFolders !== false) {
                foreach ($themeFolders as $t) {
                    if ($t === '.' || $t === '..') continue;
                    $themePath = $themesDir . '/' . $t;
                    if (is_dir($themePath)) {
                        $lowerT = strtolower($t);
                        $isActive = ($lowerT === $activeSlug || $lowerT === $parentSlug);
                        $isChild = (strpos($lowerT, '-child') !== false);
                        $stats = self::getDirectoryStats($themePath);

                        $categories['core_themes'][] = [
                            'name'          => $t,
                            'relative_path' => 'themes/' . $t,
                            'full_path'     => $themePath,
                            'is_recommended'=> ($isActive || $isChild),
                            'is_active'     => $isActive,
                            'is_child'      => $isChild,
                            'size'          => $stats['size'],
                            'size_human'    => self::formatSize($stats['size']),
                            'files_count'   => $stats['files_count'],
                        ];
                    }
                }
            }
        }

        // 2. Uploads WordPress (par années ou dossiers racines)
        if (is_dir($uploadsBase)) {
            $uploadItems = scandir($uploadsBase);
            if ($uploadItems !== false) {
                foreach ($uploadItems as $u) {
                    if ($u === '.' || $u === '..') continue;
                    if (in_array(strtolower($u), self::$excludedDirectories, true)) continue;

                    $fullPath = $uploadsBase . '/' . $u;
                    if (!is_dir($fullPath)) continue;

                    $relPath = ltrim(str_replace($contentDir, '', $fullPath), '/');
                    $lowerU = strtolower($u);

                    // Optimiseurs
                    if (in_array($lowerU, self::$optimizerDirectories, true)) {
                        $stats = self::getDirectoryStats($fullPath);
                        $categories['optimizers'][] = [
                            'name'          => $u,
                            'relative_path' => $relPath,
                            'full_path'     => $fullPath,
                            'is_recommended'=> false,
                            'size'          => $stats['size'],
                            'size_human'    => self::formatSize($stats['size']),
                            'files_count'   => $stats['files_count'],
                        ];
                        continue;
                    }

                    // Galeries dans uploads (ex: envira, foogallery, modula)
                    if (strpos($lowerU, 'gallery') !== false || strpos($lowerU, 'envira') !== false || strpos($lowerU, 'modula') !== false) {
                        $stats = self::getDirectoryStats($fullPath);
                        $categories['galleries'][] = [
                            'name'          => $u,
                            'relative_path' => $relPath,
                            'full_path'     => $fullPath,
                            'is_recommended'=> true,
                            'size'          => $stats['size'],
                            'size_human'    => self::formatSize($stats['size']),
                            'files_count'   => $stats['files_count'],
                            'size_no_thumbs'=> $stats['size_no_thumbs'],
                            'size_no_thumbs_human' => self::formatSize($stats['size_no_thumbs']),
                            'files_no_thumbs'      => $stats['files_no_thumbs'],
                        ];
                        continue;
                    }

                    // Médiathèque standard WP (années ou autres sous-dossiers uploads)
                    $stats = self::getDirectoryStats($fullPath);
                    $categories['wp_media'][] = [
                        'name'          => $u,
                        'relative_path' => $relPath,
                        'full_path'     => $fullPath,
                        'is_year'       => (bool) preg_match('/^[12][0-9]{3}$/', $u),
                        'is_recommended'=> false, // Décoché par défaut pour la séparation Cœur/Médias
                        'size'          => $stats['size'],
                        'size_human'    => self::formatSize($stats['size']),
                        'files_count'   => $stats['files_count'],
                        'size_no_thumbs'=> $stats['size_no_thumbs'],
                        'size_no_thumbs_human' => self::formatSize($stats['size_no_thumbs']),
                        'files_no_thumbs'      => $stats['files_no_thumbs'],
                    ];
                }
            }
        }

        // 3. NextGEN Gallery à la racine de wp-content (wp-content/gallery/)
        $nggDir = $contentDir . '/gallery';
        if (is_dir($nggDir)) {
            $stats = self::getDirectoryStats($nggDir);
            $categories['galleries'][] = [
                'name'                 => 'NextGEN Gallery (gallery/)',
                'relative_path'        => 'gallery',
                'full_path'            => $nggDir,
                'is_recommended'       => false,
                'is_nextgen'           => true,
                'size'                 => $stats['size'],
                'size_human'           => self::formatSize($stats['size']),
                'files_count'          => $stats['files_count'],
                'size_no_thumbs'       => $stats['size_no_thumbs'],
                'size_no_thumbs_human' => self::formatSize($stats['size_no_thumbs']),
                'files_no_thumbs'      => $stats['files_no_thumbs'],
            ];
        }

        return $categories;
    }

    /**
     * Récupère les emplacements déclarés par des extensions tierces via le hook eg_backup_registered_locations.
     *
     * @return array
     */
    public static function getRegisteredLocations() {
        $raw = apply_filters('eg_backup_registered_locations', []);
        $sanitized = [];
        $contentDir = wp_normalize_path(WP_CONTENT_DIR);

        if (!is_array($raw)) {
            return $sanitized;
        }

        foreach ($raw as $key => $loc) {
            if (!is_array($loc) || empty($loc['path'])) {
                continue;
            }

            $id = sanitize_key($loc['id'] ?? (is_string($key) ? $key : 'loc_' . wp_generate_password(4, false)));
            $label = sanitize_text_field($loc['label'] ?? $id);
            $pluginName = sanitize_text_field($loc['plugin_name'] ?? 'Extension');
            $rawPath = wp_normalize_path($loc['path']);

            if (strpos($rawPath, $contentDir) === 0) {
                $fullPath = $rawPath;
                $relativePath = ltrim(substr($rawPath, strlen($contentDir)), '/');
            } else {
                $fullPath = wp_normalize_path(WP_CONTENT_DIR . '/' . ltrim($rawPath, '/'));
                $relativePath = ltrim($rawPath, '/');
            }

            $exclude = isset($loc['exclude']) && is_array($loc['exclude'])
                ? array_map('sanitize_text_field', $loc['exclude'])
                : [];

            $stats = is_dir($fullPath) ? self::getDirectoryStats($fullPath) : ['size' => 0, 'files_count' => 0, 'size_no_thumbs' => 0, 'files_no_thumbs' => 0];

            $sanitized[$id] = [
                'id'            => $id,
                'label'         => $label,
                'plugin_name'   => $pluginName,
                'full_path'     => $fullPath,
                'relative_path' => $relativePath,
                'category'      => sanitize_key($loc['category'] ?? 'documents'),
                'recommended'   => !empty($loc['recommended']),
                'description'   => sanitize_text_field($loc['description'] ?? ''),
                'exclude'       => $exclude,
                'exists'        => is_dir($fullPath),
                'size'          => $stats['size'],
                'size_human'    => self::formatSize($stats['size']),
                'files_count'   => $stats['files_count'],
            ];
        }

        return $sanitized;
    }

    /**
     * Détermine si un fichier est une miniature ou déclinaison générée.
     *
     * @param string $filename Nom du fichier (ex: photo-150x150.jpg)
     * @param string $relativePath Chemin relatif ou sous-dossier
     * @return bool
     */
    public static function isThumbnailFile($filename, $relativePath = '') {
        $name = strtolower($filename);
        $path = strtolower(wp_normalize_path($relativePath));

        // 1. Miniatures et versions réduites standard WordPress
        if (preg_match('/-\d+x\d+\.(jpe?g|png|webp|gif|avif)$/i', $name)) {
            return true;
        }
        if (preg_match('/-scaled\.(jpe?g|png|webp|gif|avif)$/i', $name)) {
            return true;
        }

        // 2. NextGEN Gallery : dossiers thumbs/ ou fichiers commençant par thumbs_
        if (strpos($path, '/thumbs/') !== false || strpos($path, 'thumbs/') === 0 || strpos($name, 'thumbs_') === 0) {
            return true;
        }
        if (strpos($path, '/dynamic/') !== false || strpos($path, 'dynamic/') === 0) {
            return true;
        }

        return false;
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
                    'name'                 => $item,
                    'relative_path'        => $relativePath,
                    'full_path'            => $fullPath,
                    'is_dir'               => true,
                    'size'                 => $dirStats['size'],
                    'size_human'           => self::formatSize($dirStats['size']),
                    'files_count'          => $dirStats['files_count'],
                    'size_no_thumbs'       => $dirStats['size_no_thumbs'],
                    'size_no_thumbs_human' => self::formatSize($dirStats['size_no_thumbs']),
                    'files_no_thumbs'      => $dirStats['files_no_thumbs'],
                    'is_recommended'       => self::isRecommendedFolder($relativePath),
                    'children'             => [],
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
     * Calcule la taille cumulée et le nombre de fichiers d'un dossier (avec et sans miniatures).
     *
     * @param string $dir Chemin absolu.
     * @return array ['size' => int, 'files_count' => int, 'size_no_thumbs' => int, 'files_no_thumbs' => int]
     */
    public static function getDirectoryStats($dir) {
        $size = 0;
        $filesCount = 0;
        $sizeNoThumbs = 0;
        $filesNoThumbs = 0;

        if (!is_dir($dir) || !is_readable($dir)) {
            return [
                'size'            => 0,
                'files_count'     => 0,
                'size_no_thumbs'  => 0,
                'files_no_thumbs' => 0,
            ];
        }

        try {
            $iterator = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS | \FilesystemIterator::FOLLOW_SYMLINKS),
                \RecursiveIteratorIterator::LEAVES_ONLY
            );

            foreach ($iterator as $file) {
                if ($file->isFile()) {
                    $fileSize = (int) $file->getSize();
                    $filename = $file->getFilename();
                    $realPath = $file->getRealPath();

                    $size += $fileSize;
                    $filesCount++;

                    if (!self::isThumbnailFile($filename, $realPath)) {
                        $sizeNoThumbs += $fileSize;
                        $filesNoThumbs++;
                    }
                }
            }
        } catch (\Exception $e) {
            // En cas d'erreur de permission sur certains fichiers
        }

        return [
            'size'            => $size,
            'files_count'     => $filesCount,
            'size_no_thumbs'  => $sizeNoThumbs,
            'files_no_thumbs' => $filesNoThumbs,
        ];
    }

    /**
     * Détermine si un dossier est un répertoire de données externe fréquemment utile.
     *
     * @param string $relativePath Chemin relatif à wp-content.
     * @return bool
     */
    private static function isRecommendedFolder($relativePath) {
        $lower = strtolower($relativePath);

        // Recommander les thèmes enfants et le thème actif.
        if (strpos($lower, 'themes/') === 0) {
            if (strpos($lower, '-child') !== false) {
                return true;
            }
            if (function_exists('wp_get_theme')) {
                $activeTheme = wp_get_theme();
                $activeSlug = strtolower($activeTheme->get_stylesheet());
                $parentSlug = strtolower($activeTheme->get_template());
                if ($lower === 'themes/' . $activeSlug || $lower === 'themes/' . $parentSlug) {
                    return true;
                }
            }
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
