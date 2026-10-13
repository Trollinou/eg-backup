# Documentation de l'API de Hooks : EG Backup & Restore

Ce document détaille l'API d'extension par hooks de **EG Backup & Restore**, permettant aux extensions tierces et développements sur mesure (ex. plugins de gestion de club, extensions documentaires, CRM) d'enregistrer leurs dossiers et répertoires de données afin qu'ils soient reconnus, catégorisés, sauvegardés et restaurés de manière transparente.

---

## Filtre : `eg_backup_registered_locations`

Le filtre `eg_backup_registered_locations` permet d'enregistrer un ou plusieurs emplacements de données ou de documents sur le système de fichiers.

### Définition du Hook

```php
apply_filters('eg_backup_registered_locations', array $locations);
```

### Contrat de données d'un emplacement

Chaque entrée du tableau retourné doit être un tableau associatif identifié par une clé unique (`id`) et contenant les paramètres suivants :

| Clé | Type | Obligatoire | Description / Valeurs possibles |
| :--- | :--- | :--- | :--- |
| `id` | `string` | **Oui** | Identifiant unique interne (slug composé uniquement de lettres minuscules, chiffres et tirets bas `[a-z0-9_]`). |
| `label` | `string` | **Oui** | Nom lisible affiché dans l'interface d'administration WordPress. |
| `plugin_name` | `string` | **Oui** | Nom de votre extension (affiché sous forme de badge distinctif). |
| `path` | `string` | **Oui** | Chemin absolu ou relatif à `wp-content` vers le répertoire de stockage sur disque. |
| `category` | `string` | Non | Catégorie d'affichage : `'documents'`, `'media'`, `'gallery'`, ou `'custom'`. Valeur par défaut : `'documents'`. |
| `recommended` | `bool` | Non | Si `true`, le dossier est coché par défaut lors de la sélection des documents. Valeur par défaut : `false`. |
| `description` | `string` | Non | Texte explicatif détaillant la nature des fichiers stockés (affiché dans l'explorateur). |
| `exclude` | `array` | Non | Liste de motifs (patterns / noms de fichiers ou sous-dossiers) à exclure lors de la sauvegarde (ex: fichiers temporaires, caches). |

---

## Exemple d'intégration : Plugin de Gestion de Club

Dans cet exemple, un plugin de gestion de club sportif stocke des documents sensibles d'adhérents (certificats médicaux, autorisations parentales, attestations d'assurance) dans un sous-dossier de `wp-content/uploads/club-documents/`.

### Code à insérer dans votre extension :

```php
<?php
/**
 * Plugin Name: Gestion de Club Sportif
 * Description: Gestion des adhérents, licences et documents.
 */

add_filter('eg_backup_registered_locations', function (array $locations) {
    $uploadDir = wp_upload_dir();
    $clubDocsPath = $uploadDir['basedir'] . '/club-documents';

    $locations['club_documents'] = [
        'id'          => 'club_documents',
        'label'       => 'Documents des Adhérents (Certificats & Autorisations)',
        'plugin_name' => 'Gestion de Club',
        'path'        => $clubDocsPath,
        'category'    => 'documents',
        'recommended' => true,
        'description' => 'Certificats médicaux, autorisations parentales et fiches d\'inscription des membres du club.',
        'exclude'     => [
            'temp',          // Exclut le sous-dossier temp/
            '*.tmp',         // Exclut les fichiers temporaires
            'preview_cache', // Exclut les aperçus de prévisualisation
        ],
    ];

    return $locations;
});
```

---

## Comportement lors de la Sauvegarde

1. **Détection et calcul de volumétrie :**
   - EG Backup exécute le hook lors de l'analyse des fichiers.
   - Si le dossier existe sur le disque, sa taille totale et son nombre de fichiers sont calculés automatiquement.
   - Il apparaît sous la catégorie **« Documents & Données déclarées »** avec le badge de votre plugin `[Gestion de Club]`.
2. **Filtrage automatique :**
   - Lors de l'archivage, les motifs spécifiés dans la clé `exclude` sont strictement ignorés.
3. **Traçabilité dans le Manifeste (`manifest.json`) :**
   - L'archive enregistre les métadonnées de l'emplacement dans son manifeste, garantissant une identification parfaite lors de transferts de serveurs.

---

## Comportement lors de la Restauration

1. **Restauration ciblée :**
   - Les documents sont restaurés avec exactitude dans leur dossier de destination d'origine (`uploads/club-documents/`).
2. **Préservation de la base de données :**
   - Si l'archive est de type **Médias & Documents**, la base de données actuelle n'est pas modifiée et les comptes administrateurs ne sont pas déconnectés.
3. **Sécurité et intégrité :**
   - Les fichiers existants ne sont pas écrasés si leur date et empreinte sont identiques, évitant toute corruption en cas d'interruption.

---

## Utilisation via WP-CLI

Les emplacements déclarés via ce filtre peuvent être ciblés individuellement en ligne de commande :

```bash
# Sauvegarder uniquement les documents du club
wp eg-backup create --type=media --include-locations=club_documents

# Sauvegarder l'ensemble des médias et des emplacements déclarés
wp eg-backup create --type=media
```
