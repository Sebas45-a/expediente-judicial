<?php

namespace Database\Seeders;

use App\Domain\Contracts\Repositories\TipoExpedienteRepositoryInterface;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    /** Tipos iniciales frecuentes. El sistema aprende otros nuevos al importar Excel. */
    public function run(TipoExpedienteRepositoryInterface $tipos): void
    {
        $iniciales = [
            ['Juicio Ordinario Civil', 'Civil', 'Demandas civiles de conocimiento: daños y perjuicios, nulidades, cumplimiento de contratos.'],
            ['Juicio Ejecutivo', 'Civil', 'Cobro de obligaciones con título ejecutivo: pagarés, cheques, letras de cambio.'],
            ['Pensión Alimenticia', 'Familia', 'Fijación, aumento o rebaja de pensión alimenticia.'],
            ['Divorcio', 'Familia', 'Divorcio voluntario u ordinario.'],
            ['Proceso Penal', 'Penal', 'Causas penales: sindicados, delitos, audiencias de primera declaración, etapa intermedia y debate.'],
            ['Juicio Ordinario Laboral', 'Laboral', 'Reclamos laborales: despido injustificado, indemnización, prestaciones.'],
            ['Amparo', 'Constitucional', 'Acciones de amparo contra actos de autoridad.'],
            ['Recurso de Apelación', 'General', 'Impugnaciones ante tribunales de segunda instancia.'],
            ['Sentencia', 'General', 'Resoluciones que ponen fin al proceso.'],
            ['Acta de Audiencia', 'General', 'Actas de audiencias orales.'],
            ['Oficio', 'Administrativo', 'Comunicaciones oficiales entre instituciones.'],
        ];
        foreach ($iniciales as [$nombre, $materia, $descripcion]) {
            if (! $tipos->buscarPorNombre($nombre)) {
                $tipos->crear(compact('nombre', 'materia', 'descripcion'));
            }
        }
    }
}
