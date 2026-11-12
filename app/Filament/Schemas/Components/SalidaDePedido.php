<?php

declare(strict_types=1);

namespace App\Filament\Schemas\Components;

use App\Domain\Pedidos\AjustarLineas;
use App\Filament\Resources\Pedidos\Actions\LineasPedido;
use App\Models\Pedido;
use Closure;
use Filament\Forms\Components\Field;
use Filament\Support\Components\Attributes\ExposedLivewireMethod;
use Livewire\Attributes\Renderless;

/**
 * Validar y Corregir: cuánto sale de cada producto del pedido, como en la
 * preventa: un contador por cada presentación que se vende (CJ12, CJ8,
 * UN…), con lo que pidió, cuánto hay en la bodega, cuánto saca este pedido y
 * cuánto quedaría. "No hay" lo deja en 0 y "Sí hay" lo regresa a lo pedido.
 * Puede salir menos de lo pedido, más, o en otra presentación (unidades
 * sueltas si una caja vino dañada); cada cosa se dice en palabras ("falta 1
 * caja de 12 (pidió 3)"). Arriba,
 * "Agregar otro producto" busca uno que no venía en el pedido y lo suma a la
 * lista. Todo pasa en el teléfono; al servidor solo van la búsqueda y
 * confirmar.
 *
 * Estado: list<array{id: int|null, producto_id: int, presentacion_id: int, sale: int, ...}>
 * como lo arma App\Filament\Resources\Pedidos\Actions\LineasPedido::datos(): las
 * líneas del pedido y, sin id, las presentaciones que cada producto no trae.
 *
 * Vista: resources/views/filament/pedidos/salida-pedido.blade.php
 */
class SalidaDePedido extends Field
{
    public const SIN_NADA = 'Tiene que salir al menos un producto. Si no hay nada, anule el pedido.';

    /** @var view-string */
    protected string $view = 'filament.pedidos.salida-pedido';

    protected bool|Closure $paraValidar = false;

    protected function setUp(): void
    {
        parent::setUp();

        $this->hiddenLabel();
        $this->default([]);
        $this->required();
        $this->validationMessages(['required' => self::SIN_NADA]);
        $this->rule('array');
        $this->rule(fn (): Closure => function (string $attribute, mixed $value, Closure $fail): void {
            if (! self::saleAlgo($value)) {
                $fail(self::SIN_NADA);
            }
        });
    }

    /**
     * Al validar todo lo que sale se resta de la bodega; al corregir solo la
     * diferencia con lo que ya había salido (si sale menos, regresa).
     */
    public function paraValidar(bool|Closure $condicion = true): static
    {
        $this->paraValidar = $condicion;

        return $this;
    }

    public function esParaValidar(): bool
    {
        return (bool) $this->evaluate($this->paraValidar);
    }

    /**
     * @param array<string, array<mixed>> $rules
     */
    public function dehydrateValidationRules(array &$rules): void
    {
        parent::dehydrateValidationRules($rules);

        $statePath = $this->getStatePath();

        $rules["{$statePath}.*.id"] = ['nullable', 'integer'];
        $rules["{$statePath}.*.producto_id"] = ['required', 'integer'];
        $rules["{$statePath}.*.presentacion_id"] = ['required', 'integer'];
        $rules["{$statePath}.*.sale"] = ['required', 'integer', 'min:0', 'max:'.AjustarLineas::MAXIMO];
    }

    /**
     * Buscador de "Agregar otro producto": productos activos con lo que hay en la
     * bodega del pedido y sus filas listas para la lista
     * (LineasPedido::paraAgregar). No vuelve a dibujar el modal: la lista
     * vive en el teléfono.
     *
     * @return list<array<string, mixed>>
     */
    #[ExposedLivewireMethod]
    #[Renderless]
    public function buscarProductos(string $buscar): array
    {
        $this->getLivewire()->skipRender();
        $pedido = $this->getRecord();

        if (! $pedido instanceof Pedido) {
            return [];
        }

        return LineasPedido::paraAgregar($pedido, $buscar);
    }

    public static function saleAlgo(mixed $filas): bool
    {
        return collect(is_array($filas) ? $filas : [])
            ->contains(fn (mixed $fila): bool => is_array($fila) && is_numeric($fila['sale'] ?? null) && (int) $fila['sale'] > 0);
    }
}
