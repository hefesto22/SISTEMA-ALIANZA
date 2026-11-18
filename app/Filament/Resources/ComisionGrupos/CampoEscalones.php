<?php

declare(strict_types=1);

namespace App\Filament\Resources\ComisionGrupos;

use App\Domain\Comisiones\Escala;
use App\Enums\FrecuenciaComision;
use App\Models\Configuracion;
use Closure;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Repeater\TableColumn;
use Filament\Forms\Components\TextInput;
use Filament\Support\RawJs;

/**
 * Los escalones de una escala de comisión (la del grupo y la propia de un
 * vendedor): desde cuánto vende en el MES, sin ISV, y qué %. El monto lleva
 * separador de miles al escribirlo (80,000).
 */
final class CampoEscalones
{
    public static function make(string $nombre = 'escalones'): Repeater
    {
        $quincenal = Configuracion::actual()->comision_frecuencia === FrecuenciaComision::Quincenal;

        return Repeater::make($nombre)
            ->label('Escalones')
            ->helperText('Se paga el % del escalón que alcance sobre TODA la venta del grupo en el mes (sin ISV, ya con descuentos). Ej. 0.5% desde 0, 1% desde 80,000 y 2% desde 100,000: si en el mes vende 105,000 gana el 2% de 105,000. La meta es el escalón más alto.'
                .($quincenal ? ' Por quincena: la primera se paga como adelanto con lo que lleva y a fin de mes se completa con el escalón de todo el mes.' : ''))
            ->addActionLabel('Agregar escalón')
            ->defaultItems(1)
            ->default([['desde' => 0, 'porcentaje' => null]])
            ->reorderable(false)
            ->compact()
            ->table([
                TableColumn::make('Desde (venta del mes)')->markAsRequired(),
                TableColumn::make('Comisión')->markAsRequired()->width('10rem'),
            ])
            ->schema([
                TextInput::make('desde')
                    ->label('Desde')
                    ->hiddenLabel()
                    ->mask(RawJs::make('$money($input)'))
                    ->stripCharacters(',')
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
