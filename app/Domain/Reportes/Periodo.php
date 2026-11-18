<?php

declare(strict_types=1);

namespace App\Domain\Reportes;

use App\Domain\Comisiones\PeriodoComision;
use App\Enums\FrecuenciaComision;
use App\Models\Configuracion;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Throwable;

/**
 * Días que abarca un reporte, de un día a otro (los dos completos).
 *
 * Se arma con un atajo (hoy, ayer, esta semana o la pasada, esta quincena
 * o la pasada, este mes o el pasado) o con dos fechas ("otro"). Si vienen al
 * revés se acomodan. Lo que va en curso (semana, quincena, mes) llega hasta
 * hoy. Las quincenas son las de la empresa: del 1 al día de corte (14) y de
 * ahí a fin de mes, las mismas de Comisiones.
 */
final readonly class Periodo
{
    /** Atajos que se ofrecen en pantalla. */
    public const OPCIONES = [
        'hoy'        => 'Hoy',
        'ayer'       => 'Ayer',
        'semana'     => 'Esta semana',
        'mes'        => 'Este mes',
        'mes_pasado' => 'Mes pasado',
        'otro'       => 'Otras fechas',
    ];

    public CarbonImmutable $desde;

    public CarbonImmutable $hasta;

    public function __construct(CarbonImmutable $desde, CarbonImmutable $hasta)
    {
        [$desde, $hasta] = $desde->greaterThan($hasta) ? [$hasta, $desde] : [$desde, $hasta];

        $this->desde = $desde->startOfDay();
        $this->hasta = $hasta->startOfDay();
    }

    /**
     * @param string $clave hoy, ayer, semana, semana_pasada, quincena, quincena_pasada, mes, mes_pasado u otro
     * @param mixed $desde con "otro": fecha de inicio (Y-m-d)
     * @param mixed $hasta con "otro": fecha final (Y-m-d)
     * @param int|null $corte último día de la primera quincena (sin darlo, el de la configuración)
     */
    public static function de(string $clave, mixed $desde = null, mixed $hasta = null, ?CarbonImmutable $hoy = null, ?int $corte = null): self
    {
        $hoy = ($hoy ?? CarbonImmutable::now())->startOfDay();
        $semanaPasada = $hoy->subWeek()->startOfWeek(CarbonInterface::MONDAY);

        return match ($clave) {
            'hoy'             => new self($hoy, $hoy),
            'ayer'            => new self($hoy->subDay(), $hoy->subDay()),
            'semana'          => new self($hoy->startOfWeek(CarbonInterface::MONDAY), $hoy),
            'semana_pasada'   => new self($semanaPasada, $semanaPasada->addDays(6)),
            'quincena'        => new self(self::quincena($hoy, $corte)->desde, $hoy),
            'quincena_pasada' => self::quincena(self::quincena($hoy, $corte)->desde->subDay(), $corte),
            'mes_pasado'      => new self($hoy->subMonthNoOverflow()->startOfMonth(), $hoy->subMonthNoOverflow()->endOfMonth()),
            'otro'            => new self(self::fecha($desde) ?? $hoy->startOfMonth(), self::fecha($hasta) ?? $hoy),
            default           => new self($hoy->startOfMonth(), $hoy),
        };
    }

    /**
     * La quincena completa en que cae la fecha.
     */
    public static function quincena(CarbonImmutable $fecha, ?int $corte = null): self
    {
        $quincena = PeriodoComision::para($fecha, FrecuenciaComision::Quincenal, $corte ?? Configuracion::actual()->comision_corte);

        return new self($quincena->desde, $quincena->hasta);
    }

    /**
     * "11/11/2026" o "01/11/2026 al 30/11/2026".
     */
    public function texto(): string
    {
        return $this->desde->equalTo($this->hasta)
            ? $this->desde->format('d/m/Y')
            : $this->desde->format('d/m/Y').' al '.$this->hasta->format('d/m/Y');
    }

    /**
     * Para el nombre del archivo: "2026-11-01_2026-11-30".
     */
    public function enArchivo(): string
    {
        return $this->desde->equalTo($this->hasta)
            ? $this->desde->format('Y-m-d')
            : $this->desde->format('Y-m-d').'_'.$this->hasta->format('Y-m-d');
    }

    private static function fecha(mixed $valor): ?CarbonImmutable
    {
        if (! is_string($valor) || trim($valor) === '') {
            return null;
        }

        try {
            return CarbonImmutable::parse($valor);
        } catch (Throwable) {
            return null;
        }
    }
}
