/**
 * Assistant de restauration interactif et contextuel (Vanilla TypeScript).
 */

import { $, $$, escapeHtml, wpAjax, extractErrorMessage } from './_common';

document.addEventListener('DOMContentLoaded', () => {
    if (typeof egBackupData === 'undefined') {
        return;
    }

    const { ajaxUrl, nonce } = egBackupData;
    let inspectedBackupFilename = '';
    let inspectedManifest: any = null;
    let isMediaOnly = false;
    let thumbsExcluded = false;
    let restoreToken = '';

    const localBackupSelect = $<HTMLSelectElement>('#eg-local-backup-select');
    const inspectLocalBtn = $<HTMLButtonElement>('#eg-inspect-local-btn');
    const uploadBackupInput = $<HTMLInputElement>('#eg-upload-backup-input');
    const inspectUploadBtn = $<HTMLButtonElement>('#eg-inspect-upload-btn');
    const inspectLoading = $<HTMLElement>('#eg-inspect-loading');
    const restoreManifestCard = $<HTMLElement>('#eg-restore-manifest-card');
    const restoreSourceCard = $<HTMLElement>('#eg-restore-source-card');
    const restoreProgressCard = $<HTMLElement>('#eg-restore-progress-card');
    const manifestMetaGrid = $<HTMLElement>('#eg-manifest-meta-grid');
    const mediaRestoreNotice = $<HTMLElement>('#eg-media-restore-notice');
    const coreRestoreSections = $<HTMLElement>('#eg-core-restore-sections');
    const pluginsAlertBox = $<HTMLElement>('#eg-plugins-alert-box');
    const pluginsTableBody = $<HTMLTableSectionElement>('#eg-plugins-table-body');
    const themeCheckBox = $<HTMLElement>('#eg-theme-check-box');
    const replaceOldUrlInput = $<HTMLInputElement>('#eg-replace-old-url');
    const replaceNewUrlInput = $<HTMLInputElement>('#eg-replace-new-url');
    const startRestoreBtn = $<HTMLButtonElement>('#eg-start-restore-btn');
    const restoreProgressBar = $<HTMLElement>('#eg-restore-progress-bar');
    const restoreStatus = $<HTMLElement>('#eg-restore-status');
    const restoreLogConsole = $<HTMLElement>('#eg-restore-log-console');
    const restoreSuccessBox = $<HTMLElement>('#eg-restore-success-box');
    const restoreSuccessDesc = $<HTMLElement>('#eg-restore-success-desc');
    const restoreThumbsGuide = $<HTMLElement>('#eg-restore-thumbs-guide');
    const restoreMaintNotice = $<HTMLElement>('#eg-restore-maint-notice');

    // Auto-inspection si un fichier est passé dans l'URL (?file=...)
    const urlParams = new URLSearchParams(window.location.search);
    const initialFile = urlParams.get('file');
    if (initialFile && localBackupSelect) {
        localBackupSelect.value = initialFile;
        inspectArchive(initialFile);
    }

    // Clic sur "Analyser cette sauvegarde locale"
    inspectLocalBtn?.addEventListener('click', () => {
        const filename = localBackupSelect?.value;
        if (!filename) {
            alert('Veuillez sélectionner une archive dans la liste.');
            return;
        }
        inspectArchive(filename);
    });

    // Clic sur "Téléverser et analyser"
    inspectUploadBtn?.addEventListener('click', async () => {
        if (!uploadBackupInput?.files || !uploadBackupInput.files[0]) {
            alert('Veuillez choisir un fichier .zip à téléverser.');
            return;
        }

        const formData = new FormData();
        formData.append('action', 'eg_restore_inspect');
        formData.append('nonce', nonce);
        formData.append('backup_file', uploadBackupInput.files[0]);

        if (inspectLoading) inspectLoading.style.display = 'block';
        if (restoreManifestCard) restoreManifestCard.style.display = 'none';

        try {
            const response = await wpAjax(ajaxUrl, formData);
            if (inspectLoading) inspectLoading.style.display = 'none';
            handleInspectionResponse(response);
        } catch (err: any) {
            if (inspectLoading) inspectLoading.style.display = 'none';
            alert(`Erreur lors du téléversement du fichier : ${err.message || err}`);
        }
    });

    async function inspectArchive(filename: string): Promise<void> {
        if (inspectLoading) inspectLoading.style.display = 'block';
        if (restoreManifestCard) restoreManifestCard.style.display = 'none';

        try {
            const response = await wpAjax(ajaxUrl, {
                action: 'eg_restore_inspect',
                nonce,
                filename,
            });

            if (inspectLoading) inspectLoading.style.display = 'none';
            handleInspectionResponse(response);
        } catch (err: any) {
            if (inspectLoading) inspectLoading.style.display = 'none';
            alert(`Erreur réseau lors de l'analyse de la sauvegarde : ${err.message || err}`);
        }
    }

    function handleInspectionResponse(response: any): void {
        if (!response || !response.success || !response.data) {
            alert(extractErrorMessage(response, 'Impossible d\'analyser cette sauvegarde.'));
            return;
        }

        inspectedBackupFilename = response.data.filename;
        inspectedManifest = response.data.manifest;
        const comp = response.data.comparison;

        isMediaOnly = Boolean(comp.is_media_only);
        thumbsExcluded = Boolean(comp.thumbs_excluded);
        const backupType = comp.backup_type || 'full';

        // Remplir métadonnées
        let metaHtml = '';
        metaHtml += `<div class="eg-meta-item"><strong>Fichier</strong><span>${escapeHtml(inspectedBackupFilename)}</span></div>`;
        metaHtml += `<div class="eg-meta-item"><strong>Type d'archive</strong><span>${escapeHtml(backupType.toUpperCase())}</span></div>`;
        metaHtml += `<div class="eg-meta-item"><strong>Date de création</strong><span>${escapeHtml(inspectedManifest.created_at)}</span></div>`;
        metaHtml += `<div class="eg-meta-item"><strong>Version WP source</strong><span>WP ${escapeHtml(inspectedManifest.environment?.version || 'N/A')}</span></div>`;
        if (manifestMetaGrid) manifestMetaGrid.innerHTML = metaHtml;

        // Adaptation selon le type d'archive (Cœur vs Médias)
        if (isMediaOnly) {
            if (mediaRestoreNotice) mediaRestoreNotice.style.display = 'block';
            if (coreRestoreSections) coreRestoreSections.style.display = 'none';
        } else {
            if (mediaRestoreNotice) mediaRestoreNotice.style.display = 'none';
            if (coreRestoreSections) coreRestoreSections.style.display = 'block';

            // Alerte si extensions manquantes
            let alertHtml = '';
            if (comp.missing_plugins_count > 0) {
                alertHtml += '<div class="notice notice-warning inline">';
                alertHtml += `<p><strong>Attention :</strong> ${comp.missing_plugins_count} extension(s) requise(s) dans cette sauvegarde ne sont pas actuellement installée(s) sur ce site. `;
                alertHtml += 'Installez-les dès maintenant ci-dessous avant d\'appliquer la restauration.</p>';
                alertHtml += '</div>';
            } else {
                alertHtml += '<div class="notice notice-success inline">';
                alertHtml += '<p><strong>Parfait :</strong> Toutes les extensions requises sont déjà installées sur ce site !</p>';
                alertHtml += '</div>';
            }
            if (pluginsAlertBox) pluginsAlertBox.innerHTML = alertHtml;

            // Remplir le tableau des plugins
            let rowsHtml = '';
            comp.plugins.forEach((plugin: any) => {
                let statusBadge = '';
                let actionBtn = '';

                if (plugin.status === 'installed') {
                    statusBadge = `<span class="eg-badge-pill eg-badge-installed">Installé (${escapeHtml(plugin.installed_version)})</span>`;
                    actionBtn = '<span class="dashicons dashicons-yes-alt" style="color:#22c55e;"></span> Prêt';
                } else if (plugin.status === 'version_diff') {
                    statusBadge = `<span class="eg-badge-pill eg-badge-diff">Diff. version (${escapeHtml(plugin.installed_version)})</span>`;
                    actionBtn = '<span class="description">Compatible (attention aux écarts majeurs)</span>';
                } else {
                    statusBadge = '<span class="eg-badge-pill eg-badge-missing">Non installé</span>';
                    if (plugin.is_wporg && plugin.slug) {
                        actionBtn = `<button type="button" class="button button-small button-primary eg-btn-install-plugin" data-slug="${escapeHtml(plugin.slug)}">`;
                        actionBtn += '<span class="dashicons dashicons-download"></span> Installer (WP.org)';
                        actionBtn += '</button>';
                    } else {
                        actionBtn = '<span class="description">À réinstaller manuellement (tiers/payant)</span>';
                    }
                }

                rowsHtml += '<tr>';
                rowsHtml += `<td><strong>${escapeHtml(plugin.name)}</strong><br><small class="description">${escapeHtml(plugin.file)}</small></td>`;
                rowsHtml += `<td>${escapeHtml(plugin.required_version || 'N/A')}</td>`;
                rowsHtml += `<td>${statusBadge}</td>`;
                rowsHtml += `<td id="action-cell-${escapeHtml(plugin.slug)}">${actionBtn}</td>`;
                rowsHtml += '</tr>';
            });
            if (pluginsTableBody) pluginsTableBody.innerHTML = rowsHtml;

            // Bilan du thème
            const theme = comp.theme;
            const themeStatus = theme.is_installed
                ? '<span class="eg-badge-pill eg-badge-installed">Installé</span>'
                : '<span class="eg-badge-pill eg-badge-missing">Non installé</span>';

            let themeHtml = '<div style="margin: 16px 0; padding: 12px; background: #fff; border: 1px solid #dcdcde; border-radius: 6px;">';
            themeHtml += `<strong>Thème requis :</strong> ${escapeHtml(theme.name)} (version ${escapeHtml(theme.required_version)}) — État : ${themeStatus}`;
            themeHtml += '</div>';
            if (themeCheckBox) themeCheckBox.innerHTML = themeHtml;

            // Pré-remplir la migration d'URL
            if (replaceOldUrlInput) {
                replaceOldUrlInput.value = inspectedManifest.environment?.siteurl || '';
            }
        }

        if (restoreManifestCard) {
            restoreManifestCard.style.display = 'block';
        }
    }

    // Clic pour installer un plugin depuis WordPress.org en 1-clic (délégation d'événements)
    document.addEventListener('click', async (e: Event) => {
        const target = (e.target as HTMLElement).closest<HTMLButtonElement>('.eg-btn-install-plugin');
        if (!target) return;

        const slug = target.getAttribute('data-slug') || '';
        const cell = $(`#action-cell-${slug}`);

        target.disabled = true;
        target.textContent = 'Installation...';

        try {
            const response = await wpAjax<{ message: string }>(ajaxUrl, {
                action: 'eg_restore_install_plugin',
                nonce,
                slug,
            });

            if (response.success) {
                if (cell) {
                    cell.innerHTML = `<span class="dashicons dashicons-yes-alt" style="color:#22c55e;"></span> ${escapeHtml(response.data?.message || 'Installé')}`;
                    const prevCell = cell.previousElementSibling;
                    if (prevCell) {
                        prevCell.innerHTML = '<span class="eg-badge-pill eg-badge-installed">Installé</span>';
                    }
                }
            } else {
                target.disabled = false;
                target.textContent = 'Réessayer';
                alert(extractErrorMessage(response, 'Erreur lors de l\'installation.'));
            }
        } catch (err: any) {
            target.disabled = false;
            target.textContent = 'Réessayer';
            alert(`Erreur réseau lors de l'installation : ${err.message || err}`);
        }
    });

    // Lancement de la restauration
    startRestoreBtn?.addEventListener('click', async () => {
        const confirmMessage = isMediaOnly
            ? 'Confirmez-vous la réintégration des fichiers médias et documents de cette archive ?'
            : 'ATTENTION : La restauration écrasera la base de données actuelle par celle de la sauvegarde.\n\nÊtes-vous sûr de vouloir continuer ?';

        if (!confirm(confirmMessage)) {
            return;
        }

        if (restoreManifestCard) restoreManifestCard.style.display = 'none';
        if (restoreSourceCard) restoreSourceCard.style.display = 'none';
        if (restoreProgressCard) restoreProgressCard.style.display = 'block';
        if (restoreLogConsole) restoreLogConsole.innerHTML = '';

        if (isMediaOnly && restoreMaintNotice) {
            restoreMaintNotice.style.display = 'none';
        }

        logMessage('info', 'Démarrage du processus de restauration...');
        updateRestoreProgress(5, 'Décompression de l\'archive...');
        activateRestoreStep('rstep-extract');

        const oldUrl = replaceOldUrlInput?.value || '';
        const newUrl = replaceNewUrlInput?.value || '';

        // Étape 1 : Start & Unzip
        try {
            const response = await wpAjax<{
                job_id: string;
                restore_token: string;
                has_sql: boolean;
                is_media_only: boolean;
                message: string;
            }>(ajaxUrl, {
                action: 'eg_restore_start',
                nonce,
                filename: inspectedBackupFilename,
            });

            if (!response || !response.success || !response.data) {
                handleRestoreError(extractErrorMessage(response, 'Erreur lors de l\'extraction.'));
                return;
            }

            const jobId = response.data.job_id;
            restoreToken = response.data.restore_token || '';
            logMessage('success', response.data.message);
            markRestoreStepDone('rstep-extract');

            if (isMediaOnly || !response.data.has_sql) {
                logMessage('info', 'Étape base de données ignorée (archive de fichiers médias).');
                markRestoreStepDone('rstep-db');
                markRestoreStepDone('rstep-replace');
                activateRestoreStep('rstep-files');
                await processRestoreFiles(jobId);
            } else {
                activateRestoreStep('rstep-db');
                await processRestoreDb(jobId, 0, oldUrl, newUrl);
            }
        } catch (err: any) {
            handleRestoreError(`Erreur réseau au lancement de la restauration : ${err.message || err}`);
        }
    });

    async function processRestoreDb(jobId: string, offset: number, oldUrl: string, newUrl: string): Promise<void> {
        try {
            const response = await wpAjax<{
                progress: number;
                message: string;
                eof: boolean;
                offset: number;
            }>(ajaxUrl, {
                action: 'eg_restore_step_db',
                nonce,
                job_id: jobId,
                restore_token: restoreToken,
                offset,
            });

            if (!response.success || !response.data) {
                handleRestoreError(extractErrorMessage(response, 'Erreur lors de l\'import SQL.'), jobId);
                return;
            }

            const data = response.data;
            const dbProgress = Math.min(50, Math.round(5 + (data.progress * 0.45)));
            updateRestoreProgress(dbProgress, data.message);

            if (data.eof) {
                logMessage('success', 'Base de données importée avec succès.');
                markRestoreStepDone('rstep-db');
                activateRestoreStep('rstep-replace');

                // Étape 3 : Search & Replace
                await processRestoreReplace(jobId, oldUrl, newUrl);
            } else {
                await processRestoreDb(jobId, data.offset, oldUrl, newUrl);
            }
        } catch (err: any) {
            handleRestoreError(`Erreur réseau lors de l'import BDD : ${err.message || err}`, jobId);
        }
    }

    async function processRestoreReplace(jobId: string, oldUrl: string, newUrl: string): Promise<void> {
        updateRestoreProgress(55, 'Mise à jour des adresses et liens (Search & Replace)...');

        try {
            const response = await wpAjax<{ message: string }>(ajaxUrl, {
                action: 'eg_restore_step_replace',
                nonce,
                job_id: jobId,
                restore_token: restoreToken,
                old_url: oldUrl,
                new_url: newUrl,
            });

            if (!response.success) {
                handleRestoreError(extractErrorMessage(response, 'Erreur lors du Search & Replace.'), jobId);
                return;
            }

            logMessage('info', response.data?.message || 'Search & Replace terminé.');
            markRestoreStepDone('rstep-replace');
            activateRestoreStep('rstep-files');

            // Étape 4 : Fichiers
            await processRestoreFiles(jobId);
        } catch (err: any) {
            handleRestoreError(`Erreur réseau lors du Search & Replace : ${err.message || err}`, jobId);
        }
    }

    async function processRestoreFiles(jobId: string): Promise<void> {
        updateRestoreProgress(75, 'Restauration des fichiers vers wp-content...');
        logMessage('info', 'Copie des fichiers vers leurs dossiers d\'origine...');

        try {
            const response = await wpAjax<{ message: string }>(ajaxUrl, {
                action: 'eg_restore_step_files',
                nonce,
                job_id: jobId,
                restore_token: restoreToken,
            });

            if (!response.success) {
                handleRestoreError(extractErrorMessage(response, 'Erreur lors de la copie des fichiers.'), jobId);
                return;
            }

            logMessage('success', 'Fichiers restaurés avec succès.');
            markRestoreStepDone('rstep-files');
            activateRestoreStep('rstep-finalize');

            // Étape 5 : Finalisation
            await processRestoreFinalize(jobId);
        } catch (err: any) {
            handleRestoreError(`Erreur réseau lors de la restauration des fichiers : ${err.message || err}`, jobId);
        }
    }

    async function processRestoreFinalize(jobId: string): Promise<void> {
        updateRestoreProgress(95, 'Nettoyage des fichiers temporaires et finalisation...');

        try {
            const response = await wpAjax<{
                message: string;
                is_media_only?: boolean;
                thumbs_excluded?: boolean;
            }>(ajaxUrl, {
                action: 'eg_restore_finalize',
                nonce,
                job_id: jobId,
                restore_token: restoreToken,
            });

            if (!response.success || !response.data) {
                handleRestoreError(extractErrorMessage(response, 'Erreur lors de la finalisation.'), jobId);
                return;
            }

            updateRestoreProgress(100, 'Restauration terminée avec succès !');
            markRestoreStepDone('rstep-finalize');
            logMessage('success', response.data.message);

            if (restoreSuccessDesc) {
                restoreSuccessDesc.textContent = response.data.message;
            }

            if (thumbsExcluded && restoreThumbsGuide) {
                restoreThumbsGuide.style.display = 'block';
            }

            if (restoreSuccessBox) restoreSuccessBox.style.display = 'block';
        } catch (err: any) {
            handleRestoreError(`Erreur réseau lors de la finalisation : ${err.message || err}`, jobId);
        }
    }

    // Helpers UI
    function updateRestoreProgress(percent: number, statusText?: string): void {
        if (restoreProgressBar) {
            restoreProgressBar.style.width = `${percent}%`;
            restoreProgressBar.style.backgroundColor = '';
        }
        if (statusText && restoreStatus) {
            restoreStatus.textContent = statusText;
        }
    }

    function activateRestoreStep(stepId: string): void {
        $$('.eg-step').forEach(step => step.classList.remove('active'));
        const step = $(`#${stepId}`);
        if (step) step.classList.add('active');
    }

    function markRestoreStepDone(stepId: string): void {
        const step = $(`#${stepId}`);
        if (step) {
            step.classList.remove('active');
            step.classList.add('done');
        }
    }

    function logMessage(type: 'info' | 'success' | 'warning' | 'error', text: string): void {
        const time = new Date().toLocaleTimeString();
        const line = document.createElement('div');
        line.className = `log-line log-${type}`;
        line.innerHTML = `[${time}] ${escapeHtml(text)}`;

        if (restoreLogConsole) {
            restoreLogConsole.appendChild(line);
            restoreLogConsole.scrollTop = restoreLogConsole.scrollHeight;
        }
    }

    function handleRestoreError(msg: string, jobId?: any): void {
        logMessage('error', `ERREUR CRITIQUE : ${msg}`);
        if (restoreStatus) restoreStatus.textContent = `Échec : ${msg}`;
        if (restoreProgressBar) restoreProgressBar.style.backgroundColor = '#ef4444';

        if (!$('#eg-restore-error-actions')) {
            const errorActions = document.createElement('div');
            errorActions.id = 'eg-restore-error-actions';
            errorActions.style.marginTop = '15px';
            errorActions.innerHTML = `
                <button type="button" class="button button-secondary" id="eg-disable-maintenance-btn">
                    <span class="dashicons dashicons-admin-tools" style="vertical-align: middle;"></span> Désactiver le mode maintenance
                </button>
            `;

            restoreLogConsole?.after(errorActions);

            const disableBtn = $<HTMLButtonElement>('#eg-disable-maintenance-btn');
            disableBtn?.addEventListener('click', async () => {
                disableBtn.disabled = true;
                disableBtn.textContent = 'Désactivation en cours...';

                try {
                    const response = await wpAjax(ajaxUrl, {
                        action: 'eg_restore_cancel',
                        nonce,
                        job_id: jobId || '',
                        restore_token: restoreToken || '',
                    });

                    const successMsg = extractErrorMessage(response, 'Mode maintenance désactivé.');
                    logMessage('info', successMsg);
                    disableBtn.textContent = 'Mode maintenance désactivé';
                } catch {
                    logMessage('error', 'Impossible de désactiver le mode maintenance via AJAX. Supprimez le fichier .maintenance à la racine de votre site.');
                }
            });
        }
    }
});
