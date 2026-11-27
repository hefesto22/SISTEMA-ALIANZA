<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Los correos de los usuarios quedan en minúsculas: al entrar se buscan
     * así, sin importar cómo se escriban (el teclado del celular pone la
     * primera en mayúscula).
     */
    public function up(): void
    {
        DB::table('users')->whereRaw('email <> LOWER(email)')->update(['email' => DB::raw('LOWER(email)')]);
    }

    public function down(): void
    {
        // No se puede saber cómo estaban escritos.
    }
};
