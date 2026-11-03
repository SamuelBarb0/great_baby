<?php

/**
 * Los mensajes de validación en español.
 *
 * Este archivo NO EXISTÍA. El `locale` de la aplicación es `es`, pero sólo
 * estaba `lang/es/pagination.php`, así que todo mensaje de validación que no
 * trajera un texto a mano salía con su clave cruda en pantalla:
 *
 *     validation.required
 *     validation.after_or_equal
 *     validation.max.string
 *
 * Se veía en cualquier formulario del ERP el día que alguien se equivocaba —
 * que es justo el momento en que el mensaje tiene que servir para algo. Los
 * pocos textos en español que ya se veían venían escritos uno por uno en el
 * tercer argumento de `validate()`, no de acá.
 *
 * `attributes` traduce el NOMBRE del campo. Sin eso, «El campo
 * numero_documento es obligatorio» le habla al programador, no a quien está
 * llenando el formulario. Se agregan los del módulo de Gestión Humana, que es
 * donde más formularios largos hay; los demás siguen cayendo al nombre crudo
 * hasta que alguien los necesite.
 */
return [

    'accepted' => 'Tenés que aceptar :attribute.',
    'active_url' => ':attribute no es una URL válida.',
    'after' => ':attribute debe ser una fecha posterior a :date.',
    'after_or_equal' => 'El campo :attribute no puede ser anterior a :date.',
    'alpha' => ':attribute sólo puede tener letras.',
    'alpha_dash' => ':attribute sólo puede tener letras, números, guiones y guiones bajos.',
    'alpha_num' => ':attribute sólo puede tener letras y números.',
    'array' => ':attribute tiene que ser una lista.',
    'before' => ':attribute debe ser una fecha anterior a :date.',
    'before_or_equal' => ':attribute no puede ser posterior a :date.',
    'between' => [
        'array' => ':attribute debe tener entre :min y :max elementos.',
        'file' => ':attribute debe pesar entre :min y :max kilobytes.',
        'numeric' => ':attribute debe estar entre :min y :max.',
        'string' => ':attribute debe tener entre :min y :max caracteres.',
    ],
    'boolean' => 'El campo :attribute sólo puede ser sí o no.',
    'confirmed' => ':attribute no coincide con la confirmación.',
    'current_password' => 'La contraseña no es correcta.',
    'date' => 'El campo :attribute no es una fecha válida.',
    'date_equals' => ':attribute debe ser :date.',
    'date_format' => ':attribute no tiene el formato :format.',
    'decimal' => ':attribute debe tener :decimal decimales.',
    'different' => ':attribute y :other deben ser distintos.',
    'digits' => ':attribute debe tener :digits dígitos.',
    'digits_between' => ':attribute debe tener entre :min y :max dígitos.',
    'dimensions' => ':attribute no tiene unas dimensiones de imagen válidas.',
    'distinct' => ':attribute está repetido.',
    'doesnt_end_with' => ':attribute no puede terminar en: :values.',
    'doesnt_start_with' => ':attribute no puede empezar por: :values.',
    'email' => 'El campo :attribute no es un correo válido.',
    'ends_with' => ':attribute debe terminar en: :values.',
    'enum' => 'El campo :attribute no tiene un valor válido.',
    'exists' => 'El valor de :attribute no existe.',
    'file' => 'El campo :attribute tiene que ser un archivo.',
    'filled' => 'El campo :attribute no puede quedar vacío.',
    'gt' => [
        'array' => ':attribute debe tener más de :value elementos.',
        'file' => ':attribute debe pesar más de :value kilobytes.',
        'numeric' => ':attribute debe ser mayor que :value.',
        'string' => ':attribute debe tener más de :value caracteres.',
    ],
    'gte' => [
        'array' => ':attribute debe tener :value elementos o más.',
        'file' => ':attribute debe pesar :value kilobytes o más.',
        'numeric' => 'El campo :attribute no puede ser menor que :value.',
        'string' => ':attribute debe tener :value caracteres o más.',
    ],
    'image' => ':attribute tiene que ser una imagen.',
    'in' => 'El campo :attribute no tiene un valor válido.',
    'in_array' => ':attribute no está entre los valores de :other.',
    'integer' => 'El campo :attribute tiene que ser un número entero.',
    'ip' => ':attribute no es una dirección IP válida.',
    'ipv4' => ':attribute no es una dirección IPv4 válida.',
    'ipv6' => ':attribute no es una dirección IPv6 válida.',
    'json' => ':attribute no es un JSON válido.',
    'lt' => [
        'array' => ':attribute debe tener menos de :value elementos.',
        'file' => ':attribute debe pesar menos de :value kilobytes.',
        'numeric' => ':attribute debe ser menor que :value.',
        'string' => ':attribute debe tener menos de :value caracteres.',
    ],
    'lte' => [
        'array' => ':attribute no puede tener más de :value elementos.',
        'file' => ':attribute no puede pesar más de :value kilobytes.',
        'numeric' => ':attribute no puede ser mayor que :value.',
        'string' => ':attribute no puede tener más de :value caracteres.',
    ],
    'max' => [
        'array' => ':attribute no puede tener más de :max elementos.',
        'file' => ':attribute no puede pesar más de :max kilobytes.',
        'numeric' => 'El campo :attribute no puede ser mayor que :max.',
        'string' => 'El campo :attribute no puede tener más de :max caracteres.',
    ],
    'mimes' => 'El campo :attribute debe ser un archivo de tipo: :values.',
    'mimetypes' => 'El campo :attribute debe ser un archivo de tipo: :values.',
    'min' => [
        'array' => ':attribute debe tener al menos :min elementos.',
        'file' => ':attribute debe pesar al menos :min kilobytes.',
        'numeric' => 'El campo :attribute no puede ser menor que :min.',
        'string' => 'El campo :attribute debe tener al menos :min caracteres.',
    ],
    'missing' => ':attribute no debería venir.',
    'not_in' => 'El campo :attribute no tiene un valor válido.',
    'not_regex' => 'El formato del campo :attribute no es válido.',
    'numeric' => 'El campo :attribute tiene que ser un número.',
    'present' => ':attribute tiene que estar presente.',
    'prohibited' => ':attribute no está permitido.',
    'prohibited_if' => ':attribute no está permitido cuando :other es :value.',
    'regex' => 'El formato del campo :attribute no es válido.',
    'required' => 'El campo :attribute es obligatorio.',
    'required_if' => ':attribute es obligatorio cuando :other es :value.',
    'required_unless' => ':attribute es obligatorio salvo que :other sea :values.',
    'required_with' => ':attribute es obligatorio cuando hay :values.',
    'required_without' => ':attribute es obligatorio cuando no hay :values.',
    'same' => ':attribute y :other deben coincidir.',
    'size' => [
        'array' => ':attribute debe tener :size elementos.',
        'file' => ':attribute debe pesar :size kilobytes.',
        'numeric' => ':attribute debe ser :size.',
        'string' => ':attribute debe tener :size caracteres.',
    ],
    'starts_with' => ':attribute debe empezar por: :values.',
    'string' => 'El campo :attribute tiene que ser texto.',
    'timezone' => ':attribute no es una zona horaria válida.',
    'unique' => 'Ese valor de :attribute ya está registrado.',
    'uploaded' => 'No se pudo subir :attribute.',
    'url' => ':attribute no es una URL válida.',
    'uuid' => ':attribute no es un UUID válido.',

    'custom' => [],

    /*
     * El nombre del campo como lo ve quien llena el formulario.
     *
     * Sin esto, «El campo numero_documento es obligatorio» le habla al
     * programador. Están los de Gestión Humana, que es donde más formularios
     * largos hay.
     */
    'attributes' => [
        /*
         * Acá NO va `today`.
         *
         * Se intentó: `'today' => 'hoy'` en esta lista, creyendo que Laravel
         * resolvía con ella el `:date` de `before_or_equal:today`. No lo hace.
         * `ReplacesAttributes::replaceBefore()` pregunta primero si el
         * parámetro se parsea como fecha; «today» sí, así que entra por
         * `getDisplayableValue()`, que busca `validation.values.<campo>.today`
         * y no esta lista. El mensaje seguía saliendo «…posterior a today»,
         * en inglés, mientras el archivo de idioma aparentaba tenerlo cubierto.
         *
         * Cada formulario con `before_or_equal:today` escribe su mensaje en el
         * segundo parámetro de `validate()` — así lo hace `PagosController` —
         * porque además queda más claro: «La entrevista no puede tener fecha
         * futura» dice más que «la fecha no puede ser posterior a hoy».
         */
        'nombre' => 'nombre',
        'numero_documento' => 'número de documento',
        'tipo_documento' => 'tipo de documento',
        'email' => 'correo',
        'telefono' => 'teléfono',
        'direccion' => 'dirección',
        'ciudad' => 'ciudad',
        'cargo' => 'cargo',
        'area' => 'área',
        'salario' => 'salario',
        'tipo_contrato' => 'tipo de contrato',
        'fecha_nacimiento' => 'fecha de nacimiento',
        'fecha_ingreso' => 'fecha de ingreso',
        'fecha_inicio' => 'fecha de inicio',
        'fecha_retiro' => 'fecha de retiro',
        'fecha_entrevista' => 'fecha de la entrevista',
        'fecha_evaluacion' => 'fecha de evaluación',
        'grupo_sanguineo' => 'grupo sanguíneo',
        'cuenta_bancaria' => 'cuenta bancaria',
        'arl_nivel_riesgo' => 'nivel de riesgo ARL',
        'periodicidad_pago' => 'tipo de pago',
        'empresa_afiliadora' => 'empresa que afilia',
        'horario' => 'horario',
        'observacion' => 'observación',
        'observaciones' => 'observaciones',
        'motivo' => 'motivo',
        'motivo_retiro' => 'detalle del retiro',
        'motivo_principal' => 'motivo principal',
        'justificacion' => 'justificación',
        'resultado' => 'resultado',
        'periodo' => 'corte',
        'dias' => 'días',
        'horas' => 'horas',
        'descuento' => 'descuento',
        'soporte' => 'soporte',
        'password' => 'contraseña',
    ],

];
