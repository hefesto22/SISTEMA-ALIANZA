<?php

declare(strict_types=1);

namespace App\Support;

use Illuminate\Contracts\Cache\Lock;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Candados en Redis (caché de la app) por pedido mientras se imprime: si
 * otra persona, en esta u otra bodega, está imprimiendo el mismo pedido, ese
 * pedido se salta (o se avisa, si era el único) en lugar de imprimirlo dos
 * veces.
 *
 * Los números de factura NO dependen de esto: los protege el bloqueo de la
 * base de datos (CAI y pedido) y el "único" de la tabla. Si Redis no
 * responde, se sigue solo con el bloqueo de la base de datos.
 */
final class CandadosDeImpresion
{
    private const SEGUNDOS = 120;

    /** @var list<int> Los que otra persona está imprimiendo en este momento. */
    public array $ocupados = [];

    /** @param list<Lock> $tomados */
    private function __construct(private array $tomados) {}

    /**
     * @param list<int> $pedidoIds
     */
    public static function tomar(array $pedidoIds): self
    {
        $candados = new self([]);

        try {
            foreach ($pedidoIds as $id) {
                $candado = Cache::lock("imprimir:pedido:{$id}", self::SEGUNDOS);

                if ($candado->get()) {
                    $candados->tomados[] = $candado;
                } else {
                    $candados->ocupados[] = $id;
                }
            }
        } catch (Throwable $e) {
            // Redis caído o sin conexión: los números siguen protegidos por la base de datos.
            Log::warning('Candados de impresión sin Redis; se sigue con el bloqueo de la base de datos.', ['error' => $e->getMessage()]);
            $candados->soltar();

            return new self([]);
        }

        return $candados;
    }

    public function soltar(): void
    {
        foreach ($this->tomados as $candado) {
            try {
                $candado->release();
            } catch (Throwable) {
                // Vence solo a los SEGUNDOS.
            }
        }

        $this->tomados = [];
    }
}
