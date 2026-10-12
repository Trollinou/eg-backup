<?php
namespace EGBackup\Core;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Gestionnaire du manifeste de sauvegarde (manifest.json).
 * Responsable de la documentation des métadonnées du site, des extensions et thèmes.
 */
class ManifestManager {
    /**
     * Génère la structure complète du manifeste pour le site actuel.
     *
     * @param array $extraData Données supplémentaires (ex: liste des dossiers inclus, tables BDD).
     * @return array
     */
    public static function generateManifest(array $extraData = []) {
        global $wpdb;

        if (!function_exists('get_plugins')) {
            require_once ABSPATH . 'wp-admin/includes/plugin.php';
        }

        // Récupération des extensions installées.
        $allPlugins = get_plugins();
        $activePlugins = (array) get_option('active_plugins', []);
        if (is_multisite()) {
            $networkActive = (array) get_site_option('active_sitewide_plugins', []);
            $activePlugins = array_unique(array_merge($activePlugins, array_keys($networkActive)));
        }

        $pluginsManifest = [];
        foreach ($allPlugins as $pluginFile => $pluginData) {
            $slug = dirname($pluginFile);
            if ($slug === '.' || empty($slug)) {
                $slug = basename($pluginFile, '.php');
            }

            $isActive = in_array($pluginFile, $activePlugins, true);
            $isWpOrg = self::isPluginFromWpOrg($pluginFile, $pluginData);

            $pluginsManifest[] = [
                'file'        => $pluginFile,
                'slug'        => $slug,
                'name'        => $pluginData['Name'] ?? $slug,
                'version'     => $pluginData['Version'] ?? '',
                'plugin_uri'  => $pluginData['PluginURI'] ?? '',
                'author'      => $pluginData['Author'] ?? '',
                'author_uri'  => $pluginData['AuthorURI'] ?? '',
                'is_active'   => $isActive,
                'is_wporg'    => $isWpOrg,
                'wporg_url'   => $isWpOrg ? 'https://wordpress.org/plugins/' . $slug . '/' : '',
            ];
        }

        // Récupération des informations sur le thème actif.
        $theme = wp_get_theme();
        $parentTheme = $theme->parent();

        $themeManifest = [
            'name'        => $theme->get('Name'),
            'stylesheet'  => $theme->get_stylesheet(), // slug du thème enfant ou actif
            'template'    => $theme->get_template(),   // slug du thème parent si thème enfant
            'version'     => $theme->get('Version'),
            'theme_uri'   => $theme->get('ThemeURI'),
            'author'      => $theme->get('Author'),
            'is_child'    => (bool) $parentTheme,
            'parent'      => $parentTheme ? [
                'name'       => $parentTheme->get('Name'),
                'template'   => $parentTheme->get_template(),
                'version'    => $parentTheme->get('Version'),
                'theme_uri'  => $parentTheme->get('ThemeURI'),
                'author'     => $parentTheme->get('Author'),
            ] : null,
        ];

        // Informations sur l'environnement WordPress.
        $wpInfo = [
            'version'         => get_bloginfo('version'),
            'siteurl'         => get_option('siteurl'),
            'home'            => get_option('home'),
            'db_prefix'       => $wpdb->prefix,
            'db_version'      => $wpdb->db_version(),
            'charset'         => $wpdb->charset,
            'collate'         => $wpdb->collate,
            'php_version'     => phpversion(),
            'content_dir'     => WP_CONTENT_DIR,
            'uploads_basedir' => wp_upload_dir()['basedir'] ?? '',
        ];

        $manifest = [
            'generator'       => 'EG Backup & Restore',
            'plugin_version'  => defined('EG_BACKUP_VERSION') ? EG_BACKUP_VERSION : '1.0.0',
            'created_at'      => current_time('mysql'),
            'timestamp'       => time(),
            'site'            => [
                'name'        => get_bloginfo('name'),
                'description' => get_bloginfo('description'),
            ],
            'environment'     => $wpInfo,
            'plugins'         => $pluginsManifest,
            'theme'           => $themeManifest,
            'files_included'  => $extraData['files_included'] ?? [],
            'tables_included' => $extraData['tables_included'] ?? [],
            'database_file'   => 'database.sql',
        ];

        return $manifest;
    }

    /**
     * Sauvegarde le manifest dans un fichier JSON.
     *
     * @param string $filePath Chemin absolu cible du fichier manifest.json.
     * @param array $manifestData Données du manifeste.
     * @return bool
     */
    public static function saveToFile($filePath, array $manifestData) {
        $json = wp_json_encode($manifestData, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        return (bool) file_put_contents($filePath, $json);
    }

    /**
     * Lit et décode un fichier manifest.json.
     *
     * @param string $filePath Chemin du fichier manifest.json.
     * @return array|null
     */
    public static function readFromFile($filePath) {
        if (!file_exists($filePath) || !is_readable($filePath)) {
            return null;
        }

        $content = file_get_contents($filePath);
        if ($content === false) {
            return null;
        }

        $decoded = json_decode($content, true);
        return is_array($decoded) ? $decoded : null;
    }

    /**
     * Compare le manifeste d'une sauvegarde avec l'état actuel de l'installation WordPress.
     * Fournit un bilan clair pour guider la réinstallation des thèmes et extensions.
     *
     * @param array $manifest Données du manifeste issu de la sauvegarde.
     * @return array Bilan de compatibilité et dépendances.
     */
    public static function compareWithCurrentEnvironment(array $manifest) {
        if (!function_exists('get_plugins')) {
            require_once ABSPATH . 'wp-admin/includes/plugin.php';
        }

        global $wpdb;

        $currentPlugins = get_plugins();
        $currentActive = (array) get_option('active_plugins', []);

        $manifestPlugins = $manifest['plugins'] ?? [];
        $pluginsReport = [];
        $missingCount = 0;
        $activeMissingCount = 0;

        foreach ($manifestPlugins as $requiredPlugin) {
            $file = $requiredPlugin['file'] ?? '';
            $slug = $requiredPlugin['slug'] ?? '';
            $isInstalled = isset($currentPlugins[$file]);
            $isCurrentlyActive = in_array($file, $currentActive, true);

            $status = 'missing';
            $versionMatch = true;

            if ($isInstalled) {
                $installedVersion = $currentPlugins[$file]['Version'] ?? '';
                $requiredVersion = $requiredPlugin['version'] ?? '';
                $versionMatch = empty($requiredVersion) || empty($installedVersion) || version_compare($installedVersion, $requiredVersion, '==');
                $status = $versionMatch ? 'installed' : 'version_diff';
            } else {
                $missingCount++;
                if (!empty($requiredPlugin['is_active'])) {
                    $activeMissingCount++;
                }
            }

            $pluginsReport[] = [
                'file'              => $file,
                'slug'              => $slug,
                'name'              => $requiredPlugin['name'] ?? $slug,
                'required_version'  => $requiredPlugin['version'] ?? '',
                'installed_version' => $isInstalled ? ($currentPlugins[$file]['Version'] ?? '') : null,
                'status'            => $status, // 'installed', 'version_diff', 'missing'
                'is_active_in_backup' => !empty($requiredPlugin['is_active']),
                'is_currently_active' => $isCurrentlyActive,
                'is_wporg'          => !empty($requiredPlugin['is_wporg']),
                'wporg_url'         => $requiredPlugin['wporg_url'] ?? '',
                'plugin_uri'        => $requiredPlugin['plugin_uri'] ?? '',
            ];
        }

        // Vérification du thème
        $requiredTheme = $manifest['theme'] ?? [];
        $currentTheme = wp_get_theme();
        $themeInstalled = wp_get_theme($requiredTheme['stylesheet'] ?? '')->exists();
        $themeReport = [
            'name'              => $requiredTheme['name'] ?? '',
            'stylesheet'        => $requiredTheme['stylesheet'] ?? '',
            'template'          => $requiredTheme['template'] ?? '',
            'required_version'  => $requiredTheme['version'] ?? '',
            'is_installed'      => $themeInstalled,
            'is_currently_active'=> ($currentTheme->get_stylesheet() === ($requiredTheme['stylesheet'] ?? '')),
            'parent'            => $requiredTheme['parent'] ?? null,
        ];

        // Vérification environnement
        $env = $manifest['environment'] ?? [];
        $envReport = [
            'backup_url'       => $env['siteurl'] ?? '',
            'current_url'      => get_option('siteurl'),
            'url_mismatch'     => ($env['siteurl'] ?? '') !== get_option('siteurl'),
            'backup_prefix'    => $env['db_prefix'] ?? '',
            'current_prefix'   => $wpdb->prefix,
            'prefix_mismatch'  => ($env['db_prefix'] ?? '') !== $wpdb->prefix,
            'backup_wp_version'=> $env['version'] ?? '',
            'current_wp_version'=> get_bloginfo('version'),
        ];

        return [
            'plugins'              => $pluginsReport,
            'theme'                => $themeReport,
            'environment'          => $envReport,
            'missing_plugins_count'=> $missingCount,
            'active_missing_count' => $activeMissingCount,
            'can_proceed'          => true, // On autorisera l'utilisateur à poursuivre ou l'avertira
        ];
    }

    /**
     * Détermine si une extension provient vraisemblablement du dépôt officiel WordPress.org.
     *
     * @param string $pluginFile Fichier principal du plugin (ex: contact-form-7/wp-contact-form-7.php).
     * @param array $pluginData Métadonnées du plugin.
     * @return bool
     */
    private static function isPluginFromWpOrg($pluginFile, array $pluginData) {
        $uri = strtolower($pluginData['PluginURI'] ?? '');
        $authorUri = strtolower($pluginData['AuthorURI'] ?? '');

        if (strpos($uri, 'wordpress.org/plugins') !== false) {
            return true;
        }

        // Si le slug est un sous-dossier standard
        $slug = dirname($pluginFile);
        if ($slug !== '.' && !empty($slug)) {
            // Dans WordPress, les plugins officiels ont un slug normalisé (a-z0-9-)
            if (preg_match('/^[a-z0-9\-]+$/', $slug)) {
                return true;
            }
        }

        return false;
    }
}
