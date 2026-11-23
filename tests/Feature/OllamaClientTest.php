<?php

namespace Tests\Feature;

use App\Domain\Contracts\Servicios\ClienteLlmInterface;
use App\Domain\Exceptions\OllamaException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class OllamaClientTest extends TestCase
{
    use RefreshDatabase;

    private function tags(array $modelos = ['llama3.2:3b', 'nomic-embed-text:latest']): array
    {
        return ['models' => array_map(fn ($m) => ['name' => $m, 'size' => 1e9, 'details' => ['parameter_size' => '3B']], $modelos)];
    }

    private function cliente(): ClienteLlmInterface
    {
        app()->forgetInstance(ClienteLlmInterface::class);

        return app(ClienteLlmInterface::class);
    }

    public function test_estado_disponible_con_modelos(): void
    {
        Http::fake(['*/api/version' => Http::response(['version' => '0.6.0']), '*/api/tags' => Http::response($this->tags())]);
        $e = $this->cliente()->estado(true);
        $this->assertTrue($e['disponible']);
        $this->assertTrue($e['modelo_instalado']);
        $this->assertTrue($e['embeddings_instalado']);
    }

    public function test_ollama_apagado_da_mensaje_claro_y_no_lanza_error(): void
    {
        config(['ollama.reintentos' => 0]);
        Http::fake(['*' => Http::failedConnection()]);
        $e = $this->cliente()->estado(true);
        $this->assertFalse($e['disponible']);
        $this->assertStringContainsString('ollama serve', $e['mensaje']);

        $this->expectException(OllamaException::class);
        $this->cliente()->chat([['role' => 'user', 'content' => 'hola']]);
    }

    public function test_usa_modelo_alternativo_si_el_configurado_no_esta_descargado(): void
    {
        config(['ollama.modelo' => 'qwen2.5:7b']);
        Http::fake([
            '*/api/version' => Http::response(['version' => '0.6.0']),
            '*/api/tags' => Http::response($this->tags(['nomic-embed-text:latest', 'llama3.2:1b'])),
            '*/api/chat' => fn ($req) => Http::response(['message' => ['content' => 'usado '.$req['model']]]),
        ]);
        $c = $this->cliente();
        $this->assertSame('llama3.2:1b', $c->estado(true)['modelo_efectivo']);
        $this->assertSame('usado llama3.2:1b', $c->chat([['role' => 'user', 'content' => 'x']]));
    }

    public function test_modelo_no_descargado_sin_alternativo_indica_ollama_pull(): void
    {
        config(['ollama.modelo' => 'qwen2.5:7b', 'ollama.usar_modelo_alternativo' => false]);
        Http::fake(['*/api/version' => Http::response(['version' => '0.6.0']), '*/api/tags' => Http::response($this->tags(['llama3.2:1b']))]);
        try {
            $this->cliente()->chat([['role' => 'user', 'content' => 'x']]);
            $this->fail('Debió lanzar excepción');
        } catch (OllamaException $e) {
            $this->assertStringContainsString('ollama pull qwen2.5:7b', $e->getMessage());
        }
    }

    public function test_elimina_razonamiento_think_y_reintenta_error_500(): void
    {
        $intentos = 0;
        Http::fake([
            '*/api/version' => Http::response(['version' => '0.6.0']),
            '*/api/tags' => Http::response($this->tags()),
            '*/api/chat' => function () use (&$intentos) {
                $intentos++;

                return $intentos === 1 ? Http::response(['error' => 'loading model'], 503) : Http::response(['message' => ['content' => '<think>pensando</think> Respuesta final']]);
            },
        ]);
        $this->assertSame('Respuesta final', $this->cliente()->chat([['role' => 'user', 'content' => 'x']]));
        $this->assertSame(2, $intentos);
    }

    public function test_embeddings_compatibles_con_ollama_antiguo(): void
    {
        Http::fake([
            '*/api/version' => Http::response(['version' => '0.1.30']),
            '*/api/tags' => Http::response($this->tags()),
            '*/api/embed' => Http::response('404 page not found', 404),
            '*/api/embeddings' => Http::response(['embedding' => [0.1, 0.2, 0.3]]),
        ]);
        $v = $this->cliente()->embeddings(['uno', 'dos']);
        $this->assertCount(2, $v);
        $this->assertSame([0.1, 0.2, 0.3], $v[0]);
    }

    public function test_json_con_texto_alrededor_se_decodifica(): void
    {
        Http::fake([
            '*/api/version' => Http::response(['version' => '0.6.0']),
            '*/api/tags' => Http::response($this->tags()),
            '*/api/chat' => Http::response(['message' => ['content' => "Claro:\n```json\n{\"tipo\":\"Amparo\"}\n```"]]),
        ]);
        $this->assertSame(['tipo' => 'Amparo'], $this->cliente()->chatJson([['role' => 'user', 'content' => 'x']]));
    }
}
