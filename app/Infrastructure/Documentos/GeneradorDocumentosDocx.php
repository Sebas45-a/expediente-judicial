<?php

namespace App\Infrastructure\Documentos;

use App\Domain\Contracts\Servicios\GeneradorDocumentosInterface;
use App\Domain\Enums\FormatoArchivo;
use App\Domain\Services\DetectorCamposImportantes;
use App\Domain\Services\MarcadorCampos;
use App\Domain\ValueObjects\CampoDetectado;
use App\Domain\ValueObjects\DocumentoExtraido;
use DOMDocument;
use DOMElement;
use DOMXPath;
use PhpOffice\PhpWord\IOFactory as WordIOFactory;
use PhpOffice\PhpWord\PhpWord;
use PhpOffice\PhpWord\Settings;
use PhpOffice\PhpWord\SimpleType\Jc;
use PhpOffice\PhpWord\TemplateProcessor;
use RuntimeException;
use ZipArchive;

/**
 * - Si el original es DOCX: reemplaza los datos importantes por ${clave} directamente en el XML,
 *   CONSERVANDO fuentes, márgenes, tablas, encabezados, logos y estilos.
 * - Si el original es PDF/Excel/TXT: reconstruye un DOCX limpio con la estructura de párrafos.
 * - Genera documentos rellenos (o con líneas en blanco) usando TemplateProcessor de PhpWord.
 */
class GeneradorDocumentosDocx implements GeneradorDocumentosInterface
{

    public function construirPlantilla(string $rutaOriginal, DocumentoExtraido $documento, array $campos, string $rutaDestino): void
    {
        @mkdir(dirname($rutaDestino), 0775, true);
        // valores más largos primero: evita reemplazar "12345" dentro de "1234567-8"
        usort($campos, fn (CampoDetectado $a, CampoDetectado $b) => mb_strlen($b->valor) <=> mb_strlen($a->valor));

        if ($documento->formato === FormatoArchivo::Docx) {
            $this->plantillaDesdeDocx($rutaOriginal, $campos, $rutaDestino);
        } else {
            $this->plantillaDesdeTexto($documento, $campos, $rutaDestino);
        }
    }

    public function generar(string $rutaPlantilla, array $valores, string $rutaDestino): void
    {
        if (! is_file($rutaPlantilla)) {
            throw new RuntimeException('No se encontró el archivo de la plantilla. Vuelva a aprender el formato.');
        }
        @mkdir(dirname($rutaDestino), 0775, true);
        Settings::setOutputEscapingEnabled(true);

        $tp = new TemplateProcessor($rutaPlantilla);
        foreach (array_unique($tp->getVariables()) as $variable) {
            $valor = trim((string) ($valores[$variable] ?? ''));
            $tp->setValue($variable, $valor === '' ? MarcadorCampos::LINEA_BLANCO : $valor);
        }
        $tp->saveAs($rutaDestino);
    }

    // ------------------------------------------------------------------ DOCX → DOCX

    private function plantillaDesdeDocx(string $original, array $campos, string $destino): void
    {
        if (! copy($original, $destino)) {
            throw new RuntimeException('No se pudo copiar el documento original.');
        }
        $zip = new ZipArchive;
        if ($zip->open($destino) !== true) {
            throw new RuntimeException('El documento Word no se pudo abrir para crear la plantilla.');
        }
        foreach (ExtractorDocumentos::partesTexto($zip) as $parte) {
            $xml = $zip->getFromName($parte);
            if ($xml === false) {
                continue;
            }
            $nuevo = $this->reemplazarEnXml($xml, $campos);
            if ($nuevo !== null) {
                $zip->addFromString($parte, $nuevo);
            }
        }
        $zip->close();
    }

    /**
     * Reemplaza valores aunque Word los haya partido en varios "runs" (<w:r>):
     * se concatena el texto del párrafo, se localiza el valor y se reescriben los nodos afectados.
     */
    private function reemplazarEnXml(string $xml, array $campos): ?string
    {
        $dom = new DOMDocument;
        $dom->preserveWhiteSpace = true;
        $dom->loadXML($xml, LIBXML_NONET | LIBXML_PARSEHUGE);
        $xp = new DOMXPath($dom);
        $xp->registerNamespace('w', ExtractorDocumentos::NS_W);
        $cambios = 0;

        foreach ($xp->query('//w:p') as $p) {
            /** @var DOMElement[] $nodos */
            $nodos = iterator_to_array($xp->query('.//w:t', $p));
            if (! $nodos) {
                continue;
            }
            foreach ($campos as $campo) {
                $patron = DetectorCamposImportantes::patronValor($campo->valor);
                $guardia = 0;
                while ($guardia++ < 50) {
                    $texto = '';
                    $mapa = [];
                    foreach ($nodos as $i => $n) {
                        $mapa[] = [$i, strlen($texto), strlen($texto) + strlen($n->nodeValue)];
                        $texto .= $n->nodeValue;
                    }
                    if (! preg_match($patron, $texto, $m, PREG_OFFSET_CAPTURE)) {
                        break;
                    }
                    $inicio = $m[0][1];
                    $fin = $inicio + strlen($m[0][0]);
                    $this->reescribirNodos($nodos, $mapa, $inicio, $fin, '${'.$campo->clave.'}');
                    $cambios++;
                }
            }
        }

        return $cambios > 0 ? $dom->saveXML() : null;
    }

    private function reescribirNodos(array $nodos, array $mapa, int $inicio, int $fin, string $marcador): void
    {
        $colocado = false;
        foreach ($mapa as [$i, $ini, $fn]) {
            if ($fn <= $inicio || $ini >= $fin) {
                continue; // nodo fuera del rango
            }
            $valor = $nodos[$i]->nodeValue;
            $desde = max(0, $inicio - $ini);
            $hasta = min(strlen($valor), $fin - $ini);
            $antes = substr($valor, 0, $desde);
            $despues = substr($valor, $hasta);
            $nuevo = $antes.($colocado ? '' : $marcador).$despues;
            $colocado = true;
            $nodos[$i]->nodeValue = '';
            $nodos[$i]->appendChild($nodos[$i]->ownerDocument->createTextNode($nuevo));
            $nodos[$i]->setAttributeNS('http://www.w3.org/XML/1998/namespace', 'xml:space', 'preserve');
        }
    }

    // ------------------------------------------------------------------ PDF/Excel/TXT → DOCX

    private function plantillaDesdeTexto(DocumentoExtraido $documento, array $campos, string $destino): void
    {
        Settings::setOutputEscapingEnabled(true);
        $word = new PhpWord;
        $word->setDefaultFontName('Times New Roman');
        $word->setDefaultFontSize(12);
        $seccion = $word->addSection(['marginTop' => 1418, 'marginBottom' => 1418, 'marginLeft' => 1701, 'marginRight' => 1418]);

        foreach ($documento->parrafos as $parrafo) {
            $texto = MarcadorCampos::marcar($parrafo, $campos, fn (CampoDetectado $c) => '${'.$c->clave.'}');
            $esTitulo = mb_strlen($parrafo) < 110 && mb_strtoupper($parrafo) === $parrafo && preg_match('/\p{L}{3}/u', $parrafo);
            $seccion->addText(
                $texto,
                $esTitulo ? ['bold' => true] : [],
                ['alignment' => $esTitulo ? Jc::CENTER : Jc::BOTH, 'spaceAfter' => 160, 'lineHeight' => 1.3]
            );
        }
        WordIOFactory::createWriter($word, 'Word2007')->save($destino);
    }
}
