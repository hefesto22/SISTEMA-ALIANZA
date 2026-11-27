<?php

declare(strict_types=1);

namespace App\Domain\Pedidos;

use App\Models\Pedido;

/**
 * Lo que salió de ValidarVarios: los que quedaron Por imprimir, cuáles de
 * esos no van completos (salió lo que había) y los que siguen pendientes,
 * con el porqué.
 */
final readonly class PedidosValidados
{
    /**
     * @param list<Pedido> $validados en el orden en que se validaron
     * @param list<string> $incompletos números de los validados con lo que había
     * @param array<string, string> $pendientes [número => por qué no se validó]
     */
    public function __construct(
        public array $validados,
        public array $incompletos,
        public array $pendientes,
    ) {}

    /**
     * @return list<int>
     */
    public function ids(): array
    {
        return array_map(fn (Pedido $pedido): int => $pedido->id, $this->validados);
    }

    /** Lo que suman los validados (con ISV), ya con lo que de verdad salió. */
    public function total(): float
    {
        return round(array_sum(array_map(fn (Pedido $pedido): float => $pedido->total, $this->validados)), 2);
    }
}
