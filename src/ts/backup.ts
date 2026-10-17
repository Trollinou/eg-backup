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
    let currentJobId: string | null = null;

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

    const stepFilesEl = $<HTMLElement>('#step-files');

    function updateTypeDisplay(type: string): void {
        if (stepFilesEl) {
            const numSpan = stepFilesEl.querySelector('.step-num');
            const numPrefix = numSpan ? numSpan.outerHTML + ' ' : '<span class="step-num">3</span> ';
            if (type === 'core') {
                stepFilesEl.innerHTML = `${numPrefix}Thèmes & Config`;
            } else if (type === 'media') {
                stepFilesEl.innerHTML = `${numPrefix}Médias & Docs`;
            } else {
                stepFilesEl.innerHTML = `${numPrefix}Fichiers & Médias`;
            }
        }

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

    // Initialisation de l'affichage selon le type par défaut
    updateTypeDisplay(currentBackupType);

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

    function formatBytes(bytes: number): string {
        if (!bytes || bytes <= 0) return '0 Ko';
        const units = ['o', 'Ko', 'Mo', 'Go', 'To'];
        const i = Math.floor(Math.log(bytes) / Math.log(1024));
        const size = bytes / Math.pow(1024, i);
        const formatted = size >= 10 || i === 0 ? Math.round(size) : Math.round(size * 10) / 10;
        return `${formatted} ${units[i]}`;
    }

    function updateAllCategorySummaries(): void {
        if (!fileTreeContainer) return;
        const excludeThumbs = excludeThumbsCb?.checked ?? true;
        const catGroups = $$<HTMLElement>('.eg-cat-group', fileTreeContainer);

        catGroups.forEach(group => {
            const checkedBoxes = $$<HTMLInputElement>('.eg-folder-checkbox:checked', group);
            let totalBytes = 0;
            let totalFiles = 0;
            const selectedCount = checkedBoxes.length;

            checkedBoxes.forEach(cb => {
                const sizeStr = excludeThumbs
                    ? (cb.getAttribute('data-size-no-thumbs') || cb.getAttribute('data-size') || '0')
                    : (cb.getAttribute('data-size') || '0');
                const filesStr = excludeThumbs
                    ? (cb.getAttribute('data-files-no-thumbs') || cb.getAttribute('data-files') || '0')
                    : (cb.getAttribute('data-files') || '0');

                const size = parseInt(sizeStr, 10);
                const files = parseInt(filesStr, 10);

                totalBytes += isNaN(size) ? 0 : size;
                totalFiles += isNaN(files) ? 0 : files;
            });

            const badge = group.querySelector<HTMLElement>('.eg-cat-selection-badge');
            if (!badge) return;

            if (selectedCount === 0) {
                badge.className = 'eg-cat-selection-badge eg-cat-empty';
                badge.innerHTML = '<span class="dashicons dashicons-minus"></span> <span class="eg-cat-badge-text">Sélection : 0 Ko (0 dossier)</span>';
            } else {
                const filesLabel = excludeThumbs ? 'originaux' : 'fichiers';
                const foldersLabel = selectedCount > 1 ? 'dossiers' : 'dossier';
                badge.className = 'eg-cat-selection-badge eg-cat-active';
                badge.innerHTML = `<span class="dashicons dashicons-yes-alt"></span> <span class="eg-cat-badge-text">Sélection : <strong>${formatBytes(totalBytes)}</strong> <small>(${selectedCount} ${foldersLabel}, ${totalFiles} ${filesLabel})</small></span>`;
            }
        });
    }

    function renderCategorizedHtml(data: { categorized: any; registered_locations: any[]; is_cli_zip: boolean }): void {
        if (!fileTreeContainer) return;

        const cat = data.categorized;
        const excludeThumbs = excludeThumbsCb?.checked ?? true;
        let html = '';

        // 1. Médiathèque WordPress standard
        if (cat.wp_media && cat.wp_media.length > 0) {
            html += '<div class="eg-cat-group" data-cat-id="wp_media">';
            html += '<div class="eg-cat-header">';
            html += '<div class="eg-cat-header-title">';
            html += '<h4><span class="dashicons dashicons-admin-media"></span> 1. Médiathèque WordPress standard (wp-content/uploads/)</h4>';
            html += '<span class="description">Dossiers d\'uploads standards classés par années</span>';
            html += '</div>';
            html += '<div class="eg-cat-header-summary">';
            html += '<span class="eg-cat-selection-badge eg-cat-empty"><span class="dashicons dashicons-minus"></span> <span class="eg-cat-badge-text">Sélection : 0 Ko (0 dossier)</span></span>';
            html += '</div>';
            html += '</div>';
            html += '<ul class="eg-tree-list" style="padding: 10px 18px;">';

            for (const item of cat.wp_media) {
                const sizeText = excludeThumbs && item.size_no_thumbs_human ? item.size_no_thumbs_human : item.size_human;
                const countText = excludeThumbs && item.files_no_thumbs !== undefined ? `${item.files_no_thumbs} originaux` : `${item.files_count} fichiers`;
                const sizeBytes = item.size || 0;
                const sizeNoThumbs = item.size_no_thumbs !== undefined ? item.size_no_thumbs : sizeBytes;
                const filesCount = item.files_count || 0;
                const filesNoThumbs = item.files_no_thumbs !== undefined ? item.files_no_thumbs : filesCount;

                html += '<li class="eg-tree-item">';
                html += '<label class="eg-tree-label">';
                html += `<input type="checkbox" name="eg_selected_folders[]" class="eg-folder-checkbox" value="${escapeHtml(item.relative_path)}" data-recommended="${item.is_recommended ? '1' : '0'}" data-size="${sizeBytes}" data-size-no-thumbs="${sizeNoThumbs}" data-files="${filesCount}" data-files-no-thumbs="${filesNoThumbs}" />`;
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
            html += '<div class="eg-cat-group" data-cat-id="galleries">';
            html += '<div class="eg-cat-header">';
            html += '<div class="eg-cat-header-title">';
            html += '<h4><span class="dashicons dashicons-format-gallery"></span> 2. Galeries Photos & Photothèques (NextGEN Gallery, etc.)</h4>';
            html += '<span class="description">Dossiers dédiés de galeries photos</span>';
            html += '</div>';
            html += '<div class="eg-cat-header-summary">';
            html += '<span class="eg-cat-selection-badge eg-cat-empty"><span class="dashicons dashicons-minus"></span> <span class="eg-cat-badge-text">Sélection : 0 Ko (0 dossier)</span></span>';
            html += '</div>';
            html += '</div>';
            html += '<ul class="eg-tree-list" style="padding: 10px 18px;">';

            for (const item of cat.galleries) {
                const sizeText = excludeThumbs && item.size_no_thumbs_human ? item.size_no_thumbs_human : item.size_human;
                const countText = excludeThumbs && item.files_no_thumbs !== undefined ? `${item.files_no_thumbs} originaux` : `${item.files_count} fichiers`;
                const sizeBytes = item.size || 0;
                const sizeNoThumbs = item.size_no_thumbs !== undefined ? item.size_no_thumbs : sizeBytes;
                const filesCount = item.files_count || 0;
                const filesNoThumbs = item.files_no_thumbs !== undefined ? item.files_no_thumbs : filesCount;

                html += '<li class="eg-tree-item">';
                html += '<label class="eg-tree-label">';
                html += `<input type="checkbox" name="eg_selected_folders[]" class="eg-folder-checkbox" value="${escapeHtml(item.relative_path)}" data-recommended="1" checked="checked" data-size="${sizeBytes}" data-size-no-thumbs="${sizeNoThumbs}" data-files="${filesCount}" data-files-no-thumbs="${filesNoThumbs}" />`;
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
            html += '<div class="eg-cat-group" data-cat-id="registered_locations">';
            html += '<div class="eg-cat-header">';
            html += '<div class="eg-cat-header-title">';
            html += '<h4><span class="dashicons dashicons-media-document"></span> 3. Documents & Données déclarées (Plugins Métier & Extensions)</h4>';
            html += '<span class="description">Déclarés automatiquement via l\'API <code>eg_backup_registered_locations</code></span>';
            html += '</div>';
            html += '<div class="eg-cat-header-summary">';
            html += '<span class="eg-cat-selection-badge eg-cat-empty"><span class="dashicons dashicons-minus"></span> <span class="eg-cat-badge-text">Sélection : 0 Ko (0 dossier)</span></span>';
            html += '</div>';
            html += '</div>';
            html += '<ul class="eg-tree-list" style="padding: 10px 18px;">';

            for (const loc of regLocs as any[]) {
                const exists = Boolean(loc.exists);
                const checked = (exists && loc.recommended) ? 'checked="checked"' : '';
                const disabledAttr = !exists ? 'disabled="disabled"' : '';
                const itemClass = !exists ? 'eg-tree-item eg-tree-item-muted' : 'eg-tree-item';
                const sizeBytes = loc.size || 0;
                const filesCount = loc.files_count || 0;

                html += `<li class="${itemClass}">`;
                html += '<label class="eg-tree-label">';
                html += `<input type="checkbox" name="eg_selected_folders[]" class="eg-folder-checkbox" value="${escapeHtml(loc.relative_path)}" data-recommended="${loc.recommended ? '1' : '0'}" data-size="${sizeBytes}" data-size-no-thumbs="${sizeBytes}" data-files="${filesCount}" data-files-no-thumbs="${filesCount}" ${checked} ${disabledAttr} />`;
                html += '<span class="dashicons dashicons-portfolio"></span>';
                html += `<strong>${escapeHtml(loc.label)}</strong> `;
                html += `<span class="eg-plugin-badge">${escapeHtml(loc.plugin_name)}</span> `;

                if (exists) {
                    html += `<span class="eg-badge-size">${escapeHtml(loc.size_human)}</span> `;
                    html += `<span class="eg-badge-files">${escapeHtml(loc.files_count)} fichiers</span> `;
                } else {
                    html += '<span class="eg-badge-files eg-badge-not-created">Dossier non créé sur le disque (0 Ko)</span> ';
                }

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
            html += '<div class="eg-cat-group" data-cat-id="optimizers">';
            html += '<div class="eg-cat-header">';
            html += '<div class="eg-cat-header-title">';
            html += '<h4><span class="dashicons dashicons-warning"></span> 4. Caches d\'optimiseurs d\'images (Optionnel)</h4>';
            html += '<span class="description">Sauvegardes de conversion d\'images originales</span>';
            html += '</div>';
            html += '<div class="eg-cat-header-summary">';
            html += '<span class="eg-cat-selection-badge eg-cat-empty"><span class="dashicons dashicons-minus"></span> <span class="eg-cat-badge-text">Sélection : 0 Ko (0 dossier)</span></span>';
            html += '</div>';
            html += '</div>';
            html += '<ul class="eg-tree-list" style="padding: 10px 18px;">';

            for (const item of cat.optimizers) {
                const sizeBytes = item.size || 0;
                const filesCount = item.files_count || 0;

                html += '<li class="eg-tree-item">';
                html += '<label class="eg-tree-label">';
                html += `<input type="checkbox" name="eg_selected_folders[]" class="eg-folder-checkbox" value="${escapeHtml(item.relative_path)}" data-recommended="0" data-size="${sizeBytes}" data-size-no-thumbs="${sizeBytes}" data-files="${filesCount}" data-files-no-thumbs="${filesCount}" />`;
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
        updateAllCategorySummaries();
    }

    // Écouteur délégué sur les changements de sélection pour recalculer en direct les totaux
    fileTreeContainer?.addEventListener('change', (e: Event) => {
        const target = e.target as HTMLElement;
        if (target && target.classList.contains('eg-folder-checkbox')) {
            updateAllCategorySummaries();
        }
    });

    // Actions de sélection rapide
    selectAllBtn?.addEventListener('click', () => {
        $$<HTMLInputElement>('.eg-folder-checkbox:not(:disabled)').forEach(cb => { cb.checked = true; });
        updateAllCategorySummaries();
    });

    deselectAllBtn?.addEventListener('click', () => {
        $$<HTMLInputElement>('.eg-folder-checkbox').forEach(cb => { cb.checked = false; });
        updateAllCategorySummaries();
    });

    selectRecBtn?.addEventListener('click', () => {
        $$<HTMLInputElement>('.eg-folder-checkbox:not(:disabled)').forEach(cb => {
            const isRec = cb.getAttribute('data-recommended') === '1';
            cb.checked = isRec;
        });
        updateAllCategorySummaries();
    });

    // Lancement de la sauvegarde
    startBackupBtn?.addEventListener('click', async () => {
        // Pour la sauvegarde Cœur, ne sélectionner aucun dossier média
        const selectedFolders: string[] = currentBackupType === 'core'
            ? []
            : $$<HTMLInputElement>('.eg-folder-checkbox:checked').map(cb => cb.value);

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
        currentJobId = null;

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
            currentJobId = jobId;
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
        const isCore = currentBackupType === 'core';
        const progressLabel = isCore ? 'Archivage des thèmes actifs et de la configuration...' : 'Inventaire et archivage des fichiers...';
        const logLabel = isCore ? 'Analyse et indexation des thèmes actifs et de la configuration...' : 'Analyse et indexation des données à sauvegarder...';

        updateProgress(52, progressLabel);
        logMessage('info', logLabel);

        try {
            const response = await wpAjax<{
                total_files: number;
                cli_in_progress?: boolean;
                cli_completed?: boolean;
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

            const { total_files: totalFiles, cli_in_progress: cliInProgress, cli_completed: cliCompleted, message } = response.data;
            logMessage('info', message);

            // 1. Si le processus CLI tourne en arrière-plan (mode asynchrone anti-timeout)
            if (cliInProgress) {
                await pollCliZipProgress(jobId, totalFiles);
                return;
            }

            // 2. Si le moteur binaire CLI a déjà tout archivé en direct
            if (cliCompleted) {
                logMessage('success', 'Archivage des fichiers finalisé via le moteur binaire CLI (0 compression CPU).');
                markStepDone('step-files');
                activateStep('step-manifest');
                await processFinalizeStep(jobId);
                return;
            }

            // 3. Fallback PHP ZipArchive ou aucun fichier
            if (totalFiles === 0) {
                logMessage('info', 'Aucun fichier externe à archiver.');
                markStepDone('step-files');
                activateStep('step-manifest');
                await processFinalizeStep(jobId);
            } else {
                await processFilesChunkStep(jobId, 0, totalFiles);
            }
        } catch (err: any) {
            // Mécanisme de résilience : si une interruption réseau ou erreur 500 survient,
            // tenter de vérifier si l'archivage CLI a continué et s'est achevé sur le disque.
            logMessage('warning', 'Interruption réseau ou délai serveur dépassé (HTTP 500). Vérification de l\'archivage sur le disque...');
            const recovered = await tryRecoverCliZip(jobId);
            if (!recovered) {
                handleError(`Erreur réseau lors de l'indexation des fichiers : ${err.message || err}`);
            }
        }
    }

    async function pollCliZipProgress(jobId: string, totalFiles: number): Promise<void> {
        let lastLoggedCount = -1;
        let lastLoggedSize = '';
        let pollCount = 0;

        updateProgress(55, 'Archivage ultra-rapide CLI en cours...');

        return new Promise<void>((resolve) => {
            const timer = setInterval(async () => {
                pollCount++;
                try {
                    const response = await wpAjax<{
                        finished: boolean;
                        success: boolean;
                        size: number;
                        size_human: string;
                        processed_files?: number;
                        last_file?: string;
                        message?: string;
                        error?: string;
                    }>(ajaxUrl, {
                        action: 'eg_backup_poll_cli',
                        nonce,
                        job_id: jobId,
                    });

                    if (response.success && response.data) {
                        const status = response.data;

                        if (status.finished) {
                            clearInterval(timer);
                            if (status.success) {
                                updateProgress(85, `Archivage CLI terminé (${status.size_human})`);
                                logMessage('success', status.message || `Archivage CLI terminé avec succès (${status.size_human}).`);
                                markStepDone('step-files');
                                activateStep('step-manifest');
                                await processFinalizeStep(jobId);
                                resolve();
                            } else {
                                handleError(status.error || 'Erreur lors de l\'archivage CLI.');
                                resolve();
                            }
                            return;
                        }

                        // Archivage toujours en cours : mise à jour continue de la progression
                        const processed = status.processed_files ?? 0;
                        const sizeHuman = status.size_human || '0 Ko';

                        // Calcul dynamique du pourcentage entre 55% et 85%
                        let currentPct = 55;
                        if (totalFiles > 0 && processed > 0) {
                            const ratio = Math.min(1, processed / totalFiles);
                            currentPct = Math.round(55 + (ratio * 30));
                        }

                        let statusLabel = `Archivage CLI en cours (${sizeHuman} écrits)...`;
                        if (totalFiles > 0 && processed > 0) {
                            statusLabel = `Archivage CLI : ${processed} / ${totalFiles} fichiers (${sizeHuman})...`;
                        } else if (processed > 0) {
                            statusLabel = `Archivage CLI : ${processed} fichiers (${sizeHuman})...`;
                        }

                        updateProgress(currentPct, statusLabel);

                        // Journalisation dans la console : dès que de nouveaux fichiers sont ajoutés ou toutes les 6s
                        const hasNewFiles = processed > 0 && processed !== lastLoggedCount && (Math.abs(processed - lastLoggedCount) >= 100 || lastLoggedCount === -1);
                        const hasPeriodicUpdate = pollCount % 3 === 0 && (sizeHuman !== lastLoggedSize || processed !== lastLoggedCount);

                        if (hasNewFiles || hasPeriodicUpdate) {
                            lastLoggedCount = processed;
                            lastLoggedSize = sizeHuman;
                            const fileInfo = status.last_file ? ` [${status.last_file}]` : '';

                            if (totalFiles > 0 && processed > 0) {
                                logMessage('info', `Progression CLI : ${processed} / ${totalFiles} fichiers (${sizeHuman})${fileInfo}`);
                            } else {
                                logMessage('info', `Progression CLI : ${sizeHuman} écrits sur le disque...${fileInfo}`);
                            }
                        }
                    }
                } catch {
                    // Erreur transitoire de polling réseau, nouvelle tentative au prochain cycle
                }
            }, 2000);
        });
    }

    async function tryRecoverCliZip(jobId: string): Promise<boolean> {
        // Tente pendant 3 minutes de vérifier si l'archive sur le disque s'est finalisée avec succès
        for (let i = 0; i < 90; i++) {
            await new Promise(r => setTimeout(r, 2000));
            try {
                const response = await wpAjax<{
                    finished: boolean;
                    success: boolean;
                    size: number;
                    size_human: string;
                    processed_files?: number;
                }>(ajaxUrl, {
                    action: 'eg_backup_poll_cli',
                    nonce,
                    job_id: jobId,
                });

                if (response.success && response.data) {
                    if (response.data.finished && response.data.success) {
                        logMessage('success', `Récupération réussie : Archive ZIP finalisée sur le disque (${response.data.size_human}).`);
                        markStepDone('step-files');
                        activateStep('step-manifest');
                        await processFinalizeStep(jobId);
                        return true;
                    }
                    if (!response.data.finished && (response.data.size > 0 || (response.data.processed_files ?? 0) > 0) && i % 3 === 0) {
                        const fileCount = response.data.processed_files ? `${response.data.processed_files} fichiers - ` : '';
                        logMessage('info', `Archivage en cours sur le serveur : ${fileCount}${response.data.size_human} écrits...`);
                    }
                }
            } catch {
                // Ignore transient network errors
            }
        }
        return false;
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
            currentJobId = null;
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

        // Nettoyage immédiat du dossier temporaire orphelin si le travail a échoué.
        if (currentJobId) {
            const failedJobId = currentJobId;
            currentJobId = null;
            wpAjax(ajaxUrl, {
                action: 'eg_backup_cancel',
                nonce,
                job_id: failedJobId,
            }).catch(() => {
                // Ignore silencieusement en cas d'échec de communication.
            });
        }
    }
});
