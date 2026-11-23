<?php

namespace Tests\Feature;

use App\Infrastructure\Persistence\Models\Expediente;
use App\Infrastructure\Persistence\Models\ImportacionExcel;
use App\Infrastructure\Persistence\Models\TipoExpediente;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Tests\TestCase;

class ImportacionExcelTest extends TestCase
{
    use RefreshDatabase;

    private function excel(array $filas): UploadedFile
    {
        $libro = new Spreadsheet;
        $hoja = $libro->getActiveSheet();
        $hoja->fromArray([['REPORTE DE EXPEDIENTES 2024'], []], null, 'A1'); // título antes de encabezados
        $hoja->fromArray([['No. de causa', 'Clase de juicio', 'Actor', 'Demandado', 'Fecha de ingreso', 'Situación', 'Resumen', 'Cuantía']], null, 'A3');
        $hoja->fromArray($filas, null, 'A4');
        $ruta = tempnam(sys_get_temp_dir(), 'xl').'.xlsx';
        (new Xlsx($libro))->save($ruta);

        return new UploadedFile($ruta, 'expedientes.xlsx', null, null, true);
    }

    public function test_importa_aprende_tipos_y_clasifica_filas_sin_tipo(): void
    {
        Storage::fake('local');
        $archivo = $this->excel([
            ['C-001-2024-1', 'Pensión alimenticia', 'Ana Gómez', 'Luis Torres', '15/03/2024', 'En trámite', 'Solicita pensión alimenticia para hijos menores, alimentos', 'Q.1,500.00'],
            ['C-001-2024-2', 'Pensión Alimenticia', 'Marta Ruiz', 'Carlos Díaz', '2024-04-02', 'AUDIENCIA', 'Aumento de pensión alimenticia por alimentos de la menor', 'Q.2,000.00'],
            ['P-002-2024-1', 'Proceso penal', 'Ministerio Público', 'Pedro Juárez', '20 de mayo de 2024', 'Sentenciado', 'Sindicado por robo agravado, imputación del fiscal', ''],
            ['P-002-2024-2', 'Proceso Penal', 'Ministerio Público', 'Raúl Morales', '01/06/2024', 'archivo', 'Sindicado por estafa, acusación, delito', ''],
            ['X-999-2024-1', '', 'Rosa Méndez', 'Jorge López', '', '', 'La madre pide pensión de alimentos para su hijo menor', 'Q.800.00'],
        ]);

        $this->post(route('importar.previsualizar'), ['archivo' => $archivo])->assertRedirect();
        $imp = ImportacionExcel::firstOrFail();

        // mapeo automático por sinónimos
        $this->assertSame('No. de causa', $imp->mapeo['numero_expediente']);
        $this->assertSame('Clase de juicio', $imp->mapeo['tipo']);
        $this->assertSame('Actor', $imp->mapeo['demandante']);
        $this->assertSame('Situación', $imp->mapeo['estado']);
        $this->get(route('importar.mapeo', $imp))->assertOk()->assertSee('Revisar columnas');

        $this->post(route('importar.ejecutar', $imp), [
            'mapeo' => $imp->mapeo, 'duplicados' => 'actualizar', 'crear_tipos' => 1, 'clasificar' => 1,
        ])->assertRedirect(route('importar.show', $imp));

        $this->assertSame(5, Expediente::count());
        // "Pensión alimenticia" y "Pensión Alimenticia" son el mismo tipo
        $this->assertSame(2, TipoExpediente::count());
        $pension = TipoExpediente::where('slug', 'pension-alimenticia')->firstOrFail();
        $this->assertContains('pension', $pension->palabras_clave);

        $e = Expediente::where('numero_expediente', 'P-002-2024-1')->first();
        $this->assertSame('2024-05-20', $e->fecha_inicio->format('Y-m-d'));
        $this->assertSame('resuelto', $e->estado->value);
        $this->assertSame('archivado', Expediente::where('numero_expediente', 'P-002-2024-2')->first()->estado->value);

        // la fila sin tipo fue clasificada por lo aprendido
        $sinTipo = Expediente::where('numero_expediente', 'X-999-2024-1')->first();
        $this->assertSame($pension->id, $sinTipo->tipo_expediente_id);
        $this->assertSame('aprendizaje', $sinTipo->clasificado_por);
        $this->assertSame('Q.800.00', $sinTipo->datos_extra['Cuantía']);

        $this->get(route('importar.show', $imp))->assertOk()->assertSee('Pensión Alimenticia');
    }

    public function test_duplicados_se_actualizan_sin_borrar_datos(): void
    {
        Storage::fake('local');
        $fila = ['C-1', 'Divorcio', 'Ana', 'Luis', '01/01/2024', 'En trámite', 'Divorcio voluntario', ''];
        foreach ([[$fila], [['C-1', 'Divorcio', '', '', '', 'Resuelto', '', '']]] as $filas) {
            $this->post(route('importar.previsualizar'), ['archivo' => $this->excel($filas)]);
            $imp = ImportacionExcel::latest('id')->first();
            $this->post(route('importar.ejecutar', $imp), ['mapeo' => $imp->mapeo, 'duplicados' => 'actualizar', 'crear_tipos' => 1]);
        }
        $this->assertSame(1, Expediente::count());
        $e = Expediente::first();
        $this->assertSame('resuelto', $e->estado->value);
        $this->assertSame('Ana', $e->demandante); // la celda vacía no borró el dato
    }

    public function test_archivo_invalido_muestra_error_amigable(): void
    {
        $this->post(route('importar.previsualizar'), ['archivo' => UploadedFile::fake()->create('foto.png', 10)])
            ->assertSessionHasErrors('archivo');
    }
}
