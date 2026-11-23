<?php

namespace App\Providers;

use App\Domain\Contracts\Repositories\ConfiguracionRepositoryInterface;
use App\Domain\Contracts\Repositories\ConsultaIaRepositoryInterface;
use App\Domain\Contracts\Repositories\ExpedienteRepositoryInterface;
use App\Domain\Contracts\Repositories\FragmentoRepositoryInterface;
use App\Domain\Contracts\Repositories\ImportacionRepositoryInterface;
use App\Domain\Contracts\Repositories\PlantillaRepositoryInterface;
use App\Domain\Contracts\Repositories\TipoExpedienteRepositoryInterface;
use App\Domain\Contracts\Servicios\ClienteLlmInterface;
use App\Domain\Contracts\Servicios\ExtractorDocumentoInterface;
use App\Domain\Contracts\Servicios\GeneradorDocumentosInterface;
use App\Domain\Contracts\Servicios\LectorHojaCalculoInterface;
use App\Domain\Services\FragmentadorTexto;
use App\Infrastructure\Documentos\ExtractorDocumentos;
use App\Infrastructure\Documentos\GeneradorDocumentosDocx;
use App\Infrastructure\HojaCalculo\LectorPhpSpreadsheet;
use App\Infrastructure\IA\OllamaClient;
use App\Infrastructure\Persistence\Repositories\EloquentConfiguracionRepository;
use App\Infrastructure\Persistence\Repositories\EloquentConsultaIaRepository;
use App\Infrastructure\Persistence\Repositories\EloquentExpedienteRepository;
use App\Infrastructure\Persistence\Repositories\EloquentFragmentoRepository;
use App\Infrastructure\Persistence\Repositories\EloquentImportacionRepository;
use App\Infrastructure\Persistence\Repositories\EloquentPlantillaRepository;
use App\Infrastructure\Persistence\Repositories\EloquentTipoExpedienteRepository;
use Illuminate\Support\ServiceProvider;

/**
 * Inversión de dependencias entre capas:
 * Aplicación y Dominio dependen de interfaces; aquí se conectan con la Infraestructura.
 */
class CapasServiceProvider extends ServiceProvider
{
    public array $singletons = [
        // Persistencia
        ExpedienteRepositoryInterface::class => EloquentExpedienteRepository::class,
        TipoExpedienteRepositoryInterface::class => EloquentTipoExpedienteRepository::class,
        PlantillaRepositoryInterface::class => EloquentPlantillaRepository::class,
        FragmentoRepositoryInterface::class => EloquentFragmentoRepository::class,
        ConsultaIaRepositoryInterface::class => EloquentConsultaIaRepository::class,
        ConfiguracionRepositoryInterface::class => EloquentConfiguracionRepository::class,
        ImportacionRepositoryInterface::class => EloquentImportacionRepository::class,
        // Servicios externos
        ClienteLlmInterface::class => OllamaClient::class,
        ExtractorDocumentoInterface::class => ExtractorDocumentos::class,
        GeneradorDocumentosInterface::class => GeneradorDocumentosDocx::class,
        LectorHojaCalculoInterface::class => LectorPhpSpreadsheet::class,
    ];

    public function register(): void
    {
        $this->app->singleton(FragmentadorTexto::class, fn () => new FragmentadorTexto(
            (int) config('ollama.fragmento_tamano', 1000),
            (int) config('ollama.fragmento_solape', 150),
        ));
    }
}
