/**
 * Gestion des interactions de la page de liste des sauvegardes (Vanilla TypeScript).
 */

import { $, wpAjax, extractErrorMessage } from './_common';

document.addEventListener('DOMContentLoaded', () => {
    if (typeof egBackupData === 'undefined') {
        return;
    }

    const { ajaxUrl, nonce } = egBackupData;

    // Suppression d'archive depuis la liste (délégation d'événements)
    document.addEventListener('click', async (e: Event) => {
        const target = (e.target as HTMLElement).closest<HTMLButtonElement | HTMLAnchorElement>('.eg-btn-delete-backup');
        if (!target) return;

        e.preventDefault();
        const filename = target.getAttribute('data-filename') || '';
        if (!filename) return;

        if (!confirm(`Êtes-vous sûr de vouloir supprimer définitivement la sauvegarde "${filename}" ?`)) {
            return;
        }

        const row = target.closest('tr');
        target.setAttribute('disabled', 'disabled');

        try {
            const response = await wpAjax(ajaxUrl, {
                action: 'eg_backup_delete',
                nonce,
                filename,
            });

            if (response.success) {
                if (row) {
                    row.style.transition = 'opacity 0.3s ease';
                    row.style.opacity = '0';
                    setTimeout(() => {
                        row.remove();
                        const tbody = $('tbody');
                        if (tbody && !tbody.querySelector('tr')) {
                            window.location.reload();
                        }
                    }, 300);
                }
            } else {
                target.removeAttribute('disabled');
                alert(extractErrorMessage(response, 'Erreur lors de la suppression.'));
            }
        } catch (err: any) {
            target.removeAttribute('disabled');
            alert(`Erreur réseau : ${err.message || err}`);
        }
    });
});
