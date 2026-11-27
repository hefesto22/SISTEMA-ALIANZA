<?php

declare(strict_types=1);

namespace App\Filament\Resources\Pedidos\Pages;

use App\Domain\Cargas\ArmarCarga;
use App\Domain\Cargas\FacturasParaCargar;
use App\Domain\Devoluciones\Reposiciones;
use App\Domain\Exceptions\ReglaNegocioException;
use App\Domain\Facturacion\DiasDeFacturacion;
use App\Domain\Facturacion\ResumenDelDia;
use App\Domain\Reportes\Periodo;
use App\Enums\EstadoPedido;
use App\Enums\FormatoFactura;
use App\Enums\ModoImpresion;
use App\Filament\Resources\Cargas\CargaResource;
use App\Filament\Resources\Cargas\Schemas\CargaForm;
use App\Filament\Resources\Pedidos\Actions\HojaDeAlistoAction;
use App\Filament\Resources\Pedidos\Actions\ImprimirPedidoAction;
use App\Filament\Resources\Pedidos\Actions\ValidarVariosAction;
use App\Filament\Resources\Pedidos\PedidoResource;
use App\Filament\Support\Acceso;
use App\Filament\Support\VistaImpresion;
use App\Models\Bodega;
use App\Models\Carga;
use App\Models\Factura;
use App\Models\Pedido;
use App\Models\Reposicion;
use Filament\Actions\Action;
use Filament\Actions\CreateAction;
use Filament\Forms\Components\CheckboxList;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ListRecords;
use Filament\Schemas\Components\EmbeddedTable;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\RenderHook;
use Filament\Schemas\Components\Tabs\Tab;
use Filament\Schemas\Components\View;
use Filament\Schemas\Schema;
use Filament\Support\Enums\Width;
use Filament\Support\Icons\Heroicon;
use Filament\View\PanelsRenderHook;
use Illuminate\Contracts\View\View as Hoja;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\On;
use Livewire\Attributes\Url;

/**
 * Pedidos en tarjetas, con una pestaña por estado (Impreso, los que faltan
 * de cargar en el camión; Cargado, los que ya van en una carga). Pendiente,
 * Por imprimir, Impreso y Cargado se ven de entrada por día (resources/views/filament/pedidos/por-dia.blade.php):
 * un cuadro por día y bodega (DiasDeFacturacion) para sacar la hoja de
 * alisto de los pendientes de un día (alistoDia) y validarlos todos de un
 * solo (validarDia), imprimir de un solo todas
 * las de un día, o ver cuántas facturas salieron, con qué números y por
 * cuánto, y sacar el resumen del día. En Impresos se escogen uno o varios
 * días de una bodega y se carga el camión con ellos de un solo
 * (cargarDias). "Uno por uno" vuelve a las tarjetas de cada pedido
 * (?vista=lista).
 */
class ListPedidos extends ListRecords
{
    /** Pestañas que se ven por día. */
    private const POR_DIA = [EstadoPedido::Pendiente->value, EstadoPedido::PorImprimir->value, EstadoPedido::Impreso->value, self::CARGADO];

    /**
     * Pestaña de los impresos que ya van en una carga del camión (o ya se
     * entregaron, o su día ya salió en su ruta). En Impreso quedan los que
     * faltan de cargar. El pedido sigue Impreso: es solo para verlos aparte.
     */
    public const CARGADO = 'cargado';

    /** Cuadros que se ven de entrada; "Ver más días" suma otros tantos. */
    private const CUADROS = 12;

    protected static string $resource = PedidoResource::class;

    /** Pendiente, Por imprimir, Impreso y Cargado: null = por día; 'lista' = una tarjeta por pedido. */
    #[Url(as: 'vista')]
    public ?string $vista = null;

    public int $cuadros = self::CUADROS;

    /** @var list<int>|null */
    private ?array $idsPorImprimir = null;

    /** @var list<int>|null */
    private ?array $idsPendientes = null;

    /**
     * Lo de paraCargar() por días y bodega, para no buscarlo varias veces en la misma petición.
     *
     * @var array<string, array{bodega: int|null, nombre: string, dias: list<string>, hasta: string|null, cuantas: int, total: float, porDia: list<array{dia: string, titulo: string, cuantas: int, total: float}>}>
     */
    private array $yaCalculadas = [];

    protected function getHeaderActions(): array
    {
        return [
            // En la pestaña Por imprimir: todos los que se ven (con filtros y búsqueda), uno por página.
            Action::make('imprimirTodos')
                ->label(fn (): string => 'Imprimir todos ('.count($this->idsPorImprimir()).')')
                ->icon(Heroicon::OutlinedPrinter)
                ->color('info')
                ->requiresConfirmation()
                ->modalIcon(Heroicon::OutlinedPrinter)
                ->modalHeading(fn (): string => 'Imprimir '.count($this->idsPorImprimir()).' factura(s)')
                ->modalDescription('Salen como original, una por página, con los filtros y la búsqueda que tiene puestos. Si otra persona ya imprimió alguno, se salta.')
                ->modalSubmitActionLabel('Imprimir')
                ->fillForm(fn (): array => ['papel' => FormatoFactura::delUsuario(Acceso::usuario())->value])
                ->schema([ImprimirPedidoAction::campoPapel()])
                ->action(function (array $data): void {
                    ImprimirPedidoAction::ejecutar($this, $this->idsPorImprimir(), ModoImpresion::Originales, ImprimirPedidoAction::papel($data['papel'] ?? null));
                })
                ->visible(fn (): bool => $this->activeTab === EstadoPedido::PorImprimir->value && $this->idsPorImprimir() !== []),
            // En la pestaña Pendiente: todos los que se ven (con filtros y búsqueda), del más viejo al más nuevo.
            ValidarVariosAction::modal(Action::make('validarTodos'))
                ->label(fn (): string => 'Validar todos ('.count($this->idsPendientes()).')')
                ->icon(Heroicon::OutlinedClipboardDocumentCheck)
                ->color('success')
                ->modalHeading(fn (): string => 'Validar los '.count($this->idsPendientes()).' pendientes')
                ->modalDescription(fn (): string => ValidarVariosAction::descripcion($this->idsPendientes()).' Van los que se ven, con los filtros y la búsqueda que tiene puestos.')
                ->schema(fn (): array => ValidarVariosAction::schema($this->idsPendientes()))
                ->action(function (array $data): void {
                    ValidarVariosAction::ejecutar($this->idsPendientes(), $data);
                })
                ->visible(fn (): bool => $this->activeTab === EstadoPedido::Pendiente->value && $this->idsPendientes() !== []),
            CreateAction::make()->label('Nueva preventa'),
        ];
    }

    public function content(Schema $schema): Schema
    {
        return $schema
            ->components([
                $this->getTabsContentComponent(),
                View::make('filament.pedidos.por-dia')
                    ->viewData(fn (): array => $this->datosPorDia())
                    ->visible(fn (): bool => $this->tieneVistaPorDia()),
                RenderHook::make(PanelsRenderHook::RESOURCE_PAGES_LIST_RECORDS_TABLE_BEFORE),
                EmbeddedTable::make()
                    ->hidden(fn (): bool => $this->esPorDia()),
                RenderHook::make(PanelsRenderHook::RESOURCE_PAGES_LIST_RECORDS_TABLE_AFTER),
            ]);
    }

    /**
     * Al cambiar de pestaña vuelve a verse por día, con los primeros cuadros,
     * y se quita "Facturada el" (solo tiene sentido en Impresos).
     */
    public function updatedActiveTab(): void
    {
        parent::updatedActiveTab();

        $this->vista = null;
        $this->cuadros = self::CUADROS;

        if (isset($this->tableFilters['facturada'])) {
            unset($this->tableFilters['facturada']);
            $this->updatedTableFilters();
        }
    }

    public function masDias(): void
    {
        $this->cuadros = min($this->cuadros + self::CUADROS, 366);
    }

    /**
     * "Ver" de un cuadro: las tarjetas de ese día (y esa bodega), con los
     * filtros de siempre (Fecha en Por imprimir, Facturada el en Impreso y Cargado).
     */
    public function verDia(string $dia, int|string $bodega): void
    {
        $dia = Periodo::dia($dia);

        if ($dia === null || ! $this->tieneVistaPorDia()) {
            return;
        }

        $filtros = $this->tableFilters ?? [];

        if (in_array($this->activeTab, [EstadoPedido::Impreso->value, self::CARGADO], true)) {
            $filtros['facturada'] = ['el' => $dia];
        } else {
            $filtros['fecha'] = ['desde' => $dia, 'hasta' => $dia];
        }

        if (Acceso::veTodo() && is_numeric($bodega)) {
            $filtros['bodega_id'] = ['value' => (string) $bodega];
        }

        $this->tableFilters = $filtros;
        $this->vista = 'lista';
        $this->updatedTableFilters();
    }

    /**
     * "Imprimir las N" de un cuadro de Por imprimir: todos los de ese día y
     * esa bodega, como original, una por página, después de ver cómo van a salir.
     */
    public function imprimirDiaAction(): Action
    {
        return ImprimirPedidoAction::conVistaPreviaDeVarias(
            Action::make('imprimirDia')
                ->visible(fn (): bool => Acceso::puede('Validar:Pedido'))
                ->modalHeading(fn (array $arguments): string => $this->tituloDelDia($arguments))
                ->fillForm(fn (array $arguments): array => [
                    'ids'   => implode(',', $this->idsDelDia($arguments)),
                    'papel' => FormatoFactura::para(null, Bodega::query()->find((int) ($arguments['bodega'] ?? 0)))->value,
                ])
                ->action(function (array $data, array $arguments): void {
                    ImprimirPedidoAction::ejecutar($this, $this->idsDelDia($arguments), ModoImpresion::Originales, ImprimirPedidoAction::papel($data['papel'] ?? null));
                }),
        );
    }

    /**
     * "Validar las N" de un cuadro de Pendiente: todos los de ese día y esa
     * bodega, del más viejo al más nuevo, como si se validara cada uno sin
     * cambiar nada (ValidarVariosAction).
     */
    public function validarDiaAction(): Action
    {
        return ValidarVariosAction::modal(Action::make('validarDia'))
            ->visible(fn (): bool => Acceso::puede('Validar:Pedido'))
            ->modalHeading(fn (array $arguments): string => $this->tituloDeValidar($arguments))
            ->modalDescription(fn (array $arguments): string => ValidarVariosAction::descripcion($this->pendientesDelDia($arguments)))
            ->schema(fn (array $arguments): array => ValidarVariosAction::schema($this->pendientesDelDia($arguments)))
            ->action(function (array $data, array $arguments): void {
                ValidarVariosAction::ejecutar($this->pendientesDelDia($arguments), $data);
            });
    }

    /**
     * "Hoja de alisto" de un cuadro de Pendiente: lo que se saca de la bodega
     * para los pedidos de ese día, para apartarlo antes de validar.
     */
    public function alistoDiaAction(): Action
    {
        return HojaDeAlistoAction::modal(Action::make('alistoDia'))
            ->visible(fn (): bool => Acceso::puede('Validar:Pedido'))
            ->modalHeading(fn (array $arguments): string => (count($this->pendientesDelDia($arguments)) === 1 ? 'Hoja de alisto del pedido' : 'Hoja de alisto de los '.count($this->pendientesDelDia($arguments)).' pedidos').$this->delDia($arguments))
            ->modalContent(fn (array $arguments): Hoja => HojaDeAlistoAction::vista($this->pendientesDelDia($arguments)));
    }

    /**
     * "Resumen del día" de un cuadro de Impresos: la hoja con las facturas de
     * ese día y su total, para imprimir o guardar como PDF.
     */
    public function resumenDiaAction(): Action
    {
        return Action::make('resumenDia')
            ->visible(fn (): bool => Gate::allows('viewAny', Factura::class))
            ->modalHeading(fn (array $arguments): string => 'Facturas del '.ResumenDelDia::fechaLarga(Periodo::dia($arguments['dia'] ?? null) ?? today()->toDateString()))
            ->modalDescription('Vista de impresión. Para el PDF, en la ventana de imprimir escoja «Guardar como PDF».')
            ->modalIcon(Heroicon::OutlinedDocumentText)
            ->modalWidth(Width::SevenExtraLarge)
            ->modalContent(fn (array $arguments): Hoja => view('filament.reportes.vista-impresion', [
                'url' => route('facturas.resumen-dia', [
                    'dia'      => Periodo::dia($arguments['dia'] ?? null),
                    'bodega'   => is_numeric($arguments['bodega'] ?? null) ? (int) $arguments['bodega'] : null,
                    'embebido' => 1,
                ]),
                'marco' => VistaImpresion::MARCO,
            ]))
            ->modalSubmitAction(false)
            ->modalCancelActionLabel('Cerrar')
            ->extraModalFooterActions([
                Action::make('imprimirResumen')
                    ->label('Imprimir o guardar PDF')
                    ->icon(Heroicon::OutlinedPrinter)
                    ->alpineClickHandler("document.getElementById('".VistaImpresion::MARCO."')?.contentWindow?.print()"),
            ]);
    }

    /**
     * "Cargar camión" de Impresos, con uno o varios días de una bodega
     * (argumentos dias y bodega): cuántas facturas lleva de cada día (las
     * que todavía se pueden cargar), quién sale y la fecha, y
     * Guardar carga, que la arma con todas y abre la carga con lo que lleva.
     * "Escoger una por una" abre Nueva carga con esos días marcados.
     */
    public function cargarDiasAction(): Action
    {
        return Action::make('cargarDias')
            ->visible(fn (): bool => Gate::allows('create', Carga::class))
            ->modalHeading(fn (array $arguments): string => $this->tituloDeLaCarga($arguments))
            ->modalIcon(Heroicon::OutlinedTruck)
            ->modalWidth(Width::TwoExtraLarge)
            ->fillForm(fn (array $arguments): array => [
                'fecha'   => max(today()->toDateString(), $this->paraCargar($arguments)['hasta'] ?? today()->toDateString()),
                'cambios' => $this->paraCargar($arguments)['marcados'],
            ])
            ->schema(function (array $arguments): array {
                $carga = $this->paraCargar($arguments);

                return [
                    View::make('filament.pedidos.cargar-dias')->viewData(['carga' => $carga]),
                    Grid::make(['default' => 1, 'sm' => 2])
                        ->visible($carga['cuantas'] > 0)
                        ->schema([
                            Select::make('repartidor_id')
                                ->label('Quién sale')
                                ->options(CargaForm::repartidores($carga['bodega']))
                                ->searchable()
                                ->required()
                                ->helperText('Lleva la mercadería y regresa con el dinero para la liquidación.'),
                            DatePicker::make('fecha')
                                ->label('Fecha de salida')
                                ->minDate($carga['hasta'])
                                ->maxDate(today()->addDays(2)->toDateString())
                                ->required(),
                        ]),
                    CheckboxList::make('cambios')
                        ->label('Cambios por entregar que lleva')
                        ->helperText('Vienen marcados los de las rutas de estas facturas. Salen de la bodega al guardar la carga y van en la hoja de carga.')
                        ->options($carga['cambios'])
                        ->descriptions($carga['notas'])
                        ->visible($carga['cuantas'] > 0 && $carga['cambios'] !== [])
                        ->bulkToggleable(),
                ];
            })
            ->modalSubmitActionLabel('Guardar carga')
            ->modalSubmitAction(fn (Action $action, array $arguments): Action => $action->visible($this->paraCargar($arguments)['cuantas'] > 0))
            ->extraModalFooterActions(fn (array $arguments): array => [
                Action::make('escogerUnaPorUna')
                    ->label('Escoger una por una')
                    ->color('gray')
                    ->icon(Heroicon::OutlinedListBullet)
                    ->visible($this->paraCargar($arguments)['cuantas'] > 0)
                    ->url(CargaResource::getUrl('create', [
                        'dias'   => implode(',', $this->paraCargar($arguments)['dias']),
                        'bodega' => $this->paraCargar($arguments)['bodega'],
                    ])),
            ])
            ->action(function (array $data, array $arguments, Action $action): void {
                $usuario = Acceso::usuario() ?? abort(403);
                $carga = $this->paraCargar($arguments);
                $fecha = max(Periodo::dia($data['fecha'] ?? null) ?? today()->toDateString(), $carga['hasta'] ?? today()->toDateString());
                $facturas = $carga['bodega'] === null ? [] : CargaForm::deLosDias($carga['bodega'], $carga['dias'], $fecha)->modelKeys();

                if ($facturas === []) {
                    Notification::make()->title('Esos días ya no tienen facturas para cargar.')->warning()->send();
                    $action->halt();
                }

                try {
                    $nueva = app(ArmarCarga::class)->ejecutar(null, [
                        'bodega_id'     => (int) $carga['bodega'],
                        'fecha'         => $fecha,
                        'repartidor_id' => (int) ($data['repartidor_id'] ?? 0),
                        'facturas'      => array_map(intval(...), $facturas),
                        'cambios'       => CargaForm::ids($data['cambios'] ?? null),
                    ], $usuario);
                } catch (ReglaNegocioException $e) {
                    Notification::make()->title($e->getMessage())->danger()->send();
                    $action->halt();

                    return;
                }

                Notification::make()
                    ->title("Carga {$nueva->numero()} lista: sale con ".lempiras($nueva->total_carga))
                    ->body('Abajo está lo que lleva, producto por producto. Imprima la hoja de carga para ir marcando.')
                    ->success()
                    ->send();

                $this->redirect(CargaResource::getUrl('view', ['record' => $nueva]));
            });
    }

    /**
     * Bodega y administración no llevan texto: las pestañas ya dicen cuántos
     * hay de cada uno. El vendedor no tiene pestañas: se le dice por qué sus
     * pedidos desaparecen al validarlos.
     */
    public function getSubheading(): ?string
    {
        if (! Acceso::esVendedor()) {
            return null;
        }

        $pendientes = PedidoResource::getEloquentQuery()->where('estado', EstadoPedido::Pendiente);
        $cuantos = (clone $pendientes)->count();
        $monto = (float) (clone $pendientes)->sum('total');

        return "Sus pedidos por validar: {$cuantos} · ".lempiras($monto).'. Cuando bodega los valida ya no salen aquí.';
    }

    /**
     * @return array<string|int, Tab>
     */
    public function getTabs(): array
    {
        // El vendedor solo tiene pendientes: sin pestañas.
        if (Acceso::esVendedor()) {
            return [];
        }

        $tabs = [];

        foreach (EstadoPedido::cases() as $estado) {
            $filtro = fn (Builder $query): Builder => $estado === EstadoPedido::Impreso
                ? self::impresos($query, cargados: false)
                : $query->where('estado', $estado);
            $cantidad = $filtro(PedidoResource::getEloquentQuery())->count();

            $tabs[$estado->value] = Tab::make($estado->getLabel())
                ->badge($cantidad > 0 && $estado !== EstadoPedido::Anulado ? (string) $cantidad : null)
                ->badgeColor($estado->getColor())
                ->modifyQueryUsing($filtro);

            // Después de Impreso, los que ya van en una carga.
            if ($estado === EstadoPedido::Impreso) {
                $cargados = self::impresos(PedidoResource::getEloquentQuery(), cargados: true)->count();

                $tabs[self::CARGADO] = Tab::make('Cargado')
                    ->badge($cargados > 0 ? (string) $cargados : null)
                    ->badgeColor('primary')
                    ->modifyQueryUsing(fn (Builder $query): Builder => self::impresos($query, cargados: true));
            }
        }

        $tabs['todos'] = Tab::make('Todos');

        return $tabs;
    }

    /**
     * Los impresos que faltan de cargar en el camión (Impreso) o los que ya
     * van en una carga, ya se entregaron o su día ya salió en su ruta
     * (Cargado), con la misma regla de la carga (FacturasParaCargar). Las
     * ventas en bodega que el cliente se llevó no van en ninguna de las dos:
     * no pasan por el camión (se ven en Todos, con "Se lo llevó").
     *
     * @param Builder<Pedido> $query
     *
     * @return Builder<Pedido>
     */
    public static function impresos(Builder $query, bool $cargados): Builder
    {
        $paraCargar = FacturasParaCargar::paraCargar()->select('facturas.pedido_id');

        return $query
            ->where('pedidos.estado', EstadoPedido::Impreso)
            ->where('pedidos.se_lo_lleva', false)
            ->when(
                $cargados,
                fn (Builder $q): Builder => $q->whereNotIn('pedidos.id', $paraCargar),
                fn (Builder $q): Builder => $q->whereIn('pedidos.id', $paraCargar),
            );
    }

    /**
     * Las pestañas cuentan pedidos y Filament las calcula al principio de cada
     * petición, antes de validar, corregir o imprimir. Se recalculan justo
     * antes de dibujar para que los números queden al día.
     */
    public function rendering(): void
    {
        unset($this->cachedTabs);
        $this->idsPorImprimir = null;
        $this->idsPendientes = null;
    }

    /**
     * Botones de los avisos ("Imprimir" al validar o corregir, "No salió").
     *
     * @param array<int|string, mixed> $ids
     */
    #[On(ImprimirPedidoAction::PEDIR)]
    public function imprimirPedidos(array $ids = [], string $modo = 'originales', ?string $papel = null): void
    {
        ImprimirPedidoAction::desdeAviso($this, $ids, $modo, $papel);
    }

    /**
     * Después de imprimir (resources/views/filament/imprimir-hoja.blade.php):
     * se vuelve a dibujar para que los impresos pasen a su pestaña.
     */
    #[On('hoja-impresa')]
    public function hojaImpresa(): void
    {
        $this->idsPorImprimir = null;
    }

    /**
     * Para resources/views/filament/pedidos/por-dia.blade.php.
     *
     * @return array<string, mixed>
     */
    private function datosPorDia(): array
    {
        $usuario = Acceso::usuario();
        $porValidar = $this->activeTab === EstadoPedido::Pendiente->value;
        $cargados = $this->activeTab === self::CARGADO;
        $impresos = $cargados || $this->activeTab === EstadoPedido::Impreso->value;
        $porDia = $this->esPorDia();
        $cargar = $impresos && ! $cargados && $porDia && Gate::allows('create', Carga::class);
        $cuadros = ['dias' => [], 'hayMas' => false];

        // Impreso: los días que tienen facturas por cargar; Cargado: los que tienen cargadas.
        if ($porDia && $usuario !== null) {
            $cuadros = match (true) {
                $impresos   => DiasDeFacturacion::impresas($usuario, $this->cuadros, $cargados),
                $porValidar => DiasDeFacturacion::pendientes($usuario, $this->cuadros),
                default     => DiasDeFacturacion::porImprimir($usuario, $this->cuadros),
            };
        }

        return [
            'porDia'     => $porDia,
            'porValidar' => $porValidar,
            'impresos'   => $impresos,
            'cargados'   => $cargados,
            'dias'       => $cuadros['dias'],
            'hayMas'     => $cuadros['hayMas'],
            'veTodo'     => Acceso::veTodo(),
            'imprime'    => Acceso::puede('Validar:Pedido'),
            'resumen'    => Gate::allows('viewAny', Factura::class),
            'cargar'     => $cargar,
            'valida'     => Acceso::puede('Validar:Pedido'),
        ];
    }

    /**
     * Lo que lleva "Cargar camión" con los días escogidos (argumentos dias y
     * bodega; al encargado, siempre su bodega): los días que se pueden
     * cargar, cuántas facturas de cada uno y cuánto suman.
     *
     * @param array<string, mixed> $arguments
     *
     * @return array{bodega: int|null, nombre: string, dias: list<string>, hasta: string|null, cuantas: int, total: float, porDia: list<array{dia: string, titulo: string, cuantas: int, total: float}>, cambios: array<int, string>, notas: array<int, string>, marcados: list<string>}
     */
    private function paraCargar(array $arguments): array
    {
        $clave = md5((string) json_encode([$arguments['dias'] ?? null, $arguments['bodega'] ?? null]));

        return $this->yaCalculadas[$clave] ??= $this->calcularParaCargar($arguments);
    }

    /**
     * @param array<string, mixed> $arguments
     *
     * @return array{bodega: int|null, nombre: string, dias: list<string>, hasta: string|null, cuantas: int, total: float, porDia: list<array{dia: string, titulo: string, cuantas: int, total: float}>, cambios: array<int, string>, notas: array<int, string>, marcados: list<string>}
     */
    private function calcularParaCargar(array $arguments): array
    {
        $usuario = Acceso::usuario();
        $pedida = $arguments['bodega'] ?? null;
        $bodega = $usuario?->veTodasLasBodegas() ? (is_numeric($pedida) ? (int) $pedida : null) : $usuario?->bodega_id;
        $dias = [];

        foreach (is_array($arguments['dias'] ?? null) ? $arguments['dias'] : [] as $valor) {
            $dia = Periodo::dia($valor);

            if ($dia !== null) {
                $dias[$dia] = $dia;
            }
        }

        ksort($dias);
        $dias = array_values($dias);
        $facturas = $bodega === null ? new Collection : CargaForm::deLosDias($bodega, $dias);
        $porDia = [];

        foreach ($dias as $dia) {
            $delDia = $facturas->filter(fn (Factura $factura): bool => $factura->fecha_emision->toDateString() === $dia);
            $porDia[] = [
                'dia'     => $dia,
                'titulo'  => mb_ucfirst(Carbon::parse($dia)->translatedFormat('l d/m/Y')),
                'cuantas' => $delDia->count(),
                'total'   => round((float) $delDia->sum('total'), 2),
            ];
        }

        // Los cambios por entregar de la bodega; marcados, los de las rutas de esas facturas.
        $cambios = $bodega === null ? new Collection : Reposiciones::paraCarga($bodega);
        $marcados = $bodega === null || $facturas->isEmpty() ? [] : CargaForm::cambiosDeLasRutas($bodega, $facturas)->map(fn (Reposicion $cambio): string => (string) $cambio->id)->values()->all();

        return [
            'bodega'   => $bodega,
            'nombre'   => $bodega === null ? '' : (string) Bodega::query()->whereKey($bodega)->value('nombre'),
            'dias'     => $dias,
            'hasta'    => $dias === [] ? null : max($dias),
            'cuantas'  => $facturas->count(),
            'total'    => round((float) $facturas->sum('total'), 2),
            'porDia'   => $porDia,
            'cambios'  => $cambios->mapWithKeys(fn (Reposicion $cambio): array => [$cambio->id => $cambio->etiqueta()])->all(),
            'notas'    => $cambios->filter(fn (Reposicion $cambio): bool => $cambio->nota !== null)->mapWithKeys(fn (Reposicion $cambio): array => [$cambio->id => (string) $cambio->nota])->all(),
            'marcados' => $marcados,
        ];
    }

    /**
     * "Cargar camión · 3 días · OCOTEPEQUE".
     *
     * @param array<string, mixed> $arguments
     */
    private function tituloDeLaCarga(array $arguments): string
    {
        $carga = $this->paraCargar($arguments);
        $dias = count($carga['dias']);

        return 'Cargar camión'
            .($dias === 1 ? ' · '.mb_strtolower($carga['porDia'][0]['titulo']) : ' · '.$dias.' días')
            .(Acceso::veTodo() && $carga['nombre'] !== '' ? ' · '.$carga['nombre'] : '');
    }

    private function tieneVistaPorDia(): bool
    {
        return in_array($this->activeTab, self::POR_DIA, true);
    }

    private function esPorDia(): bool
    {
        return $this->tieneVistaPorDia() && $this->vista !== 'lista';
    }

    /**
     * Los pedidos de un cuadro de Por imprimir (argumentos dia y bodega).
     *
     * @param array<string, mixed> $arguments
     *
     * @return list<int>
     */
    private function idsDelDia(array $arguments): array
    {
        $usuario = Acceso::usuario();
        $dia = Periodo::dia($arguments['dia'] ?? null);
        $bodega = $arguments['bodega'] ?? null;

        if ($usuario === null || $dia === null || ! is_numeric($bodega)) {
            return [];
        }

        return DiasDeFacturacion::idsPorImprimir($usuario, $dia, (int) $bodega);
    }

    /**
     * "Imprimir las 12 facturas del martes 18 de noviembre de 2026" (y la
     * bodega, para administración).
     *
     * @param array<string, mixed> $arguments
     */
    private function tituloDelDia(array $arguments): string
    {
        $cuantas = count($this->idsDelDia($arguments));
        $dia = Periodo::dia($arguments['dia'] ?? null);
        $bodega = $arguments['bodega'] ?? null;

        return ($cuantas === 1 ? 'Imprimir la factura' : "Imprimir las {$cuantas} facturas")
            .($dia === null ? '' : ' del '.ResumenDelDia::fechaLarga($dia))
            .(Acceso::veTodo() && is_numeric($bodega) ? ' · '.(string) Bodega::query()->whereKey((int) $bodega)->value('nombre') : '');
    }

    /**
     * Los que están Por imprimir en la tabla (con filtros y búsqueda). La
     * tabla ya muestra solo lo de su bodega (o todo a administración), así
     * que basta con que pueda validar/imprimir.
     *
     * @return list<int>
     */
    private function idsPorImprimir(): array
    {
        if (! Acceso::puede('Validar:Pedido')) {
            return $this->idsPorImprimir = [];
        }

        return $this->idsPorImprimir ??= array_values(array_map(
            intval(...),
            ($this->getFilteredTableQuery() ?? PedidoResource::getEloquentQuery())
                ->where('pedidos.estado', EstadoPedido::PorImprimir)
                ->reorder()
                ->orderBy('pedidos.id')
                ->pluck('pedidos.id')
                ->all(),
        ));
    }

    /**
     * Los pendientes de la tabla (con filtros y búsqueda) que puede validar.
     *
     * @return list<int>
     */
    private function idsPendientes(): array
    {
        if (! Acceso::puede('Validar:Pedido')) {
            return $this->idsPendientes = [];
        }

        return $this->idsPendientes ??= array_values(array_map(
            intval(...),
            ($this->getFilteredTableQuery() ?? PedidoResource::getEloquentQuery())
                ->where('pedidos.estado', EstadoPedido::Pendiente)
                ->reorder()
                ->orderBy('pedidos.id')
                ->pluck('pedidos.id')
                ->all(),
        ));
    }

    /**
     * Los pendientes de un cuadro (argumentos dia y bodega).
     *
     * @param array<string, mixed> $arguments
     *
     * @return list<int>
     */
    private function pendientesDelDia(array $arguments): array
    {
        $usuario = Acceso::usuario();
        $dia = Periodo::dia($arguments['dia'] ?? null);
        $bodega = $arguments['bodega'] ?? null;

        if ($usuario === null || $dia === null || ! is_numeric($bodega)) {
            return [];
        }

        return DiasDeFacturacion::idsPendientes($usuario, $dia, (int) $bodega);
    }

    /**
     * "Validar los 45 pedidos del jueves 26 de noviembre de 2026" (y la
     * bodega, para administración).
     *
     * @param array<string, mixed> $arguments
     */
    private function tituloDeValidar(array $arguments): string
    {
        $cuantos = count($this->pendientesDelDia($arguments));

        return ($cuantos === 1 ? 'Validar el pedido' : "Validar los {$cuantos} pedidos").$this->delDia($arguments);
    }

    /**
     * " del jueves 26 de noviembre de 2026" (y la bodega, para administración).
     *
     * @param array<string, mixed> $arguments
     */
    private function delDia(array $arguments): string
    {
        $dia = Periodo::dia($arguments['dia'] ?? null);
        $bodega = $arguments['bodega'] ?? null;

        return ($dia === null ? '' : ' del '.ResumenDelDia::fechaLarga($dia))
            .(Acceso::veTodo() && is_numeric($bodega) ? ' · '.(string) Bodega::query()->whereKey((int) $bodega)->value('nombre') : '');
    }
}
