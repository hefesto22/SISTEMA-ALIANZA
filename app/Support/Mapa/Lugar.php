<?php

declare(strict_types=1);

namespace App\Support\Mapa;

/**
 * Lo que hay en un punto del mapa (BuscarLugar).
 *
 * - departamentoId / municipioId: del catálogo; null si no se reconoció.
 * - referencia: calle, barrio, aldea… en MAYÚSCULAS, para "Dirección / referencia".
 * - descripcion: para los avisos ("SANTA ROSA DE COPÁN · COPÁN").
 */
final readonly class Lugar
{
    public function __construct(
        public ?int $departamentoId,
        public ?int $municipioId,
        public string $referencia,
        public string $descripcion,
        public bool $enHonduras,
    ) {}
}
