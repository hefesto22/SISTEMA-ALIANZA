{{--
    Vista de impresión de un reporte dentro de un modal (App\Filament\Support\VistaImpresion):
    la hoja se ve como papel sobre fondo gris; "Imprimir o guardar PDF" imprime este marco.
    Datos: $url (la hoja con embebido=1) y $marco (id del iframe).
--}}
<div class="vista-impresion" x-data="{ cargando: true }" wire:ignore>
    <div class="vi-cargando" x-show="cargando">
        {{ svg('heroicon-o-arrow-path', 'vi-giro') }}
        <span>Preparando la hoja…</span>
    </div>
    <iframe
        id="{{ $marco }}"
        src="{{ $url }}"
        title="Vista de impresión"
        x-on:load="cargando = false"
    ></iframe>
</div>

<style>
    .vista-impresion { position: relative; border-radius: 0.75rem; overflow: hidden; border: 1px solid var(--gray-200); background: #f3f4f6; }
    .dark .vista-impresion { border-color: rgb(255 255 255 / 0.1); }
    .vista-impresion iframe { display: block; width: 100%; height: min(75vh, 900px); border: 0; background: #f3f4f6; }
    .vi-cargando { position: absolute; inset: 0; display: flex; align-items: center; justify-content: center; gap: 0.5rem; font-size: 0.9rem; color: #4b5563; background: #f3f4f6; }
    .vi-giro { width: 1.1rem; height: 1.1rem; animation: vi-girar 1s linear infinite; }
    @keyframes vi-girar { to { transform: rotate(360deg); } }
    @media (prefers-reduced-motion: reduce) { .vi-giro { animation: none; } }
    @media (max-width: 640px) { .vista-impresion iframe { height: 70vh; } }
</style>
