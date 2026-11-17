<?php

namespace App\Modules\Dropi\Filament\Pages;

use App\Auth\Permisos;
use App\Modules\Dropi\Enums\EstadoPedidoDropi;
use App\Modules\Dropi\Events\PedidoDespachado;
use App\Modules\Dropi\Jobs\NotificarClienteDespachoJob;
use App\Modules\Dropi\Models\DropiEstadoBitacora;
use App\Modules\Dropi\Models\DropiPedido;
use BackedEnum;
use Filament\Notifications\Notification;
use Filament\Pages\Page;

/**
 * §10 diseño Dropi — Escaneo por cámara del celular/tablet.
 * BarcodeDetector API nativa (Chrome/Edge/Android) con fallback opcional a ZXing.
 * Clasifica automáticamente la intención según historial de la guía:
 *   - Guía sin registro previo → despacho directo
 *   - Ya despachada otro día → confirma devolución
 *   - Ya despachada hoy → bloquea doble escaneo
 *   - No existe → alerta
 *   - Coincide con pendiente_inventario → propone reasignación
 * Feedback multisensorial: luz verde/roja + beep + vibración.
 */
class EscannerCamara extends Page
{
    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-camera';

    protected static ?string $navigationLabel = 'Escáner cámara';

    protected static ?string $title = 'Escáner de guías por cámara';

    protected static string|\UnitEnum|null $navigationGroup = 'Operación';

    protected static ?int $navigationSort = 3;

    protected string $view = 'dropi.pages.escanner-camara';

    public static function canAccess(): bool
    {
        // Antes preguntaba por la casilla de la Vista del alistador
        //   (`esAlistador()`): «Escáner con cámara» tiene la suya, y es la que
        //   ya usa la búsqueda global de /app para esta misma pantalla.
        return Permisos::puede(auth()->user(), 'escanner_camara');
    }

    /**
     * Analiza una guía escaneada y decide la intención sin ejecutar nada aún.
     */
    public function analizarGuia(string $guia): array
    {
        $pedido = DropiPedido::where('guia', trim($guia))->first();

        if (! $pedido) {
            return [
                'accion' => 'no_encontrada',
                'guia' => $guia,
                'titulo' => 'Guía no encontrada',
                'detalle' => 'No hay pedido registrado con esta guía en el sistema.',
                'color' => 'gray',
            ];
        }

        $ctx = [
            'cliente' => $pedido->cliente_nombre,
            'ciudad' => $pedido->cliente_ciudad,
            'estado' => $pedido->estado->label(),
            'monto' => (float) $pedido->monto_esperado_proveedor,
        ];

        $estado = $pedido->estado;
        $recienDespachado = $pedido->despachado_at && $pedido->despachado_at->isSameDay(now());

        if (in_array($estado, [EstadoPedidoDropi::Despachado, EstadoPedidoDropi::Entregado, EstadoPedidoDropi::Pagado], true)) {
            if ($recienDespachado) {
                return [
                    'accion' => 'doble_escaneo',
                    'pedido_id' => $pedido->id, 'guia' => $guia,
                    'titulo' => '⚠️ Doble escaneo bloqueado',
                    'detalle' => "Esta guía ya se escaneó como despachada hoy a las " . $pedido->despachado_at->format('H:i') . ".",
                    'color' => 'warning', 'context' => $ctx,
                ];
            }
            return [
                'accion' => 'proponer_devolucion',
                'pedido_id' => $pedido->id, 'guia' => $guia,
                'titulo' => '¿Es una devolución?',
                'detalle' => "Esta guía salió el " . $pedido->despachado_at?->format('Y-m-d') . ". Confirma si volvió a bodega.",
                'color' => 'info', 'context' => $ctx,
            ];
        }

        return [
            'accion' => 'despachar',
            'pedido_id' => $pedido->id, 'guia' => $guia,
            'titulo' => '✅ Listo para despachar',
            'detalle' => "Cliente: {$ctx['cliente']} · {$ctx['ciudad']}",
            'color' => 'success', 'context' => $ctx,
        ];
    }

    /**
     * Ejecuta el despacho (llamado desde el JS tras confirmar).
     */
    public function ejecutarDespacho(int $pedidoId): array
    {
        $pedido = DropiPedido::find($pedidoId);
        if (! $pedido) {
            return ['ok' => false, 'mensaje' => 'Pedido no encontrado.'];
        }

        $anterior = $pedido->estado->value;
        $pedido->update([
            'estado' => EstadoPedidoDropi::Despachado,
            'despachado_at' => now(),
        ]);

        DropiEstadoBitacora::create([
            'pedido_id' => $pedido->id,
            'estado_desde' => $anterior,
            'estado_hasta' => EstadoPedidoDropi::Despachado->value,
            'fuente' => 'manual',
            'user_id' => auth()->id(),
            'payload' => ['origen' => 'escaner_camara'],
        ]);

        event(new PedidoDespachado($pedido));
        NotificarClienteDespachoJob::dispatch($pedido->id);

        Notification::make()
            ->title("Despachado {$pedido->guia}")
            ->success()->send();

        return ['ok' => true, 'guia' => $pedido->guia];
    }
}
