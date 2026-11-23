<?php

namespace Tests\Feature;

use App\Infrastructure\Persistence\Models\Expediente;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class PantallasTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['ollama.reintentos' => 0]);
        Http::fake(['*' => Http::failedConnection()]);
        $this->seed(DemoSeeder::class);
    }

    public function test_todas_las_pantallas_cargan_aunque_ollama_este_apagado(): void
    {
        $e = Expediente::first();
        foreach (['/', '/expedientes', '/expedientes/create', "/expedientes/{$e->id}", "/expedientes/{$e->id}/edit", '/importar', '/tipos', '/tipos/1',
            '/formatos', '/formatos/aprender', '/asistente', '/base-datos', '/base-datos/tabla/expedientes', '/expedientes?tipo=sin&buscar=pension'] as $url) {
            $this->get($url)->assertOk();
        }
        $this->get('/base-datos/tabla/users')->assertRedirect(); // tabla no permitida
    }

    public function test_crud_de_expediente_con_clasificacion_automatica(): void
    {
        $this->post(route('expedientes.store'), [
            'titulo' => 'Solicitud de pensión de alimentos', 'estado' => 'en_tramite',
            'descripcion' => 'La madre solicita pensión alimenticia para sus hijos menores',
        ])->assertRedirect();
        $e = Expediente::latest('id')->first();
        $this->assertSame('Pensión Alimenticia', $e->tipo->nombre);
        $this->assertSame('aprendizaje', $e->clasificado_por);

        $this->put(route('expedientes.update', $e), ['titulo' => 'Editado', 'estado' => 'resuelto'])->assertRedirect();
        $this->assertSame('resuelto', $e->fresh()->estado->value);
        $this->delete(route('expedientes.destroy', $e))->assertRedirect();
        $this->assertSoftDeleted($e);
    }

    public function test_pregunta_a_la_ia_sin_ollama_devuelve_mensaje_claro(): void
    {
        $r = $this->postJson(route('asistente.preguntar'), ['pregunta' => '¿Quién es el demandado?', 'expediente_id' => 1]);
        $r->assertStatus(503)->assertJson(['ok' => false]);
        $this->assertStringContainsString('Ollama', $r->json('error'));
    }

    public function test_exportar_y_respaldo(): void
    {
        $this->get(route('expedientes.exportar'))->assertOk()->assertDownload();
        $this->post(route('bd.optimizar'))->assertRedirect();
        $this->post(route('tipos.entrenar'))->assertRedirect()->assertSessionHas('ok');
        $this->postJson(route('tipos.probar'), ['texto' => 'robo agravado sindicado'])->assertOk()->assertJson(['tipo' => 'Proceso Penal']);
    }
}
