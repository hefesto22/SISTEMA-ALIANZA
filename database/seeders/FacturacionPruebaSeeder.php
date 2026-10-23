<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\Cai;
use App\Models\DatosFiscales;
use Illuminate\Database\Seeder;

/**
 * SOLO PARA PROBAR EN LOCAL: datos fiscales de ejemplo (si faltan) y un CAI
 * global de prueba, para poder imprimir facturas antes de tener el CAI
 * real. En producción se cargan los del SAR en Administración > CAI.
 *
 *   php artisan db:seed --class=FacturacionPruebaSeeder
 */
class FacturacionPruebaSeeder extends Seeder
{
    public const CAI_PRUEBA = '000000-000000-000000-000000-000000-00';

    public function run(): void
    {
        $fiscales = DatosFiscales::actual();

        if (! $fiscales->estanCompletos()) {
            $fiscales->update([
                'razon_social'     => $fiscales->razon_social ?: 'DISTRIBUIDORA ALIANZA HOZANA (PRUEBA)',
                'nombre_comercial' => $fiscales->nombre_comercial ?: (string) config('app.name'),
                'rtn'              => $fiscales->rtn ?: '00000000000000',
                'direccion'        => $fiscales->direccion ?: 'SANTA ROSA DE COPÁN, COPÁN',
            ]);
        }

        Cai::query()->firstOrCreate(['cai' => self::CAI_PRUEBA], [
            'establecimiento' => '000',
            'punto_emision'   => '999',
            'tipo_documento'  => Cai::FACTURA,
            'rango_desde'     => 1,
            'rango_hasta'     => 5000,
            'fecha_limite'    => today()->addYear(),
            'activo'          => true,
            'notas'           => 'CAI DE PRUEBA: CAMBIAR POR EL DEL SAR',
        ]);

        $this->command?->warn('CAI de prueba listo (global). Cámbielo por el del SAR antes de facturar de verdad.');
    }
}
