<?php

declare(strict_types=1);

namespace App\Filament\Schemas\Components;

use App\Filament\Support\Acceso;
use App\Models\Departamento;
use App\Models\Municipio;
use Filament\Forms\Components\Select;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Illuminate\Database\Eloquent\Model;

/**
 * Departamento + municipio de Honduras. Se guarda solo municipio_id; el
 * departamento sirve para escoger y se llena solo al editar.
 *
 * Uso:
 *   ...UbicacionFields::make()
 *   ...UbicacionFields::make(required: false)
 *   ...UbicacionFields::make(soloSusRutas: true)  // el vendedor solo ve los municipios de sus rutas
 *   UbicacionFields::municipio(soloSusRutas: true) // un solo campo: "SANTA ROSA DE COPÁN · COPÁN"
 */
final class UbicacionFields
{
    /**
     * @return array{0: Select, 1: Select}
     */
    public static function make(bool $required = true, bool $soloSusRutas = false): array
    {
        return [
            Select::make('departamento_id')
                ->label('Departamento')
                ->options(fn (): array => Departamento::opciones(self::departamentosDe(self::permitidos($soloSusRutas))))
                ->searchable()
                ->required($required)
                ->live()
                ->dehydrated(false)
                ->default(function () use ($soloSusRutas): ?int {
                    $departamentos = self::departamentosDe(self::permitidos($soloSusRutas));

                    // Si todo lo que puede escoger está en un departamento, ya viene puesto.
                    return $departamentos !== null && count($departamentos) === 1 ? $departamentos[0] : null;
                })
                ->afterStateHydrated(function (Select $component, ?Model $record): void {
                    $municipioId = $record?->getAttribute('municipio_id');

                    if (filled($municipioId)) {
                        $component->state(Municipio::query()->whereKey($municipioId)->value('departamento_id'));
                    }
                })
                ->afterStateUpdated(function (Set $set): void {
                    $set('municipio_id', null);
                })
                ->helperText(fn (): ?string => self::permitidos($soloSusRutas) === []
                    ? 'No tiene rutas asignadas. Pida a administración que lo agregue a una ruta.'
                    : null),
            Select::make('municipio_id')
                ->label('Municipio')
                ->options(fn (Get $get): array => Municipio::opcionesDe($get('departamento_id'), self::permitidos($soloSusRutas)))
                ->searchable()
                ->required($required)
                ->placeholder(fn (Get $get): string => blank($get('departamento_id')) ? 'Escoja primero el departamento' : 'Seleccione el municipio'),
        ];
    }

    /**
     * Un solo campo en lugar de dos: se busca el municipio o el departamento
     * ("dolores", "copan") y se escoge "DOLORES · COPÁN". Más rápido en el
     * teléfono. Si solo puede escoger uno (el vendedor con una ruta), ya
     * viene puesto.
     */
    public static function municipio(bool $required = true, bool $soloSusRutas = false): Select
    {
        return Select::make('municipio_id')
            ->label('Municipio')
            ->options(fn (): array => Municipio::opcionesConDepartamento(self::permitidos($soloSusRutas)))
            ->searchable()
            ->required($required)
            ->placeholder('Buscar municipio…')
            ->searchPrompt('Escriba el municipio o el departamento')
            ->default(function () use ($soloSusRutas): ?int {
                $permitidos = self::permitidos($soloSusRutas);

                return $permitidos !== null && count($permitidos) === 1 ? $permitidos[0] : null;
            })
            ->helperText(fn (): ?string => self::permitidos($soloSusRutas) === []
                ? 'No tiene rutas asignadas. Pida a administración que lo agregue a una ruta.'
                : null);
    }

    /**
     * Municipios que puede escoger quien usa el formulario: los de sus rutas
     * si es vendedor; null = todos.
     *
     * @return list<int>|null
     */
    private static function permitidos(bool $soloSusRutas): ?array
    {
        return $soloSusRutas ? Acceso::usuario()?->municipiosDeRuta() : null;
    }

    /**
     * Departamentos de esos municipios (null = todos).
     *
     * @param list<int>|null $municipios
     *
     * @return list<int>|null
     */
    private static function departamentosDe(?array $municipios): ?array
    {
        if ($municipios === null) {
            return null;
        }

        return array_values(Municipio::query()
            ->whereKey($municipios)
            ->distinct()
            ->pluck('departamento_id')
            ->map(fn (mixed $id): int => (int) $id)
            ->all());
    }
}
