<?php

declare(strict_types=1);

namespace App\Domain\Facturacion;

use App\Models\Bodega;
use App\Models\Factura;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;

/**
 * Resumen de las facturas de un día (y una bodega), para cuadrar: todas las
 * que se emitieron ese día en el orden de su número, con el total de las
 * vigentes. Las anuladas salen en la lista (su número se usó ese día) pero
 * no suman; cada una dice cuándo se anuló y cuál la sustituye, y la que
 * sustituye dice a cuál (con su día, si es de otro). Solo lo que el usuario
 * ve (Factura::visiblesPara).
 *
 * Hoja: App\Http\Controllers\ResumenDiaController.
 */
final class ResumenDelDia
{
    /**
     * @return array{
     *     dia: string,
     *     fecha: string,
     *     bodega: string,
     *     variasBodegas: bool,
     *     facturas: list<Factura>,
     *     vigentes: int,
     *     anuladas: int,
     *     sustituciones: int,
     *     copias: int,
     *     exento: float,
     *     gravado: float,
     *     isv: float,
     *     total: float,
     *     numeros: string|null,
     * }
     */
    public static function datos(User $usuario, string $dia, ?int $bodegaId): array
    {
        $facturas = Factura::query()
            ->visiblesPara($usuario)
            ->whereDate('facturas.fecha_emision', $dia)
            ->when($bodegaId !== null, fn (Builder $query): Builder => $query->where('facturas.bodega_id', $bodegaId))
            ->with([
                'pedido:id,numero,vendedor_id',
                'pedido.vendedor:id,name',
                'reemplazo:id,reemplaza_a_id,numero,fecha_emision',
                'reemplazaA:id,numero,fecha_emision',
                'bodega:id,nombre',
            ])
            ->orderBy('facturas.numero')
            ->get();

        $vigentes = $facturas->reject(fn (Factura $factura): bool => $factura->estaAnulada());
        $suma = fn (string $campo): float => round((float) $vigentes->sum($campo), 2);

        return [
            'dia'           => $dia,
            'fecha'         => self::fechaLarga($dia),
            'bodega'        => $bodegaId === null ? 'Todas las bodegas' : (string) Bodega::query()->whereKey($bodegaId)->value('nombre'),
            'variasBodegas' => $facturas->pluck('bodega_id')->unique()->count() > 1,
            'facturas'      => array_values($facturas->all()),
            'vigentes'      => $vigentes->count(),
            'anuladas'      => $facturas->count() - $vigentes->count(),
            'sustituciones' => $vigentes->whereNotNull('reemplaza_a_id')->count(),
            'copias'        => (int) $facturas->sum(fn (Factura $factura): int => max(0, $factura->impresiones - 1)),
            'exento'        => $suma('importe_exento'),
            'gravado'       => $suma('importe_gravado'),
            'isv'           => $suma('isv'),
            'total'         => $suma('total'),
            'numeros'       => self::numeros($facturas->first()?->numero, $facturas->last()?->numero),
        ];
    }

    /**
     * "000-999-01 · del 4106 al 4142" (de CAI distintos, los números completos).
     */
    private static function numeros(?string $desde, ?string $hasta): ?string
    {
        if ($desde === null || $hasta === null) {
            return null;
        }

        $rango = DiaDeFacturacion::rango($desde, $hasta);

        return str_contains($rango, '-') ? "del {$rango}" : substr($desde, 0, 10)." · del {$rango}";
    }

    /**
     * "miércoles 18 de noviembre de 2026".
     */
    public static function fechaLarga(string $dia): string
    {
        $fecha = CarbonImmutable::parse($dia);
        $enEspanol = $fecha->locale('es');

        return $enEspanol instanceof CarbonImmutable
            ? $enEspanol->isoFormat('dddd D [de] MMMM [de] YYYY')
            : $fecha->format('d/m/Y');
    }
}
