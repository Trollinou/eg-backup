/**
 * Gestion du scan de fichiers et du processus d'exportation de sauvegarde modulaire (Vanilla TypeScript).
 */

import { $, $$, escapeHtml, wpAjax, extractErrorMessage } from './_common';

document.addEventListener('DOMContentLoaded', () => {
    if (typeof egBackupData === 'undefined') {
        return;
    }

    const { ajaxUrl, nonce } = egBackupData;

    // Éléments UI du type de sauvegarde
    const typeCards = $$<HTMLLabelElement>('.eg-type-card');
    const mediaOptionsCard = $<HTMLElement>('#eg-media-options-card');
    const actionDescription = $<HTMLElement>('#eg-action-description');
    const excludeThumbsCb = $<HTMLInputElement>('#eg-exclude-thumbs-cb');

    // Éléments explorateur
    const fileTreeContainer = $<HTMLDivElement>('#eg-file-tree-container');
    const treeLoading = $<HTMLElement>('#eg-tree-loading');
    const scanBtn = $<HTMLButtonElement>('#eg-scan-btn');
    const selectAllBtn = $<HTMLButtonElement>('#eg-select-all-btn');
    const deselectAllBtn = $<HTMLButtonElement>('#eg-deselect-all-btn');
    const selectRecBtn = $<HTMLButtonElement>('#eg-select-recommended-btn');

    // Éléments progression
    const startBackupBtn = $<HTMLButtonElement>('#eg-start-backup-btn');
    const progressContainer = $<HTMLElement>('#eg-progress-container');
    const progressBar = $<HTMLElement>('#eg-progress-bar');
    const progressStatus = $<HTMLElement>('#eg-progress-status');
    const logConsole = $<HTMLElement>('#eg-log-console');
    const successBox = $<HTMLElement>('#eg-success-box');
    const successDetails = $<HTMLElement>('#eg-success-details');
    const downloadLink = $<HTMLAnchorElement>('#eg-download-link');

    let currentBackupType = 'core';
    let cachedCategorizedData: any = null;

    // Changement de type de sauvegarde
    typeCards.forEach(card => {
        const radio = card.querySelector<HTMLInputElement>('input[type="radio"]');
        if (radio) {
            radio.addEventListener('change', () => {
                if (radio.checked) {
                    currentBackupType = radio.value;
                    typeCards.forEach(c => c.classList.remove('active'));
                    card.classList.add('active');
                    updateTypeDisplay(currentBackupType);
                }
            });
        }
    });

    function updateTypeDisplay(type: string): void {
        if (!actionDescription) return;

        if (type === 'core') {
            if (mediaOptionsCard) mediaOptionsCard.style.display = 'none';
            actionDescription.textContent = 'Sauvegarde ultra-rapide de la configuration, des réglages et de la base de données intégrale (~15s).';
        } else if (type === 'media') {
            if (mediaOptionsCard) mediaOptionsCard.style.display = 'block';
            actionDescription.textContent = 'Sauvegarde dédiée des médias, galeries NextGEN et documents d\'extensions (Mode Store, 0 compression CPU).';
            if (!cachedCategorizedData) {
                loadDirectoryTree();
            }
        } else if (type === 'full') {
            if (mediaOptionsCard) mediaOptionsCard.style.display = 'block';
            actionDescription.textContent = 'Sauvegarde intégrale combinant la base de données, la configuration et tous les médias/documents choisis.';
            if (!cachedCategorizedData) {
                loadDirectoryTree();
            }
        }
    }

    // Clic sur "Actualiser l'inventaire"
    scanBtn?.addEventListener('click', () => {
        loadDirectoryTree();
    });

    // Écouteur sur la case exclusion miniatures pour recalculer les badges
    excludeThumbsCb?.addEventListener('change', () => {
        if (cachedCategorizedData) {
            renderCategorizedHtml(cachedCategorizedData);
        }
    });

    async function loadDirectoryTree(): Promise<void> {
        if (!fileTreeContainer) return;

        if (treeLoading) treeLoading.style.display = 'block';
        fileTreeContainer.innerHTML = '';

        try {
            const response = await wpAjax<{
                tree: any[];
                categorized: any;
                registered_locations: any[];
                is_cli_zip: boolean;
            }>(ajaxUrl, {
                action: 'eg_backup_scan_files',
                nonce,
            });

            if (treeLoading) treeLoading.style.display = 'none';

            if (response.success && response.data) {
                cachedCategorizedData = response.data;
                renderCategorizedHtml(response.data);
            } else {
                fileTreeContainer.innerHTML = '<p class="eg-log-error">Impossible d\'analyser les données de wp-content.</p>';
            }
        } catch (error: any) {
            if (treeLoading) treeLoading.style.display = 'none';
            fileTreeContainer.innerHTML = '<p class="eg-log-error">Erreur de connexion au serveur.</p>';
        }
    }

    function renderCategorizedHtml(data: { categorized: any; registered_locations: any[]; is_cli_zip: boolean }): void {
        if (!fileTreeContainer) return;

        const cat = data.categorized;
        const excludeThumbs = excludeThumbsCb?.checked ?? true;
        let html = '';

        // 1. Médiathèque WordPress standard
        if (cat.wp_media && cat.wp_media.length > 0) {
            html += '<div class="eg-cat-group">';
            html += '<div class="eg-cat-header">';
            html += '<h4><span class="dashicons dashicons-admin-media"></span> 1. Médiathèque WordPress standard (wp-content/uploads/)</h4>';
            html += '<span class="description">Dossiers d\'uploads standards classés par années</span>';
            html += '</div>';
            html += '<ul class="eg-tree-list" style="padding: 10px 18px;">';

            for (const item of cat.wp_media) {
                const sizeText = excludeThumbs && item.size_no_thumbs_human ? item.size_no_thumbs_human : item.size_human;
                const countText = excludeThumbs && item.files_no_thumbs !== undefined ? `${item.files_no_thumbs} originaux` : `${item.files_count} fichiers`;

                html += '<li class="eg-tree-item">';
                html += '<label class="eg-tree-label">';
                html += `<input type="checkbox" name="eg_selected_folders[]" class="eg-folder-checkbox" value="${escapeHtml(item.relative_path)}" data-recommended="${item.is_recommended ? '1' : '0'}" />`;
                html += '<span class="dashicons dashicons-category"></span>';
                html += `<strong>${escapeHtml(item.name)}/</strong> `;
                html += `<span class="eg-badge-size">${escapeHtml(sizeText)}</span> `;
                html += `<span class="eg-badge-files">${escapeHtml(countText)}</span>`;
                html += '</label>';
                html += '</li>';
            }
            html += '</ul></div>';
        }

        // 2. Photothèques et Galeries tierces (NextGEN Gallery, etc.)
        if (cat.galleries && cat.galleries.length > 0) {
            html += '<div class="eg-cat-group">';
            html += '<div class="eg-cat-header">';
            html += '<h4><span class="dashicons dashicons-format-gallery"></span> 2. Galeries Photos & Photothèques (NextGEN Gallery, etc.)</h4>';
            html += '<span class="description">Dossiers dédiés de galeries photos</span>';
            html += '</div>';
            html += '<ul class="eg-tree-list" style="padding: 10px 18px;">';

            for (const item of cat.galleries) {
                const sizeText = excludeThumbs && item.size_no_thumbs_human ? item.size_no_thumbs_human : item.size_human;
                const countText = excludeThumbs && item.files_no_thumbs !== undefined ? `${item.files_no_thumbs} originaux` : `${item.files_count} fichiers`;

                html += '<li class="eg-tree-item">';
                html += '<label class="eg-tree-label">';
                html += `<input type="checkbox" name="eg_selected_folders[]" class="eg-folder-checkbox" value="${escapeHtml(item.relative_path)}" data-recommended="1" checked="checked" />`;
                html += '<span class="dashicons dashicons-images-alt"></span>';
                html += `<strong>${escapeHtml(item.name)}</strong> `;
                html += `<span class="eg-badge-size">${escapeHtml(sizeText)}</span> `;
                html += `<span class="eg-badge-files">${escapeHtml(countText)}</span> `;
                html += '<span class="eg-badge-rec">Recommandé</span>';
                html += '</label>';
                html += '</li>';
            }
            html += '</ul></div>';
        }

        // 3. Documents & Emplacements enregistrés via hook (ex: Plugins de Club)
        const regLocs = Object.values(cat.registered_locations || {});
        if (regLocs.length > 0) {
            html += '<div class="eg-cat-group">';
            html += '<div class="eg-cat-header">';
            html += '<h4><span class="dashicons dashicons-media-document"></span> 3. Documents & Données déclarées (Plugins Métier & Extensions)</h4>';
            html += '<span class="description">Déclarés automatiquement via l\'API <code>eg_backup_registered_locations</code></span>';
            html += '</div>';
            html += '<ul class="eg-tree-list" style="padding: 10px 18px;">';

            for (const loc of regLocs as any[]) {
                if (!loc.exists) continue;
                const checked = loc.recommended ? 'checked="checked"' : '';

                html += '<li class="eg-tree-item">';
                html += '<label class="eg-tree-label">';
                html += `<input type="checkbox" name="eg_selected_folders[]" class="eg-folder-checkbox" value="${escapeHtml(loc.relative_path)}" data-recommended="${loc.recommended ? '1' : '0'}" ${checked} />`;
                html += '<span class="dashicons dashicons-portfolio"></span>';
                html += `<strong>${escapeHtml(loc.label)}</strong> `;
                html += `<span class="eg-plugin-badge">${escapeHtml(loc.plugin_name)}</span> `;
                html += `<span class="eg-badge-size">${escapeHtml(loc.size_human)}</span> `;
                html += `<span class="eg-badge-files">${escapeHtml(loc.files_count)} fichiers</span> `;
                if (loc.description) {
                    html += `<small class="description" style="margin-left: 8px;">(${escapeHtml(loc.description)})</small>`;
                }
                html += '</label>';
                html += '</li>';
            }
            html += '</ul></div>';
        }

        // 4. Optimiseurs et backups secondaires (décochés par défaut)
        if (cat.optimizers && cat.optimizers.length > 0) {
            html += '<div class="eg-cat-group">';
            html += '<div class="eg-cat-header">';
            html += '<h4><span class="dashicons dashicons-warning"></span> 4. Caches d\'optimiseurs d\'images (Optionnel)</h4>';
            html += '<span class="description">Sauvegardes de conversion d\'images originales</span>';
            html += '</div>';
            html += '<ul class="eg-tree-list" style="padding: 10px 18px;">';

            for (const item of cat.optimizers) {
                html += '<li class="eg-tree-item">';
                html += '<label class="eg-tree-label">';
                html += `<input type="checkbox" name="eg_selected_folders[]" class="eg-folder-checkbox" value="${escapeHtml(item.relative_path)}" data-recommended="0" />`;
                html += '<span class="dashicons dashicons-backup"></span>';
                html += `<strong>${escapeHtml(item.name)}/</strong> `;
                html += `<span class="eg-badge-size">${escapeHtml(item.size_human)}</span> `;
                html += `<span class="eg-badge-files">${escapeHtml(item.files_count)} fichiers</span>`;
                html += '</label>';
                html += '</li>';
            }
            html += '</ul></div>';
        }

        if (!html) {
            html = '<p class="eg-placeholder-text">Aucun dossier de médias spécifique trouvé dans wp-content.</p>';
        }

        fileTreeContainer.innerHTML = html;
    }

    // Actions de sélection rapide
    selectAllBtn?.addEventListener('click', () => {
        $$<HTMLInputElement>('.eg-folder-checkbox').forEach(cb => { cb.checked = true; });
    });

    deselectAllBtn?.addEventListener('click', () => {
        $$<HTMLInputElement>('.eg-folder-checkbox').forEach(cb => { cb.checked = false; });
    });

    selectRecBtn?.addEventListener('click', () => {
        $$<HTMLInputElement>('.eg-folder-checkbox').forEach(cb => {
            const isRec = cb.getAttribute('data-recommended') === '1';
            cb.checked = isRec;
        });
    });

    // Lancement de la sauvegarde
    startBackupBtn?.addEventListener('click', async () => {
        const selectedFolders: string[] = $$<HTMLInputElement>('.eg-folder-checkbox:checked')
            .map(cb => cb.value);

        const excludeThumbs = excludeThumbsCb?.checked ? 1 : 0;

        startBackupBtn.disabled = true;
        startBackupBtn.classList.add('updating-message');

        if (progressContainer) {
            progressContainer.style.display = 'block';
            progressContainer.style.opacity = '1';
        }
        if (successBox) successBox.style.display = 'none';
        if (logConsole) logConsole.innerHTML = '';

        logMessage('info', `Démarrage de la sauvegarde [Type : ${currentBackupType.toUpperCase()}]...`);
        updateProgress(5, 'Initialisation du travail...');
        activateStep('step-init');

        // Étape 1 : Start
        try {
            const response = await wpAjax<{
                job_id: string;
                total_tables: number;
                skip_db: boolean;
                message?: string;
            }>(ajaxUrl, {
                action: 'eg_backup_start',
                nonce,
                backup_type: currentBackupType,
                exclude_thumbs: excludeThumbs,
                selected_folders: selectedFolders,
            });

            if (!response.success || !response.data) {
                handleError(extractErrorMessage(response, 'Erreur lors de l\'initialisation.'));
                return;
            }

            const { job_id: jobId, total_tables: totalTables, skip_db: skipDb } = response.data;
            markStepDone('step-init');

            if (skipDb) {
                logMessage('info', 'Étape base de données ignorée (sauvegarde de médias seuls).');
                markStepDone('step-db');
                activateStep('step-files');
                await prepareFilesStep(jobId, selectedFolders);
            } else {
                logMessage('info', `Initialisation réussie. Nombre de tables BDD à sauvegarder : ${totalTables}`);
                activateStep('step-db');
                await processDbStep(jobId, 0, 0, totalTables, selectedFolders);
            }
        } catch (err: any) {
            handleError(`Erreur de communication lors du démarrage : ${err.message || err}`);
        }
    });

    async function processDbStep(
        jobId: string,
        tableIndex: number,
        rowOffset: number,
        totalTables: number,
        selectedFolders: string[]
    ): Promise<void> {
        try {
            const response = await wpAjax<{
                progress: number;
                message: string;
                current_table: string;
                db_finished: boolean;
                table_index: number;
                row_offset: number;
            }>(ajaxUrl, {
                action: 'eg_backup_step_db',
                nonce,
                job_id: jobId,
                table_index: tableIndex,
                row_offset: rowOffset,
            });

            if (!response.success || !response.data) {
                handleError(extractErrorMessage(response, 'Erreur lors de l\'export BDD.'));
                return;
            }

            const data = response.data;
            const dbProgress = Math.min(50, Math.round(5 + (data.progress * 0.45)));
            updateProgress(dbProgress, data.message);

            if (data.current_table && rowOffset === 0) {
                logMessage('info', `Exportation table : ${data.current_table}`);
            }

            if (data.db_finished) {
                logMessage('success', 'Base de données exportée avec succès.');
                markStepDone('step-db');
                activateStep('step-files');

                // Étape 3 : Fichiers
                await prepareFilesStep(jobId, selectedFolders);
            } else {
                await processDbStep(jobId, data.table_index, data.row_offset, totalTables, selectedFolders);
            }
        } catch (err: any) {
            handleError(`Erreur réseau lors de l'export BDD : ${err.message || err}`);
        }
    }

    async function prepareFilesStep(jobId: string, selectedFolders: string[]): Promise<void> {
        updateProgress(52, 'Inventaire et archivage des fichiers...');
        logMessage('info', 'Analyse et indexation des données à sauvegarder...');

        try {
            const response = await wpAjax<{
                total_files: number;
                cli_completed: boolean;
                message: string;
            }>(ajaxUrl, {
                action: 'eg_backup_prepare_files',
                nonce,
                job_id: jobId,
            });

            if (!response.success || !response.data) {
                handleError(extractErrorMessage(response, 'Erreur lors de l\'indexation des fichiers.'));
                return;
            }

            const { total_files: totalFiles, cli_completed: cliCompleted, message } = response.data;
            logMessage('info', message);

            // Si le moteur binaire CLI a tout archivé en une seule passe instantanée
            if (cliCompleted) {
                logMessage('success', 'Archivage des fichiers finalisé via le moteur binaire CLI (0 compression CPU).');
                markStepDone('step-files');
                activateStep('step-manifest');
                await processFinalizeStep(jobId);
                return;
            }

            if (totalFiles === 0) {
                logMessage('info', 'Aucun fichier externe à archiver.');
                markStepDone('step-files');
                activateStep('step-manifest');
                await processFinalizeStep(jobId);
            } else {
                await processFilesChunkStep(jobId, 0, totalFiles);
            }
        } catch (err: any) {
            handleError(`Erreur réseau lors de l'indexation des fichiers : ${err.message || err}`);
        }
    }

    let filesRetryCount = 0;
    const MAX_FILES_RETRIES = 3;

    async function processFilesChunkStep(jobId: string, offset: number, totalFiles: number): Promise<void> {
        try {
            const response = await wpAjax<{
                progress: number;
                message: string;
                finished: boolean;
                offset: number;
            }>(ajaxUrl, {
                action: 'eg_backup_step_files',
                nonce,
                job_id: jobId,
                offset,
            });

            filesRetryCount = 0;

            if (!response.success || !response.data) {
                handleError(extractErrorMessage(response, 'Erreur lors de l\'ajout des fichiers au ZIP.'));
                return;
            }

            const data = response.data;
            const fileOverallPercent = Math.min(90, Math.round(52 + (data.progress * 0.38)));
            updateProgress(fileOverallPercent, data.message);
            logMessage('info', data.message);

            if (data.finished) {
                logMessage('success', 'Tous les fichiers ont été intégrés à l\'archive.');
                markStepDone('step-files');
                activateStep('step-manifest');
                await processFinalizeStep(jobId);
            } else {
                await processFilesChunkStep(jobId, data.offset, totalFiles);
            }
        } catch (err: any) {
            if (filesRetryCount < MAX_FILES_RETRIES) {
                filesRetryCount++;
                logMessage('warning', `Incident réseau temporaire (lot ${offset}), nouvelle tentative dans 2s (${filesRetryCount}/${MAX_FILES_RETRIES})...`);
                setTimeout(() => {
                    processFilesChunkStep(jobId, offset, totalFiles);
                }, 2000);
                return;
            }
            handleError(`Erreur réseau lors du traitement des fichiers : ${err.message || err}`);
        }
    }

    async function processFinalizeStep(jobId: string): Promise<void> {
        updateProgress(92, 'Finalisation de la sauvegarde...');
        logMessage('info', 'Nettoyage des fichiers temporaires...');

        try {
            const response = await wpAjax<{
                filename: string;
                size_human: string;
                download_url?: string;
                message?: string;
            }>(ajaxUrl, {
                action: 'eg_backup_finalize',
                nonce,
                job_id: jobId,
            });

            if (!response.success || !response.data) {
                handleError(extractErrorMessage(response, 'Erreur lors de la finalisation.'));
                return;
            }

            const data = response.data;
            updateProgress(100, 'Sauvegarde terminée !');
            markStepDone('step-manifest');
            logMessage('success', `Archive ZIP créée : ${data.filename} (${data.size_human})`);

            if (successDetails) {
                successDetails.innerHTML = `Archive créée : <strong>${escapeHtml(data.filename)}</strong> (${escapeHtml(data.size_human)})`;
            }

            let fileDownloadUrl = data.download_url;
            if (!fileDownloadUrl) {
                const dlNonce = egBackupData.downloadNonce || nonce;
                fileDownloadUrl = `admin.php?page=eg-backup-list&action=eg_backup_download&file=${encodeURIComponent(data.filename)}&nonce=${encodeURIComponent(dlNonce)}`;
            }

            if (downloadLink) {
                downloadLink.href = fileDownloadUrl;
            }

            if (successBox) {
                successBox.style.display = 'block';
            }

            if (startBackupBtn) {
                startBackupBtn.disabled = false;
                startBackupBtn.classList.remove('updating-message');
            }
        } catch (err: any) {
            handleError(`Erreur réseau lors de la finalisation du ZIP : ${err.message || err}`);
        }
    }

    // Helpers UI
    function updateProgress(percent: number, statusText?: string): void {
        if (progressBar) {
            progressBar.style.width = `${percent}%`;
            progressBar.style.backgroundColor = '';
        }
        if (statusText && progressStatus) {
            progressStatus.textContent = statusText;
        }
    }

    function activateStep(stepId: string): void {
        $$('.eg-step').forEach(step => step.classList.remove('active'));
        const step = $(`#${stepId}`);
        if (step) step.classList.add('active');
    }

    function markStepDone(stepId: string): void {
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

        if (logConsole) {
            logConsole.appendChild(line);
            logConsole.scrollTop = logConsole.scrollHeight;
        }
    }

    function handleError(msg: string): void {
        logMessage('error', `ERREUR : ${msg}`);
        if (progressStatus) progressStatus.textContent = `Échec : ${msg}`;
        if (progressBar) progressBar.style.backgroundColor = '#ef4444';
        if (startBackupBtn) {
            startBackupBtn.disabled = false;
            startBackupBtn.classList.remove('updating-message');
        }
    }
});
