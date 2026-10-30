<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Qué se pide al imprimir:
 *
 *  Originales   → "Imprimir" / "Imprimir todos": solo los Por imprimir; sale
 *                 la factura original (se emite si no tiene). Los que ya
 *                 imprimió otra persona se saltan.
 *  Seleccionados→ los marcados: Por imprimir sale original; Impreso, copia.
 *  Copia        → "Imprimir copia" de uno impreso (sale REIMPRESIÓN · COPIA).
 *  NoSalio      → la última hoja no salió (atasco, se canceló): se imprime
 *                 igual que la vez anterior, sin contarla como copia.
 */
enum ModoImpresion: string
{
    case Originales = 'originales';
    case Seleccionados = 'seleccionados';
    case Copia = 'copia';
    case NoSalio = 'no_salio';
}
