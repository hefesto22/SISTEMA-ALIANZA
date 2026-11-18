<?php

declare(strict_types=1);

namespace App\Filament\Widgets;

use App\Domain\Comisiones\PeriodoComision;
use App\Filament\Pages\MisMetas;
use App\Filament\Support\Acceso;
use Filament\Widgets\Widget;

/**
 * En el escritorio del vendedor: cómo va en cada meta de la quincena (o el
 * mes) y lo que lleva ganado, con el enlace a Mis metas.
 */
class MisMetasWidget extends Widget
{
    protected string $view = 'filament.comisiones.widget-mis-metas';

    protected static ?int $sort = 0;

    protected int|string|array $columnSpan = 'full';

    public static function canView(): bool
    {
        return Acceso::esVendedor() && MisMetas::canAccess();
    }

    /**
     * @return array<string, mixed>
     */
    public function datos(): array
    {
        $usuario = Acceso::usuario() ?? abort(403);

        return [
            ...MisMetas::paraVendedor($usuario->id, PeriodoComision::actual(), conKpis: false),
            'url' => MisMetas::getUrl(),
        ];
    }
}
