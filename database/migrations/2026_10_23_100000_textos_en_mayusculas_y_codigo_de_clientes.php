<?php

declare(strict_types=1);

use App\Models\Cliente;
use App\Support\Texto;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Tablas y campos que se guardan en MAYÚSCULAS (los mismos que cada
     * modelo declara en camposEnMayusculas()).
     *
     * @var array<string, list<string>>
     */
    private const CAMPOS = [
        'departamentos'  => ['nombre'],
        'municipios'     => ['nombre'],
        'bodegas'        => ['codigo', 'nombre', 'direccion'],
        'rutas'          => ['nombre'],
        'clientes'       => ['nombre', 'direccion'],
        'proveedores'    => ['nombre', 'nombre_corto', 'razon_social', 'direccion'],
        'categorias'     => ['nombre'],
        'productos'      => ['descripcion'],
        'cambios_precio' => ['nota'],
        'promociones'    => ['nombre', 'descripcion'],
        'pedidos'        => ['notas', 'motivo_fuera_ruta'],
        'users'          => ['name'],
    ];

    /**
     * Pasa a MAYÚSCULAS lo que ya estaba guardado y le pone código
     * (CLI-000001) a los clientes que no tienen.
     */
    public function up(): void
    {
        foreach (self::CAMPOS as $tabla => $campos) {
            DB::table($tabla)
                ->select(['id', ...$campos])
                ->chunkById(500, function (Collection $filas) use ($tabla, $campos): void {
                    foreach ($filas as $fila) {
                        $fila = (array) $fila;
                        $cambios = [];

                        foreach ($campos as $campo) {
                            $valor = $fila[$campo] ?? null;

                            if (is_string($valor) && $valor !== Texto::mayusculas($valor)) {
                                $cambios[$campo] = Texto::mayusculas($valor);
                            }
                        }

                        if ($cambios !== []) {
                            DB::table($tabla)->where('id', $fila['id'])->update($cambios);
                        }
                    }
                });
        }

        DB::table('clientes')
            ->whereNull('codigo')
            ->select(['id'])
            ->chunkById(500, function (Collection $filas): void {
                foreach ($filas as $fila) {
                    $id = (int) ((array) $fila)['id'];

                    DB::table('clientes')->where('id', $id)->update(['codigo' => Cliente::codigoPara($id)]);
                }
            });
    }

    /**
     * No hay vuelta atrás: no se sabe cómo estaba escrito cada texto.
     */
    public function down(): void {}
};
