/**
 * Utilitaires communs et client HTTP natif (Vanilla TypeScript) pour EG Backup.
 */

export interface WpAjaxResponse<T = any> {
    success: boolean;
    data?: T;
    message?: string;
    [key: string]: any;
}

/**
 * Exécute une requête AJAX sécurisée vers admin-ajax.php via l'API Fetch native.
 */
export async function wpAjax<T = any>(
    url: string,
    params: Record<string, any> | FormData
): Promise<WpAjaxResponse<T>> {
    let body: FormData | URLSearchParams;
    const headers: Record<string, string> = {};

    if (params instanceof FormData) {
        body = params;
    } else {
        const searchParams = new URLSearchParams();
        for (const [key, value] of Object.entries(params)) {
            if (value === undefined || value === null) continue;
            if (Array.isArray(value)) {
                for (const item of value) {
                    searchParams.append(`${key}[]`, String(item));
                }
            } else {
                searchParams.append(key, String(value));
            }
        }
        body = searchParams;
        headers['Content-Type'] = 'application/x-www-form-urlencoded; charset=UTF-8';
    }

    const response = await fetch(url, {
        method: 'POST',
        headers,
        body,
        credentials: 'same-origin',
    });

    if (!response.ok) {
        throw new Error(`HTTP ${response.status} ${response.statusText}`);
    }

    const text = await response.text();
    let json: any;
    try {
        json = JSON.parse(text);
    } catch {
        if (text === '-1' || text === '0') {
            return { success: false, data: 'Session expirée ou requête non autorisée.' as any };
        }
        return { success: false, data: (text || 'Réponse serveur invalide.') as any };
    }

    return json;
}

/**
 * Sélecteur d'élément unique (raccourci querySelector typé).
 */
export function $<T extends HTMLElement = HTMLElement>(selector: string, parent: ParentNode = document): T | null {
    return parent.querySelector<T>(selector);
}

/**
 * Sélecteur d'éléments multiples (raccourci querySelectorAll en Array typé).
 */
export function $$<T extends HTMLElement = HTMLElement>(selector: string, parent: ParentNode = document): T[] {
    return Array.from(parent.querySelectorAll<T>(selector));
}

/**
 * Échappe une chaîne pour un affichage sécurisé dans le DOM.
 */
export function escapeHtml(str: any): string {
    if (str === null || str === undefined) return '';
    const text = String(str);
    const div = document.createElement('div');
    div.textContent = text;
    return div.innerHTML;
}

/**
 * Extrait le message d'erreur d'une réponse WordPress JSON.
 */
export function extractErrorMessage(response: any, defaultMsg: string): string {
    if (!response) {
        return defaultMsg;
    }
    if (typeof response === 'string') {
        return response === '-1' ? 'Session expirée ou requête non autorisée (-1).' : response;
    }
    if (response.data) {
        if (typeof response.data === 'string') {
            return response.data;
        }
        if (response.data.message) {
            return response.data.message;
        }
    }
    if (response.message) {
        return response.message;
    }
    return defaultMsg;
}

/**
 * Formate une taille en octets en chaîne lisible (Ko, Mo, Go).
 */
export function formatBytes(bytes: number): string {
    if (!bytes || bytes <= 0) return '0 Ko';
    const units = ['o', 'Ko', 'Mo', 'Go', 'To'];
    const i = Math.floor(Math.log(bytes) / Math.log(1024));
    const size = bytes / Math.pow(1024, i);
    const formatted = size >= 10 || i === 0 ? Math.round(size) : Math.round(size * 10) / 10;
    return `${formatted} ${units[i]}`;
}

/**
 * Télécharge une archive ZIP par tranches de 64 Mo (Chunked Fetch) puis déclenche la sauvegarde locale.
 * Contourne définitivement la coupure réseau à 512 Mo (~537 Mo) imposée par certains hébergeurs/proxys.
 */
export async function downloadBackupByChunks(
    downloadUrl: string,
    filename: string,
    initialTotalSize: number,
    buttonEl?: HTMLElement | null
): Promise<void> {
    if (buttonEl?.getAttribute('data-downloading') === '1') {
        return;
    }

    const originalHtml = buttonEl ? buttonEl.innerHTML : '';
    if (buttonEl) {
        buttonEl.setAttribute('data-downloading', '1');
        buttonEl.classList.add('disabled');
        buttonEl.style.pointerEvents = 'none';
    }

    const CHUNK_SIZE = 64 * 1024 * 1024; // 64 Mo par morceau (8x sous la limite de 512 Mo).
    const MAX_RETRIES = 3;
    const chunks: Blob[] = [];
    let totalSize = initialTotalSize > 0 ? initialTotalSize : 0;
    let offset = 0;

    try {
        while (totalSize === 0 || offset < totalSize) {
            const end = totalSize > 0
                ? Math.min(offset + CHUNK_SIZE - 1, totalSize - 1)
                : offset + CHUNK_SIZE - 1;

            if (buttonEl) {
                if (totalSize > 0) {
                    const percent = Math.min(99, Math.round((offset / totalSize) * 100));
                    buttonEl.innerHTML = `<span class="dashicons dashicons-download"></span> ${percent}% (${formatBytes(offset)} / ${formatBytes(totalSize)})`;
                } else {
                    buttonEl.innerHTML = `<span class="dashicons dashicons-download"></span> Téléchargement...`;
                }
            }

            const separator = downloadUrl.includes('?') ? '&' : '?';
            const chunkUrl = `${downloadUrl}${separator}chunk_start=${offset}&chunk_end=${end}`;

            let chunkBlob: Blob | null = null;
            let lastError: any = null;

            for (let attempt = 1; attempt <= MAX_RETRIES; attempt++) {
                try {
                    const response = await fetch(chunkUrl, {
                        method: 'GET',
                        credentials: 'same-origin',
                        cache: 'no-store',
                    });

                    if (!response.ok) {
                        throw new Error(`HTTP ${response.status} ${response.statusText}`);
                    }

                    const headerTotal = response.headers.get('X-EG-Total-Size');
                    if (headerTotal) {
                        const parsedTotal = parseInt(headerTotal, 10);
                        if (parsedTotal > 0) {
                            totalSize = parsedTotal;
                        }
                    }

                    chunkBlob = await response.blob();
                    break;
                } catch (err) {
                    lastError = err;
                    if (attempt < MAX_RETRIES) {
                        await new Promise(r => setTimeout(r, 1000 * attempt));
                    }
                }
            }

            if (!chunkBlob) {
                throw lastError || new Error('Échec du téléchargement d\'un tronçon.');
            }

            if (chunkBlob.size === 0) {
                break;
            }

            chunks.push(chunkBlob);
            offset += chunkBlob.size;

            if (totalSize > 0 && offset >= totalSize) {
                break;
            }
            if (chunkBlob.size < CHUNK_SIZE) {
                break;
            }
        }

        if (buttonEl) {
            buttonEl.innerHTML = `<span class="dashicons dashicons-yes"></span> Assemblage ZIP...`;
        }

        const finalBlob = new Blob(chunks, { type: 'application/zip' });
        const objectUrl = URL.createObjectURL(finalBlob);

        const tempLink = document.createElement('a');
        tempLink.href = objectUrl;
        tempLink.download = filename;
        tempLink.style.display = 'none';
        document.body.appendChild(tempLink);
        tempLink.click();
        tempLink.remove();

        // Libère la référence mémoire après le déclenchement de l'enregistrement par le navigateur.
        setTimeout(() => {
            URL.revokeObjectURL(objectUrl);
        }, 60000);

        if (buttonEl) {
            buttonEl.innerHTML = `<span class="dashicons dashicons-yes"></span> Téléchargé (100%)`;
            setTimeout(() => {
                buttonEl.innerHTML = originalHtml;
                buttonEl.removeAttribute('data-downloading');
                buttonEl.classList.remove('disabled');
                buttonEl.style.pointerEvents = '';
            }, 3000);
        }
    } catch (err: any) {
        if (buttonEl) {
            buttonEl.innerHTML = originalHtml;
            buttonEl.removeAttribute('data-downloading');
            buttonEl.classList.remove('disabled');
            buttonEl.style.pointerEvents = '';
        }
        alert(`Erreur lors du téléchargement de l'archive : ${err.message || err}`);
    }
}
