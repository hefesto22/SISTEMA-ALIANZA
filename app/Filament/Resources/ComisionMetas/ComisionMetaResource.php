<?php

declare(strict_types=1);

namespace App\Filament\Resources\ComisionMetas;

use App\Domain\Comisiones\Escala;
use App\Domain\Comisiones\GuardarMetaComision;
use App\Domain\Exceptions\ReglaNegocioException;
use App\Enums\Rol;
use App\Filament\Resources\ComisionGrupos\CampoEscalones;
use App\Filament\Resources\ComisionMetas\Pages\ManageComisionMetas;
use App\Filament\Support\Acceso;
use App\Models\ComisionGrupo;
use App\Models\ComisionMeta;
use App\Models\User;
use BackedEnum;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Select;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Schema;
use Filament\Support\Enums\Width;
use Filament\Support\Exceptions\Halt;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

/**
 * Metas por vendedor: la escala propia de un vendedor en un grupo de
 * comisión (una ruta más chica, alguien que empieza…). Sin meta propia,
 * el vendedor usa la escala de su bodega, y si su bodega no puso montos, la
 * del grupo.
 *
 * Administración, de cualquier vendedor; el encargado, de los de su bodega.
 *
 * @extends \Filament\Resources\Resource<ComisionMeta>
 */
class ComisionMetaResource extends Resource
{
    protected static ?string $model = ComisionMeta::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedFlag;

    protected static ?string $modelLabel = 'Meta de vendedor';

    protected static ?string $pluralModelLabel = 'Metas por vendedor';

    protected static bool $hasTitleCaseModelLabel = false;

    protected static ?int $navigationSort = 2;

    public static function getNavigationGroup(): ?string
    {
        return 'Comisiones';
    }

    public static function form(Schema $schema): Schema
    {
        return $schema
            ->columns(2)
            ->components([
                Select::make('vendedor_id')
                    ->label('Vendedor')
                    ->options(fn (): array => self::vendedores())
                    ->searchable()
                    ->required()
                    ->live(),
                Select::make('comision_grupo_id')
                    ->label('Grupo de comisión')
                    ->options(fn (): array => ComisionGrupo::query()->activos()->orderBy('nombre')->pluck('nombre', 'id')->all())
                    ->required()
                    ->live()
                    ->afterStateUpdated(function (mixed $state, Get $get, Set $set): void {
                        // Para empezar: la que ya tiene (la de su bodega o la del grupo), y se cambia lo que haga falta.
                        $grupo = is_numeric($state) ? ComisionGrupo::query()->find((int) $state) : null;

                        if ($grupo !== null && self::sinEscribir($get('escalones'))) {
                            $set('escalones', self::escalaSinMeta($grupo, $get('vendedor_id'))->paraFormulario());
                        }
                    })
                    ->helperText(function (Get $get): ?string {
                        $grupo = is_numeric($get('comision_grupo_id')) ? ComisionGrupo::query()->find((int) $get('comision_grupo_id')) : null;

                        return $grupo === null ? null : 'Sin meta propia usa '.self::deDonde($grupo, $get('vendedor_id')).': '.self::escalaSinMeta($grupo, $get('vendedor_id'))->texto();
                    }),
                CampoEscalones::make()
                    ->label('Su escala')
                    ->columnSpanFull(),
            ]);
    }

    /**
     * Administración ve todas; el encargado, las de los vendedores de su bodega.
     */
    public static function getEloquentQuery(): Builder
    {
        $usuario = Acceso::usuario();
        $query = ComisionMeta::query()->with(['grupo.escalasBodega', 'vendedor.bodega']);

        if ($usuario === null) {
            return $query->whereRaw('1 = 0');
        }

        return $usuario->veTodasLasBodegas()
            ? $query
            : $query->whereHas('vendedor', fn (Builder $q): Builder => $q->where('bodega_id', $usuario->bodega_id));
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('vendedor.name')
                    ->label('Vendedor')
                    ->formatStateUsing(fn (ComisionMeta $record): string => filled($record->vendedor->codigo) ? $record->vendedor->codigo.' · '.$record->vendedor->name : $record->vendedor->name)
                    ->description(fn (ComisionMeta $record): ?string => $record->vendedor->bodega?->nombre)
                    ->searchable(['name', 'codigo'])
                    ->sortable()
                    ->weight('bold'),
                TextColumn::make('grupo.nombre')
                    ->label('Grupo')
                    ->badge()
                    ->color('gray')
                    ->sortable(),
                TextColumn::make('escala')
                    ->label('Su escala')
                    ->state(fn (ComisionMeta $record): string => $record->escala()->texto())
                    ->wrap(),
                TextColumn::make('de_todos')
                    ->label('Sin meta propia')
                    ->state(fn (ComisionMeta $record): string => $record->grupo->escalaEn($record->vendedor->bodega_id)->texto())
                    ->description(fn (ComisionMeta $record): string => $record->grupo->escalasBodega->contains('bodega_id', $record->vendedor->bodega_id) ? 'la de su bodega' : 'la de todos')
                    ->color('gray')
                    ->wrap(),
            ])
            ->defaultSort('vendedor.name')
            ->filters([
                SelectFilter::make('comision_grupo_id')
                    ->label('Grupo')
                    ->relationship('grupo', 'nombre'),
                SelectFilter::make('vendedor_id')
                    ->label('Vendedor')
                    ->options(fn (): array => self::vendedores())
                    ->searchable(),
            ])
            ->emptyStateHeading('Todos usan la escala de su grupo')
            ->emptyStateDescription('Ponga una meta propia a un vendedor cuando su ruta necesite otra escala (más chica, alguien que empieza…).')
            ->recordActions([
                EditAction::make()
                    ->modalHeading(fn (ComisionMeta $record): string => 'Meta de '.$record->vendedor->name.' en '.$record->grupo->nombre)
                    ->modalWidth(Width::ThreeExtraLarge)
                    ->mutateRecordDataUsing(fn (array $data, ComisionMeta $record): array => [...$data, 'escalones' => $record->escala()->paraFormulario()])
                    ->using(fn (array $data, ComisionMeta $record): ComisionMeta => self::guardar($record, $data)),
                DeleteAction::make()
                    ->modalDescription('El vendedor vuelve a la escala de todos. Lo ya pagado no cambia.'),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => ManageComisionMetas::route('/'),
        ];
    }

    /**
     * @param array<string, mixed> $data
     */
    public static function guardar(?ComisionMeta $meta, array $data): ComisionMeta
    {
        try {
            return app(GuardarMetaComision::class)->ejecutar(
                $meta,
                $data['comision_grupo_id'] ?? null,
                $data['vendedor_id'] ?? null,
                $data['escalones'] ?? [],
                Acceso::usuario() ?? abort(403),
            );
        } catch (ReglaNegocioException $e) {
            Notification::make()->title($e->getMessage())->danger()->send();

            throw new Halt;
        }
    }

    /**
     * Vendedores activos ("V01 · ANA"); el encargado, solo los de su bodega.
     *
     * @return array<int, string>
     */
    public static function vendedores(): array
    {
        return User::query()
            ->whereHas('roles', fn (Builder $r): Builder => $r->where('name', Rol::Vendedor->value))
            ->where('is_active', true)
            ->when(! Acceso::veTodo(), fn (Builder $q): Builder => $q->where('bodega_id', Acceso::usuario()?->bodega_id))
            ->orderBy('codigo')
            ->orderBy('name')
            ->get()
            ->mapWithKeys(fn (User $u): array => [$u->id => filled($u->codigo) ? "{$u->codigo} · {$u->name}" : $u->name])
            ->all();
    }

    /**
     * Con lo que se le paga si no tiene meta propia: la escala de su bodega
     * o la del grupo.
     */
    private static function escalaSinMeta(ComisionGrupo $grupo, mixed $vendedorId): Escala
    {
        return $grupo->escalaEn(self::bodegaDe($vendedorId));
    }

    /**
     * "la de su bodega" o "la de todos".
     */
    private static function deDonde(ComisionGrupo $grupo, mixed $vendedorId): string
    {
        $bodegaId = self::bodegaDe($vendedorId);

        return $bodegaId !== null && $grupo->escalasBodega->contains('bodega_id', $bodegaId) ? 'la de su bodega' : 'la de todos';
    }

    private static function bodegaDe(mixed $vendedorId): ?int
    {
        $bodegaId = is_numeric($vendedorId) ? User::query()->whereKey((int) $vendedorId)->value('bodega_id') : null;

        return is_numeric($bodegaId) ? (int) $bodegaId : null;
    }

    /**
     * El repetidor sigue como al abrir (un escalón vacío): se puede llenar con la del grupo.
     */
    private static function sinEscribir(mixed $escalones): bool
    {
        foreach (is_array($escalones) ? $escalones : [] as $fila) {
            if (is_array($fila) && (filled($fila['porcentaje'] ?? null) || (float) ($fila['desde'] ?? 0) > 0)) {
                return false;
            }
        }

        return true;
    }
}
