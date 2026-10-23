<?php

declare(strict_types=1);

namespace App\Domain\Precios;

use App\Enums\MotivoCambioPrecio;
use App\Models\Categoria;
use App\Models\Producto;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Cambia el % de utilidad de una categoría y recalcula el precio de todos
 * sus productos (con su costo actual), dejando cada cambio en el historial.
 *
 * Los productos con margen propio no se tocan: su margen manda.
 */
final class CambiarMargenCategoria
{
    public function __construct(private readonly ActualizarPrecioProducto $actualizarPrecio) {}

    /**
     * @return int Cantidad de productos cuyo precio cambió
     */
    public function ejecutar(Categoria $categoria, float $margenNuevo, ?User $usuario = null): int
    {
        // Valida el margen antes de tocar nada (lanza si es >= 100% o negativo).
        CalculadoraPrecio::precioVenta(1.0, $margenNuevo);

        $margenAnterior = $categoria->margen;
        $nota = sprintf(
            'Margen de %s: %s%% → %s%%',
            $categoria->nombre,
            rtrim(rtrim(number_format($margenAnterior * 100, 2), '0'), '.'),
            rtrim(rtrim(number_format($margenNuevo * 100, 2), '0'), '.'),
        );

        return DB::transaction(function () use ($categoria, $margenNuevo, $usuario, $nota): int {
            $categoria->forceFill(['margen' => $margenNuevo])->save();

            $cambiados = 0;

            $categoria->productos()
                ->whereNull('margen')
                ->with('categoria')
                ->lazyById()
                ->each(function (Producto $producto) use ($usuario, $nota, &$cambiados): void {
                    $cambio = $this->actualizarPrecio->ejecutar(
                        $producto,
                        $producto->costo_unidad,
                        MotivoCambioPrecio::Margen,
                        $usuario,
                        nota: $nota,
                    );

                    if ($cambio !== null) {
                        $cambiados++;
                    }
                });

            return $cambiados;
        });
    }
}
