<?php

/*
|--------------------------------------------------------------------------
| IA local con Ollama
|--------------------------------------------------------------------------
| Todo se ejecuta en el equipo/servidor: ningún documento sale de la red.
| Instalación: https://ollama.com  →  ollama pull llama3.2:3b  →  ollama pull nomic-embed-text
*/

return [
    'url' => rtrim(env('OLLAMA_URL', 'http://127.0.0.1:11434'), '/'),

    // Modelo de chat (se puede cambiar desde la pantalla "Asistente IA")
    'modelo' => env('OLLAMA_MODELO', 'llama3.2:3b'),

    // Modelo para búsqueda semántica. Si no está instalado se usa búsqueda por palabras clave.
    'modelo_embeddings' => env('OLLAMA_MODELO_EMBEDDINGS', 'nomic-embed-text'),

    // Si el modelo configurado no está descargado, usar automáticamente otro modelo instalado.
    'usar_modelo_alternativo' => (bool) env('OLLAMA_MODELO_ALTERNATIVO', true),

    'timeout' => (int) env('OLLAMA_TIMEOUT', 180),          // segundos para generar respuesta
    'timeout_conexion' => (int) env('OLLAMA_TIMEOUT_CONEXION', 5),
    'reintentos' => (int) env('OLLAMA_REINTENTOS', 2),
    'keep_alive' => env('OLLAMA_KEEP_ALIVE', '10m'),

    'opciones' => [
        'temperature' => (float) env('OLLAMA_TEMPERATURA', 0.2),
        'num_ctx' => (int) env('OLLAMA_NUM_CTX', 8192),
        'num_predict' => (int) env('OLLAMA_NUM_PREDICT', 1024),
    ],

    // RAG
    'fragmento_tamano' => 1000,
    'fragmento_solape' => 150,
    'fragmentos_contexto' => (int) env('OLLAMA_TOP_K', 6),

    // Clasificación de tipos: confianza mínima del aprendizaje antes de consultar a la IA
    'umbral_clasificacion' => (float) env('UMBRAL_CLASIFICACION', 0.60),
];
