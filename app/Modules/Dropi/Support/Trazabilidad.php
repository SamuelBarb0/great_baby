<?php

namespace App\Modules\Dropi\Support;

use App\Modules\Dropi\Models\DropiPedido;
use App\Modules\Dropi\Models\EmpaqueRegistro;
use App\Modules\Dropi\Models\InventarioMovimiento;
use App\Modules\Dropi\Services\SalidaInventarioDropi;
use InvalidArgumentException;

/**
 * Capas de seguridad del envío Dropi: quién, desde dónde y con qué equipo se
 * empacó y despachó cada pedido, y la línea de tiempo que lo junta todo.
 *
 * La ubicación la manda el navegador (`navigator.geolocation`) como
 * `gps = {lat, lng, precision, estado}`. Si el operario la niega o el equipo
 * no la tiene, se anota el motivo en vez de dejar el hueco: «no hay GPS» y
 * «se negó a darlo» no dicen lo mismo en un reclamo.
 */
class Trazabilidad
{
    public const ESTADOS_GPS = ['ok', 'denegado', 'no_disponible', 'sin_dato'];

    /** Reglas de validación del bloque `gps` que mandan las pantallas. */
    public static function reglas(): array
    {
        return [
            'gps' => ['nullable', 'array'],
            'gps.lat' => ['nullable', 'numeric', 'between:-90,90'],
            'gps.lng' => ['nullable', 'numeric', 'between:-180,180'],
            'gps.precision' => ['nullable', 'numeric', 'min:0'],
            'gps.estado' => ['nullable', 'string', 'in:'.implode(',', self::ESTADOS_GPS)],
        ];
    }

    /**
     * @param  array{lat?:mixed, lng?:mixed, precision?:mixed, estado?:string}|null  $gps
     * @return array{lat:?float, lng:?float, precision:?int, estado:string}
     */
    public static function normalizar(?array $gps): array
    {
        $lat = $gps['lat'] ?? null;
        $lng = $gps['lng'] ?? null;
        $hay = is_numeric($lat) && is_numeric($lng)
            && abs((float) $lat) <= 90 && abs((float) $lng) <= 180;

        $estado = $hay ? 'ok' : ($gps['estado'] ?? 'sin_dato');
        if (! in_array($estado, self::ESTADOS_GPS, true) || ($estado === 'ok' && ! $hay)) {
            $estado = 'sin_dato';
        }

        return [
            'lat' => $hay ? round((float) $lat, 7) : null,
            'lng' => $hay ? round((float) $lng, 7) : null,
            'precision' => $hay && is_numeric($gps['precision'] ?? null) ? (int) round((float) $gps['precision']) : null,
            'estado' => $estado,
        ];
    }

    /**
     * Frena si la regla `empaque.gps_obligatorio` está prendida y no llegó una
     * ubicación válida.
     */
    public static function exigir(?array $gps): void
    {
        if (! (bool) setting('empaque.gps_obligatorio', false)) {
            return;
        }

        $g = self::normalizar($gps);
        if ($g['estado'] !== 'ok') {
            throw new InvalidArgumentException(match ($g['estado']) {
                'denegado' => 'Este equipo negó el permiso de ubicación. Activá la ubicación del navegador para continuar.',
                'no_disponible' => 'El equipo no pudo obtener la ubicación (GPS apagado o sin señal). Activala para continuar.',
                default => 'Falta la ubicación del equipo: es obligatoria para empacar y despachar.',
            });
        }
    }

    /** Lo que va dentro del payload de `transicionar()`. */
    public static function payload(?array $gps): array
    {
        return $gps === null ? [] : ['gps' => self::normalizar($gps)];
    }

    /**
     * Columnas de la bitácora para un cambio de estado. IP y navegador sólo
     * cuando lo hizo una persona desde una petición web (el sync no tiene).
     *
     * @return array<string, mixed>
     */
    public static function columnas(array $payload, string $fuente): array
    {
        $cols = [];

        if (isset($payload['gps']) && is_array($payload['gps'])) {
            $g = self::normalizar($payload['gps']);
            $cols += [
                'lat' => $g['lat'],
                'lng' => $g['lng'],
                'gps_precision_m' => $g['precision'],
                'gps_estado' => $g['estado'],
            ];
        }

        if ($fuente === 'manual' && ! app()->runningInConsole() && ($r = request())) {
            $cols['ip'] = $r->ip();
            $cols['user_agent'] = mb_substr((string) $r->userAgent(), 0, 255) ?: null;
        }

        return $cols;
    }

    /** «Chrome · Android» en vez de la cadena entera del navegador. */
    public static function equipo(?string $ua): ?string
    {
        if (! $ua) return null;

        $nav = match (true) {
            str_contains($ua, 'Edg/') => 'Edge',
            str_contains($ua, 'OPR/') => 'Opera',
            str_contains($ua, 'Chrome/') => 'Chrome',
            str_contains($ua, 'Firefox/') => 'Firefox',
            str_contains($ua, 'Safari/') => 'Safari',
            default => 'Navegador',
        };
        $so = match (true) {
            str_contains($ua, 'Android') => 'Android',
            str_contains($ua, 'iPhone'), str_contains($ua, 'iPad') => 'iOS',
            str_contains($ua, 'Windows') => 'Windows',
            str_contains($ua, 'Mac OS') => 'Mac',
            str_contains($ua, 'Linux') => 'Linux',
            default => null,
        };

        return $so ? "{$nav} · {$so}" : $nav;
    }

    /**
     * Todo lo que le pasó al envío, en orden: cambios de estado (con quién,
     * fuente, GPS y equipo), el empaque (operario, duración, foto) y la salida
     * del inventario con su costo.
     *
     * @return list<array{at:string, tipo:string, titulo:string, detalle:?string, quien:?string, fuente:?string, gps:?array, equipo:?string, ip:?string, foto_url:?string}>
     */
    public static function lineaDeTiempo(DropiPedido $pedido): array
    {
        $eventos = [];

        $bitacora = $pedido->bitacoraEstados()->with('user:id,name')->orderBy('created_at')->orderBy('id')->get();
        foreach ($bitacora as $b) {
            $hasta = \App\Modules\Dropi\Enums\EstadoPedidoDropi::tryFrom((string) $b->estado_hasta);
            $desde = \App\Modules\Dropi\Enums\EstadoPedidoDropi::tryFrom((string) $b->estado_desde);
            $p = is_array($b->payload) ? $b->payload : [];

            $detalle = [];
            if ($desde) $detalle[] = 'desde '.$desde->label();
            if (isset($p['duracion_seg'])) $detalle[] = 'empacado en '.gmdate('i:s', (int) $p['duracion_seg']);
            if (! empty($p['motivo'])) $detalle[] = (string) $p['motivo'];

            $eventos[] = [
                'at' => $b->created_at?->toIso8601String(),
                'tipo' => 'estado',
                'titulo' => $hasta?->label() ?? (string) $b->estado_hasta,
                'detalle' => $detalle ? implode(' · ', $detalle) : null,
                'quien' => $b->user?->name,
                'fuente' => $b->fuente,
                'gps' => $b->gps_estado ? [
                    'estado' => $b->gps_estado,
                    'lat' => $b->lat !== null ? (float) $b->lat : null,
                    'lng' => $b->lng !== null ? (float) $b->lng : null,
                    'precision' => $b->gps_precision_m,
                    'mapa' => $b->lat !== null ? "https://www.google.com/maps?q={$b->lat},{$b->lng}" : null,
                ] : null,
                'equipo' => self::equipo($b->user_agent),
                'ip' => $b->ip,
                'foto_url' => null,
            ];
        }

        $registros = EmpaqueRegistro::with('operario:id,name')
            ->where('pedido_id', $pedido->id)->orderBy('inicio_at')->get();
        foreach ($registros as $r) {
            $estado = $r->estado instanceof \BackedEnum ? $r->estado->value : (string) $r->estado;
            $eventos[] = [
                'at' => $r->inicio_at?->toIso8601String(),
                'tipo' => 'empaque',
                'titulo' => 'Empezó a empacar',
                'detalle' => "{$r->items_escaneados}/{$r->items_totales} unidades escaneadas · {$estado}"
                    .($r->duracion_segundos ? ' · '.gmdate('i:s', (int) $r->duracion_segundos) : ''),
                'quien' => $r->operario?->name,
                'fuente' => 'manual',
                'gps' => null,
                'equipo' => null,
                'ip' => null,
                'foto_url' => null,
            ];
            if ($r->foto_path) {
                $eventos[] = [
                    'at' => ($r->foto_at ?? $r->inicio_at)?->toIso8601String(),
                    'tipo' => 'foto',
                    'titulo' => 'Foto del paquete',
                    'detalle' => null,
                    'quien' => $r->operario?->name,
                    'fuente' => 'manual',
                    'gps' => null,
                    'equipo' => null,
                    'ip' => null,
                    'foto_url' => route('empaques.foto', $r->id),
                ];
            }
        }

        $kardex = InventarioMovimiento::query()
            ->with('ubicacion:id,codigo,nombre')
            ->whereIn('referencia_tipo', [SalidaInventarioDropi::EGRESO, SalidaInventarioDropi::REVERSO])
            ->where('referencia_id', $pedido->id)
            ->orderBy('id')->get()
            ->groupBy(fn ($m) => $m->referencia_tipo.'|'.$m->created_at?->format('Y-m-d H:i:s'));
        foreach ($kardex as $grupo) {
            $primero = $grupo->first();
            $esReverso = $primero->referencia_tipo === SalidaInventarioDropi::REVERSO;
            $unidades = abs((int) $grupo->sum('cantidad'));
            $valor = abs((float) $grupo->sum(fn ($m) => (int) $m->cantidad * (float) ($m->costo_unit ?? 0)));
            $bodegas = $grupo->map(fn ($m) => $m->ubicacion?->codigo)->filter()->unique()->implode(', ');

            $eventos[] = [
                'at' => $primero->created_at?->toIso8601String(),
                'tipo' => 'inventario',
                'titulo' => $esReverso ? 'Volvió al inventario' : 'Salió del inventario',
                'detalle' => "{$unidades} ud".($unidades === 1 ? '' : 's')
                    .($bodegas ? " · {$bodegas}" : '')
                    .($valor > 0 ? ' · costo $'.number_format($valor, 0, ',', '.') : ' · sin costo conocido'),
                'quien' => null,
                'fuente' => 'sistema',
                'gps' => null,
                'equipo' => null,
                'ip' => null,
                'foto_url' => null,
            ];
        }

        usort($eventos, fn ($a, $b) => strcmp((string) $a['at'], (string) $b['at']));

        return $eventos;
    }
}
