<?php

namespace App\Modules\Siigo\Support;

/**
 * La clave con la que SIIGO reconoce «este documento ya me lo mandaste».
 *
 * Sirve para que un reintento del job no cree un duplicado fiscal: si la clave
 * se repite, SIIGO no vuelve a crear nada y devuelve lo que guardó la primera
 * vez. Eso es justo lo que queremos... mientras la clave identifique de verdad
 * al documento.
 *
 * ── Por qué no alcanza el id local ──────────────────────────────────────────
 *
 * La clave era `inv:{id}`, y el id es un autoincremento de NUESTRA base. Al
 * resembrar o restaurar, el id 30 vuelve a existir siendo OTRA factura — y
 * SIIGO, que se acuerda de la clave, devuelve el documento viejo en vez de
 * crear el nuevo.
 *
 * Pasó el 2026-11-03: la factura FV-261103-0002 (3 de noviembre, $144.000,
 * GB-DEMO-001) se guardó apuntando a FV-247-1222 (10 de octubre, $89.250, otro
 * producto, otro pedido). El ERP la dio por emitida, el documento nunca existió
 * en SIIGO, y nada lo avisó: la contabilidad quedó enganchada a un papel ajeno.
 *
 * Con el instante de creación adentro, dos documentos distintos tendrían que
 * compartir id Y el segundo exacto en que nacieron para chocar. Y un reintento
 * del mismo documento sigue dando la misma clave, que es para lo que existe.
 */
final class ClaveIdempotencia
{
    public static function para(string $prefijo, object $modelo): string
    {
        $nacido = $modelo->created_at?->getTimestamp() ?? 0;

        return $prefijo.':'.$modelo->getKey().':'.$nacido;
    }
}
