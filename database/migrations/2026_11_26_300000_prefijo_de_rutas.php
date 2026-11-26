<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

return new class extends Migration
{
    /**
     * Prefijo de las rutas de cada bodega: con el número arma el código de
     * la ruta que sale en la factura (CO1, CO2… en Copán; OC1… en
     * Ocotepeque). Único entre bodegas. A las que ya existen se les ponen las
     * dos primeras letras del código (COP → CO); si ya las tiene otra, tres
     * o cuatro.
     */
    public function up(): void
    {
        Schema::table('bodegas', function (Blueprint $table): void {
            $table->string('prefijo_ruta', 4)->nullable()->unique()->after('codigo');
        });

        $usados = [];

        foreach (DB::table('bodegas')->orderBy('id')->get(['id', 'codigo']) as $bodega) {
            $letras = (string) preg_replace('/[^A-Z]/', '', mb_strtoupper(Str::ascii((string) $bodega->codigo)));

            foreach ([2, 3, 4] as $largo) {
                $prefijo = mb_substr($letras, 0, $largo);

                if ($prefijo !== '' && ! isset($usados[$prefijo])) {
                    $usados[$prefijo] = true;
                    DB::table('bodegas')->where('id', $bodega->id)->update(['prefijo_ruta' => $prefijo]);

                    break;
                }
            }
        }
    }

    public function down(): void
    {
        Schema::table('bodegas', function (Blueprint $table): void {
            $table->dropUnique(['prefijo_ruta']);
            $table->dropColumn('prefijo_ruta');
        });
    }
};
