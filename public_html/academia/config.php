<?php
/**
 * FluxusTerapia Academia — config
 * Cambiá ADMIN_USER / ADMIN_PASS solo antes de la primera instalación.
 */
return [
    'app_name' => 'AcademiaFluxus',
    'site_url' => 'https://fluxusterapia.com',
    'timezone' => 'America/Argentina/Buenos_Aires',
    'db_path' => __DIR__ . '/data/academia.sqlite',
    // Se usa solo en install.php la primera vez
    'admin_user' => 'admin',
    'admin_pass' => 'CHANGE_ME',
    'admin_name' => 'Michael Grandon',
    // Correos (HostGator usa mail() del dominio)
    'mail_from' => 'hola@fluxusterapia.com',
    'mail_from_name' => 'AcademiaFluxus',
    'mail_reply_to' => 'hola@fluxusterapia.com',
    'migrate_key' => 'CHANGE_ME',
    // Cuotas mensuales
    'payments' => [
        'currency' => 'ARS',
        'default_monthly_amount' => 25000, // pesos
        'transfer_holder' => 'Michael Grandon',
        'transfer_bank' => 'Mercado Pago',
        'transfer_cbu' => '',
        'transfer_alias' => 'michael.grandon.mp',
        'transfer_note' => 'En el concepto poné tu nombre y el mes (ej: Juan Pérez · Septiembre). Alias: michael.grandon.mp',
        // Mercado Pago · https://www.mercadopago.com.ar/developers
        // Dejá vacío hasta cargar credenciales de producción/test
        'mp_access_token' => '',
        'mp_public_key' => '',
    ],
];
