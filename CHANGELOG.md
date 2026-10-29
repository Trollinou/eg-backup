# Changelog

Toutes les modifications notables apportées au projet **EG Backup & Restore** sont documentées dans ce fichier.

Le format est basé sur [Keep a Changelog](https://keepachangelog.com/fr/1.0.0/), et ce projet adhère à la spécification [Semantic Versioning](https://semver.org/lang/fr/).

---

## [Unreleased]

## [1.0.9] - 2026-10-29

## [1.0.8] - 2026-10-25

### Corrigé
- **Coupure réseau à 537 Mo et dépassement mémoire lors du téléchargement d'archives volumineuses (> 500 Mo / 1 Go)** :
  - **Téléchargement segmenté côté navigateur par tranches de 64 Mo (`downloadBackupByChunks` & `Archiver::streamFileChunk`)** : Pour franchir le plafond matériel de 512 MiB (~537,5 Mo) imposé par les proxys frontaux d'hébergeurs (o2switch / TigerProtect / Cloudflare) sur toute réponse HTTP unique, le client TypeScript découpe automatiquement le téléchargement en requêtes successives de 64 Mo (`chunk_start` / `chunk_end`) avec reprise automatique (3 tentatives par tronçon), suivi du pourcentage en direct sur le bouton, assemblage `Blob` zéro-copie et enregistrement local instantané.
  - **Téléchargement statique direct sécurisé par token éphémère (`Archiver::prepareDirectDownloadUrl`)** : Pour les accès hors JS, création d'un lien physique (`hardlink`, 0 octet disque supplémentaire) dans un sous-dossier à jeton cryptographique aléatoire de 48 caractères (`eg-backup-data/dl/<token>/`) purgé automatiquement après 1 heure.
  - **Fallback de streaming binaire par morceaux (`Archiver::streamFileToOutput`)** : Diffusion séquentielle par paquets de 1 Mo avec support `HTTP Range` (206 Partial Content), en-têtes `X-LiteSpeed-Buffer: no` / `X-Accel-Buffering: no`, désactivation de `zlib.output_compression` et vidage des tampons de sortie (`ob_end_clean`).

## [1.0.7] - 2026-10-17

### Ajouté
- **Purge automatique & Garbage Collector des dossiers de travail temporaires (`Archiver::purgeOldTempDirectories`)** :
  - Détection et suppression récursive automatique des dossiers temporaires de travail orphelins (`temp/job_*`, `temp/restore_*`) et fichiers résiduels dont la dernière activité remonte à plus de 2 heures.
  - Exécution opportuniste lors de la consultation des sauvegardes, de la suppression d'une archive, ainsi qu'au démarrage d'une nouvelle sauvegarde ou restauration.
  - Planification d'une tâche de fond quotidienne WP-Cron (`eg_backup_cleanup_temp_event`) avec désinscription propre lors de la désactivation du plugin.
- **Annulation et nettoyage immédiat en cas d'erreur (`eg_backup_cancel`)** :
  - Émission automatique d'une requête AJAX d'annulation dès qu'une erreur ou une interruption intervient côté client, détruisant immédiatement le dossier temporaire du job en cours sans attendre le délai d'expiration de 2h.
- **Commande WP-CLI `wp eg-backup purge-temp`** :
  - Commande d'administration permettant de purger manuellement les dossiers temporaires orphelins avec paramètre d'âge `--max-age=<secondes>`.
- **Tests unitaires (`ArchiverTest`)** :
  - Couverture automatisée des fonctions `cleanDirectory` (gestion des sous-dossiers, fichiers et symlinks) et `purgeOldTempDirectories` (préservation des jobs récents et suppression sélective des jobs expirés).

### Amélioré
- **Sécurisation de `Archiver::cleanDirectory`** :
  - Prise en charge stricte des liens symboliques (`is_link` vérifié avant `is_dir`) pour prévenir toute traversée intempestive ou blocage de suppression (`rmdir`).
  - Normalisation des chemins avec `rtrim()` pour éliminer tout risque de double séparateur de répertoire.

## [1.0.6] - 2026-10-17

### Corrigé
- **Préservation des symboles `%` et intégrité des données sérialisées (Placeholder Escape WordPress)** :
  - **Exportation (`DatabaseExporter`)** : Appel systématique de `$wpdb->remove_placeholder_escape()` après `esc_sql()` lors de l'export des enregistrements. Empêche la persistance des tokens de sécurité WordPress (`{hash}` sha256 générés par `wpdb::placeholder_escape()`) dans les dumps SQL et préserve les symboles `%` (permaliens, styles CSS, URLs encodées).
  - **Restauration (`DatabaseImporter`)** : Ajout du filtre de nettoyage `cleanPlaceholderEscape()` exécuté à la volée sur chaque instruction SQL lors de l'import par morceaux (`importSqlChunk`). Les éventuels tokens résiduels issus d'anciennes archives sont rétablis en `%` avant l'exécution MySQL, garantissant la validité de la longueur d'octets déclarée (`s:X:`) dans les structures sérialisées PHP et éliminant tout risque d'erreur fatale au démarrage de WordPress (`wp_fatal_error_handler`).
- **Correction du pointeur d'offset en restauration WP-CLI (`BackupCommand`)** :
  - Transmission correcte de `$offset` dans la boucle d'importation de `DatabaseImporter::importSqlChunk($sqlPath, $offset, 4194304)`, évitant le blocage à 4 Mo lors des restaurations en ligne de commande.

### Ajouté
- **Tests unitaires automatisés (`DatabaseTest`)** :
  - Ajout d'une suite de tests PHPUnit validant le nettoyage et le rétablissement de `%` sur des requêtes SQL réelles contenant des placeholders WordPress, ainsi que la non-altération des requêtes standards.

## [1.0.5] - 2026-10-13

## [1.0.4] - 2026-10-13

### Ajouté
- **Archivage CLI asynchrone non-bloquant & Surveillance en direct (Anti-Timeout HTTP 500)** :
  - **Exécution détachée en arrière-plan** : Découplage de la commande système `zip` via [`Archiver::startAsyncCliZip()`](file:///c:/Users/egagnon/Personnel/Dev/eg-backup/includes/Core/Archiver.php). La requête AJAX HTTP initiale répond immédiatement (< 0.5s), éliminant définitivement les erreurs HTTP 500 / 504 provoquées par les timeouts stricts de 60s des serveurs web (FastCGI / Nginx / Apache).
  - **Surveillance active (Polling auto-refresh & Détection des fichiers temporaires `zi*`)** :
    - Nouvel endpoint AJAX [`eg_backup_poll_cli`](file:///c:/Users/egagnon/Personnel/Dev/eg-backup/includes/Ajax/AjaxHandler.php) et boucle JavaScript réactive sondant l'état toutes les 2 secondes.
    - Détection de la taille réelle pendant l'écriture Info-ZIP via la recherche des fichiers de travail temporaires (`zi*`), évitant que la taille affichée ne reste bloquée à la taille de l'archive BDD initiale jusqu'au `close()`.
    - Suivi et affichage en temps réel du décompte des fichiers traités (`X / Y fichiers`), du pourcentage fluide et du nom du fichier en cours d'archivage dans la console et la barre de statut.
  - **Restauration par micro-lots et extraction optimisée (Zéro timeout sur Médias & Full)** :
    - **Initialisation instantanée (`initRestoreJob`)** : L'étape initiale n'extrait plus l'intégralité de l'archive (1 Go+) dans `temp/`, mais uniquement les métadonnées et la base SQL en moins de 0.05 seconde, éliminant tout blocage à l'étape 1.
    - **Restitution directe par micro-lots (`restoreFilesChunk`)** : Remplacement de la copie monolithique récursive par une extraction directe par streaming vers `WP_CONTENT_DIR` découpée en micro-lots (150 fichiers ou 16 Mo par passe, durée maximale de 4s par requête). Zéro risque de timeout HTTP 500, suppression de la double écriture sur disque et suivi en direct de la progression de 0 à 100%.

## [1.0.3] - 2026-10-13

## [1.0.2] - 2026-10-13

### Ajouté
- **Cumul et affichage en temps réel de la volumétrie sélectionnée par catégorie** :
  - Ajout dans le bandeau de chaque groupe de l'inventaire (*Médiathèque*, *Galeries*, *Données déclarées via hook*, *Optimiseurs*) d'un indicateur dynamique calculant la taille totale cumulée et le décompte de fichiers des dossiers cochés au sein de cette liste.
  - Recalcul réactif instantané sans rechargement lors de la sélection/désélection manuelle, des actions rapides (*Tout cocher*, *Tout décocher*, *Sélection recommandée*) et de la bascule de l'option d'exclusion des miniatures (`data-size-no-thumbs`).
  - Nouveaux badges d'état SCSS (`.eg-cat-active` émeraude avec volumétrie mise en valeur et `.eg-cat-empty` discret), avec prise en charge responsive et RTL (`admin-rtl.css`).

### Modifié
- **Suppression intégrale des redondances pour les répertoires enregistrés via hook (`eg_backup_registered_locations`)** :
  - Implémentation de [`FileScanner::isLocationCoveredByHooks()`](file:///c:/Users/egagnon/Personnel/Dev/eg-backup/includes/Core/FileScanner.php) effectuant une analyse multi-critères (chemins relatifs à `wp-content`, chemins absolus normalisés, chemins réels sur disque via `realpath`, et détection des inclusions hiérarchiques de sous-dossiers).
  - Retrait automatique des répertoires déclarés par hook ainsi que de leurs dossiers conteneurs parents (ex: un dossier `uploads/eg-media/` dont tous les sous-dossiers utiles comme `originals/` sont déclarés via hook) des listes génériques *« Médiathèque WordPress standard »* et *« Galeries Photos »* (y compris NextGEN Gallery `wp-content/gallery/`), évitant tout doublon d'affichage ou risque de double archivage.
  - Renforcement de la normalisation des chemins absolus et relatifs dans [`FileScanner::getRegisteredLocations()`](file:///c:/Users/egagnon/Personnel/Dev/eg-backup/includes/Core/FileScanner.php).
  - Mise à jour de la documentation de référence dans [`docs/HOOKS_API.md`](file:///c:/Users/egagnon/Personnel/Dev/eg-backup/docs/HOOKS_API.md).

## [1.0.1] - 2026-10-13

### Ajouté
- **Affichage des emplacements déclarés non encore créés (`eg_backup_registered_locations`)** :
  - Les emplacements déclarés par des addons ou extensions métier apparaissent désormais systématiquement dans l'explorateur de sauvegarde, même si leur répertoire physique n'existe pas encore sur le disque.
  - Affichage d'un badge distinctif `Dossier non créé sur le disque (0 Ko)` et case à cocher désactivée (`disabled`) avec style atténué pour confirmer visuellement la bonne prise en compte du hook sans risque d'erreur à l'archivage.

### Corrigé
- **Résolution et normalisation des chemins de fichiers (Windows / Multiplateforme)** :
  - Remplacement de `strpos()` par `stripos()` dans [`FileScanner::getRegisteredLocations()`](file:///c:/Users/egagnon/Personnel/Dev/eg-backup/includes/Core/FileScanner.php) pour gérer la casse des lettres de lecteurs sous Windows (`C:` vs `c:`).
  - Détection fiable des chemins absolus pour prévenir toute double concaténation erronée avec `WP_CONTENT_DIR`.
- **Déduplication des répertoires déclarés dans `wp_media`** :
  - Les dossiers situés dans `wp-content/uploads/` et déclarés via `eg_backup_registered_locations` sont désormais exclus de la liste brute de la médiathèque standard pour éviter les doublons et valoriser l'identité de l'extension avec son badge dédié.
- **Gestion des fuseaux horaires et horodatage local** :
  - Remplacement de `gmdate()` par `wp_date()` pour la génération des noms d'archives et des identifiants de tâches AJAX/CLI, assurant la prise en compte du décalage horaire configuré dans WordPress.
  - Correction de l'affichage de la colonne *Date de création* dans la liste des sauvegardes via `wp_date()`, résolvant le décalage UTC constaté avec `date_i18n()` sur les timestamps UNIX bruts.
  - Enrichissement du manifeste (`manifest.json`) avec `created_at_formatted` et `timezone`, et mise à jour de l'écran d'inspection de restauration.

## [1.0.0] - 2026-10-13

### Ajouté
- **Sauvegarde modulaire par profils** :
  - Profil **Cœur** : Sauvegarde ultra-légère de la base de données SQL et des thèmes actifs / parents.
  - Profil **Médias & Documents** : Sauvegarde ciblée des répertoires d'uploads et documents volumineux.
  - Profil **Complet** : Archivage intégral combinant BDD, thèmes, répertoires sélectionnés et manifestes.
- **Explorateur arborescent granulaire** :
  - Calcul dynamique de la taille et du nombre de fichiers par dossier.
  - Catégorisation intelligente des répertoires (`wp-content/uploads` par année, photothèques de galeries tierces telles que NextGEN Gallery, optimisateurs d'images).
  - Option d'exclusion automatique des miniatures générées (ex. `-150x150`, `-scaled`, dossiers `thumbs/` et `dynamic/`).
- **Archivage haute performance** :
  - Prise en charge native de l'utilitaire CLI `zip -0` (stockage sans compression) pour les médias déjà compressés, réduisant drastiquement l'usage CPU et le temps de traitement.
  - Fallback automatique et robuste sur l'extension PHP `ZipArchive`.
- **API d'extension & Hooks** :
  - Filtre `eg_backup_registered_locations` permettant aux extensions tierces et développeurs de déclarer des dossiers personnalisés hors BDD.
  - Documentation détaillée de l'API dans [`docs/HOOKS_API.md`](docs/HOOKS_API.md).
- **Manifeste de dépendances (`manifest.json`)** :
  - Enregistrement des versions, états et métadonnées des extensions et thèmes installés.
  - Détection automatique et comparaison des dépendances requises lors de la restauration.
  - Réinstallation assistée en un clic des extensions disponibles sur le dépôt officiel WordPress.org.
- **Assistant de restauration interactif & Migration** :
  - Restauration par micro-lots AJAX avec suivi de progression et logs en temps réel.
  - Outil de *Search & Replace* SQL compatible avec les chaînes sérialisées PHP (migration sécurisée de domaine ou environnement local/staging).
  - Activation et désactivation automatisée du mode maintenance (`.maintenance`).
- **Support WP-CLI complet** :
  - Commandes `wp eg-backup create` avec options de profils (`--type=core|media|full`), exclusion des miniatures (`--no-thumbs`) et inclusion personnalisée (`--include-locations`).
  - Commandes `wp eg-backup list` et `wp eg-backup restore`.
- **Pipeline d'outillage & Build** :
  - Compilation automatisée SCSS vers CSS minifié avec génération RTL (`rtlcss`).
  - Bundling TypeScript via `esbuild` avec vérification statique des types (`tsc --noEmit`).
  - Scripts d'automatisation pour le packaging d'archive ZIP de distribution (`npm run package`) et la synchronisation de version (`npm run version`).

### Modifié
- **Architecture des assets & Performance front-end** :
  - Découpage modulaire du code TypeScript en bundles dédiés par écran (`backup.ts`, `list.ts`, `restore.ts`).
  - Chargement strictement contextuel des scripts et styles admin via `$hook_suffix`.
  - Adoption de la stratégie de chargement différé `defer` conforme à WordPress 6.3+.
  - Proscription complète de jQuery au profit de l'API standard `fetch` et Vanilla TS.
- **Robustesse AJAX & Sessions** :
  - Traitement sécurisé par micro-lots successifs pour prévenir tout timeout serveur ou dépassement de mémoire PHP (`memory_limit`).
