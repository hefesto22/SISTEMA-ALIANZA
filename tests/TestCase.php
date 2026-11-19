<?php

declare(strict_types=1);

namespace Tests;

use Database\Seeders\RolesSistemaSeeder;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Testing\Attributes\Seeder;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
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
}
