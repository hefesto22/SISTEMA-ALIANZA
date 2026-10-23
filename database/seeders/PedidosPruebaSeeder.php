<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Domain\Pedidos\RecalcularPedido;
use App\Enums\Rol;
use App\Enums\UnidadVenta;
use App\Models\Cliente;
use App\Models\Pedido;
use App\Models\Producto;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Seeder;

/**
 * 10 pedidos de PRUEBA, todos pendientes, con distintos productos (por
 * unidad y por caja); uno es fuera de ruta. Los precios salen del
 * catálogo, como en la preventa.
 *
 * Necesita clientes (ClientesPruebaSeeder), vendedores con bodega
 * (PersonalSeeder) y productos con precio (CatalogoSeeder).
 * Si se corre otra vez, cambia los pedidos de prueba por unos nuevos.
 * No corre en producción.
 */
class PedidosPruebaSeeder extends Seeder
{
    private const NOTA = 'PEDIDO DE PRUEBA';

    /**
     * [bodega del vendedor, bodega del cliente, días atrás, líneas].
     * Vendedor y cliente de bodegas distintas = fuera de ruta.
     *
     * @var list<array{string, string, int, int}>
     */
    private const PEDIDOS = [
        ['COP', 'COP', 0, 4],
        ['COP', 'COP', 0, 2],
        ['COP', 'COP', 1, 6],
        ['OCO', 'OCO', 0, 3],
        ['SB', 'SB', 1, 5],
        ['OCO', 'COP', 0, 3],
        ['COP', 'COP', 2, 5],
        ['OCO', 'OCO', 2, 4],
        ['SB', 'SB', 3, 3],
        ['COP', 'COP', 3, 2],
    ];

    public function run(): void
    {
        if (app()->environment('production')) {
            $this->command?->warn('PedidosPruebaSeeder no se corre en producción.');

            return;
        }

        $productos = Producto::query()->activos()->where('precio_unidad', '>', 0)->orderBy('id')->get();

        if ($productos->count() < 6) {
            $this->command?->warn('Se necesitan al menos 6 productos con precio: corra CatalogoSeeder.');

            return;
        }

        // Los de prueba anteriores se cambian por estos (las líneas se borran con el pedido).
        $anteriores = Pedido::query()->where('notas', 'like', self::NOTA.'%')->delete();

        $creados = 0;

        foreach (self::PEDIDOS as $n => [$bodegaVendedor, $bodegaCliente, $dias, $lineas]) {
            $vendedor = self::vendedorDe($bodegaVendedor, $n);
            $cliente = self::clienteDe($bodegaCliente, $n);

            if ($vendedor === null || $cliente === null) {
                $this->command?->warn("Pedido {$n}: falta vendedor de {$bodegaVendedor} o cliente de {$bodegaCliente}; se salta.");

                continue;
            }

            $fueraDeRuta = $bodegaVendedor !== $bodegaCliente;
            $cuando = $dias === 0
                ? now()->subMinutes(20 + $n * 35)
                : now()->subDays($dias)->setTime(8 + $n % 9, ($n * 7) % 60);

            $pedido = Pedido::query()->create([
                'bodega_id'         => $vendedor->bodega_id,
                'cliente_id'        => $cliente->id,
                'vendedor_id'       => $vendedor->id,
                'fecha'             => $cuando->toDateString(),
                'notas'             => $n % 3 === 0 ? self::NOTA.' · ENTREGAR ANTES DE LAS 10 AM' : self::NOTA,
                'fuera_de_ruta'     => $fueraDeRuta,
                'motivo_fuera_ruta' => $fueraDeRuta ? 'CUBRIENDO LA RUTA DE OTRO VENDEDOR' : null,
            ]);

            $pedido->forceFill(['created_at' => $cuando, 'updated_at' => $cuando])->saveQuietly();

            foreach (self::lineas($productos, $n, $lineas) as [$producto, $unidad, $cantidad]) {
                $pedido->items()->create(['producto_id' => $producto->id, 'unidad' => $unidad, 'cantidad' => $cantidad]);
            }

            app(RecalcularPedido::class)->ejecutar($pedido, preciosVigentes: true);

            $creados++;
        }

        $this->command?->info("✓ Pedidos de prueba: {$creados} pendientes".($anteriores > 0 ? " (se quitaron {$anteriores} de prueba anteriores)." : '.'));
    }

    private static function vendedorDe(string $bodega, int $n): ?User
    {
        $vendedores = User::query()
            ->where('is_active', true)
            ->whereHas('bodega', fn (Builder $query): Builder => $query->where('codigo', $bodega))
            ->whereHas('roles', fn (Builder $query): Builder => $query->where('name', Rol::Vendedor->value))
            ->orderBy('id')
            ->get();

        return $vendedores->isEmpty() ? null : $vendedores->get($n % $vendedores->count());
    }

    private static function clienteDe(string $bodega, int $n): ?Cliente
    {
        $clientes = Cliente::query()
            ->where('activo', true)
            ->whereNotNull('municipio_id')
            ->whereHas('bodega', fn (Builder $query): Builder => $query->where('codigo', $bodega))
            ->orderBy('id')
            ->get();

        return $clientes->isEmpty() ? null : $clientes->get(($n * 3) % $clientes->count());
    }

    /**
     * Productos distintos en cada pedido; algunas líneas por caja.
     *
     * @param Collection<int, Producto> $productos
     *
     * @return list<array{Producto, UnidadVenta, int}>
     */
    private static function lineas(Collection $productos, int $n, int $cuantas): array
    {
        $total = $productos->count();
        $elegidas = [];

        for ($j = 0; count($elegidas) < $cuantas && $j < $cuantas * 4; $j++) {
            $producto = $productos->get(($n * 11 + $j * 7) % $total);

            if ($producto === null || isset($elegidas[$producto->id])) {
                continue;
            }

            $porCaja = ($n + $j) % 3 === 0 && $producto->unidades_por_caja > 1;

            $elegidas[$producto->id] = [
                $producto,
                $porCaja ? UnidadVenta::Caja : UnidadVenta::Unidad,
                $porCaja ? 1 + ($n + $j) % 3 : 2 + ($n * 3 + $j * 5) % 11,
            ];
        }

        return array_values($elegidas);
    }
}
