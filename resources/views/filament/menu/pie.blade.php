{{--
    Pie del menú lateral (AdminPanelProvider, SIDEBAR_FOOTER): quién entró,
    con qué rol y de qué bodega. Con el menú cerrado queda solo el avatar.
--}}
@php
    use App\Enums\Rol;
    use App\Models\User;

    $usuario = filament()->auth()->user();
@endphp

@if ($usuario instanceof User)
    @php
        $veTodo = $usuario->veTodasLasBodegas();
        $rol = match (true) {
            $veTodo => 'Administración',
            $usuario->hasRole(Rol::Bodega->value) => 'Encargado de bodega',
            $usuario->hasRole(Rol::Vendedor->value) => 'Vendedor',
            default => 'Usuario',
        };
        $bodega = $veTodo ? 'todas las bodegas' : $usuario->bodega?->nombre;
    @endphp

    <div class="mn-pie">
        <x-filament-panels::avatar.user :user="$usuario" />

        <div class="mn-pie-texto" x-show="$store.sidebar.isOpen">
            <span class="mn-pie-nombre">{{ filament()->getUserName($usuario) }}</span>
            <span class="mn-pie-detalle">{{ $rol }}{{ filled($bodega) ? ' · '.$bodega : '' }}</span>
        </div>
    </div>
@endif
