<?php

declare(strict_types=1);

namespace App\Models\Concerns;

use App\Support\Texto;

/**
 * Guarda en MAYÚSCULAS los campos de texto del modelo, sin importar
 * cómo se escribieron (formulario, crear desde el pedido, seeders…).
 *
 * Cada modelo dice cuáles con camposEnMayusculas().
 */
trait GuardaEnMayusculas
{
    /**
     * @return list<string>
     */
    abstract protected function camposEnMayusculas(): array;

    public static function bootGuardaEnMayusculas(): void
    {
        static::saving(function (self $model): void {
            foreach ($model->camposEnMayusculas() as $campo) {
                $valor = $model->getAttribute($campo);

                if (is_string($valor)) {
                    $model->setAttribute($campo, Texto::mayusculas($valor));
                }
            }
        });
    }
}
