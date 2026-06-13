/**
 * TRIVIAX DiagnosticsService
 * Permite monitorear el estado técnico, caché, red y service worker para docentes
 */
import { APP_VERSION } from '../config.js';
import { ApiClient } from './apiClient.js';

export class DiagnosticsService {
    /**
     * Obtiene la versión actual de la aplicación
     * @returns {string}
     */
    static getAppVersion() {
        return APP_VERSION;
    }

    /**
     * Obtiene el estado del Service Worker
     * @returns {Promise<string>} 'No soportado' | 'No instalado' | 'Instalado / Activo' | 'Esperando actualización' | 'Instalando...'
     */
    static async getServiceWorkerStatus() {
        if (!('serviceWorker' in navigator)) {
            return 'No soportado';
        }
        
        try {
            const reg = await navigator.serviceWorker.getRegistration();
            if (!reg) {
                return 'No instalado';
            }
            if (reg.waiting) {
                return 'Esperando actualización';
            }
            if (reg.installing) {
                return 'Instalando...';
            }
            if (reg.active) {
                return 'Instalado / Activo';
            }
            return 'Registrado';
        } catch (e) {
            return `Error: ${e.message}`;
        }
    }

    /**
     * Obtiene el estado de conexión a Internet (online/offline)
     * @returns {string} 'Online' | 'Offline'
     */
    static getOnlineStatus() {
        return navigator.onLine ? 'Online' : 'Offline';
    }

    /**
     * Comprueba si el backend PHP está respondiendo
     * @returns {Promise<string>} 'Disponible' | 'No disponible'
     */
    static async getBackendStatus() {
        const ok = await ApiClient.checkConnection();
        return ok ? 'Disponible' : 'No disponible';
    }

    /**
     * Obtiene los nombres de las cachés activas de la app
     * @returns {Promise<Array<string>>}
     */
    static async getActiveCaches() {
        if (!('caches' in window)) {
            return [];
        }
        const keys = await caches.keys();
        return keys.filter(key => key.startsWith('triviax-'));
    }

    /**
     * Limpia todas las cachés y recarga la aplicación
     */
    static async clearAllCachesAndReload() {
        if ('caches' in window) {
            const keys = await caches.keys();
            await Promise.all(
                keys.map(key => caches.delete(key))
            );
        }
        
        // Limpiar LocalStorage e IndexedDB
        localStorage.clear();
        
        try {
            // Borrar base de datos IndexedDB si es posible
            if (window.indexedDB) {
                window.indexedDB.deleteDatabase('triviax_db');
            }
        } catch (e) {
            console.warn('No se pudo borrar IndexedDB:', e);
        }

        // Forzar recarga omitiendo la caché del navegador
        window.location.reload();
    }

    /**
     * Busca actualizaciones del Service Worker de forma explícita
     */
    static async checkForUpdates() {
        if (!('serviceWorker' in navigator)) {
            alert('Las actualizaciones automáticas no están soportadas en este navegador.');
            return;
        }

        try {
            const reg = await navigator.serviceWorker.getRegistration();
            if (reg) {
                console.log('[Diagnostics] Forzando búsqueda de actualización del SW...');
                await reg.update();
                if (reg.waiting) {
                    alert('Nueva versión encontrada. Se mostrará el aviso de recarga.');
                } else {
                    alert('TRIVIAX ya está actualizado a la versión más reciente en este dispositivo.');
                }
            } else {
                alert('No se detectó un Service Worker activo. Inicializando...');
                window.location.reload();
            }
        } catch (err) {
            alert(`No se pudo buscar actualizaciones: ${err.message}`);
        }
    }

    /**
     * Genera un reporte detallado del estado del sistema en JSON
     * @param {Object} activeProjectData - Datos del proyecto actual cargado
     * @returns {Promise<Object>} Reporte técnico
     */
    static async generateReport(activeProjectData = null) {
        const swStatus = await this.getServiceWorkerStatus();
        const activeCaches = await this.getActiveCaches();
        
        return {
            appVersion: APP_VERSION,
            diagnosticsTimestamp: new Date().toISOString(),
            userAgent: navigator.userAgent,
            networkStatus: this.getOnlineStatus(),
            backendStatus: await this.getBackendStatus(),
            serviceWorker: {
                status: swStatus,
                controller: !!navigator.serviceWorker?.controller
            },
            cacheStorage: {
                available: 'caches' in window,
                activeCaches: activeCaches
            },
            localStorageUsage: {
                keys: Object.keys(localStorage),
                length: localStorage.length
            },
            activeProject: activeProjectData ? {
                name: activeProjectData.name || '-',
                title: activeProjectData.metadata?.title || '-',
                author: activeProjectData.metadata?.author || '-',
                nivel: activeProjectData.metadata?.nivel || '-',
                challengesCount: activeProjectData.questions?.length || 0,
                hasCustomBackground: !!activeProjectData.metadata?.background
            } : null
        };
    }

    /**
     * Exporta el reporte técnico de diagnóstico como archivo JSON para descarga
     * @param {Object} activeProjectData 
     */
    static async exportReportToFile(activeProjectData = null) {
        const report = await this.generateReport(activeProjectData);
        const dataStr = "data:text/json;charset=utf-8," + encodeURIComponent(JSON.stringify(report, null, 2));
        
        const downloadAnchor = document.createElement('a');
        downloadAnchor.setAttribute("href", dataStr);
        downloadAnchor.setAttribute("download", `diagnostico_triviax_${Date.now()}.json`);
        document.body.appendChild(downloadAnchor);
        downloadAnchor.click();
        downloadAnchor.remove();
    }
}
