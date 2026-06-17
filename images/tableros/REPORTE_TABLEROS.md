# Reporte: viabilidad de tableros más atractivos en TRIVIAX

**Fecha:** 2026-06-14
**Autor:** análisis técnico (Claude Code)
**Alcance:** solo análisis. No se implementó nada.

---

## 1. Resumen ejecutivo

**Sí se puede hacer, y con menos esfuerzo del esperado**, porque el motor actual ya tiene
el 80% de la infraestructura necesaria. La clave es que las casillas **no están "horneadas"
dentro de la imagen de fondo**: se dibujan por encima en coordenadas porcentuales (X%, Y%)
calculadas en código. Eso significa que podemos poner las casillas donde queramos —
incluyendo recorridos curvos, libres o en espiral como los de tus ejemplos.

El verdadero trabajo no es "rediseñar el motor", sino **resolver cómo obtener las coordenadas
de cada casilla para una imagen de tablero arbitraria** y que la ficha siga ese recorrido
exacto al avanzar. Para eso propongo un **editor visual de tableros** (ver sección 5).

**Herramientas a descargar: ninguna obligatoria.** Todo se resuelve con HTML/SVG/JS que ya
usamos. Hay una opción avanzada (visión por computadora con Python/OpenCV) pero **no la
recomiendo** como primer paso.

---

## 2. Qué tenemos hoy

Dos motores conviven:

- [`js/board.js`](../../js/board.js) — versión vieja, **serpentina fija** de 50 casillas
  (10×5 horizontal / 5×10 vertical). Es la más "rígida".
- [`js/engines/boardEngine.js`](../../js/engines/boardEngine.js) — versión nueva, **ya soporta
  varios tipos de recorrido**:
  - `serpentine` (oca clásica)
  - `linear` (línea recta)
  - `circular` (espiral hacia el centro)
  - `circular-loop` (anillo cerrado)
  - `rectangular-loop` (perímetro tipo Monopoly)
  - `map` → **usa `customPositions[]`, es decir coordenadas manuales por casilla**

Cómo funciona el dibujado (importante para entender la viabilidad):

1. Se pone la imagen de fondo (`board-background`).
2. Una capa **SVG** dibuja la línea del recorrido como una `polyline` que une los centros
   de las casillas (`renderPath`).
3. Una capa de **casillas** (`<div>` posicionados con `left:%`, `top:%`) dibuja cada número.
4. Una capa de **fichas** posiciona los tokens en las mismas coordenadas y los anima
   casilla por casilla (`animateTokenMove` / `animateTokenPath`).

Todo se mueve en un viewBox virtual `0..100` (porcentajes), así que es **independiente de la
resolución** de la imagen y responsivo.

> Conclusión técnica: el tipo `map` con `customPositions` ya es exactamente el mecanismo que
> necesitamos para tableros de forma libre. Está implementado pero **no se está explotando**.

---

## 3. Análisis de tus 12 ejemplos

Clasifiqué cada imagen por **forma de recorrido** y **forma de casilla**, que son las dos
variables que definen la dificultad de reproducirlo.

| Img | Tema | Recorrido | Casillas | Nº | Dificultad |
|-----|------|-----------|----------|-----|-----------|
| t1  | Granja | Meandro libre (serpentea sin cuadrícula) | Cuadrado redondeado, colores alternos | sin números | Media |
| t2  | Hadas/cielo | Serpentina rectangular con esquinas redondeadas | Cuadrada | 1–50 | **Baja** |
| t3  | Parque | Curva sinuosa ancha | Círculo | 1–24 | Media |
| t4  | Espacio | "S" tipo serpiente sobre cinta gruesa | Círculo blanco sobre cinta | 1–30 | Media |
| t5  | Granja | Doble "S" compacta | Círculo | 1–34 | Media |
| t6  | Monstruos | Serpentina horizontal | Círculo | 1–50 | **Baja** |
| t7  | Bosque | **Espiral concéntrica** (fuera→centro) | Círculo | START→FINISH | Media |
| t8  | Pueblo/mapa | **Sendero libre tipo Candy Land** (sin grilla) | Tramos de camino, sin casilla discreta | sin números | **Alta** |
| t9  | Granja | Meandro libre | Círculo | 1–50 | Media |
| t10 | Espacio | Serpentina rectangular | Círculo | 1–22 | **Baja** |
| t11 | Monstruos | Curva sinuosa | Cuadrado redondeado | 1–~50 | Media |
| t12 | Sol/nubes | **Serpentina multi-bucle irregular** | Cuadrada | 1–52 | Media-alta |

### Patrones que se repiten (y cómo los cubrimos)

1. **Serpentina regular** (t2, t6, t10, parte de t12) → ya soportada por `serpentine`. Solo hay
   que ajustar márgenes/filas para calzar con la imagen. *Reproducción casi inmediata.*
2. **Espiral** (t7) → ya soportada por `circular`. Ajuste de vueltas/radio. *Inmediata.*
3. **Meandro / curva libre** (t1, t3, t4, t5, t9, t11) → requiere `map` con coordenadas por
   casilla. **Aquí es donde aporta el editor visual.** Una vez tenemos los puntos, el motor
   los dibuja sin problema.
4. **Sendero continuo sin casillas discretas** (t8) → es el caso más distinto a nuestro modelo
   (no hay "casilla 1, 2, 3" claras). Reproducible, pero exige decidir manualmente dónde caen
   los puntos de parada. *Lo dejaría para una fase posterior.*

### Sobre la forma de las casillas

Hoy las casillas son círculos por CSS. Pasar a **cuadrado redondeado** (t1, t2, t11, t12) es
un cambio trivial de CSS (`border-radius`), e incluso puede ser una propiedad por-tablero
(`cellShape: 'circle' | 'rounded'`). Colores alternos (t1) = otra clase CSS por paridad.

---

## 4. Lo que falta para lograrlo

El motor sabe **dibujar** cualquier recorrido si le das las coordenadas. Lo que no existe es:

1. **Una fuente de coordenadas por imagen.** Hoy se calculan con fórmulas; para una imagen
   arbitraria hay que tener un JSON `customPositions` por tablero.
2. **Identificar qué tablero está en uso** y cargar su JSON (asociar imagen ↔ recorrido).
3. **Curvas suaves opcionales.** El recorrido hoy es `polyline` (líneas rectas entre puntos).
   Para que se vea como tus ejemplos conviene migrar a `path` con curvas Bézier/Catmull-Rom.
   Cambio acotado en `renderPath`.
4. **Forma/estilo de casilla configurable** (círculo vs. redondeado, color alterno).

---

## 5. Cómo lo resolvería (recomendación)

### Enfoque recomendado: **Editor visual de tableros** (sin descargar nada)

Una pantalla nueva en el panel docente / super donde:

1. Cargás la imagen del tablero (la que vos generaste).
2. Hacés clic sobre cada casilla en orden (Salida → 1 → 2 → … → Meta). Cada clic guarda
   un `{x%, y%}`.
3. Opcional: editás tipo de casilla, casillas especiales (bonus/castigo, que el motor **ya
   soporta**), curva suave sí/no.
4. Se exporta un JSON tipo:
   ```json
   {
     "name": "Granja serpenteante",
     "image": "tableros/t9.png",
     "type": "map",
     "size": 50,
     "cellShape": "circle",
     "smoothPath": true,
     "customPositions": [
       {"x": 8,  "y": 88},   // 0 = Salida
       {"x": 14, "y": 84},   // 1
       {"x": 21, "y": 86}    // ...
     ]
   }
   ```

Ese JSON se guarda junto al tablero y el juego lo carga con `BoardEngine.init(img, config)`.
La ficha seguirá **exactamente** esos puntos porque la animación recorre el array de
posiciones casilla por casilla. Resuelve de raíz tu requisito de "identificar el tablero y
ajustar el recorrido correcto".

**Ventajas:** preciso, sin dependencias nuevas, reutiliza casi todo lo existente, te da
control total sobre dónde cae cada ficha.

**Costo estimado:** editor (1 pantalla JS) + soporte de carga de JSON en el motor + estilos
de casilla + curvas Bézier. Es la mayor parte del trabajo, pero es trabajo de UI conocido.

### Yo puedo acelerar el sembrado de puntos

Como puedo "ver" las imágenes, para cada tablero nuevo puedo generar una **primera versión
del `customPositions`** estimando las coordenadas a ojo, y vos solo **afinás** los puntos en
el editor en vez de marcarlos todos desde cero. La precisión de mi estimación es buena pero
no perfecta (±2-3%), por eso el editor sigue siendo necesario para el ajuste fino.

### Opción avanzada (NO recomendada por ahora): visión por computadora

Detectar automáticamente los círculos/cuadros numerados con **Python + OpenCV** (Hough
Circles / detección de contornos + OCR de números con Tesseract). 

- **Pros:** podría auto-generar coordenadas de tableros muy regulares.
- **Contras:** frágil con fondos ilustrados como los tuyos (falsos positivos con flores,
  planetas, animales), requiere instalar Python/OpenCV/Tesseract, y **el orden del recorrido
  igual hay que inferirlo o corregirlo a mano**. Más complejidad para un resultado dudoso.
- **Veredicto:** solo valdría la pena si en el futuro hay que importar cientos de tableros
  ajenos. Para tu caso (vos generás las imágenes), el editor manual + mi pre-sembrado gana.

---

## 6. Plan por fases sugerido (cuando decidas implementar)

- **Fase 0 — Quick win sin editor:** afinar `serpentine`, `circular` y añadir `cellShape`
  redondeado + colores alternos. Con esto ya reproducimos t2, t6, t7, t10 y mejoramos la
  sensación "rígida" de inmediato.
- **Fase 1 — Editor visual + tipo `map`:** desbloquea los meandros libres (t1, t3, t4, t5,
  t9, t11) y la asociación imagen↔recorrido.
- **Fase 2 — Curvas Bézier en el camino:** acabado visual igual a los ejemplos.
- **Fase 3 — Senderos libres tipo t8** (sin casillas discretas), si interesa ese estilo.

---

## 7. Respuestas directas a tus preguntas

- **¿Podemos tener interfaces así?** Sí. La arquitectura ya separa fondo / casillas / fichas
  y ya existe el modo de coordenadas libres (`map`).
- **¿Puedo ver las imágenes y analizar el recorrido?** Sí, lo hice (sección 3).
- **¿Hay que descargar herramientas?** No es obligatorio. Todo con la pila actual. OpenCV es
  opcional y no recomendado de entrada.
- **¿Puedo identificar el tablero y ajustar el recorrido de las fichas?** Sí: con el JSON de
  `customPositions` por tablero, el motor mueve la ficha exactamente por ese recorrido. Y yo
  puedo pre-generar esos puntos a partir de cada imagen para ahorrarte trabajo.

---

*Siguiente paso sugerido: si te convence, arranco por la Fase 0 (cambios de CSS + ajustes de
serpentina/espiral) que ya quita la rigidez actual sin tocar la lógica de juego, y en paralelo
prototipo el editor visual.*
