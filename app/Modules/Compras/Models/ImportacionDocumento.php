<?php

namespace App\Modules\Compras\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Un archivo del contenedor. Los tipos siguen el camino que contaron Alejandro
 * y Liz el 30-oct: de la orden al proveedor hasta el levante. El comprobante de
 * un gasto es un documento más, con su `gasto_id`.
 */
class ImportacionDocumento extends Model
{
    use SoftDeletes;

    protected $table = 'compras_importacion_documentos';

    /** Disco privado: los documentos solo salen por el controlador, con permiso. */
    public const DISCO = 'local';

    public const TIPOS = [
        'orden_proveedor' => 'Orden de pedido',
        'proforma' => 'Proforma / cotización del proveedor',
        'cotizacion_flete' => 'Cotización de flete',
        'prelista_empaque' => 'Prelista de empaque',
        'borrador_lista_empaque' => 'Borrador de lista de empaque',
        'lista_empaque' => 'Lista de empaque final (packing list)',
        'bl' => 'BL',
        'invoice' => 'Factura del proveedor (invoice)',
        'factura_final' => 'Factura final para Colombia',
        'duta' => 'DUTA',
        'formulario_ingreso' => 'Formulario de ingreso a zona franca',
        'declaracion' => 'Declaración de importación',
        'levante' => 'Levante',
        'etiquetas' => 'Etiquetas de importación',
        'comprobante_gasto' => 'Comprobante de gasto',
        'otro' => 'Otro',
    ];

    protected $fillable = [
        'importacion_id', 'gasto_id', 'tipo', 'nombre', 'ruta', 'mime', 'tamano', 'version', 'notas', 'subido_por',
    ];

    public function importacion(): BelongsTo { return $this->belongsTo(Importacion::class); }
    public function gasto(): BelongsTo { return $this->belongsTo(GastoImportacion::class, 'gasto_id'); }
    public function autor(): BelongsTo { return $this->belongsTo(User::class, 'subido_por'); }
}
