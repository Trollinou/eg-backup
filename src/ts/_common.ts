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
