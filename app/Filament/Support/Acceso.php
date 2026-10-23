<?php

declare(strict_types=1);

namespace App\Filament\Support;

use App\Models\User;
use Illuminate\Support\Facades\Auth;

/**
 * Atajos para saber quién está usando el panel (usuario, si ve todas las
 * bodegas, si es vendedor). Evita repetir Auth::user() + chequeos en cada pantalla.
 */
final class Acceso
{
    public static function usuario(): ?User
    {
        $usuario = Auth::user();

        return $usuario instanceof User ? $usuario : null;
    }

    public static function veTodo(): bool
    {
        return self::usuario()?->veTodasLasBodegas() ?? false;
    }

    public static function esVendedor(): bool
    {
        return self::usuario()?->esVendedor() ?? false;
    }

    public static function puede(string $permiso): bool
    {
        return self::usuario()?->can($permiso) ?? false;
    }
}
