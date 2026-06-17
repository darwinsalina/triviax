# Plan de implementación: Generador de Tableros TRIVIAX

**Fecha:** 2026-06-14
**Estado:** PLAN — nada implementado todavía.
**Idea base (del usuario):** un generador que, a partir de una imagen de tablero, produce un
prompt para que una IA devuelva el `customPositions[]` de ese tablero, y se guarda como un
archivo con nombre.

---

## 1. Objetivo

Dado **una imagen de tablero** que aporta el usuario, obtener un **archivo de configuración**
(`customPositions[]` + metadatos) que el `BoardEngine` ya sabe consumir, de modo que la ficha
recorra **exactamente** las casillas dibujadas en esa imagen.

---

## 2. Evaluación de tu idea (qué mantengo y qué le agrego)

| Tu propuesta | Veredicto | Ajuste que propongo |
|---|---|---|
| Generar un **prompt** para que una IA arme la matriz | ✅ Correcto y barato | El tool arma el prompt + adjunta la imagen; lo pegás en tu IA habitual. **Sin API key ni costo.** |
| La IA devuelve `customPositions[]` | ✅ Viable | Forzar **JSON estricto con esquema** y reglas de orden/sistema de coordenadas para minimizar errores. |
| Guardar como archivo con nombre | ✅ | Definir formato y carpeta estándar (`config/tableros/`). |
| *(faltaba)* | ⚠️ | **Paso de verificación visual + ajuste fino**: superponer los puntos sobre la imagen, animar una ficha de prueba, y permitir arrastrar puntos. Sin esto, las fichas caen ligeramente fuera. |

**Conclusión:** tu camino es el adecuado como motor de generación. El único agregado
imprescindible es el verificador/ajustador visual (es poco código y es lo que da calidad).

---

## 3. Flujo de uso (cómo se verá al final)

```
[1] Subir imagen del tablero  ──►  [2] El tool genera un PROMPT
                                          │
                                          ▼
                          [3] Pegás prompt + imagen en tu IA (Claude.ai / ChatGPT)
                                          │  (devuelve JSON)
                                          ▼
[6] Guardar tablero  ◄──  [5] Verificar/Ajustar  ◄──  [4] Pegar el JSON de vuelta en el tool
   (config/tableros/      (puntos sobre la imagen,
    nombre.json)          ficha de prueba, arrastrar)
```

Modo alternativo dentro del mismo editor: **click-to-place manual** (marcás casilla por
casilla con el mouse) como respaldo si la IA falla en algún tablero raro.

---

## 4. Formato del archivo de tablero

Archivo `config/tableros/<slug>.json`:

```json
{
  "id": "granja-serpenteante",
  "name": "Granja serpenteante",
  "image": "images/tableros/t9.png",
  "imageAspect": 1.55,
  "type": "map",
  "size": 50,
  "cellShape": "circle",
  "smoothPath": true,
  "customPositions": [
    { "x": 8,  "y": 88 },
    { "x": 14, "y": 84 },
    { "x": 21, "y": 86 }
  ],
  "specialCells": [],
  "createdAt": "2026-06-14",
  "source": "ai-prompt"
}
```

Reglas:
- Índice `0` = Salida, índice `size` = Meta. Largo del array = `size + 1`.
- Coordenadas en **porcentaje 0–100** sobre el lienzo virtual del `BoardEngine`.
- `imageAspect` (ancho/alto) para validar y para que el verificador encaje la imagen.

---

## 5. Diseño del prompt (corazón del generador)

El tool genera dinámicamente un prompt que incluye:

1. **Rol y tarea:** "Sos un extractor de coordenadas de tableros de juego. Devolvé SOLO JSON."
2. **Sistema de coordenadas:** origen arriba-izquierda, X→derecha 0–100, Y→abajo 0–100,
   relativo a la imagen completa.
3. **Reglas de orden (críticas):** seguir la numeración impresa en las casillas (Salida/START
   → 1 → 2 → … → META/FINISH). Si no hay números, seguir el sendero de inicio a fin.
4. **Tamaño esperado:** "Hay N casillas + Salida; devolvé exactamente N+1 puntos."
5. **Esquema de salida estricto** (el de la sección 4, solo el array `customPositions`).
6. **Datos de la imagen:** dimensiones en px y aspect ratio (los inyecta el tool al leer el archivo).
7. **Auto-chequeo:** "Verificá que el array tenga N+1 elementos y que X,Y estén en 0–100."

**Mejora opcional (mayor precisión):** el tool superpone sobre la imagen una **grilla numerada
ligera** (ej. 20×20 con etiquetas) antes de exportarla, y el prompt pide a la IA anclar cada
casilla a la celda de grilla visible. Esto reduce el error de estimación.

---

## 6. Pasos de implementación (por fases, cada una testeable)

### Fase 0 — Preparar el motor (independiente del generador)
- En `BoardEngine`: confirmar carga de `type:"map"` + `customPositions`, y añadir:
  - `cellShape` (`circle` | `rounded`) vía clase CSS.
  - `smoothPath`: en `renderPath`, opción de `path` Bézier/Catmull-Rom en vez de `polyline`.
- **Prueba:** crear a mano un JSON de 5 puntos y verificar que la ficha los recorre y que
  la imagen calza. *(Valida toda la cadena de consumo antes de tocar el generador.)*

### Fase 1 — Pantalla "Generador de Tableros" (panel admin/super)
- Nueva página (ej. `panel/generador_tableros.php` + `js/panel/boardGenerator.js`).
- Subir imagen (preview), campo de nombre, campo "cantidad de casillas".
- Botón **"Generar prompt"** → arma el texto (sección 5) y lo muestra con botón *Copiar*.
- **Prueba:** subir t9.png, generar prompt, comprobar que incluye dimensiones y N correcto.

### Fase 2 — Ingreso y validación del JSON
- Textarea **"Pegar respuesta de la IA"** + botón *Procesar*.
- Validaciones: JSON válido, largo = N+1, todos los puntos en 0–100, orden presente.
- Mostrar errores claros (ej. "esperaba 51 puntos, recibí 48").
- **Prueba:** pegar un JSON correcto y uno con errores; ver que valida/avisa bien.

### Fase 3 — Verificador visual + ajuste fino (el agregado clave)
- Render: imagen de fondo + puntos numerados + línea del recorrido encima.
- Botón **"Probar ficha"**: anima un token recorriendo los puntos (reusa `animateTokenPath`).
- **Arrastrar** cualquier punto para corregirlo (actualiza `customPositions` en vivo).
- Opción de marcar **casillas especiales** (bonus/castigo) — el motor ya las soporta.
- **Prueba:** sobre t9.png, comprobar que los puntos caen en las casillas; arrastrar 2-3 y
  ver que la ficha sigue el cambio.

### Fase 4 — Guardado y registro
- Guardar `config/tableros/<slug>.json` (endpoint PHP, validando de nuevo en servidor).
- Registro/listado de tableros disponibles (para elegirlos al crear un proyecto/partida).
- **Prueba:** guardar, recargar la lista, abrir el tablero guardado en el verificador.

### Fase 5 — Selección del tablero en el juego
- Al crear/configurar un proyecto, elegir tablero del registro.
- El juego carga `BoardEngine.init(image, config)` con ese JSON.
- **Prueba:** jugar una partida real sobre un tablero generado y confirmar el recorrido.

### Fase 6 (opcional, futuro) — Automatización por API
- Botón "Generar automáticamente" que llama a la API de Claude (visión) y trae el JSON sin
  copiar/pegar. Requiere key en el `.env` de producción y tiene costo por uso.
- Mantener el modo manual (pegar prompt) siempre como respaldo gratuito.

---

## 7. Riesgos y mitigaciones

| Riesgo | Mitigación |
|---|---|
| La IA estima coordenadas con error (±2-3%) | Verificador visual + arrastre (Fase 3). |
| La IA equivoca el orden de casillas | Prompt exige seguir números impresos + validación de largo; el verificador muestra la línea para detectarlo de un vistazo. |
| Imagen con relación de aspecto distinta al contenedor | Guardar `imageAspect`; el contenedor respeta esa proporción. |
| Tableros sin números (t8) | Para esos, usar el modo click-to-place manual. |
| Inyección/JSON malicioso pegado | Validación estricta cliente **y** servidor antes de guardar. |

---

## 8. Decisiones

1. **Ubicación:** ✅ DECIDIDO — el generador va **solo en el panel super (admin)**.
   No será accesible para docentes.
2. **Modo IA:** ¿arrancamos solo con el modo "copiar prompt / pegar respuesta" (gratis), y
   dejamos la API directa para después? (mi recomendación: sí)
3. **Tamaño de casillas variable:** ¿querés permitir tableros de N≠50 casillas desde el inicio?
4. **¿Arranco por la Fase 0** (motor) para validar toda la cadena con un JSON hecho a mano,
   antes de construir la UI del generador?

---

*Recomendación de orden: Fase 0 → 1 → 2 → 3 primero. Con esas cuatro ya tenés un generador
funcional y probable de punta a punta sobre tus imágenes actuales. Las fases 4–6 son
integración y automatización.*
