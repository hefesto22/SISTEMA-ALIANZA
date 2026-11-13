<?php

declare(strict_types=1);

namespace App\Filament\Resources\Pedidos\Actions;

use App\Domain\Inventario\Disponible;
use App\Domain\Inventario\SalidaDePedido as SalidaDeBodega;
use App\Filament\Schemas\Components\SalidaDePedido;
use App\Models\Configuracion;
use App\Models\Existencia;
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
 * del pedido, de cada presentación (las cajas cerradas y las sueltas
 * aparte). Si las sueltas no alcanzan, bodega dice qué caja abre
 * (aperturas()). Bodega también puede agregar un producto que no venía en
 * el pedido (paraAgregar()).
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
     * lo que alcanza: cada caja de sus cajas y las sueltas de las sueltas y
     * de las cajas que sobran (se pueden abrir).
     *
     * Al corregir, "salio_pres" es lo que este pedido ya sacó de la bodega
     * en esa presentación (regresa si sale menos). Los validados antes de que
     * hubiera inventario no sacaron nada y corregirlos no mueve la existencia
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
        $existencias = self::existencias($pedido->bodega_id, $items->pluck('producto_id')->unique()->values()->all());
        [$mueve, $salio] = self::movimiento($pedido);

        $sale = [];

        foreach ($items as $item) {
            $sale[$item->id] = $item->cantidadFacturable();
        }

        if ($segunExistencia && Configuracion::actual()->controlar_existencias) {
            $sale = self::loQueAlcanza($items->all(), $sale, $existencias);
        }

        $filas = [];

        /** @var PedidoItem $item */
        foreach ($items as $item) {
            $filas[] = self::fila($item->id, $item->presentacion, $item->producto, $item->cantidad, $sale[$item->id], $existencias, $salio, $mueve);
        }

        // Las otras presentaciones que se venden de cada producto.
        foreach ($items->unique('producto_id') as $item) {
            foreach ($item->producto->presentacionesActivas() as $presentacion) {
                if (! $items->contains(fn (PedidoItem $otra): bool => $otra->presentacion_id === $presentacion->id)) {
                    $filas[] = self::fila(null, $presentacion, $item->producto, 0, 0, $existencias, $salio, $mueve);
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
        $existencias = self::existencias($pedido->bodega_id, $productos->modelKeys());
        $resultados = [];

        foreach ($productos as $producto) {
            $enBodega = $producto->getAttribute('existencia_bodega');
            $enBodega = is_numeric($enBodega) ? (int) $enBodega : 0;
            $activas = $producto->presentacionesActivas();
            $sale = self::conQueSale($activas->all(), $existencias);
            $filas = [];

            foreach ($activas->sortByDesc('contenido') as $presentacion) {
                $filas[] = self::fila(null, $presentacion, $producto, 0, $presentacion->id === $sale?->id ? 1 : 0, $existencias, $salio, $mueve);
            }

            $resultados[] = [
                'producto_id'  => $producto->id,
                'codigo'       => $producto->codigo,
                'descripcion'  => $producto->descripcion,
                'hay'          => self::hayTexto($producto, $existencias),
                'hay_unidades' => $enBodega,
                'filas'        => $filas,
            ];
        }

        return $resultados;
    }

    /**
     * En qué presentación sale 1 al agregar un producto: la más grande que
     * tiene existencia en la bodega; sin nada, la más grande.
     *
     * @param array<int, Presentacion> $activas
     * @param array<int, int> $existencias [presentacion_id => lo que hay en la bodega]
     */
    private static function conQueSale(array $activas, array $existencias): ?Presentacion
    {
        usort($activas, fn (Presentacion $a, Presentacion $b): int => $b->contenido <=> $a->contenido);

        foreach ($activas as $presentacion) {
            if (($existencias[$presentacion->id] ?? 0) > 0) {
                return $presentacion;
            }
        }

        return $activas[0] ?? null;
    }

    /**
     * Con "controlar existencias": lo que alcanza a salir de cada línea.
     * Primero las cajas (cada una de sus cajas, en orden de línea) y después
     * las sueltas: de las sueltas y, si no alcanzan, de las cajas que
     * sobraron (se abren al validar).
     *
     * @param list<PedidoItem> $items
     * @param array<int, int> $sale [id de línea => lo que pidió]
     * @param array<int, int> $existencias [presentacion_id => lo que hay en la bodega]
     *
     * @return array<int, int>
     */
    private static function loQueAlcanza(array $items, array $sale, array $existencias): array
    {
        $quedan = $existencias;

        foreach ($items as $item) {
            if (! $item->presentacion->es_base) {
                $sale[$item->id] = min($sale[$item->id], max(0, $quedan[$item->presentacion_id] ?? 0));
                $quedan[$item->presentacion_id] = ($quedan[$item->presentacion_id] ?? 0) - $sale[$item->id];
            }
        }

        foreach ($items as $item) {
            if ($item->presentacion->es_base) {
                $sueltas = max(0, $quedan[$item->presentacion_id] ?? 0) + (int) $item->producto->presentaciones
                    ->filter(fn (Presentacion $caja): bool => ! $caja->es_base)
                    ->sum(fn (Presentacion $caja): int => max(0, $quedan[$caja->id] ?? 0) * $caja->contenido);

                $sale[$item->id] = min($sale[$item->id], $sueltas);
                $quedan[$item->presentacion_id] = ($quedan[$item->presentacion_id] ?? 0) - $sale[$item->id];

                // Lo que no hubo suelto sale de abrir cajas: ya no quedan para otra línea.
                $falta = max(0, -($quedan[$item->presentacion_id] ?? 0));

                foreach ($item->producto->presentaciones->filter(fn (Presentacion $caja): bool => ! $caja->es_base)->sortBy('contenido') as $caja) {
                    while ($falta > 0 && ($quedan[$caja->id] ?? 0) > 0) {
                        $quedan[$caja->id]--;
                        $quedan[$item->presentacion_id] += $caja->contenido;
                        $falta = max(0, -$quedan[$item->presentacion_id]);
                    }
                }
            }
        }

        return $sale;
    }

    /**
     * [presentacion_id => lo que hay en la bodega] de esos productos.
     *
     * @param array<mixed> $productoIds
     *
     * @return array<int, int>
     */
    private static function existencias(int $bodegaId, array $productoIds): array
    {
        if ($productoIds === []) {
            return [];
        }

        /** @var array<int, int> $hay */
        $hay = Existencia::query()
            ->where('bodega_id', $bodegaId)
            ->whereIn('producto_id', $productoIds)
            ->pluck('cantidad', 'presentacion_id')
            ->map(fn (mixed $cantidad): int => (int) $cantidad)
            ->all();

        return $hay;
    }

    /**
     * "2 CJ48 · 3 CJ32 · 5 UN": lo que hay del producto en la bodega.
     *
     * @param array<int, int> $existencias
     */
    private static function hayTexto(Producto $producto, array $existencias): string
    {
        $suyas = [];

        foreach ($producto->presentaciones as $presentacion) {
            $suyas[$presentacion->id] = $existencias[$presentacion->id] ?? 0;
        }

        return $producto->existenciaPorPresentacion($suyas);
    }

    /**
     * Si el pedido mueve la existencia de la bodega y lo que ya sacó de cada
     * presentación. Al validar mueve y no ha sacado nada; al corregir, lo del
     * kárdex. Los validados antes de que hubiera inventario no sacaron nada
     * y corregirlos no mueve la existencia.
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
     * Las cajas que bodega dijo que se abren para tener sueltas (en la fila
     * de la base de cada producto): [presentacion_id de la caja => cuántas]
     * para ValidarDespacho / CorregirPedido.
     *
     * @param array<string, mixed> $data
     *
     * @return array<int, int>
     */
    public static function aperturas(array $data): array
    {
        $abrir = [];

        foreach ((array) ($data['items'] ?? []) as $fila) {
            if (! is_array($fila) || ! is_numeric($fila['abrir_de'] ?? null) || ! is_numeric($fila['abrir_cajas'] ?? null) || (int) $fila['abrir_cajas'] < 1) {
                continue;
            }

            $abrir[(int) $fila['abrir_de']] = ($abrir[(int) $fila['abrir_de']] ?? 0) + (int) $fila['abrir_cajas'];
        }

        return $abrir;
    }

    /**
     * Una fila del modal: una presentación de un producto.
     *
     * @param array<int, int> $existencias lo que hay en la bodega por presentación
     * @param array<int, int> $salio lo que ya sacó el pedido por presentación
     *
     * @return array<string, mixed>
     */
    private static function fila(?int $id, Presentacion $presentacion, Producto $producto, int $cantidad, int $sale, array $existencias, array $salio, bool $mueve): array
    {
        $mayor = $producto->presentacionMayor();
        $unidades = 0;
        $salioUnidades = 0;

        foreach ($producto->presentaciones as $suya) {
            $unidades += ($existencias[$suya->id] ?? 0) * $suya->contenido;
            $salioUnidades += ($salio[$suya->id] ?? 0) * $suya->contenido;
        }

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
            'hay'             => self::hayTexto($producto, $existencias),
            'hay_unidades'    => $unidades,
            'salio_unidades'  => $salioUnidades,
            // De ESTA presentación: lo que hay en la bodega y lo que ya sacó el pedido.
            'hay_pres'   => $existencias[$presentacion->id] ?? 0,
            'salio_pres' => $salio[$presentacion->id] ?? 0,
            'mueve'      => $mueve,
            'sale'       => $sale,
            // Solo en la base: qué caja se abre y cuántas, si las sueltas no alcanzan (lo dice bodega).
            'abrir_de'    => null,
            'abrir_cajas' => 0,
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
