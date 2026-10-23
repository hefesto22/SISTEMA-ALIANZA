<?php

declare(strict_types=1);

namespace App\Filament\Pages\Auth;

use Filament\Auth\Pages\Login as BaseLogin;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Component;
use Illuminate\Validation\ValidationException;
use SensitiveParameter;

/**
 * Login que acepta el correo O el código de usuario (HO01, HO24…),
 * para que los vendedores entren desde el celular sin escribir un correo.
 */
class Login extends BaseLogin
{
    protected function getEmailFormComponent(): Component
    {
        return TextInput::make('email')
            ->label('Correo o código de usuario')
            ->placeholder('admin@correo.com o HO01')
            ->required()
            ->autocomplete('username')
            ->autofocus();
    }

    /**
     * @param array<string, mixed> $data
     *
     * @return array<string, mixed>
     */
    protected function getCredentialsFromFormData(#[SensitiveParameter] array $data): array
    {
        $usuario = trim((string) $data['email']);

        $campo = str_contains($usuario, '@') ? 'email' : 'codigo';

        return [
            $campo     => $campo === 'codigo' ? mb_strtoupper($usuario) : $usuario,
            'password' => $data['password'],
        ];
    }

    protected function throwFailureValidationException(): never
    {
        throw ValidationException::withMessages([
            'data.email' => 'El correo/código o la contraseña no son correctos.',
        ]);
    }
}
