# EG Backup & Restore (Plugin WordPress)

Plugin WordPress de sauvegarde et restauration intelligente :
- **Export intégral de la base de données** (posts, pages, options, tables personnalisées créées par des extensions).
- **Explorateur granulaire de données hors BDD** : choix des répertoires de `wp-content` (photothèques de galeries comme NextGEN Gallery, dossiers d'uploads personnalisés, documents) avec calcul des tailles et du nombre de fichiers.
- **Manifeste des dépendances (`manifest.json`)** : mémorise la liste exacte, la version et la provenance de chaque extension et thème.
- **Assistant de restauration interactif** :
  - Détecte et compare les plugins et thèmes requis vs installés.
  - Propose l'installation en 1-clic pour les extensions issues du dépôt officiel WordPress.org.
  - Outil de *Search & Replace* (compatible avec les données sérialisées PHP) pour la migration vers un nouveau nom de domaine ou un environnement local/staging.
  - Activation automatique du mode maintenance pendant l'opération.
- **Zéro timeout serveur** : exécution par micro-lots via requêtes AJAX successives avec barre de progression et console en temps réel.
- **Commandes WP-CLI** incluses pour les serveurs et tâches automatisées.

---

## 📁 Structure du projet

```text
eg-backup/
├── eg-backup.php                 # Fichier d'en-tête du plugin et initialisation
├── includes/
│   ├── Autoloader.php            # Autochargement PSR-4
│   ├── Admin/
│   │   ├── AdminController.php   # Contrôleur d'administration (menus, assets, downloads)
│   │   └── Views/
│   │       ├── backup-page.php   # Page de création avec explorateur arborescent
│   │       ├── list-page.php     # Liste des archives existantes
│   │       └── restore-page.php  # Assistant de restauration interactif
│   ├── Core/
│   │   ├── Archiver.php          # Gestionnaire de fichiers ZIP et stockage sécurisé
│   │   ├── DatabaseExporter.php  # Dump SQL par lots de tables et lignes
│   │   ├── DatabaseImporter.php  # Importation SQL + Search & Replace sérialisé
│   │   ├── FileScanner.php       # Découverte de l'arborescence wp-content
│   │   ├── MaintenanceMode.php   # Gestion du fichier .maintenance
│   │   └── ManifestManager.php   # Génération et comparaison du manifest.json
│   ├── Ajax/
│   │   └── AjaxHandler.php       # Endpoints AJAX sécurisés
│   └── Cli/
│       └── BackupCommand.php     # Commandes WP-CLI (wp eg-backup)
├── assets/
│   ├── css/
│   │   └── admin.css             # Interface moderne et responsive
│   └── js/
│       ├── backup.js             # Scan, sélection et boucle AJAX de sauvegarde
│       └── restore.js            # Détection de dépendances et boucle AJAX de restauration
└── README.md
```

---

## 🚀 Installation

1. Copiez ou clonez le dossier `eg-backup` dans le répertoire des extensions de votre site WordPress :
   ```bash
   wp-content/plugins/eg-backup/
   ```
2. Rendez-vous dans le tableau de bord WordPress > **Extensions** > **Extensions installées**.
3. Activez **EG Backup & Restore**.
4. Un nouveau menu **EG Backup** apparaît dans votre barre latérale d'administration.

---

## 🔒 Sécurité du répertoire de stockage

Les archives sont stockées dans `wp-content/uploads/eg-backup-data/`.
Ce dossier est automatiquement protégé dès l'activation par :
- Un fichier `.htaccess` bloquant tout accès web direct (`Require all denied` / `Deny from all`).
- Un fichier `index.html` interdisant l'affichage de l'index du répertoire.
- Les téléchargements depuis l'administration passent par un handler PHP vérifiant les droits administrateur (`manage_options`) et un nonce de sécurité.

---

## 💻 Commandes WP-CLI

Le plugin fournit des commandes WP-CLI natives :

```bash
# Lister les sauvegardes disponibles
wp eg-backup list

# Créer une sauvegarde (inclut uploads par défaut)
wp eg-backup create

# Créer une sauvegarde en spécifiant des dossiers personnalisés
wp eg-backup create --folders=uploads,gallery

# Inspecter le manifeste et les dépendances d'une sauvegarde
wp eg-backup inspect backup_2026-10-12_14-30-00.zip

# Restaurer une archive avec Search & Replace de domaine
wp eg-backup restore backup_2026-10-12_14-30-00.zip --search=https://ancien-site.com --replace=https://nouveau-site.local
```
