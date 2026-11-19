<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Domain\Exceptions\ReglaNegocioException;
use App\Domain\Pedidos\BorrarPedidos;
use App\Models\Pedido;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Builder;

/**
 * Borra pedidos de prueba con sus facturas (del CAI de prueba) y regresa a
 * la bodega lo que salió con ellos (App\Domain\Pedidos\BorrarPedidos).
 * Enseña qué va a borrar y pregunta antes.
 *
 *   php artisan pedidos:borrar                          todos los pedidos
 *   php artisan pedidos:borrar COP-000003 COP-000004    solo esos (o sus ids)
 */
class BorrarPedidosDePrueba extends Command
{
    protected $signature = 'pedidos:borrar
                            {pedidos?* : Números (COP-000003) o ids de los pedidos; sin nada son todos}
                            {--force : Borra sin preguntar (en producción es obligatorio)}';

    protected $description = 'Borra pedidos de prueba con sus facturas de prueba y regresa a la bodega lo que salió con ellos.';

    public function handle(BorrarPedidos $borrar): int
    {
        if (app()->isProduction() && ! $this->option('force')) {
            $this->error('Esto es producción: los pedidos de verdad se anulan o se corrigen. Si de verdad hay que borrarlos, agregue --force.');

            return self::FAILURE;
        }

        /** @var list<string> $buscados */
        $buscados = array_values(array_map(strval(...), (array) $this->argument('pedidos')));
        $ids = $this->ids($buscados);

        if ($ids === null) {
            return self::FAILURE;
        }

        if ($ids === []) {
            $this->info('No hay pedidos que borrar.');

            return self::SUCCESS;
        }

        $resumen = $borrar->resumen($ids);

        $this->table(['Estado', 'Pedidos'], array_map(
            fn (string $estado, int $cuantos): array => [$estado, $cuantos],
            array_keys($resumen['pedidos']),
            array_values($resumen['pedidos']),
        ));
        $this->line(match (count($resumen['facturas'])) {
            0       => 'Sin facturas.',
            1       => 'Factura: '.$resumen['facturas'][0],
            default => count($resumen['facturas']).' facturas: '.implode(', ', $resumen['facturas']),
        });
        $this->line($resumen['movimientos'] === 0
            ? 'No sacaron nada de la bodega.'
            : self::cuantos($resumen['movimientos'], 'renglón', 'renglones').' del kárdex: lo que salió regresa a la bodega.');

        $impedimento = $borrar->impedimento($ids);

        if ($impedimento !== null) {
            $this->error($impedimento);

            return self::FAILURE;
        }

        $cuantos = count($ids) === 1 ? 'el pedido' : 'los '.count($ids).' pedidos';

        if (! $this->option('force') && ! $this->confirm("¿Borrar {$cuantos}? No se puede deshacer.", false)) {
            $this->info('No se borró nada.');

            return self::SUCCESS;
        }

        try {
            $hecho = $borrar->ejecutar($ids);
        } catch (ReglaNegocioException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        $this->info(sprintf(
            'Listo. Borrados: %s, %s y %s del kárdex.',
            self::cuantos($hecho['pedidos'], 'pedido', 'pedidos'),
            self::cuantos($hecho['facturas'], 'factura', 'facturas'),
            self::cuantos($hecho['movimientos'], 'renglón', 'renglones'),
        ));
        $this->line('La existencia quedó como si esos pedidos nunca se hubieran despachado. Los clientes no se tocaron.');

        if (! Pedido::query()->exists()) {
            $this->line('Ya no hay pedidos: el próximo vuelve a ser el 000001.');
        }

        return self::SUCCESS;
    }

    private static function cuantos(int $cuantos, string $uno, string $varios): string
    {
        return $cuantos === 1 ? "1 {$uno}" : "{$cuantos} {$varios}";
    }

    /**
     * Los ids de los pedidos buscados (todos si no se dijo ninguno); null si
     * alguno no existe.
     *
     * @param list<string> $buscados
     *
     * @return list<int>|null
     */
    private function ids(array $buscados): ?array
    {
        if ($buscados === []) {
            return array_values(Pedido::query()->orderBy('id')->pluck('id')->map(fn (mixed $id): int => (int) $id)->all());
        }

        $claves = array_map(fn (string $buscado): string => mb_strtoupper(trim($buscado)), $buscados);
        $numericos = array_values(array_map(intval(...), array_filter($claves, ctype_digit(...))));

        $pedidos = Pedido::query()
            ->where(fn (Builder $q): Builder => $q->whereIn('numero', $claves)->orWhereIn('id', $numericos))
            ->orderBy('id')
            ->get(['id', 'numero']);

        $faltan = array_filter($claves, fn (string $clave): bool => ! $pedidos->contains(
            fn (Pedido $pedido): bool => $pedido->numero === $clave || (string) $pedido->id === $clave,
        ));

        if ($faltan !== []) {
            $this->error('No se encontraron: '.implode(', ', $faltan).'.');

            return null;
        }

        return array_values($pedidos->pluck('id')->map(fn (mixed $id): int => (int) $id)->all());
    }
}
