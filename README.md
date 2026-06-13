# TRIVIAX — Juego Educativo de Trivia y Mesa

**TRIVIAX** es una aplicación web interactiva y modular diseñada para el aula escolar (estudiantes de ~12 años). Funciona como un juego de mesa de 50 casillas (inspirado en la dinámica del "Juego de la Oca"), donde los jugadores avanzan tirando un dado virtual y deben responder preguntas de opción múltiple para acumular puntos y evitar penalizaciones de turnos.

El backend está desarrollado en **PHP** para permitir la carga y lectura dinámica de diferentes proyectos de preguntas, y el cliente es una aplicación ágil de una sola página (SPA) construida en **HTML5, CSS3 (Vanilla) y JS Moderno**.

---

## 1. Requisitos e Instalación en Servidor Local (Wamp64 / Apache)

Dado que la aplicación lee archivos locales a través de PHP y realiza peticiones `fetch()`, requiere ejecutarse desde un servidor web.

### Instrucciones de Despliegue en Wamp64/Apache:
1. Copia toda la carpeta del proyecto `triviax` dentro de tu directorio `www` de Wamp64 (habitualmente en `C:\wamp64\www\triviax`).
2. Asegúrate de que el servidor Apache esté encendido desde el panel de control de Wamp64.
3. Abre tu navegador web e ingresa a:
   ```text
   http://localhost/triviax/
   ```
4. El juego cargará automáticamente el proyecto por defecto `informatica101`.

---

## 2. Cómo agregar nuevos Proyectos Educativos

El docente puede crear múltiples proyectos temáticos simplemente agregando carpetas dentro del directorio `proyectos/`. 

Cada proyecto debe tener la siguiente estructura exacta:
```text
proyectos/
├── tu_nuevo_proyecto/
│   ├── preguntas.txt       # Archivo de preguntas estructurado (UTF-8)
│   └── fondo.jpg           # Imagen de fondo para el tablero (Recomendado 16:9)
```

La aplicación detectará de forma automática el nuevo proyecto en el menú inicial y permitirá seleccionarlo para jugar. Las imágenes de fondo de los proyectos deben guardarse como `fondo.jpg`.

---

## 2.1. Acceso docente

Las páginas `admin.php` y `estadisticas.php` solicitan un código de acceso docente antes de mostrar su contenido.

El código debe coincidir con la llave `tkey` declarada en:

```text
../../dbconn/triviax.env
```

Ejemplo:

```text
tkey=mi_codigo_docente
```

Las acciones sensibles de la API, como reiniciar estadísticas, también requieren esa llave.

---

## 3. Formato del archivo `preguntas.txt`

El archivo de preguntas debe ser un archivo de texto plano guardado en codificación **UTF-8**. Puede incluir metadatos opcionales y bloques informativos que serán ignorados por el sistema de juego.

### A. Cabecera de Metadatos (Opcional)
Si aparece al inicio del archivo, el sistema leerá los siguientes campos para mostrarlos en la pantalla inicial:
```text
TITLE: Fundamentos de la informática I
AUTHOR: Prof. Luis Darwin Salina
NIVEL: S1
OBS: Tira los dados y contesta las preguntas para llegar a la meta
DATE: 28/05/2026
```
*Las claves no distinguen entre mayúsculas y minúsculas. Si se omiten, la aplicación usará valores por defecto.*

### B. Preguntas y Opciones
Cada pregunta debe iniciar con un número correlativo seguido de un punto y un espacio:
* Las opciones de respuesta inician con el símbolo `@`.
* La respuesta correcta se identifica colocando un asterisco `*` inmediatamente después del símbolo `@`.
* Las líneas vacías y las líneas informativas que empiecen con `#` (como `#### Bloque 2`) son ignoradas automáticamente por el parser.

**Ejemplo de formato:**
```text
1. ¿Cómo se define formalmente a la informática?
@ *La ciencia que estudia el tratamiento automático de la información mediante dispositivos digitales.
@ El estudio exclusivo del diseño de piezas físicas de una computadora.
@ La técnica de reparar cables de red y conectores telefónicos.

2. ¿Cuál es la unidad mínima de información digital?
@ El byte completo.
@ *El bit.
@ El Kilobyte de datos.
@ El Megabyte de datos.
```

### Reglas de Validación del Parser:
* Cada pregunta debe tener **entre 3 y 4 opciones** de respuesta.
* Debe existir **exactamente una respuesta correcta** por pregunta.
* Si el parser en PHP detecta alguna anomalía, bloqueará la partida e indicará al docente exactamente qué pregunta tiene el error de formato (ej. sin respuesta correcta, más de 4 opciones, etc.).

---

## 4. Reglas del Juego

1. **Jugadores**: Se admite de **2 a 4 jugadores** con nombres personalizables. Las fichas son de colores Rojo, Azul, Verde y Amarillo.
2. **Tablero**: Cuenta con 50 casillas dispuestas en forma de serpentina sobre la imagen de fondo.
3. **El Dado**: El dado virtual de 6 caras se lanza para definir la cantidad de casillas a avanzar.
4. **Flujo de Turno**:
   * El jugador tira el dado.
   * Se muestra una pregunta trivia.
   * **Respuesta Correcta**: el jugador avanza la cantidad indicada por el dado y gana **10 puntos**. El turno pasa al siguiente jugador.
   * **Respuesta Incorrecta o Tiempo Agotado**: por defecto el jugador no avanza y pierde su próximo turno, sin descuento de puntos.
   * Desde la lista desplegable de configuración se pueden elegir las variantes con descuento de **5 puntos** o con descuento de puntos más turno perdido.
5. **Puntuaciones**: Los puntos inician en 0 y nunca podrán disminuir por debajo de 0 (no hay puntajes negativos).
6. **Condición de Victoria**: Gana el primer jugador en alcanzar la casilla 50 (si avanza y supera la casilla 50, se ajusta automáticamente al límite de la casilla 50).
7. **Reutilización de Preguntas (Sesión)**:
   * Prioridad 1: Preguntas que aún no se han mostrado.
   * Prioridad 2: Preguntas contestadas incorrectamente o por timeout.
   * Prioridad 3: Preguntas contestadas correctamente (solo si se agotaron las anteriores).
   * Se evita la repetición consecutiva de la última pregunta mostrada.
