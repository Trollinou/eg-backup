/**
 * Assistant de restauration interactif et vérification des dépendances (AJAX).
 */
(function($) {
    'use strict';

    $(document).ready(function() {
        var ajaxUrl = egBackupData.ajaxUrl;
        var nonce = egBackupData.nonce;
        var inspectedBackupFilename = '';
        var inspectedManifest = null;

        // Auto-inspection si un fichier est passé dans l'URL (?file=...)
        var urlParams = new URLSearchParams(window.location.search);
        var initialFile = urlParams.get('file');
        if (initialFile) {
            $('#eg-local-backup-select').val(initialFile);
            inspectArchive({ filename: initialFile });
        }

        // Clic sur "Analyser cette sauvegarde locale"
        $('#eg-inspect-local-btn').on('click', function() {
            var filename = $('#eg-local-backup-select').val();
            if (!filename) {
                alert('Veuillez sélectionner une archive dans la liste.');
                return;
            }
            inspectArchive({ filename: filename });
        });

        // Clic sur "Téléverser et analyser"
        $('#eg-inspect-upload-btn').on('click', function() {
            var fileInput = $('#eg-upload-backup-input')[0];
            if (!fileInput.files || !fileInput.files[0]) {
                alert('Veuillez choisir un fichier .zip à téléverser.');
                return;
            }

            var formData = new FormData();
            formData.append('action', 'eg_restore_inspect');
            formData.append('nonce', nonce);
            formData.append('backup_file', fileInput.files[0]);

            $('#eg-inspect-loading').show();
            $('#eg-restore-manifest-card').hide();

            $.ajax({
                url: ajaxUrl,
                type: 'POST',
                data: formData,
                processData: false,
                contentType: false,
                success: function(response) {
                    $('#eg-inspect-loading').hide();
                    handleInspectionResponse(response);
                },
                error: function() {
                    $('#eg-inspect-loading').hide();
                    alert('Erreur lors du téléversement du fichier.');
                }
            });
        });

        function inspectArchive(data) {
            $('#eg-inspect-loading').show();
            $('#eg-restore-manifest-card').hide();

            $.post(ajaxUrl, {
                action: 'eg_restore_inspect',
                nonce: nonce,
                filename: data.filename
            }, function(response) {
                $('#eg-inspect-loading').hide();
                handleInspectionResponse(response);
            }).fail(function() {
                $('#eg-inspect-loading').hide();
                alert('Erreur réseau lors de l\'analyse de la sauvegarde.');
            });
        }

        function handleInspectionResponse(response) {
            if (!response.success) {
                alert(response.data.message || 'Impossible d\'analyser cette sauvegarde.');
                return;
            }

            inspectedBackupFilename = response.data.filename;
            inspectedManifest = response.data.manifest;
            var comp = response.data.comparison;

            // Remplir métadonnées
            var metaHtml = '';
            metaHtml += '<div class="eg-meta-item"><strong>Fichier</strong><span>' + escapeHtml(inspectedBackupFilename) + '</span></div>';
            metaHtml += '<div class="eg-meta-item"><strong>Site d\'origine</strong><span>' + escapeHtml(inspectedManifest.site.name || 'WordPress') + '</span></div>';
            metaHtml += '<div class="eg-meta-item"><strong>Date de création</strong><span>' + escapeHtml(inspectedManifest.created_at) + '</span></div>';
            metaHtml += '<div class="eg-meta-item"><strong>Version WP source</strong><span>WP ' + escapeHtml(inspectedManifest.environment.version) + '</span></div>';
            $('#eg-manifest-meta-grid').html(metaHtml);

            // Alerte si extensions manquantes
            var alertHtml = '';
            if (comp.missing_plugins_count > 0) {
                alertHtml += '<div class="notice notice-warning inline">';
                alertHtml += '<p><strong>Attention :</strong> ' + comp.missing_plugins_count + ' extension(s) requise(s) dans cette sauvegarde ne sont pas actuellement installée(s) sur ce site. ';
                alertHtml += 'Installez-les dès maintenant ci-dessous avant d\'appliquer la restauration.</p>';
                alertHtml += '</div>';
            } else {
                alertHtml += '<div class="notice notice-success inline">';
                alertHtml += '<p><strong>Parfait :</strong> Toutes les extensions requises sont déjà installées sur ce site !</p>';
                alertHtml += '</div>';
            }
            $('#eg-plugins-alert-box').html(alertHtml);

            // Remplir le tableau des plugins
            var rowsHtml = '';
            comp.plugins.forEach(function(plugin) {
                var statusBadge = '';
                var actionBtn = '';

                if (plugin.status === 'installed') {
                    statusBadge = '<span class="eg-badge-pill eg-badge-installed">Installé (' + escapeHtml(plugin.installed_version) + ')</span>';
                    actionBtn = '<span class="dashicons dashicons-yes-alt" style="color:#22c55e;"></span> Prêt';
                } else if (plugin.status === 'version_diff') {
                    statusBadge = '<span class="eg-badge-pill eg-badge-diff">Diff. version (' + escapeHtml(plugin.installed_version) + ')</span>';
                    actionBtn = '<span class="description">Compatible (attention aux écarts majeurs)</span>';
                } else {
                    statusBadge = '<span class="eg-badge-pill eg-badge-missing">Non installé</span>';
                    if (plugin.is_wporg && plugin.slug) {
                        actionBtn = '<button type="button" class="button button-small button-primary eg-btn-install-plugin" data-slug="' + escapeHtml(plugin.slug) + '">';
                        actionBtn += '<span class="dashicons dashicons-download"></span> Installer (WP.org)';
                        actionBtn += '</button>';
                    } else {
                        actionBtn = '<span class="description">À réinstaller manuellement (tiers/payant)</span>';
                    }
                }

                rowsHtml += '<tr>';
                rowsHtml += '<td><strong>' + escapeHtml(plugin.name) + '</strong><br><small class="description">' + escapeHtml(plugin.file) + '</small></td>';
                rowsHtml += '<td>' + escapeHtml(plugin.required_version || 'N/A') + '</td>';
                rowsHtml += '<td>' + statusBadge + '</td>';
                rowsHtml += '<td id="action-cell-' + escapeHtml(plugin.slug) + '">' + actionBtn + '</td>';
                rowsHtml += '</tr>';
            });
            $('#eg-plugins-table-body').html(rowsHtml);

            // Bilan du thème
            var theme = comp.theme;
            var themeStatus = theme.is_installed ? '<span class="eg-badge-pill eg-badge-installed">Installé</span>' : '<span class="eg-badge-pill eg-badge-missing">Non installé</span>';
            var themeHtml = '<div style="margin: 16px 0; padding: 12px; background: #fff; border: 1px solid #dcdcde; border-radius: 6px;">';
            themeHtml += '<strong>Thème requis :</strong> ' + escapeHtml(theme.name) + ' (version ' + escapeHtml(theme.required_version) + ') — État : ' + themeStatus;
            themeHtml += '</div>';
            $('#eg-theme-check-box').html(themeHtml);

            // Pré-remplir la migration d'URL
            $('#eg-replace-old-url').val(inspectedManifest.environment.siteurl || '');

            $('#eg-restore-manifest-card').fadeIn();
        }

        // Clic pour installer un plugin depuis WordPress.org en 1-clic
        $(document).on('click', '.eg-btn-install-plugin', function() {
            var $btn = $(this);
            var slug = $btn.data('slug');
            var $cell = $('#action-cell-' + slug);

            $btn.prop('disabled', true).text('Installation...');

            $.post(ajaxUrl, {
                action: 'eg_restore_install_plugin',
                nonce: nonce,
                slug: slug
            }, function(response) {
                if (response.success) {
                    $cell.html('<span class="dashicons dashicons-yes-alt" style="color:#22c55e;"></span> ' + escapeHtml(response.data.message));
                    $cell.prev().html('<span class="eg-badge-pill eg-badge-installed">Installé</span>');
                } else {
                    $btn.prop('disabled', false).text('Réessayer');
                    alert(response.data.message || 'Erreur lors de l\'installation.');
                }
            }).fail(function() {
                $btn.prop('disabled', false).text('Réessayer');
                alert('Erreur réseau lors de l\'installation.');
            });
        });

        // Lancement de la restauration
        $('#eg-start-restore-btn').on('click', function() {
            if (!confirm('ATTENTION : La restauration écrasera la base de données actuelle par celle de la sauvegarde.\n\nÊtes-vous sûr de vouloir continuer ?')) {
                return;
            }

            $('#eg-restore-manifest-card').hide();
            $('#eg-restore-source-card').hide();
            $('#eg-restore-progress-card').fadeIn();
            $('#eg-restore-log-console').empty();

            logMessage('info', 'Démarrage du processus de restauration...');
            updateRestoreProgress(5, 'Décompression et activation de la maintenance...');
            activateRestoreStep('rstep-extract');

            var oldUrl = $('#eg-replace-old-url').val();
            var newUrl = $('#eg-replace-new-url').val();

            // Étape 1 : Start & Unzip
            $.post(ajaxUrl, {
                action: 'eg_restore_start',
                nonce: nonce,
                filename: inspectedBackupFilename
            }, function(response) {
                if (!response.success) {
                    handleRestoreError(response.data.message || 'Erreur lors de l\'extraction.');
                    return;
                }

                var jobId = response.data.job_id;
                logMessage('success', response.data.message);
                markRestoreStepDone('rstep-extract');
                activateRestoreStep('rstep-db');

                // Étape 2 : Import SQL par lots
                processRestoreDb(jobId, 0, oldUrl, newUrl);
            }).fail(function(jqXHR, textStatus, errorThrown) {
                var detail = jqXHR.status ? ' (HTTP ' + jqXHR.status + ' ' + errorThrown + ')' : '';
                handleRestoreError('Erreur réseau au lancement de la restauration' + detail);
            });
        });

        function processRestoreDb(jobId, offset, oldUrl, newUrl) {
            $.post(ajaxUrl, {
                action: 'eg_restore_step_db',
                nonce: nonce,
                job_id: jobId,
                offset: offset
            }, function(response) {
                if (!response.success) {
                    handleRestoreError(response.data.message || 'Erreur lors de l\'import SQL.');
                    return;
                }

                var data = response.data;
                var dbPercent = Math.min(60, Math.round(15 + (data.progress * 0.45)));
                updateRestoreProgress(dbPercent, data.message);

                if (data.eof) {
                    logMessage('success', 'Base de données réimportée intégralement.');
                    markRestoreStepDone('rstep-db');
                    activateRestoreStep('rstep-replace');

                    // Étape 3 : Search & Replace
                    processRestoreReplace(jobId, oldUrl, newUrl);
                } else {
                    processRestoreDb(jobId, data.offset, oldUrl, newUrl);
                }
            }).fail(function(jqXHR, textStatus, errorThrown) {
                var detail = jqXHR.status ? ' (HTTP ' + jqXHR.status + ' ' + errorThrown + ')' : '';
                handleRestoreError('Erreur réseau lors de l\'import SQL' + detail);
            });
        }

        function processRestoreReplace(jobId, oldUrl, newUrl) {
            updateRestoreProgress(70, 'Mise à jour des adresses et liens (Search & Replace)...');
            logMessage('info', 'Vérification et adaptation des URLs sérialisées...');

            $.post(ajaxUrl, {
                action: 'eg_restore_step_replace',
                nonce: nonce,
                old_url: oldUrl,
                new_url: newUrl
            }, function(response) {
                logMessage('success', response.data.message);
                markRestoreStepDone('rstep-replace');
                activateRestoreStep('rstep-files');

                // Étape 4 : Restauration des fichiers
                processRestoreFiles(jobId);
            }).fail(function(jqXHR, textStatus, errorThrown) {
                var detail = jqXHR.status ? ' (HTTP ' + jqXHR.status + ' ' + errorThrown + ')' : '';
                handleRestoreError('Erreur réseau lors du remplacement des URLs' + detail);
            });
        }

        function processRestoreFiles(jobId) {
            updateRestoreProgress(85, 'Restauration des fichiers vers wp-content...');
            logMessage('info', 'Copie des dossiers de données dans wp-content...');

            $.post(ajaxUrl, {
                action: 'eg_restore_step_files',
                nonce: nonce,
                job_id: jobId
            }, function(response) {
                logMessage('success', response.data.message);
                markRestoreStepDone('rstep-files');
                activateRestoreStep('rstep-finalize');

                // Étape 5 : Finalisation
                processRestoreFinalize(jobId);
            }).fail(function(jqXHR, textStatus, errorThrown) {
                var detail = jqXHR.status ? ' (HTTP ' + jqXHR.status + ' ' + errorThrown + ')' : '';
                handleRestoreError('Erreur réseau lors de la restauration des fichiers' + detail);
            });
        }

        function processRestoreFinalize(jobId) {
            updateRestoreProgress(95, 'Désactivation du mode maintenance et nettoyage...');
            logMessage('info', 'Suppression des fichiers temporaires et retour en ligne...');

            $.post(ajaxUrl, {
                action: 'eg_restore_finalize',
                nonce: nonce,
                job_id: jobId
            }, function(response) {
                updateRestoreProgress(100, 'Restauration terminée avec succès !');
                markRestoreStepDone('rstep-finalize');
                logMessage('success', response.data.message);

                $('#eg-restore-success-box').fadeIn();
            }).fail(function() {
                handleRestoreError('Erreur réseau lors de la finalisation.');
            });
        }

        // Helpers UI
        function updateRestoreProgress(percent, statusText) {
            $('#eg-restore-progress-bar').css('width', percent + '%');
            if (statusText) {
                $('#eg-restore-status').text(statusText);
            }
        }

        function activateRestoreStep(stepId) {
            $('.eg-step').removeClass('active');
            $('#' + stepId).addClass('active');
        }

        function markRestoreStepDone(stepId) {
            $('#' + stepId).removeClass('active').addClass('done');
        }

        function logMessage(type, text) {
            var time = new Date().toLocaleTimeString();
            var line = '<div class="log-line log-' + type + '">[' + time + '] ' + escapeHtml(text) + '</div>';
            var $console = $('#eg-restore-log-console');
            $console.append(line);
            $console.scrollTop($console[0].scrollHeight);
        }

        function handleRestoreError(msg) {
            logMessage('error', 'ERREUR CRITIQUE : ' + msg);
            $('#eg-restore-status').text('Échec : ' + msg);
            $('#eg-restore-progress-bar').css('background', '#ef4444');
        }

        function escapeHtml(str) {
            if (!str) return '';
            return $('<div>').text(str).html();
        }
    });
})(jQuery);
