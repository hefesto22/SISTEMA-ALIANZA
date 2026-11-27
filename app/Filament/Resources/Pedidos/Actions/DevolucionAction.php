<?php

declare(strict_types=1);

namespace App\Filament\Resources\Pedidos\Actions;

use App\Domain\Devoluciones\RegistrarDevolucion;
use App\Domain\Exceptions\ReglaNegocioException;
use App\Enums\EstadoPedido;
use App\Enums\TipoDevolucion;
use App\Filament\Support\Acceso;
use App\Models\Carga;
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
use Filament\Notifications\Notification;
use Filament\Schemas\Components\Utilities\Get;
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
 * Sale en Ver pedido y, por cada factura, en Ver carga y en Liquidar
 * (deLaCarga()): lo que el cliente regresó con el que salió en la ruta.
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
            ->modalDescription('Lo bueno regresa a la bodega; lo dañado no, queda marcado para el reporte de producto dañado. Las unidades no importan de qué caja salieron: se cuentan en la presentación de cada línea.')
            ->modalWidth(Width::ThreeExtraLarge)
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
                        TableColumn::make('Puede devolver')->width('140px'),
                        TableColumn::make('Buenas')->width('100px'),
                        TableColumn::make('Dañadas')->width('100px'),
                    ])
                    ->schema([
                        Hidden::make('id'),
                        TextInput::make('producto')->hiddenLabel()->disabled()->dehydrated(false),
                        TextInput::make('puede')->hiddenLabel()->disabled()->dehydrated(false),
                        TextInput::make('buenas')->hiddenLabel()->numeric()->integer()->minValue(0)->default(0),
                        TextInput::make('danadas')->hiddenLabel()->numeric()->integer()->minValue(0)->default(0),
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
                            'buenas'  => is_numeric($fila['buenas'] ?? null) ? (int) $fila['buenas'] : 0,
                            'danadas' => is_numeric($fila['danadas'] ?? null) ? (int) $fila['danadas'] : 0,
                        ];
                    }
                }

                $tipo = TipoDevolucion::tryFrom(is_string($data['tipo'] ?? null) ? $data['tipo'] : '') ?? TipoDevolucion::Cambio;
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
            ->record(function (array $arguments) use ($carga): ?Pedido {
                $factura = is_numeric($arguments['factura'] ?? null)
                    ? $carga->renglones()->where('factura_id', (int) $arguments['factura'])->first()?->factura
                    : null;

                return $factura?->pedido;
            });
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
     * Las líneas que salieron y todavía se pueden devolver.
     *
     * @return list<array{id: int, producto: string, puede: string, buenas: int, danadas: int}>
     */
    public static function lineas(Pedido $pedido): array
    {
        $devueltas = RegistrarDevolucion::devueltas($pedido);
        $filas = [];

        foreach ($pedido->items()->with(['producto', 'presentacion.unidadMedida'])->orderBy('id')->get() as $item) {
            $puede = $item->cantidadFacturable() - ($devueltas[$item->id] ?? 0);

            if ($puede <= 0) {
                continue;
            }

            $filas[] = [
                'id'       => $item->id,
                'producto' => "{$item->presentacion->sigla()} · {$item->producto->descripcion}".($item->bonificacion ? ' (regalo)' : ''),
                'puede'    => $item->presentacion->enPalabras($puede),
                'buenas'   => 0,
                'danadas'  => 0,
            ];
        }

        return $filas;
    }
}
