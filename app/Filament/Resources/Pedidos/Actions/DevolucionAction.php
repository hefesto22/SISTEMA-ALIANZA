<?php

declare(strict_types=1);

namespace App\Filament\Resources\Pedidos\Actions;

use App\Domain\Cargas\HojaDeCarga;
use App\Domain\Devoluciones\RegistrarDevolucion;
use App\Domain\Exceptions\ReglaNegocioException;
use App\Enums\EntregaFactura;
use App\Enums\EstadoPedido;
use App\Enums\TipoDevolucion;
use App\Filament\Resources\Cargas\Pages\ListCargas;
use App\Filament\Support\Acceso;
use App\Models\Carga;
use App\Models\CargaFactura;
use App\Models\Configuracion;
use App\Models\Devolucion;
use App\Models\Pedido;
use Filament\Actions\Action;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Repeater\TableColumn;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\ToggleButtons;
use Filament\Infolists\Components\TextEntry;
use Filament\Notifications\Notification;
use Filament\Schemas\Components\FusedGroup;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Support\Enums\FontWeight;
use Filament\Support\Enums\Width;
use Filament\Support\Icons\Heroicon;

/**
 * "Devolución" en un pedido ya entregado (impreso): de cada producto,
 * cuántas regresan buenas (vuelven a la bodega) y cuántas dañadas (quedan
 * marcadas), y si se le cambia por lo mismo o se le da crédito (con nota de
 * crédito si está encendida en Configuración). Lo hace RegistrarDevolucion.
 *
 * En un cambio se dice cómo se le entrega: se le lleva en la próxima carga
 * (queda Por entregar a su nombre) o ya se le dio con producto del pedido
 * de otro cliente (se escoge ese pedido: a ese otro se le queda debiendo).
 *
 * Sale en Ver pedido; por cada factura, en Ver carga y en Liquidar
 * (deLaCarga()); y en la lista de cargas, al lado de Liquidar (enLaLista():
 * se escoge la factura y abre esta misma con deUnaCarga()): lo que el
 * cliente regresó con el que salió en la ruta.
 */
final class DevolucionAction
{
    public const PROXIMA_CARGA = 'proxima';

    public const DE_OTRO = 'otro';

    public static function make(): Action
    {
        return Action::make('devolucion')
            ->label('Devolución')
            ->icon(Heroicon::OutlinedArrowUturnLeft)
            ->color('gray')
            ->visible(fn (mixed $record): bool => $record instanceof Pedido && $record->estado === EstadoPedido::Impreso && (Acceso::usuario()?->can('create', Devolucion::class) ?? false))
            ->modalHeading(fn (Pedido $record): string => "Devolución · {$record->numero} · {$record->cliente->nombre}")
            ->modalDescription('Anote lo que regresó de cada producto: en cajas (CJ) o en unidades sueltas (UN). Lo bueno regresa a la bodega; lo dañado no regresa: es pérdida y sale en el reporte de producto dañado.')
            ->modalWidth(Width::FiveExtraLarge)
            ->modalSubmitActionLabel('Registrar devolución')
            ->fillForm(fn (Pedido $record): array => ['tipo' => TipoDevolucion::Cambio->value, 'entrega' => self::PROXIMA_CARGA, 'lineas' => self::lineas($record)])
            ->schema([
                ToggleButtons::make('tipo')
                    ->label('Qué se le da al cliente')
                    ->options(TipoDevolucion::class)
                    ->icons([
                        TipoDevolucion::Cambio->value  => Heroicon::OutlinedArrowsRightLeft,
                        TipoDevolucion::Credito->value => Heroicon::OutlinedBanknotes,
                    ])
                    ->inline()
                    ->required()
                    ->live()
                    ->helperText(fn (): string => Configuracion::actual()->notas_credito
                        ? 'Con crédito se emite la nota de crédito (CAI tipo 07) con los datos de la factura.'
                        : 'Con crédito queda como crédito interno: la nota de crédito se hace a mano (se enciende en Configuración).'),
                ToggleButtons::make('entrega')
                    ->label('Cómo se le entrega el cambio')
                    ->options([
                        self::PROXIMA_CARGA => 'Se le lleva en la próxima carga',
                        self::DE_OTRO       => 'Ya se le dio con producto de otro cliente',
                    ])
                    ->icons([
                        self::PROXIMA_CARGA => Heroicon::OutlinedTruck,
                        self::DE_OTRO       => Heroicon::OutlinedUserGroup,
                    ])
                    ->inline()
                    ->required(fn (Get $get): bool => self::esCambio($get))
                    ->visible(fn (Get $get): bool => self::esCambio($get))
                    ->live()
                    ->helperText(fn (Get $get): string => $get('entrega') === self::DE_OTRO
                        ? 'Lo que se le dio era del pedido de otro cliente: a ese otro se le queda debiendo; la orden de cambio (CAM) sale a su nombre y se le lleva en la próxima carga.'
                        : 'Se crea su orden de cambio (CAM): sale en Pedidos → Por imprimir; impresa, se sube a la próxima carga y sale de la bodega en ese momento.'),
                Select::make('se_le_debe_a')
                    ->label('Pedido del cliente al que se le quedó debiendo')
                    ->options(fn (Pedido $record): array => self::paraDeberle($record))
                    ->searchable()
                    ->required(fn (Get $get): bool => self::esCambio($get) && $get('entrega') === self::DE_OTRO)
                    ->visible(fn (Get $get): bool => self::esCambio($get) && $get('entrega') === self::DE_OTRO)
                    ->helperText('Los pedidos entregados de la bodega de los últimos '.RegistrarDevolucion::DIAS_PARA_DEBERLE.' días.'),
                Repeater::make('lineas')
                    ->label('Lo que regresa')
                    ->table([
                        TableColumn::make('Producto'),
                        TableColumn::make('Salió')->width('150px'),
                        TableColumn::make('Buenas (regresan a la bodega)')->width('230px'),
                        TableColumn::make('Dañadas (pérdida)')->width('230px'),
                    ])
                    ->schema([
                        Hidden::make('id'),
                        Hidden::make('producto')->dehydrated(false),
                        Hidden::make('codigo')->dehydrated(false),
                        Hidden::make('salio')->dehydrated(false),
                        Hidden::make('puede')->dehydrated(false),
                        Hidden::make('sigla')->dehydrated(false),
                        Hidden::make('base')->dehydrated(false),
                        Hidden::make('caja')->dehydrated(false),
                        TextEntry::make('ver_producto')
                            ->hiddenLabel()
                            ->state(fn (Get $get): string => (string) $get('producto'))
                            ->helperText(fn (Get $get): string => (string) $get('codigo'))
                            ->weight(FontWeight::SemiBold),
                        TextEntry::make('ver_salio')
                            ->hiddenLabel()
                            ->state(fn (Get $get): string => (string) $get('salio'))
                            ->helperText(fn (Get $get): string => (string) $get('puede')),
                        self::cantidades('buenas'),
                        self::cantidades('danadas'),
                    ])
                    ->addable(false)
                    ->deletable(false)
                    ->reorderable(false),
                TextInput::make('motivo')
                    ->label('Motivo')
                    ->placeholder('Ej. venían golpeados')
                    ->maxLength(200),
            ])
            ->action(function (array $data, Pedido $record): void {
                $usuario = Acceso::usuario();

                if ($usuario === null) {
                    return;
                }

                $lineas = [];

                foreach ((array) ($data['lineas'] ?? []) as $fila) {
                    if (is_array($fila) && is_numeric($fila['id'] ?? null)) {
                        $lineas[(int) $fila['id']] = [
                            'buenas'     => is_numeric($fila['buenas'] ?? null) ? (int) $fila['buenas'] : 0,
                            'danadas'    => is_numeric($fila['danadas'] ?? null) ? (int) $fila['danadas'] : 0,
                            'buenas_un'  => is_numeric($fila['buenas_un'] ?? null) ? (int) $fila['buenas_un'] : 0,
                            'danadas_un' => is_numeric($fila['danadas_un'] ?? null) ? (int) $fila['danadas_un'] : 0,
                        ];
                    }
                }

                $tipo = self::tipo($data['tipo'] ?? null);
                $otro = ($data['entrega'] ?? null) === self::DE_OTRO && is_numeric($data['se_le_debe_a'] ?? null)
                    ? Pedido::query()->find((int) $data['se_le_debe_a'])
                    : null;

                if ($tipo === TipoDevolucion::Cambio && ($data['entrega'] ?? null) === self::DE_OTRO && $otro === null) {
                    Notification::make()->title('Escoja el pedido del cliente al que se le quedó debiendo.')->danger()->send();

                    return;
                }

                try {
                    $devolucion = app(RegistrarDevolucion::class)->ejecutar($record, $tipo, $lineas, $usuario, is_string($data['motivo'] ?? null) ? $data['motivo'] : null, $otro);
                } catch (ReglaNegocioException $e) {
                    Notification::make()->title($e->getMessage())->danger()->send();

                    return;
                }

                Notification::make()
                    ->title("Devolución {$devolucion->numero} registrada")
                    ->body(match (true) {
                        $devolucion->conNota()                        => "Nota de crédito N° {$devolucion->nota_numero} por ".lempiras($devolucion->total).'.',
                        $devolucion->tipo === TipoDevolucion::Credito => 'Crédito interno por '.lempiras($devolucion->total).'.',
                        $otro !== null                                => "Orden de cambio {$devolucion->reposicion?->numero} a nombre de {$otro->cliente->nombre} (se le quedó debiendo): imprímala en Pedidos → Por imprimir y súbala a la próxima carga.",
                        default                                       => "Orden de cambio {$devolucion->reposicion?->numero}: imprímala en Pedidos → Por imprimir; impresa, se sube a la próxima carga.",
                    })
                    ->success()
                    ->actions([
                        Action::make('imprimirDevolucion')
                            ->label($devolucion->conNota() ? 'Imprimir nota de crédito' : 'Imprimir constancia')
                            ->url(route('devoluciones.imprimir', $devolucion), shouldOpenInNewTab: true),
                    ])
                    ->persistent()
                    ->send();
            });
    }

    /**
     * La misma, para una factura de la carga (argumento "factura": su id):
     * en Ver carga y en Liquidar, al lado de cada factura. Solo de las
     * facturas que lleva esa carga.
     */
    public static function deLaCarga(Carga $carga): Action
    {
        return self::make()
            ->record(fn (array $arguments): ?Pedido => self::pedidoDe($carga, $arguments));
    }

    /**
     * La misma, desde la lista de cargas (argumentos "carga" y "factura"):
     * solo de una carga en ruta que el usuario ve y de una factura que lleva.
     */
    public static function deUnaCarga(): Action
    {
        return self::make()
            ->record(function (array $arguments): ?Pedido {
                $usuario = Acceso::usuario();
                $carga = $usuario !== null && is_numeric($arguments['carga'] ?? null)
                    ? Carga::query()->visiblesPara($usuario)->whereKey((int) $arguments['carga'])->first()
                    : null;

                return $carga instanceof Carga && $carga->enRuta() ? self::pedidoDe($carga, $arguments) : null;
            });
    }

    /**
     * "Devolución" en la lista de cargas, al lado de Liquidar (solo en ruta):
     * se escoge la factura del cliente que devolvió (si lleva una sola, va
     * directo) y se abre el formulario de la devolución de esa factura
     * ("devolucion" de la página, deUnaCarga()).
     */
    public static function enLaLista(): Action
    {
        return Action::make('devolver')
            ->label('Devolución')
            ->icon(Heroicon::OutlinedArrowUturnLeft)
            ->color('gray')
            ->button()
            ->size('sm')
            ->visible(fn (Carga $record): bool => $record->enRuta()
                && (Acceso::usuario()?->can('create', Devolucion::class) ?? false)
                && self::facturasDe($record) !== [])
            ->modalHeading(fn (Carga $record): string => 'Devolución · '.$record->numero())
            ->modalDescription('Escoja la factura del cliente que devolvió.')
            ->modalWidth(Width::Large)
            ->modalSubmitActionLabel('Siguiente')
            ->modalHidden(fn (Carga $record): bool => count(self::facturasDe($record)) === 1)
            ->fillForm(function (Carga $record): array {
                $facturas = self::facturasDe($record);

                return ['factura' => count($facturas) === 1 ? array_key_first($facturas) : null];
            })
            ->schema([
                Select::make('factura')
                    ->label('Factura')
                    ->options(fn (Carga $record): array => self::facturasDe($record))
                    ->searchable()
                    ->required(),
            ])
            ->action(function (Carga $record, array $data, ListCargas $livewire): void {
                $factura = is_numeric($data['factura'] ?? null) ? (int) $data['factura'] : null;

                if ($factura === null || ! array_key_exists($factura, self::facturasDe($record))) {
                    Notification::make()->title('Esa factura ya no va en la carga.')->warning()->send();

                    return;
                }

                $livewire->replaceMountedAction('devolucion', ['carga' => $record->id, 'factura' => $factura]);
            });
    }

    /**
     * Las facturas que lleva la carga y se pueden devolver (en ruta, vigentes,
     * de un pedido impreso): [factura_id => "PULPERIA LA ROCA · Fact. 1234 · L. 500.00"].
     *
     * @return array<int, string>
     */
    public static function facturasDe(Carga $carga): array
    {
        $opciones = [];

        foreach ($carga->renglones()->where('estado', EntregaFactura::EnRuta->value)->with('factura.pedido.cliente')->get() as $renglon) {
            $factura = $renglon->factura;

            if ($factura->estaAnulada() || $factura->pedido->estado !== EstadoPedido::Impreso) {
                continue;
            }

            $opciones[$factura->id] = implode(' · ', [$factura->pedido->cliente->nombre, 'Fact. '.HojaDeCarga::corto($factura->numero), lempiras($factura->total)]);
        }

        return $opciones;
    }

    /**
     * El pedido de la factura (argumento "factura") que lleva la carga.
     *
     * @param array<string, mixed> $arguments
     */
    private static function pedidoDe(Carga $carga, array $arguments): ?Pedido
    {
        $renglon = is_numeric($arguments['factura'] ?? null)
            ? $carga->renglones()->where('factura_id', (int) $arguments['factura'])->with('factura.pedido')->first()
            : null;

        return $renglon instanceof CargaFactura ? $renglon->factura->pedido : null;
    }

    /**
     * Lo que se escogió en "Qué se le da al cliente": el campo entrega el
     * enum (TipoDevolucion), no el texto.
     */
    private static function tipo(mixed $valor): TipoDevolucion
    {
        if ($valor instanceof TipoDevolucion) {
            return $valor;
        }

        return TipoDevolucion::tryFrom(is_string($valor) ? $valor : '') ?? TipoDevolucion::Cambio;
    }

    /**
     * Si en el formulario se escogió cambio.
     */
    private static function esCambio(Get $get): bool
    {
        $tipo = $get('tipo');

        return ($tipo instanceof TipoDevolucion ? $tipo->value : $tipo) === TipoDevolucion::Cambio->value;
    }

    /**
     * Los pedidos a cuyo cliente se le puede quedar debiendo el cambio:
     * [id => "PULPERIA LA BENDICION · PED-000120 · CO22 · 18/11"].
     *
     * @return array<int, string>
     */
    private static function paraDeberle(Pedido $pedido): array
    {
        $opciones = [];

        foreach (RegistrarDevolucion::paraDeberle($pedido)->with(['cliente', 'ruta'])->limit(300)->get() as $otro) {
            $opciones[$otro->id] = implode(' · ', array_filter([$otro->cliente->nombre, $otro->numero, $otro->ruta?->codigo(), $otro->fecha->format('d/m')]));
        }

        return $opciones;
    }

    /**
     * Las líneas que salieron y todavía se pueden devolver, para el
     * formulario: el producto, en qué salió ("1 CJ12"), cuánto queda por
     * devolver ("son 12 UN"; "ya devolvió 3 UN · quedan 9 UN") y en qué se
     * anota: la presentación de la línea (sigla) y, de una caja, las
     * unidades sueltas (base).
     *
     * @return list<array{id: int, producto: string, codigo: string, salio: string, puede: string, sigla: string, base: string, caja: bool, buenas: null, danadas: null, buenas_un: null, danadas_un: null}>
     */
    public static function lineas(Pedido $pedido): array
    {
        $devueltas = RegistrarDevolucion::devueltas($pedido);
        $filas = [];

        foreach ($pedido->items()->with(['producto.presentaciones.unidadMedida', 'presentacion.unidadMedida'])->orderBy('id')->get() as $item) {
            $puede = RegistrarDevolucion::puede($item, $devueltas);

            if ($puede <= 0) {
                continue;
            }

            $sigla = $item->presentacion->sigla();
            $sueltas = RegistrarDevolucion::sueltas($item);
            $contenido = max(1, $item->presentacion->contenido);
            $devuelto = $devueltas[$item->id] ?? 0;
            // Sin base (o en unidades), lo que queda se cuenta en la presentación de la línea.
            [$cuantas, $ya, $en] = $sueltas === null && $contenido > 1
                ? [intdiv($puede, $contenido), intdiv($devuelto, $contenido), $sigla]
                : [$puede, $devuelto, ($sueltas ?? $item->presentacion)->sigla()];

            $filas[] = [
                'id'         => $item->id,
                'producto'   => $item->producto->descripcion.($item->bonificacion ? ' (regalo)' : ''),
                'codigo'     => (string) $item->producto->codigo,
                'salio'      => number_format($item->cantidadFacturable()).' '.$sigla,
                'puede'      => $ya > 0 ? "ya devolvió {$ya} {$en} · quedan {$cuantas} {$en}" : ($sueltas !== null ? "son {$cuantas} {$en}" : ''),
                'sigla'      => $sigla,
                'base'       => $sueltas?->sigla() ?? '',
                'caja'       => $sueltas !== null,
                'buenas'     => null,
                'danadas'    => null,
                'buenas_un'  => null,
                'danadas_un' => null,
            ];
        }

        return $filas;
    }

    /**
     * Buenas o dañadas de una línea: en su presentación (CJ12, UN…) y, si es
     * una caja, también unidades sueltas (UN), juntas en la misma celda.
     */
    private static function cantidades(string $campo): FusedGroup
    {
        return FusedGroup::make([
            TextInput::make($campo)
                ->hiddenLabel()
                ->numeric()
                ->integer()
                ->minValue(0)
                ->placeholder('0')
                ->suffix(fn (Get $get): string => (string) $get('sigla')),
            TextInput::make($campo.'_un')
                ->hiddenLabel()
                ->numeric()
                ->integer()
                ->minValue(0)
                ->placeholder('0')
                ->suffix(fn (Get $get): string => (string) $get('base'))
                ->visible(fn (Get $get): bool => (bool) $get('caja')),
        ])
            ->hiddenLabel()
            ->columns(fn (Get $get): int => $get('caja') ? 2 : 1);
    }
}
