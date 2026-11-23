<?php

namespace App\Infrastructure\Documentos;

use App\Domain\Contracts\Servicios\ExtractorDocumentoInterface;
use App\Domain\Enums\FormatoArchivo;
use App\Domain\Exceptions\DocumentoNoSoportadoException;
use App\Domain\Services\NormalizadorTexto;
use App\Domain\ValueObjects\DocumentoExtraido;
use DOMDocument;
use DOMElement;
use DOMXPath;
use PhpOffice\PhpSpreadsheet\IOFactory;
use Smalot\PdfParser\Parser as PdfParser;
use ZipArchive;

/**
 * Extrae texto en orden de lectura desde DOCX, PDF, XLSX/XLS/CSV y TXT.
 */
class ExtractorDocumentos implements ExtractorDocumentoInterface
{
    public const NS_W = 'http://schemas.openxmlformats.org/wordprocessingml/2006/main';

    public function soporta(string $nombreArchivo): bool
    {
        return FormatoArchivo::desdeNombre($nombreArchivo) !== null;
    }

    public function extraer(string $rutaAbsoluta, ?string $nombreOriginal = null): DocumentoExtraido
    {
        $nombre = $nombreOriginal ?? basename($rutaAbsoluta);
        $formato = FormatoArchivo::desdeNombre($nombre);
        if (! $formato) {
            throw new DocumentoNoSoportadoException("Formato no soportado: {$nombre}. Use Word (.docx), PDF, Excel (.xlsx/.xls/.csv) o texto (.txt). Los archivos .doc antiguos deben guardarse como .docx.");
        }
        if (! is_file($rutaAbsoluta) || filesize($rutaAbsoluta) === 0) {
            throw new DocumentoNoSoportadoException("El archivo {$nombre} está vacío o no se pudo leer.");
        }

        try {
            $parrafos = match ($formato) {
                FormatoArchivo::Docx => $this->docx($rutaAbsoluta),
                FormatoArchivo::Pdf => $this->pdf($rutaAbsoluta),
                FormatoArchivo::Xlsx, FormatoArchivo::Xls, FormatoArchivo::Csv => $this->hojaCalculo($rutaAbsoluta),
                FormatoArchivo::Txt => $this->txt($rutaAbsoluta),
            };
        } catch (DocumentoNoSoportadoException $e) {
            throw $e;
        } catch (\Throwable $e) {
            report($e);
            throw new DocumentoNoSoportadoException("No se pudo leer {$nombre}: el archivo parece dañado o protegido con contraseña.", 0, $e);
        }

        $parrafos = array_values(array_filter(array_map(fn ($p) => NormalizadorTexto::limpiar($p), $parrafos), fn ($p) => $p !== ''));
        $documento = new DocumentoExtraido($formato, $parrafos, [
            'nombre' => $nombre,
            'bytes' => filesize($rutaAbsoluta),
            'parrafos' => count($parrafos),
        ]);

        if ($documento->vacio()) {
            $extra = $formato === FormatoArchivo::Pdf ? ' Si es un PDF escaneado (imagen), conviértalo primero con OCR.' : '';
            throw new DocumentoNoSoportadoException("No se encontró texto en {$nombre}.{$extra}");
        }

        return $documento;
    }

    // ------------------------------------------------------------------ DOCX

    /** @return string[] */
    private function docx(string $ruta): array
    {
        $zip = new ZipArchive;
        if ($zip->open($ruta) !== true) {
            throw new DocumentoNoSoportadoException('El archivo Word no es un .docx válido.');
        }
        $parrafos = [];
        $partes = self::partesTexto($zip);
        foreach ($partes as $parte) {
            $xml = $zip->getFromName($parte);
            if ($xml === false) {
                continue;
            }
            array_push($parrafos, ...$this->parrafosDeXml($xml));
        }
        $zip->close();

        return $parrafos;
    }

    /** Partes del DOCX con texto, en orden: encabezados, cuerpo, pies. */
    public static function partesTexto(ZipArchive $zip): array
    {
        $headers = $footers = [];
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $n = $zip->getNameIndex($i);
            if (preg_match('#^word/header\d*\.xml$#', $n)) {
                $headers[] = $n;
            } elseif (preg_match('#^word/footer\d*\.xml$#', $n)) {
                $footers[] = $n;
            }
        }
        sort($headers);
        sort($footers);

        return [...$headers, 'word/document.xml', ...$footers];
    }

    /** @return string[] */
    private function parrafosDeXml(string $xml): array
    {
        $dom = new DOMDocument;
        $dom->loadXML($xml, LIBXML_NONET | LIBXML_COMPACT | LIBXML_PARSEHUGE);
        $xp = new DOMXPath($dom);
        $xp->registerNamespace('w', self::NS_W);

        $parrafos = [];
        $cuerpo = $xp->query('//w:body')->item(0) ?? $dom->documentElement;
        foreach ($cuerpo->childNodes as $nodo) {
            if (! $nodo instanceof DOMElement) {
                continue;
            }
            if ($nodo->localName === 'p') {
                $parrafos[] = self::textoParrafo($nodo, $xp);
            } elseif ($nodo->localName === 'tbl') {
                foreach ($xp->query('.//w:tr', $nodo) as $fila) {
                    $celdas = [];
                    foreach ($xp->query('./w:tc', $fila) as $celda) {
                        $textos = [];
                        foreach ($xp->query('.//w:p', $celda) as $p) {
                            $textos[] = self::textoParrafo($p, $xp);
                        }
                        $celdas[] = trim(implode(' ', $textos));
                    }
                    $parrafos[] = implode(' | ', array_filter($celdas, fn ($c) => $c !== ''));
                }
            } elseif ($nodo->localName === 'sdt') {
                foreach ($xp->query('.//w:p', $nodo) as $p) {
                    $parrafos[] = self::textoParrafo($p, $xp);
                }
            }
        }

        return $parrafos;
    }

    public static function textoParrafo(DOMElement $p, DOMXPath $xp): string
    {
        $texto = '';
        foreach ($xp->query('.//w:t|.//w:tab|.//w:br|.//w:cr', $p) as $n) {
            $texto .= match ($n->localName) {
                't' => $n->textContent,
                'tab' => "\t",
                default => ' ',
            };
        }

        return $texto;
    }

    // ------------------------------------------------------------------ PDF

    /** @return string[] */
    private function pdf(string $ruta): array
    {
        $pdf = (new PdfParser)->parseFile($ruta);
        $lineas = [];
        foreach ($pdf->getPages() as $pagina) {
            foreach (preg_split("/\r?\n/", $pagina->getText()) ?: [] as $l) {
                $lineas[] = rtrim($l);
            }
        }

        // une líneas cortadas a mitad de oración para reconstruir párrafos
        $parrafos = [];
        $actual = '';
        foreach ($lineas as $l) {
            $l = trim($l);
            if ($l === '') {
                if ($actual !== '') {
                    $parrafos[] = $actual;
                    $actual = '';
                }

                continue;
            }
            if ($actual === '') {
                $actual = $l;
            } elseif (! preg_match('/[.:;]$/u', $actual) && preg_match('/^[a-záéíóúñ0-9(,]/u', $l) && mb_strtoupper($actual) !== $actual) {
                $actual .= ' '.$l;
            } else {
                $parrafos[] = $actual;
                $actual = $l;
            }
        }
        if ($actual !== '') {
            $parrafos[] = $actual;
        }

        return $parrafos;
    }

    // ------------------------------------------------------------------ Hojas de cálculo

    /** @return string[] */
    private function hojaCalculo(string $ruta): array
    {
        $reader = IOFactory::createReaderForFile($ruta);
        $reader->setReadDataOnly(true);
        $libro = $reader->load($ruta);
        $parrafos = [];
        foreach ($libro->getAllSheets() as $hoja) {
            if ($libro->getSheetCount() > 1) {
                $parrafos[] = 'Hoja: '.$hoja->getTitle();
            }
            foreach ($hoja->toArray(null, true, true, false) as $fila) {
                $celdas = array_filter(array_map(fn ($c) => trim((string) $c), $fila), fn ($c) => $c !== '');
                if ($celdas) {
                    $parrafos[] = implode(' | ', $celdas);
                }
            }
        }
        $libro->disconnectWorksheets();

        return $parrafos;
    }

    /** @return string[] */
    private function txt(string $ruta): array
    {
        return preg_split("/\r?\n/", NormalizadorTexto::limpiar((string) file_get_contents($ruta))) ?: [];
    }
}
