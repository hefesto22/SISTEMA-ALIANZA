<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\Bodega;
use App\Models\Cliente;
use App\Models\Municipio;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Seeder;

/**
 * Clientes de PRUEBA repartidos en los municipios de cada bodega, para
 * probar rutas, preventa y pedidos fuera de ruta. No corre en producción.
 *
 * Necesita DivisionPoliticaSeeder y BodegaSeeder antes. Se puede correr
 * las veces que sea: no repite clientes (mismo nombre en el mismo municipio).
 *
 * Trae un caso de sucursal (mismo nombre y RTN en otro municipio) y
 * algunos clientes inactivos.
 */
class ClientesPruebaSeeder extends Seeder
{
    /**
     * Bodega => [código de departamento, municipios]. Cada municipio lleva
     * 2 clientes (si se repite, lleva 2 más).
     *
     * @var array<string, array{string, list<string>}>
     */
    private const ZONAS = [
        'COP' => ['04', [
            'SANTA ROSA DE COPÁN', 'SANTA ROSA DE COPÁN', 'SANTA ROSA DE COPÁN', 'DOLORES', 'SAN JUAN DE OPOA',
            'CUCUYAGUA', 'CORQUÍN', 'NUEVA ARCADIA', 'COPÁN RUINAS', 'TRINIDAD DE COPÁN', 'SAN PEDRO', 'VERACRUZ',
        ]],
        'OCO' => ['14', [
            'OCOTEPEQUE', 'OCOTEPEQUE', 'SINUAPA', 'SAN MARCOS', 'SENSENTI', 'LA LABOR', 'SAN FRANCISCO DEL VALLE', 'CONCEPCIÓN',
        ]],
        'LUC' => ['14', [
            'LUCERNA', 'LUCERNA', 'MERCEDES', 'FRATERNIDAD', 'BELÉN GUALCHO', 'LA ENCARNACIÓN',
        ]],
        'SB' => ['16', [
            'SANTA BÁRBARA', 'SANTA BÁRBARA', 'ILAMA', 'TRINIDAD', 'QUIMISTÁN', 'LAS VEGAS', 'MACUELIZO', 'SAN NICOLÁS',
        ]],
    ];

    private const NEGOCIOS = [
        'PULPERÍA', 'MINI SÚPER', 'ABARROTERÍA', 'SURTIDORA', 'COMERCIAL',
        'TIENDA', 'VARIEDADES', 'BODEGUITA', 'DESPENSA', 'MERCADITO',
    ];

    private const NOMBRES = [
        'LA BENDICIÓN', 'EL ÉXITO', 'DOÑA MARTA', 'SAN JOSÉ', 'LOS PINOS', 'EL BUEN PRECIO',
        'LA ECONOMÍA', 'DIVINO NIÑO', 'EL AHORRO', 'LA ESPERANZA', 'DON CHEPE', 'LAS DELICIAS',
        'EL CAMINANTE', 'LA FE', 'EL PARAÍSO', 'LOS ÁNGELES', 'MARY', 'LA PREFERIDA',
        'EL TRIUNFO', 'GÉNESIS', 'EMANUEL', 'JIREH', 'LA CEIBITA', 'DON TOÑO',
    ];

    private const DIRECCIONES = [
        'BARRIO EL CENTRO, FRENTE AL PARQUE',
        'BARRIO MERCEDES, MEDIA CUADRA AL SUR DE LA IGLESIA',
        'COLONIA LAS FLORES, CALLE PRINCIPAL',
        'BARRIO SAN MARTÍN, CONTIGUO A LA ESCUELA',
        'SALIDA PRINCIPAL, FRENTE A LA GASOLINERA',
        'MERCADO MUNICIPAL, LOCAL 8',
        'BARRIO EL CALVARIO, ESQUINA OPUESTA A LA FARMACIA',
        'COLONIA SANTA TERESA, 2 CUADRAS AL NORTE DEL KÍNDER',
        'ALDEA EL ROSARIO, ENTRADA PRINCIPAL',
        'BARRIO LAS PALMAS, A LA PAR DEL CENTRO DE SALUD',
        'COLONIA EL BOSQUE, PASAJE 3',
        'BARRIO EL CARMEN, FRENTE A LA CANCHA',
    ];

    public function run(): void
    {
        if (app()->environment('production')) {
            $this->command?->warn('ClientesPruebaSeeder no se corre en producción.');

            return;
        }

        $nuevos = 0;
        $i = 0;

        foreach (self::ZONAS as $codigoBodega => [$departamento, $municipios]) {
            $bodega = Bodega::query()->where('codigo', $codigoBodega)->first();

            if ($bodega === null) {
                $this->command?->warn("No existe la bodega {$codigoBodega}: corra BodegaSeeder.");

                continue;
            }

            foreach ($municipios as $nombreMunicipio) {
                $municipio = self::municipio($departamento, $nombreMunicipio);

                if ($municipio === null) {
                    $this->command?->warn("No existe el municipio {$nombreMunicipio}: corra DivisionPoliticaSeeder.");

                    continue;
                }

                for ($n = 0; $n < 2; $n++, $i++) {
                    $cliente = Cliente::query()->firstOrCreate(
                        ['nombre' => self::NEGOCIOS[$i % 10].' '.self::NOMBRES[$i % 24], 'municipio_id' => $municipio->id],
                        [
                            'rtn'       => $i % 3 === 0 ? sprintf('%s%02d%04d%06d', $departamento, 1 + $i % 20, 1960 + $i % 45, 1000 + $i) : null,
                            'telefono'  => sprintf('%s%07d', $i % 2 === 0 ? '9' : '3', 4410000 + $i * 1373),
                            'direccion' => self::DIRECCIONES[$i % 12],
                            'bodega_id' => $bodega->id,
                            'activo'    => $i % 17 !== 16,
                        ],
                    );

                    $nuevos += $cliente->wasRecentlyCreated ? 1 : 0;
                }
            }
        }

        $nuevos += $this->sucursal();

        $this->command?->info("✓ Clientes de prueba: {$nuevos} nuevos (".Cliente::query()->count().' en total).');
    }

    /**
     * Mismo nombre y RTN que un cliente de Santa Rosa, pero en Dolores:
     * se permite porque es otro municipio.
     */
    private function sucursal(): int
    {
        $matriz = Cliente::query()->where('nombre', 'PULPERÍA LA BENDICIÓN')->whereNotNull('rtn')->first();
        $dolores = self::municipio('04', 'DOLORES');

        if ($matriz === null || $dolores === null || $matriz->municipio_id === $dolores->id) {
            return 0;
        }

        $sucursal = Cliente::query()->firstOrCreate(
            ['nombre' => $matriz->nombre, 'municipio_id' => $dolores->id],
            [
                'rtn'       => $matriz->rtn,
                'telefono'  => '99112233',
                'direccion' => 'BARRIO EL CENTRO, SUCURSAL',
                'bodega_id' => $matriz->bodega_id,
                'activo'    => true,
            ],
        );

        return $sucursal->wasRecentlyCreated ? 1 : 0;
    }

    private static function municipio(string $departamento, string $nombre): ?Municipio
    {
        return Municipio::query()
            ->where('nombre', $nombre)
            ->whereHas('departamento', fn (Builder $query): Builder => $query->where('codigo', $departamento))
            ->first();
    }
}
