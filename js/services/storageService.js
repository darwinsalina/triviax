/**
 * TRIVIAX StorageService
 * Capa de persistencia híbrida (IndexedDB + LocalStorage) para funcionamiento offline-first
 */
export class StorageService {
    static DB_NAME = 'triviax_db';
    static DB_VERSION = 1;
    static STORES = {
        reports: 'reports',      // Reportes locales pendientes de enviar por mail
        projects: 'projects'     // Estructura de proyectos cached offline (proyecto.json)
    };

    /**
     * Inicializa IndexedDB de forma asíncrona
     * @returns {Promise<IDBDatabase>}
     */
    static getDB() {
        return new Promise((resolve, reject) => {
            if (!window.indexedDB) {
                reject(new Error('IndexedDB no está soportado en este navegador.'));
                return;
            }

            const timeoutId = setTimeout(() => {
                reject(new Error('Timeout al abrir IndexedDB (1s)'));
            }, 1000);

            const request = window.indexedDB.open(this.DB_NAME, this.DB_VERSION);

            request.onerror = () => {
                clearTimeout(timeoutId);
                reject(request.error);
            };
            request.onsuccess = () => {
                clearTimeout(timeoutId);
                resolve(request.result);
            };
            request.onblocked = () => {
                clearTimeout(timeoutId);
                reject(new Error('IndexedDB bloqueado por otra conexión'));
            };

            request.onupgradeneeded = (event) => {
                const db = request.result;
                // Crear almacenes si no existen
                if (!db.objectStoreNames.contains(this.STORES.reports)) {
                    db.createObjectStore(this.STORES.reports, { keyPath: 'id', autoIncrement: true });
                }
                if (!db.objectStoreNames.contains(this.STORES.projects)) {
                    db.createObjectStore(this.STORES.projects, { keyPath: 'name' });
                }
            };
        });
    }

    /**
     * Guarda un reporte en local (IndexedDB o LocalStorage de respaldo)
     * @param {Object} report 
     */
    static async saveLocalReport(report) {
        const reportData = {
            ...report,
            timestamp: Date.now(),
            synced: false
        };

        try {
            const db = await this.getDB();
            return new Promise((resolve, reject) => {
                const tx = db.transaction(this.STORES.reports, 'readwrite');
                const store = tx.objectStore(this.STORES.reports);
                const req = store.add(reportData);
                req.onsuccess = () => resolve(req.result);
                req.onerror = () => reject(req.error);
            });
        } catch (e) {
            console.warn('[StorageService] IndexedDB falló. Guardando reporte en LocalStorage.', e);
            const reports = this.getLocalStorageReports();
            reports.push(reportData);
            localStorage.setItem('triviax_reports', JSON.stringify(reports));
            return Date.now();
        }
    }

    /**
     * Obtiene los reportes locales pendientes de sincronización
     * @returns {Promise<Array<Object>>}
     */
    static async getPendingReports() {
        try {
            const db = await this.getDB();
            return new Promise((resolve, reject) => {
                const tx = db.transaction(this.STORES.reports, 'readonly');
                const store = tx.objectStore(this.STORES.reports);
                const req = store.getAll();
                req.onsuccess = () => {
                    const pending = req.result.filter(r => !r.synced);
                    resolve(pending);
                };
                req.onerror = () => reject(req.error);
            });
        } catch (e) {
            const reports = this.getLocalStorageReports();
            return reports.filter(r => !r.synced);
        }
    }

    /**
     * Marca un reporte como sincronizado
     * @param {number|string} id 
     */
    static async markReportAsSynced(id) {
        try {
            const db = await this.getDB();
            const report = await new Promise((resolve, reject) => {
                const tx = db.transaction(this.STORES.reports, 'readonly');
                const store = tx.objectStore(this.STORES.reports);
                const req = store.get(id);
                req.onsuccess = () => resolve(req.result);
                req.onerror = () => reject(req.error);
            });

            if (report) {
                report.synced = true;
                await new Promise((resolve, reject) => {
                    const tx = db.transaction(this.STORES.reports, 'readwrite');
                    const store = tx.objectStore(this.STORES.reports);
                    const req = store.put(report);
                    req.onsuccess = () => resolve();
                    req.onerror = () => reject(req.error);
                });
            }
        } catch (e) {
            const reports = this.getLocalStorageReports();
            const r = reports.find(item => item.id === id || item.timestamp === id);
            if (r) {
                r.synced = true;
                localStorage.setItem('triviax_reports', JSON.stringify(reports));
            }
        }
    }

    /**
     * Guarda los datos de un proyecto para uso offline
     * @param {string} projectName 
     * @param {Object} projectData 
     */
    static async saveLocalProject(projectName, projectData) {
        const data = {
            name: projectName,
            data: projectData,
            updatedAt: Date.now()
        };

        try {
            const db = await this.getDB();
            await new Promise((resolve, reject) => {
                const tx = db.transaction(this.STORES.projects, 'readwrite');
                const store = tx.objectStore(this.STORES.projects);
                const req = store.put(data);
                req.onsuccess = () => resolve();
                req.onerror = () => reject(req.error);
            });
        } catch (e) {
            localStorage.setItem(`triviax_project_${projectName}`, JSON.stringify(data));
        }
    }

    /**
     * Carga un proyecto guardado localmente
     * @param {string} projectName 
     * @returns {Promise<Object|null>}
     */
    static async getLocalProject(projectName) {
        try {
            const db = await this.getDB();
            const res = await new Promise((resolve, reject) => {
                const tx = db.transaction(this.STORES.projects, 'readonly');
                const store = tx.objectStore(this.STORES.projects);
                const req = store.get(projectName);
                req.onsuccess = () => resolve(req.result);
                req.onerror = () => reject(req.error);
            });
            return res ? res.data : null;
        } catch (e) {
            const raw = localStorage.getItem(`triviax_project_${projectName}`);
            if (raw) {
                const parsed = JSON.parse(raw);
                return parsed ? parsed.data : null;
            }
            return null;
        }
    }

    /**
     * Obtiene todos los nombres de proyectos cached offline
     * @returns {Promise<Array<string>>}
     */
    static async getLocalProjectNames() {
        try {
            const db = await this.getDB();
            return new Promise((resolve, reject) => {
                const tx = db.transaction(this.STORES.projects, 'readonly');
                const store = tx.objectStore(this.STORES.projects);
                const req = store.getAllKeys();
                req.onsuccess = () => resolve(req.result);
                req.onerror = () => reject(req.error);
            });
        } catch (e) {
            const keys = [];
            for (let i = 0; i < localStorage.length; i++) {
                const key = localStorage.key(i);
                if (key.startsWith('triviax_project_')) {
                    keys.push(key.replace('triviax_project_', ''));
                }
            }
            return keys;
        }
    }

    // Auxiliares para LocalStorage de respaldo
    static getLocalStorageReports() {
        const raw = localStorage.getItem('triviax_reports');
        return raw ? JSON.parse(raw) : [];
    }
}
