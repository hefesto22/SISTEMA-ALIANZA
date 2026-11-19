<?php

declare(strict_types=1);

use App\Domain\Precios\UnificarCostos;
use Illuminate\Database\Migrations\Migration;

/**
 * El costo pasa a ser uno por unidad del producto: cada presentación
 * cuesta ese × lo que trae (App\Domain\Precios\UnificarCostos). Los precios
 * "Con %" se recalculan y quedan en el historial como "Costo por unidad".
 * En una base nueva no hay productos y no hace nada.
 */
return new class extends Migration
{
    public function up(): void
    {
        app(UnificarCostos::class)->ejecutar();
    }

    public function down(): void
    {
        // Los costos por presentación de antes quedan en el historial de precios.
    }
};
