<?php

namespace Database\Seeders;

use App\Application\Services\AprendizajeTiposService;
use App\Domain\Contracts\Repositories\ExpedienteRepositoryInterface;
use App\Domain\Contracts\Repositories\TipoExpedienteRepositoryInterface;
use Illuminate\Database\Seeder;

/** Datos de demostración: php artisan db:seed --class=DemoSeeder */
class DemoSeeder extends Seeder
{
    public function run(ExpedienteRepositoryInterface $expedientes, TipoExpedienteRepositoryInterface $tipos, AprendizajeTiposService $aprendizaje): void
    {
        $this->call(DatabaseSeeder::class);
        $datos = [
            ['Pensión Alimenticia', 'C-01077-2024-00045', 'Fijación de pensión alimenticia', 'Juzgado Segundo de Primera Instancia de Familia', 'Ana Lucía Gómez Pérez', 'Luis Fernando Torres', 'en_audiencia', 'La madre solicita pensión alimenticia para sus dos hijos menores de edad. El demandado trabaja en empresa privada y no ha cumplido con los alimentos.'],
            ['Pensión Alimenticia', 'C-01077-2024-00102', 'Aumento de pensión alimenticia', 'Juzgado Primero de Familia', 'Marta Elena Ruiz', 'Carlos Alberto Díaz', 'en_tramite', 'Solicitud de aumento de pensión por necesidades escolares y médicas de la menor. Alimentos insuficientes.'],
            ['Pensión Alimenticia', 'C-01078-2023-00550', 'Rebaja de pensión alimenticia', 'Juzgado Tercero de Familia', 'Jorge Mario López', 'Sandra Patricia Ramírez', 'resuelto', 'El alimentante solicita rebaja de la pensión por pérdida de empleo. Hijos menores, alimentos.'],
            ['Proceso Penal', 'MP001-2024-12345', 'Robo agravado', 'Juzgado Quinto de Primera Instancia Penal, Narcoactividad y Delitos contra el Ambiente', 'Ministerio Público / José Pérez (agraviado)', 'Pedro Antonio Juárez (sindicado)', 'en_audiencia', 'El sindicado fue aprehendido en flagrancia. Audiencia de primera declaración. El Ministerio Público formuló imputación por robo agravado.'],
            ['Proceso Penal', 'MP001-2023-98765', 'Estafa mediante información contable', 'Tribunal Primero de Sentencia Penal', 'Ministerio Público', 'Raúl Enrique Morales (sindicado)', 'en_tramite', 'Acusación por estafa. El agraviado denunció. Etapa intermedia, el fiscal presentó acusación y solicitud de apertura a juicio.'],
            ['Proceso Penal', 'MP001-2024-55555', 'Lesiones leves', 'Juzgado de Paz Penal de Turno', 'Ministerio Público / Rosa Méndez (agraviada)', 'Mario Castillo (sindicado)', 'resuelto', 'Delito de lesiones. Criterio de oportunidad. Sindicado, imputación, víctima, agraviada.'],
            ['Juicio Ejecutivo', '01043-2024-00321', 'Cobro de pagaré', 'Juzgado Primero de Primera Instancia Civil', 'Banco de Desarrollo, S.A.', 'Comercial El Éxito', 'en_tramite', 'Ejecución de pagaré vencido por Q.50,000.00. Título ejecutivo, requerimiento de pago y embargo.'],
            ['Juicio Ejecutivo', '01043-2023-00900', 'Cobro de cheque sin fondos', 'Juzgado Segundo de Primera Instancia Civil', 'Distribuidora Central', 'Juan Carlos Herrera', 'suspendido', 'Cobro ejecutivo de cheque rechazado. Embargo de cuentas bancarias, título ejecutivo, requerimiento.'],
            ['Juicio Ordinario Laboral', '01173-2024-00210', 'Despido injustificado', 'Juzgado Primero de Trabajo y Previsión Social', 'Héctor Daniel Cifuentes', 'Maquilas del Norte, S.A.', 'en_tramite', 'El trabajador reclama indemnización por despido injustificado, vacaciones, aguinaldo y bono 14. Prestaciones laborales.'],
            ['Juicio Ordinario Laboral', '01173-2023-00777', 'Reclamo de prestaciones laborales', 'Juzgado Segundo de Trabajo y Previsión Social', 'Gloria Estela Sic', 'Restaurantes Unidos', 'resuelto', 'Pago de salarios retenidos, indemnización, vacaciones y aguinaldo. El patrono despidió a la trabajadora.'],
        ];
        foreach ($datos as [$tipo, $numero, $titulo, $juzgado, $actor, $demandado, $estado, $desc]) {
            if ($expedientes->porNumero($numero)) {
                continue;
            }
            $expedientes->crear([
                'tipo_expediente_id' => $tipos->buscarOCrear($tipo)->id,
                'numero_expediente' => $numero, 'titulo' => $titulo, 'juzgado' => $juzgado,
                'demandante' => $actor, 'demandado' => $demandado, 'estado' => $estado, 'descripcion' => $desc,
                'fecha_inicio' => now()->subDays(random_int(10, 400))->toDateString(),
                'origen' => 'manual', 'clasificado_por' => 'manual', 'clasificacion_confianza' => 100,
            ]);
        }
        $aprendizaje->entrenar();
    }
}
