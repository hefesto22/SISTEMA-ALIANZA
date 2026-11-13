<?php

declare(strict_types=1);

namespace App\Filament\Resources\Productos\Pages;

use App\Domain\Exceptions\ReglaNegocioException;
use App\Domain\Precios\ActualizarPrecioProducto;
use App\Domain\Productos\GuardarPresentaciones;
use App\Enums\MotivoCambioPrecio;
use App\Filament\Resources\Productos\Actions\ActualizarPrecioAction;
use App\Filament\Resources\Productos\ProductoResource;
use App\Filament\Resources\Productos\Schemas\ProductoForm;
use App\Models\Presentacion;
use App\Models\Producto;
use App\Models\User;
use Filament\Actions\ViewAction;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Support\Facades\Auth;

/**
 * Editar el producto y sus presentaciones. Una presentación nueva entra con
 * el precio que da el costo de hoy con el % de utilidad (sin costo todavía,
 * sin precio: se pone con "Actualizar precio").
 */
class EditProducto extends EditRecord
{
    protected static string $resource = ProductoResource::class;

    protected function getHeaderActions(): array
    {
        return [
            ActualizarPrecioAction::make(),
            ViewAction::make(),
        ];
    }

    /**
     * @param array<string, mixed> $data
     *
     * @return array<string, mixed>
     */
    protected function mutateFormDataBeforeFill(array $data): array
    {
        /** @var Producto $producto */
        $producto = $this->getRecord();

        return [...$data, ...ProductoForm::presentacionesParaEditar($producto)];
    }

    protected function beforeSave(): void
    {
        $datos = $this->data ?? [];
        $error = GuardarPresentaciones::revisar((int) ($datos['unidad_medida_id'] ?? 0), ProductoForm::otras($datos));

        if ($error !== null) {
            Notification::make()->title($error)->danger()->send();

            $this->halt();
        }
    }

    protected function afterSave(): void
    {
        /** @var Producto $producto */
        $producto = $this->getRecord();
        $datos = $this->data ?? [];
        $antes = $producto->presentaciones()->pluck('id')->all();

        /** @var User|null $usuario */
        $usuario = Auth::user();

        try {
            $guardadas = app(GuardarPresentaciones::class)->ejecutar($producto, (bool) ($datos['base_activa'] ?? true), ProductoForm::otras($datos));

            $nuevas = array_filter($guardadas['otras'], fn (Presentacion $presentacion): bool => ! in_array($presentacion->id, $antes, true));

            // Las nuevas traen un costo estimado (GuardarPresentaciones): su precio sale de él con el % de utilidad.
            if (array_filter($nuevas, fn (Presentacion $presentacion): bool => $presentacion->costo > 0) !== []) {
                $producto->refresh();

                app(ActualizarPrecioProducto::class)->ejecutar($producto, MotivoCambioPrecio::Manual, $usuario, nota: 'PRESENTACIÓN NUEVA');
            }
        } catch (ReglaNegocioException $e) {
            Notification::make()->title('Se guardó el producto, pero no las presentaciones')->body($e->getMessage())->danger()->persistent()->send();
        }

        $this->fillForm();
    }
}
