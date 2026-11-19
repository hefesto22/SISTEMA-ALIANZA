<?php

declare(strict_types=1);

namespace App\Filament\Resources\ComisionGrupos;

use App\Domain\Comisiones\GuardarEscalaBodega;
use App\Domain\Comisiones\GuardarGrupoComision;
use App\Domain\Exceptions\ReglaNegocioException;
use App\Filament\Resources\ComisionGrupos\Pages\ManageComisionGrupos;
use App\Filament\Support\Acceso;
use App\Models\Bodega;
use App\Models\Categoria;
use App\Models\ComisionEscalaBodega;
use App\Models\ComisionGrupo;
use App\Support\Texto;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Schema;
use Filament\Support\Enums\Width;
use Filament\Support\Exceptions\Halt;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\Layout\Stack;
use Filament\Tables\Columns\ViewColumn;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

/**
 * Grupos de comisión: "LIMPIEZA" junta categorías de varios proveedores y
 * tiene su escala (0.5% desde 0, 1% desde 80,000, 2% desde 100,000). Lo
 * que no está en un grupo no paga comisión.
 *
 * Los grupos son de todas las bodegas. Administración los arma; cada bodega
 * puede poner sus propios montos ("Por bodega": el encargado, solo los de la
 * suya) y si no, usa la escala del grupo. Las metas propias de un vendedor
 * se ponen en Metas por vendedor.
 *
 * @extends \Filament\Resources\Resource<ComisionGrupo>
 */
class ComisionGrupoResource extends Resource
{
    protected static ?string $model = ComisionGrupo::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedRectangleGroup;

    protected static ?string $recordTitleAttribute = 'nombre';

    protected static ?string $modelLabel = 'Grupo de comisión';

    protected static ?string $pluralModelLabel = 'Grupos de comisión';

    protected static bool $hasTitleCaseModelLabel = false;

    protected static ?int $navigationSort = 3;

    public static function getNavigationGroup(): ?string
    {
        return 'Comisiones';
    }

    public static function form(Schema $schema): Schema
    {
        return $schema
            ->columns(2)
            ->components([
                TextInput::make('nombre')
                    ->label('Nombre del grupo')
                    ->placeholder('Ej. Limpieza')
                    ->required()
                    ->maxLength(80)
                    ->mutateStateForValidationUsing(fn (?string $state): ?string => filled($state) ? Texto::mayusculas($state) : $state)
                    ->unique(ignoreRecord: true),
                Toggle::make('activo')
                    ->label('Paga comisión')
                    ->default(true)
                    ->inline(false)
                    ->helperText('Apagado, lo que se vende de sus categorías no paga comisión.'),
                TextInput::make('descripcion')
                    ->label('Descripción')
                    ->placeholder('Opcional')
                    ->maxLength(255)
                    ->columnSpanFull(),
                Select::make('categorias')
                    ->label('Categorías que entran')
                    ->options(fn (?ComisionGrupo $record): array => self::opcionesCategorias($record))
                    ->multiple()
                    ->searchable()
                    ->placeholder('Buscar categoría…')
                    ->helperText('De cualquier proveedor. Una categoría va en un solo grupo: si escoge una que está en otro, se pasa a este.')
                    ->columnSpanFull(),
                CampoEscalones::make()
                    ->label('Escala para todas las bodegas')
                    ->columnSpanFull(),
            ]);
    }

    public static function getEloquentQuery(): Builder
    {
        return ComisionGrupo::query()->with(['categorias.proveedor', 'escalasBodega.bodega'])->withCount('metas');
    }

    public static function table(Table $table): Table
    {
        return $table
            // Una tarjeta por grupo: su escala en escalones, cómo va en cada bodega y sus categorías por proveedor.
            ->columns([
                Stack::make([
                    ViewColumn::make('tarjeta')
                        ->view('filament.comisiones.tarjeta-grupo')
                        ->state(fn (ComisionGrupo $record): string => $record->nombre)
                        ->searchable(query: fn (Builder $query, string $search): Builder => $query->where(fn (Builder $q): Builder => $q
                            ->where('nombre', 'ilike', '%'.$search.'%')
                            ->orWhereHas('categorias', fn (Builder $c): Builder => $c->where('nombre', 'ilike', '%'.$search.'%')))),
                ]),
            ])
            ->contentGrid(['md' => 2, '2xl' => 3])
            ->extraAttributes(['class' => 'grupos-tarjetas'])
            ->searchPlaceholder('Grupo o categoría')
            ->defaultSort('nombre')
            ->filters([
                TernaryFilter::make('activo')
                    ->label('Paga comisión'),
            ])
            ->emptyStateHeading('Sin grupos de comisión')
            ->emptyStateDescription('Cree un grupo (ej. Limpieza), escoja sus categorías y ponga la escala: desde cuánto vende y qué % gana.')
            ->recordActions([
                self::accionBodega(),
                EditAction::make()
                    ->button()
                    ->outlined()
                    ->modalHeading(fn (ComisionGrupo $record): string => 'Grupo '.$record->nombre)
                    ->modalWidth(Width::FourExtraLarge)
                    ->mutateRecordDataUsing(fn (array $data, ComisionGrupo $record): array => [
                        ...$data,
                        'categorias' => $record->categorias->pluck('id')->all(),
                        'escalones'  => $record->escala()->paraFormulario(),
                    ])
                    ->using(fn (array $data, ComisionGrupo $record): ComisionGrupo => self::guardar($record, $data)),
                DeleteAction::make()
                    ->iconButton()
                    ->tooltip('Borrar el grupo'),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => ManageComisionGrupos::route('/'),
        ];
    }

    /**
     * Con lo que no se pudo guardar, avisa y deja el formulario abierto.
     *
     * @param array<string, mixed> $data
     */
    public static function guardar(?ComisionGrupo $grupo, array $data): ComisionGrupo
    {
        try {
            return app(GuardarGrupoComision::class)->ejecutar($grupo, $data);
        } catch (ReglaNegocioException $e) {
            Notification::make()->title($e->getMessage())->danger()->send();

            throw new Halt;
        }
    }

    /**
     * Los montos del grupo en una bodega: administración escoge la bodega; el
     * encargado, la suya. Apagado "propios montos", la bodega usa los del grupo.
     */
    private static function accionBodega(): Action
    {
        return Action::make('bodega')
            ->label(fn (): string => Acceso::veTodo() ? 'Por bodega' : 'Mi bodega')
            ->icon(Heroicon::OutlinedBuildingStorefront)
            ->button()
            ->outlined()
            ->color('gray')
            ->visible(fn (): bool => Acceso::puede('EscalaBodega:ComisionGrupo') && (Acceso::veTodo() || Acceso::usuario()?->bodega_id !== null))
            ->modalHeading(fn (ComisionGrupo $record): string => Acceso::veTodo() ? $record->nombre.' por bodega' : $record->nombre.' en '.(Acceso::usuario()->bodega->nombre ?? 'su bodega'))
            ->modalDescription(fn (ComisionGrupo $record): string => 'Para todas las bodegas: '.$record->escala()->texto().'. La bodega que no ponga sus montos usa esta.')
            ->modalWidth(Width::ThreeExtraLarge)
            ->modalSubmitActionLabel('Guardar')
            ->fillForm(fn (ComisionGrupo $record): array => self::datosBodega($record, Acceso::veTodo() ? null : Acceso::usuario()?->bodega_id))
            ->schema([
                Hidden::make('grupo_id'),
                Select::make('bodega_id')
                    ->label('Bodega')
                    ->options(fn (): array => Bodega::query()->orderBy('nombre')->pluck('nombre', 'id')->all())
                    ->required()
                    ->live()
                    ->afterStateUpdated(function (mixed $state, Get $get, Set $set): void {
                        $grupo = is_numeric($get('grupo_id')) ? ComisionGrupo::query()->find((int) $get('grupo_id')) : null;

                        if ($grupo !== null) {
                            $datos = self::datosBodega($grupo, is_numeric($state) ? (int) $state : null);
                            $set('propia', $datos['propia']);
                            $set('escalones', $datos['escalones']);
                        }
                    })
                    ->visible(fn (): bool => Acceso::veTodo()),
                Toggle::make('propia')
                    ->label('Esta bodega pone sus propios montos')
                    ->helperText('Apagado, usa la escala del grupo.')
                    ->live(),
                CampoEscalones::make()
                    ->label('Escala en la bodega')
                    ->visible(fn (Get $get): bool => (bool) $get('propia')),
            ])
            ->action(function (array $data, ComisionGrupo $record): void {
                $usuario = Acceso::usuario() ?? abort(403);
                $bodegaId = $usuario->veTodasLasBodegas() ? ($data['bodega_id'] ?? null) : $usuario->bodega_id;

                try {
                    app(GuardarEscalaBodega::class)->ejecutar($record->id, $bodegaId, ($data['propia'] ?? false) ? ($data['escalones'] ?? []) : null, $usuario);
                } catch (ReglaNegocioException $e) {
                    Notification::make()->title($e->getMessage())->danger()->send();

                    throw new Halt;
                }

                Notification::make()->title(($data['propia'] ?? false) ? 'Escala de la bodega guardada' : 'La bodega usa la escala del grupo')->success()->send();
            });
    }

    /**
     * Para llenar el formulario de "Por bodega": sus montos si los puso; si
     * no, los del grupo (para empezar a cambiarlos desde ahí).
     *
     * @return array{grupo_id: int, bodega_id: int|null, propia: bool, escalones: list<array{desde: string, porcentaje: float}>}
     */
    private static function datosBodega(ComisionGrupo $grupo, ?int $bodegaId): array
    {
        $propia = $bodegaId === null ? null : ComisionEscalaBodega::query()->where('comision_grupo_id', $grupo->id)->where('bodega_id', $bodegaId)->first();

        return [
            'grupo_id'  => $grupo->id,
            'bodega_id' => $bodegaId,
            'propia'    => $propia !== null,
            'escalones' => ($propia?->escala() ?? $grupo->escala())->paraFormulario(),
        ];
    }

    /**
     * Lo que muestra la tarjeta del grupo (resources/views/filament/comisiones/tarjeta-grupo.blade.php):
     *  - escalones: la escala para todas las bodegas, con la meta (el último).
     *  - bodegas: las que pusieron sus montos (el encargado, solo la suya).
     *  - categorias: por proveedor, en orden.
     *
     * @return array{todas: bool, escalones: list<array{desde: float, porcentaje: float, meta: bool}>, bodegas: list<array{nombre: string, escala: string}>, categorias: array<string, list<string>>, cuantas: int, metas: int}
     */
    public static function tarjeta(ComisionGrupo $grupo): array
    {
        $usuario = Acceso::usuario();
        $todas = $usuario?->veTodasLasBodegas() ?? false;
        $escalones = $grupo->escala()->escalones;
        $ultimo = count($escalones) - 1;

        $categorias = [];

        foreach ($grupo->categorias->sortBy(fn (Categoria $c): string => $c->proveedor->etiqueta().' '.$c->nombre) as $categoria) {
            $categorias[$categoria->proveedor->etiqueta()][] = $categoria->nombre;
        }

        return [
            'todas'     => $todas,
            'escalones' => array_map(fn (array $e, int $i): array => [...$e, 'meta' => $i === $ultimo && $e['desde'] > 0], $escalones, array_keys($escalones)),
            'bodegas'   => array_values($grupo->escalasBodega
                ->filter(fn (ComisionEscalaBodega $e): bool => $todas || ($usuario !== null && $e->bodega_id === $usuario->bodega_id))
                ->sortBy(fn (ComisionEscalaBodega $e): string => $e->bodega->nombre)
                ->map(fn (ComisionEscalaBodega $e): array => ['nombre' => $e->bodega->nombre, 'escala' => $e->escala()->texto()])
                ->all()),
            'categorias' => $categorias,
            'cuantas'    => $grupo->categorias->count(),
            'metas'      => (int) $grupo->getAttribute('metas_count'),
        ];
    }

    /**
     * "MERHONSA · DETERGENTES" (y a qué grupo pertenece si es de otro).
     *
     * @return array<int, string>
     */
    private static function opcionesCategorias(?ComisionGrupo $record): array
    {
        return Categoria::query()
            ->with(['proveedor', 'comisionGrupo'])
            ->get()
            ->sortBy(fn (Categoria $c): string => $c->etiqueta())
            ->mapWithKeys(fn (Categoria $c): array => [
                $c->id => $c->etiqueta().($c->comisionGrupo !== null && $c->comision_grupo_id !== $record?->id ? ' (hoy en '.$c->comisionGrupo->nombre.')' : ''),
            ])
            ->all();
    }
}
