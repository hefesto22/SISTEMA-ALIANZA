<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * % de utilidad propio de cada presentación (0.10 = 10 %): la caja puede
     * dejar menos que la unidad. Vacío = el del producto (o el de su
     * categoría). Con él se calcula el precio de las que no son fijas.
     */
    public function up(): void
    {
        Schema::table('presentaciones', function (Blueprint $table): void {
            $table->decimal('margen', 6, 4)->nullable()->after('precio');
        });
    }

    public function down(): void
    {
        Schema::table('presentaciones', function (Blueprint $table): void {
            $table->dropColumn('margen');
        });
    }
};
