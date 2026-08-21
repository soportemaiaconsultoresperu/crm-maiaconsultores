<?php

/**
 * Labels and help texts for the admin Settings form.
 *
 * Structure:
 *   - groups: human-readable names for each setting group (the card title).
 *   - keys: per-setting label, help and (when relevant) field-type override.
 *     Keys are organised as nested arrays that mirror the dotted `setting.key`
 *     column: e.g. the row `company.name` lives at `keys.company.name`.
 *     This is required because Laravel's translator treats dots in keys as
 *     array-path separators — so flat keys with literal dots would not be
 *     looked up correctly via `__()`. The view uses `Arr::get()` to navigate.
 *
 * Supported type overrides used by the view:
 *   - "image"      : renders an upload widget with preview instead of a text input.
 *   - "boolean_on" : renders a single checkbox that posts "1"/"0" (default behaviour).
 */

return [

    'groups' => [
        'general'        => 'General',
        'quotations'     => 'Cotizaciones',
        'sequences'      => 'Secuencias',
        'company'        => 'Datos de la empresa',
        'notifications'  => 'Notificaciones',
    ],

    'keys' => [

        // ── general ────────────────────────────────────────────────────────
        'currency_default' => [
            'label' => 'Moneda por defecto',
            'help'  => 'Código ISO 4217 de la moneda usada por defecto en cotizaciones nuevas (ej. PEN, USD, EUR).',
        ],
        'date_format' => [
            'label' => 'Formato de fecha',
            'help'  => 'Formato PHP para fechas (ej. d/m/Y para 31/12/2026).',
        ],
        'pagination_size' => [
            'label' => 'Tamaño de página',
            'help'  => 'Cantidad de filas por página en listados (recomendado entre 10 y 50).',
        ],
        'prices_include_tax' => [
            'label' => 'Los precios ya incluyen IGV',
            'help'  => 'Marcá esta opción si los precios del catálogo de productos ya incluyen el impuesto.',
        ],

        // ── quotations ─────────────────────────────────────────────────────
        'quote_validity_days' => [
            'label' => 'Vigencia de cotización (días)',
            'help'  => 'Cantidad de días por defecto durante los que una cotización se considera válida.',
        ],

        // ── company ────────────────────────────────────────────────────────
        'company' => [
            'name' => [
                'label' => 'Nombre de empresa',
                'help'  => 'Razón social que aparece en la cabecera del PDF de cotización.',
            ],
            'tax_id' => [
                'label' => 'RUC',
                'help'  => 'Número de identificación tributaria (11 dígitos para Perú).',
            ],
            'address' => [
                'label' => 'Dirección fiscal',
                'help'  => 'Dirección que aparece en la cabecera del PDF de cotización.',
            ],
            'phone' => [
                'label' => 'Teléfono',
                'help'  => 'Teléfono de contacto de la empresa.',
            ],
            'email' => [
                'label' => 'Email de contacto',
                'help'  => 'Email general de la empresa (aparece en la cabecera del PDF).',
            ],
            'logo_path' => [
                'label' => 'Logo de la empresa',
                'help'  => 'Imagen cuadrada o apaisada (JPG, PNG o WEBP, máximo 2 MB). Aparece en la cabecera del PDF.',
                'type'  => 'image',
            ],
        ],

        // ── notifications ──────────────────────────────────────────────────
        'notifications' => [
            'mail' => [
                'enabled' => [
                    'label' => 'Activar notificaciones por email',
                    'help'  => 'Cuando está activo, el sistema puede enviar correos. Requiere credenciales SMTP u OAuth configuradas.',
                ],
            ],
            'whatsapp' => [
                'enabled' => [
                    'label' => 'Activar notificaciones por WhatsApp',
                    'help'  => 'Cuando está activo, el sistema puede enviar mensajes por WhatsApp. Requiere una cuenta de WhatsApp Business configurada.',
                ],
            ],
        ],

        // ── sequences ──────────────────────────────────────────────────────
        'seq' => [
            'lead' => [
                'prefix' => [
                    'label' => 'Prefijo de prospectos',
                    'help'  => 'Prefijo del correlativo de prospectos (ej. LEAD-2026-00001).',
                ],
            ],
            'customer' => [
                'prefix' => [
                    'label' => 'Prefijo de clientes',
                    'help'  => 'Prefijo del correlativo de clientes.',
                ],
            ],
            'opportunity' => [
                'prefix' => [
                    'label' => 'Prefijo de oportunidades',
                    'help'  => 'Prefijo del correlativo de oportunidades.',
                ],
            ],
            'quotation' => [
                'prefix' => [
                    'label' => 'Prefijo de cotizaciones',
                    'help'  => 'Prefijo del correlativo de cotizaciones.',
                ],
                'pad_length' => [
                    'label' => 'Dígitos del correlativo de cotizaciones',
                    'help'  => 'Cantidad de dígitos del correlativo (5 → COT-2026-00001).',
                ],
            ],
            'product' => [
                'prefix' => [
                    'label' => 'Prefijo de productos',
                    'help'  => 'Prefijo del correlativo de productos.',
                ],
                'pad_length' => [
                    'label' => 'Dígitos del correlativo de productos',
                    'help'  => 'Cantidad de dígitos del correlativo de productos.',
                ],
            ],
            'pad_length' => [
                'label' => 'Dígitos del correlativo (global)',
                'help'  => 'Padding por defecto para los demás correlativos.',
            ],
        ],

    ],

];
