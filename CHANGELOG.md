# Changelog

Toutes les modifications notables apportées au projet **EG Backup & Restore** sont documentées dans ce fichier.

Le format est basé sur [Keep a Changelog](https://keepachangelog.com/fr/1.0.0/), et ce projet adhère à la spécification [Semantic Versioning](https://semver.org/lang/fr/).

---

## [Unreleased]

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
