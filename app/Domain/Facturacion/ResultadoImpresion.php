<?php

declare(strict_types=1);

namespace App\Domain\Facturacion;

use App\Models\Pedido;
use Illuminate\Support\Facades\URL;

/**
 * Lo que salió de ImprimirFacturas: qué pedidos imprimir (en orden), cuáles
 * van como ORIGINAL, las facturas y lo que se saltó con el motivo.
 */
final class ResultadoImpresion
{
    /** Minutos que sirve el enlace firmado de la hoja. */
    public const MINUTOS = 10;

    /** @var list<int> */
    public array $pedidos = [];

    /** @var list<int> */
    public array $originales = [];

    /** @var list<string> */
    public array $facturas = [];

    /** @var list<string> */
    public array $saltados = [];

    public function imprimir(Pedido $pedido, bool $original): void
    {
        $this->pedidos[] = $pedido->id;

        if ($original) {
            $this->originales[] = $pedido->id;
        }

        if ($pedido->factura !== null) {
            $this->facturas[] = $pedido->factura->numero;
        }
    }

    public function saltar(Pedido $pedido, string $motivo): void
    {
        $this->saltados[] = "{$pedido->numero}: {$motivo}";
    }

    public function hayQueImprimir(): bool
    {
        return $this->pedidos !== [];
    }

    /**
     * Enlace firmado a la hoja: solo con la firma salen como ORIGINAL; sin
     * ella (vista previa, enlace copiado) la factura sale como COPIA.
     */
    public function url(): string
    {
        return URL::temporarySignedRoute('pedidos.imprimir-lote', now()->addMinutes(self::MINUTOS), [
            'ids'        => implode(',', $this->pedidos),
            'originales' => implode(',', $this->originales),
        ]);
    }
}
