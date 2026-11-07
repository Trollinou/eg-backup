<?php
namespace EGBackup\Core;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Moteur d'importation SQL par lots et outil de Search & Replace compatible données sérialisées.
 */
class DatabaseImporter {
    /**
     * Exécute un lot de requêtes SQL à partir d'un fichier avec pointeur d'octet (offset).
     *
     * @param string $filePath Chemin du fichier SQL.
     * @param int $byteOffset Position de départ dans le fichier.
     * @param int $maxBytesToRead Nombre d'octets maximum à lire par passe (ex: 2 Mo).
     * @return array ['new_offset' => int, 'eof' => bool, 'queries_executed' => int]
     */
    public static function importSqlChunk($filePath, $byteOffset = 0, $maxBytesToRead = 2097152) {
        global $wpdb;

        if (!file_exists($filePath) || !is_readable($filePath)) {
            return ['error' => 'Fichier SQL introuvable ou illisible.'];
        }

        $handle = fopen($filePath, 'r');
        if (!$handle) {
            return ['error' => 'Impossible d\'ouvrir le fichier SQL.'];
        }

        fseek($handle, $byteOffset);

        $currentQuery = '';
        $queriesCount = 0;
        $bytesRead = 0;

        // Désactivation des clés étrangères pour l'import.
        $wpdb->query("SET FOREIGN_KEY_CHECKS = 0;");

        $inString = false;
        $stringChar = '';
        $escaped = false;

        while (!feof($handle)) {
            $line = fgets($handle);
            if ($line === false) {
                break;
            }
            $bytesRead += strlen($line);

            $trimmed = trim($line);
            // Ignorer les commentaires et lignes vides hors d'une chaîne SQL.
            if (!$inString && (empty($trimmed) || strpos($trimmed, '--') === 0 || strpos($trimmed, '/*') === 0)) {
                continue;
            }

            $lineLength = strlen($line);
            for ($i = 0; $i < $lineLength; $i++) {
                $c = $line[$i];
                $currentQuery .= $c;

                if ($inString) {
                    if ($escaped) {
                        $escaped = false;
                    } elseif ($c === '\\') {
                        $escaped = true;
                    } elseif ($c === $stringChar) {
                        // Échappement SQL via double quote ''
                        if ($stringChar === "'" && $i + 1 < $lineLength && $line[$i + 1] === "'") {
                            $currentQuery .= $line[++$i];
                        } else {
                            $inString = false;
                        }
                    }
                } else {
                    if ($c === "'" || $c === '"') {
                        $inString = true;
                        $stringChar = $c;
                    } elseif ($c === ';') {
                        // Fin réelle d'une instruction SQL hors d'une chaîne.
                        $cleanQuery = trim($currentQuery);
                        if (!empty($cleanQuery)) {
                            $cleanQuery = self::cleanPlaceholderEscape($cleanQuery);
                            $wpdb->query($cleanQuery);
                            $queriesCount++;
                        }
                        $currentQuery = '';
                    }
                }
            }

            // Arrêt du lot dès qu'une requête complète est terminée et le quota d'octets atteint.
            if (trim($currentQuery) === '') {
                $currentQuery = '';
                if ($bytesRead >= $maxBytesToRead) {
                    break;
                }
            }
        }

        // Exécution de toute requête résiduelle avant la fin de fichier.
        $cleanQuery = trim($currentQuery);
        if (!empty($cleanQuery)) {
            $cleanQuery = self::cleanPlaceholderEscape($cleanQuery);
            $wpdb->query($cleanQuery);
            $queriesCount++;
            $currentQuery = '';
        }

        $newOffset = ftell($handle);
        $isEof = feof($handle);
        fclose($handle);

        if ($isEof) {
            $wpdb->query("SET FOREIGN_KEY_CHECKS = 1;");
            // Vérification de santé automatique et réinitialisation des permaliens si nécessaire.
            self::checkPermalinksHealth();
        }

        return [
            'new_offset'       => $newOffset,
            'eof'              => $isEof,
            'queries_executed' => $queriesCount,
        ];
    }

    /**
     * Recherche et remplace une chaîne (URL, chemin) dans toute la base de données
     * en préservant l'intégrité des structures sérialisées PHP.
     *
     * @param string $search Chaîne à remplacer (ex: ancienne URL).
     * @param string $replace Nouvelle chaîne (ex: nouvelle URL).
     * @param array $tables Liste des tables à traiter. Si vide, toutes les tables.
     * @return array Statistiques de remplacement.
     */
    public static function searchAndReplace($search, $replace, array $tables = []) {
        global $wpdb;

        if (empty($search) || $search === $replace) {
            return ['total_updates' => 0];
        }

        if (empty($tables)) {
            $tables = DatabaseExporter::getTables();
        }

        $totalUpdates = 0;

        foreach ($tables as $table) {
            $safeTable = preg_replace('/[^a-zA-Z0-9_$]/', '', $table);

            // Récupérer la clé primaire de la table
            $primaryKey = self::getPrimaryKey($safeTable);
            if (!$primaryKey) {
                continue;
            }

            // Récupérer les colonnes de type texte / varchar / blob
            $textColumns = self::getTextColumns($safeTable);
            if (empty($textColumns)) {
                continue;
            }

            // Parcourir les lignes par blocs de 1000.
            $offset = 0;
            $limit = 1000;

            while (true) {
                $colsSelect = array_map(function($c) { return "`$c`"; }, array_merge([$primaryKey], $textColumns));
                $query = $wpdb->prepare(
                    "SELECT " . implode(', ', $colsSelect) . " FROM `{$safeTable}` LIMIT %d OFFSET %d",
                    $limit,
                    $offset
                );
                $rows = $wpdb->get_results($query, ARRAY_A);

                if (empty($rows)) {
                    break;
                }

                foreach ($rows as $row) {
                    $pkValue = $row[$primaryKey];
                    $updates = [];

                    foreach ($textColumns as $col) {
                        $originalVal = $row[$col];
                        if (is_string($originalVal) && strpos($originalVal, $search) !== false) {
                            $newVal = self::recursiveReplace($originalVal, $search, $replace);
                            if ($newVal !== $originalVal) {
                                $updates[$col] = $newVal;
                            }
                        }
                    }

                    if (!empty($updates)) {
                        $wpdb->update($safeTable, $updates, [$primaryKey => $pkValue]);
                        $totalUpdates += count($updates);
                    }
                }

                $offset += $limit;
            }
        }

        return ['total_updates' => $totalUpdates];
    }

    /**
     * Remplacement récursif gérant les données sérialisées, tableaux et objets.
     * Intègre le nettoyage sécurisé des tokens d'échappement WordPress ({hash}) en symbole '%'
     * sans corrompre les longueurs d'octets déclarées (s:XX:).
     *
     * @param mixed $data Donnée à traiter (chaîne sérialisée, tableau, objet, etc.).
     * @param string $search Chaîne à rechercher (optionnel si uniquement nettoyage de tokens).
     * @param string $replace Chaîne de remplacement.
     * @return mixed Donnée assainie et mise à jour.
     */
    public static function recursiveReplace($data, $search = '', $replace = '') {
        if (is_string($data)) {
            // Vérifier si la chaîne est sérialisée.
            if (self::isSerialized($data)) {
                // Désérialisation sécurisée interdisant l'instanciation de classes (protection contre POP chains / PHP Object Injection).
                $unserialized = @unserialize($data, ['allowed_classes' => false]);
                // Si la désérialisation échoue à cause de longueurs corrompues, tenter une réparation.
                if ($unserialized === false && $data !== 'b:0;') {
                    $repaired = self::repairSerializedString($data);
                    if ($repaired !== $data) {
                        $unserialized = @unserialize($repaired, ['allowed_classes' => false]);
                    }
                }

                if ($unserialized !== false || $data === 'b:0;') {
                    $replaced = self::recursiveReplace($unserialized, $search, $replace);
                    return serialize($replaced);
                }
            }

            $result = $data;
            if ($search !== '' && $search !== null) {
                $result = str_replace($search, $replace, $result);
            }

            // Nettoyage des tokens d'échappement WordPress ({hash}) résiduels dans la chaîne.
            if (strpos($result, '{') !== false && preg_match('/\{[a-f0-9]{64}\}|\{[a-f0-9]{32}\}/i', $result)) {
                $result = (string) preg_replace('/\{[a-f0-9]{64}\}|\{[a-f0-9]{32}\}/i', '%', $result);
            }

            return $result;
        }

        if (is_array($data)) {
            $result = [];
            foreach ($data as $key => $val) {
                $newKey = is_string($key) ? self::recursiveReplace($key, $search, $replace) : $key;
                $result[$newKey] = self::recursiveReplace($val, $search, $replace);
            }
            return $result;
        }

        if (is_object($data)) {
            $result = clone $data;
            foreach ($data as $key => $val) {
                $result->$key = self::recursiveReplace($val, $search, $replace);
            }
            return $result;
        }

        return $data;
    }

    /**
     * Répare la longueur déclarée des chaînes (s:XX:) au sein d'une structure sérialisée corrompue.
     *
     * @param string $data Chaîne sérialisée potentiellement corrompue.
     * @return string Chaîne avec les longueurs d'octets synchronisées.
     */
    public static function repairSerializedString($data) {
        if (!is_string($data) || strpos($data, 's:') === false) {
            return $data;
        }

        return (string) preg_replace_callback(
            '/s:(\d+):"(.*?)";/s',
            function ($matches) {
                return 's:' . strlen($matches[2]) . ':"' . $matches[2] . '";';
            },
            $data
        );
    }

    /**
     * Vérifie si une chaîne est une donnée PHP sérialisée.
     */
    private static function isSerialized($data) {
        if (!is_string($data)) {
            return false;
        }
        $data = trim($data);
        if ('N;' === $data) {
            return true;
        }
        if (strlen($data) < 4) {
            return false;
        }
        if (':' !== $data[1]) {
            return false;
        }
        $last = substr($data, -1);
        if (';' !== $last && '}' !== $last) {
            return false;
        }
        $token = $data[0];
        switch ($token) {
            case 's':
                if ('"' !== substr($data, -2, 1)) {
                    return false;
                }
            case 'a':
            case 'O':
                return (bool) preg_match("/^{$token}:[0-9]+:/s", $data);
            case 'b':
            case 'i':
            case 'd':
                return (bool) preg_match("/^{$token}:[0-9.E+-]+;$/", $data);
        }
        return false;
    }

    /**
     * Récupère la clé primaire d'une table.
     */
    private static function getPrimaryKey($table) {
        global $wpdb;
        $row = $wpdb->get_row("SHOW KEYS FROM `{$table}` WHERE Key_name = 'PRIMARY'", ARRAY_A);
        return $row['Column_name'] ?? null;
    }

    /**
     * Récupère les colonnes textuelles d'une table pouvant contenir des URLs/chemins.
     */
    private static function getTextColumns($table) {
        global $wpdb;
        $columns = $wpdb->get_results("SHOW COLUMNS FROM `{$table}`", ARRAY_A);
        $textCols = [];

        foreach ($columns as $col) {
            $type = strtolower($col['Type'] ?? '');
            if (strpos($type, 'varchar') !== false ||
                strpos($type, 'text') !== false ||
                strpos($type, 'longtext') !== false ||
                strpos($type, 'mediumtext') !== false) {
                $textCols[] = $col['Field'];
            }
        }

        return $textCols;
    }

    /**
     * Nettoie les éventuels tokens d'échappement WordPress ({hash}) résiduels d'un export défaillant
     * et les rétablit en symbole '%', tout en préservant l'intégrité des structures sérialisées.
     *
     * @param string $query Requête SQL ou chaîne à assainir.
     * @return string Requête nettoyée.
     */
    public static function cleanPlaceholderEscape($query) {
        if (!is_string($query) || strpos($query, '{') === false || !preg_match('/\{[a-f0-9]{64}\}|\{[a-f0-9]{32}\}/i', $query)) {
            return $query;
        }

        // Si la chaîne entière est une donnée PHP sérialisée, utiliser le remplacement récursif sécurisé.
        if (self::isSerialized($query)) {
            return (string) self::recursiveReplace($query, '', '');
        }

        // Remplacement standard pour les requêtes SQL.
        $cleaned = (string) preg_replace('/\{[a-f0-9]{64}\}|\{[a-f0-9]{32}\}/i', '%', $query);

        // Si la requête contient des données sérialisées (ex: INSERT wp_options), réparer les longueurs s:XX:.
        if (strpos($cleaned, 's:') !== false && (strpos($cleaned, 'a:') !== false || strpos($cleaned, 'O:') !== false)) {
            $cleaned = self::repairSerializedString($cleaned);
        }

        return $cleaned;
    }

    /**
     * Vérification automatique de santé des permaliens en fin de restauration de la base de données.
     * Assainit le permalink_structure corrompu par un jeton d'échappement WordPress ({hash})
     * et régénère les règles de réécriture (flush_rewrite_rules).
     *
     * @return bool True si la vérification a été exécutée avec succès.
     */
    public static function checkPermalinksHealth() {
        global $wpdb;

        // Vider le cache des options WordPress pour s'assurer d'une lecture fraîche depuis la base.
        if (function_exists('wp_cache_delete')) {
            wp_cache_delete('permalink_structure', 'options');
            wp_cache_delete('alloptions', 'options');
        }

        // Nettoyage préventif des options contenant des tokens de placeholder résiduels dans wp_options.
        if (!empty($wpdb) && !empty($wpdb->options)) {
            $dirtyOptions = $wpdb->get_results(
                "SELECT option_name, option_value FROM {$wpdb->options} WHERE option_value LIKE '%{%'",
                ARRAY_A
            );
            if (!empty($dirtyOptions) && is_array($dirtyOptions)) {
                foreach ($dirtyOptions as $opt) {
                    $optVal = $opt['option_value'] ?? '';
                    if (strpos($optVal, '{') !== false && preg_match('/\{[a-f0-9]{64}\}|\{[a-f0-9]{32}\}/i', $optVal)) {
                        $cleanedVal = self::recursiveReplace($optVal, '', '');
                        if ($cleanedVal !== $optVal) {
                            $wpdb->update(
                                $wpdb->options,
                                ['option_value' => $cleanedVal],
                                ['option_name' => $opt['option_name']]
                            );
                            if (function_exists('wp_cache_delete')) {
                                wp_cache_delete($opt['option_name'], 'options');
                            }
                        }
                    }
                }
            }
        }

        $permalink = function_exists('get_option')
            ? (string) get_option('permalink_structure', '')
            : '';

        if (empty($permalink) && !empty($wpdb) && !empty($wpdb->options)) {
            $permalink = (string) $wpdb->get_var("SELECT option_value FROM {$wpdb->options} WHERE option_name = 'permalink_structure'");
        }

        // Si le permalien contient une accolade '{' ou un hash d'échappement WordPress, le réinitialiser.
        if (strpos($permalink, '{') !== false || preg_match('/\{[a-f0-9]{64}\}|\{[a-f0-9]{32}\}/i', $permalink)) {
            if (function_exists('update_option')) {
                update_option('permalink_structure', '/%postname%/');
            } elseif (!empty($wpdb) && !empty($wpdb->options)) {
                $wpdb->update(
                    $wpdb->options,
                    ['option_value' => '/%postname%/'],
                    ['option_name' => 'permalink_structure']
                );
            }
        }

        // Recalculer systématiquement les règles de réécriture sans intervention manuelle de l'utilisateur.
        if (function_exists('flush_rewrite_rules')) {
            flush_rewrite_rules(true);
        }

        return true;
    }
}
