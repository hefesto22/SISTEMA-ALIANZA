<?php

declare(strict_types=1);

namespace App\Filament\Resources\Cargas\Pages;

use App\Domain\Cargas\CuentaCarga;
use App\Domain\Cargas\HojaDeCarga;
use App\Domain\Cargas\LiquidarCarga;
use App\Domain\Devoluciones\Reposiciones;
use App\Domain\Exceptions\ReglaNegocioException;
use App\Enums\EntregaFactura;
use App\Enums\TipoDevolucion;
use App\Filament\Resources\Cargas\CargaResource;
use App\Filament\Support\Acceso;
use App\Models\Carga;
use App\Models\CargaFactura;
use App\Models\CargaReposicion;
use App\Models\Devolucion;
use Filament\Actions\Action;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Repeater\TableColumn;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\ToggleButtons;
use Filament\Infolists\Components\TextEntry;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\Concerns\InteractsWithRecord;
use Filament\Resources\Pages\Page;
use Filament\Schemas\Components\Actions;
use Filament\Schemas\Components\Group;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\View;
use Filament\Schemas\Schema;
use Filament\Support\Enums\Alignment;
use Filament\Support\Enums\FontWeight;
use Filament\Support\Enums\Width;
use Filament\Support\Icons\Heroicon;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Support\Facades\Gate;

/**
 * Liquidar la carga cuando el camión regresa.
 *
 *  - Cada factura viene como entregada; se cambian solo las que no se
 *    entregaron: Anular (la factura se anula y lo que llevaba regresa a la
 *    bodega) u Otra carga (sale otro día).
 *  - Cada cambio que llevaba viene como entregado; el que no se entregó
 *    regresa a la bodega y espera otra carga.
 *  - Lo que trajo: el efectivo, y los cheques y las transferencias uno por
 *    uno (de qué factura, banco, número o referencia, monto).
 *  - Al lado, el cuadre en vivo (CuentaCarga): debía traer, trajo y la
 *    diferencia. Si no cuadra, la nota es obligatoria.
 *
 * "Liquidar" pide confirmar con el resultado y lo guarda LiquidarCarga.
 *
 * @property Schema $form
 */
class Liquidacion extends Page
{
    use InteractsWithRecord;

    protected static string $resource = CargaResource::class;

    protected string $view = 'filament.cargas.liquidar';

    protected Width|string|null $maxContentWidth = Width::Full;

    /** @var array<string, mixed> */
    public array $data = [];

    /**
     * El último cuadre y de qué datos salió (dentro de la misma petición se pide varias veces).
     *
     * @var array{facturas: int, entregadas: int, salio: float, entregado: float, no_entregado: float, anuladas: float, devuelto: float, debe_traer: float, efectivo: float, cheques: float, transferencias: float, trajo: float, diferencia: float}|null
     */
    private ?array $cuenta = null;

    private ?string $cuentaDe = null;

    /** @var array<int, string>|null */
    private ?array $opciones = null;

    public function mount(int|string $record): void
    {
        $this->record = $this->resolveRecord($record);

        $this->form->fill([
            'facturas'       => $this->filas(),
            'cambios'        => $this->filasDeCambios(),
            'efectivo'       => null,
            'cheques'        => [],
            'transferencias' => [],
            'nota'           => null,
        ]);
    }

    /**
     * Solo la que va en ruta, y quien despacha en esa bodega (CargaPolicy::liquidar).
     *
     * @param array<string, mixed> $parameters
     */
    public static function canAccess(array $parameters = []): bool
    {
        $record = $parameters['record'] ?? null;

        return $record instanceof Carga && Gate::allows('liquidar', $record);
    }

    public function getTitle(): string|Htmlable
    {
        return 'Liquidar carga '.$this->carga()->numero();
    }

    public function getSubheading(): string
    {
        $carga = $this->carga();

        return 'Salió '.$carga->fecha->format('d/m/Y').' con '.lempiras($carga->total_carga).' · '.$carga->repartidor->name;
    }

    public function getBreadcrumb(): string
    {
        return 'Liquidar';
    }

    public function form(Schema $schema): Schema
    {
        return $schema
            ->statePath('data')
            ->columns(['default' => 1, 'xl' => 3])
            ->components([
                Group::make([
                    Section::make('Cada factura')
                        ->description('Todas vienen como entregadas: cambie solo las que no se entregaron. Anular: la factura se anula y lo que llevaba regresa a la bodega. Otra carga: sale otro día. Si un cliente devolvió algo, regístrelo antes en su pedido (Devolución).')
                        ->icon(Heroicon::OutlinedDocumentText)
                        ->compact()
                        ->schema([
                            Repeater::make('facturas')
                                ->hiddenLabel()
                                ->addable(false)
                                ->deletable(false)
                                ->reorderable(false)
                                ->table([
                                    TableColumn::make('Factura'),
                                    TableColumn::make('Cliente'),
                                    TableColumn::make('Total')->alignment(Alignment::End),
                                    TableColumn::make('Qué pasó'),
                                ])
                                ->schema([
                                    Hidden::make('id'),
                                    Hidden::make('corto'),
                                    Hidden::make('cliente'),
                                    Hidden::make('detalle'),
                                    Hidden::make('monto'),
                                    TextEntry::make('ver_numero')
                                        ->hiddenLabel()
                                        ->state(fn (Get $get): string => (string) $get('corto'))
                                        ->weight(FontWeight::SemiBold),
                                    TextEntry::make('ver_cliente')
                                        ->hiddenLabel()
                                        ->state(fn (Get $get): string => (string) $get('cliente'))
                                        ->helperText(fn (Get $get): string => (string) $get('detalle')),
                                    TextEntry::make('ver_total')
                                        ->hiddenLabel()
                                        ->state(fn (Get $get): string => lempiras((float) $get('monto')))
                                        ->alignEnd(),
                                    ToggleButtons::make('entrega')
                                        ->hiddenLabel()
                                        ->options([
                                            CuentaCarga::ENTREGADA  => 'Entregada',
                                            CuentaCarga::ANULAR     => 'Anular',
                                            CuentaCarga::OTRA_CARGA => 'Otra carga',
                                        ])
                                        ->colors([
                                            CuentaCarga::ENTREGADA  => 'success',
                                            CuentaCarga::ANULAR     => 'danger',
                                            CuentaCarga::OTRA_CARGA => 'info',
                                        ])
                                        ->grouped()
                                        ->required()
                                        ->live(),
                                ]),
                        ]),

                    Section::make('Cambios que llevaba')
                        ->description('Lo que se le debía a cada cliente por un cambio por cambio. Todos vienen como entregados: cambie solo los que no se entregaron (regresan a la bodega y esperan otra carga).')
                        ->icon(Heroicon::OutlinedArrowsRightLeft)
                        ->compact()
                        ->visible(fn (): bool => $this->carga()->cambios()->where('estado', EntregaFactura::EnRuta->value)->exists())
                        ->schema([
                            Repeater::make('cambios')
                                ->hiddenLabel()
                                ->addable(false)
                                ->deletable(false)
                                ->reorderable(false)
                                ->table([
                                    TableColumn::make('Cliente'),
                                    TableColumn::make('Qué se le llevaba'),
                                    TableColumn::make('Qué pasó'),
                                ])
                                ->schema([
                                    Hidden::make('id'),
                                    Hidden::make('cliente'),
                                    Hidden::make('detalle'),
                                    Hidden::make('lleva'),
                                    TextEntry::make('ver_cliente')
                                        ->hiddenLabel()
                                        ->state(fn (Get $get): string => (string) $get('cliente'))
                                        ->helperText(fn (Get $get): string => (string) $get('detalle'))
                                        ->weight(FontWeight::SemiBold),
                                    TextEntry::make('ver_lleva')
                                        ->hiddenLabel()
                                        ->state(fn (Get $get): string => (string) $get('lleva')),
                                    ToggleButtons::make('entrega')
                                        ->hiddenLabel()
                                        ->options([
                                            CuentaCarga::ENTREGADA  => 'Entregado',
                                            CuentaCarga::OTRA_CARGA => 'No se entregó',
                                        ])
                                        ->colors([
                                            CuentaCarga::ENTREGADA  => 'success',
                                            CuentaCarga::OTRA_CARGA => 'warning',
                                        ])
                                        ->grouped()
                                        ->required(),
                                ]),
                        ]),

                    Section::make('Lo que trajo')
                        ->description('El efectivo contado y, uno por uno, los cheques y las transferencias.')
                        ->icon(Heroicon::OutlinedBanknotes)
                        ->compact()
                        ->columns(['default' => 1, 'md' => 3])
                        ->schema([
                            TextInput::make('efectivo')
                                ->label('Efectivo')
                                ->prefix('L')
                                ->numeric()
                                ->minValue(0)
                                ->placeholder('0.00')
                                ->required()
                                ->validationMessages(['required' => 'Anote el efectivo que trajo (0 si no trajo).'])
                                ->live(debounce: 600),
                            $this->pagos('cheques', 'Cheques', 'Número', 'Agregar cheque')->columnSpanFull(),
                            $this->pagos('transferencias', 'Transferencias', 'Referencia', 'Agregar transferencia')->columnSpanFull(),
                        ]),
                ])->columnSpan(['default' => 1, 'xl' => 2]),

                Group::make([
                    Section::make('Cuadre')
                        ->icon(Heroicon::OutlinedScale)
                        ->compact()
                        ->schema([
                            View::make('filament.cargas.cuadre')
                                ->viewData(fn (): array => [
                                    'c'            => $this->cuenta(),
                                    'devoluciones' => $this->devoluciones(),
                                ]),
                            Textarea::make('nota')
                                ->label('Nota')
                                ->rows(3)
                                ->placeholder(fn (): string => $this->cuadra() ? 'Opcional' : 'Por qué falta o sobra')
                                ->required(fn (): bool => ! $this->cuadra())
                                ->validationMessages(['required' => 'No cuadra: escriba por qué falta o sobra.'])
                                ->maxLength(1000),
                            Actions::make([$this->accionLiquidar()])->fullWidth(),
                        ]),
                ])
                    // En pantalla ancha el cuadre acompaña mientras se baja por las facturas.
                    ->extraAttributes(['style' => 'position: sticky; top: 5rem;'])
                    ->columnSpan(['default' => 1, 'xl' => 1]),
            ]);
    }

    /**
     * "Liquidar": primero revisa el formulario; luego confirma con el resultado.
     */
    private function accionLiquidar(): Action
    {
        return Action::make('liquidar')
            ->label('Liquidar')
            ->icon(Heroicon::OutlinedCheckCircle)
            ->size('lg')
            ->mountUsing(fn () => $this->form->validate())
            ->requiresConfirmation()
            ->modalIcon(Heroicon::OutlinedBanknotes)
            ->modalHeading(fn (): string => 'Liquidar la carga '.$this->carga()->numero())
            ->modalDescription(fn (): string => $this->resumen())
            ->modalSubmitActionLabel('Sí, liquidar')
            ->action(fn () => $this->liquidar());
    }

    public function liquidar(): void
    {
        $carga = $this->carga();
        $usuario = Acceso::usuario() ?? abort(403);
        $this->form->validate();

        try {
            $carga = app(LiquidarCarga::class)->ejecutar($carga, $this->datos(), $usuario);
        } catch (ReglaNegocioException $e) {
            Notification::make()->title($e->getMessage())->danger()->send();

            return;
        }

        Notification::make()
            ->title("Carga {$carga->numero()} liquidada: ".mb_strtolower($carga->diferenciaTexto()))
            ->success()
            ->send();

        $this->redirect(CargaResource::getUrl('view', ['record' => $carga]));
    }

    /**
     * El cuadre con lo que va en el formulario.
     *
     * @return array{facturas: int, entregadas: int, salio: float, entregado: float, no_entregado: float, anuladas: float, devuelto: float, debe_traer: float, efectivo: float, cheques: float, transferencias: float, trajo: float, diferencia: float}
     */
    public function cuenta(): array
    {
        $datos = $this->datos();
        $clave = serialize([$datos['entregas'], $datos['efectivo'], $datos['cheques'], $datos['transferencias']]);

        if ($this->cuentaDe !== $clave || $this->cuenta === null) {
            $this->cuentaDe = $clave;
            $this->cuenta = CuentaCarga::de(
                $this->carga(),
                $datos['entregas'],
                $datos['efectivo'],
                array_map(fn (array $pago): float => $pago['monto'], $datos['cheques']),
                array_map(fn (array $pago): float => $pago['monto'], $datos['transferencias']),
            );
        }

        return $this->cuenta;
    }

    private function cuadra(): bool
    {
        return abs($this->cuenta()['diferencia']) < 0.005;
    }

    /**
     * Lo del formulario, como lo pide LiquidarCarga.
     *
     * @return array{entregas: array<int, string>, cambios: array<int, string>, efectivo: float, cheques: list<array{factura_id: int|null, banco: string|null, referencia: string|null, monto: float}>, transferencias: list<array{factura_id: int|null, banco: string|null, referencia: string|null, monto: float}>, nota: string|null}
     */
    private function datos(): array
    {
        $entregas = self::decisiones($this->data['facturas'] ?? []);

        $efectivo = $this->data['efectivo'] ?? null;
        $nota = $this->data['nota'] ?? null;

        return [
            'entregas'       => $entregas,
            'cambios'        => self::decisiones($this->data['cambios'] ?? []),
            'efectivo'       => is_numeric($efectivo) ? (float) $efectivo : 0.0,
            'cheques'        => self::listaDePagos($this->data['cheques'] ?? []),
            'transferencias' => self::listaDePagos($this->data['transferencias'] ?? []),
            'nota'           => is_string($nota) ? $nota : null,
        ];
    }

    /**
     * Qué pasó con cada factura o cambio: [id => entregada | anular | otra_carga].
     *
     * @return array<int, string>
     */
    private static function decisiones(mixed $filas): array
    {
        $decisiones = [];

        foreach (is_array($filas) ? $filas : [] as $fila) {
            if (is_array($fila) && is_numeric($fila['id'] ?? null)) {
                $decisiones[(int) $fila['id']] = is_string($fila['entrega'] ?? null) ? $fila['entrega'] : CuentaCarga::ENTREGADA;
            }
        }

        return $decisiones;
    }

    /**
     * Los renglones de cheques o transferencias con monto.
     *
     * @return list<array{factura_id: int|null, banco: string|null, referencia: string|null, monto: float}>
     */
    private static function listaDePagos(mixed $filas): array
    {
        $pagos = [];

        foreach (is_array($filas) ? $filas : [] as $fila) {
            if (! is_array($fila) || ! is_numeric($fila['monto'] ?? null)) {
                continue;
            }

            $pagos[] = [
                'factura_id' => is_numeric($fila['factura_id'] ?? null) ? (int) $fila['factura_id'] : null,
                'banco'      => is_string($fila['banco'] ?? null) ? $fila['banco'] : null,
                'referencia' => is_string($fila['referencia'] ?? null) ? $fila['referencia'] : null,
                'monto'      => (float) $fila['monto'],
            ];
        }

        return $pagos;
    }

    /**
     * "Debía traer L 1,250.00 y trajo L 1,250.00: cuadra. 1 factura no se
     * entregó y se anula (lo que llevaba regresa a la bodega). Después ya no
     * se puede cambiar."
     */
    private function resumen(): string
    {
        $c = $this->cuenta();
        $entregas = array_count_values($this->datos()['entregas']);
        $anular = $entregas[CuentaCarga::ANULAR] ?? 0;
        $otra = $entregas[CuentaCarga::OTRA_CARGA] ?? 0;

        $partes = ['Debía traer '.lempiras($c['debe_traer']).' y trajo '.lempiras($c['trajo']).': '.mb_strtolower(CuentaCarga::texto($c['diferencia'])).'.'];

        if ($anular > 0) {
            $partes[] = ($anular === 1 ? '1 factura no se entregó y se anula' : "{$anular} facturas no se entregaron y se anulan").' (lo que llevaban regresa a la bodega).';
        }

        if ($otra > 0) {
            $partes[] = ($otra === 1 ? '1 factura sale en otra carga.' : "{$otra} facturas salen en otra carga.");
        }

        $cambios = array_count_values($this->datos()['cambios']);
        $sinEntregar = $cambios[CuentaCarga::OTRA_CARGA] ?? 0;
        $entregados = $cambios[CuentaCarga::ENTREGADA] ?? 0;

        if ($entregados + $sinEntregar > 0) {
            $partes[] = ($entregados === 1 ? '1 cambio entregado' : "{$entregados} cambios entregados")
                .($sinEntregar === 0 ? '.' : ($sinEntregar === 1 ? '; 1 no se entregó: regresa a la bodega y espera otra carga.' : "; {$sinEntregar} no se entregaron: regresan a la bodega y esperan otra carga."));
        }

        $partes[] = 'Después ya no se puede cambiar.';

        return implode(' ', $partes);
    }

    /**
     * Las devoluciones con crédito que se registraron en la ruta (se descuentan).
     *
     * @return list<string>
     */
    private function devoluciones(): array
    {
        return $this->carga()->devoluciones()
            ->where('tipo', TipoDevolucion::Credito->value)
            ->with('factura')
            ->orderBy('id')
            ->get()
            ->map(fn (Devolucion $devolucion): string => $devolucion->numero.($devolucion->factura !== null ? ' · factura '.HojaDeCarga::corto($devolucion->factura->numero) : '').' · '.lempiras($devolucion->total))
            ->values()
            ->all();
    }

    /**
     * Cheques o transferencias: una fila por cada uno.
     */
    private function pagos(string $nombre, string $titulo, string $referencia, string $agregar): Repeater
    {
        return Repeater::make($nombre)
            ->label($titulo)
            ->table([
                TableColumn::make('Factura'),
                TableColumn::make('Banco'),
                TableColumn::make($referencia),
                TableColumn::make('Monto')->markAsRequired(),
            ])
            ->schema([
                Select::make('factura_id')
                    ->hiddenLabel()
                    ->options(fn (): array => $this->opcionesDeFacturas())
                    ->placeholder('De cuál (opcional)')
                    ->searchable(),
                TextInput::make('banco')
                    ->hiddenLabel()
                    ->placeholder('Ej. BAC')
                    ->maxLength(80),
                TextInput::make('referencia')
                    ->hiddenLabel()
                    ->placeholder($referencia === 'Número' ? 'Número del cheque' : 'Referencia')
                    ->maxLength(80),
                TextInput::make('monto')
                    ->hiddenLabel()
                    ->prefix('L')
                    ->numeric()
                    ->minValue(0.01)
                    ->required()
                    ->live(debounce: 600),
            ])
            ->defaultItems(0)
            ->addActionLabel($agregar)
            ->reorderable(false)
            ->live();
    }

    /**
     * Las facturas de la carga para "De cuál": "4106 · PULPERÍA LA ROCA".
     *
     * @return array<int, string>
     */
    private function opcionesDeFacturas(): array
    {
        if ($this->opciones !== null) {
            return $this->opciones;
        }

        $opciones = [];

        foreach ($this->carga()->renglones()->with('factura')->get() as $renglon) {
            if (! $renglon->factura->estaAnulada()) {
                $opciones[$renglon->factura_id] = HojaDeCarga::corto($renglon->factura->numero).' · '.$renglon->factura->cliente_nombre;
            }
        }

        return $this->opciones = $opciones;
    }

    /**
     * Una fila por cada factura vigente de la carga (las anuladas al corregir
     * el pedido no se cobran: va la que la sustituye).
     *
     * @return list<array<string, mixed>>
     */
    private function filas(): array
    {
        $carga = $this->carga();
        $carga->load(['renglones.factura.pedido.cliente.municipio', 'renglones.factura.pedido.vendedor', 'renglones.factura.pedido.ruta.bodega']);
        $filas = [];

        /** @var CargaFactura $renglon */
        foreach ($carga->renglones as $renglon) {
            $factura = $renglon->factura;

            if ($factura->estaAnulada()) {
                continue;
            }

            $municipio = $factura->pedido->cliente->municipio?->nombre;
            $ruta = $factura->pedido->ruta?->codigo();

            $filas[] = [
                'id'      => $factura->id,
                'corto'   => HojaDeCarga::corto($factura->numero),
                'cliente' => $factura->cliente_nombre,
                'detalle' => ($ruta !== null ? $ruta.' · ' : '').($municipio !== null ? $municipio.' · ' : '').'vendió '.$factura->pedido->vendedor->name,
                'monto'   => $factura->total,
                'entrega' => CuentaCarga::ENTREGADA,
            ];
        }

        return $filas;
    }

    /**
     * Una fila por cada cambio que lleva en ruta.
     *
     * @return list<array<string, mixed>>
     */
    private function filasDeCambios(): array
    {
        $filas = [];

        /** @var CargaReposicion $renglon */
        foreach ($this->carga()->cambios()->where('estado', EntregaFactura::EnRuta->value)->with(array_map(fn (string $relacion): string => 'reposicion.'.$relacion, Reposiciones::DATOS))->get() as $renglon) {
            $cambio = $renglon->reposicion;
            $municipio = $cambio->cliente->municipio?->nombre;

            $filas[] = [
                'id'      => $cambio->id,
                'cliente' => $cambio->cliente->nombre,
                'detalle' => implode(' · ', array_filter([$cambio->deRuta(), $municipio, $cambio->devolucion->numero])),
                'lleva'   => $cambio->texto(),
                'entrega' => CuentaCarga::ENTREGADA,
            ];
        }

        return $filas;
    }

    public function carga(): Carga
    {
        $carga = $this->getRecord();
        abort_unless($carga instanceof Carga, 404);

        return $carga;
    }
}
