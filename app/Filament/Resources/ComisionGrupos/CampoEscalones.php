<?php

declare(strict_types=1);

namespace App\Filament\Resources\ComisionGrupos;

use App\Domain\Comisiones\Escala;
use App\Models\Configuracion;
use Closure;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Repeater\TableColumn;
use Filament\Forms\Components\TextInput;

/**
 * Los escalones de una escala de comisión (la del grupo y la propia de un
 * vendedor): desde cuánto vende en el periodo, sin ISV, y qué %.
 */
final class CampoEscalones
{
    public static function make(string $nombre = 'escalones'): Repeater
    {
        $periodo = Configuracion::actual()->comision_frecuencia->periodo();

        return Repeater::make($nombre)
            ->label('Escalones')
            ->helperText("Se paga el % del escalón que alcance sobre TODA la venta del grupo en la {$periodo} (sin ISV, ya con descuentos). Ej. 0.5% desde 0, 1% desde 80,000 y 2% desde 100,000: si vende 105,000 gana el 2% de 105,000. La meta es el escalón más alto.")
            ->addActionLabel('Agregar escalón')
            ->defaultItems(1)
            ->default([['desde' => 0, 'porcentaje' => null]])
            ->reorderable(false)
            ->compact()
            ->table([
                TableColumn::make("Desde (venta de la {$periodo})")->markAsRequired(),
                TableColumn::make('Comisión')->markAsRequired()->width('10rem'),
            ])
            ->schema([
                TextInput::make('desde')
                    ->label('Desde')
                    ->hiddenLabel()
                    ->numeric()
                    ->minValue(0)
                    ->prefix('L.')
                    ->placeholder('0')
                    ->required(),
                TextInput::make('porcentaje')
                    ->label('%')
                    ->hiddenLabel()
                    ->numeric()
                    ->minValue(0)
                    ->maxValue(Escala::MAXIMO_PORCENTAJE)
                    ->suffix('%')
                    ->placeholder('0')
                    ->required(),
            ])
            ->rule(fn (): Closure => function (string $attribute, mixed $value, Closure $fail): void {
                $error = Escala::error($value);

                if ($error !== null) {
                    $fail($error);
                }
            });
    }
}
