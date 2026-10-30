<?php

declare(strict_types=1);

namespace App\Domain\Facturacion;

use App\Domain\Exceptions\ReglaNegocioException;
use App\Enums\EstadoPedido;
use App\Enums\ModoImpresion;
use App\Models\Cai;
use App\Models\Factura;
use App\Models\Pedido;
use App\Models\User;
use App\Support\CandadosDeImpresion;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

/**
 * Imprimir facturas (uno, todos, seleccionados, copia o "no salió"). Es lo
 * único que emite facturas y cuenta impresiones; la hoja (GET) solo muestra.
 *
 * Con muchas impresiones a la vez, en una o varias bodegas:
 *  1. Candado en Redis por pedido: si otra persona imprime el mismo pedido,
 *     ese se salta y se avisa al momento (no salen dos originales); los
 *     demás del lote sí salen.
 *  2. En la base de datos, en una transacción: primero los pedidos y después
 *     los CAI que se van a usar, siempre en orden de id. Así dos lotes al
 *     mismo tiempo esperan uno al otro y nunca se traban; si aun así la base
 *     detecta un bloqueo cruzado, se reintenta solo (3 veces).
 *  3. Cada número sale del CAI bloqueado y la tabla no acepta números
 *     repetidos: aunque Redis falle, nunca hay dos facturas con el mismo número.
 *
 * Si algo falla, no se imprime nada ni se gasta ningún número (todo o nada).
 */
final class ImprimirFacturas
{
    public const MAXIMO = 200;

    private const INTENTOS = 3;

    public function __construct(private readonly EmitirFactura $emitir) {}

    /**
     * @param list<int> $ids
     */
    public function ejecutar(array $ids, User $usuario, ModoImpresion $modo): ResultadoImpresion
    {
        $ids = array_values(array_unique(array_filter($ids, fn (int $id): bool => $id > 0)));
        sort($ids);

        if ($ids === []) {
            throw new ReglaNegocioException('No hay pedidos para imprimir.');
        }

        if (count($ids) > self::MAXIMO) {
            throw new ReglaNegocioException('Se pueden imprimir hasta '.self::MAXIMO.' pedidos de un solo. Use los filtros o seleccione menos.');
        }

        $candados = CandadosDeImpresion::tomar($ids);
        $libres = array_values(array_diff($ids, $candados->ocupados));

        if ($libres === []) {
            throw new ReglaNegocioException(count($ids) === 1
                ? 'Otra persona está imprimiendo este pedido en este momento. Espere unos segundos y revise si ya salió.'
                : 'Otra persona está imprimiendo estos pedidos en este momento. Espere unos segundos y revise si ya salieron.');
        }

        try {
            $resultado = DB::transaction(fn (): ResultadoImpresion => $this->imprimir($libres, $usuario, $modo), self::INTENTOS);
        } finally {
            $candados->soltar();
        }

        // Los que otra persona tenía tomados: se saltan y se avisa.
        foreach (Pedido::query()->whereKey($candados->ocupados)->orderBy('id')->get(['id', 'numero']) as $ocupado) {
            $resultado->saltar($ocupado, 'lo está imprimiendo otra persona en este momento');
        }

        return $resultado;
    }

    /**
     * @param list<int> $ids
     */
    private function imprimir(array $ids, User $usuario, ModoImpresion $modo): ResultadoImpresion
    {
        /** @var Collection<int, Pedido> $pedidos */
        $pedidos = Pedido::query()->whereKey($ids)->orderBy('id')->lockForUpdate()->get();
        $pedidos->load(['items', 'cliente', 'bodega', 'factura', 'impresor']);

        $resultado = new ResultadoImpresion;
        $plan = [];

        foreach ($pedidos as $pedido) {
            [$paso, $motivo] = $this->paso($pedido, $usuario, $modo);

            if ($paso === null) {
                $resultado->saltar($pedido, $motivo);

                continue;
            }

            $plan[] = [$pedido, $paso];
        }

        $this->bloquearCais($plan);

        foreach ($plan as [$pedido, $paso]) {
            $original = match ($paso) {
                'original'  => $this->original($pedido, $usuario),
                'copia'     => $this->copia($pedido, $usuario),
                'reintento' => $this->reintento($pedido, $usuario),
            };

            $resultado->imprimir($pedido, $original);
        }

        return $resultado;
    }

    /**
     * Qué toca con este pedido: 'original', 'copia', 'reintento', o null y el motivo para saltarlo.
     *
     * @return array{0: 'original'|'copia'|'reintento'|null, 1: string}
     */
    private function paso(Pedido $pedido, User $usuario, ModoImpresion $modo): array
    {
        if (! Gate::forUser($usuario)->allows('imprimir', $pedido)) {
            return [null, $pedido->estaValidado() ? 'no es de su bodega o no tiene permiso para imprimir' : 'no está validado'];
        }

        $impreso = $pedido->estado === EstadoPedido::Impreso;
        $porImprimir = $pedido->estado === EstadoPedido::PorImprimir;

        return match ($modo) {
            ModoImpresion::Originales => $porImprimir
                ? ['original', '']
                : [null, 'ya lo imprimió '.($pedido->impresor->name ?? 'otra persona').($pedido->impreso_at ? ' a las '.$pedido->impreso_at->format('H:i') : '')],
            ModoImpresion::Seleccionados => $porImprimir ? ['original', ''] : ['copia', ''],
            ModoImpresion::Copia         => $impreso && $pedido->factura !== null
                ? ['copia', '']
                : [null, 'lo corrigieron: imprímalo de nuevo (sale factura nueva)'],
            ModoImpresion::NoSalio => $impreso && $pedido->factura !== null
                ? ['reintento', '']
                : [null, 'no tiene una factura impresa que repetir'],
        };
    }

    /**
     * Antes de emitir, los CAI que se van a usar quedan tomados en orden de id.
     *
     * @param list<array{0: Pedido, 1: string}> $plan
     */
    private function bloquearCais(array $plan): void
    {
        $caiIds = [];

        foreach ($plan as [$pedido, $paso]) {
            if ($paso === 'original' && $pedido->factura === null) {
                $cai = Cai::paraBodega($pedido->bodega_id);

                if ($cai !== null) {
                    $caiIds[$cai->id] = $cai->id;
                }
            }
        }

        if ($caiIds === []) {
            return;
        }

        ksort($caiIds);

        Cai::query()->whereKey(array_values($caiIds))->orderBy('id')->lockForUpdate()->get();
    }

    /**
     * Original: se emite la factura si no tiene; pasa a Impreso.
     */
    private function original(Pedido $pedido, User $usuario): bool
    {
        $factura = $pedido->factura ?? $this->emitir->ejecutar($pedido, $usuario);
        $original = $factura->impresiones === 0;

        $this->contarImpresion($pedido, $factura, $usuario);

        return $original;
    }

    private function copia(Pedido $pedido, User $usuario): bool
    {
        /** @var Factura $factura */
        $factura = $pedido->factura;

        $this->contarImpresion($pedido, $factura, $usuario);

        return false;
    }

    /**
     * "No salió": la misma hoja otra vez (original si era el original), sin
     * contarla como copia; queda registrado.
     */
    private function reintento(Pedido $pedido, User $usuario): bool
    {
        /** @var Factura $factura */
        $factura = $pedido->factura;

        $factura->forceFill([
            'reintentos'    => $factura->reintentos + 1,
            'reintento_at'  => now(),
            'reintento_por' => $usuario->id,
        ])->save();

        return $factura->impresiones <= 1;
    }

    private function contarImpresion(Pedido $pedido, Factura $factura, User $usuario): void
    {
        $factura->forceFill(['impresiones' => $factura->impresiones + 1])->save();

        $pedido->forceFill([
            'estado'      => EstadoPedido::Impreso,
            'impresiones' => $pedido->impresiones + 1,
            'impreso_at'  => now(),
            'impreso_por' => $usuario->id,
        ])->save();

        $pedido->setRelation('factura', $factura);
    }
}
