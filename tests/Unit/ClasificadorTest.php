<?php

namespace Tests\Unit;

use App\Domain\Services\ClasificadorNaiveBayes;
use PHPUnit\Framework\TestCase;

class ClasificadorTest extends TestCase
{
    private function muestras(): array
    {
        return [
            [1, 'Pensión alimenticia para hijos menores, alimentos, madre solicita fijación'],
            [1, 'Aumento de pensión alimenticia, necesidades de la menor, alimentos'],
            [1, 'Rebaja de pensión de alimentos por pérdida de empleo del alimentante'],
            [2, 'Proceso penal por robo agravado, sindicado aprehendido, Ministerio Público'],
            [2, 'Delito de estafa, acusación del fiscal, sindicado, etapa intermedia'],
            [2, 'Lesiones leves, agraviada, imputación, criterio de oportunidad penal'],
            [3, 'Juicio ejecutivo, cobro de pagaré vencido, embargo, título ejecutivo'],
            [3, 'Cobro ejecutivo de cheque sin fondos, requerimiento de pago y embargo'],
        ];
    }

    public function test_aprende_y_clasifica_cada_tipo(): void
    {
        $c = new ClasificadorNaiveBayes;
        $modelo = $c->entrenar($this->muestras());
        $this->assertSame(1, $c->clasificar($modelo, 'La demandante pide pensión de alimentos para su hijo')->tipoId);
        $this->assertSame(2, $c->clasificar($modelo, 'El sindicado fue acusado por el delito de robo')->tipoId);
        $this->assertSame(3, $c->clasificar($modelo, 'Se solicita embargo para el cobro del pagaré')->tipoId);
    }

    public function test_palabras_clave_y_evaluacion(): void
    {
        $c = new ClasificadorNaiveBayes;
        $modelo = $c->entrenar($this->muestras());
        $this->assertContains('sindicado', $c->palabrasClave($modelo, 2));
        $this->assertNotNull($c->evaluar($this->muestras()));
    }

    public function test_texto_desconocido_no_se_clasifica(): void
    {
        $c = new ClasificadorNaiveBayes;
        $r = $c->clasificar($c->entrenar($this->muestras()), 'xyz qwerty asdf');
        $this->assertNull($r->tipoId);
    }
}
