<?php

namespace Tests\Unit;

use App\Domain\Services\DetectorCamposImportantes;
use App\Domain\Services\MarcadorCampos;
use PHPUnit\Framework\TestCase;

class DetectorCamposTest extends TestCase
{
    private const TEXTO = <<<'TXT'
JUZGADO PRIMERO DE PRIMERA INSTANCIA CIVIL DEL DEPARTAMENTO DE GUATEMALA
Expediente No. 01043-2024-00321
Guatemala, quince de marzo de dos mil veinticuatro.
JUICIO ORDINARIO promovido por el señor Carlos Alberto Méndez López, de cuarenta y dos años de edad, quien se identifica con DPI 2456 78901 0101, contra la demandada María Fernanda Ruiz Paz, con domicilio en 6a. avenida 3-45 zona 1, ciudad de Guatemala. Se reclama la cantidad de CINCO MIL QUETZALES (Q.5,000.00).
CONSIDERANDO: Que el artículo 96 del Código Procesal Civil y Mercantil establece el procedimiento.
Se señala audiencia para el 20/04/2024 a las nueve horas con treinta minutos.
Abogado director: Lic. José Antonio Pérez García, colegiado No. 12345, NIT: 1234567-8
TXT;

    private function porClave(): array
    {
        $r = [];
        foreach ((new DetectorCamposImportantes)->detectar(self::TEXTO) as $c) {
            $r[$c->clave] = $c->valor;
        }

        return $r;
    }

    public function test_detecta_los_datos_importantes(): void
    {
        $c = $this->porClave();
        $this->assertSame('01043-2024-00321', $c['numero_expediente']);
        $this->assertSame('JUZGADO PRIMERO DE PRIMERA INSTANCIA CIVIL DEL DEPARTAMENTO DE GUATEMALA', $c['juzgado']);
        $this->assertSame('Carlos Alberto Méndez López', $c['demandante']);
        $this->assertSame('María Fernanda Ruiz Paz', $c['demandado']);
        $this->assertSame('José Antonio Pérez García', $c['abogado']);
        $this->assertSame('quince de marzo de dos mil veinticuatro', $c['fecha']);
        $this->assertSame('20/04/2024', $c['fecha_2']);
        $this->assertSame('2456 78901 0101', $c['dpi']);
        $this->assertSame('Q.5,000.00', $c['monto']);
        $this->assertSame('CINCO MIL QUETZALES', $c['monto_letras']);
        $this->assertSame('12345', $c['colegiado']);
        $this->assertSame('1234567-8', $c['nit']);
        $this->assertStringContainsString('zona 1', $c['direccion']);
    }

    public function test_no_convierte_en_blanco_el_texto_legal_fijo(): void
    {
        $valores = implode('|', $this->porClave());
        $this->assertStringNotContainsString('Código Procesal Civil', $valores);
        $this->assertStringNotContainsString('CONSIDERANDO', $valores);
    }

    public function test_marcador_respeta_limites_de_palabra(): void
    {
        $campos = array_values(array_filter((new DetectorCamposImportantes)->detectar(self::TEXTO), fn ($c) => in_array($c->clave, ['colegiado', 'nit'], true)));
        $res = MarcadorCampos::marcar('colegiado No. 12345, NIT: 1234567-8', $campos, fn ($c) => '{{'.$c->clave.'}}');
        $this->assertSame('colegiado No. {{colegiado}}, NIT: {{nit}}', $res);
    }

    public function test_texto_vacio_no_falla(): void
    {
        $this->assertSame([], (new DetectorCamposImportantes)->detectar(''));
    }
}
