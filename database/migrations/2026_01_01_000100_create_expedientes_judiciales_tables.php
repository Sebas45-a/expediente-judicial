<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Esquema principal del sistema de Expedientes Judiciales.
 * Compatible con SQLite, MySQL/MariaDB y PostgreSQL.
 */
return new class extends Migration
{
    public function up(): void
    {
        // Tipos de expediente (cada tipo "aprende" su perfil a partir de los ejemplos)
        Schema::create('tipos_expediente', function (Blueprint $table) {
            $table->id();
            $table->string('nombre', 150)->unique();
            $table->string('slug', 160)->unique();
            $table->string('materia', 100)->nullable();
            $table->text('descripcion')->nullable();
            $table->string('color', 9)->default('#1f4e79');
            $table->json('perfil')->nullable();            // frecuencias de términos aprendidas
            $table->json('palabras_clave')->nullable();    // términos más representativos
            $table->json('campos_frecuentes')->nullable(); // columnas / datos que suele tener
            $table->unsignedInteger('total_ejemplos')->default(0);
            $table->decimal('precision_entrenamiento', 5, 2)->nullable();
            $table->timestamp('entrenado_en')->nullable();
            $table->timestamps();
        });

        // Historial de importaciones desde Excel
        Schema::create('importaciones_excel', function (Blueprint $table) {
            $table->id();
            $table->string('archivo_nombre');
            $table->string('archivo_ruta');
            $table->string('hoja')->nullable();
            $table->json('encabezados')->nullable();
            $table->json('mapeo')->nullable();
            $table->unsignedInteger('total_filas')->default(0);
            $table->unsignedInteger('importadas')->default(0);
            $table->unsignedInteger('actualizadas')->default(0);
            $table->unsignedInteger('omitidas')->default(0);
            $table->json('errores')->nullable();
            $table->string('estado', 30)->default('pendiente'); // pendiente|completada|con_errores|fallida
            $table->timestamps();
        });

        Schema::create('expedientes', function (Blueprint $table) {
            $table->id();
            $table->string('numero_expediente', 100)->nullable()->unique();
            $table->string('titulo');
            $table->foreignId('tipo_expediente_id')->nullable()->constrained('tipos_expediente')->nullOnDelete();
            $table->string('juzgado')->nullable();
            $table->string('demandante')->nullable();
            $table->string('demandado')->nullable();
            $table->date('fecha_inicio')->nullable();
            $table->string('estado', 30)->default('en_tramite');
            $table->text('descripcion')->nullable();
            $table->longText('contenido_texto')->nullable();
            $table->json('datos_extra')->nullable();
            $table->string('origen', 20)->default('manual'); // manual|excel|documento|plantilla
            $table->string('archivo_ruta')->nullable();
            $table->string('archivo_nombre')->nullable();
            $table->string('formato_archivo', 10)->nullable();
            $table->foreignId('importacion_excel_id')->nullable()->constrained('importaciones_excel')->nullOnDelete();
            $table->string('clasificado_por', 20)->nullable(); // manual|excel|aprendizaje|ia
            $table->decimal('clasificacion_confianza', 5, 2)->nullable();
            $table->timestamp('indexado_en')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['tipo_expediente_id', 'estado']);
            $table->index('fecha_inicio');
        });

        // Plantillas de formato aprendidas a partir de documentos reales
        Schema::create('plantillas_formato', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tipo_expediente_id')->constrained('tipos_expediente')->cascadeOnDelete();
            $table->string('nombre');
            $table->unsignedInteger('version')->default(1);
            $table->string('formato_origen', 10);
            $table->string('archivo_original_ruta')->nullable();
            $table->string('archivo_original_nombre')->nullable();
            $table->string('archivo_plantilla_ruta')->nullable(); // DOCX con ${campo}
            $table->longText('contenido_plantilla');             // texto con {{campo}}
            $table->unsignedInteger('muestras_analizadas')->default(1);
            $table->boolean('activa')->default(true);
            $table->boolean('uso_ia')->default(false);
            $table->timestamps();
        });

        Schema::create('campos_plantilla', function (Blueprint $table) {
            $table->id();
            $table->foreignId('plantilla_formato_id')->constrained('plantillas_formato')->cascadeOnDelete();
            $table->string('clave', 80);
            $table->string('etiqueta', 150);
            $table->string('tipo_dato', 30)->default('texto');
            $table->text('valor_ejemplo')->nullable();
            $table->unsignedInteger('ocurrencias')->default(1);
            $table->boolean('obligatorio')->default(true);
            $table->string('fuente', 20)->default('regla'); // regla|ia|manual
            $table->unsignedInteger('orden')->default(0);
            $table->timestamps();
            $table->unique(['plantilla_formato_id', 'clave']);
        });

        Schema::create('documentos_generados', function (Blueprint $table) {
            $table->id();
            $table->foreignId('plantilla_formato_id')->constrained('plantillas_formato')->cascadeOnDelete();
            $table->foreignId('expediente_id')->nullable()->constrained('expedientes')->nullOnDelete();
            $table->json('valores');
            $table->string('archivo_ruta');
            $table->timestamps();
        });

        // Fragmentos indexados para la IA (RAG)
        Schema::create('fragmentos_expediente', function (Blueprint $table) {
            $table->id();
            $table->foreignId('expediente_id')->constrained('expedientes')->cascadeOnDelete();
            $table->unsignedInteger('orden');
            $table->text('contenido');
            $table->longText('embedding')->nullable();
            $table->string('modelo_embedding', 100)->nullable();
            $table->timestamps();
            $table->index(['expediente_id', 'orden']);
        });

        Schema::create('consultas_ia', function (Blueprint $table) {
            $table->id();
            $table->foreignId('expediente_id')->nullable()->constrained('expedientes')->nullOnDelete();
            $table->text('pregunta');
            $table->longText('respuesta')->nullable();
            $table->string('modelo', 100)->nullable();
            $table->unsignedInteger('duracion_ms')->default(0);
            $table->json('fragmentos_usados')->nullable();
            $table->boolean('exito')->default(true);
            $table->text('error')->nullable();
            $table->timestamps();
        });

        Schema::create('configuraciones', function (Blueprint $table) {
            $table->string('clave', 100)->primary();
            $table->text('valor')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('configuraciones');
        Schema::dropIfExists('consultas_ia');
        Schema::dropIfExists('fragmentos_expediente');
        Schema::dropIfExists('documentos_generados');
        Schema::dropIfExists('campos_plantilla');
        Schema::dropIfExists('plantillas_formato');
        Schema::dropIfExists('expedientes');
        Schema::dropIfExists('importaciones_excel');
        Schema::dropIfExists('tipos_expediente');
    }
};
