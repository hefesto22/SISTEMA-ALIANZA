<?php

declare(strict_types=1);

namespace App\Domain\Comisiones;

use App\Enums\FrecuenciaComision;
use App\Models\Configuracion;
use Carbon\CarbonImmutable;

/**
 * Un periodo de pago de comisiones: una quincena o un mes.
 *
 * Quincenal con corte 14: del 1 al 14 (se paga el 15) y del 15 a fin de
 * mes. Mensual: el mes completo. La frecuencia y el corte se configuran en
 * Comisiones → Periodo de pago.
 */
final class PeriodoComision
{
    private const MESES = ['enero', 'febrero', 'marzo', 'abril', 'mayo', 'junio', 'julio', 'agosto', 'septiembre', 'octubre', 'noviembre', 'diciembre'];

    public const CORTE_MINIMO = 10;

    public const CORTE_MAXIMO = 20;

    public function __construct(
        public readonly CarbonImmutable $desde,
        public readonly CarbonImmutable $hasta,
        public readonly FrecuenciaComision $frecuencia,
    ) {}

    /**
     * El periodo en curso según la configuración.
     */
    public static function actual(?CarbonImmutable $hoy = null): self
    {
        $configuracion = Configuracion::actual();

        return self::para($hoy ?? CarbonImmutable::today(), $configuracion->comision_frecuencia, $configuracion->comision_corte);
    }

    /**
     * El periodo en que cae esa fecha.
     */
    public static function para(CarbonImmutable $fecha, FrecuenciaComision $frecuencia, int $corte): self
    {
        $inicio = $fecha->startOfMonth()->startOfDay();
        $fin = $fecha->endOfMonth()->startOfDay();

        if ($frecuencia === FrecuenciaComision::Mensual) {
            return new self($inicio, $fin, $frecuencia);
        }

        $corte = max(1, min($corte, $fin->day - 1));

        return $fecha->day <= $corte
            ? new self($inicio, $inicio->setDay($corte), $frecuencia)
            : new self($inicio->setDay($corte + 1), $fin, $frecuencia);
    }

    /**
     * De la clave "2026-11-01_2026-11-14" (la del selector de periodos).
     */
    public static function deClave(mixed $clave): ?self
    {
        if (! is_string($clave) || preg_match('/^(\d{4}-\d{2}-\d{2})_(\d{4}-\d{2}-\d{2})$/', $clave, $m) !== 1) {
            return null;
        }

        $desde = CarbonImmutable::createFromFormat('!Y-m-d', $m[1]);
        $hasta = CarbonImmutable::createFromFormat('!Y-m-d', $m[2]);

        if (! $desde instanceof CarbonImmutable || ! $hasta instanceof CarbonImmutable || $hasta->lt($desde) || $desde->diffInDays($hasta) > 31) {
            return null;
        }

        $mes = $desde->day === 1 && $hasta->isSameDay($desde->endOfMonth());

        return new self($desde, $hasta, $mes ? FrecuenciaComision::Mensual : FrecuenciaComision::Quincenal);
    }

    /**
     * Los últimos periodos, del actual hacia atrás.
     *
     * @return list<self>
     */
    public static function recientes(int $cuantos, ?CarbonImmutable $hoy = null): array
    {
        $periodos = [];
        $periodo = self::actual($hoy);

        for ($i = 0; $i < $cuantos; $i++) {
            $periodos[] = $periodo;
            $periodo = $periodo->anterior();
        }

        return $periodos;
    }

    public function anterior(): self
    {
        $configuracion = Configuracion::actual();

        return self::para($this->desde->subDay(), $configuracion->comision_frecuencia, $configuracion->comision_corte);
    }

    public function clave(): string
    {
        return $this->desde->format('Y-m-d').'_'.$this->hasta->format('Y-m-d');
    }

    /**
     * "1 al 14 de noviembre de 2026" o "noviembre de 2026".
     */
    public function texto(): string
    {
        $mes = self::MESES[$this->hasta->month - 1].' de '.$this->hasta->year;

        if ($this->desde->day === 1 && $this->hasta->isSameDay($this->hasta->endOfMonth())) {
            return ucfirst($mes);
        }

        if ($this->desde->month !== $this->hasta->month) {
            return $this->desde->format('d/m/Y').' al '.$this->hasta->format('d/m/Y');
        }

        return $this->desde->day.' al '.$this->hasta->day.' de '.$mes;
    }

    public function dias(): int
    {
        return (int) $this->desde->diffInDays($this->hasta) + 1;
    }

    /**
     * Cuántos días del periodo ya pasaron (contando hoy).
     */
    public function diasCorridos(?CarbonImmutable $hoy = null): int
    {
        $hoy = ($hoy ?? CarbonImmutable::today())->startOfDay();

        if ($hoy->lt($this->desde)) {
            return 0;
        }

        return $hoy->gt($this->hasta) ? $this->dias() : (int) $this->desde->diffInDays($hoy) + 1;
    }

    /**
     * Ya llegó su último día: se puede cerrar y pagar (lo que se facture
     * después del cierre con fecha de este periodo pasa al siguiente).
     */
    public function sePuedeCerrar(?CarbonImmutable $hoy = null): bool
    {
        return ! ($hoy ?? CarbonImmutable::today())->startOfDay()->lt($this->hasta);
    }

    public function enCurso(?CarbonImmutable $hoy = null): bool
    {
        $hoy = ($hoy ?? CarbonImmutable::today())->startOfDay();

        return ! $hoy->lt($this->desde) && ! $hoy->gt($this->hasta);
    }

    /**
     * El primer instante después del periodo (para "fecha < hasta").
     */
    public function finExclusivo(): string
    {
        return $this->hasta->addDay()->format('Y-m-d 00:00:00');
    }

    public function inicio(): string
    {
        return $this->desde->format('Y-m-d 00:00:00');
    }
}
