<?php

namespace Tests\Feature;

use App\Infrastructure\Persistence\Models\PlantillaFormato;
use App\Infrastructure\Persistence\Models\TipoExpediente;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use PhpOffice\PhpWord\IOFactory;
use PhpOffice\PhpWord\PhpWord;
use Tests\TestCase;
use ZipArchive;

class AprendizajeFormatoTest extends TestCase
{
    use RefreshDatabase;

    /** Documento Word donde el nombre del actor está partido en 3 "runs" con formatos distintos. */
    private function docx(): UploadedFile
    {
        $w = new PhpWord;
        $s = $w->addSection();
        $s->addText('JUZGADO SEGUNDO DE PRIMERA INSTANCIA DE FAMILIA DEL DEPARTAMENTO DE GUATEMALA', ['bold' => true]);
        $s->addText('Expediente No. C-01077-2024-00045');
        $run = $s->addTextRun();
        $run->addText('Demanda promovida por la señora ');
        $run->addText('Ana Lucía ', ['bold' => true]);
        $run->addText('Gómez Pérez', ['italic' => true]);
        $run->addText(', contra el demandado Luis Fernando Torres, por incumplimiento de contrato.');
        $s->addText('CONSIDERANDO: Que conforme al Código Civil los alimentos comprenden todo lo indispensable para el sustento.');
        $s->addText('Guatemala, 15 de marzo de 2024.');
        $ruta = tempnam(sys_get_temp_dir(), 'dx').'.docx';
        IOFactory::createWriter($w, 'Word2007')->save($ruta);

        return new UploadedFile($ruta, 'pension_ejemplo.docx', null, null, true);
    }

    private function textoDocx(string $ruta): string
    {
        $z = new ZipArchive;
        $z->open($ruta);
        $xml = $z->getFromName('word/document.xml');
        $z->close();

        return strip_tags(str_replace('</w:p>', "\n", $xml));
    }

    public function test_aprende_formato_y_genera_documentos(): void
    {
        Storage::fake('local');
        Http::fake(['*' => Http::failedConnection()]); // sin Ollama: debe funcionar solo con reglas
        config(['ollama.reintentos' => 0]);
        $tipo = TipoExpediente::create(['nombre' => 'Pensión Alimenticia', 'slug' => 'pension-alimenticia']);

        $r = $this->post(route('formatos.analizar'), ['documento' => $this->docx(), 'tipo_expediente_id' => $tipo->id, 'usar_ia' => 1]);
        $token = basename(parse_url($r->headers->get('Location'), PHP_URL_PATH));
        $revision = $this->get(route('formatos.revisar', $token))->assertOk();
        $revision->assertSee('No se usó la IA', false); // aviso claro, sin error

        $analisis = json_decode(Storage::disk('local')->get("formatos/analisis/{$token}.json"), true);
        $valores = array_column($analisis['campos'], 'valor', 'clave');
        $this->assertSame('Ana Lucía Gómez Pérez', $valores['demandante']);
        $this->assertSame('Luis Fernando Torres', $valores['demandado']);
        $this->assertSame('C-01077-2024-00045', $valores['numero_expediente']);

        $campos = array_map(fn ($c) => $c + ['incluir' => 1], $analisis['campos']);
        $this->post(route('formatos.confirmar', $token), [
            'tipo_expediente_id' => $tipo->id, 'nombre' => 'Demanda de pensión', 'campos' => $campos,
            'manuales' => [['valor' => 'incumplimiento de contrato', 'etiqueta' => 'Motivo']],
        ])->assertRedirect();

        $p = PlantillaFormato::with('campos')->firstOrFail();
        $plantilla = $this->textoDocx(Storage::disk('local')->path($p->archivo_plantilla_ruta));
        $this->assertStringContainsString('${demandante}', $plantilla);
        $this->assertStringContainsString('${numero_expediente}', $plantilla);
        $this->assertStringContainsString('${motivo}', $plantilla);
        $this->assertStringNotContainsString('Ana Lucía', $plantilla);
        $this->assertStringContainsString('Código Civil los alimentos', $plantilla); // texto fijo intacto
        $this->assertStringContainsString('{{demandante}}', $p->contenido_plantilla);

        // Generar documento completo
        $valores = ['demandante' => 'María José Ruiz & Asociados', 'demandado' => 'Pedro Gil', 'numero_expediente' => 'C-9-2025-1', 'juzgado' => 'JUZGADO X', 'fecha' => '1 de enero de 2025', 'motivo' => 'mora'];
        $resp = $this->post(route('formatos.generar', $p), ['valores' => $valores, 'permitir_vacios' => 1]);
        $resp->assertOk();
        $generado = $this->textoDocx($resp->baseResponse->getFile()->getPathname());
        $this->assertStringContainsString('María José Ruiz &amp; Asociados', $generado); // & escapado: el XML sigue válido
        $this->assertStringContainsString('C-9-2025-1', $generado);
        $this->assertStringNotContainsString('${', $generado);

        // Formato en blanco
        $blanco = $this->get(route('formatos.blanco', $p))->assertOk();
        $this->assertStringContainsString('______', $this->textoDocx($blanco->baseResponse->getFile()->getPathname()));

        // Segunda muestra del mismo tipo: nueva versión y detecta datos repetidos como posible texto fijo
        $r2 = $this->post(route('formatos.analizar'), ['documento' => $this->docx(), 'tipo_expediente_id' => $tipo->id]);
        $token2 = basename(parse_url($r2->headers->get('Location'), PHP_URL_PATH));
        $a2 = json_decode(Storage::disk('local')->get("formatos/analisis/{$token2}.json"), true);
        $juzgado = collect($a2['campos'])->firstWhere('clave', 'juzgado');
        $this->assertFalse($juzgado['incluir']);
        $this->assertNotNull($juzgado['nota']);

        foreach (['formatos.index', 'formatos.create'] as $ruta) {
            $this->get(route($ruta))->assertOk();
        }
        $this->get(route('formatos.show', $p))->assertOk()->assertSee('Demanda de pensión');
        $this->get(route('formatos.usar', $p))->assertOk();
    }

    public function test_valores_inventados_por_la_ia_se_descartan(): void
    {
        Storage::fake('local');
        Http::fake([
            '*/api/version' => Http::response(['version' => '0.6.0']),
            '*/api/tags' => Http::response(['models' => [['name' => 'llama3.2:3b'], ['name' => 'nomic-embed-text:latest']]]),
            '*/api/chat' => Http::response(['message' => ['content' => json_encode(['campos' => [
                ['clave' => 'motivo', 'etiqueta' => 'Motivo', 'tipo' => 'texto', 'valor' => 'incumplimiento de contrato'],
                ['clave' => 'inventado', 'etiqueta' => 'X', 'tipo' => 'texto', 'valor' => 'Esto no está en el documento'],
            ]])]]),
        ]);
        $tipo = TipoExpediente::create(['nombre' => 'Divorcio', 'slug' => 'divorcio']);
        $r = $this->post(route('formatos.analizar'), ['documento' => $this->docx(), 'tipo_expediente_id' => $tipo->id, 'usar_ia' => 1]);
        $token = basename(parse_url($r->headers->get('Location'), PHP_URL_PATH));
        $a = json_decode(Storage::disk('local')->get("formatos/analisis/{$token}.json"), true);
        $valores = array_column($a['campos'], 'valor');
        $this->assertContains('incumplimiento de contrato', $valores);
        $this->assertNotContains('Esto no está en el documento', $valores);
        $this->assertTrue($a['uso_ia']);
    }
}
