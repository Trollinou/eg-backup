<?php
if (!defined('ABSPATH')) {
    exit;
}
?>
<div class="wrap eg-backup-wrap">
    <div class="eg-header">
        <h1><span class="dashicons dashicons-database-export"></span> EG Backup & Restore</h1>
        <p class="eg-subtitle">Sauvegarde modulaire et intelligente de WordPress : Cœur, Base de données, Médias et Données de plugins métier.</p>
    </div>

    <!-- Sélecteur de type de sauvegarde -->
    <div class="eg-card eg-type-selection-card">
        <h2><span class="dashicons dashicons-forms"></span> Choisissez le type de sauvegarde</h2>
        <div class="eg-backup-types-grid">
            <label class="eg-type-card active" for="eg-type-core">
                <input type="radio" name="eg_backup_type" id="eg-type-core" value="core" checked />
                <div class="eg-type-card-content">
                    <div class="eg-type-icon"><span class="dashicons dashicons-database"></span></div>
                    <h3>Sauvegarde Cœur du Site <span class="eg-badge-rec">Recommandé</span></h3>
                    <p>Base de données intégrale, thèmes actifs/enfants, réglages Customizer et manifeste des extensions.</p>
                    <div class="eg-type-footer">
                        <span class="eg-badge-speed"><span class="dashicons dashicons-clock"></span> ~15 secondes</span>
                        <span class="eg-badge-size-est">< 30 Mo</span>
                    </div>
                </div>
            </label>

            <label class="eg-type-card" for="eg-type-media">
                <input type="radio" name="eg_backup_type" id="eg-type-media" value="media" />
                <div class="eg-type-card-content">
                    <div class="eg-type-icon"><span class="dashicons dashicons-images-alt2"></span></div>
                    <h3>Médias, Galeries & Documents</h3>
                    <p>Photos, galeries NextGEN, médiathèque WordPress et documents déclarés par vos extensions métier.</p>
                    <div class="eg-type-footer">
                        <span class="eg-badge-speed"><span class="dashicons dashicons-performance"></span> Mode Store (0 compression CPU)</span>
                    </div>
                </div>
            </label>

            <label class="eg-type-card" for="eg-type-full">
                <input type="radio" name="eg_backup_type" id="eg-type-full" value="full" />
                <div class="eg-type-card-content">
                    <div class="eg-type-icon"><span class="dashicons dashicons-portfolio"></span></div>
                    <h3>Sauvegarde Complète (Full)</h3>
                    <p>Combine le Cœur, la base de données intégrale et tous les fichiers médias/documents sélectionnés.</p>
                    <div class="eg-type-footer">
                        <span class="eg-badge-info">Archive consolidée</span>
                    </div>
                </div>
            </label>
        </div>
    </div>

    <!-- Section Options Médias & Fichiers hors BDD (affichée pour 'media' et 'full') -->
    <div class="eg-card" id="eg-media-options-card" style="display: none;">
        <h2><span class="dashicons dashicons-category"></span> Sélection fine des médias et documents</h2>
        
        <!-- Option d'exclusion des miniatures -->
        <div class="eg-thumbs-option-box">
            <label class="eg-checkbox-label">
                <input type="checkbox" id="eg-exclude-thumbs-cb" name="eg_exclude_thumbs" value="1" checked />
                <strong>Exclure les miniatures et vignettes générées (fortement recommandé)</strong>
            </label>
            <p class="description">
                Préserve uniquement les photos et documents originaux en ignorant les déclinaisons redimensionnées (suffixes <code>-150x150</code>, <code>-scaled</code>, dossiers <code>thumbs/</code> de NextGEN).
                Réduit d'environ <strong>85 %</strong> le nombre de fichiers et évite des heures d'archivage inutile. Les vignettes peuvent être régénérées en 1 clic à la restauration.
            </p>
        </div>

        <div class="eg-tree-actions">
            <button type="button" class="button" id="eg-scan-btn">
                <span class="dashicons dashicons-update"></span> Actualiser l'inventaire
            </button>
            <button type="button" class="button" id="eg-select-recommended-btn">
                <span class="dashicons dashicons-yes-alt"></span> Sélection recommandée
            </button>
            <button type="button" class="button" id="eg-select-all-btn">Tout cocher</button>
            <button type="button" class="button" id="eg-deselect-all-btn">Tout décocher</button>
        </div>

        <div id="eg-tree-loading" class="eg-tree-loading" style="display: none;">
            <span class="spinner is-active"></span> Analyse de l'arborescence et calcul des volumétries en cours...
        </div>

        <div id="eg-file-tree-container" class="eg-file-tree-container">
            <p class="eg-placeholder-text">Cliquez sur « Actualiser l'inventaire » pour afficher les répertoires détectés.</p>
        </div>
    </div>

    <!-- Section Lancement -->
    <div class="eg-card eg-action-card">
        <h2><span class="dashicons dashicons-cloud-saved"></span> Lancer la sauvegarde</h2>
        <p id="eg-action-description">Sauvegarde ultra-rapide de la configuration, des réglages et de la base de données complète.</p>

        <div class="eg-backup-cta">
            <button type="button" class="button button-primary button-hero" id="eg-start-backup-btn">
                <span class="dashicons dashicons-shield"></span> Démarrer la sauvegarde maintenant
            </button>
        </div>

        <!-- Zone de progression en direct -->
        <div id="eg-progress-container" class="eg-progress-container" style="display: none;">
            <div class="eg-steps-indicator" id="eg-steps-indicator">
                <div class="eg-step" id="step-init"><span class="step-num">1</span> Initialisation</div>
                <div class="eg-step" id="step-db"><span class="step-num">2</span> Base de données</div>
                <div class="eg-step" id="step-files"><span class="step-num">3</span> Fichiers</div>
                <div class="eg-step" id="step-manifest"><span class="step-num">4</span> Finalisation</div>
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
