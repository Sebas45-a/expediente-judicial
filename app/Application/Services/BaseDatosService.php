<?php

namespace App\Application\Services;

use App\Domain\Exceptions\ExpedienteException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;

/**
 * Apartado "Base de Datos": información de la conexión, tablas, explorador,
 * respaldo, optimización y estado de migraciones.
 */
class BaseDatosService
{
    /** Tablas del sistema que se pueden explorar (las de seguridad quedan fuera). */
    public const TABLAS = [
        'expedientes' => 'Expedientes',
        'tipos_expediente' => 'Tipos de expediente (aprendidos)',
        'importaciones_excel' => 'Importaciones de Excel',
        'plantillas_formato' => 'Formatos aprendidos',
        'campos_plantilla' => 'Campos de los formatos',
        'documentos_generados' => 'Documentos generados',
        'fragmentos_expediente' => 'Índice de la IA (fragmentos)',
        'consultas_ia' => 'Consultas a la IA',
        'configuraciones' => 'Configuración',
        'migrations' => 'Migraciones',
    ];

    /** Columnas pesadas que se recortan al mostrar. */
    private const COLUMNAS_LARGAS = ['contenido_texto', 'contenido_plantilla', 'embedding', 'perfil', 'contenido', 'respuesta', 'valor'];

    public function info(): array
    {
        $conexion = DB::connection();
        $driver = $conexion->getDriverName();
        $baseDatos = $conexion->getDatabaseName();
        $tamano = null;
        if ($driver === 'sqlite' && is_file($baseDatos)) {
            $tamano = filesize($baseDatos);
        } elseif (in_array($driver, ['mysql', 'mariadb'], true)) {
            $tamano = (int) DB::selectOne('SELECT SUM(data_length + index_length) AS t FROM information_schema.tables WHERE table_schema = ?', [$baseDatos])?->t;
        } elseif ($driver === 'pgsql') {
            $tamano = (int) DB::selectOne('SELECT pg_database_size(current_database()) AS t')?->t;
        }

        $version = rescue(fn () => match ($driver) {
            'sqlite' => 'SQLite '.DB::selectOne('select sqlite_version() as v')->v,
            'pgsql' => DB::selectOne('select version() as v')->v,
            default => DB::selectOne('select version() as v')->v,
        }, '—', false);

        return [
            'driver' => $driver,
            'base_datos' => $driver === 'sqlite' ? basename($baseDatos) : $baseDatos,
            'host' => $driver === 'sqlite' ? 'archivo local' : config("database.connections.{$conexion->getName()}.host"),
            'version' => $version,
            'tamano' => $tamano,
            'tablas' => $this->tablas(),
        ];
    }

    public function tablas(): array
    {
        $res = [];
        foreach (self::TABLAS as $tabla => $nombre) {
            if (! Schema::hasTable($tabla)) {
                continue;
            }
            $res[] = [
                'tabla' => $tabla,
                'nombre' => $nombre,
                'registros' => DB::table($tabla)->count(),
                'columnas' => count(Schema::getColumnListing($tabla)),
            ];
        }

        return $res;
    }

    public function explorar(string $tabla, ?string $buscar, int $pagina = 1, int $porPagina = 25): array
    {
        if (! array_key_exists($tabla, self::TABLAS) || ! Schema::hasTable($tabla)) {
            throw new ExpedienteException('Tabla no permitida.');
        }
        $columnas = Schema::getColumnListing($tabla);
        $q = DB::table($tabla);
        if ($buscar !== null && trim($buscar) !== '') {
            $q->where(function ($w) use ($columnas, $buscar) {
                foreach ($columnas as $c) {
                    if (! in_array($c, ['embedding'], true)) {
                        $w->orWhere($c, 'like', '%'.trim($buscar).'%');
                    }
                }
            });
        }
        $orden = in_array('id', $columnas, true) ? 'id' : $columnas[0];
        $paginador = $q->orderByDesc($orden)->paginate($porPagina, ['*'], 'pagina', $pagina)->withQueryString();
        $paginador->getCollection()->transform(function ($fila) {
            foreach ((array) $fila as $k => $v) {
                if (is_string($v) && in_array($k, self::COLUMNAS_LARGAS, true) && mb_strlen($v) > 160) {
                    $fila->$k = $k === 'embedding' ? '[vector de '.count((array) json_decode($v)).' dimensiones]' : mb_substr($v, 0, 160).'…';
                }
            }

            return $fila;
        });

        return ['tabla' => $tabla, 'nombre' => self::TABLAS[$tabla], 'columnas' => $columnas, 'filas' => $paginador];
    }

    /** Respaldo: copia del archivo SQLite o volcado JSON para MySQL/PostgreSQL. */
    public function respaldo(): string
    {
        @set_time_limit(0);
        $driver = DB::connection()->getDriverName();
        $fecha = now()->format('Ymd_His');
        Storage::disk('local')->makeDirectory('respaldos');

        if ($driver === 'sqlite') {
            $origen = DB::connection()->getDatabaseName();
            $destino = Storage::disk('local')->path("respaldos/expedientes_{$fecha}.sqlite");
            if ($origen === ':memory:' || ! is_file($origen)) {
                throw new ExpedienteException('La base de datos SQLite en memoria no se puede respaldar.');
            }
            DB::statement('PRAGMA wal_checkpoint(FULL)');
            copy($origen, $destino);

            return $destino;
        }

        $destino = Storage::disk('local')->path("respaldos/expedientes_{$fecha}.json");
        $f = fopen($destino, 'w');
        fwrite($f, '{"generado":"'.now()->toIso8601String().'","driver":"'.$driver.'","tablas":{');
        $primera = true;
        foreach (array_keys(self::TABLAS) as $tabla) {
            if (! Schema::hasTable($tabla)) {
                continue;
            }
            fwrite($f, ($primera ? '' : ',').json_encode($tabla).':[');
            $primera = false;
            $sep = '';
            DB::table($tabla)->orderBy(Schema::getColumnListing($tabla)[0])->chunk(500, function ($filas) use ($f, &$sep) {
                foreach ($filas as $fila) {
                    fwrite($f, $sep.json_encode($fila, JSON_UNESCAPED_UNICODE));
                    $sep = ',';
                }
            });
            fwrite($f, ']');
        }
        fwrite($f, '}}');
        fclose($f);

        return $destino;
    }

    public function optimizar(): string
    {
        $driver = DB::connection()->getDriverName();
        switch ($driver) {
            case 'sqlite':
                if (DB::transactionLevel() === 0) {
                    DB::statement('VACUUM'); // no se permite dentro de una transacción
                }
                DB::statement('ANALYZE');
                break;
            case 'mysql':
            case 'mariadb':
                foreach (array_keys(self::TABLAS) as $t) {
                    if (Schema::hasTable($t)) {
                        DB::statement("OPTIMIZE TABLE `{$t}`");
                    }
                }
                break;
            case 'pgsql':
                DB::statement('VACUUM ANALYZE');
                break;
        }

        return "Base de datos optimizada ({$driver}).";
    }

    public function migraciones(): array
    {
        $ejecutadas = Schema::hasTable('migrations') ? DB::table('migrations')->pluck('batch', 'migration')->all() : [];
        $archivos = collect(glob(database_path('migrations/*.php')))->map(fn ($f) => basename($f, '.php'))->sort()->values();

        return $archivos->map(fn ($m) => ['migracion' => $m, 'ejecutada' => isset($ejecutadas[$m]), 'lote' => $ejecutadas[$m] ?? null])->all();
    }

    /** Elimina el índice de la IA (se regenera automáticamente al consultar). */
    public function vaciarIndiceIa(): int
    {
        $n = DB::table('fragmentos_expediente')->count();
        DB::table('fragmentos_expediente')->delete();
        DB::table('expedientes')->update(['indexado_en' => null]);

        return $n;
    }
}
