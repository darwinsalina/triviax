# Copias de Seguridad y Restauración (BACKUP & RESTORE)

Este documento detalla las políticas y comandos recomendados para realizar copias de seguridad de la base de datos MySQL, el sistema de archivos de las actividades de TRIVIAX, y la configuración del servidor web.

---

## 1. Copia de Seguridad de la Base de Datos

La base de datos `triviax` almacena la información crítica de usuarios (docentes/estudiantes), metadatos de proyectos y registros históricos de partidas, sesiones e intentos.

### 1.1. Mediante la Consola (mysqldump)
Si estás utilizando WAMP, ejecuta el siguiente comando en la terminal para exportar toda la estructura y datos de la base de datos a un archivo `.sql`:

```powershell
# Exportar base de datos completa a un archivo sql comprimido por fecha
C:\wamp64\bin\mysql\mysql8.0.31\bin\mysqldump.exe -u root -p triviax > backup_triviax_db.sql
```
*(Nota: Ajusta la ruta del binario `mysqldump.exe` a la versión de MySQL activa en tu servidor local).*

### 1.2. Mediante phpMyAdmin
1. Abre phpMyAdmin en tu navegador (`http://localhost/phpmyadmin`).
2. Selecciona la base de datos `triviax` en la barra lateral izquierda.
3. Haz clic en la pestaña **Exportar**.
4. Selecciona el método de exportación **Rápido** y el formato **SQL**.
5. Presiona **Exportar** y guarda el archivo `.sql` descargado en un sitio seguro.

---

## 2. Restauración de la Base de Datos

### 2.1. Mediante la Consola (MySQL)
Para restaurar una base de datos a partir de un volcado `.sql`, primero asegúrate de que la base de datos `triviax` existe y luego ejecuta:

```powershell
C:\wamp64\bin\mysql\mysql8.0.31\bin\mysql.exe -u root -p triviax < backup_triviax_db.sql
```

### 2.2. Mediante phpMyAdmin
1. Crea una base de datos nueva llamada `triviax` si no existe.
2. Selecciónala en el panel izquierdo.
3. Ve a la pestaña **Importar**.
4. Selecciona el archivo `.sql` de respaldo en tu equipo.
5. Presiona **Importar** en la parte inferior de la página.

---

## 3. Respaldo del Sistema de Archivos y Configuración

Además de la base de datos, es indispensable respaldar los siguientes elementos físicos:

### 3.1. Proyectos de Actividades (Filesystem)
La carpeta `/proyectos/` en la raíz de TRIVIAX contiene los bancos de preguntas (`preguntas.txt`, `proyecto.json`) y archivos multimedia cargados por los docentes.
- **Ruta a respaldar:** `C:\wamp64\www\triviax\proyectos`
- **Recomendación:** Comprimir esta carpeta periódicamente.

### 3.2. Archivos de Configuración del Servidor (.env)
La conexión a base de datos y llaves criptográficas sensibles residen fuera del directorio web por seguridad.
- **Ruta a respaldar:** `C:\wamp64\dbconn\triviax.env` y `C:\wamp64\dbconn\dbkey_triviax.php`
- **Importancia:** Sin estos archivos, el servidor no podrá conectarse a MySQL ni verificar firmas CSRF o sesiones tras una restauración.
