<?php

declare(strict_types=1);

namespace App\Filament\Resources\Pedidos\Actions;

use App\Domain\Inventario\Disponible;
use App\Domain\Inventario\SalidaDePedido as SalidaDeBodega;
use App\Enums\UnidadVenta;
use App\Filament\Schemas\Components\SalidaDePedido;
use App\Models\Configuracion;
use App\Models\Existencia;
use App\Models\Pedido;
use App\Models\PedidoItem;
use App\Models\Producto;
use Filament\Schemas\Components\Component;

/**
 * Las líneas del pedido en el modal de Validar y de Corregir: cuánto sale
 * de cada producto (SalidaDePedido). Lo que no hay se marca "No hay" (sale
 * 0). "Hay" es la existencia de la bodega del pedido. Bodega también puede
 * agregar un producto que no venía en el pedido (paraAgregar()).
 */
final class LineasPedido
{
    /** Cuántos productos trae el buscador de "Agregar otro producto". */
    public const RESULTADOS = 20;

    /**
     * @return array<int, Component>
     */
    public static function schema(bool $paraValidar = false): array
    {
        return [
            SalidaDePedido::make('items')->paraValidar($paraValidar),
        ];
    }

    /**
     * Para llenar el modal: lo que se pidió, lo que hay en la bodega y lo que
     * sale ahora. Con eso el modal va diciendo cuánto quedaría en la bodega.
     *
     * Al validar con "controlar existencias" encendido, "Sale" ya viene con
     * lo que alcanza (repartiendo la existencia entre la caja y las unidades
     * del mismo producto, en orden).
     *
     * Al corregir, "salio_unidades" es lo que este pedido ya sacó de la
     * bodega (regresa si sale menos). Los validados antes de que hubiera
     * inventario no sacaron nada y corregirlos no mueve la existencia
     * ("mueve" = false).
     *
     * Primero van las líneas del pedido (en orden) y después, de cada
     * producto, la unidad que no trae (sin id, "pidió 0"): así el modal
     * muestra cajas y unidades de cada producto y bodega puede mandar en la
     * otra (en unidades si una caja vino dañada, por ejemplo).
     *
     * @return array{items: list<array<string, mixed>>}
     */
    public static function datos(Pedido $pedido, bool $segunExistencia = false): array
    {
        $items = $pedido->items()->with('producto')->orderBy('id')->get();
        $hay = Disponible::existencia($pedido->bodega_id, $items->pluck('producto_id')->unique()->values()->all());
        [$mueve, $salio] = self::movimiento($pedido);

        $ajustar = $segunExistencia && Configuracion::actual()->controlar_existencias;
        $quedan = $hay;
        $filas = [];

        /** @var PedidoItem $item */
        foreach ($items as $item) {
            $sale = $item->cantidadFacturable();

            if ($ajustar) {
                $porUnidad = $item->producto->unidadesEn($item->unidad);
                $sale = min($sale, intdiv(max(0, $quedan[$item->producto_id] ?? 0), $porUnidad));
                $quedan[$item->producto_id] = ($quedan[$item->producto_id] ?? 0) - $sale * $porUnidad;
            }

            $filas[] = self::fila($item->id, $item->producto, $item->unidad, $item->cantidad, $sale, $hay, $salio, $mueve);
        }

        // La otra unidad de cada producto (la caja solo si trae más de 1).
        foreach ($items->unique('producto_id') as $item) {
            foreach ([UnidadVenta::Caja, UnidadVenta::Unidad] as $unidad) {
                $yaViene = $items->contains(fn (PedidoItem $otra): bool => $otra->producto_id === $item->producto_id && $otra->unidad === $unidad);

                if (! $yaViene && ($unidad === UnidadVenta::Unidad || $item->producto->unidades_por_caja > 1)) {
                    $filas[] = self::fila(null, $item->producto, $unidad, 0, 0, $hay, $salio, $mueve);
                }
            }
        }

        return ['items' => $filas];
    }

    /**
     * Buscador de "Agregar otro producto" en Validar y Corregir: productos activos
     * por código o nombre, para mandar uno que el cliente no pidió. Primero
     * los que hay en la bodega del pedido, después el código exacto y luego
     * por nombre. Cada uno trae sus filas (caja y unidad, sin id, "pidió 0"),
     * iguales a las de datos(), para que el modal las sume a la lista. "Sale"
     * ya viene en 1: una caja si en la bodega alcanza para una (o no hay
     * nada), si no una unidad.
     *
     * @return list<array{producto_id: int, codigo: string, descripcion: string, por_caja: int, hay_unidades: int, filas: list<array<string, mixed>>}>
     */
    public static function paraAgregar(Pedido $pedido, string $buscar): array
    {
        $buscar = trim($buscar);

        if ($buscar === '') {
            return [];
        }

        $productos = Producto::query()
            ->fromSub(Disponible::conColumnas(Producto::query()->activos()->buscar($buscar), $pedido->bodega_id), 'productos')
            ->orderByRaw('CASE WHEN existencia_bodega > 0 THEN 0 ELSE 1 END')
            ->orderByRaw('CASE WHEN UPPER(codigo) = ? THEN 0 ELSE 1 END', [mb_strtoupper($buscar)])
            ->orderBy('descripcion')
            ->limit(self::RESULTADOS)
            ->get();

        [$mueve, $salio] = self::movimiento($pedido);
        $resultados = [];

        foreach ($productos as $producto) {
            $enBodega = $producto->getAttribute('existencia_bodega');
            $enBodega = is_numeric($enBodega) ? (int) $enBodega : 0;
            $hay = [$producto->id => $enBodega];
            $conCaja = $producto->unidades_por_caja > 1;
            $enCaja = $conCaja && ($enBodega >= $producto->unidades_por_caja || $enBodega <= 0);
            $filas = [];

            if ($conCaja) {
                $filas[] = self::fila(null, $producto, UnidadVenta::Caja, 0, $enCaja ? 1 : 0, $hay, $salio, $mueve);
            }

            $filas[] = self::fila(null, $producto, UnidadVenta::Unidad, 0, $enCaja ? 0 : 1, $hay, $salio, $mueve);

            $resultados[] = [
                'producto_id'  => $producto->id,
                'codigo'       => $producto->codigo,
                'descripcion'  => $producto->descripcion,
                'por_caja'     => $producto->unidades_por_caja,
                'hay_unidades' => $enBodega,
                'filas'        => $filas,
            ];
        }

        return $resultados;
    }

    /**
     * Si el pedido mueve la existencia de la bodega y lo que ya sacó de cada
     * producto (en unidades). Al validar mueve y no ha sacado nada; al
     * corregir, lo del kárdex. Los validados antes de que hubiera inventario
     * no sacaron nada y corregirlos no mueve la existencia.
     *
     * @return array{0: bool, 1: array<int, int>}
     */
    private static function movimiento(Pedido $pedido): array
    {
        $mueve = ! $pedido->estaValidado() || SalidaDeBodega::yaSalio($pedido);

        return [$mueve, $pedido->estaValidado() && $mueve ? SalidaDeBodega::yaSalido($pedido) : []];
    }

    /**
     * Las líneas que bodega llenó en la unidad que el pedido no traía o de un
     * producto que agregó: [{producto_id, unidad, cantidad}] para
     * ValidarDespacho / CorregirPedido.
     *
     * @param array<string, mixed> $data
     *
     * @return list<array{producto_id: int, unidad: UnidadVenta, cantidad: int}>
     */
    public static function nuevas(array $data): array
    {
        $nuevas = [];

        foreach ((array) ($data['items'] ?? []) as $fila) {
            if (! is_array($fila) || isset($fila['id']) || ! is_numeric($fila['producto_id'] ?? null) || ! is_numeric($fila['sale'] ?? null)) {
                continue;
            }

            if ((int) $fila['sale'] > 0) {
                $nuevas[] = [
                    'producto_id' => (int) $fila['producto_id'],
                    'unidad'      => ($fila['es_caja'] ?? false) ? UnidadVenta::Caja : UnidadVenta::Unidad,
                    'cantidad'    => (int) $fila['sale'],
                ];
            }
        }

        return $nuevas;
    }

    /**
     * Una fila del modal.
     *
     * @param array<int, int> $hay unidades en la bodega por producto
     * @param array<int, int> $salio unidades que ya sacó el pedido por producto
     *
     * @return array<string, mixed>
     */
    private static function fila(?int $id, Producto $producto, UnidadVenta $unidad, int $cantidad, int $sale, array $hay, array $salio, bool $mueve): array
    {
        return [
            'id'             => $id,
            'producto_id'    => $producto->id,
            'producto'       => $producto->etiqueta(),
            'codigo'         => $producto->codigo,
            'descripcion'    => $producto->descripcion,
            'unidad'         => $unidad->getLabel(),
            'es_caja'        => $unidad === UnidadVenta::Caja,
            'sigla'          => $unidad === UnidadVenta::Caja ? 'CJ' : 'UN',
            'por_unidad'     => $producto->unidadesEn($unidad),
            'por_caja'       => $producto->unidades_por_caja,
            'cantidad'       => $cantidad,
            'hay'            => Existencia::enCajas($hay[$producto->id] ?? 0, $producto->unidades_por_caja),
            'hay_unidades'   => $hay[$producto->id] ?? 0,
            'salio_unidades' => $salio[$producto->id] ?? 0,
            'mueve'          => $mueve,
            'sale'           => $sale,
        ];
    }

    /**
     * Para el aviso al validar: "Sale completo." o "No va completo: ACEITE…
     * (falta 1 caja de 3); JABON… (no sale: pidió 2 unidades). Va de más:
     * AZUCAR… (+1 unidad, pidió 2)." Lo que bodega agregó va al final:
     * "Agregado: SAL… (2 cajas)."
     */
    public static function incompletas(Pedido $pedido): string
    {
        $faltan = [];
        $deMas = [];
        $agregados = [];

        foreach ($pedido->items()->with('producto')->orderBy('id')->get() as $item) {
            if ($item->cantidadFacturable() > 0 && $item->esAgregado()) {
                $agregados[] = "{$item->producto->descripcion} (".PedidoItem::enPalabras($item->cantidadFacturable(), $item->unidad).')';

                continue;
            }

            if (($falta = $item->faltaEnPalabras(corto: true)) !== null) {
                $faltan[] = "{$item->producto->descripcion} ({$falta})";
            }

            if (($mas = $item->deMasEnPalabras(corto: true)) !== null) {
                $deMas[] = "{$item->producto->descripcion} ({$mas})";
            }
        }

        $partes = $faltan === [] && $deMas === []
            ? ['Sale completo.']
            : [self::lista('No va completo', $faltan), self::lista('Va de más', $deMas)];

        $partes[] = self::lista('Agregado', $agregados);

        return implode(' ', array_filter($partes, fn (string $parte): bool => $parte !== ''));
    }

    /**
     * "No va completo: A; B; C; D y 2 más." (vacío si no hay nada).
     *
     * @param list<string> $partes
     */
    private static function lista(string $titulo, array $partes): string
    {
        if ($partes === []) {
            return '';
        }

        $resto = count($partes) > 4 ? ' y '.(count($partes) - 4).' más' : '';

        return $titulo.': '.implode('; ', array_slice($partes, 0, 4)).$resto.'.';
    }

    /**
     * Lo que sale de cada línea según el modal; las que se quitaron, 0.
     *
     * @param array<string, mixed> $data
     *
     * @return array<int, int|null>
     */
    public static function cantidades(array $data, Pedido $pedido): array
    {
        $sale = [];

        foreach ((array) ($data['items'] ?? []) as $fila) {
            if (is_array($fila) && isset($fila['id'])) {
                $sale[(int) $fila['id']] = is_numeric($fila['sale'] ?? null) ? (int) $fila['sale'] : null;
            }
        }

        $cantidades = [];

        foreach ($pedido->items()->pluck('id') as $id) {
            $cantidades[(int) $id] = array_key_exists((int) $id, $sale) ? $sale[(int) $id] : 0;
        }

        return $cantidades;
    }
}
