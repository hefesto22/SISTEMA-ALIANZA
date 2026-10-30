{{--
    Mapa de UbicacionEnMapa: el del plugin afsakar/filament-leaflet-map-picker
    más lo que hace falta para el vendedor en la calle:

    - Botón grande "Usar mi ubicación actual" (el del plugin es un ícono chico
      dentro del mapa; los dos hacen lo mismo).
    - Cada que se marca un punto se llama a ubicar() en el servidor, que llena
      departamento, municipio y referencia, y el mapa se acerca al punto.
    - Una línea que dice qué está pasando (buscando, punto marcado, sin punto).

    El plugin manda el punto al servidor hasta la siguiente llamada; ubicar()
    es esa llamada, así que llega con el punto completo.
--}}
@php
    $statePath = $getStatePath();
@endphp

<div
    class="ubicacion-en-mapa"
    x-data="{
        punto: null,
        ultimo: null,
        localizando: false,
        buscando: false,
        espera: null,

        init() {
            this.punto = $wire.get(@js($statePath)) ?? null;
            this.ultimo = JSON.stringify(this.punto);
            $wire.$watch(@js($statePath), (valor) => this.alMarcar(valor));
        },

        mapa() {
            const contenedor = this.$root.querySelector('.leaflet-map-picker');

            return contenedor ? Alpine.$data(contenedor) : null;
        },

        usarMiUbicacion() {
            const mapa = this.mapa();

            if (! mapa || typeof mapa.goToCurrentLocation !== 'function') {
                return;
            }

            this.localizando = true;
            clearTimeout(this.espera);
            this.espera = setTimeout(() => this.localizando = false, 16000);
            mapa.goToCurrentLocation();
        },

        alMarcar(valor) {
            this.punto = valor ?? null;
            const json = JSON.stringify(this.punto);

            if (json === this.ultimo || ! this.punto) {
                return;
            }

            this.ultimo = json;
            this.localizando = false;
            this.acercar();
            this.buscando = true;

            $wire.callSchemaComponentMethod(@js($getKey()), 'ubicar')
                .finally(() => this.buscando = false);
        },

        acercar() {
            const mapa = this.mapa()?.map;

            if (mapa && typeof mapa.getZoom === 'function' && mapa.getZoom() < 16) {
                mapa.setView([this.punto.lat, this.punto.lng], 16);
            }
        },

        coordenadas() {
            return this.punto ? `${Number(this.punto.lat).toFixed(5)}, ${Number(this.punto.lng).toFixed(5)}` : '';
        },
    }"
>
    <style>
        .ubicacion-en-mapa { display: grid; gap: 0.5rem; }
        /* La línea de coordenadas del plugin: aquí va la nuestra, abajo. */
        .ubicacion-en-mapa .leaflet-map-picker + div { display: none; }
        /*
         * El plugin usa clases de Tailwind (h-4, w-4, rounded-lg…) que el panel
         * no trae porque no tiene tema propio: aquí lo mínimo para que se vea bien.
         */
        .ubicacion-en-mapa .leaflet-map-picker { border-radius: 0.75rem; border: 1px solid var(--gray-200); }
        .dark .ubicacion-en-mapa .leaflet-map-picker { border-color: var(--gray-700); }
        .ubicacion-en-mapa .leaflet-bar button { display: flex; align-items: center; justify-content: center; color: var(--gray-700); }
        .ubicacion-en-mapa .leaflet-bar button svg { width: 1.125rem; height: 1.125rem; }
        .ubicacion-en-mapa .fi-modal svg:not(.fi-icon) { width: 1.25rem; height: 1.25rem; flex-shrink: 0; }
        .ubicacion-en-mapa .fi-modal .sr-only { position: absolute; width: 1px; height: 1px; overflow: hidden; clip: rect(0, 0, 0, 0); white-space: nowrap; }
        .ubicacion-en-mapa .fi-modal ul { margin-top: 1rem; border: 1px solid var(--gray-200); border-radius: 0.5rem; overflow: hidden; }
        .ubicacion-en-mapa .fi-modal ul li + li { border-top: 1px solid var(--gray-200); }
        .ubicacion-en-mapa .fi-modal ul button { display: flex; align-items: flex-start; gap: 0.75rem; width: 100%; padding: 0.75rem 1rem; text-align: left; font-size: 0.875rem; }
        .dark .ubicacion-en-mapa .fi-modal ul, .dark .ubicacion-en-mapa .fi-modal ul li + li { border-color: var(--gray-700); }
        .ubicacion-en-mapa-pie { display: flex; flex-wrap: wrap; align-items: center; gap: 0.5rem 0.75rem; }
        .ubicacion-en-mapa-estado { font-size: 0.875rem; line-height: 1.25rem; color: var(--gray-500); }
        .ubicacion-en-mapa-estado strong { color: var(--gray-700); font-weight: 600; }
        .dark .ubicacion-en-mapa-estado strong { color: var(--gray-200); }
    </style>

    @include('filament-leaflet-map-picker::leaflet-map-picker')

    @unless ($isDisabled())
        <div class="ubicacion-en-mapa-pie">
            <x-filament::button
                type="button"
                color="gray"
                size="sm"
                icon="heroicon-m-map-pin"
                x-on:click="usarMiUbicacion()"
                x-bind:disabled="localizando || buscando"
            >
                Usar mi ubicación actual
            </x-filament::button>

            <p class="ubicacion-en-mapa-estado" aria-live="polite">
                <span x-show="localizando" x-cloak>Buscando dónde está…</span>
                <span x-show="buscando" x-cloak>Buscando la dirección de ese punto…</span>
                <span x-show="! localizando && ! buscando && punto" x-cloak>
                    <strong>Ubicación marcada</strong> (<span x-text="coordenadas()"></span>). Puede mover el pin para corregirla.
                </span>
                <span x-show="! localizando && ! buscando && ! punto">
                    Toque el botón estando en el negocio, o toque el mapa donde queda. Se llenan solos el departamento, el municipio y la referencia.
                </span>
            </p>
        </div>
    @endunless
</div>
