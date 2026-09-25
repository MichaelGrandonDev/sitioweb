<?php
return [
    'app_name' => 'Turnos FluxusTerapia',
    'site_url' => 'https://fluxusterapia.com',
    'timezone' => 'America/Argentina/Buenos_Aires',
    'db_path' => __DIR__ . '/data/turnos.sqlite',
    'slot_minutes' => 60,
    'booking_horizon_days' => 45,
    'admin_pass' => 'CHANGE_ME',
    'place_name' => 'FluxusTerapia',
    'place_city' => 'Punta Alta · Coronel Suárez · Online',
    'whatsapp' => '5492932537949',
    'mail_from' => 'hola@fluxusterapia.com',
    'mail_from_name' => 'FluxusTerapia',
    'migrate_key' => 'CHANGE_ME',
    // Seña al reservar turno
    'deposit' => [
        'amount' => 15000,
        'currency' => 'ARS',
        'transfer_holder' => 'Michael Grandon',
        'transfer_bank' => 'Mercado Pago',
        'transfer_alias' => 'michael.grandon.mp',
        'transfer_cbu' => '',
        'transfer_note' => 'Concepto: Seña turno + tu nombre + fecha.',
        'mp_access_token' => '', // mismo token de MP o el de la cuenta Fluxus
        'mp_public_key' => '',
    ],
];
