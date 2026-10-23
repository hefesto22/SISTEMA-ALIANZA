{{--
    Imprimir sin salir de la página: la hoja se carga en un iframe oculto y
    se abre ahí mismo el diálogo de imprimir del navegador.

    Lo usan las acciones de App\Filament\Resources\Pedidos\Actions\ImprimirPedidoAction
    (evento "imprimir-hoja" con { url }). Al cerrar el diálogo se manda
    "hoja-impresa" para que la lista o el pedido se actualicen.
    Se carga en todo el panel desde AdminPanelProvider (BODY_END).
--}}
<script>
    (() => {
        if (window.imprimirHoja) {
            return;
        }

        const avisarError = (motivo) => {
            if (window.FilamentNotification) {
                new window.FilamentNotification()
                    .title('No se pudo imprimir')
                    .body(motivo || 'Recargue la página e intente de nuevo.')
                    .danger()
                    .persistent()
                    .send();
            }
        };

        window.imprimirHoja = (url) => {
            document.getElementById('hoja-para-imprimir')?.remove();

            const marco = document.createElement('iframe');
            marco.id = 'hoja-para-imprimir';
            marco.title = 'Hoja para imprimir';
            marco.tabIndex = -1;
            marco.setAttribute('aria-hidden', 'true');
            marco.style.cssText = 'position:fixed;right:0;bottom:0;width:0;height:0;border:0;opacity:0;pointer-events:none';

            marco.addEventListener('load', () => {
                const ventana = marco.contentWindow;

                // Si no llegó la hoja (sin CAI vigente, sin permiso, sesión vencida…) no se imprime la página de error.
                if (! ventana || ! marco.contentDocument?.querySelector('.hoja')) {
                    const motivo = marco.contentDocument?.body?.dataset.error;

                    marco.remove();
                    avisarError(motivo);

                    return;
                }

                ventana.focus();
                ventana.print();

                // Al abrir la hoja ya quedó como impreso: se refresca lo que está en pantalla.
                setTimeout(() => window.Livewire?.dispatch('hoja-impresa'), 300);
            }, { once: true });

            marco.src = url;
            document.body.appendChild(marco);
        };

        const escuchar = () => window.Livewire.on('imprimir-hoja', (datos) => {
            if (datos?.url) {
                window.imprimirHoja(datos.url);
            }
        });

        if (window.Livewire) {
            escuchar();
        } else {
            document.addEventListener('livewire:init', escuchar, { once: true });
        }
    })();
</script>
