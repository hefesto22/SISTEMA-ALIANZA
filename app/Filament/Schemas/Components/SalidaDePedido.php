<?php

declare(strict_types=1);

namespace App\Filament\Schemas\Components;

use Closure;
use Filament\Forms\Components\Field;

/**
 * Validar y Corregir: cuánto sale de cada línea del pedido, una fila por
 * línea: si es caja o unidad, lo que pidió y, del producto, cuánto hay en la
 * bodega, cuánto saca este pedido y cuánto quedaría. Con − y + para "Sale" y
 * un botón "No hay" que la deja en 0 (y "Sí hay" la regresa). Lo que no va
 * completo se dice en palabras ("falta 1 caja de 3"). Todo pasa en el
 * teléfono; al servidor solo va al confirmar.
 *
 * Estado: list<array{id: int, sale: int, ...}> como lo arma
 * App\Filament\Resources\Pedidos\Actions\LineasPedido::datos() (trae también
 * lo que se muestra). Lo que vale es id y sale; el dominio no deja que salga
 * más de lo pedido.
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

        $rules["{$statePath}.*.id"] = ['required', 'integer'];
        $rules["{$statePath}.*.sale"] = ['required', 'integer', 'min:0'];
    }

    public static function saleAlgo(mixed $filas): bool
    {
        return collect(is_array($filas) ? $filas : [])
            ->contains(fn (mixed $fila): bool => is_array($fila) && is_numeric($fila['sale'] ?? null) && (int) $fila['sale'] > 0);
    }
}
