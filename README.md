# ⚖ Expediente Judicial — Laravel · Arquitectura N capas · IA local (Ollama)

Sistema web para el Poder / Organismo Judicial que:

1. **Gestiona expedientes** (registro, búsqueda, filtros, documentos adjuntos, exportación a Excel).
2. **Importa expedientes desde Excel** reconociendo automáticamente las columnas.
3. **Aprende cada tipo de expediente** (clasificador Naive Bayes) y clasifica solo los nuevos.
4. **Aprende el formato** de cada tipo a partir de un documento real y deja en blanco **solo los datos importantes**.
5. **Asistente IA 100 % local con Ollama**, robusto y sin errores no controlados.
6. **Apartado de Base de Datos**: tablas, explorador, respaldo, optimización y migraciones.

Todo se ejecuta en su equipo o servidor: ningún documento sale a internet.

---

## Arquitectura en N capas

```
┌──────────────────────────────────────────────────────────────────────┐
│ PRESENTACIÓN   app/Http (Controllers, Requests, Middleware)          │
│                resources/views (Blade) · public/css · public/js      │
├──────────────────────────────────────────────────────────────────────┤
│ APLICACIÓN     app/Application/Services                              │
│   ExpedienteService · ImportacionExcelService · AprendizajeTipos     │
│   AprendizajeFormato · AsistenteIaService · BaseDatosService         │
├──────────────────────────────────────────────────────────────────────┤
│ DOMINIO        app/Domain   (PHP puro, sin Laravel)                  │
│   Enums · ValueObjects · Exceptions · Contracts (interfaces)         │
│   Services: DetectorCamposImportantes · ClasificadorNaiveBayes       │
│             MarcadorCampos · FragmentadorTexto · NormalizadorTexto   │
├──────────────────────────────────────────────────────────────────────┤
│ INFRAESTRUCTURA app/Infrastructure                                   │
│   Persistence (Eloquent Models + Repositories) · IA/OllamaClient     │
│   Documentos (Word/PDF/Excel → texto, plantillas DOCX)               │
│   HojaCalculo (PhpSpreadsheet)                                       │
└──────────────────────────────────────────────────────────────────────┘
        app/Providers/CapasServiceProvider.php conecta interfaces ↔ implementaciones
```

- Cada capa solo conoce a la de abajo **a través de interfaces** (`app/Domain/Contracts`).
- Cambiar Ollama por otro motor, o Eloquent por otro ORM, solo requiere una clase nueva y cambiar el *binding* en `CapasServiceProvider`.
- Los errores del dominio (`ExpedienteException`, `OllamaException`) se convierten automáticamente en mensajes claros en pantalla (`bootstrap/app.php`), nunca en una página de error.

---

## Requisitos

| Componente | Versión |
|---|---|
| PHP | 8.2 o superior, con extensiones `zip`, `dom`, `mbstring`, `gd`, `fileinfo`, `pdo_sqlite` (o `pdo_mysql`) |
| Composer | 2.x — https://getcomposer.org |
| Ollama (opcional, para la IA) | https://ollama.com |
| Base de datos | SQLite (incluida, no requiere instalar nada) o MySQL/MariaDB/PostgreSQL |

> En Windows lo más sencillo es **Laragon** o **XAMPP** (ya traen PHP). No se necesita Node.js.

---

## Instalación

### Windows
1. Descomprima el proyecto.
2. Doble clic en **`instalar.bat`** (instala dependencias, crea la base SQLite y los tipos iniciales).
3. Doble clic en **`iniciar.bat`** → se abre http://127.0.0.1:8000

### Linux / macOS / terminal de VS Code
```bash
composer run setup          # instala, crea .env, base de datos y tipos iniciales
php artisan db:seed --class=DemoSeeder   # (opcional) 10 expedientes de demostración
./iniciar.sh                # o: composer run dev
```

### Como programa de escritorio (instalador .exe para cualquier PC)
Vea **[desktop/README.md](desktop/README.md)**. En resumen: doble clic en `construir-instalador.bat`
(o use GitHub Actions) y obtendrá `ExpedienteJudicial-Instalador-<versión>.exe`, que se instala
en cualquier PC con Windows 10/11 **sin PHP, Composer ni XAMPP**.

### Usar MySQL en lugar de SQLite
En `.env`:
```
DB_CONNECTION=mysql
DB_HOST=127.0.0.1
DB_PORT=3306
DB_DATABASE=expedientes
DB_USERNAME=root
DB_PASSWORD=
```
Cree la base `expedientes` y ejecute `php artisan migrate --seed`.

---

## IA local con Ollama (configuración para que funcione sin errores)

```bash
ollama pull llama3.2:3b          # modelo de chat (ligero, funciona en CPU)
ollama pull nomic-embed-text     # búsqueda semántica (recomendado)
```
Deje Ollama abierto. En **Asistente IA → Probar conexión** verá el resultado.

| Equipo | Modelo recomendado (`OLLAMA_MODELO` en `.env`) |
|---|---|
| 8 GB RAM, sin GPU | `llama3.2:1b` o `qwen2.5:1.5b` |
| 16 GB RAM | `llama3.2:3b` (por defecto) o `qwen2.5:7b` |
| GPU dedicada | `llama3.1:8b`, `qwen2.5:14b` |

**Qué hace el cliente para no fallar** (`app/Infrastructure/IA/OllamaClient.php`):
- Verifica conexión y modelos instalados antes de cada uso (caché de 20 s).
- Si el modelo configurado **no está descargado**, usa otro modelo instalado y lo avisa (`OLLAMA_MODELO_ALTERNATIVO=true`).
- Reintenta errores de conexión y errores 500/503 (modelo cargando); **no** reintenta tiempos agotados.
- Compatible con Ollama nuevo (`/api/embed`) y antiguo (`/api/embeddings`).
- Elimina el razonamiento `<think>…</think>` de modelos como deepseek-r1 o qwen3.
- Recorta el contexto según `OLLAMA_NUM_CTX` para no desbordar el modelo.
- Cada error se traduce a un mensaje en español con la solución (p. ej. *"Ejecute: ollama pull llama3.2:3b"*).
- Si Ollama está apagado, **todo el sistema sigue funcionando**; solo las funciones de IA muestran el aviso.
- Respuestas basadas **solo** en el expediente (RAG): expedientes pequeños se envían completos; los grandes se fragmentan y se buscan por similitud semántica (o por palabras clave si no hay modelo de embeddings).

Variables en `.env`: `OLLAMA_URL`, `OLLAMA_MODELO`, `OLLAMA_MODELO_EMBEDDINGS`, `OLLAMA_TIMEOUT`, `OLLAMA_REINTENTOS`, `OLLAMA_NUM_CTX`, `OLLAMA_TEMPERATURA`, `UMBRAL_CLASIFICACION`.

---

## Cómo se usa

### 1. Importar expedientes desde Excel
**Importar desde Excel** → suba `.xlsx`, `.xls` o `.csv` → revise la asignación de columnas → **Importar**.

- Detecta la fila de encabezados aunque haya títulos arriba.
- Reconoce sinónimos: *No. de causa, Expediente, Clase de juicio, Actor, Agraviado, Sindicado, Situación, Fecha de ingreso…*
- Fechas en `15/03/2024`, `2024-03-15`, `15 de marzo de 2024` o formato de fecha de Excel.
- Estados libres (*"SENTENCIADO"*, *"archivo"*, *"audiencia"*) se normalizan.
- Si el número de expediente ya existe: **actualiza** (sin borrar datos con celdas vacías) u **omite**.
- Columnas extra (Cuantía, Abogado…) se guardan como datos del expediente.
- Al terminar **reentrena el aprendizaje** y clasifica las filas que venían sin tipo.

Pruebe con `ejemplos/expedientes_ejemplo.xlsx` o descargue la plantilla desde la pantalla.

### 2. Aprendizaje de tipos de expediente
**Tipos de expediente** muestra lo aprendido de cada tipo: palabras que lo distinguen, datos que suele traer, juzgados frecuentes y la precisión estimada (validación cruzada).
- *Reentrenar*, *Clasificar los «sin tipo»*, *Probar el clasificador*, *Fusionar* tipos duplicados.
- Si el aprendizaje duda (confianza < `UMBRAL_CLASIFICACION`), opcionalmente consulta a Ollama, que solo puede elegir entre los tipos existentes.

### 3. Aprendizaje de formato (espacios en blanco)
**Formatos aprendidos → Aprender un formato** → suba un expediente real (Word recomendado).
1. Se detectan los datos importantes: número de expediente, juzgado, nombres por rol (demandante, demandado, sindicado, agraviado, juez, abogado…), fechas y horas (en números o en letras), DPI, NIT, colegiado, montos en números y en letras, direcciones, teléfonos, correos, edad, estado civil, profesión y líneas «Etiqueta: valor».
2. Opcionalmente la IA busca datos adicionales; **cada valor propuesto se verifica contra el documento** y se descarta si no aparece exactamente.
3. Usted revisa: desmarca lo que sea texto fijo, corrige etiquetas o **selecciona con el mouse** cualquier texto del documento para dejarlo en blanco.
4. Se crea una plantilla Word que **conserva el diseño original** (fuentes, márgenes, encabezados, tablas, logos) aunque Word haya partido un nombre en varios fragmentos con formatos distintos.
5. Con una **segunda muestra** del mismo tipo, el sistema compara: si un dato es idéntico al de la muestra anterior, lo propone como texto fijo. Cada muestra crea una versión nueva.

**Usar el formato:** *Completar* → llene los datos (o tómelos de un expediente, o *Completar con IA*) → **Generar documento Word**. También puede descargar el **formato en blanco** con líneas para llenar a mano.

Pruebe con `ejemplos/demanda_pension_ejemplo.docx`.

### 4. Asistente IA
- En cada expediente: preguntas, *Resumen* ejecutivo e historial.
- En **Asistente IA**: consultas sobre todos los expedientes, estado de Ollama, cambio de modelo y guía de instalación.

### 5. Base de datos
Motor, tamaño, tablas con número de registros, explorador de solo lectura con búsqueda, **descarga de respaldo** (archivo SQLite o volcado JSON para MySQL/PostgreSQL), optimización, estado de migraciones y vaciado del índice de la IA.

---

## Estructura de la base de datos

| Tabla | Contenido |
|---|---|
| `expedientes` | Datos del expediente, texto completo, datos extra (JSON), origen, clasificación |
| `tipos_expediente` | Tipos y su perfil aprendido (palabras clave, campos frecuentes, precisión) |
| `importaciones_excel` | Historial de importaciones, mapeo de columnas y observaciones |
| `plantillas_formato` / `campos_plantilla` | Formatos aprendidos (versiones) y sus espacios en blanco |
| `documentos_generados` | Documentos producidos con cada formato |
| `fragmentos_expediente` | Índice de la IA (fragmentos + embeddings) |
| `consultas_ia` | Historial de preguntas, respuestas, modelo y duración |
| `configuraciones` | Modelo de IA elegido y modelo del clasificador |

---

## Pruebas automáticas

```bash
php artisan test        # o: vendor/bin/phpunit
```
23 pruebas cubren: detector de campos, clasificador, cliente Ollama (apagado, modelo faltante, modelo alternativo, error 500 con reintento, `<think>`, Ollama antiguo, JSON con texto alrededor), importación de Excel (sinónimos, fechas, estados, duplicados, clasificación automática), aprendizaje de formato con un Word real (nombres partidos en varios fragmentos, texto fijo intacto, caracteres especiales como `&`, IA que inventa valores) y todas las pantallas con Ollama apagado.

---

## Solución de problemas

| Problema | Solución |
|---|---|
| «No se pudo conectar con Ollama» | Abra Ollama (`ollama serve`) y verifique `OLLAMA_URL`. |
| «El modelo … no está descargado» | `ollama pull <modelo>` o elija otro en *Asistente IA → Cambiar modelo*. |
| La IA tarda demasiado | Use un modelo más pequeño o suba `OLLAMA_TIMEOUT`. |
| Error al subir archivos grandes | Use `iniciar.bat`/`iniciar.sh` (ya amplían el límite a 50 MB) o ajuste `upload_max_filesize` y `post_max_size` en `php.ini`. |
| PDF sin texto | Es un PDF escaneado: aplíquele OCR antes de cargarlo. |
| Archivo `.doc` antiguo | Ábralo en Word y guárdelo como `.docx`. |

---

## Cambios respecto a la versión anterior (Python/CustomTkinter)

- Aplicación web en **Laravel 12** con arquitectura **N capas** e inyección de dependencias.
- Base de datos con migraciones; SQLite por defecto y soporte para MySQL/PostgreSQL.
- Nuevo: **importación masiva desde Excel** con mapeo inteligente de columnas.
- Nuevo: **aprendizaje real de tipos** (antes el tipo se elegía siempre a mano).
- Formatos: antes se copiaba el archivo completo como plantilla; ahora se detectan los **datos importantes**, se dejan **en blanco** y se generan documentos rellenos.
- IA: se eliminó la dependencia de ChromaDB/LangChain (fuente frecuente de errores de instalación); el índice vive en la misma base de datos y el cliente de Ollama maneja todos los fallos.

### Próximos pasos sugeridos
- Inicio de sesión con usuarios y roles (Laravel Breeze/Fortify) antes de publicarlo en red.
- OCR para PDF escaneados (Tesseract).
- Cola de trabajos (`php artisan queue:work`) para importaciones de decenas de miles de filas.
