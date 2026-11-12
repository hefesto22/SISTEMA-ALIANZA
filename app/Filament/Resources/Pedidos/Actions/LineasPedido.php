<?php

declare(strict_types=1);

namespace App\Filament\Resources\Pedidos\Actions;

use App\Domain\Inventario\Disponible;
use App\Domain\Inventario\SalidaDePedido as SalidaDeBodega;
use App\Filament\Schemas\Components\SalidaDePedido;
use App\Models\Configuracion;
use App\Models\Pedido;
use App\Models\PedidoItem;
use App\Models\Presentacion;
use App\Models\Producto;
use Filament\Schemas\Components\Component;
use Illuminate\Database\Eloquent\Builder;

/**
 * Las líneas del pedido en el modal de Validar y de Corregir: cuánto sale
 * de cada producto (SalidaDePedido), en cada una de sus presentaciones. Lo
 * que no hay se marca "No hay" (sale 0). "Hay" es la existencia de la bodega
 * del pedido. Bodega también puede agregar un producto que no venía en el
 * pedido (paraAgregar()).
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
     * lo que alcanza (repartiendo la existencia entre las presentaciones del
     * mismo producto, en orden).
     *
     * Al corregir, "salio_unidades" es lo que este pedido ya sacó de la
     * bodega (regresa si sale menos). Los validados antes de que hubiera
     * inventario no sacaron nada y corregirlos no mueve la existencia
     * ("mueve" = false).
     *
     * Primero van las líneas del pedido (en orden) y después, de cada
     * producto, las presentaciones que se venden y no trae (sin id, "pidió
     * 0"): así bodega puede mandar en otra (en unidades si una caja vino
     * dañada, o en otra caja).
     *
     * @return array{items: list<array<string, mixed>>}
     */
    public static function datos(Pedido $pedido, bool $segunExistencia = false): array
    {
        $items = $pedido->items()->with(['producto.presentaciones.unidadMedida', 'presentacion.unidadMedida'])->orderBy('id')->get();
        $hay = Disponible::existencia($pedido->bodega_id, $items->pluck('producto_id')->unique()->values()->all());
        [$mueve, $salio] = self::movimiento($pedido);

        $ajustar = $segunExistencia && Configuracion::actual()->controlar_existencias;
        $quedan = $hay;
        $filas = [];

        /** @var PedidoItem $item */
        foreach ($items as $item) {
            $sale = $item->cantidadFacturable();

            if ($ajustar) {
                $contenido = max(1, $item->contenido);
                $sale = min($sale, intdiv(max(0, $quedan[$item->producto_id] ?? 0), $contenido));
                $quedan[$item->producto_id] = ($quedan[$item->producto_id] ?? 0) - $sale * $contenido;
            }

            $filas[] = self::fila($item->id, $item->presentacion, $item->producto, $item->cantidad, $sale, $hay, $salio, $mueve);
        }

        // Las otras presentaciones que se venden de cada producto.
        foreach ($items->unique('producto_id') as $item) {
            foreach ($item->producto->presentacionesActivas() as $presentacion) {
                if (! $items->contains(fn (PedidoItem $otra): bool => $otra->presentacion_id === $presentacion->id)) {
                    $filas[] = self::fila(null, $presentacion, $item->producto, 0, 0, $hay, $salio, $mueve);
                }
            }
        }

        return ['items' => $filas];
    }

    /**
     * Buscador de "Agregar otro producto" en Validar y Corregir: productos
     * activos (que tengan alguna presentación que se venda) por código o
     * nombre, para mandar uno que el cliente no pidió. Primero los que hay en
     * la bodega del pedido, después el código exacto y luego por nombre.
     * Cada uno trae sus filas (una por presentación, sin id, "pidió 0"),
     * iguales a las de datos(), para que el modal las sume a la lista.
     * "Sale" ya viene en 1 en la presentación más grande que alcanza con lo
     * que hay en la bodega (sin nada en la bodega, la más grande).
     *
     * @return list<array{producto_id: int, codigo: string, descripcion: string, hay: string, hay_unidades: int, filas: list<array<string, mixed>>}>
     */
    public static function paraAgregar(Pedido $pedido, string $buscar): array
    {
        $buscar = trim($buscar);

        if ($buscar === '') {
            return [];
        }

        $conPresentacion = Producto::query()
            ->activos()
            ->buscar($buscar)
            ->whereHas('presentaciones', fn (Builder $presentaciones): Builder => $presentaciones->where('activo', true));

        $productos = Producto::query()
            ->fromSub(Disponible::conColumnas($conPresentacion, $pedido->bodega_id), 'productos')
            ->orderByRaw('CASE WHEN existencia_bodega > 0 THEN 0 ELSE 1 END')
            ->orderByRaw('CASE WHEN UPPER(codigo) = ? THEN 0 ELSE 1 END', [mb_strtoupper($buscar)])
            ->orderBy('descripcion')
            ->limit(self::RESULTADOS)
            ->with('presentaciones.unidadMedida')
            ->get();

        [$mueve, $salio] = self::movimiento($pedido);
        $resultados = [];

        foreach ($productos as $producto) {
            $enBodega = $producto->getAttribute('existencia_bodega');
            $enBodega = is_numeric($enBodega) ? (int) $enBodega : 0;
            $hay = [$producto->id => $enBodega];
            $activas = $producto->presentacionesActivas();
            $sale = self::conQueSale($activas->all(), $enBodega);
            $filas = [];

            foreach ($activas->sortByDesc('contenido') as $presentacion) {
                $filas[] = self::fila(null, $presentacion, $producto, 0, $presentacion->id === $sale?->id ? 1 : 0, $hay, $salio, $mueve);
            }

            $resultados[] = [
                'producto_id'  => $producto->id,
                'codigo'       => $producto->codigo,
                'descripcion'  => $producto->descripcion,
                'hay'          => $producto->existenciaEnTexto($enBodega),
                'hay_unidades' => $enBodega,
                'filas'        => $filas,
            ];
        }

        return $resultados;
    }

    /**
     * En qué presentación sale 1 al agregar un producto: la más grande que
     * alcanza con lo que hay; sin nada en la bodega, la más grande; si hay
     * algo pero no alcanza para ninguna, la más chica.
     *
     * @param array<int, Presentacion> $activas
     */
    private static function conQueSale(array $activas, int $enBodega): ?Presentacion
    {
        usort($activas, fn (Presentacion $a, Presentacion $b): int => $b->contenido <=> $a->contenido);

        if ($activas === []) {
            return null;
        }

        if ($enBodega <= 0) {
            return $activas[0];
        }

        foreach ($activas as $presentacion) {
            if ($presentacion->contenido <= $enBodega) {
                return $presentacion;
            }
        }

        return $activas[count($activas) - 1];
    }

    /**
     * Si el pedido mueve la existencia de la bodega y lo que ya sacó de cada
     * producto (en unidades base). Al validar mueve y no ha sacado nada; al
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
     * Las líneas que bodega llenó en una presentación que el pedido no traía
     * o de un producto que agregó: [{presentacion_id, cantidad}] para
     * ValidarDespacho / CorregirPedido.
     *
     * @param array<string, mixed> $data
     *
     * @return list<array{presentacion_id: int, cantidad: int}>
     */
    public static function nuevas(array $data): array
    {
        $nuevas = [];

        foreach ((array) ($data['items'] ?? []) as $fila) {
            if (! is_array($fila) || isset($fila['id']) || ! is_numeric($fila['presentacion_id'] ?? null) || ! is_numeric($fila['sale'] ?? null)) {
                continue;
            }

            if ((int) $fila['sale'] > 0) {
                $nuevas[] = [
                    'presentacion_id' => (int) $fila['presentacion_id'],
                    'cantidad'        => (int) $fila['sale'],
                ];
            }
        }

        return $nuevas;
    }

    /**
     * Una fila del modal: una presentación de un producto.
     *
     * @param array<int, int> $hay unidades base en la bodega por producto
     * @param array<int, int> $salio unidades base que ya sacó el pedido por producto
     *
     * @return array<string, mixed>
     */
    private static function fila(?int $id, Presentacion $presentacion, Producto $producto, int $cantidad, int $sale, array $hay, array $salio, bool $mueve): array
    {
        $mayor = $producto->presentacionMayor();

        return [
            'id'              => $id,
            'producto_id'     => $producto->id,
            'presentacion_id' => $presentacion->id,
            'producto'        => $producto->etiqueta(),
            'codigo'          => $producto->codigo,
            'descripcion'     => $producto->descripcion,
            'sigla'           => $presentacion->sigla(),
            'nombre'          => $presentacion->nombre(),
            'singular'        => $presentacion->palabra(1),
            'plural'          => $presentacion->palabra(2),
            'es_base'         => $presentacion->es_base,
            'contenido'       => $presentacion->contenido,
            // Para contar la existencia del producto: "2 CJ12 + 5 UN".
            'base_sigla'      => $producto->presentacionBase()->sigla(),
            'mayor_sigla'     => $mayor?->sigla(),
            'mayor_contenido' => $mayor === null ? 1 : $mayor->contenido,
            'cantidad'        => $cantidad,
            'hay'             => $producto->existenciaEnTexto($hay[$producto->id] ?? 0),
            'hay_unidades'    => $hay[$producto->id] ?? 0,
            'salio_unidades'  => $salio[$producto->id] ?? 0,
            'mueve'           => $mueve,
            'sale'            => $sale,
        ];
    }

    /**
     * Para el aviso al validar: "Sale completo." o "No va completo: ACEITE…
     * (falta 1 caja de 12, pidió 3); JABON… (no sale: pidió 2 unidades).
     * Va de más: AZUCAR… (+1 unidad, pidió 2)." Lo que bodega agregó va al
     * final: "Agregado: SAL… (2 cajas de 6)."
     */
    public static function incompletas(Pedido $pedido): string
    {
        $faltan = [];
        $deMas = [];
        $agregados = [];

        foreach ($pedido->items()->with(['producto', 'presentacion.unidadMedida'])->orderBy('id')->get() as $item) {
            if ($item->cantidadFacturable() > 0 && $item->esAgregado()) {
                $agregados[] = "{$item->producto->descripcion} (".$item->presentacion->enPalabras($item->cantidadFacturable()).')';

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
