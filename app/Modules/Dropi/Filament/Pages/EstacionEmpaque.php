<?php

namespace App\Modules\Dropi\Filament\Pages;

use App\Modules\Dropi\Actions\ConfirmarEmpaqueYSiguiente;
use App\Modules\Dropi\Actions\ProcesarEscaneoEmpaque;
use App\Modules\Dropi\Enums\EstadoPedidoDropi;
use App\Modules\Dropi\Models\DropiPedido;
use App\Modules\Dropi\Models\EmpaqueRegistro;
use BackedEnum;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;

class EstacionEmpaque extends Page
{
    protected string $view = 'dropi.pages.estacion-empaque';

    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-qr-code';

    protected static ?string $navigationLabel = '📦 Estación de Empaque';

    protected static string|\UnitEnum|null $navigationGroup = 'Operación';

    protected static ?int $navigationSort = 0;

    protected static ?string $slug = 'estacion-empaque';

    public string $codigo = '';

    public ?int $pedidoActivoId = null;

    public ?array $ultimoResultado = null;

    public static function canAccess(): bool
    {
        $u = auth()->user();
        if (! $u) {
            return false;
        }
        return $u->esAracely() || $u->hasAnyRole(['Alistador']);
    }

    /**
     * Re-audit DR-α UX (UX-C2) · recuperación de sesión al reload.
     *
     *   Antes: `pedidoActivoId` era estado Livewire volátil. Si la tablet
     *   se refrescaba, perdía WebSocket o el operario cerraba el tab con
     *   5 items pickeados, el pedido activo se perdía y su foto quedaba
     *   huérfana.
     *
     *   Ahora: al montar buscamos el EmpaqueRegistro `en_curso` del
     *   operario logueado y rehidratamos `pedidoActivoId`.
     */
    public function mount(): void
    {
        $registro = EmpaqueRegistro::query()
            ->where('operario_id', auth()->id())
            ->where('estado', 'en_curso')
            ->orderByDesc('id')
            ->first();
        if ($registro) {
            $this->pedidoActivoId = $registro->pedido_id;
        }
    }

    public function escanear(): void
    {
        if (trim($this->codigo) === '') {
            return;
        }

        $resultado = ProcesarEscaneoEmpaque::run(
            codigo: $this->codigo,
            pedidoActivoId: $this->pedidoActivoId,
            operarioId: auth()->id(),
        );

        if ($resultado['tipo'] === 'pedido_abierto') {
            $this->pedidoActivoId = $resultado['pedido_id'];
        }

        $this->ultimoResultado = $resultado;
        $this->codigo = '';

        $this->dispatch('empaque-scan', sonido: $resultado['sonido'], mensaje: $resultado['mensaje']);
    }

    public function confirmarYSiguiente(?array $gps = null): void
    {
        if (! $this->pedidoActivoId) {
            return;
        }

        // Bloqueo: exigir foto del paquete cerrado antes de confirmar
        $registro = EmpaqueRegistro::where('pedido_id', $this->pedidoActivoId)
            ->where('estado', 'en_curso')
            ->latest('inicio_at')->first();

        if (! $registro?->foto_path) {
            Notification::make()
                ->title('Falta la foto del paquete')
                ->body('Toma una foto del paquete cerrado antes de confirmar (evidencia contra reclamos).')
                ->warning()->send();
            $this->dispatch('empaque-scan', sonido: 'warn', mensaje: '📸 Falta foto del paquete');
            return;
        }

        try {
            $res = ConfirmarEmpaqueYSiguiente::run($this->pedidoActivoId, auth()->id(), $gps);
            Notification::make()->title($res['msg'])->success()->send();
            $this->pedidoActivoId = null;
            $this->ultimoResultado = null;

            // Anunciar siguiente pedido en cola si existe
            $siguiente = $this->proximosPedidos(1)[0] ?? null;
            $sig = $siguiente
                ? " Siguiente: {$siguiente['guia']}, {$siguiente['ciudad']}."
                : '';

            $this->dispatch('empaque-scan', sonido: 'ok', mensaje: '✅ ' . $res['msg'] . $sig);
        } catch (\Throwable $e) {
            Notification::make()->title('No se pudo confirmar')->body($e->getMessage())->danger()->send();
        }
    }

    /**
     * Guarda una foto capturada desde la webcam.
     * Se almacena en storage/app/private/empaques (NO servida por Nginx),
     * y se sirve a Aracely/Alistador por EmpaqueFotoController.
     *
     * Valida tamaño antes del base64_decode para evitar memory bombs.
     */
    public function guardarFoto(string $dataUri): void
    {
        if (! $this->pedidoActivoId) {
            return;
        }

        if (! str_starts_with($dataUri, 'data:image/')) {
            Notification::make()->title('Formato inválido')->danger()->send();
            return;
        }

        [$meta, $b64] = explode(',', $dataUri, 2);

        // Guard rail ANTES de decodificar: base64 ≈ 4/3 del binario.
        // Aceptamos hasta 3MB de imagen → 4.1M chars de base64.
        if (strlen($b64) > 4_200_000) {
            Notification::make()->title('La foto pesa demasiado (máx 3MB)')->warning()->send();
            return;
        }

        $ext = str_contains($meta, 'jpeg') ? 'jpg' : (str_contains($meta, 'png') ? 'png' : 'webp');
        $bin = base64_decode($b64, strict: true);

        // Validación real de contenido con GD (previene binarios disfrazados de imagen)
        if (! $bin || @getimagesizefromstring($bin) === false) {
            Notification::make()->title('El archivo no es una imagen válida')->danger()->send();
            return;
        }

        // Storage privado — nunca queda accesible desde el navegador
        // Sufijo aleatorio evita colisión mismo segundo por mismo pedido
        $rel = 'empaques/emp-' . $this->pedidoActivoId . '-' . now()->format('YmdHis') . '-' . \Illuminate\Support\Str::random(6) . '.' . $ext;
        \Illuminate\Support\Facades\Storage::disk('local')->put($rel, $bin);

        // Ownership check: solo el operario que abrió el registro sobreescribe su foto
        $filas = EmpaqueRegistro::where('pedido_id', $this->pedidoActivoId)
            ->where('estado', 'en_curso')
            ->where('operario_id', auth()->id())
            ->update(['foto_path' => $rel, 'foto_at' => now()]);

        if ($filas === 0) {
            Notification::make()->title('No podés subir foto de un pedido de otro operario')->danger()->send();
            return;
        }
        $this->registroCache = null;

        $this->dispatch('empaque-scan', sonido: 'ok', mensaje: '📸 Foto guardada — ya puedes confirmar');
    }

    /**
     * Próximos pedidos pendientes por empacar (para "cola" en el UI y anuncio del siguiente).
     * @return array<int, array{id:int, guia:string, cliente:string, ciudad:string, items:int}>
     */
    public function proximosPedidos(int $limit = 5): array
    {
        return DropiPedido::query()
            ->whereIn('estado', [EstadoPedidoDropi::Pending, EstadoPedidoDropi::Alistando])
            ->when($this->pedidoActivoId, fn ($q) => $q->where('id', '!=', $this->pedidoActivoId))
            ->withCount('items')
            ->orderBy('created_at', 'asc')
            ->limit($limit)
            ->get()
            ->map(fn ($p) => [
                'id' => $p->id,
                'guia' => (string) $p->guia,
                'cliente' => (string) $p->cliente_nombre,
                'ciudad' => (string) $p->cliente_ciudad,
                'items' => (int) $p->items_count,
            ])
            ->toArray();
    }

    public function cancelarPedido(): void
    {
        if ($this->pedidoActivoId) {
            // Re-audit DR-α UX (SEG-M2, UX-C3) · sólo cancela si el operario
            //   ES DUEÑO del EmpaqueRegistro en_curso. Antes: cualquier
            //   Alistador cancelaba el picking de OTRO borrando su trabajo
            //   (el update de items no filtraba por `pickeado_por`).
            $miRegistro = EmpaqueRegistro::where('pedido_id', $this->pedidoActivoId)
                ->where('estado', 'en_curso')
                ->where('operario_id', auth()->id())
                ->first();

            if (! $miRegistro) {
                \Filament\Notifications\Notification::make()
                    ->title('No puedes cancelar')
                    ->body('Este empaque lo tomó otro operario.')
                    ->danger()->send();
                return;
            }

            $miRegistro->update(['estado' => 'anulado', 'fin_at' => now()]);

            // Reset SOLO de items pickeados por ESTE operario (no destruir
            //   trabajo ajeno si por alguna razón hubo picking mixto).
            \App\Modules\Dropi\Models\DropiPedidoItem::where('pedido_id', $this->pedidoActivoId)
                ->where('pickeado_por', auth()->id())
                ->update(['cantidad_pickeada' => 0, 'pickeado_at' => null, 'pickeado_por' => null]);

            \Filament\Notifications\Notification::make()
                ->title('Empaque cancelado')
                ->body('Tu picking de este pedido fue liberado.')
                ->success()->send();
        }
        $this->pedidoActivoId = null;
        $this->ultimoResultado = null;
        $this->registroCache = null;
    }

    public function pedidoActivo(): ?DropiPedido
    {
        if (! $this->pedidoActivoId) {
            return null;
        }

        return DropiPedido::with(['items.variante.producto', 'corte'])->find($this->pedidoActivoId);
    }

    /**
     * Registro de empaque en curso del pedido activo (foto, tiempo, operario).
     * Se cachea en memoria del componente para no golpear BD desde el Blade.
     */
    private ?EmpaqueRegistro $registroCache = null;

    public function registroActivo(): ?EmpaqueRegistro
    {
        if (! $this->pedidoActivoId) {
            return null;
        }
        if ($this->registroCache) {
            return $this->registroCache;
        }
        return $this->registroCache = EmpaqueRegistro::where('pedido_id', $this->pedidoActivoId)
            ->where('estado', 'en_curso')
            ->latest('inicio_at')
            ->first();
    }

    public function metricas(): array
    {
        $hoy = today();
        $miHoy = EmpaqueRegistro::where('operario_id', auth()->id())
            ->where('estado', 'completado')
            ->whereDate('fin_at', $hoy)
            ->get();

        $promedio = $miHoy->avg('duracion_segundos') ?? 0;

        return [
            'mis_empaques_hoy' => $miHoy->count(),
            'promedio_seg' => (int) $promedio,
            'promedio_display' => $promedio > 0 ? gmdate('i:s', (int) $promedio) : '—',
            'mejor_tiempo_seg' => (int) ($miHoy->min('duracion_segundos') ?? 0),
        ];
    }

    public function ranking(): array
    {
        return DB::table('empaques_registro')
            ->join('users', 'users.id', '=', 'empaques_registro.operario_id')
            ->whereDate('fin_at', today())
            ->where('estado', 'completado')
            ->selectRaw('users.name, COUNT(*) as total, AVG(duracion_segundos) as prom')
            ->groupBy('users.id', 'users.name')
            ->orderByDesc('total')
            ->limit(5)
            ->get()
            ->map(fn ($r) => [
                'nombre' => $r->name,
                'total' => (int) $r->total,
                'prom' => (int) ($r->prom ?? 0),
            ])
            ->toArray();
    }

}
