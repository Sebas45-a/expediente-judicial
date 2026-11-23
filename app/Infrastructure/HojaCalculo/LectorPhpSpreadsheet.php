<?php

namespace App\Infrastructure\HojaCalculo;

use App\Domain\Contracts\Servicios\LectorHojaCalculoInterface;
use App\Domain\Exceptions\ExpedienteException;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Reader\Csv;
use PhpOffice\PhpSpreadsheet\Shared\Date as ExcelDate;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

class LectorPhpSpreadsheet implements LectorHojaCalculoInterface
{
    public function hojas(string $rutaAbsoluta): array
    {
        $reader = $this->lector($rutaAbsoluta);

        return method_exists($reader, 'listWorksheetNames') ? $reader->listWorksheetNames($rutaAbsoluta) : ['Hoja1'];
    }

    public function leer(string $rutaAbsoluta, ?string $hoja = null, ?int $limite = null): array
    {
        try {
            $reader = $this->lector($rutaAbsoluta);
            if ($hoja && method_exists($reader, 'setLoadSheetsOnly')) {
                $reader->setLoadSheetsOnly([$hoja]);
            }
            $libro = $reader->load($rutaAbsoluta);
        } catch (\Throwable $e) {
            report($e);
            throw new ExpedienteException('No se pudo abrir el archivo Excel. Verifique que no esté dañado ni protegido con contraseña.', 0, $e);
        }

        $ws = $hoja ? ($libro->getSheetByName($hoja) ?? $libro->getActiveSheet()) : $libro->getSheet(0);
        $maxFila = $ws->getHighestDataRow();
        $maxCol = Coordinate::columnIndexFromString($ws->getHighestDataColumn());

        $matriz = [];
        for ($f = 1; $f <= $maxFila; $f++) {
            $fila = [];
            for ($c = 1; $c <= $maxCol; $c++) {
                $celda = $ws->getCell([$c, $f]);
                $v = $celda->getValue();
                if ($v instanceof \PhpOffice\PhpSpreadsheet\RichText\RichText) {
                    $v = $v->getPlainText();
                } elseif (is_string($v) && str_starts_with($v, '=')) {
                    try {
                        $v = $celda->getCalculatedValue();
                    } catch (\Throwable) {
                        $v = $celda->getOldCalculatedValue();
                    }
                }
                if (is_numeric($v) && ExcelDate::isDateTime($celda)) {
                    try {
                        $v = ExcelDate::excelToDateTimeObject((float) $v)->format('Y-m-d');
                    } catch (\Throwable) {
                    }
                }
                $fila[] = is_string($v) ? trim($v) : $v;
            }
            $matriz[] = $fila;
        }
        $titulo = $ws->getTitle();
        $libro->disconnectWorksheets();

        $indiceEncabezado = $this->detectarFilaEncabezado($matriz);
        if ($indiceEncabezado === null) {
            throw new ExpedienteException('La hoja está vacía o no tiene una fila de encabezados reconocible.');
        }
        $encabezados = $this->encabezadosUnicos($matriz[$indiceEncabezado]);

        $filas = [];
        $total = 0;
        foreach (array_slice($matriz, $indiceEncabezado + 1, null, true) as $num => $valores) {
            if (! array_filter($valores, fn ($v) => $v !== null && $v !== '')) {
                continue; // fila vacía
            }
            $total++;
            if ($limite !== null && count($filas) >= $limite) {
                continue;
            }
            $asoc = ['__fila' => $num + 1];
            foreach ($encabezados as $i => $h) {
                $asoc[$h] = $valores[$i] ?? null;
            }
            $filas[] = $asoc;
        }

        return ['encabezados' => $encabezados, 'filas' => $filas, 'total' => $total, 'hoja' => $titulo];
    }

    public function escribir(string $rutaAbsoluta, array $encabezados, array $filas, string $tituloHoja = 'Datos'): void
    {
        $libro = new Spreadsheet;
        $ws = $libro->getActiveSheet();
        $ws->setTitle(mb_substr($tituloHoja, 0, 31));
        $ws->fromArray($encabezados, null, 'A1');
        if ($filas) {
            $ws->fromArray(array_map('array_values', $filas), null, 'A2', true);
        }
        $ultima = Coordinate::stringFromColumnIndex(max(1, count($encabezados)));
        $ws->getStyle("A1:{$ultima}1")->getFont()->setBold(true)->getColor()->setRGB('FFFFFF');
        $ws->getStyle("A1:{$ultima}1")->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB('1F3A5F');
        foreach (range(1, count($encabezados)) as $c) {
            $ws->getColumnDimension(Coordinate::stringFromColumnIndex($c))->setAutoSize(true);
        }
        $ws->freezePane('A2');
        $ws->setAutoFilter("A1:{$ultima}".max(1, count($filas) + 1));
        @mkdir(dirname($rutaAbsoluta), 0775, true);
        (new Xlsx($libro))->save($rutaAbsoluta);
        $libro->disconnectWorksheets();
    }

    // ------------------------------------------------------------------

    private function lector(string $ruta): \PhpOffice\PhpSpreadsheet\Reader\IReader
    {
        $reader = IOFactory::createReaderForFile($ruta);
        if ($reader instanceof Csv) {
            $primera = (string) fgets(fopen($ruta, 'r'));
            $conteos = [';' => substr_count($primera, ';'), ',' => substr_count($primera, ','), "\t" => substr_count($primera, "\t"), '|' => substr_count($primera, '|')];
            arsort($conteos);
            $reader->setDelimiter((string) array_key_first($conteos));
            if (defined(Csv::class.'::GUESS_ENCODING')) {
                $reader->setInputEncoding(Csv::GUESS_ENCODING);
            }
        }

        return $reader;
    }

    /** La fila de encabezados es la primera (de las 15 iniciales) con ≥2 textos no numéricos. */
    private function detectarFilaEncabezado(array $matriz): ?int
    {
        $mejor = null;
        $mejorPuntaje = 0;
        foreach (array_slice($matriz, 0, 15, true) as $i => $fila) {
            $textos = count(array_filter($fila, fn ($v) => is_string($v) && $v !== '' && ! is_numeric($v) && mb_strlen($v) <= 80));
            $llenas = count(array_filter($fila, fn ($v) => $v !== null && $v !== ''));
            if ($textos >= 2 && $textos >= $llenas * 0.7 && $textos > $mejorPuntaje) {
                $mejor = $i;
                $mejorPuntaje = $textos;
                if ($textos >= 3) {
                    break;
                }
            }
        }
        if ($mejor === null) {
            foreach ($matriz as $i => $fila) {
                if (array_filter($fila, fn ($v) => $v !== null && $v !== '')) {
                    return $i;
                }
            }
        }

        return $mejor;
    }

    private function encabezadosUnicos(array $fila): array
    {
        $vistos = [];
        $res = [];
        foreach ($fila as $i => $h) {
            $h = trim(preg_replace('/\s+/u', ' ', (string) $h) ?? '');
            if ($h === '') {
                $h = 'Columna '.Coordinate::stringFromColumnIndex($i + 1);
            }
            $base = $h;
            $n = 2;
            while (isset($vistos[mb_strtolower($h)])) {
                $h = "{$base} ({$n})";
                $n++;
            }
            $vistos[mb_strtolower($h)] = true;
            $res[] = $h;
        }

        return $res;
    }
}
