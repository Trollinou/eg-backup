/**
 * Gestion du scan de fichiers et du processus d'exportation de sauvegarde (Vanilla TypeScript).
 */

import { $, $$, escapeHtml, wpAjax, extractErrorMessage } from './_common';

document.addEventListener('DOMContentLoaded', () => {
    if (typeof egBackupData === 'undefined') {
        return;
    }

    const { ajaxUrl, nonce } = egBackupData;

    const fileTreeContainer = $<HTMLDivElement>('#eg-file-tree-container');
    const treeLoading = $<HTMLElement>('#eg-tree-loading');
    const scanBtn = $<HTMLButtonElement>('#eg-scan-btn');
    const selectAllBtn = $<HTMLButtonElement>('#eg-select-all-btn');
    const deselectAllBtn = $<HTMLButtonElement>('#eg-deselect-all-btn');
    const selectRecBtn = $<HTMLButtonElement>('#eg-select-recommended-btn');
    const startBackupBtn = $<HTMLButtonElement>('#eg-start-backup-btn');
    const progressContainer = $<HTMLElement>('#eg-progress-container');
    const progressBar = $<HTMLElement>('#eg-progress-bar');
    const progressStatus = $<HTMLElement>('#eg-progress-status');
    const logConsole = $<HTMLElement>('#eg-log-console');
    const successBox = $<HTMLElement>('#eg-success-box');
    const successDetails = $<HTMLElement>('#eg-success-details');
    const downloadLink = $<HTMLAnchorElement>('#eg-download-link');

    // Auto-chargement de l'arborescence au chargement si sur la page de sauvegarde
    if (fileTreeContainer) {
        loadDirectoryTree();
    }

    // Clic sur "Analyser le dossier wp-content"
    scanBtn?.addEventListener('click', () => {
        loadDirectoryTree();
    });

    async function loadDirectoryTree(): Promise<void> {
        if (!fileTreeContainer) return;

        if (treeLoading) treeLoading.style.display = 'block';
        fileTreeContainer.innerHTML = '';

        try {
            const response = await wpAjax<{ tree: any[] }>(ajaxUrl, {
                action: 'eg_backup_scan_files',
                nonce,
            });

            if (treeLoading) treeLoading.style.display = 'none';

            if (response.success && response.data?.tree) {
                fileTreeContainer.innerHTML = renderTreeHtml(response.data.tree);
            } else {
                fileTreeContainer.innerHTML = '<p class="eg-log-error">Impossible d\'analyser le dossier wp-content.</p>';
            }
        } catch (error: any) {
            if (treeLoading) treeLoading.style.display = 'none';
            fileTreeContainer.innerHTML = '<p class="eg-log-error">Erreur de connexion au serveur.</p>';
        }
    }

    function renderTreeHtml(nodes: any[]): string {
        if (!nodes || !nodes.length) {
            return '<p class="eg-placeholder-text">Aucun dossier personnalisable trouvé dans wp-content.</p>';
        }

        let html = '<ul class="eg-tree-list">';
        for (const node of nodes) {
            const checked = node.is_recommended ? 'checked="checked"' : '';
            const recBadge = node.is_recommended ? '<span class="eg-badge-rec">Recommandé</span>' : '';

            html += '<li class="eg-tree-item">';
            html += '<label class="eg-tree-label">';
            html += `<input type="checkbox" name="eg_selected_folders[]" class="eg-folder-checkbox" value="${escapeHtml(node.relative_path)}" ${checked} data-recommended="${node.is_recommended ? '1' : '0'}" />`;
            html += '<span class="dashicons dashicons-category"></span>';
            html += `<strong>${escapeHtml(node.name)}</strong> `;
            html += `<span class="eg-badge-size">${escapeHtml(node.size_human)}</span> `;
            html += `<span class="eg-badge-files">${escapeHtml(node.files_count)} fichiers</span> `;
            html += recBadge;
            html += '</label>';

            if (node.children && node.children.length > 0) {
                html += renderTreeHtml(node.children);
            }
            html += '</li>';
        }
        html += '</ul>';
        return html;
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

    // Propagation de la sélection parent/enfants dans l'arborescence
    document.addEventListener('change', (e: Event) => {
        const target = e.target as HTMLElement;
        if (target && target.classList.contains('eg-folder-checkbox')) {
            const checkbox = target as HTMLInputElement;
            const treeItem = checkbox.closest('li.eg-tree-item');
            if (treeItem) {
                $$<HTMLInputElement>('ul .eg-folder-checkbox', treeItem).forEach(childCb => {
                    childCb.checked = checkbox.checked;
                });
            }
        }
    });

    // Lancement de la sauvegarde
    startBackupBtn?.addEventListener('click', async () => {
        const selectedFolders: string[] = $$<HTMLInputElement>('.eg-folder-checkbox:checked')
            .map(cb => cb.value);

        startBackupBtn.disabled = true;
        startBackupBtn.classList.add('updating-message');

        if (progressContainer) {
            progressContainer.style.display = 'block';
            progressContainer.style.opacity = '1';
        }
        if (successBox) successBox.style.display = 'none';
        if (logConsole) logConsole.innerHTML = '';

        logMessage('info', 'Démarrage du processus de sauvegarde...');
        updateProgress(5, 'Initialisation du travail...');
        activateStep('step-init');

        // Étape 1 : Start
        try {
            const response = await wpAjax<{ job_id: string; total_tables: number; message?: string }>(ajaxUrl, {
                action: 'eg_backup_start',
                nonce,
                selected_folders: selectedFolders,
            });

            if (!response.success || !response.data) {
                handleError(extractErrorMessage(response, 'Erreur lors de l\'initialisation.'));
                return;
            }

            const { job_id: jobId, total_tables: totalTables } = response.data;
            logMessage('info', `Initialisation réussie. Nombre de tables BDD à sauvegarder : ${totalTables}`);
            markStepDone('step-init');
            activateStep('step-db');

            // Étape 2 : Dump DB par lots
            await processDbStep(jobId, 0, 0, totalTables, selectedFolders);
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

                // Étape 3 : Fichiers par morceaux
                await prepareFilesStep(jobId, selectedFolders);
            } else {
                await processDbStep(jobId, data.table_index, data.row_offset, totalTables, selectedFolders);
            }
        } catch (err: any) {
            handleError(`Erreur réseau lors de l'export BDD : ${err.message || err}`);
        }
    }

    async function prepareFilesStep(jobId: string, selectedFolders: string[]): Promise<void> {
        updateProgress(52, 'Inventaire des fichiers à archiver...');
        logMessage('info', 'Analyse et indexation des dossiers sélectionnés...');

        try {
            const response = await wpAjax<{ total_files: number; message: string }>(ajaxUrl, {
                action: 'eg_backup_prepare_files',
                nonce,
                job_id: jobId,
            });

            if (!response.success || !response.data) {
                handleError(extractErrorMessage(response, 'Erreur lors de l\'indexation des fichiers.'));
                return;
            }

            const totalFiles = response.data.total_files;
            logMessage('info', response.data.message);

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

                // Étape 4 : Finalisation
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
