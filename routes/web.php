<?php

use App\Http\Controllers\Cartera\EstadoCuentaController;
use App\Http\Controllers\Cartera\FacturaPdfController;
use App\Http\Controllers\Cartera\PlantillaContactosController;
use App\Http\Controllers\Catalogo\CodigoBarrasController;
use App\Http\Controllers\Catalogo\EtiquetasMasivasController;
use App\Http\Controllers\Compras\ImportacionPdfController;
use App\Http\Controllers\Compras\ImportOCController;
use App\Http\Controllers\Compras\OrdenCompraPdfController;
use App\Http\Controllers\Compras\PlantillaImportOCController;
use App\Http\Controllers\Compras\RecepcionPdfController;
use App\Http\Controllers\Dropi\EscanerController;
use App\Http\Controllers\Dropi\ManifiestoController;
use App\Http\Controllers\Dropi\PlantillaImportProductosController;
use Illuminate\Support\Facades\Route;

Route::get('/', fn () => redirect('/app'));

// Interruptor de canal Empresa <-> Dropi (modo global del panel admin).
Route::middleware(['web', 'auth'])->get('/set-canal/{canal}', function (string $canal) {
    session(['gb_canal' => in_array($canal, ['dropi', 'empresa'], true) ? $canal : 'empresa']);
    return back();
})->name('set.canal');

// ============================================================
// NUEVO STACK · Laravel + Inertia + Vue 3 (bajo /app)
// El panel Filament sigue en /admin durante la migración gradual.
// ============================================================

// Auth (guest)
Route::middleware(['web', 'guest'])->prefix('app')->group(function () {
    Route::get('/login', [\App\Http\Controllers\App\AuthController::class, 'showLogin'])->name('app.login');
    Route::post('/login', [\App\Http\Controllers\App\AuthController::class, 'login'])->middleware('throttle:8,1');

    // F7 · El empleado pone su contraseña por primera vez. Va acá y no en el
    //      grupo de `auth` porque justamente todavía no tiene sesión: el
    //      enlace se lo manda Gestión Humana por WhatsApp.
    Route::get('/activar-portal', [\App\Http\Controllers\App\PortalColaboradorController::class, 'mostrarActivacion'])->name('app.portal.activar');
    Route::post('/activar-portal', [\App\Http\Controllers\App\PortalColaboradorController::class, 'activar'])
        ->middleware('throttle:10,60');
});

// App (auth)
Route::middleware(['web', 'auth'])->prefix('app')->group(function () {
    Route::get('/', \App\Http\Controllers\App\DashboardController::class)->name('app.dashboard');
    // MEJORAS-A · ⌘K búsqueda global
    Route::get('/buscar', \App\Http\Controllers\App\BusquedaGlobalController::class)->name('app.buscar');
    Route::get('/cosas-del-dia', \App\Http\Controllers\App\CosasDelDiaController::class)->name('app.cosas');
    Route::post('/copiloto', [\App\Http\Controllers\App\CopilotoController::class, 'preguntar'])->name('app.copiloto');
    Route::get('/notificaciones', \App\Http\Controllers\App\NotificacionesController::class)->name('app.notificaciones');
    Route::post('/notificaciones/leidas', [\App\Http\Controllers\App\NotificacionesController::class, 'marcarLeidas'])->name('app.notificaciones.leidas');
    Route::get('/mapa-colombia', \App\Http\Controllers\App\MapaColombiaController::class)->name('app.mapa');
    Route::post('/logout', [\App\Http\Controllers\App\AuthController::class, 'logout'])->name('app.logout');

    // Reglas de Negocio (admin)
    Route::get('/reglas', [\App\Http\Controllers\App\ReglasController::class, 'index'])->name('app.reglas');
    Route::post('/reglas', [\App\Http\Controllers\App\ReglasController::class, 'guardar'])->name('app.reglas.guardar');

    // Cartera · Dashboard
    Route::get('/cartera', \App\Http\Controllers\App\CarteraDashboardController::class)->name('app.cartera.dashboard');

    // Cartera · Facturas
    Route::get('/facturas', [\App\Http\Controllers\App\FacturasController::class, 'index'])->name('app.facturas.index');
    Route::get('/facturas/{factura}', [\App\Http\Controllers\App\FacturasController::class, 'show'])->name('app.facturas.show');

    // Rol Facturador · bandeja de pedidos listos para facturar en SIIGO.
    Route::get('/facturacion/bandeja', [\App\Http\Controllers\App\FacturacionController::class, 'bandeja'])->middleware('throttle:30,1')->name('app.facturacion.bandeja');
    Route::get('/facturacion/preview/{pedido}', [\App\Http\Controllers\App\FacturacionController::class, 'preview'])->name('app.facturacion.preview');
    Route::post('/facturacion/facturar/{pedido}', [\App\Http\Controllers\App\FacturacionController::class, 'facturar'])->middleware('throttle:10,1')->name('app.facturacion.facturar');

    // Acciones SIIGO sobre facturas (anular/mail/PDF/XML/errores DIAN).
    Route::post('/facturas/{factura}/siigo/anular', [\App\Http\Controllers\App\FacturasSiigoAccionesController::class, 'anular'])->middleware('throttle:10,1')->name('app.facturas.siigo.anular');
    Route::post('/facturas/{factura}/siigo/reenviar-mail', [\App\Http\Controllers\App\FacturasSiigoAccionesController::class, 'reenviarMail'])->middleware('throttle:20,1')->name('app.facturas.siigo.reenviar-mail');
    Route::get('/facturas/{factura}/siigo/pdf', [\App\Http\Controllers\App\FacturasSiigoAccionesController::class, 'pdf'])->middleware('throttle:30,1')->name('app.facturas.siigo.pdf');
    Route::get('/facturas/{factura}/siigo/xml', [\App\Http\Controllers\App\FacturasSiigoAccionesController::class, 'xml'])->middleware('throttle:30,1')->name('app.facturas.siigo.xml');
    Route::get('/facturas/{factura}/siigo/errores-dian', [\App\Http\Controllers\App\FacturasSiigoAccionesController::class, 'erroresDian'])->middleware('throttle:30,1')->name('app.facturas.siigo.errores-dian');

    // Cartera · Contactos
    Route::get('/contactos', [\App\Http\Controllers\App\ContactosController::class, 'index'])->name('app.contactos.index');
    // Alta y edición de contactos · antes sólo existían en el panel Filament.
    Route::get('/contactos/nuevo', [\App\Http\Controllers\App\ContactosController::class, 'form'])->name('app.contactos.nuevo');
    Route::post('/contactos', [\App\Http\Controllers\App\ContactosController::class, 'guardar'])->name('app.contactos.crear');
    Route::get('/contactos/{contacto}/editar', [\App\Http\Controllers\App\ContactosController::class, 'form'])->name('app.contactos.editar');
    Route::post('/contactos/{contacto}', [\App\Http\Controllers\App\ContactosController::class, 'guardar'])->name('app.contactos.actualizar');
    Route::get('/contactos/{contacto}', [\App\Http\Controllers\App\ContactosController::class, 'show'])->name('app.contactos.show');
    // Cartera de clientes · gerencia asigna o libera la cuenta.
    Route::post('/contactos/{contacto}/vendedor', [\App\Http\Controllers\App\ContactosController::class, 'asignarVendedor'])
        ->name('app.contactos.vendedor');

    // Cartera · Pagos
    // Maestro de métodos de pago y comisiones de vendedores · vivían sólo en
    // el panel Filament, que quedó reservado para Dropi.
    Route::get('/cartera/metodos-pago', [\App\Http\Controllers\App\MetodosPagoController::class, 'index'])->name('app.metodos-pago.index');
    Route::get('/cartera/metodos-pago/tipos-siigo', [\App\Http\Controllers\App\MetodosPagoController::class, 'tiposSiigo'])
        ->middleware('throttle:30,1')->name('app.metodos-pago.tipos-siigo');
    Route::post('/cartera/metodos-pago', [\App\Http\Controllers\App\MetodosPagoController::class, 'guardar'])->name('app.metodos-pago.crear');
    Route::post('/cartera/metodos-pago/{metodo}', [\App\Http\Controllers\App\MetodosPagoController::class, 'guardar'])->name('app.metodos-pago.actualizar');
    Route::delete('/cartera/metodos-pago/{metodo}', [\App\Http\Controllers\App\MetodosPagoController::class, 'eliminar'])->name('app.metodos-pago.eliminar');

    Route::get('/cartera/comisiones', [\App\Http\Controllers\App\ComisionesController::class, 'index'])->name('app.comisiones.index');
    Route::post('/cartera/comisiones/calcular', [\App\Http\Controllers\App\ComisionesController::class, 'calcular'])->name('app.comisiones.calcular');
    Route::post('/cartera/comisiones/{comision}/estado', [\App\Http\Controllers\App\ComisionesController::class, 'cambiarEstado'])->name('app.comisiones.estado');
    Route::post('/cartera/comisiones/config', [\App\Http\Controllers\App\ComisionesController::class, 'guardarConfig'])->name('app.comisiones.config.crear');
    Route::post('/cartera/comisiones/config/{config}', [\App\Http\Controllers\App\ComisionesController::class, 'guardarConfig'])->name('app.comisiones.config.actualizar');
    Route::delete('/cartera/comisiones/config/{config}', [\App\Http\Controllers\App\ComisionesController::class, 'eliminarConfig'])->name('app.comisiones.config.eliminar');

    Route::get('/pagos', [\App\Http\Controllers\App\PagosController::class, 'index'])->name('app.pagos.index');
    // Registrar plata entrando · antes sólo existía en el panel Filament.
    Route::get('/pagos/facturas-pendientes', [\App\Http\Controllers\App\PagosController::class, 'facturasPendientes'])
        ->middleware('throttle:60,1')->name('app.pagos.facturas');
    Route::post('/pagos', [\App\Http\Controllers\App\PagosController::class, 'guardar'])->name('app.pagos.guardar');

    // Cartera · Crédito y cobranza
    Route::get('/credito', [\App\Http\Controllers\App\CreditoController::class, 'index'])->name('app.credito.index');
    // FIL-C · Cartera extras
    Route::get('/cartera/reportes', [\App\Http\Controllers\App\CarteraExtrasController::class, 'reportes'])->name('app.cartera.reportes');
    Route::get('/cartera/cobranzas', [\App\Http\Controllers\App\CarteraExtrasController::class, 'cobranzasIndex'])->name('app.cartera.cobranzas');
    Route::post('/cartera/cobranzas', [\App\Http\Controllers\App\CarteraExtrasController::class, 'cobranzaCrear'])->name('app.cartera.cobranza.crear');
    Route::get('/cartera/solicitudes', [\App\Http\Controllers\App\CarteraExtrasController::class, 'solicitudesIndex'])->name('app.cartera.solicitudes');
    Route::post('/cartera/solicitudes/{solicitud}/resolver', [\App\Http\Controllers\App\CarteraExtrasController::class, 'solicitudResolver'])->name('app.cartera.solicitud.resolver');
    // Re-audit M5 R4 SEG-M4 · throttle en agregados pesados.
    Route::get('/cartera/movimientos', [\App\Http\Controllers\App\CarteraExtrasController::class, 'movimientos'])->middleware('throttle:30,1')->name('app.cartera.movimientos');

    // Compras / Inventario / Contabilidad
    Route::get('/compras', [\App\Http\Controllers\App\ComprasController::class, 'index'])->name('app.compras.index');
    // Re-audit M2 R3 PATRÓN R (SEG-A5) · throttle en agregados/mutaciones.
    Route::get('/compras/reporte', [\App\Http\Controllers\App\ComprasGestionController::class, 'reporte'])->middleware('throttle:30,1')->name('app.compras.reporte');
    Route::get('/compras/oc/nueva', [\App\Http\Controllers\App\ComprasGestionController::class, 'ocForm'])->name('app.compras.oc.nueva');
    Route::post('/compras/oc', [\App\Http\Controllers\App\ComprasGestionController::class, 'ocCrear'])->middleware('throttle:60,1')->name('app.compras.oc.crear');
    Route::get('/compras/oc/{orden}', [\App\Http\Controllers\App\ComprasGestionController::class, 'ocShow'])->name('app.compras.oc.show');
    Route::post('/compras/oc/{orden}/aprobar', [\App\Http\Controllers\App\ComprasGestionController::class, 'ocAprobar'])->middleware('throttle:60,1')->name('app.compras.oc.aprobar');
    Route::post('/compras/oc/{orden}/anular', [\App\Http\Controllers\App\ComprasGestionController::class, 'ocAnular'])->middleware('throttle:20,1')->name('app.compras.oc.anular');
    // Sprint 3 · A.1 · duplicar OC + reenviar recepción a SIIGO.
    Route::post('/compras/oc/{orden}/duplicar', [\App\Http\Controllers\App\ComprasGestionController::class, 'ocDuplicar'])->middleware('throttle:20,1')->name('app.compras.oc.duplicar');
    Route::post('/compras/oc/{orden}/reenviar-siigo', [\App\Http\Controllers\App\ComprasGestionController::class, 'ocReenviarSiigo'])->middleware('throttle:20,1')->name('app.compras.oc.reenviar-siigo');
    Route::post('/compras/recepcion/{recepcion}/reenviar-siigo', [\App\Http\Controllers\App\ComprasGestionController::class, 'recepcionReenviarSiigo'])->middleware('throttle:20,1')->name('app.compras.recepcion.reenviar-siigo');
    // Sprint 4 · G.3 · CRUD Productos Vue formato SIIGO (4 pestañas).
    Route::get('/catalogo/productos', [\App\Http\Controllers\App\ProductosController::class, 'index'])->name('app.catalogo.productos');
    Route::get('/catalogo/productos/verificar-referencia', [\App\Http\Controllers\App\ProductosController::class, 'verificarReferencia']);
    Route::get('/catalogo/productos/sugerir-referencia', [\App\Http\Controllers\App\ProductosController::class, 'sugerirReferencia']);
    Route::get('/catalogo/productos/nuevo', [\App\Http\Controllers\App\ProductosController::class, 'crearForm'])->name('app.catalogo.productos.nuevo');
    Route::get('/catalogo/productos/{producto}', [\App\Http\Controllers\App\ProductosController::class, 'show'])->name('app.catalogo.productos.show');
    Route::post('/catalogo/productos', [\App\Http\Controllers\App\ProductosController::class, 'guardar'])->name('app.catalogo.productos.crear');
    Route::put('/catalogo/productos/{producto}', [\App\Http\Controllers\App\ProductosController::class, 'guardar'])->name('app.catalogo.productos.actualizar');
    // Precio de venta por lista de un producto agregado. Los granulares lo
    // llevan por variante; los agregados no tenian donde, y por eso no se
    // podian vender.
    Route::put('/catalogo/productos/{producto}/precios', [\App\Http\Controllers\App\ProductosController::class, 'guardarPrecios'])->name('app.catalogo.productos.precios');
    Route::delete('/catalogo/productos/{producto}', [\App\Http\Controllers\App\ProductosController::class, 'eliminar'])->name('app.catalogo.productos.eliminar');
    // FASE C · CRUD robusto
    Route::post('/catalogo/productos/{producto}/clonar', [\App\Http\Controllers\App\ProductosController::class, 'clonar'])->middleware('throttle:10,1')->name('app.catalogo.productos.clonar');
    // FASE F3.A17 · bulk-edit throttle: 10/min · opera sobre lote de hasta 1000
    // productos y encola 1 push SIIGO por cada uno. Sin throttle, un script
    // podía saturar la cola y agotar el rate limit SIIGO (60/min).
    Route::post('/catalogo/productos-bulk-edit', [\App\Http\Controllers\App\ProductosController::class, 'bulkEdit'])->middleware('throttle:10,1')->name('app.catalogo.productos.bulk-edit');
    // PROD-13 · bulk push a SIIGO · throttle idéntico a bulk-edit (10/min, 500/lote).
    Route::post('/catalogo/productos-bulk-push-siigo', [\App\Http\Controllers\App\ProductosController::class, 'bulkPushSiigo'])->middleware('throttle:10,1')->name('app.catalogo.productos.bulk-push-siigo');
    Route::get('/catalogo/productos-papelera', [\App\Http\Controllers\App\ProductosController::class, 'papelera'])->name('app.catalogo.productos.papelera');
    Route::post('/catalogo/productos-papelera/{id}/restaurar', [\App\Http\Controllers\App\ProductosController::class, 'restaurar'])->name('app.catalogo.productos.restaurar');
    // FASE D3 · Forzar push manual a SIIGO · FASE F3.A17 throttle 20/min
    // (igual que otros endpoints `reenviar-siigo` del proyecto).
    Route::post('/catalogo/productos/{producto}/forzar-sync', [\App\Http\Controllers\App\ProductosController::class, 'forzarSync'])->middleware('throttle:20,1')->name('app.catalogo.productos.forzar-sync');
    // FASE H6 · Imágenes del producto (hasta 5 slots)
    Route::post('/catalogo/productos/{producto}/imagenes', [\App\Http\Controllers\App\ProductosController::class, 'subirImagen'])->middleware('throttle:30,1')->name('app.catalogo.productos.imagen.subir');
    Route::delete('/catalogo/productos/{producto}/imagenes/{imagen}', [\App\Http\Controllers\App\ProductosController::class, 'eliminarImagen'])->middleware('throttle:30,1')->name('app.catalogo.productos.imagen.eliminar');
    // FASE G · Import / Export Excel SIIGO · throttle para evitar DoS self-inflicted
    // en export (genera XLSX de hasta 5k productos) e import (encola push masivo).
    Route::get('/catalogo/productos-exportar-siigo', [\App\Http\Controllers\App\ProductosController::class, 'exportarExcelSiigo'])->middleware('throttle:10,1')->name('app.catalogo.productos.exportar-siigo');
    Route::get('/catalogo/productos-plantilla-siigo', [\App\Http\Controllers\App\ProductosController::class, 'plantillaExcelSiigo'])->name('app.catalogo.productos.plantilla-siigo');
    Route::post('/catalogo/productos-importar-siigo', [\App\Http\Controllers\App\ProductosController::class, 'importarExcelSiigo'])->middleware('throttle:5,10')->name('app.catalogo.productos.importar-siigo');
    Route::get('/catalogo/productos-importar-siigo/estado', [\App\Http\Controllers\App\ProductosController::class, 'importarEstado'])->name('app.catalogo.productos.importar-siigo.estado');
    Route::get('/catalogo/productos-buscar', [\App\Http\Controllers\App\ProductosController::class, 'buscar'])->name('app.catalogo.productos.buscar');
    Route::get('/catalogo/siigo-grupos', [\App\Http\Controllers\App\ProductosController::class, 'grupos']);
    Route::get('/catalogo/siigo-subgrupos', [\App\Http\Controllers\App\ProductosController::class, 'subgrupos']);
    Route::get('/catalogo/siigo-clases', [\App\Http\Controllers\App\ProductosController::class, 'clases']);
    // Sprint 4 · G.2 · CRUD jerarquía SIIGO.
    Route::get('/catalogo/jerarquia-siigo', [\App\Http\Controllers\App\JerarquiaSiigoController::class, 'index'])->name('app.catalogo.jerarquia-siigo');
    Route::post('/catalogo/jerarquia-siigo/linea', [\App\Http\Controllers\App\JerarquiaSiigoController::class, 'lineaGuardar']);
    Route::delete('/catalogo/jerarquia-siigo/linea/{linea}', [\App\Http\Controllers\App\JerarquiaSiigoController::class, 'lineaEliminar']);
    Route::post('/catalogo/jerarquia-siigo/grupo', [\App\Http\Controllers\App\JerarquiaSiigoController::class, 'grupoGuardar']);
    Route::delete('/catalogo/jerarquia-siigo/grupo/{grupo}', [\App\Http\Controllers\App\JerarquiaSiigoController::class, 'grupoEliminar']);
    Route::post('/catalogo/jerarquia-siigo/subgrupo', [\App\Http\Controllers\App\JerarquiaSiigoController::class, 'subgrupoGuardar']);
    Route::delete('/catalogo/jerarquia-siigo/subgrupo/{subgrupo}', [\App\Http\Controllers\App\JerarquiaSiigoController::class, 'subgrupoEliminar']);
    Route::post('/catalogo/jerarquia-siigo/clase', [\App\Http\Controllers\App\JerarquiaSiigoController::class, 'claseGuardar']);
    Route::delete('/catalogo/jerarquia-siigo/clase/{clase}', [\App\Http\Controllers\App\JerarquiaSiigoController::class, 'claseEliminar']);
    // Sprint 4 · B.3 · CRUD Retenciones (Retefuente/Reteica/Reteiva).
    Route::get('/cartera/retenciones', [\App\Http\Controllers\App\RetencionesController::class, 'index'])->name('app.cartera.retenciones');
    Route::post('/cartera/retenciones', [\App\Http\Controllers\App\RetencionesController::class, 'guardar']);
    Route::delete('/cartera/retenciones/{regla}', [\App\Http\Controllers\App\RetencionesController::class, 'eliminar']);
    // Sprint 4 · B.1 · Notas crédito manuales.
    Route::get('/cartera/notas-credito', [\App\Http\Controllers\App\NotasCreditoController::class, 'index'])->name('app.cartera.notas-credito');
    Route::post('/cartera/notas-credito', [\App\Http\Controllers\App\NotasCreditoController::class, 'crear'])->middleware('throttle:30,1');
    Route::post('/cartera/notas-credito/{notaCredito}/reenviar-siigo', [\App\Http\Controllers\App\NotasCreditoController::class, 'reenviarSiigo'])->middleware('throttle:20,1');
    // Sprint 4 · B.2 · Notas débito.
    Route::get('/cartera/notas-debito', [\App\Http\Controllers\App\NotasDebitoController::class, 'index'])->name('app.cartera.notas-debito');
    Route::post('/cartera/notas-debito', [\App\Http\Controllers\App\NotasDebitoController::class, 'crear'])->middleware('throttle:30,1');
    Route::post('/cartera/notas-debito/{notaDebito}/reenviar-siigo', [\App\Http\Controllers\App\NotasDebitoController::class, 'reenviarSiigo'])->middleware('throttle:20,1');
    // Sprint 4 · B.4 · Asientos manuales.
    Route::get('/contabilidad/asientos-manuales', [\App\Http\Controllers\App\AsientosManualesController::class, 'index'])->name('app.contabilidad.asientos-manuales');
    Route::post('/contabilidad/asientos-manuales', [\App\Http\Controllers\App\AsientosManualesController::class, 'crear'])->middleware('throttle:20,1');
    Route::post('/contabilidad/asientos-manuales/{asientoManual}/aprobar', [\App\Http\Controllers\App\AsientosManualesController::class, 'aprobar'])->middleware('throttle:20,1');
    Route::post('/contabilidad/asientos-manuales/{asientoManual}/reenviar-siigo', [\App\Http\Controllers\App\AsientosManualesController::class, 'reenviarSiigo'])->middleware('throttle:20,1');
    // Sprint 4 · B.3+ · Pagos a proveedor (con retenciones aplicadas).
    Route::get('/cartera/pagos-proveedor', [\App\Http\Controllers\App\PagosProveedorController::class, 'index'])->name('app.cartera.pagos-proveedor');
    Route::post('/cartera/pagos-proveedor', [\App\Http\Controllers\App\PagosProveedorController::class, 'crear'])->middleware('throttle:30,1');
    Route::post('/cartera/pagos-proveedor/{pagoProveedor}/reenviar-siigo', [\App\Http\Controllers\App\PagosProveedorController::class, 'reenviarSiigo'])->middleware('throttle:20,1');
    // COMP-B5 · pago nace pendiente; confirmar dispara el push a SIIGO.
    Route::post('/cartera/pagos-proveedor/{pagoProveedor}/confirmar', [\App\Http\Controllers\App\PagosProveedorController::class, 'confirmar'])->middleware('throttle:30,1')->name('app.cartera.pagos-proveedor.confirmar');
    Route::post('/cartera/pagos-proveedor/{pagoProveedor}/anular', [\App\Http\Controllers\App\PagosProveedorController::class, 'anular'])->middleware('throttle:20,1')->name('app.cartera.pagos-proveedor.anular');
    // QA-FIX #11 · preview retenciones desde el motor real.
    Route::post('/api/retenciones/preview', [\App\Http\Controllers\App\PagosProveedorController::class, 'previewRetenciones'])->middleware('throttle:120,1');
    Route::get('/compras/recepcion/nueva', [\App\Http\Controllers\App\ComprasGestionController::class, 'recepcionForm'])->name('app.compras.recepcion.nueva');
    Route::post('/compras/recepcion', [\App\Http\Controllers\App\ComprasGestionController::class, 'recepcionCrear'])->middleware('throttle:60,1')->name('app.compras.recepcion.crear');
    Route::get('/compras/recepcion/{recepcion}', [\App\Http\Controllers\App\ComprasGestionController::class, 'recepcionShow'])->name('app.compras.recepcion.show');
    Route::get('/compras/importacion', [\App\Http\Controllers\App\ComprasGestionController::class, 'importacionIndex'])->name('app.compras.importacion');
    Route::get('/compras/importacion/nueva', [\App\Http\Controllers\App\ComprasGestionController::class, 'importacionForm'])->name('app.compras.importacion.nueva');
    Route::get('/compras/importacion/{importacion}', [\App\Http\Controllers\App\ComprasGestionController::class, 'importacionShow'])->name('app.compras.importacion.show');
    Route::post('/compras/importacion', [\App\Http\Controllers\App\ComprasGestionController::class, 'importacionCrear'])->middleware('throttle:60,1')->name('app.compras.importacion.crear');
    Route::post('/compras/importacion/{importacion}/gasto', [\App\Http\Controllers\App\ComprasGestionController::class, 'importacionGastoAgregar'])->middleware('throttle:60,1')->name('app.compras.importacion.gasto');
    Route::post('/compras/importacion/{importacion}/liquidar', [\App\Http\Controllers\App\ComprasGestionController::class, 'importacionLiquidar'])->middleware('throttle:20,1')->name('app.compras.importacion.liquidar');
    Route::get('/inventario', [\App\Http\Controllers\App\InventarioController::class, 'index'])->name('app.inventario.index');
    // Importador Excel del cliente Aracely (formato REPORTE · productos agregados)
    Route::get('/inventario/importar-cliente', [\App\Http\Controllers\App\ImportarInventarioClienteController::class, 'show'])->name('app.inventario.importar.cliente');
    Route::post('/inventario/importar-cliente', [\App\Http\Controllers\App\ImportarInventarioClienteController::class, 'procesar'])->middleware('throttle:30,1')->name('app.inventario.importar.cliente.procesar');
    // Re-audit M3 PATRÓN θ (SEG-A3) · throttle en /app/inventario/* — antes sin
    //   límite; kardex agregaba 500 movs, reporte-stock hacía SUM group by sobre
    //   todo el kardex. Un actor autenticado saturaba la DB con requests.
    Route::get('/inventario/kardex', [\App\Http\Controllers\App\InventarioGestionController::class, 'kardex'])->middleware('throttle:30,1')->name('app.inventario.kardex');
    Route::get('/inventario/reporte-stock', [\App\Http\Controllers\App\InventarioGestionController::class, 'reporteStock'])->middleware('throttle:30,1')->name('app.inventario.reporte');
    Route::get('/inventario/conteos', [\App\Http\Controllers\App\InventarioGestionController::class, 'conteosIndex'])->middleware('throttle:60,1')->name('app.inventario.conteos');
    Route::post('/inventario/conteos', [\App\Http\Controllers\App\InventarioGestionController::class, 'conteoCrear'])->middleware('throttle:30,1')->name('app.inventario.conteo.crear');
    Route::get('/inventario/traslados', [\App\Http\Controllers\App\InventarioGestionController::class, 'trasladosIndex'])->middleware('throttle:60,1')->name('app.inventario.traslados');
    Route::post('/inventario/traslados', [\App\Http\Controllers\App\InventarioGestionController::class, 'trasladoCrear'])->middleware('throttle:30,1')->name('app.inventario.traslado.crear');
    // PATRÓN α · UI operativa traslados (Show + repeater items + state machine).
    Route::get('/inventario/traslados/{id}', [\App\Http\Controllers\App\InventarioGestionController::class, 'trasladoShow'])->middleware('throttle:60,1')->name('app.inventario.traslado.show');
    Route::post('/inventario/traslados/{id}/items', [\App\Http\Controllers\App\InventarioGestionController::class, 'trasladoItemGuardar'])->middleware('throttle:60,1')->name('app.inventario.traslado.item.guardar');
    Route::delete('/inventario/traslados/{id}/items/{itemId}', [\App\Http\Controllers\App\InventarioGestionController::class, 'trasladoItemEliminar'])->middleware('throttle:60,1')->name('app.inventario.traslado.item.eliminar');
    Route::post('/inventario/traslados/{id}/enviar', [\App\Http\Controllers\App\InventarioGestionController::class, 'trasladoEnviar'])->middleware('throttle:30,1')->name('app.inventario.traslado.enviar');
    Route::post('/inventario/traslados/{id}/recibir', [\App\Http\Controllers\App\InventarioGestionController::class, 'trasladoRecibir'])->middleware('throttle:30,1')->name('app.inventario.traslado.recibir');
    Route::post('/inventario/traslados/{id}/anular', [\App\Http\Controllers\App\InventarioGestionController::class, 'trasladoAnular'])->middleware('throttle:30,1')->name('app.inventario.traslado.anular');
    // PATRÓN α · UI captura conteo físico (Show + iniciar + guardar item + cerrar).
    Route::get('/inventario/conteos/{id}', [\App\Http\Controllers\App\InventarioGestionController::class, 'conteoShow'])->middleware('throttle:60,1')->name('app.inventario.conteo.show');
    Route::post('/inventario/conteos/{id}/iniciar', [\App\Http\Controllers\App\InventarioGestionController::class, 'conteoIniciar'])->middleware('throttle:20,1')->name('app.inventario.conteo.iniciar');
    Route::post('/inventario/conteos/{id}/items/{itemId}', [\App\Http\Controllers\App\InventarioGestionController::class, 'conteoItemGuardar'])->middleware('throttle:120,1')->name('app.inventario.conteo.item');
    Route::post('/inventario/conteos/{id}/cerrar', [\App\Http\Controllers\App\InventarioGestionController::class, 'conteoCerrar'])->middleware('throttle:20,1')->name('app.inventario.conteo.cerrar');
    // Sprint 3 · A.4 · anular en curso + eliminar borrador vacío.
    Route::post('/inventario/conteos/{id}/anular', [\App\Http\Controllers\App\InventarioGestionController::class, 'conteoAnular'])->middleware('throttle:20,1')->name('app.inventario.conteo.anular');
    Route::delete('/inventario/conteos/{id}', [\App\Http\Controllers\App\InventarioGestionController::class, 'conteoEliminar'])->middleware('throttle:20,1')->name('app.inventario.conteo.eliminar');
    // UBIC-2 · CRUD Ubicaciones (bodegas / puntos de venta).
    Route::get('/inventario/ubicaciones', [\App\Http\Controllers\App\InventarioUbicacionesController::class, 'index'])->middleware('throttle:60,1')->name('app.inventario.ubicaciones');
    Route::post('/inventario/ubicaciones', [\App\Http\Controllers\App\InventarioUbicacionesController::class, 'guardar'])->middleware('throttle:30,1')->name('app.inventario.ubicacion.guardar');
    // UBIC-4 · sync resoluciones DIAN desde SIIGO (document-types tipo FV).
    Route::post('/inventario/ubicaciones/sync-resoluciones', [\App\Http\Controllers\App\InventarioUbicacionesController::class, 'sincronizarResoluciones'])->middleware('throttle:5,1')->name('app.inventario.ubicacion.sync-resoluciones');
    // UBIC-6 · marca una resolución SIIGO como default para auto-asignación.
    Route::post('/inventario/ubicaciones/resolucion-default', [\App\Http\Controllers\App\InventarioUbicacionesController::class, 'marcarResolucionDefault'])->middleware('throttle:10,1')->name('app.inventario.ubicacion.resolucion-default');
    // UBIC-8 · crear admin bodega sin salir del CRUD de ubicaciones.
    Route::post('/inventario/ubicaciones/admin-bodega', [\App\Http\Controllers\App\InventarioUbicacionesController::class, 'crearAdminBodega'])->middleware('throttle:10,1')->name('app.inventario.ubicacion.admin-bodega');
    // UBIC-9 · botón manual "Enviar a SIIGO" (crear o re-enviar warehouse).
    Route::post('/inventario/ubicaciones/{id}/enviar-siigo', [\App\Http\Controllers\App\InventarioUbicacionesController::class, 'enviarASiigo'])->middleware('throttle:20,1')->name('app.inventario.ubicacion.enviar-siigo');
    Route::post('/inventario/ubicaciones/{id}/toggle', [\App\Http\Controllers\App\InventarioUbicacionesController::class, 'toggleActiva'])->middleware('throttle:30,1')->name('app.inventario.ubicacion.toggle');
    Route::delete('/inventario/ubicaciones/{id}', [\App\Http\Controllers\App\InventarioUbicacionesController::class, 'eliminar'])->middleware('throttle:30,1')->name('app.inventario.ubicacion.eliminar');
    Route::get('/inventario/alertas', [\App\Http\Controllers\App\InventarioGestionController::class, 'alertasIndex'])->middleware('throttle:60,1')->name('app.inventario.alertas');
    Route::post('/inventario/alertas', [\App\Http\Controllers\App\InventarioGestionController::class, 'alertaGuardar'])->middleware('throttle:30,1')->name('app.inventario.alerta.guardar');
    // INV-B2 · Import masivo Excel + plantilla descargable.
    Route::get('/inventario/plantilla-masiva.xlsx', [\App\Http\Controllers\App\InventarioGestionController::class, 'plantillaMasivaExcel'])->middleware('throttle:30,1')->name('app.inventario.plantilla-masiva');
    Route::post('/inventario/conteos/{id}/importar', [\App\Http\Controllers\App\InventarioGestionController::class, 'conteoImportarMasivo'])->middleware('throttle:20,1')->name('app.inventario.conteo.importar');
    Route::post('/inventario/traslados/{id}/importar', [\App\Http\Controllers\App\InventarioGestionController::class, 'trasladoImportarMasivo'])->middleware('throttle:20,1')->name('app.inventario.traslado.importar');
    Route::post('/inventario/alertas/importar', [\App\Http\Controllers\App\InventarioGestionController::class, 'alertasImportarMasivo'])->middleware('throttle:20,1')->name('app.inventario.alerta.importar');
    // INV-B1 · Buscador inteligente global en Inventario/Logística.
    Route::get('/inventario/buscador', [\App\Http\Controllers\App\InventarioGestionController::class, 'buscadorInteligente'])->middleware('throttle:120,1')->name('app.inventario.buscador');

    // COMP-B1 · Devoluciones a proveedor (wizard Vue + push a NC compra SIIGO).
    Route::get('/compras/devoluciones', [\App\Http\Controllers\App\DevolucionProveedorController::class, 'index'])->name('app.compras.devoluciones.index');
    Route::get('/compras/devoluciones/nueva', [\App\Http\Controllers\App\DevolucionProveedorController::class, 'create'])->name('app.compras.devoluciones.crear');
    Route::get('/compras/devoluciones/items/buscar', [\App\Http\Controllers\App\DevolucionProveedorController::class, 'buscarItems'])->middleware('throttle:120,1')->name('app.compras.devoluciones.items.buscar');
    Route::get('/compras/devoluciones/{id}', [\App\Http\Controllers\App\DevolucionProveedorController::class, 'show'])->where('id', '[0-9]+')->name('app.compras.devoluciones.show');
    Route::post('/compras/devoluciones', [\App\Http\Controllers\App\DevolucionProveedorController::class, 'guardar'])->middleware('throttle:30,1')->name('app.compras.devoluciones.guardar');
    Route::post('/compras/devoluciones/{id}/items', [\App\Http\Controllers\App\DevolucionProveedorController::class, 'itemGuardar'])->where('id', '[0-9]+')->middleware('throttle:60,1')->name('app.compras.devoluciones.item.guardar');
    Route::delete('/compras/devoluciones/{id}/items/{itemId}', [\App\Http\Controllers\App\DevolucionProveedorController::class, 'itemEliminar'])->where(['id' => '[0-9]+', 'itemId' => '[0-9]+'])->middleware('throttle:60,1')->name('app.compras.devoluciones.item.eliminar');
    Route::get('/compras/devoluciones/{id}/preview-asiento', [\App\Http\Controllers\App\DevolucionProveedorController::class, 'previewAsiento'])->where('id', '[0-9]+')->middleware('throttle:60,1')->name('app.compras.devoluciones.preview');
    Route::post('/compras/devoluciones/{id}/confirmar', [\App\Http\Controllers\App\DevolucionProveedorController::class, 'confirmar'])->where('id', '[0-9]+')->middleware('throttle:20,1')->name('app.compras.devoluciones.confirmar');
    Route::post('/compras/devoluciones/{id}/reenviar-siigo', [\App\Http\Controllers\App\DevolucionProveedorController::class, 'reenviarSiigo'])->where('id', '[0-9]+')->middleware('throttle:20,1')->name('app.compras.devoluciones.reenviar-siigo');
    Route::delete('/compras/devoluciones/{id}', [\App\Http\Controllers\App\DevolucionProveedorController::class, 'descartar'])->where('id', '[0-9]+')->middleware('throttle:20,1')->name('app.compras.devoluciones.descartar');
    Route::delete('/inventario/alertas/{id}', [\App\Http\Controllers\App\InventarioGestionController::class, 'alertaEliminar'])->middleware('throttle:30,1')->name('app.inventario.alerta.eliminar');
    Route::get('/inventario/buscar-variantes', [\App\Http\Controllers\App\InventarioGestionController::class, 'buscarVariantes'])->middleware('throttle:60,1')->name('app.inventario.buscar.variantes');
    // Re-audit M5 SEG-A1 · throttle:30,1 en agregados pesados. Sin él, un
    // usuario autenticado puede tumbar la DB spammeando reportes con rangos
    // de años. `reportes` (landing estática) queda sin throttle porque no
    // consulta agregados.
    Route::get('/contabilidad', [\App\Http\Controllers\App\ContabilidadController::class, 'index'])->middleware('throttle:30,1')->name('app.contabilidad.index');
    Route::get('/contabilidad/panel', [\App\Http\Controllers\App\ContabilidadExtrasController::class, 'panel'])->middleware('throttle:30,1')->name('app.contabilidad.panel');
    // CONT-C1 · Dashboard único de documentos pendientes de SIIGO.
    Route::get('/contabilidad/pendientes-siigo', [\App\Http\Controllers\App\ContabilidadPendientesSiigoController::class, 'index'])->middleware('throttle:30,1')->name('app.contabilidad.pendientes-siigo');

    // CONT-C2/C3/C4 · Discrepancias ERP ↔ SIIGO + ver journal + reintentar.
    Route::get('/contabilidad/discrepancias-siigo', [\App\Http\Controllers\App\ContabilidadDiscrepanciasController::class, 'index'])->middleware('throttle:30,1')->name('app.contabilidad.discrepancias-siigo');
    Route::get('/contabilidad/asientos-manuales/{asiento}/ver-siigo', [\App\Http\Controllers\App\ContabilidadDiscrepanciasController::class, 'verSiigo'])->middleware('throttle:30,1')->name('app.contabilidad.asiento.ver-siigo');
    // CONT-C5 · Validación mapeo PUC → SIIGO (diagnóstico antes del push).
    Route::get('/contabilidad/validacion-puc-siigo', [\App\Http\Controllers\App\ValidacionPucSiigoController::class, 'index'])->middleware('throttle:30,1')->name('app.contabilidad.validacion-puc-siigo');
    Route::get('/contabilidad/reportes', [\App\Http\Controllers\App\ContabilidadExtrasController::class, 'reportes'])->name('app.contabilidad.reportes');
    // Los dos estados financieros que el hub listaba como «no listo».
    Route::get('/contabilidad/balance-general', [\App\Http\Controllers\App\ContabilidadExtrasController::class, 'balanceGeneral'])->middleware('throttle:30,1')->name('app.contabilidad.balance-general');
    Route::get('/contabilidad/estado-resultados', [\App\Http\Controllers\App\ContabilidadExtrasController::class, 'estadoResultados'])->middleware('throttle:30,1')->name('app.contabilidad.estado-resultados');
    // CONT-C8 · Export CSV con gate esRoot (ver controller).
    Route::get('/contabilidad/reportes/exportar-csv', [\App\Http\Controllers\App\ContabilidadExtrasController::class, 'exportarCsv'])->middleware('throttle:10,1')->name('app.contabilidad.reportes.exportar-csv');
    Route::get('/contabilidad/reporte-detalle', [\App\Http\Controllers\App\ContabilidadExtrasController::class, 'reporteDetalle'])->middleware('throttle:60,1')->name('app.contabilidad.detalle');
    // Reportes que genera SIIGO (no el ERP) · sirven para contrastar la
    // contabilidad local contra la oficial.
    Route::post('/contabilidad/siigo/balance-prueba', [\App\Http\Controllers\App\ContabilidadExtrasController::class, 'siigoBalancePrueba'])->middleware('throttle:10,1')->name('app.contabilidad.siigo.balance-prueba');
    Route::get('/contabilidad/siigo/cuentas-por-pagar', [\App\Http\Controllers\App\ContabilidadExtrasController::class, 'siigoCuentasPorPagar'])->middleware('throttle:20,1')->name('app.contabilidad.siigo.cuentas-por-pagar');
    Route::get('/contabilidad/siigo/conciliacion', [\App\Http\Controllers\App\ContabilidadExtrasController::class, 'conciliacionFacturas'])->middleware('throttle:20,1')->name('app.contabilidad.siigo.conciliacion');

    // Plantillas de documento WYSIWYG (Aracely)
    // Roles y permisos · el admin crea el rol, marca qué ve y elige quién lo tiene.
    Route::get('/roles', [\App\Http\Controllers\App\RolesController::class, 'index'])->name('app.roles.index');
    Route::get('/roles/nuevo', [\App\Http\Controllers\App\RolesController::class, 'form'])->name('app.roles.nuevo');
    Route::post('/roles', [\App\Http\Controllers\App\RolesController::class, 'guardar'])->middleware('throttle:30,1')->name('app.roles.crear');
    Route::get('/roles/{rol}', [\App\Http\Controllers\App\RolesController::class, 'form'])->whereNumber('rol')->name('app.roles.editar');
    Route::post('/roles/{rol}', [\App\Http\Controllers\App\RolesController::class, 'guardar'])->whereNumber('rol')->middleware('throttle:30,1')->name('app.roles.guardar');
    Route::delete('/roles/{rol}', [\App\Http\Controllers\App\RolesController::class, 'eliminar'])->whereNumber('rol')->middleware('throttle:10,1')->name('app.roles.eliminar');

    Route::get('/plantillas', [\App\Http\Controllers\App\PlantillasController::class, 'index'])->name('app.plantillas.index');
    Route::post('/plantillas', [\App\Http\Controllers\App\PlantillasController::class, 'guardar'])->name('app.plantillas.guardar');
    Route::delete('/plantillas/{plantilla}', [\App\Http\Controllers\App\PlantillasController::class, 'eliminar'])->name('app.plantillas.eliminar');
    Route::post('/plantillas/preview', [\App\Http\Controllers\App\PlantillasController::class, 'preview'])
        ->middleware('throttle:30,1')->name('app.plantillas.preview');

    // Dropi / Catálogo / Empresa / SIIGO
    Route::get('/dropi', [\App\Http\Controllers\App\DropiController::class, 'index'])->name('app.dropi.index');
    // Dropi · Operación (DRP-A: Alistador, Devolución, Escáner cámara, Discrepancias)
    Route::get('/dropi/alistador', [\App\Http\Controllers\App\DropiOperacionController::class, 'alistador'])->name('app.dropi.alistador');
    Route::post('/dropi/alistador/heartbeat', [\App\Http\Controllers\App\DropiOperacionController::class, 'heartbeat'])
        ->middleware('throttle:120,1')  // Re-audit N8/H5 · 2 req/s por IP tope
        ->name('app.dropi.alistador.heartbeat');
    // Re-audit DR-ι (SEG-M4) · throttle en flujo alistador — antes sin límite:
    //   spam de despachos + WhatsApp job por segundo. Ahora 30/min tope.
    Route::post('/dropi/alistador/pedido/{pedido}/tomar', [\App\Http\Controllers\App\DropiOperacionController::class, 'tomarPedido'])->middleware('throttle:60,1')->name('app.dropi.alistador.tomar');
    Route::post('/dropi/alistador/pedido/{pedido}/empacar', [\App\Http\Controllers\App\DropiOperacionController::class, 'empacarPedido'])->middleware('throttle:60,1')->name('app.dropi.alistador.empacar');
    Route::post('/dropi/alistador/pedido/{pedido}/despachar', [\App\Http\Controllers\App\DropiOperacionController::class, 'despacharPedido'])->middleware('throttle:30,1')->name('app.dropi.alistador.despachar');
    Route::get('/dropi/devolucion/registrar', [\App\Http\Controllers\App\DropiOperacionController::class, 'devolucionForm'])->name('app.dropi.devolucion.registrar');
    Route::post('/dropi/devolucion/registrar', [\App\Http\Controllers\App\DropiOperacionController::class, 'devolucionGuardar'])->middleware('throttle:30,1')->name('app.dropi.devolucion.guardar');
    Route::get('/dropi/escaner-camara', [\App\Http\Controllers\App\DropiOperacionController::class, 'escanerCamara'])->name('app.dropi.escaner-camara');
    Route::post('/dropi/escaner/buscar', [\App\Http\Controllers\App\DropiOperacionController::class, 'escanerBuscar'])->middleware('throttle:60,1')->name('app.dropi.escaner.buscar');
    Route::get('/dropi/discrepancias', [\App\Http\Controllers\App\DropiOperacionController::class, 'discrepancias'])->name('app.dropi.discrepancias');

    // Dropi · Gestión (DRP-B)
    Route::get('/dropi/pedidos/{pedido}/editar', [\App\Http\Controllers\App\DropiGestionController::class, 'pedidoEditar'])->name('app.dropi.pedido.editar');
    Route::match(['put', 'post'], '/dropi/pedidos/{pedido}/actualizar', [\App\Http\Controllers\App\DropiGestionController::class, 'pedidoActualizar'])->name('app.dropi.pedido.actualizar');
    Route::get('/dropi/cortes', [\App\Http\Controllers\App\DropiGestionController::class, 'cortesIndex'])->name('app.dropi.cortes');
    Route::post('/dropi/cortes', [\App\Http\Controllers\App\DropiGestionController::class, 'corteCrear'])->name('app.dropi.corte.crear');
    Route::post('/dropi/cortes/{corte}/cerrar', [\App\Http\Controllers\App\DropiGestionController::class, 'corteCerrar'])->name('app.dropi.corte.cerrar');
    Route::get('/dropi/wallet', [\App\Http\Controllers\App\DropiGestionController::class, 'walletIndex'])->name('app.dropi.wallet');
    Route::post('/dropi/wallet', [\App\Http\Controllers\App\DropiGestionController::class, 'walletCrear'])->name('app.dropi.wallet.crear');
    Route::get('/dropi/ubicaciones', [\App\Http\Controllers\App\DropiGestionController::class, 'ubicacionesIndex'])->name('app.dropi.ubicaciones');
    Route::post('/dropi/ubicaciones', [\App\Http\Controllers\App\DropiGestionController::class, 'ubicacionGuardar'])->name('app.dropi.ubicacion.guardar');
    Route::delete('/dropi/ubicaciones/{ubicacion}', [\App\Http\Controllers\App\DropiGestionController::class, 'ubicacionEliminar'])->name('app.dropi.ubicacion.eliminar');

    // Dropi · Reportes/Inventario/Import (DRP-C)
    Route::get('/dropi/inventario-en-vivo', [\App\Http\Controllers\App\DropiReportesController::class, 'inventarioEnVivo'])->name('app.dropi.inventario');
    Route::get('/dropi/reportes', [\App\Http\Controllers\App\DropiReportesController::class, 'reportes'])->name('app.dropi.reportes');
    Route::get('/dropi/productos/importar', [\App\Http\Controllers\App\DropiReportesController::class, 'importarForm'])->name('app.dropi.productos.importar');
    Route::post('/dropi/productos/importar', [\App\Http\Controllers\App\DropiReportesController::class, 'importarProcesar'])->name('app.dropi.productos.importar.procesar');
    Route::get('/catalogo', [\App\Http\Controllers\App\CatalogoController::class, 'index'])->name('app.catalogo.index');
    Route::get('/empresa', [\App\Http\Controllers\App\EmpresaController::class, 'index'])->name('app.empresa.index');
    Route::post('/empresa', [\App\Http\Controllers\App\EmpresaController::class, 'guardar'])->name('app.empresa.guardar');
    Route::get('/siigo', [\App\Http\Controllers\App\SiigoController::class, 'index'])->name('app.siigo.index');
    // F8 · panel de control del sync SIIGO
    Route::post('/siigo/kill-switch', [\App\Http\Controllers\App\SiigoController::class, 'toggleKillSwitch'])->name('app.siigo.kill-switch');
    // Credenciales + prueba de conexión · vivían en la pantalla de Filament,
    // que se retiró al dejar /admin sólo para Dropi.
    Route::post('/siigo/credenciales', [\App\Http\Controllers\App\SiigoController::class, 'guardarCredenciales'])->name('app.siigo.credenciales');
    Route::post('/siigo/probar-conexion', [\App\Http\Controllers\App\SiigoController::class, 'probarConexion'])->name('app.siigo.probar');
    Route::post('/siigo/logs/{log}/reintentar', [\App\Http\Controllers\App\SiigoController::class, 'reintentar'])->name('app.siigo.reintentar');
    Route::get('/siigo/logs', [\App\Http\Controllers\App\SiigoController::class, 'logs'])->name('app.siigo.logs');
    // Visor en vivo · trae lo que SIIGO tiene (comprobación bidireccional del CRUD).
    Route::get('/siigo/verificar/producto/{producto}', [\App\Http\Controllers\App\SiigoController::class, 'verificarProducto'])->name('app.siigo.verificar.producto');
    // UBIC-10 · Descargar el Excel de saldos iniciales con layout nativo SIIGO.
    Route::get('/siigo/saldos-iniciales.xlsx', [\App\Http\Controllers\App\SiigoController::class, 'descargarSaldosIniciales'])->middleware('throttle:10,1')->name('app.siigo.saldos-iniciales');
    // PROD-14 · reporte global de discrepancias ERP↔SIIGO.
    Route::get('/siigo/discrepancias', [\App\Http\Controllers\App\SiigoController::class, 'discrepancias'])->name('app.siigo.discrepancias');
    Route::get('/siigo/discrepancias/calcular', [\App\Http\Controllers\App\SiigoController::class, 'discrepanciasCalcular'])->middleware('throttle:5,1')->name('app.siigo.discrepancias.calcular');
    // Reconciliar · dispara pull completo SIIGO→ERP + detección de zombies.
    Route::post('/siigo/reconciliar', [\App\Http\Controllers\App\SiigoController::class, 'reconciliar'])->name('app.siigo.reconciliar');
    Route::get('/siigo/reconciliar/estado', [\App\Http\Controllers\App\SiigoController::class, 'reconciliarEstado'])->name('app.siigo.reconciliar.estado');
    // FASE E · Semáforo global ligero
    Route::get('/siigo/semaforo', [\App\Http\Controllers\App\SiigoController::class, 'semaforo'])->name('app.siigo.semaforo');
    Route::post('/siigo/reconciliar/deshacer', [\App\Http\Controllers\App\SiigoController::class, 'deshacerUltimaReconciliacion'])->name('app.siigo.reconciliar.deshacer');
    // Importar un producto SIIGO por su code (útil para sandbox compartido).
    Route::post('/siigo/importar-por-code', [\App\Http\Controllers\App\SiigoController::class, 'importarPorCode'])->name('app.siigo.importar-code');
    // Sincronizar catálogos SIIGO (taxes, account-groups, warehouses, price-lists, etc).
    Route::post('/siigo/sincronizar-catalogos', [\App\Http\Controllers\App\SiigoController::class, 'sincronizarCatalogos'])->name('app.siigo.sync-catalogos');
    // Catálogo /v1/document-types (FC/FV/NC/ND/RP/DS/CC/RC) para alimentar los
    // selectores de settings `siigo.doc_type_*` en el panel Reglas tras conectar
    // la cuenta real del cliente. GET devuelve agrupado por type; POST re-sincroniza.
    Route::get('/siigo/document-types', [\App\Http\Controllers\App\SiigoController::class, 'documentTypes'])->name('app.siigo.document-types');
    Route::post('/siigo/document-types/sync', [\App\Http\Controllers\App\SiigoController::class, 'documentTypesSync'])->middleware('throttle:5,1')->name('app.siigo.document-types.sync');
    // Catálogo /v1/taxes agrupado por tipo (IVA, Retefuente, ReteICA, etc.)
    // Alimenta los selectores de settings `siigo.tax_id_*` en el panel Reglas.
    Route::get('/siigo/taxes', [\App\Http\Controllers\App\SiigoController::class, 'taxes'])->name('app.siigo.taxes');
    Route::post('/siigo/taxes/sync', [\App\Http\Controllers\App\SiigoController::class, 'taxesSync'])->middleware('throttle:5,1')->name('app.siigo.taxes.sync');
    // Plan de cuentas (PUC) en Vue · reutiliza ImportadorPlanCuentas + PlanCuenta existentes
    Route::get('/contabilidad/plan-cuentas', [\App\Http\Controllers\App\PlanCuentasController::class, 'index'])->name('app.contabilidad.plan-cuentas');
    Route::post('/contabilidad/plan-cuentas', [\App\Http\Controllers\App\PlanCuentasController::class, 'guardar'])->name('app.contabilidad.plan-cuentas.guardar');
    Route::delete('/contabilidad/plan-cuentas/{planCuenta}', [\App\Http\Controllers\App\PlanCuentasController::class, 'eliminar'])->name('app.contabilidad.plan-cuentas.eliminar');
    Route::post('/contabilidad/plan-cuentas/importar', [\App\Http\Controllers\App\PlanCuentasController::class, 'importar'])->name('app.contabilidad.plan-cuentas.importar');
    Route::get('/contabilidad/plan-cuentas/plantilla', [\App\Http\Controllers\App\PlanCuentasController::class, 'plantilla'])->name('app.contabilidad.plan-cuentas.plantilla');
    // FIL-E · Catálogo maestras + Bandeja
    Route::get('/catalogo/maestras', [\App\Http\Controllers\App\CatalogoMaestrasController::class, 'index'])->name('app.catalogo.maestras');
    Route::post('/catalogo/marca', [\App\Http\Controllers\App\CatalogoMaestrasController::class, 'marcaGuardar']);
    Route::delete('/catalogo/marca/{marca}', [\App\Http\Controllers\App\CatalogoMaestrasController::class, 'marcaEliminar']);
    Route::post('/catalogo/categoria', [\App\Http\Controllers\App\CatalogoMaestrasController::class, 'categoriaGuardar']);
    Route::delete('/catalogo/categoria/{categoria}', [\App\Http\Controllers\App\CatalogoMaestrasController::class, 'categoriaEliminar']);
    Route::post('/catalogo/color', [\App\Http\Controllers\App\CatalogoMaestrasController::class, 'colorGuardar']);
    Route::delete('/catalogo/color/{color}', [\App\Http\Controllers\App\CatalogoMaestrasController::class, 'colorEliminar']);
    Route::get('/bandeja-importaciones', [\App\Http\Controllers\App\BandejaImportacionesController::class, 'index'])->name('app.bandeja');

    // M10 · Gastos y reembolsos
    Route::get('/gastos', [\App\Http\Controllers\App\GastosController::class, 'index'])->name('app.gastos');
    Route::post('/gastos', [\App\Http\Controllers\App\GastosController::class, 'crear'])->name('app.gastos.crear');
    Route::post('/gastos/{gasto}/aprobar', [\App\Http\Controllers\App\GastosController::class, 'aprobar'])->name('app.gastos.aprobar');
    Route::post('/gastos/{gasto}/rechazar', [\App\Http\Controllers\App\GastosController::class, 'rechazar'])->name('app.gastos.rechazar');
    Route::post('/gastos/{gasto}/pagado', [\App\Http\Controllers\App\GastosController::class, 'marcarPagado'])->name('app.gastos.pagado');

    // M9 · Gestión Humana
    // F1 · Requisiciones. Van primero porque son el arranque del proceso, y
    //      con su propio permiso: el líder de área entra acá sin ver el resto
    //      de Gestión Humana (salarios y hojas de vida de toda la empresa).
    Route::get('/rrhh/requisiciones', [\App\Http\Controllers\App\RequisicionesController::class, 'index'])->name('app.rrhh.requisiciones');
    Route::post('/rrhh/requisiciones', [\App\Http\Controllers\App\RequisicionesController::class, 'store'])->name('app.rrhh.requisicion.crear');
    Route::get('/rrhh/requisiciones/{requisicion}', [\App\Http\Controllers\App\RequisicionesController::class, 'show'])->name('app.rrhh.requisicion.show');
    Route::put('/rrhh/requisiciones/{requisicion}', [\App\Http\Controllers\App\RequisicionesController::class, 'update'])->name('app.rrhh.requisicion.actualizar');
    Route::post('/rrhh/requisiciones/{requisicion}/enviar', [\App\Http\Controllers\App\RequisicionesController::class, 'enviar'])->name('app.rrhh.requisicion.enviar');
    Route::post('/rrhh/requisiciones/{requisicion}/aprobar', [\App\Http\Controllers\App\RequisicionesController::class, 'aprobar'])->name('app.rrhh.requisicion.aprobar');
    Route::post('/rrhh/requisiciones/{requisicion}/rechazar', [\App\Http\Controllers\App\RequisicionesController::class, 'rechazar'])->name('app.rrhh.requisicion.rechazar');
    Route::post('/rrhh/requisiciones/{requisicion}/vacante', [\App\Http\Controllers\App\RequisicionesController::class, 'abrirVacante'])->name('app.rrhh.requisicion.vacante');
    // F2 · El jefe elige de la terna desde SU pantalla, sin entrar a Gestión Humana.
    Route::post('/rrhh/candidatos/{candidato}/elegir', [\App\Http\Controllers\App\RequisicionesController::class, 'elegirDeLaTerna'])->name('app.rrhh.candidato.elegir');

    Route::get('/rrhh/vacantes', [\App\Http\Controllers\App\RrhhController::class, 'vacantesIndex'])->name('app.rrhh.vacantes');
    Route::post('/rrhh/vacantes', [\App\Http\Controllers\App\RrhhController::class, 'vacanteCrear'])->name('app.rrhh.vacante.crear');
    // F2 · La vacante se abre directo en su tablero de selección: la pantalla
    //      vieja escribía etapas que ya no existen y rompía al leerlas.
    Route::get('/rrhh/vacantes/{vacante}', [\App\Http\Controllers\App\SeleccionController::class, 'tablero'])->name('app.rrhh.vacante.show');
    Route::post('/rrhh/vacantes/{vacante}/candidatos', [\App\Http\Controllers\App\SeleccionController::class, 'crear'])->name('app.rrhh.candidato.crear');
    Route::post('/rrhh/candidatos/{candidato}/mover', [\App\Http\Controllers\App\SeleccionController::class, 'mover'])->name('app.rrhh.candidato.mover');
    Route::post('/rrhh/candidatos/{candidato}/descartar', [\App\Http\Controllers\App\SeleccionController::class, 'descartar'])->name('app.rrhh.candidato.descartar');
    Route::post('/rrhh/candidatos/{candidato}/antecedentes', [\App\Http\Controllers\App\SeleccionController::class, 'antecedentes'])->name('app.rrhh.candidato.antecedentes');
    Route::post('/rrhh/candidatos/{candidato}/valorar', [\App\Http\Controllers\App\SeleccionController::class, 'valorar'])->name('app.rrhh.candidato.valorar');
    Route::get('/rrhh/candidatos/{candidato}/hoja-de-vida', [\App\Http\Controllers\App\SeleccionController::class, 'verHojaDeVida'])->name('app.rrhh.candidato.hv');
    Route::get('/rrhh/empleados', [\App\Http\Controllers\App\RrhhController::class, 'empleadosIndex'])->name('app.rrhh.empleados');
    Route::post('/rrhh/empleados', [\App\Http\Controllers\App\RrhhController::class, 'empleadoCrear'])->name('app.rrhh.empleado.crear');

    // F7 · «Mi espacio»: lo que el empleado ve de sí mismo.
    Route::get('/mi-espacio', [\App\Http\Controllers\App\PortalColaboradorController::class, 'miEspacio'])->name('app.miespacio');
    Route::post('/mi-espacio/asignaciones/{asignacion}/reportar', [\App\Http\Controllers\App\PortalColaboradorController::class, 'reportarNovedad'])->name('app.miespacio.reportar');
    Route::get('/mi-espacio/documentos/{documento}', [\App\Http\Controllers\App\PortalColaboradorController::class, 'miDocumento'])->name('app.miespacio.documento');
    Route::post('/rrhh/empleados/{empleado}/acceso', [\App\Http\Controllers\App\EmpleadoController::class, 'crearAcceso'])->name('app.rrhh.empleado.acceso');

    // F9 · Dotación y herramientas: qué tiene cada quien.
    Route::get('/rrhh/asignaciones', [\App\Http\Controllers\App\AsignacionesController::class, 'index'])->name('app.rrhh.asignaciones');
    Route::post('/rrhh/asignaciones', [\App\Http\Controllers\App\AsignacionesController::class, 'entregar'])->name('app.rrhh.asignacion.entregar');
    Route::post('/rrhh/asignaciones/{asignacion}/devolver', [\App\Http\Controllers\App\AsignacionesController::class, 'devolver'])->name('app.rrhh.asignacion.devolver');
    Route::post('/rrhh/reportes/{reporte}/atender', [\App\Http\Controllers\App\AsignacionesController::class, 'atenderReporte'])->name('app.rrhh.reporte.atender');

    // F11 · Los indicadores que se le presentan a la Junta Directiva.
    Route::get('/rrhh/indicadores', [\App\Http\Controllers\App\IndicadoresRrhhController::class, 'index'])->name('app.rrhh.indicadores');
    Route::get('/rrhh/indicadores/pdf', [\App\Http\Controllers\App\IndicadoresRrhhController::class, 'pdf'])->name('app.rrhh.indicadores.pdf');
    Route::get('/rrhh/indicadores/retiros', [\App\Http\Controllers\App\IndicadoresRrhhController::class, 'retirosExcel'])->name('app.rrhh.indicadores.retiros');
    Route::post('/rrhh/empleados/{empleado}/retiro', [\App\Http\Controllers\App\IndicadoresRrhhController::class, 'registrarRetiro'])->name('app.rrhh.empleado.retiro');

    // F6 · Sacar la información: PDF para la carpeta física (que la norma
    //      exige) y CSV para abrir en Excel, masivo y de a uno.
    Route::get('/rrhh/empleados/{empleado}/pdf', [\App\Http\Controllers\App\ExportacionRrhhController::class, 'historiaLaboral'])->name('app.rrhh.empleado.pdf');
    Route::get('/rrhh/candidatos/{candidato}/pdf', [\App\Http\Controllers\App\ExportacionRrhhController::class, 'procesoCandidato'])->name('app.rrhh.candidato.pdf');
    Route::get('/rrhh/exportar/empleados', [\App\Http\Controllers\App\ExportacionRrhhController::class, 'empleadosExcel'])->name('app.rrhh.exportar.empleados');
    Route::get('/rrhh/exportar/vacantes/{vacante}/candidatos', [\App\Http\Controllers\App\ExportacionRrhhController::class, 'candidatosExcel'])->name('app.rrhh.exportar.candidatos');
    Route::get('/rrhh/exportar/requisiciones', [\App\Http\Controllers\App\ExportacionRrhhController::class, 'requisicionesExcel'])->name('app.rrhh.exportar.requisiciones');

    // F5a · La orden de contratación que autoriza la Junta Directiva.
    //       Es el control entre «el jefe eligió» y «arranca la contratación».
    Route::get('/rrhh/ordenes', [\App\Http\Controllers\App\OrdenContratacionController::class, 'index'])->name('app.rrhh.ordenes');
    Route::post('/rrhh/candidatos/{candidato}/orden', [\App\Http\Controllers\App\OrdenContratacionController::class, 'solicitar'])->name('app.rrhh.orden.solicitar');
    Route::post('/rrhh/ordenes/{orden}/autorizar', [\App\Http\Controllers\App\OrdenContratacionController::class, 'autorizar'])->name('app.rrhh.orden.autorizar');
    Route::post('/rrhh/ordenes/{orden}/negar', [\App\Http\Controllers\App\OrdenContratacionController::class, 'negar'])->name('app.rrhh.orden.negar');

    // F5b · El jefe de área y SU gente (concepto de período de prueba).
    Route::get('/rrhh/mi-equipo', [\App\Http\Controllers\App\MiEquipoController::class, 'index'])->name('app.rrhh.miequipo');
    Route::post('/rrhh/mi-equipo/{empleado}/concepto', [\App\Http\Controllers\App\MiEquipoController::class, 'conceptoDePrueba'])->name('app.rrhh.miequipo.concepto');

    // F3 · Contratación: de «lo elegimos» a «ya trabaja acá».
    Route::get('/rrhh/contrataciones', [\App\Http\Controllers\App\ContratacionController::class, 'index'])->name('app.rrhh.contrataciones');
    Route::post('/rrhh/candidatos/{candidato}/contratar', [\App\Http\Controllers\App\ContratacionController::class, 'abrir'])->name('app.rrhh.contratacion.abrir');
    Route::get('/rrhh/contrataciones/{contratacion}', [\App\Http\Controllers\App\ContratacionController::class, 'show'])->name('app.rrhh.contratacion.show');
    Route::post('/rrhh/contrataciones/{contratacion}/acuerdo', [\App\Http\Controllers\App\ContratacionController::class, 'acuerdo'])->name('app.rrhh.contratacion.acuerdo');
    Route::post('/rrhh/contrataciones/{contratacion}/examen', [\App\Http\Controllers\App\ContratacionController::class, 'examen'])->name('app.rrhh.contratacion.examen');
    Route::post('/rrhh/contrataciones/{contratacion}/firmar', [\App\Http\Controllers\App\ContratacionController::class, 'firmarContrato'])->name('app.rrhh.contratacion.firmar');
    Route::post('/rrhh/contrataciones/{contratacion}/afiliacion', [\App\Http\Controllers\App\ContratacionController::class, 'afiliacion'])->name('app.rrhh.contratacion.afiliacion');
    Route::post('/rrhh/contrataciones/{contratacion}/documento', [\App\Http\Controllers\App\ContratacionController::class, 'subirDocumento'])->name('app.rrhh.contratacion.documento');
    Route::post('/rrhh/contrataciones/{contratacion}/completar', [\App\Http\Controllers\App\ContratacionController::class, 'completar'])->name('app.rrhh.contratacion.completar');
    Route::post('/rrhh/contrataciones/{contratacion}/cancelar', [\App\Http\Controllers\App\ContratacionController::class, 'cancelar'])->name('app.rrhh.contratacion.cancelar');
    Route::get('/rrhh/documentos/{documento}', [\App\Http\Controllers\App\ContratacionController::class, 'verDocumento'])->name('app.rrhh.documento.ver');

    // F3 + F4 · La ficha del empleado: historia laboral, inducción y prueba.
    Route::get('/rrhh/empleados/{empleado}', [\App\Http\Controllers\App\EmpleadoController::class, 'show'])->name('app.rrhh.empleado.show');
    Route::post('/rrhh/empleados/{empleado}/induccion', [\App\Http\Controllers\App\EmpleadoController::class, 'induccion'])->name('app.rrhh.empleado.induccion');
    Route::post('/rrhh/empleados/{empleado}/prueba', [\App\Http\Controllers\App\EmpleadoController::class, 'periodoDePrueba'])->name('app.rrhh.empleado.prueba');
    Route::post('/rrhh/empleados/{empleado}/prueba/evaluar', [\App\Http\Controllers\App\EmpleadoController::class, 'evaluarPrueba'])->name('app.rrhh.empleado.prueba.evaluar');
    Route::post('/rrhh/empleados/{empleado}/documento', [\App\Http\Controllers\App\EmpleadoController::class, 'subirDocumento'])->name('app.rrhh.empleado.documento');

    // LOG-J9 · Marketing (préstamos de productos a bodega)
    Route::get('/marketing', [\App\Http\Controllers\App\MarketingPrestamosController::class, 'index'])->name('app.marketing.index');
    // Marketing pide el préstamo desde su propio panel: la pantalla de
    // traslados de Inventario le responde 403 y además lista los movimientos
    // de toda la empresa, que no le corresponden.
    Route::post('/marketing/prestamo', [\App\Http\Controllers\App\MarketingPrestamosController::class, 'prestamoCrear'])
        ->middleware('throttle:30,1')->name('app.marketing.prestamo.crear');

    // M8 · Marketing
    Route::get('/marketing/parrilla', [\App\Http\Controllers\App\MarketingController::class, 'parrilla'])->name('app.marketing.parrilla');
    Route::post('/marketing/parrilla', [\App\Http\Controllers\App\MarketingController::class, 'postCrear'])->name('app.marketing.post.crear');
    Route::put('/marketing/parrilla/{post}', [\App\Http\Controllers\App\MarketingController::class, 'postActualizar'])->name('app.marketing.post.actualizar');
    Route::delete('/marketing/parrilla/{post}', [\App\Http\Controllers\App\MarketingController::class, 'postEliminar'])->name('app.marketing.post.eliminar');
    Route::get('/marketing/producto/{producto}/ficha', [\App\Http\Controllers\App\MarketingController::class, 'fichaEditar'])->name('app.marketing.ficha');
    Route::match(['put', 'post'], '/marketing/producto/{producto}/ficha', [\App\Http\Controllers\App\MarketingController::class, 'fichaGuardar'])->name('app.marketing.ficha.guardar');

    // M7 · Garantías
    Route::get('/garantias', [\App\Http\Controllers\App\GarantiasController::class, 'index'])->name('app.garantias.index');
    Route::get('/garantias/nueva', [\App\Http\Controllers\App\GarantiasController::class, 'form'])->name('app.garantias.nueva');
    Route::post('/garantias', [\App\Http\Controllers\App\GarantiasController::class, 'crear'])->name('app.garantias.crear');
    Route::get('/garantias/{ticket}', [\App\Http\Controllers\App\GarantiasController::class, 'show'])->name('app.garantias.show');
    Route::post('/garantias/{ticket}/decidir', [\App\Http\Controllers\App\GarantiasController::class, 'decidir'])->name('app.garantias.decidir');
    Route::post('/garantias/{ticket}/reposicion', [\App\Http\Controllers\App\GarantiasController::class, 'iniciarReposicion'])->name('app.garantias.reposicion');
    Route::post('/garantias/{ticket}/cerrar', [\App\Http\Controllers\App\GarantiasController::class, 'cerrar'])->name('app.garantias.cerrar');

    // Pedidos B2B (recibidos del portal)
    Route::get('/pedidos-b2b', [\App\Http\Controllers\App\PedidosB2BController::class, 'index'])->name('app.pedidos-b2b.index');
    Route::get('/pedidos-b2b/{pedido}', [\App\Http\Controllers\App\PedidosB2BController::class, 'show'])->name('app.pedidos-b2b.show');
    Route::post('/pedidos-b2b/{pedido}/aprobar', [\App\Http\Controllers\App\PedidosB2BController::class, 'aprobar'])->name('app.pedidos-b2b.aprobar');
    Route::post('/pedidos-b2b/{pedido}/rechazar', [\App\Http\Controllers\App\PedidosB2BController::class, 'rechazar'])->name('app.pedidos-b2b.rechazar');
    Route::post('/pedidos-b2b/{pedido}/facturar', [\App\Http\Controllers\App\PedidosB2BController::class, 'facturar'])->name('app.pedidos-b2b.facturar');
    // LOG-J7 · gate duro: no se despacha sin factura (raíz inventario negativo que reportó Don Jorge).
    Route::post('/pedidos-b2b/{pedido}/despachar', [\App\Http\Controllers\App\PedidosB2BController::class, 'despachar'])->middleware('throttle:30,1')->name('app.pedidos-b2b.despachar');

    // LOG-J5 · Cola Don Jorge · reemplaza el Excel manual de alistamiento.
    Route::get('/logistica/cola-jorge', [\App\Http\Controllers\App\ColaJorgeController::class, 'index'])->name('app.cola-jorge.index');
    Route::post('/logistica/cola-jorge/{pedido}/asignar', [\App\Http\Controllers\App\ColaJorgeController::class, 'asignar'])->middleware('throttle:60,1')->name('app.cola-jorge.asignar');
    Route::post('/logistica/cola-jorge/{pedido}/iniciar', [\App\Http\Controllers\App\ColaJorgeController::class, 'iniciar'])->middleware('throttle:60,1')->name('app.cola-jorge.iniciar');
    Route::post('/logistica/cola-jorge/{pedido}/finalizar', [\App\Http\Controllers\App\ColaJorgeController::class, 'finalizar'])->middleware('throttle:60,1')->name('app.cola-jorge.finalizar');
    // LOG-J5-fix · resolver novedad del alistamiento (gerencia/admin de bodega).
    Route::post('/logistica/cola-jorge/{pedido}/resolver-novedad', [\App\Http\Controllers\App\ColaJorgeController::class, 'resolverNovedad'])->middleware('throttle:30,1')->name('app.cola-jorge.resolver-novedad');
    // LOG-J6 · hoja de picking imprimible con Rack · Sección · Nivel.
    Route::get('/logistica/cola-jorge/{pedido}/picking', [\App\Http\Controllers\App\ColaJorgeController::class, 'imprimir'])->name('app.cola-jorge.picking');

    // LOG-J1 + Miracle port · Panel de Gestión Comercial del Vendedor.
    Route::get('/vendedor', [\App\Http\Controllers\App\VendedorPedidoController::class, 'index'])->name('app.vendedor.index');
    Route::get('/vendedor/ventas-por-cliente', [\App\Http\Controllers\App\VendedorPedidoController::class, 'ventasPorCliente'])->name('app.vendedor.ventas-por-cliente');
    Route::get('/vendedor/contado-credito', [\App\Http\Controllers\App\VendedorPedidoController::class, 'contadoCredito'])->name('app.vendedor.contado-credito');
    Route::get('/vendedor/seguimiento', [\App\Http\Controllers\App\VendedorPedidoController::class, 'seguimiento'])->name('app.vendedor.seguimiento');
    Route::get('/vendedor/pedido-nuevo/{contactoId}', [\App\Http\Controllers\App\VendedorPedidoController::class, 'nuevo'])->name('app.vendedor.nuevo');
    Route::post('/vendedor/pedido-nuevo/{contactoId}', [\App\Http\Controllers\App\VendedorPedidoController::class, 'confirmar'])->middleware('throttle:30,1')->name('app.vendedor.confirmar');

    // CRM
    Route::get('/crm', [\App\Http\Controllers\App\CrmController::class, 'index'])->name('app.crm.index');
    Route::post('/crm/interaccion', [\App\Http\Controllers\App\CrmController::class, 'crearInteraccion'])->name('app.crm.interaccion.crear');
    Route::post('/crm/segmentar', [\App\Http\Controllers\App\CrmController::class, 'segmentarAhora'])->name('app.crm.segmentar');
    // Re-audit SEG A3 · rate-limit anti-enumeración.
    Route::get('/api/contactos/buscar', [\App\Http\Controllers\App\ContactosController::class, 'buscarApi'])
        ->middleware('throttle:60,1')->name('app.contactos.buscar');

    // Estación de Empaque (escaneo con throttle generoso — pistola dispara ~30/min real)
    Route::get('/estacion-empaque', [\App\Http\Controllers\App\EstacionEmpaqueController::class, 'index'])->name('app.estacion');
    Route::middleware('throttle:120,1')->group(function () {
        Route::post('/estacion-empaque/escanear', [\App\Http\Controllers\App\EstacionEmpaqueController::class, 'escanear'])->name('app.estacion.escanear');
        Route::post('/estacion-empaque/foto', [\App\Http\Controllers\App\EstacionEmpaqueController::class, 'guardarFoto'])->name('app.estacion.foto');
        Route::post('/estacion-empaque/confirmar', [\App\Http\Controllers\App\EstacionEmpaqueController::class, 'confirmar'])->name('app.estacion.confirmar');
        Route::post('/estacion-empaque/cancelar', [\App\Http\Controllers\App\EstacionEmpaqueController::class, 'cancelar'])->name('app.estacion.cancelar');
    });
});
Route::post('/logout', [\App\Http\Controllers\App\AuthController::class, 'logout'])->middleware(['web', 'auth']);

// ============================================================
// PORTAL B2B · Clientes con guard 'cliente' bajo /portal
// ============================================================
Route::middleware(['web', 'guest:cliente'])->prefix('portal')->group(function () {
    Route::get('/login', [\App\Http\Controllers\Portal\PortalAuthController::class, 'showLogin'])->name('portal.login');
    // Re-audit SEG A2 · dos throttles combinados:
    //   - throttle:portal-login → 5 intentos por (IP + email documento) por minuto
    //   - throttle:portal-login-ip → 15 intentos por IP por 10 min (defensa distribuida)
    Route::post('/login', [\App\Http\Controllers\Portal\PortalAuthController::class, 'login'])
        ->middleware(['throttle:portal-login', 'throttle:portal-login-ip']);

    // H3 · reset password del portal (3 pasos: solicitar → form → submit).
    // Throttle: 5 solicitudes por hora por IP (anti-spam / anti-enumeración).
    Route::post('/password/olvide', [\App\Http\Controllers\Portal\PortalAuthController::class, 'olvidePassword'])
        ->middleware('throttle:5,60')->name('portal.password.olvide');
    Route::get('/password/reset', [\App\Http\Controllers\Portal\PortalAuthController::class, 'resetForm'])
        ->middleware('signed')->name('portal.password.reset.form');
    Route::post('/password/reset', [\App\Http\Controllers\Portal\PortalAuthController::class, 'resetSubmit'])
        ->middleware('throttle:10,60')->name('portal.password.reset.submit');
});

Route::middleware(['web', 'auth:cliente'])->prefix('portal')->group(function () {
    Route::get('/', \App\Http\Controllers\Portal\PortalHomeController::class)->name('portal.home');
    Route::post('/logout', [\App\Http\Controllers\Portal\PortalAuthController::class, 'logout'])->name('portal.logout');

    // Catálogo con precios del cliente
    Route::get('/catalogo', [\App\Http\Controllers\Portal\PortalCatalogoController::class, 'index'])->name('portal.catalogo');
    Route::get('/producto/{producto}', [\App\Http\Controllers\Portal\PortalCatalogoController::class, 'show'])->name('portal.producto');

    // Pedidos B2B
    Route::get('/carrito', [\App\Http\Controllers\Portal\PortalCarritoController::class, 'index'])->name('portal.carrito');
    Route::post('/carrito/confirmar', [\App\Http\Controllers\Portal\PortalCarritoController::class, 'confirmar'])
        ->middleware('throttle:15,1') // QA-D Bloque2: evita spam de pedidos
        ->name('portal.carrito.confirmar');
    Route::get('/pedidos', [\App\Http\Controllers\Portal\PortalPedidosController::class, 'index'])->name('portal.pedidos');
    Route::get('/pedidos/{pedido}', [\App\Http\Controllers\Portal\PortalPedidosController::class, 'show'])->name('portal.pedidos.show');

    // Facturas del cliente
    Route::get('/facturas', [\App\Http\Controllers\Portal\PortalFacturasController::class, 'index'])->name('portal.facturas');
    // H2 · detalle de factura (items + pagos + saldo) para el portal.
    Route::get('/facturas/{factura}', [\App\Http\Controllers\Portal\PortalFacturasController::class, 'show'])->name('portal.facturas.show');
    // Re-audit UX#1 · descarga PDF de la factura desde el portal.
    Route::get('/factura/{factura}/pdf', [\App\Http\Controllers\Portal\PortalFacturasController::class, 'pdf'])
        ->middleware('throttle:30,1')
        ->name('portal.factura.pdf');
});


// Fallback para middleware auth que redirige a route('login') → mandamos al
//   ÚNICO login real (Vue en /app/login). El login de Filament fue borrado
//   para no tener dos puertas de entrada que confundían al equipo.
Route::get('/login', fn () => redirect('/app/login'))->name('login');

// Fix Livewire assets con hash bajo PHP built-in server (dev)
Route::get('/livewire-{hash}/livewire.js', function () {
    return response()->file(public_path('vendor/livewire/livewire.js'), ['Content-Type' => 'application/javascript']);
})->where('hash', '.*');
Route::get('/livewire-{hash}/livewire.min.js.map', function () {
    return response()->file(public_path('vendor/livewire/livewire.js.map'), ['Content-Type' => 'application/json']);
})->where('hash', '.*');

// Auto-login DEV — entra como el usuario 1 sin contraseña.
//
// Las guardas de antes (`APP_ENV=local` + `APP_DEBUG=true` + REMOTE_ADDR local)
// no alcanzaban para subir esto a un servidor:
//   · el `.env` del repo trae APP_ENV=local y APP_DEBUG=true, así que copiarlo
//     al servidor ya pasaba dos de las tres;
//   · detrás de nginx→php-fpm el REMOTE_ADDR es 127.0.0.1 (el proxy, no el
//     visitante), así que la tercera fallaba ABIERTA: admin gratis para
//     cualquiera que supiera la URL.
//
// Ahora pide dos cosas que un servidor real no tiene:
//   1. `DEV_LOGIN=true` explícito, que NO está en `.env.example` ni en el
//      `.env` del repo: hay que escribirlo a mano con intención.
//   2. Que el dominio con que se entra sea local. El Host lo pone el navegador,
//      no el proxy, así que `erp.greatbaby.com` nunca pasa aunque el
//      REMOTE_ADDR sea 127.0.0.1.
if (app()->environment('local') && config('app.debug') === true && env('DEV_LOGIN') === true) {
    Route::get('/dev-login', function (\Illuminate\Http\Request $r) {
        $host = strtolower((string) $r->getHost());
        $esHostLocal = in_array($host, ['localhost', '127.0.0.1', '::1', '[::1]'], true)
            || str_ends_with($host, '.localhost')
            || str_ends_with($host, '.test');
        abort_unless($esHostLocal, 403, 'dev-login solo desde un dominio local.');

        // REMOTE_ADDR directo (bypasea cualquier TrustProxies que respete
        // X-Forwarded-For). Se mantiene como cinturón además del Host.
        $ip = $_SERVER['REMOTE_ADDR'] ?? '';
        abort_unless(in_array($ip, ['127.0.0.1', '::1'], true), 403, 'dev-login solo desde localhost');

        \Illuminate\Support\Facades\Auth::loginUsingId(1);

        return redirect('/admin');
    });
}

// Modo TV: pantalla de bodega (fullscreen, auto-refresh). REQUIERE auth ahora.
Route::get('/tv/empaque', [\App\Http\Controllers\Dropi\ModoTvController::class, 'empaque'])
    ->middleware(['web', 'auth'])
    ->name('tv.empaque');

// Foto de empaque (privada): sólo Aracely / Alistador con sesión.
Route::get('/empaques/foto/{registro}', [\App\Http\Controllers\Dropi\EmpaqueFotoController::class, 'ver'])
    ->middleware(['web', 'auth'])
    ->name('empaques.foto');

// Portal público: descarga de factura por token — rate-limit contra abuso.
Route::get('/factura/publica/{token}', [FacturaPdfController::class, 'publica'])
    ->middleware(['web', 'throttle:20,1'])
    ->where('token', '[A-Za-z0-9]+')
    ->name('cartera.factura.publica');

Route::middleware(['web', 'auth'])->group(function () {
    // P5 · manifiesto + plantilla productos: sólo equipo administrativo (Aracely/Gerencia).
    Route::middleware([\App\Http\Middleware\SoloAracely::class])->group(function () {
        Route::get('/dropi/manifiesto/{corte}', [ManifiestoController::class, 'descargar'])
            ->name('dropi.manifiesto.descargar');
        Route::get('/dropi/plantilla/productos', [PlantillaImportProductosController::class, 'descargar'])
            ->name('dropi.plantilla.productos');
    });

    // P9 · escáner con throttle para evitar despacho masivo o enumeración de guías.
    Route::post('/dropi/escaner/analizar', [EscanerController::class, 'analizar'])
        ->middleware('throttle:60,1')->name('dropi.escaner.analizar');
    Route::post('/dropi/escaner/despachar', [EscanerController::class, 'despachar'])
        ->middleware('throttle:30,1')->name('dropi.escaner.despachar');
    // 4.6 · escanear guía → facturar el pedido despachado que quedó sin factura.
    Route::post('/dropi/escaner/facturar', [EscanerController::class, 'facturar'])
        ->middleware('throttle:30,1')->name('dropi.escaner.facturar');

    // Re-audit SEG C1 · rate-limit para bloquear enumeración de estados de cuenta.
    Route::get('/cartera/estado-cuenta/{contacto}', [EstadoCuentaController::class, 'pdf'])
        ->middleware('throttle:20,1')
        ->name('cartera.estado-cuenta');
    Route::get('/cartera/plantilla/contactos', [PlantillaContactosController::class, 'descargar'])
        ->name('cartera.plantilla.contactos');
    Route::get('/cartera/factura/{factura}/pdf', [FacturaPdfController::class, 'pdf'])
        ->name('cartera.factura.pdf');

    Route::get('/catalogo/variante/{variante}/qr', [CodigoBarrasController::class, 'qr'])->name('catalogo.variante.qr');
    Route::get('/catalogo/variante/{variante}/barcode', [CodigoBarrasController::class, 'barcode'])->name('catalogo.variante.barcode');
    Route::get('/catalogo/variante/{variante}/etiqueta', [CodigoBarrasController::class, 'etiqueta'])->name('catalogo.variante.etiqueta');
    Route::get('/catalogo/etiquetas-lote', [EtiquetasMasivasController::class, 'pdf'])->name('catalogo.etiquetas.lote');

    // Compras: sólo Aracely/Gerencia (fix auditor #19 — antes cualquier autenticado veía costos de proveedor)
    Route::middleware(['auth', \App\Http\Middleware\SoloAracely::class])->group(function () {
        // Re-audit M2 R3 PATRÓN R (SEG-A6) · throttle en PDFs (DomPDF es CPU-heavy).
        Route::get('/compras/orden/{orden}/pdf', [OrdenCompraPdfController::class, 'pdf'])->middleware('throttle:20,1')->name('compras.orden.pdf');
        Route::get('/compras/recepcion/{recepcion}/pdf', [RecepcionPdfController::class, 'pdf'])->middleware('throttle:20,1')->name('compras.recepcion.pdf');
        Route::get('/compras/importacion/{importacion}/pdf', [ImportacionPdfController::class, 'pdf'])->middleware('throttle:20,1')->name('compras.importacion.pdf');
        Route::get('/compras/plantilla/oc', [PlantillaImportOCController::class, 'descargar'])->name('compras.plantilla.oc');
        Route::post('/compras/import/oc', [ImportOCController::class, 'importar'])->middleware('throttle:10,1')->name('compras.import.oc');
    });
});
