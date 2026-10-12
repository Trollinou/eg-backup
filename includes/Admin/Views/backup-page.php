<?php
if (!defined('ABSPATH')) {
    exit;
}
?>
<div class="wrap eg-backup-wrap">
    <div class="eg-header">
        <h1><span class="dashicons dashicons-database-export"></span> EG Backup & Restore</h1>
        <p class="eg-subtitle">Sauvegarde intelligente de WordPress : configuration intégrale, base de données complète et données de plugins hors BDD.</p>
    </div>

    <div class="eg-card eg-info-card">
        <div class="eg-info-icon"><span class="dashicons dashicons-info"></span></div>
        <div class="eg-info-content">
            <h3>Périmètre de cette sauvegarde</h3>
            <ul>
                <li><strong>Base de données complète :</strong> Toutes les tables (options, articles, pages, utilisateurs, réglages de chaque extension).</li>
                <li><strong>Manifeste des dépendances :</strong> Enregistrement automatique de la version et de la source de chaque plugin et thème pour réinstallation guidée.</li>
                <li><strong>Optimisation :</strong> Les fichiers de code source des thèmes et extensions ne sont pas dupliqués dans l'archive.</li>
            </ul>
        </div>
    </div>

    <!-- Section Arborescence de fichiers hors BDD -->
    <div class="eg-card">
        <h2><span class="dashicons dashicons-category"></span> Fichiers et données hors base de données</h2>
        <p class="description">
            Certains plugins sauvegardent des données en dehors de la base de données (ex: dossiers d'uploads personnalisés, photothèque de NextGEN Gallery, documents).
            Parcourez ci-dessous ce qui est présent pour choisir précisément ce que vous souhaitez inclure.
        </p>

        <div class="eg-tree-actions">
            <button type="button" class="button" id="eg-scan-btn">
                <span class="dashicons dashicons-update"></span> Analyser le dossier wp-content
            </button>
            <button type="button" class="button" id="eg-select-recommended-btn">
                <span class="dashicons dashicons-yes-alt"></span> Sélection recommandée
            </button>
            <button type="button" class="button" id="eg-select-all-btn">Tout cocher</button>
            <button type="button" class="button" id="eg-deselect-all-btn">Tout décocher</button>
        </div>

        <div id="eg-tree-loading" class="eg-tree-loading" style="display: none;">
            <span class="spinner is-active"></span> Analyse de l'arborescence et calcul des tailles en cours...
        </div>

        <div id="eg-file-tree-container" class="eg-file-tree-container">
            <p class="eg-placeholder-text">Cliquez sur « Analyser le dossier wp-content » pour charger l'arborescence des dossiers.</p>
        </div>
    </div>

    <!-- Section Lancement -->
    <div class="eg-card eg-action-card">
        <h2><span class="dashicons dashicons-cloud-saved"></span> Lancer la sauvegarde</h2>
        <p>L'opération s'exécute par tranches successives pour garantir une compatibilité totale sans risque de timeout serveur.</p>

        <div class="eg-backup-cta">
            <button type="button" class="button button-primary button-hero" id="eg-start-backup-btn">
                <span class="dashicons dashicons-shield"></span> Démarrer la sauvegarde maintenant
            </button>
        </div>

        <!-- Zone de progression en direct -->
        <div id="eg-progress-container" class="eg-progress-container" style="display: none;">
            <div class="eg-steps-indicator">
                <div class="eg-step" id="step-init"><span class="step-num">1</span> Initialisation</div>
                <div class="eg-step" id="step-db"><span class="step-num">2</span> Base de données</div>
                <div class="eg-step" id="step-files"><span class="step-num">3</span> Fichiers choisis</div>
                <div class="eg-step" id="step-manifest"><span class="step-num">4</span> Manifeste & ZIP</div>
            </div>

            <div class="eg-progress-bar-wrapper">
                <div class="eg-progress-bar" id="eg-progress-bar" style="width: 0%;"></div>
            </div>

            <div class="eg-progress-status" id="eg-progress-status">Préparation...</div>

            <div class="eg-log-console" id="eg-log-console"></div>

            <div id="eg-success-box" class="eg-success-box" style="display: none;">
                <h3><span class="dashicons dashicons-yes-alt"></span> Sauvegarde terminée avec succès !</h3>
                <p id="eg-success-details"></p>
                <div class="eg-success-actions">
                    <a href="#" id="eg-download-link" class="button button-primary button-large">
                        <span class="dashicons dashicons-download"></span> Télécharger l'archive ZIP
                    </a>
                    <a href="<?php echo esc_url(admin_url('admin.php?page=eg-backup-list')); ?>" class="button button-secondary button-large">
                        Voir la liste des sauvegardes
                    </a>
                </div>
            </div>
        </div>
    </div>
</div>
