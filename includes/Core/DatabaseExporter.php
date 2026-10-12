<?php
namespace EGBackup\Core;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Moteur d'exportation de base de données par lots (chunks).
 * Conçu pour éviter les dépassements de mémoire et timeouts PHP.
 */
class DatabaseExporter {
    /**
     * Récupère la liste de toutes les tables de la base de données.
     *
     * @return array
     */
    public static function getTables() {
        global $wpdb;
        $tables = $wpdb->get_col("SHOW FULL TABLES WHERE Table_Type = 'BASE TABLE'");
        return is_array($tables) ? $tables : [];
    }

    /**
     * Initialise le fichier SQL avec les en-têtes nécessaires.
     *
     * @param string $filePath Chemin du fichier SQL de travail.
     * @return bool
     */
    public static function initSqlFile($filePath) {
        $header = "-- ========================================================\n";
        $header .= "-- EG Backup & Restore - Database Export\n";
        $header .= "-- Date: " . current_time('mysql') . "\n";
        $header .= "-- WordPress Version: " . get_bloginfo('version') . "\n";
        $header .= "-- ========================================================\n\n";
        $header .= "SET FOREIGN_KEY_CHECKS = 0;\n";
        $header .= "SET SQL_MODE = 'NO_AUTO_VALUE_ON_ZERO';\n";
        $header .= "SET NAMES utf8mb4;\n\n";

        return (bool) file_put_contents($filePath, $header);
    }

    /**
     * Exporte la structure d'une table (DROP + CREATE).
     *
     * @param string $table Nom de la table.
     * @param string $filePath Chemin du fichier SQL.
     * @return bool
     */
    public static function exportTableStructure($table, $filePath) {
        global $wpdb;

        $safeTable = preg_replace('/[^a-zA-Z0-9_$]/', '', $table);
        $createRow = $wpdb->get_row("SHOW CREATE TABLE `{$safeTable}`", ARRAY_N);

        if (!$createRow || empty($createRow[1])) {
            return false;
        }

        $sql = "\n-- --------------------------------------------------------\n";
        $sql .= "-- Structure de la table `{$safeTable}`\n";
        $sql .= "-- --------------------------------------------------------\n";
        $sql .= "DROP TABLE IF EXISTS `{$safeTable}`;\n";
        $sql .= $createRow[1] . ";\n\n";

        return (bool) file_put_contents($filePath, $sql, FILE_APPEND);
    }

    /**
     * Compte le nombre total de lignes dans une table.
     *
     * @param string $table
     * @return int
     */
    public static function countTableRows($table) {
        global $wpdb;
        $safeTable = preg_replace('/[^a-zA-Z0-9_$]/', '', $table);
        return (int) $wpdb->get_var("SELECT COUNT(*) FROM `{$safeTable}`");
    }

    /**
     * Exporte un lot (chunk) de lignes pour une table donnée.
     *
     * @param string $table Nom de la table.
     * @param int $offset Position de départ.
     * @param int $limit Nombre de lignes à extraire.
     * @param string $filePath Chemin du fichier SQL.
     * @return int Nombre de lignes réellement exportées.
     */
    public static function exportTableChunk($table, $offset, $limit, $filePath) {
        global $wpdb;

        $safeTable = preg_replace('/[^a-zA-Z0-9_$]/', '', $table);
        $rows = $wpdb->get_results(
            $wpdb->prepare("SELECT * FROM `{$safeTable}` LIMIT %d OFFSET %d", $limit, $offset),
            ARRAY_A
        );

        if (empty($rows)) {
            return 0;
        }

        $sql = "";
        $first = true;
        $columns = [];

        foreach ($rows as $row) {
            if ($first) {
                $columns = array_map(function($col) {
                    return '`' . str_replace('`', '``', $col) . '`';
                }, array_keys($row));

                $sql .= "INSERT INTO `{$safeTable}` (" . implode(', ', $columns) . ") VALUES\n";
                $first = false;
            } else {
                $sql .= ",\n";
            }

            $values = [];
            foreach ($row as $val) {
                if ($val === null) {
                    $values[] = "NULL";
                } elseif (is_numeric($val) && !is_string($val)) {
                    $values[] = $val;
                } else {
                    $escapedVal = esc_sql($val);
                    $escapedVal = str_replace(["\r", "\n"], ['\r', '\n'], $escapedVal);
                    $values[] = "'" . $escapedVal . "'";
                }
            }

            $sql .= "(" . implode(', ', $values) . ")";
        }

        $sql .= ";\n";

        file_put_contents($filePath, $sql, FILE_APPEND);
        return count($rows);
    }

    /**
     * Finalise le fichier SQL en réactivant les contraintes d'intégrité.
     *
     * @param string $filePath
     * @return bool
     */
    public static function finalizeSqlFile($filePath) {
        $footer = "\n-- Rétablissement des vérifications de clés étrangères\n";
        $footer .= "SET FOREIGN_KEY_CHECKS = 1;\n";
        return (bool) file_put_contents($filePath, $footer, FILE_APPEND);
    }
}
