<?php

declare(strict_types=1);

namespace App\Providers\Filament;

use App\Enums\TemaPanel;
use App\Filament\Pages\Auth\Login;
use App\Models\BrandingSetting;
use BezhanSalleh\FilamentShield\FilamentShieldPlugin;
use Filament\Http\Middleware\Authenticate;
use Filament\Http\Middleware\AuthenticateSession;
use Filament\Http\Middleware\DisableBladeIconComponents;
use Filament\Http\Middleware\DispatchServingFilamentEvent;
use Filament\Pages\Dashboard;
use Filament\Panel;
use Filament\PanelProvider;
use Filament\Support\Colors\Color;
use Filament\View\PanelsRenderHook;
use Filament\Widgets\AccountWidget;
use Illuminate\Cookie\Middleware\AddQueuedCookiesToResponse;
use Illuminate\Cookie\Middleware\EncryptCookies;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Routing\Middleware\SubstituteBindings;
use Illuminate\Session\Middleware\StartSession;
use Illuminate\Support\HtmlString;
use Illuminate\View\Middleware\ShareErrorsFromSession;
use Throwable;

class AdminPanelProvider extends PanelProvider
{
    /**
     * Los campos de texto se ven en mayúsculas mientras se escribe (menos
     * correo, contraseña y la pantalla de entrada; los ejemplos en gris
     * quedan como se escribieron). Lo que se guarda lo pasa a mayúsculas el
     * modelo.
     */
    private const ESTILO_MAYUSCULAS = '<style>'
        .'.fi-fo-text-input input:not([type=email]):not([type=password]):not([type=url]),'
        .'.fi-fo-textarea textarea{text-transform:uppercase}'
        .'.fi-simple-layout .fi-fo-text-input input,'
        .'.fi-fo-text-input input::placeholder,.fi-fo-textarea textarea::placeholder{text-transform:none}'
        .'</style>';

    /**
     * Modales pensados para el teléfono (clase "modal-telefono" en la
     * ventana): el botón de guardar ocupa el ancho, Cancelar queda a un lado.
     */
    private const ESTILO_MODALES = '<style>'
        .'.fi-modal-window.modal-telefono .fi-modal-footer-actions{display:grid!important;grid-template-columns:1fr auto!important}'
        .'.fi-modal-window.modal-telefono .fi-modal-footer-actions>*{width:100%}'
        .'</style>';

    /**
     * Listados:
     *  - no sale la ruta "Pedidos > Listado" arriba del título: no dice nada
     *    que no diga el menú (en Ver, Editar o Crear sí queda, para regresar);
     *  - la columna con clase "celda-recortada" toma el espacio que sobra y su
     *    texto queda en una línea, cortado con "…" si no cabe.
     */
    private const ESTILO_LISTADOS = '<style>'
        .'.fi-resource-list-records-page .fi-breadcrumbs{display:none}'
        .'.fi-ta-cell.celda-recortada{width:100%;max-width:0;min-width:8rem}'
        .'.fi-ta-cell.celda-recortada>.fi-ta-col{min-width:0}'
        .'.fi-ta-cell.celda-recortada .fi-ta-text,.fi-ta-cell.celda-recortada .fi-ta-text>*{display:block;min-width:0;max-width:100%;overflow:hidden;text-overflow:ellipsis;white-space:nowrap}'
        .'</style>';

    public function panel(Panel $panel): Panel
    {
        return $panel
            ->default()
            ->id('admin')
            // Path '/' = panel en la raíz. URLs limpias: /, /login, /dashboard, /users.
            // El sistema es 100% panel admin, sin frontend público (decisión de la plantilla).
            ->path('/')
            // Login con correo o código de usuario (HO01…) para los vendedores.
            ->login(Login::class)
            ->profile()
            // ── Branding dinámico desde BrandingSetting ─────────────────────
            // Logo y favicon se configuran desde el panel sin tocar código.
            // Colores: tema shadcn (openplain/filament-shadcn-theme) escogido
            // en Sistema → Configuración, con grises zinc como shadcn/ui.
            ->brandName(fn (): string => env('APP_BRAND_NAME', config('app.name', 'Olympo')))
            // Sin logo cargado: las iniciales en un cuadrito del color del tema y el nombre.
            ->brandLogo(fn (): string|HtmlString => self::brandingValue('logoUrl') ?? new HtmlString(view('filament.menu.marca')->render()))
            ->darkModeBrandLogo(fn (): ?string => self::brandingValue('logoUrl'))
            ->brandLogoHeight('2.5rem')
            ->favicon(fn (): ?string => self::brandingValue('faviconUrl'))
            // Closure: se evalúa en cada request, ya con la app completa, así
            // el tema recién escogido se ve al recargar.
            ->colors(fn (): array => [
                'primary' => self::paletaPrimaria(),
                'gray'    => Color::Zinc,
            ])
            ->discoverResources(in: app_path('Filament/Resources'), for: 'App\\Filament\\Resources')
            ->discoverPages(in: app_path('Filament/Pages'), for: 'App\\Filament\\Pages')
            ->pages([
                Dashboard::class,
            ])
            ->discoverWidgets(in: app_path('Filament/Widgets'), for: 'App\\Filament\\Widgets')
            ->widgets([
                AccountWidget::class,
            ])
            ->middleware([
                EncryptCookies::class,
                AddQueuedCookiesToResponse::class,
                StartSession::class,
                AuthenticateSession::class,
                ShareErrorsFromSession::class,
                PreventRequestForgery::class,
                SubstituteBindings::class,
                DisableBladeIconComponents::class,
                DispatchServingFilamentEvent::class,
            ])
            ->authMiddleware([
                Authenticate::class,
            ])
            ->plugins([
                FilamentShieldPlugin::make()
                    ->navigationGroup('Sistema')
                    ->navigationSort(2),
            ])
            ->databaseNotifications()
            ->databaseNotificationsPolling('30s')
            ->globalSearchKeyBindings(['command+k', 'ctrl+k'])
            ->navigationGroups(['Ventas', 'Inventario', 'Precios', 'Administración', 'Sistema'])
            ->sidebarWidth('17rem')
            ->sidebarCollapsibleOnDesktop()
            // Todo se guarda en MAYÚSCULAS (GuardaEnMayusculas): se escribe ya en mayúsculas.
            ->renderHook(PanelsRenderHook::HEAD_END, fn (): string => self::ESTILO_MAYUSCULAS.self::ESTILO_MODALES.self::ESTILO_LISTADOS.view('filament.menu.estilos')->render())
            // Abajo del menú: quién entró, su rol y su bodega.
            ->renderHook(PanelsRenderHook::SIDEBAR_FOOTER, fn (): string => view('filament.menu.pie')->render())
            // Imprimir hojas de pedidos ahí mismo, sin abrir otra pestaña.
            ->renderHook(PanelsRenderHook::BODY_END, fn (): string => view('filament.imprimir-hoja')->render());
    }

    /**
     * Lee un atributo del singleton BrandingSetting con tolerancia a errores.
     *
     * Si la migración aún no se ha corrido (por ejemplo, durante el primer
     * `migrate` del setup), evitamos que Filament muera intentando leer
     * la tabla. En ese caso retornamos null y Filament usa su default.
     */
    private static function brandingValue(string $atributo): ?string
    {
        try {
            $valor = BrandingSetting::current()->{$atributo} ?? null;

            return is_string($valor) && $valor !== '' ? $valor : null;
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * Paleta del tema shadcn escogido en Configuración. Se lee directo de la
     * tabla (una fila, sin caché) para que el cambio se vea de inmediato. Si
     * la tabla aún no está migrada o no hay tema, usa el clásico de shadcn.
     *
     * @return array<int|string, string>
     */
    private static function paletaPrimaria(): array
    {
        try {
            $tema = TemaPanel::tryFrom((string) BrandingSetting::query()->value('tema'));
        } catch (Throwable) {
            $tema = null;
        }

        return ($tema ?? TemaPanel::Default)->paleta();
    }
}
