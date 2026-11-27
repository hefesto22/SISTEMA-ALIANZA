<?php

declare(strict_types=1);

namespace App\Filament\Resources\Pedidos\Actions;

use App\Domain\Pedidos\HojaDeAlisto;
use App\Domain\Pedidos\PedidosValidados;
use App\Domain\Pedidos\ValidarVarios;
use App\Enums\EstadoPedido;
use App\Enums\ModoImpresion;
use App\Filament\Support\Acceso;
use App\Models\Configuracion;
use App\Models\Pedido;
use Filament\Actions\Action;
use Filament\Actions\BulkAction;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\ToggleButtons;
use Filament\Notifications\Notification;
use Filament\Schemas\Components\Callout;
use Filament\Schemas\Components\Component;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Support\Enums\Width;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Gate;
use Livewire\Component as Livewire;

/**
 * Validar muchos pendientes de un solo (App\Domain\Pedidos\ValidarVarios),
 * como si bodega abriera el Validar de cada uno y le diera Validar sin
 * cambiar nada:
 *  - "Validar las N" de un cuadro de Pendiente (ListPedidos::validarDiaAction).
 *  - "Validar todos" de la pestaña Pendiente, con los filtros y la búsqueda.
 *  - "Validar seleccionados", los marcados en "Uno por uno" (seleccionados()).
 *
 * El modal dice cuántos son y por cuánto, qué no alcanza para todos y, con
 * "controlar existencias", qué hacer con el pedido al que no le alcanza:
 * mandar lo que hay (como trae de entrada el Validar de cada uno) o dejarlo
 * pendiente. Si se alistó con la hoja (HojaDeAlistoAction), en "¿Faltó algo
 * al alistar?" se anota lo que no se encontró de cada producto: a los
 * últimos pedidos les sale menos. El aviso dice cuántos quedaron por
 * imprimir (con el botón Imprimir las N), cuáles no van completos y cuáles
 * siguen pendientes.
 */
final class ValidarVariosAction
{
    public const SI_NO_ALCANZA = 'si_no_alcanza';

    public const LO_QUE_HAY = 'lo_que_hay';

    public const DEJARLO = 'dejarlo';

    /** Lo que faltó al alistar: [{clave: "bodega:presentación", cantidad}]. */
    public const FALTARON = 'faltaron';

    /** Cuántos números (o productos) se nombran; del resto se dice cuántos más. */
    private const NOMBRADOS = 6;

    /**
     * Lo de todos los modales; quien lo usa pone el título, el schema
     * (schema()), la descripción (descripcion()) y la acción (ejecutar()).
     */
    public static function modal(Action $action): Action
    {
        return $action
            ->modalIcon(Heroicon::OutlinedClipboardDocumentCheck)
            ->modalIconColor('success')
            ->modalWidth(Width::TwoExtraLarge)
            ->modalSubmitActionLabel('Validar')
            ->fillForm([self::SI_NO_ALCANZA => self::LO_QUE_HAY, self::FALTARON => []]);
    }

    /**
     * Lo que no alcanza para todos (con "controlar existencias"), qué hacer
     * con el pedido al que no le alcanza y lo que faltó al alistar.
     *
     * @param list<int> $ids
     *
     * @return array<int, Component>
     */
    public static function schema(array $ids): array
    {
        $controla = Configuracion::actual()->controlar_existencias;
        $hoja = HojaDeAlisto::datos($ids);
        $faltan = $controla ? ValidarVarios::noAlcanzanEn($hoja) : [];
        $productos = HojaDeAlisto::paraAnotar($hoja);
        $topes = [];

        foreach (HojaDeAlisto::cobrado($hoja) as $bodega => $presentaciones) {
            foreach ($presentaciones as $presentacion => $cobra) {
                $topes["{$bodega}:{$presentacion}"] = $cobra;
            }
        }

        return [
            Callout::make('No alcanza para todos')
                ->description(self::nombrar($faltan, ' · ').'. Van del más viejo al más nuevo: a los últimos les toca lo que quede.')
                ->icon(Heroicon::OutlinedExclamationTriangle)
                ->warning()
                ->visible($faltan !== []),
            ToggleButtons::make(self::SI_NO_ALCANZA)
                ->label('Si a un pedido no le alcanza algo')
                ->options([
                    self::LO_QUE_HAY => 'Mandar lo que hay',
                    self::DEJARLO    => 'Dejarlo pendiente',
                ])
                ->icons([
                    self::LO_QUE_HAY => Heroicon::OutlinedTruck,
                    self::DEJARLO    => Heroicon::OutlinedPauseCircle,
                ])
                ->inline()
                ->required()
                ->helperText('Mandar lo que hay: sale lo que alcanza, como trae de entrada el Validar de cada uno, y el aviso dice cuáles no van completos. Dejarlo pendiente: queda para revisarlo uno por uno. Si no hay nada de lo que pidió, queda pendiente.')
                ->visible($controla),
            Repeater::make(self::FALTARON)
                ->label('¿Faltó algo al alistar?')
                ->helperText('Si alistó con la hoja: lo que anotó en «Faltó». De ese producto, a los primeros pedidos les sale completo y a los últimos menos; lo demás se aparta hoy.')
                ->schema([
                    Select::make('clave')
                        ->label('Producto')
                        ->options($productos)
                        ->searchable()
                        ->required()
                        ->distinct()
                        ->live()
                        ->columnSpan(['default' => 1, 'sm' => 3]),
                    TextInput::make('cantidad')
                        ->label('Faltó')
                        ->numeric()
                        ->integer()
                        ->minValue(1)
                        ->maxValue(fn (Get $get): ?int => $topes[(string) $get('clave')] ?? null)
                        ->required(),
                ])
                ->columns(['default' => 1, 'sm' => 4])
                ->defaultItems(0)
                ->addActionLabel('Agregar lo que faltó')
                ->reorderable(false)
                ->visible($productos !== []),
        ];
    }

    /**
     * "Son 45 pedidos por L 52,300.00. Sale todo lo pedido de cada uno…".
     *
     * @param list<int> $ids
     */
    public static function descripcion(array $ids): string
    {
        $pedidos = Pedido::query()->whereKey($ids)->where('estado', EstadoPedido::Pendiente);
        $cuantos = (clone $pedidos)->count();
        $total = (float) (clone $pedidos)->sum('total');

        return ($cuantos === 1 ? 'Es 1 pedido por ' : "Son {$cuantos} pedidos por ").lempiras($total).'. '
            .'Sale todo lo pedido de cada uno, del más viejo al más nuevo: se descuenta de la bodega y quedan Por imprimir. '
            .'Para cambiar algo de uno, valídelo uno por uno.';
    }

    /**
     * Valida los que le tocan al usuario (PedidoPolicy::validar) y avisa.
     *
     * @param list<int> $ids
     * @param array<string, mixed> $data
     */
    public static function ejecutar(array $ids, array $data): void
    {
        $usuario = Acceso::usuario();

        if ($usuario === null) {
            return;
        }

        $suyos = Pedido::query()
            ->whereKey($ids)
            ->where('estado', EstadoPedido::Pendiente)
            ->get()
            ->filter(fn (Pedido $pedido): bool => Gate::forUser($usuario)->allows('validar', $pedido))
            ->modelKeys();

        if ($suyos === []) {
            Notification::make()->title('No hay pedidos pendientes que validar')->warning()->send();

            return;
        }

        // Muchos pedidos tardan (unos 3 s cada 100): que no se corte a medias; igual cada uno se guarda solo.
        set_time_limit(max(60, count($suyos)));

        $resultado = app(ValidarVarios::class)->ejecutar(
            array_values(array_map(intval(...), $suyos)),
            $usuario,
            conLoQueHay: ($data[self::SI_NO_ALCANZA] ?? self::LO_QUE_HAY) !== self::DEJARLO,
            faltaron: self::faltaron($data),
        );

        self::avisar($resultado);
    }

    /**
     * "Validar seleccionados" en "Uno por uno" de la pestaña Pendiente.
     */
    public static function seleccionados(): BulkAction
    {
        $action = BulkAction::make('validarSeleccionados')
            ->label('Validar seleccionados')
            ->icon(Heroicon::OutlinedClipboardDocumentCheck)
            ->color('success')
            ->visible(fn (Livewire $livewire): bool => Acceso::puede('Validar:Pedido')
                && data_get($livewire, 'activeTab') === EstadoPedido::Pendiente->value)
            ->modalHeading(fn (Collection $records): string => $records->count() === 1 ? 'Validar el pedido marcado' : 'Validar los '.$records->count().' pedidos marcados')
            ->modalDescription(fn (Collection $records): string => self::descripcion(self::idsDe($records)))
            ->schema(fn (Collection $records): array => self::schema(self::idsDe($records)))
            ->action(function (array $data, Collection $records): void {
                self::ejecutar(self::idsDe($records), $data);
            })
            ->deselectRecordsAfterCompletion();

        self::modal($action);

        return $action;
    }

    /**
     * El aviso al terminar: cuántos quedaron por imprimir y por cuánto (con
     * Imprimir las N), cuáles no van completos y cuáles siguen pendientes.
     */
    private static function avisar(PedidosValidados $resultado): void
    {
        $validados = count($resultado->validados);
        $partes = [];

        if ($validados > 0) {
            $partes[] = 'Total para los clientes: '.lempiras($resultado->total()).'.';
        }

        if ($resultado->incompletos !== []) {
            $partes[] = 'No van completos ('.count($resultado->incompletos).', salió lo que había): '.self::nombrar($resultado->incompletos, ', ').'.';
        }

        if ($resultado->pendientes !== []) {
            $porQue = [];

            foreach ($resultado->pendientes as $numero => $motivo) {
                $porQue[] = "{$numero}: ".rtrim($motivo, '.');
            }

            $partes[] = 'Quedaron pendientes ('.count($resultado->pendientes).'), revíselos uno por uno: '.self::nombrar($porQue, '; ').'.';
        }

        $aviso = Notification::make()
            ->title(match ($validados) {
                0       => 'No se validó ninguno',
                1       => '1 pedido validado: queda por imprimir',
                default => "{$validados} pedidos validados: quedan por imprimir",
            })
            ->body(implode(' ', $partes));

        if ($validados === 0) {
            $aviso->warning()->persistent()->send();

            return;
        }

        $aviso
            ->success()
            ->actions([
                Action::make('imprimir')
                    ->label($validados === 1 ? 'Imprimir' : "Imprimir las {$validados}")
                    ->dispatch(ImprimirPedidoAction::PEDIR, ['ids' => $resultado->ids(), 'modo' => ModoImpresion::Originales->value])
                    ->close(),
            ]);

        if ($resultado->incompletos !== [] || $resultado->pendientes !== []) {
            $aviso->persistent();
        }

        $aviso->send();
    }

    /**
     * Lo que faltó al alistar, del modal: [bodega => [presentación => cuántas]].
     *
     * @param array<string, mixed> $data
     *
     * @return array<int, array<int, int>>
     */
    public static function faltaron(array $data): array
    {
        $faltaron = [];

        foreach ((array) ($data[self::FALTARON] ?? []) as $fila) {
            if (! is_array($fila) || ! is_string($fila['clave'] ?? null) || ! is_numeric($fila['cantidad'] ?? null)) {
                continue;
            }

            $partes = explode(':', $fila['clave']);
            $bodega = (int) ($partes[0] ?? 0);
            $presentacion = (int) ($partes[1] ?? 0);
            $cantidad = (int) $fila['cantidad'];

            if ($bodega > 0 && $presentacion > 0 && $cantidad > 0) {
                $faltaron[$bodega][$presentacion] = ($faltaron[$bodega][$presentacion] ?? 0) + $cantidad;
            }
        }

        return $faltaron;
    }

    /**
     * "A, B, C, D, E, F y 3 más".
     *
     * @param list<string> $partes
     */
    private static function nombrar(array $partes, string $separador): string
    {
        $resto = count($partes) - self::NOMBRADOS;

        return implode($separador, array_slice($partes, 0, self::NOMBRADOS)).($resto > 0 ? " y {$resto} más" : '');
    }

    /**
     * @param Collection<array-key, mixed> $records
     *
     * @return list<int>
     */
    private static function idsDe(Collection $records): array
    {
        $ids = [];

        foreach ($records as $pedido) {
            if ($pedido instanceof Pedido) {
                $ids[] = $pedido->id;
            }
        }

        return $ids;
    }
}
