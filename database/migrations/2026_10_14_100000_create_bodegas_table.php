<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('bodegas', function (Blueprint $table): void {
            $table->id();
            $table->string('codigo', 10)->unique();
            $table->string('nombre');
            $table->string('direccion')->nullable();
            $table->string('telefono', 30)->nullable();
            $table->boolean('activa')->default(true);
            $table->timestamps();
        });

        // Cada usuario (vendedor o encargado) pertenece a una bodega.
        // El código (HO01, HO02…) es el mismo del sistema actual y sirve
        // para entrar al panel sin escribir el correo.
        Schema::table('users', function (Blueprint $table): void {
            $table->foreignId('bodega_id')->nullable()->after('phone')->constrained('bodegas')->nullOnDelete();
            $table->string('codigo', 20)->nullable()->unique()->after('bodega_id');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('bodega_id');
            $table->dropUnique(['codigo']);
            $table->dropColumn('codigo');
        });

        Schema::dropIfExists('bodegas');
    }
};
