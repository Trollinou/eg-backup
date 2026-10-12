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
            $wpdb->query($cleanQuery);
            $queriesCount++;
            $currentQuery = '';
        }

        $newOffset = ftell($handle);
        $isEof = feof($handle);
        fclose($handle);

        if ($isEof) {
            $wpdb->query("SET FOREIGN_KEY_CHECKS = 1;");
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

            // Parcourir les lignes par blocs de 1000
            $offset = 0;
            $limit = 1000;

            while (true) {
                $colsSelect = array_map(function($c) { return "`$c`"; }, array_merge([$primaryKey], $textColumns));
                $query = "SELECT " . implode(', ', $colsSelect) . " FROM `{$safeTable}` LIMIT {$limit} OFFSET {$offset}";
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
     *
     * @param mixed $data
     * @param string $search
     * @param string $replace
     * @return mixed
     */
    public static function recursiveReplace($data, $search, $replace) {
        if (is_string($data)) {
            // Vérifier si la chaîne est sérialisée
            if (self::isSerialized($data)) {
                $unserialized = @unserialize($data);
                if ($unserialized !== false || $data === 'b:0;') {
                    $replaced = self::recursiveReplace($unserialized, $search, $replace);
                    return serialize($replaced);
                }
            }
            return str_replace($search, $replace, $data);
        }

        if (is_array($data)) {
            $result = [];
            foreach ($data as $key => $val) {
                $newKey = is_string($key) ? str_replace($search, $replace, $key) : $key;
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
}
