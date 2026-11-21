<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\Cai;
use App\Models\DatosFiscales;
use Illuminate\Database\Seeder;

/**
 * SOLO PARA PROBAR EN LOCAL: datos fiscales de ejemplo (si faltan) y CAI
 * globales de prueba, uno de facturas y otro de notas de crédito, para poder
 * imprimir antes de tener los reales. En producción se cargan los del SAR en
 * Administración > CAI.
 *
 *   php artisan db:seed --class=FacturacionPruebaSeeder
 */
class FacturacionPruebaSeeder extends Seeder
{
    public const CAI_PRUEBA = Cai::PRUEBA;

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

        Cai::query()->firstOrCreate(['cai' => Cai::PRUEBA_NOTAS], [
            'establecimiento' => '000',
            'punto_emision'   => '999',
            'tipo_documento'  => Cai::NOTA_CREDITO,
            'rango_desde'     => 1,
            'rango_hasta'     => 5000,
            'fecha_limite'    => today()->addYear(),
            'activo'          => true,
            'notas'           => 'CAI DE PRUEBA DE NOTAS DE CRÉDITO: CAMBIAR POR EL DEL SAR',
        ]);

        $this->command?->warn('CAI de prueba listos (globales, de facturas y de notas de crédito). Cámbielos por los del SAR antes de facturar de verdad.');
    }
}
