/**
 * Gestion du scan de fichiers et du processus d'exportation de sauvegarde (AJAX).
 */
(function($) {
    'use strict';

    $(document).ready(function() {
        var ajaxUrl = egBackupData.ajaxUrl;
        var nonce = egBackupData.nonce;

        // Auto-chargement de l'arborescence au chargement si sur la page de sauvegarde
        if ($('#eg-file-tree-container').length) {
            loadDirectoryTree();
        }

        // Clic sur "Analyser le dossier wp-content"
        $('#eg-scan-btn').on('click', function() {
            loadDirectoryTree();
        });

        function loadDirectoryTree() {
            $('#eg-tree-loading').show();
            $('#eg-file-tree-container').empty();

            $.post(ajaxUrl, {
                action: 'eg_backup_scan_files',
                nonce: nonce
            }, function(response) {
                $('#eg-tree-loading').hide();
                if (response.success && response.data.tree) {
                    var treeHtml = renderTreeHtml(response.data.tree);
                    $('#eg-file-tree-container').html(treeHtml);
                } else {
                    $('#eg-file-tree-container').html('<p class="eg-log-error">Impossible d\'analyser le dossier wp-content.</p>');
                }
            }).fail(function() {
                $('#eg-tree-loading').hide();
                $('#eg-file-tree-container').html('<p class="eg-log-error">Erreur de connexion au serveur.</p>');
            });
        }

        function renderTreeHtml(nodes) {
            if (!nodes || !nodes.length) {
                return '<p class="eg-placeholder-text">Aucun dossier personnalisable trouvé dans wp-content.</p>';
            }

            var html = '<ul class="eg-tree-list">';
            nodes.forEach(function(node) {
                var checked = node.is_recommended ? 'checked="checked"' : '';
                var recBadge = node.is_recommended ? '<span class="eg-badge-rec">Recommandé</span>' : '';

                html += '<li class="eg-tree-item">';
                html += '<label class="eg-tree-label">';
                html += '<input type="checkbox" name="eg_selected_folders[]" class="eg-folder-checkbox" value="' + escapeHtml(node.relative_path) + '" ' + checked + ' data-recommended="' + (node.is_recommended ? '1' : '0') + '" />';
                html += '<span class="dashicons dashicons-category"></span>';
                html += '<strong>' + escapeHtml(node.name) + '</strong> ';
                html += '<span class="eg-badge-size">' + escapeHtml(node.size_human) + '</span> ';
                html += '<span class="eg-badge-files">' + escapeHtml(node.files_count) + ' fichiers</span> ';
                html += recBadge;
                html += '</label>';

                if (node.children && node.children.length > 0) {
                    html += renderTreeHtml(node.children);
                }
                html += '</li>';
            });
            html += '</ul>';
            return html;
        }

        // Actions de sélection rapide
        $('#eg-select-all-btn').on('click', function() {
            $('.eg-folder-checkbox').prop('checked', true);
        });

        $('#eg-deselect-all-btn').on('click', function() {
            $('.eg-folder-checkbox').prop('checked', false);
        });

        $('#eg-select-recommended-btn').on('click', function() {
            $('.eg-folder-checkbox').each(function() {
                var isRec = $(this).data('recommended') === 1 || $(this).data('recommended') === '1';
                $(this).prop('checked', isRec);
            });
        });

        // Propagation de la sélection parent/enfants dans l'arborescence
        $(document).on('change', '.eg-folder-checkbox', function() {
            var isChecked = $(this).is(':checked');
            $(this).closest('li.eg-tree-item').find('ul .eg-folder-checkbox').prop('checked', isChecked);
        });

        // Lancement de la sauvegarde
        $('#eg-start-backup-btn').on('click', function() {
            var $btn = $(this);
            var selectedFolders = [];
            $('.eg-folder-checkbox:checked').each(function() {
                selectedFolders.push($(this).val());
            });

            $btn.prop('disabled', true).addClass('updating-message');
            $('#eg-progress-container').fadeIn();
            $('#eg-success-box').hide();
            $('#eg-log-console').empty();

            logMessage('info', 'Démarrage du processus de sauvegarde...');
            updateProgress(5, 'Initialisation du travail...');
            activateStep('step-init');

            // Étape 1 : Start
            $.post(ajaxUrl, {
                action: 'eg_backup_start',
                nonce: nonce,
                selected_folders: selectedFolders
            }, function(response) {
                if (!response.success) {
                    handleError(response.data.message || 'Erreur lors de l\'initialisation.');
                    return;
                }

                var jobId = response.data.job_id;
                var totalTables = response.data.total_tables;
                logMessage('info', 'Initialisation réussie. Nombre de tables BDD à sauvegarder : ' + totalTables);
                markStepDone('step-init');
                activateStep('step-db');

                // Étape 2 : Dump DB par lots
                processDbStep(jobId, 0, 0, totalTables, selectedFolders);

            }).fail(function() {
                handleError('Erreur de communication lors du démarrage de la sauvegarde.');
            });
        });

        function processDbStep(jobId, tableIndex, rowOffset, totalTables, selectedFolders) {
            $.post(ajaxUrl, {
                action: 'eg_backup_step_db',
                nonce: nonce,
                job_id: jobId,
                table_index: tableIndex,
                row_offset: rowOffset
            }, function(response) {
                if (!response.success) {
                    handleError(response.data.message || 'Erreur lors de l\'export BDD.');
                    return;
                }

                var data = response.data;
                var dbProgress = Math.min(50, Math.round(5 + (data.progress * 0.45))); // BDD compte pour 5% à 50%
                updateProgress(dbProgress, data.message);

                if (data.current_table && rowOffset === 0) {
                    logMessage('info', 'Exportation table : ' + data.current_table);
                }

                if (data.db_finished) {
                    logMessage('success', 'Base de données exportée avec succès.');
                    markStepDone('step-db');
                    activateStep('step-files');

                    // Étape 3 : Fichiers par morceaux
                    prepareFilesStep(jobId, selectedFolders);
                } else {
                    // Continuer le prochain chunk BDD
                    processDbStep(jobId, data.table_index, data.row_offset, totalTables, selectedFolders);
                }
            }).fail(function(jqXHR, textStatus, errorThrown) {
                var detail = jqXHR.status ? ' (HTTP ' + jqXHR.status + ' ' + errorThrown + ')' : '';
                handleError('Erreur réseau lors de l\'export BDD' + detail);
            });
        }

        function prepareFilesStep(jobId, selectedFolders) {
            updateProgress(52, 'Inventaire des fichiers à archiver...');
            logMessage('info', 'Analyse et indexation des dossiers sélectionnés...');

            $.post(ajaxUrl, {
                action: 'eg_backup_prepare_files',
                nonce: nonce,
                job_id: jobId
            }, function(response) {
                if (!response.success) {
                    handleError(response.data.message || 'Erreur lors de l\'indexation des fichiers.');
                    return;
                }

                var totalFiles = response.data.total_files;
                logMessage('info', response.data.message);

                if (totalFiles === 0) {
                    logMessage('info', 'Aucun fichier externe à archiver.');
                    markStepDone('step-files');
                    activateStep('step-manifest');
                    processFinalizeStep(jobId);
                } else {
                    processFilesChunkStep(jobId, 0, totalFiles);
                }
            }).fail(function(jqXHR, textStatus, errorThrown) {
                var detail = jqXHR.status ? ' (HTTP ' + jqXHR.status + ' ' + errorThrown + ')' : '';
                handleError('Erreur réseau lors de l\'indexation des fichiers' + detail);
            });
        }

        var filesRetryCount = 0;
        var MAX_FILES_RETRIES = 3;

        function processFilesChunkStep(jobId, offset, totalFiles) {
            $.post(ajaxUrl, {
                action: 'eg_backup_step_files',
                nonce: nonce,
                job_id: jobId,
                offset: offset
            }, function(response) {
                filesRetryCount = 0;

                if (!response.success) {
                    handleError(response.data.message || 'Erreur lors de l\'ajout des fichiers au ZIP.');
                    return;
                }

                var data = response.data;
                var fileOverallPercent = Math.min(90, Math.round(52 + (data.progress * 0.38))); // Fichiers de 52% à 90%
                updateProgress(fileOverallPercent, data.message);

                logMessage('info', data.message);

                if (data.finished) {
                    logMessage('success', 'Tous les fichiers ont été intégrés à l\'archive.');
                    markStepDone('step-files');
                    activateStep('step-manifest');

                    // Étape 4 : Finalisation
                    processFinalizeStep(jobId);
                } else {
                    // Lot suivant
                    processFilesChunkStep(jobId, data.offset, totalFiles);
                }
            }).fail(function(jqXHR, textStatus, errorThrown) {
                if (filesRetryCount < MAX_FILES_RETRIES) {
                    filesRetryCount++;
                    logMessage('warning', 'Incident réseau temporaire (lot ' + offset + '), nouvelle tentative dans 2s (' + filesRetryCount + '/' + MAX_FILES_RETRIES + ')...');
                    setTimeout(function() {
                        processFilesChunkStep(jobId, offset, totalFiles);
                    }, 2000);
                    return;
                }

                var detail = jqXHR.status ? ' (HTTP ' + jqXHR.status + ' ' + errorThrown + ')' : '';
                handleError('Erreur réseau lors du traitement des fichiers' + detail);
            });
        }

        function processFinalizeStep(jobId) {
            updateProgress(92, 'Finalisation de la sauvegarde...');
            logMessage('info', 'Nettoyage des fichiers temporaires...');

            $.post(ajaxUrl, {
                action: 'eg_backup_finalize',
                nonce: nonce,
                job_id: jobId
            }, function(response) {
                if (!response.success) {
                    handleError(response.data.message || 'Erreur lors de la finalisation.');
                    return;
                }

                var data = response.data;
                updateProgress(100, 'Sauvegarde terminée !');
                markStepDone('step-manifest');
                logMessage('success', 'Archive ZIP créée : ' + data.filename + ' (' + data.size_human + ')');

                $('#eg-success-details').html(
                    'Archive créée : <strong>' + escapeHtml(data.filename) + '</strong> (' + escapeHtml(data.size_human) + ')'
                );

                var downloadUrl = data.download_url;
                if (!downloadUrl) {
                    var dlNonce = (typeof egBackupData !== 'undefined' && egBackupData.downloadNonce) ? egBackupData.downloadNonce : nonce;
                    downloadUrl = 'admin.php?page=eg-backup-list&action=eg_backup_download&file=' + encodeURIComponent(data.filename) + '&nonce=' + encodeURIComponent(dlNonce);
                }
                $('#eg-download-link').attr('href', downloadUrl);

                $('#eg-success-box').fadeIn();
                $('#eg-start-backup-btn').prop('disabled', false).removeClass('updating-message');
            }).fail(function(jqXHR, textStatus, errorThrown) {
                var detail = jqXHR.status ? ' (HTTP ' + jqXHR.status + ' ' + errorThrown + ')' : '';
                handleError('Erreur réseau lors de la finalisation du ZIP' + detail);
            });
        }

        // Suppression d'archive depuis la liste
        $('.eg-btn-delete-backup').on('click', function(e) {
            e.preventDefault();
            var filename = $(this).data('filename');
            if (!confirm('Êtes-vous sûr de vouloir supprimer définitivement la sauvegarde "' + filename + '" ?')) {
                return;
            }

            var $row = $(this).closest('tr');
            $.post(ajaxUrl, {
                action: 'eg_backup_delete',
                nonce: nonce,
                filename: filename
            }, function(response) {
                if (response.success) {
                    $row.fadeOut(300, function() { $(this).remove(); });
                } else {
                    alert(response.data.message || 'Erreur lors de la suppression.');
                }
            });
        });

        // Helpers UI
        function updateProgress(percent, statusText) {
            $('#eg-progress-bar').css('width', percent + '%');
            if (statusText) {
                $('#eg-progress-status').text(statusText);
            }
        }

        function activateStep(stepId) {
            $('.eg-step').removeClass('active');
            $('#' + stepId).addClass('active');
        }

        function markStepDone(stepId) {
            $('#' + stepId).removeClass('active').addClass('done');
        }

        function logMessage(type, text) {
            var time = new Date().toLocaleTimeString();
            var line = '<div class="log-line log-' + type + '">[' + time + '] ' + escapeHtml(text) + '</div>';
            var $console = $('#eg-log-console');
            $console.append(line);
            $console.scrollTop($console[0].scrollHeight);
        }

        function handleError(msg) {
            logMessage('error', 'ERREUR : ' + msg);
            $('#eg-progress-status').text('Échec : ' + msg);
            $('#eg-progress-bar').css('background', '#ef4444');
            $('#eg-start-backup-btn').prop('disabled', false).removeClass('updating-message');
        }

        function escapeHtml(str) {
            if (!str) return '';
            return $('<div>').text(str).html();
        }
    });
})(jQuery);
