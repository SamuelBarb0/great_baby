<?php

namespace App\Modules\Dropi\Enums;

/**
 * §7 Diseño Dropi — La ubicación del inventario NO es solo dónde está el producto,
 * también define para qué está disponible.
 */
enum CategoriaUbicacion: string
{
    case Venta = 'venta';
    case AveriaReparar = 'averia_reparar';
    case AveriaBaja = 'averia_baja';
    case Garantia = 'garantia';
    case ReservaProveedor = 'reserva_proveedor';

    /**
     * Mercancía que está en el piso, no en estantería.
     *
     * El parqueadero, el local y la zona Arsutex son donde se descarga un
     * contenedor antes de subirlo a los racks. Nacieron como «venta» con el
     * levantamiento del área, y eso le promete al cliente la carga que
     * todavía está en el piso sin contar ni revisar. Cuenta en el kardex
     * —existe y hay que saber dónde está— pero no se le ofrece a nadie.
     *
     * Si el área decide que el local sí vende, se le cambia la categoría a
     * esa ubicación desde /app/inventario/ubicaciones; no hace falta tocar
     * código.
     */
    case Cuarentena = 'cuarentena';

    public function label(): string
    {
        return match ($this) {
            self::Venta => 'Stock de venta',
            self::AveriaReparar => 'Avería — por reparar',
            self::AveriaBaja => 'Avería — baja total',
            self::Garantia => 'Reservado para garantía',
            self::ReservaProveedor => 'Reserva de proveedor',
            self::Cuarentena => 'En piso — sin entrar a estantería',
        };
    }

    public function disponibleParaVenta(): bool
    {
        return match ($this) {
            self::Venta, self::ReservaProveedor => true,
            self::AveriaReparar, self::AveriaBaja, self::Garantia, self::Cuarentena => false,
        };
    }
}
