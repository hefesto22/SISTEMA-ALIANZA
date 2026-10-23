<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Departamentos y municipios de Honduras (los llena DivisionPoliticaSeeder).
     */
    public function up(): void
    {
        Schema::create('departamentos', function (Blueprint $table): void {
            $table->id();
            // Código oficial: 01 Atlántida … 18 Yoro.
            $table->string('codigo', 2)->nullable()->unique();
            $table->string('nombre', 60)->unique();
        });

        Schema::create('municipios', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('departamento_id')->constrained('departamentos')->restrictOnDelete();
            $table->string('nombre', 80);

            $table->unique(['departamento_id', 'nombre']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('municipios');
        Schema::dropIfExists('departamentos');
    }
};
