#!/usr/bin/env bash
# Inicia Expediente Judicial en http://127.0.0.1:8000 (Linux / macOS)
cd "$(dirname "$0")/public" || exit 1
command -v ollama >/dev/null && (pgrep -x ollama >/dev/null || (ollama serve >/dev/null 2>&1 &)) || echo "[AVISO] Ollama no encontrado: https://ollama.com"
exec php -d upload_max_filesize=50M -d post_max_size=55M -d memory_limit=512M -d max_execution_time=600 \
  -S 127.0.0.1:8000 ../vendor/laravel/framework/src/Illuminate/Foundation/resources/server.php
