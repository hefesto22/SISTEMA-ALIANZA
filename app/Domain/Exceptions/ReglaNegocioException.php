<?php

declare(strict_types=1);

namespace App\Domain\Exceptions;

/**
 * Operación que el negocio no permite (validar un pedido ya despachado,
 * margen de 100% o más, costo negativo…). El mensaje va en español y
 * se le puede mostrar tal cual al usuario.
 */
final class ReglaNegocioException extends GrupoOlympoException {}
