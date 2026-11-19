<?php

declare(strict_types=1);

namespace Tests;

use Database\Seeders\RolesSistemaSeeder;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Testing\Attributes\Seeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Foundation\Testing\RefreshDatabaseState;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Los roles y permisos (RolesSistemaSeeder) se cargan UNA vez por corrida,
 * cuando RefreshDatabase deja la base nueva; cada prueba corre en una
 * transacción que se deshace, así que todas los encuentran sin volver a
 * sembrarlos (sembrarlos en cada prueba costaba medio segundo por prueba).
 */
#[Seeder(RolesSistemaSeeder::class)]
abstract class TestCase extends BaseTestCase
{
    /**
     * Candado: los tests SOLO pueden correr contra una DB cuyo nombre lleve "_test".
     *
     * Se valida aquí (antes de RefreshDatabase) para que un config:cache viejo
     * o un .env mal puesto nunca borre la DB local de desarrollo.
     */
    public function createApplication(): Application
    {
        $app = parent::createApplication();

        $connection = config()->string('database.default');
        $database = config()->string("database.connections.{$connection}.database");

        if (! str_contains($database, '_test')) {
            throw new RuntimeException(
                "Tests abortados: la DB [{$database}] no es de testing (falta \"_test\"). "
                .'Corre `php artisan config:clear` y revisa phpunit.xml.'
            );
        }

        return $app;
    }

    /**
     * Antes del migrate:fresh de RefreshDatabase (una vez por proceso) borra
     * las tablas de la base de pruebas una por una.
     *
     * migrate:fresh las borra todas en una sola sentencia, que toma unos mil
     * bloqueos; con --parallel son 14 bases borrándose a la vez y Postgres se
     * queda sin espacio para tantos ("out of shared memory", la pista habla
     * de max_locks_per_transaction). Una por una no pasan de unos 150 cada
     * vez, y migrate:fresh ya no encuentra nada que borrar.
     *
     * @return array<mixed>
     */
    protected function setUpTraits(): array
    {
        if (isset(class_uses_recursive(static::class)[RefreshDatabase::class]) && ! RefreshDatabaseState::$migrated) {
            $this->borrarTablasUnaPorUna();
        }

        return parent::setUpTraits();
    }

    private function borrarTablasUnaPorUna(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        foreach (DB::select('SELECT tablename FROM pg_tables WHERE schemaname = current_schema()') as $fila) {
            if (is_object($fila) && isset($fila->tablename) && is_string($fila->tablename)) {
                DB::statement('DROP TABLE IF EXISTS "'.str_replace('"', '""', $fila->tablename).'" CASCADE');
            }
        }
    }
}
