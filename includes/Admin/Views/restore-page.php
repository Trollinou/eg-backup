<?php
if (!defined('ABSPATH')) {
    exit;
}
?>
<div class="wrap eg-backup-wrap">
    <div class="eg-header">
        <h1><span class="dashicons dashicons-image-rotate"></span> Assistant de restauration intelligent</h1>
        <p class="eg-subtitle">Restaurez votre configuration et vos données avec vérification préalable des thèmes et extensions requis.</p>
    </div>

    <!-- Étape 1 : Choix de la sauvegarde -->
    <div class="eg-card" id="eg-restore-source-card">
        <h2><span class="dashicons dashicons-media-archive"></span> Étape 1 : Sélectionner l'archive de sauvegarde</h2>
        
        <div class="eg-source-options">
            <div class="eg-source-box">
                <h4>Choisir une sauvegarde locale existante :</h4>
                <div class="eg-form-inline">
                    <select id="eg-local-backup-select" class="regular-text">
                        <option value="">-- Sélectionnez une archive --</option>
                        <?php foreach ($backups as $b) : ?>
                            <option value="<?php echo esc_attr($b['filename']); ?>" <?php selected($selectedFile, $b['filename']); ?>>
                                <?php echo esc_html($b['filename'] . ' (' . $b['size_human'] . ' - ' . $b['date'] . ')'); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                    <button type="button" class="button button-primary" id="eg-inspect-local-btn">
                        <span class="dashicons dashicons-search"></span> Analyser cette sauvegarde
                    </button>
                </div>
            </div>

            <div class="eg-source-separator">OU</div>

            <div class="eg-source-box">
                <h4>Téléverser une archive ZIP depuis votre ordinateur :</h4>
                <div class="eg-form-inline">
                    <input type="file" id="eg-upload-backup-input" accept=".zip" />
                    <button type="button" class="button button-primary" id="eg-inspect-upload-btn">
                        <span class="dashicons dashicons-upload"></span> Téléverser et analyser
                    </button>
                </div>
            </div>
        </div>

        <div id="eg-inspect-loading" class="eg-tree-loading" style="display: none;">
            <span class="spinner is-active"></span> Extraction et analyse du manifeste de la sauvegarde en cours...
        </div>
    </div>

    <!-- Étape 2 : Bilan comparatif des dépendances (Plugins / Thèmes) -->
    <div class="eg-card" id="eg-restore-manifest-card" style="display: none;">
        <h2><span class="dashicons dashicons-admin-plugins"></span> Étape 2 : Vérification des extensions et thèmes requis</h2>
        <p class="description">
            Cette sauvegarde contient la configuration et les tables de vos extensions, mais pas leurs fichiers sources.
            Vérifiez ci-dessous que vos extensions sont bien réinstallées avant de réimporter leurs données.
        </p>

        <!-- Métadonnées de l'archive -->
        <div class="eg-manifest-meta-grid" id="eg-manifest-meta-grid"></div>

        <!-- Alerte si extensions manquantes -->
        <div id="eg-plugins-alert-box"></div>

        <!-- Tableau des plugins requis -->
        <div class="eg-table-responsive">
            <table class="wp-list-table widefat fixed striped eg-plugins-check-table">
                <thead>
                    <tr>
                        <th style="width: 35%;">Extension requise</th>
                        <th style="width: 15%;">Version sauvegarde</th>
                        <th style="width: 20%;">État actuel</th>
                        <th style="width: 30%;">Action recommandée</th>
                    </tr>
                </thead>
                <tbody id="eg-plugins-table-body">
                    <!-- Rempli dynamiquement en JS -->
                </tbody>
            </table>
        </div>

        <!-- Bilan du thème -->
        <div class="eg-theme-check-box" id="eg-theme-check-box"></div>

        <!-- Options de migration de domaine / URLs -->
        <div class="eg-migration-options-box">
            <h3><span class="dashicons dashicons-admin-links"></span> Réglages de domaine et URLs (Migration)</h3>
            <p class="description">Si vous restaurez sur un nouveau nom de domaine ou en local, les adresses seront automatiquement adaptées dans toute la base de données (avec respect des données sérialisées).</p>
            <div class="eg-form-row">
                <label>
                    <strong>URL source (sauvegarde) :</strong>
                    <input type="text" id="eg-replace-old-url" class="regular-text" readonly />
                </label>
                <label>
                    <strong>Nouvelle URL cible :</strong>
                    <input type="text" id="eg-replace-new-url" class="regular-text" value="<?php echo esc_attr(get_option('siteurl')); ?>" />
                </label>
            </div>
        </div>

        <!-- Confirmation de lancement -->
        <div class="eg-restore-launch-bar">
            <button type="button" class="button button-primary button-hero" id="eg-start-restore-btn">
                <span class="dashicons dashicons-update"></span> Lancer la restauration maintenant
            </button>
        </div>
    </div>

    <!-- Étape 3 : Progression de la restauration en direct -->
    <div class="eg-card" id="eg-restore-progress-card" style="display: none;">
        <h2><span class="dashicons dashicons-clock"></span> Restauration en cours...</h2>
        <div class="eg-notice-warning inline">
            <p><strong>Mode maintenance actif :</strong> Votre site est temporairement mis en maintenance le temps d'appliquer les changements. Ne fermez pas cette page.</p>
        </div>

        <div class="eg-steps-indicator">
            <div class="eg-step" id="rstep-extract"><span class="step-num">1</span> Décompression</div>
            <div class="eg-step" id="rstep-db"><span class="step-num">2</span> Import BDD</div>
            <div class="eg-step" id="rstep-replace"><span class="step-num">3</span> URLs & Liens</div>
            <div class="eg-step" id="rstep-files"><span class="step-num">4</span> Fichiers</div>
            <div class="eg-step" id="rstep-finalize"><span class="step-num">5</span> Finalisation</div>
        </div>

        <div class="eg-progress-bar-wrapper">
            <div class="eg-progress-bar" id="eg-restore-progress-bar" style="width: 0%;"></div>
        </div>

        <div class="eg-progress-status" id="eg-restore-status">Initialisation...</div>

        <div class="eg-log-console" id="eg-restore-log-console"></div>

        <div id="eg-restore-success-box" class="eg-success-box" style="display: none;">
            <h3><span class="dashicons dashicons-yes-alt"></span> Restauration terminée avec succès !</h3>
            <p>Toutes les données, configurations de plugins et fichiers choisis ont été réintégrés. Le mode maintenance a été automatiquement désactivé.</p>
            <div class="eg-success-actions">
                <a href="<?php echo esc_url(home_url('/')); ?>" target="_blank" class="button button-primary button-large">
                    <span class="dashicons dashicons-external"></span> Voir le site en ligne
                </a>
                <a href="<?php echo esc_url(admin_url()); ?>" class="button button-secondary button-large">
                    Retour au tableau de bord
                </a>
            </div>
        </div>
    </div>
</div>
