<?php

declare(strict_types=1);

namespace App\Console\Commands;

use Carbon\CarbonImmutable;
use Database\Seeders\Demo\HistorialDemo;
use Database\Seeders\Demo\LimpiarMovimientos;
use Database\Seeders\FacturacionPruebaSeeder;
use Illuminate\Console\Command;

/**
 * Llena la base LOCAL con la operación de demostración del día 1 de hace dos
 * meses hasta hoy (Database\Seeders\Demo\HistorialDemo), para ver reportes,
 * KPIs, metas y comisiones con datos. Antes borra los pedidos, compras,
 * kárdex y cierres de comisiones que haya (pregunta). En producción no corre.
 *
 *   php artisan demo:historial
 */
class GenerarHistorialDemo extends Command
{
    protected $signature = 'demo:historial
                            {--force : No pregunta antes de borrar}';

    protected $description = 'Genera dos meses de operación de demostración (compras, ventas, devoluciones y comisiones) en la base local.';

    public function handle(LimpiarMovimientos $limpiar, HistorialDemo $demo): int
    {
        if (app()->isProduction()) {
            $this->error('Esto es producción: la operación de demostración solo se genera en una base local.');

            return self::FAILURE;
        }

        $impedimento = $limpiar->impedimento();

        if ($impedimento !== null) {
            $this->error($impedimento);

            return self::FAILURE;
        }

        $desde = CarbonImmutable::today()->subMonthsNoOverflow(2)->startOfMonth();
        $hasta = CarbonImmutable::today();

        $this->info("Operación de demostración del {$desde->format('d/m/Y')} al {$hasta->format('d/m/Y')}: inventario inicial, compras semanales, pedidos de lunes a sábado (los de hoy quedan pendientes), devoluciones y cierres de comisiones.");

        $hay = array_filter($limpiar->resumen());

        if ($hay !== []) {
            $this->warn('Antes se borra lo que ya hay (el catálogo, los clientes, los usuarios y la configuración se quedan):');
            $this->table(['Qué', 'Cuántos'], array_map(fn (string $que, int $cuantos): array => [$que, number_format($cuantos)], array_keys($hay), array_values($hay)));
        }

        if (! $this->option('force') && ! $this->confirm('¿Seguir?', true)) {
            $this->info('No se hizo nada.');

            return self::SUCCESS;
        }

        // Datos fiscales de ejemplo y el CAI de prueba, si faltan.
        app(FacturacionPruebaSeeder::class)->run();

        $limpiar->ejecutar();
        $inicio = microtime(true);

        $demo->generar($desde, $hasta, avance: function (string $linea): void {
            $this->line('  '.$linea);
        });

        $this->newLine();
        $this->table(
            ['Mes', 'Pedidos', 'Facturas', 'Venta sin ISV', 'Venta con ISV', 'Compras con ISV', 'Devoluciones'],
            array_map(fn (array $fila): array => [
                $fila['mes'],
                number_format($fila['pedidos']),
                number_format($fila['facturas']),
                'L '.number_format($fila['venta'], 2),
                'L '.number_format($fila['con_isv'], 2),
                'L '.number_format($fila['compras'], 2),
                number_format($fila['devoluciones']),
            ], HistorialDemo::resumen($desde, $hasta)),
        );

        $this->info('Listo en '.number_format(microtime(true) - $inicio, 0).' s. Los vendedores entran con su código (HO01…).');

        return self::SUCCESS;
    }
}
