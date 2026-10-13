# Règles de développement du projet EG Backup & Restore

Ce document définit les règles de développement et conventions obligatoires pour ce projet WordPress.

---

## 1. Feuilles de style : Utilisation exclusive de SCSS

- **Emplacement des sources** : Tous les styles doivent être rédigés en fichiers SCSS situés dans le dossier `src/scss/` (ex. `src/scss/admin.scss` et ses modules/partials `_*.scss`).
- **Fichiers de sortie (`assets/css/`)** : **Ne jamais modifier directement** les fichiers CSS situés dans `assets/css/` (`admin.css`, `admin-rtl.css`). Ces fichiers sont générés automatiquement lors du build.
- **Support RTL** : Le fichier RTL (`admin-rtl.css`) est automatiquement généré à partir de la compilation SCSS via `rtlcss`.
- **Commande de compilation** :
  - Compilation des styles : `npm run build:css`
  - Compilation globale : `npm run build`

---

## 2. Scripts : Utilisation exclusive de TypeScript (Sans jQuery / Vanilla TS)

- **Emplacement des sources** : Tous les scripts clients doivent être rédigés en TypeScript (`.ts`) dans le dossier `src/ts/` (ex. `src/ts/backup.ts`, `src/ts/restore.ts`, et modules partagés `src/ts/_*.ts`).
- **Fichiers de sortie (`assets/js/`)** : **Ne jamais modifier directement** les fichiers JavaScript finaux situés dans `assets/js/` (`backup.js`, `restore.js`). Ils sont bundlés et minifiés par `esbuild`.
- **Proscription stricte de jQuery** : Le code TypeScript doit être rédigé en Vanilla TS moderne en s'appuyant sur les API standards des navigateurs :
  - DOM : `document.querySelector`, `document.querySelectorAll`, `addEventListener`, etc.
  - Réseau : API native `fetch()` avec `async/await` via le helper partagé `wpAjax` de `src/ts/_common.ts`.
  - Ne pas déclarer `['jquery']` dans les dépendances `wp_enqueue_script()`.
- **Typage et variables globales** :
  - Déclarer les objets transmis par WordPress (via `wp_localize_script` comme `egBackupData`) dans `src/ts/globals.d.ts`.
  - Vérifier la conformité du code TypeScript avec `npm run typecheck` (`tsc --noEmit`).
- **Commande de compilation** :
  - Compilation des scripts : `npm run build:js`
  - Compilation globale : `npm run build`

---

## 3. Workflow & Automatisation

1. **Cycle de modification front-end** :
   - Modifier les fichiers dans `src/scss/` ou `src/ts/`.
   - Exécuter `npm run build` pour régénérer `assets/css/` et `assets/js/`.
   - Vérifier l'absence d'erreurs de type avec `npm run typecheck`.
2. **Packaging & Déploiement** :
   - Pour créer l'archive ZIP de distribution du plugin WordPress, exécuter `npm run package`. Le script compile automatiquement les assets en mode production avant de créer l'archive.
3. **Synchronisation des versions** :
   - Pour mettre à jour la version du plugin sur l'ensemble des fichiers, exécuter `node scripts/version-sync.cjs <version>` (ou `npm run version`).

---

## 4. Architecture WordPress & Optimisation des Assets (Performance & DevTools Coverage)

Pour garantir une couverture de code optimale (DevTools Coverage) et respecter les recommandations PageSpeed / Site Kit :

- **Séparation stricte Register vs Enqueue** :
  - Toujours déclarer les assets avec `wp_register_style()` et `wp_register_script()` avant tout appel à `wp_enqueue_*()`.
  - Ne jamais exécuter de `wp_enqueue_style()` ou `wp_enqueue_script()` inconditionnel ou global sur les hooks `wp_enqueue_scripts` ou `admin_enqueue_scripts`.
- **Chargement contextuel et à la demande** :
  - **Back-end / Admin** : Ne charger les assets que sur les pages d'options ou écrans du plugin. Récupérer les identifiants retournés par `add_menu_page()` / `add_submenu_page()` et filtrer strictement via le paramètre `$hook_suffix` de `admin_enqueue_scripts( $hook_suffix )` ou `get_current_screen()`.
  - **Front-end** : Vérifier le contexte avant tout enqueue (conditional tags `is_page()`, arguments `style`/`view_script_module` de `register_block_type()` pour Gutenberg, ou dans le callback de rendu des shortcodes).
- **Stratégie de chargement des scripts (WordPress 6.3+)** :
  - Déclarer systématiquement la stratégie `defer` ou `async` via le tableau d'options de `wp_register_script()` :
    ```php
    wp_register_script(
        'handle-script',
        EG_BACKUP_PLUGIN_URL . 'assets/js/mon-script.js',
        [],
        EG_BACKUP_VERSION,
        [ 'strategy' => 'defer', 'in_footer' => true ]
    );
    ```
- **Granularité des bundles** :
  - Bannir les fichiers CSS/JS monolithiques regroupant plusieurs pages distinctes.
  - Découper le code source TypeScript (`src/ts/`) en scripts atomiques dédiés à chaque vue (ex. `backup.ts` pour la création, `list.ts` pour la suppression d'archives, `restore.ts` pour la restauration) afin de maintenir un taux de code JavaScript inutilisé proche de 0%.
