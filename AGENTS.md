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
