@echo off
REM ===== Inicia Expediente Judicial en http://127.0.0.1:8000 =====
cd /d "%~dp0public"
where ollama >nul 2>nul && (start "" /min ollama serve) || echo [AVISO] Ollama no encontrado: la IA no estara disponible. Instale desde https://ollama.com
start "" http://127.0.0.1:8000
php -d upload_max_filesize=50M -d post_max_size=55M -d memory_limit=512M -d max_execution_time=600 -S 127.0.0.1:8000 ..\vendor\laravel\framework\src\Illuminate\Foundation\resources\server.php
