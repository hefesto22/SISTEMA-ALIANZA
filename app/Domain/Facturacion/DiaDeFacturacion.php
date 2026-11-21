<?php

declare(strict_types=1);

namespace App\Domain\Facturacion;

use Carbon\CarbonImmutable;

/**
 * Un cuadro de la vista por día de Pedidos: un día y una bodega.
 *
 *  - Por imprimir: $cuantas pedidos por imprimir de ese día, su $total, de
 *    cuántos $vendedores y cuántas son $reimpresiones (corregidos después
 *    de impresos).
 *  - Impresas: $cuantas facturas vigentes emitidas ese día y su $total, las
 *    $anuladas, las $copias y el primer y el último número usados.
 */
final readonly class DiaDeFacturacion
{
    public function __construct(
        public CarbonImmutable $dia,
        public int $bodegaId,
        public string $bodega,
        public int $cuantas,
        public float $total,
        public int $vendedores = 0,
        public int $reimpresiones = 0,
        public int $anuladas = 0,
        public int $copias = 0,
        public ?string $desde = null,
        public ?string $hasta = null,
    ) {}

    /**
     * "2026-11-18-3": para wire:key y para los argumentos de las acciones.
     */
    public function clave(): string
    {
        return $this->dia->format('Y-m-d').'-'.$this->bodegaId;
    }

    /**
     * Los números usados, corto: "4106 al 4142" (si son del mismo CAI; si
     * no, completos). Null si no es un cuadro de impresas.
     */
    public function numeros(): ?string
    {
        return $this->desde === null || $this->hasta === null ? null : self::rango($this->desde, $this->hasta);
    }

    /**
     * "000-999-01-00004106" y "000-999-01-00004142" → "4106 al 4142"; de
     * CAI distintos, completos.
     */
    public static function rango(string $desde, string $hasta): string
    {
        $prefijo = substr($desde, 0, 11);

        if (strlen($desde) < 12 || substr($hasta, 0, 11) !== $prefijo) {
            return "{$desde} al {$hasta}";
        }

        return ltrim(substr($desde, 11), '0').' al '.ltrim(substr($hasta, 11), '0');
    }

    /**
     * "Hoy", "Ayer" o el día de la semana ("Martes").
     */
    public function nombre(): string
    {
        $enEspanol = $this->dia->locale('es');

        return match (true) {
            $this->dia->isToday()     => 'Hoy',
            $this->dia->isYesterday() => 'Ayer',
            default                   => mb_ucfirst($enEspanol instanceof CarbonImmutable ? $enEspanol->dayName : $this->dia->dayName),
        };
    }
}
