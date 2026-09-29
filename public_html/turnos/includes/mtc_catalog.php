<?php

declare(strict_types=1);

/**
 * Catálogo editable desde el admin (mtc_catalogo.php): «Diagnósticos y tratamientos» (patrones) e «Indicaciones».
 * Se siembra desde el código (solo se agregan claves que falten: lo editado en el admin no se pisa).
 *
 * API (la usa también el módulo Pacientes):
 * - mtc_catalog_patterns(bool $all = false): [clave => patrón] activos (o todos). mtc_patterns() devuelve los activos.
 *   Patrón: label, organ, signs, plain (para el paciente), principle, meridian, points, root, evidence [campo diag => valores],
 *   moxa (puntos), moxa_mode ('si' | 'cauto' | 'no': con calor/Yang que asciende no se moxa), moxa_note, no_cups (bool),
 *   tuina [maniobras, acupresion], qigong [sesion, casa, min], ventosas [zona, modo], oreja (puntos auriculares), recs, contra_notes, ashi.
 * - mtc_catalog_indications(bool $all = false): [clave => indicación] con category, text, patterns (claves; «*» = todos; «higado*» = prefijo),
 *   therapist (bool: requiere indicación del terapeuta, nunca se tilda sola), caution.
 * - mtc_indications_for(array $patternKeys): ['auto' => [...], 'offer' => [...]] propuestas para un diagnóstico.
 * - mtc_catalog_save_pattern(), mtc_catalog_save_indication(), mtc_catalog_set_active(), mtc_catalog_reset().
 */

const MTC_ORGANS = ['Hígado', 'Vesícula Biliar', 'Corazón', 'Bazo', 'Estómago', 'Pulmón', 'Riñón', 'General'];

const MTC_IND_CATEGORIES = [
    'horarios' => 'Horarios y ritmo circadiano',
    'alimentacion' => 'Alimentación',
    'sueno' => 'Sueño',
    'habitos' => 'Hábitos',
    'hidroterapia' => 'Hidroterapia',
    'actividad' => 'Actividad y chi kung',
    'fitoterapia' => 'Fitoterapia',
    'especias' => 'Especias',
];

const MTC_EAR_POINTS = [
    'Shenmen', 'Simpático', 'Punto Cero', 'Subcórtex', 'Endocrino', 'Corazón', 'Pulmón', 'Hígado', 'Vesícula Biliar', 'Bazo',
    'Estómago', 'Riñón', 'Vejiga', 'San Jiao', 'Ojo', 'Surco hipotensor', 'Cervicales', 'Zona lumbar', 'Hombro', 'Rodilla',
];

/** Señales abreviadas para escribir las plantillas: token => [campo de diag, valor]. */
const MTC_SIGN_TOKENS = [
    'palida' => ['lengua_color', 'Pálida'], 'roja' => ['lengua_color', 'Roja'], 'punta_roja' => ['lengua_color', 'Roja en la punta'],
    'bordes_rojos' => ['lengua_color', 'Bordes rojos'], 'violacea' => ['lengua_color', 'Violácea'],
    'grande' => ['lengua_tamano', 'Grande / ancha'], 'pequena' => ['lengua_tamano', 'Pequeña / delgada'],
    'seca' => ['lengua_humedad', 'Seca'], 'muy_humeda' => ['lengua_humedad', 'Muy húmeda'],
    'temblorosa' => ['lengua_movilidad', 'Temblorosa'], 'desviada' => ['lengua_movilidad', 'Desviada'], 'rigida' => ['lengua_movilidad', 'Rígida'], 'flacida' => ['lengua_movilidad', 'Flácida'],
    'venas_oscuras' => ['lengua_venas', 'Dilatadas / oscuras'], 'venas_palidas' => ['lengua_venas', 'Pálidas / poco visibles'],
    'hinchada' => ['lengua_forma', 'Hinchada'], 'fina' => ['lengua_forma', 'Fina'], 'marcas' => ['lengua_forma', 'Marcas dentales'],
    'grietas' => ['lengua_forma', 'Grietas'], 'puntos_rojos' => ['lengua_forma', 'Puntos rojos / petequias'], 'manchas' => ['lengua_forma', 'Manchas violáceas'],
    'sab_blanca' => ['saburra_color', 'Blanca'], 'sab_amarilla' => ['saburra_color', 'Amarilla'], 'sab_gris' => ['saburra_color', 'Gris / oscura'], 'sin_saburra' => ['saburra_color', 'Sin saburra (pelada)'],
    'sab_fina' => ['saburra_espesor', 'Fina'], 'sab_gruesa' => ['saburra_espesor', 'Gruesa'],
    'sab_raiz' => ['saburra_distribucion', 'Más en la raíz'], 'sab_centro' => ['saburra_distribucion', 'Más en el centro'], 'sab_parches' => ['saburra_distribucion', 'A parches (geográfica)'],
    'sin_raiz' => ['saburra_raiz', 'Sin raíz (se desprende)'],
    'sab_seca' => ['saburra_humedad', 'Seca'], 'sab_humeda' => ['saburra_humedad', 'Húmeda'], 'sab_pegajosa' => ['saburra_humedad', 'Pegajosa / grasosa'],
    'z_punta' => ['zonas', 'Punta (Corazón / Pulmón)'], 'z_centro' => ['zonas', 'Centro (Bazo / Estómago)'],
    'z_laterales' => ['zonas', 'Laterales (Hígado / Vesícula)'], 'z_raiz' => ['zonas', 'Raíz (Riñón)'],
    'insomnio' => ['sueno', 'Le cuesta dormirse'], 'despierta' => ['sueno', 'Se despierta seguido'], 'suenos' => ['sueno', 'Sueño liviano / muchos sueños'], 'somnolencia' => ['sueno', 'Somnolencia de día'],
    'dig_lenta' => ['digestion', 'Lenta / pesadez'], 'distension' => ['digestion', 'Distensión / gases'], 'acidez' => ['digestion', 'Acidez / reflujo'],
    'heces_blandas' => ['digestion', 'Heces blandas'], 'constipacion' => ['digestion', 'Constipación'],
    'sed_mucha' => ['sed', 'Mucha sed (bebidas frías)'], 'sed_poca' => ['sed', 'Poca sed'], 'boca_seca' => ['sed', 'Boca seca sin sed'],
    'friolento' => ['frio_calor', 'Friolento / manos y pies fríos'], 'caluroso' => ['frio_calor', 'Caluroso'], 'calores' => ['frio_calor', 'Calores / sudor nocturno'],
    'estres' => ['animo', 'Estrés / irritabilidad'], 'ansiedad' => ['animo', 'Ansiedad / preocupación'], 'tristeza' => ['animo', 'Tristeza / desgano'], 'humor' => ['animo', 'Cambios de humor'],
    'ciclo_irregular' => ['ciclo', 'Irregular'], 'dolor_menstrual' => ['ciclo', 'Dolor menstrual'], 'ciclo_abundante' => ['ciclo', 'Abundante'], 'ciclo_escaso' => ['ciclo', 'Escaso'],
    'sudor_dia' => ['sudor', 'Sudor espontáneo de día'], 'sudor_noche' => ['sudor', 'Sudor nocturno'], 'no_transpira' => ['sudor', 'Casi no transpira'],
    'cefalea' => ['dolor', 'Cabeza'], 'dolor_fijo' => ['dolor', 'Fijo / punzante'], 'dolor_movil' => ['dolor', 'Se mueve de lugar'],
    'pesadez' => ['dolor', 'Pesadez en el cuerpo'], 'lumbar' => ['dolor', 'Lumbar / rodillas débiles'],
    'orina_clara' => ['orina', 'Clara y abundante'], 'orina_oscura' => ['orina', 'Oscura / escasa'], 'nicturia' => ['orina', 'Se levanta a orinar de noche'],
    'poco_apetito' => ['apetito', 'Poco apetito'], 'mucho_apetito' => ['apetito', 'Mucho apetito'], 'amargo' => ['apetito', 'Sabor amargo'], 'pastosa' => ['apetito', 'Boca pastosa / dulce'],
    'opresion' => ['torax', 'Opresión en el pecho'], 'suspira' => ['torax', 'Suspira seguido'], 'costados' => ['torax', 'Tensión en los costados'], 'palpitaciones' => ['torax', 'Palpitaciones'],
    'zumbidos' => ['sentidos', 'Zumbidos'], 'ojos_secos' => ['sentidos', 'Ojos secos / vista cansada'], 'mareos' => ['sentidos', 'Mareos'], 'ojos_rojos' => ['sentidos', 'Ojos rojos'],
];

/** Lo propio de cada órgano cuando la plantilla no lo define (tuina, chi kung con su sonido del Liu Zi Jue, ventosas, oreja). */
const MTC_ORGAN_DEFAULTS = [
    'Hígado' => [
        'meridian' => 'Hígado y Vesícula Biliar', 'sound' => 'Xu', 'piece' => '7.ª pieza del Ba Duan Jin («Cerrar los puños con mirada firme»)', 'ear' => ['Hígado'],
        'tuina' => 'na (pinzado) y gun (rodamiento) en trapecios, tui (empuje) por los costados y por la cara interna de la pierna, rou (amasado) sobre Ganshu (V18)',
        'cups' => 'trapecios y zona dorsal media (Ganshu V18)',
    ],
    'Vesícula Biliar' => [
        'meridian' => 'Vesícula Biliar e Hígado', 'sound' => 'Xu', 'piece' => '7.ª pieza del Ba Duan Jin («Cerrar los puños con mirada firme»)', 'ear' => ['Vesícula Biliar', 'Hígado'],
        'tuina' => 'tui (empuje) por el costado del cuerpo y la cara externa de la pierna (meridiano de Vesícula Biliar), na (pinzado) en trapecios, rou (amasado) sobre Danshu (V19)',
        'cups' => 'costados de la espalda y Danshu (V19)',
    ],
    'Corazón' => [
        'meridian' => 'Corazón y Pericardio', 'sound' => 'He', 'piece' => '5.ª pieza del Ba Duan Jin («Balancear la cabeza y la cola», calma el fuego del Corazón)', 'ear' => ['Corazón'],
        'tuina' => 'rou (amasado) suave entre los omóplatos sobre Xinshu (V15), tui (empuje) por la cara interna del brazo (Corazón y Pericardio), an (presión) suave en el centro del pecho',
        'cups' => 'zona entre los omóplatos (Xinshu V15, Jueyinshu V14)',
    ],
    'Bazo' => [
        'meridian' => 'Bazo y Estómago', 'sound' => 'Hu', 'piece' => '3.ª pieza del Ba Duan Jin («Separar cielo y tierra»)', 'ear' => ['Bazo', 'Estómago'],
        'tuina' => 'mo (frotación circular) en el abdomen en sentido horario, rou (amasado) y an (presión) sobre Pishu (V20) y Weishu (V21), tui (empuje) por el meridiano de Estómago en la pierna',
        'cups' => 'Shu dorsales de Bazo y Estómago (V20, V21)',
    ],
    'Estómago' => [
        'meridian' => 'Estómago y Bazo', 'sound' => 'Hu', 'piece' => '3.ª pieza del Ba Duan Jin («Separar cielo y tierra»)', 'ear' => ['Estómago', 'Bazo'],
        'tuina' => 'mo (frotación circular) suave en la boca del estómago, rou (amasado) sobre Weishu (V21), tui (empuje) por el meridiano de Estómago en la pierna',
        'cups' => 'Weishu (V21) y zona dorsal media',
    ],
    'Pulmón' => [
        'meridian' => 'Pulmón e Intestino Grueso', 'sound' => 'Si', 'piece' => '2.ª pieza del Ba Duan Jin («Tensar el arco»)', 'ear' => ['Pulmón'],
        'tuina' => 'tui (empuje) por la cara anterior del brazo (meridiano de Pulmón), rou (amasado) y an (presión) en la zona dorsal alta sobre Feishu (V13), ca (fricción) suave en el pecho',
        'cups' => 'zona dorsal alta (Feishu V13, Dazhui DU14)',
    ],
    'Riñón' => [
        'meridian' => 'Riñón y Vejiga', 'sound' => 'Chui', 'piece' => '6.ª pieza del Ba Duan Jin («Tocar los pies para fortalecer los riñones»)', 'ear' => ['Riñón'],
        'tuina' => 'ca (fricción) transversal en la zona lumbar sobre Shenshu (V23) y Mingmen (DU4), an (presión) y rou (amasado) en la planta del pie y el tobillo interno',
        'cups' => 'zona lumbar (Shenshu V23)',
    ],
    'General' => [
        'meridian' => '', 'sound' => '', 'piece' => 'el Ba Duan Jin completo, suave', 'ear' => [],
        'tuina' => 'tui (empuje) y rou (amasado) en la espalda sobre los Shu dorsales, an (presión) en los puntos del plan',
        'cups' => 'espalda (Shu dorsales)',
    ],
];

/**
 * Arma un patrón completo a partir de lo mínimo (órgano, nombre, señales, puntos) y los valores del órgano.
 * $o puede pisar cualquier clave del patrón.
 */
function mtc_seed_pattern(string $organ, string $label, string $signs, string $plain, string $principle, array $points, array $root,
    array $moxa, string $mode, array $tokens, array $press, array $recs, string $contra = '', array $o = []): array
{
    $d = MTC_ORGAN_DEFAULTS[$organ] ?? MTC_ORGAN_DEFAULTS['General'];
    $evidence = [];
    foreach ($tokens as $t) {
        if (isset(MTC_SIGN_TOKENS[$t])) {
            [$field, $value] = MTC_SIGN_TOKENS[$t];
            $evidence[$field][] = $value;
        }
    }
    $yin = $mode === 'no';
    $pieceShort = preg_replace('/^.*«([^»]+)».*$/u', '«$1»', $d['piece']) ?? $d['piece'];
    $sound = $d['sound'] !== '' ? ' y el sonido «' . $d['sound'] . '» del Liu Zi Jue (6 veces)' : '';
    return mtc_pattern_merge([
        'label' => $label,
        'organ' => $organ,
        'signs' => $signs,
        'plain' => $plain,
        'principle' => $principle,
        'meridian' => $d['meridian'],
        'points' => $points,
        'root' => $root,
        'techs' => [],
        'evidence' => $evidence,
        'moxa' => $moxa,
        'moxa_mode' => $mode,
        'moxa_note' => $yin ? 'Patrón con calor o Yang que asciende: sin moxa; tuina, acupresión y semillas.' : ($mode === 'cauto' ? 'Moxa breve y en picoteo; si aparece calor, pasar a acupresión.' : ''),
        'no_cups' => false,
        'tuina' => [
            'maniobras' => $yin ? ltrim(preg_replace('/(^|, )ca \(fricción\)[^,]*/u', '', $d['tuina']) ?? $d['tuina'], ', ') . ', siempre suave y sin generar calor' : $d['tuina'],
            'acupresion' => $press,
        ],
        'qigong' => [
            'sesion' => ($yin ? 'respiración suave con exhalación larga' : 'respiración abdominal') . ' y la ' . $d['piece'],
            'casa' => ($yin ? 'respiración con exhalación larga 5 minutos' : 'respiración abdominal 5 minutos') . ', 8 repeticiones de ' . $pieceShort . $sound,
            'min' => 10,
        ],
        'ventosas' => ['zona' => $d['cups'], 'modo' => $yin ? 'fijas suaves y breves, 3 a 5 minutos' : 'fijas, 5 a 8 minutos'],
        'oreja' => array_values(array_unique(array_merge(['Shenmen'], $d['ear'], $o['oreja'] ?? []))),
        'recs' => $recs,
        'contra_notes' => $contra,
        'ashi' => false,
    ], array_diff_key($o, ['oreja' => 1]));
}

/** Plantillas de fábrica: las 6 del comienzo (mtc_seed_core) y el resto del zang-fu. */
function mtc_seed_patterns(): array
{
    $core = mtc_seed_core();
    $extra = [
        'bazo' => ['label' => 'Deficiencia de Qi de Bazo', 'organ' => 'Bazo', 'principle' => 'Tonificar el Qi de Bazo', 'moxa_mode' => 'si'],
        'higado' => ['organ' => 'Hígado', 'principle' => 'Mover el Qi de Hígado y calmar la mente', 'moxa_mode' => 'cauto'],
        'rinon' => ['label' => 'Deficiencia de Riñón (general)', 'organ' => 'Riñón', 'principle' => 'Tonificar el Riñón', 'moxa_mode' => 'si'],
        'sangre' => ['organ' => 'General', 'principle' => 'Mover la Sangre y quitar la estasis', 'moxa_mode' => 'cauto'],
        'humedad' => ['organ' => 'General', 'principle' => 'Resolver la humedad y la flema', 'moxa_mode' => 'si'],
        'musculo' => ['organ' => 'General', 'principle' => 'Mover el Qi y la Sangre en la zona', 'moxa_mode' => 'si'],
    ];
    foreach ($extra as $k => $v) {
        $core[$k] = $v + $core[$k];
        $core[$k]['label'] = $v['label'] ?? $core[$k]['label'];
        $core[$k] += ['contra_notes' => '', 'no_cups' => false, 'moxa_note' => ''];
    }
    $picantes = 'Reducí picantes, frituras, alcohol y café.';
    $s = 'mtc_seed_pattern';
    $more = [
        'higado_yang' => $s('Hígado', 'Exacerbación del Yang de Hígado', 'cefalea, mareos, irritabilidad, cara roja; bordes de la lengua rojos',
            'El Yang (la energía que sube y calienta) del Hígado está exacerbado: se relaciona con dolores de cabeza, mareos, tensión en el cuello, irritabilidad y sueño inquieto.',
            'Someter el Yang de Hígado y nutrir el Yin de Hígado y Riñón', ['H3', 'VB20', 'R3', 'B6', 'PC6'], ['H3', 'R3'], [], 'no',
            ['bordes_rojos', 'roja', 'z_laterales', 'rigida', 'cefalea', 'mareos', 'estres', 'ojos_rojos', 'zumbidos', 'insomnio', 'caluroso'],
            ['H3', 'VB20', 'R3', 'B6', 'R1', 'PC6'],
            ['Evitá el alcohol, el café y las comidas muy picantes o fritas.', 'Pausas con respiración lenta (exhalación larga) varias veces al día.'],
            'Sin moxa (el Yang ya sube y calienta). Baños calientes largos, no: mejor pediluvio tibio.',
            ['tuina' => ['maniobras' => 'tui (empuje) descendente desde la cabeza y el cuello hacia los hombros, na (pinzado) en trapecios, an (presión) en Fengchi (VB20), rou (amasado) en la planta del pie (Yongquan R1) para bajar la energía'],
                'ventosas' => ['zona' => 'trapecios', 'modo' => 'deslizantes suaves, 5 minutos'], 'oreja' => ['Riñón', 'Surco hipotensor', 'Punto Cero']]),
        'higado_fuego' => $s('Hígado', 'Fuego de Hígado', 'cefalea intensa, ojos rojos, boca amarga, enojo; lengua roja con saburra amarilla',
            'Calor intenso en el Hígado (en MTC, «fuego de Hígado»): se relaciona con dolor de cabeza fuerte, ojos rojos, boca amarga, enojo fácil y constipación.',
            'Drenar el fuego de Hígado', ['H2', 'H3', 'VB20', 'IG11'], ['H2', 'IG11'], [], 'no',
            ['roja', 'bordes_rojos', 'sab_amarilla', 'seca', 'cefalea', 'ojos_rojos', 'amargo', 'estres', 'constipacion', 'sed_mucha', 'orina_oscura', 'caluroso'],
            ['H2', 'H3', 'VB20', 'IG11', 'VB43'], [$picantes, 'Preferí verduras verdes y amargas cocidas.'], 'Sin moxa.',
            ['ventosas' => ['zona' => 'trapecios y zona dorsal media', 'modo' => 'rápidas (poner y sacar), 3 a 5 minutos'], 'oreja' => ['Ojo', 'Punto Cero']]),
        'higado_viento' => $s('Hígado', 'Viento interno de Hígado', 'mareos, temblores, tics, rigidez; lengua temblorosa o desviada',
            'Movimiento interno desordenado (en MTC, «viento interno de Hígado»): se relaciona con mareos, temblores, tics o rigidez. Si algo aparece de golpe, consultá a un médico de inmediato.',
            'Calmar el viento, someter el Yang y nutrir el Yin', ['H3', 'VB20', 'R3', 'IG4'], ['H3', 'VB20'], [], 'no',
            ['temblorosa', 'desviada', 'rigida', 'roja', 'mareos', 'cefalea', 'zumbidos'], ['H3', 'VB20', 'R3', 'IG4', 'R1'],
            ['Movimientos lentos y suaves; evitá los cambios bruscos de posición.', 'Controlá tu presión arterial con tu médico.'],
            'Derivar al médico ante debilidad de un lado del cuerpo, dificultad para hablar o mareo súbito. Sin moxa.', ['oreja' => ['Subcórtex', 'Punto Cero']]),
        'higado_sangre' => $s('Hígado', 'Deficiencia de Sangre de Hígado', 'vista cansada, calambres, uñas débiles, reglas escasas; lengua pálida',
            'Falta de Sangre en el Hígado: se relaciona con la vista cansada, calambres, uñas quebradizas, mareos al pararse y reglas escasas.',
            'Nutrir la Sangre de Hígado', ['H8', 'B6', 'E36', 'V18', 'V17'], ['H8', 'E36'], ['E36', 'V18', 'V17', 'B6'], 'si',
            ['palida', 'fina', 'pequena', 'seca', 'ojos_secos', 'mareos', 'ciclo_escaso', 'suenos'], ['H8', 'B6', 'E36'],
            ['Sumá verduras de hoja verde, remolacha, legumbres y huevos.', 'Descansá la vista: pausas de pantalla cada hora.'],
            'Moxa suave (nutre). Tuina sin maniobras fuertes.', ['oreja' => ['Bazo', 'Ojo', 'Endocrino']]),
        'higado_yin' => $s('Hígado', 'Deficiencia de Yin de Hígado', 'ojos secos, mareos, calores; lengua roja con poca saburra',
            'Falta de Yin (lo que nutre y refresca) en el Hígado: se relaciona con ojos secos, mareos, calores y sueño liviano.',
            'Nutrir el Yin de Hígado y Riñón', ['H8', 'R3', 'B6', 'R6'], ['R3', 'H8'], [], 'no',
            ['roja', 'sin_saburra', 'seca', 'pequena', 'grietas', 'ojos_secos', 'mareos', 'calores', 'sudor_noche', 'suenos', 'boca_seca'], ['H8', 'R3', 'R6', 'B6'],
            ['Evitá trasnochar y los estimulantes.', 'Alimentos que nutren el Yin: sopas, peras cocidas, sésamo, porotos aduki.'],
            'Sin moxa (el Yin débil genera calor).', ['oreja' => ['Riñón', 'Ojo']]),
        'higado_humedad_calor' => $s('Hígado', 'Humedad-calor de Hígado y Vesícula Biliar', 'boca amarga, pesadez, molestias en los costados; saburra amarilla pegajosa',
            'Calor con humedad en el Hígado y la Vesícula Biliar: se relaciona con boca amarga, pesadez, molestias en los costados, náuseas o picazón.',
            'Drenar la humedad-calor de Hígado y Vesícula Biliar', ['VB34', 'H14', 'B9', 'H2', 'VB24'], ['VB34', 'B9'], [], 'no',
            ['sab_amarilla', 'sab_pegajosa', 'sab_gruesa', 'bordes_rojos', 'z_laterales', 'amargo', 'costados', 'pesadez', 'orina_oscura', 'distension'], ['VB34', 'B9', 'H2', 'VB41'],
            ['Evitá frituras, grasas, alcohol y azúcar.', 'Preferí verduras amargas cocidas (alcaucil, radicheta, achicoria).'], 'Sin moxa.',
            ['ventosas' => ['zona' => 'zona dorsal media (Ganshu V18, Danshu V19)', 'modo' => 'deslizantes, 5 minutos'], 'oreja' => ['Vesícula Biliar', 'Punto Cero', 'Endocrino']]),
        'corazon_qi' => $s('Corazón', 'Deficiencia de Qi de Corazón', 'palpitaciones al esfuerzo, cansancio, falta de aire; lengua pálida',
            'Energía del Corazón baja: se relaciona con palpitaciones al hacer esfuerzo, cansancio y falta de aire.',
            'Tonificar el Qi de Corazón', ['PC6', 'C7', 'V15', 'RM17', 'E36'], ['PC6', 'V15'], ['V15', 'RM17', 'E36'], 'si',
            ['palida', 'z_punta', 'palpitaciones', 'sudor_dia', 'tristeza', 'somnolencia'], ['PC6', 'C7', 'RM17'],
            ['Actividad suave y regular, sin agotarte.', 'Descansá un rato después de comer.'],
            'Palpitaciones nuevas, fuertes o con dolor de pecho: consultá al médico.', ['oreja' => ['Pulmón']]),
        'corazon_yang' => $s('Corazón', 'Deficiencia de Yang de Corazón', 'palpitaciones, frío, manos frías, opresión; lengua pálida y húmeda',
            'Falta de calor y energía en el Corazón: se relaciona con palpitaciones, frío, manos frías y opresión en el pecho.',
            'Tonificar y calentar el Yang de Corazón', ['PC6', 'V15', 'RM17', 'DU14', 'RM6'], ['V15', 'RM6'], ['V15', 'DU14', 'RM6', 'RM17'], 'si',
            ['palida', 'muy_humeda', 'hinchada', 'z_punta', 'palpitaciones', 'friolento', 'opresion', 'sudor_dia'], ['PC6', 'C7'],
            ['Mantené el cuerpo abrigado, sobre todo pecho y espalda.', 'Comidas calientes y cocidas.'],
            'Dolor de pecho, piernas hinchadas o falta de aire en reposo: al médico.', ['oreja' => ['Simpático']]),
        'corazon_sangre' => $s('Corazón', 'Deficiencia de Sangre de Corazón', 'palpitaciones, le cuesta dormirse, mala memoria, ansiedad; lengua pálida y fina',
            'Falta de Sangre en el Corazón: se relaciona con palpitaciones, dificultad para dormirse, sueños, mala memoria y ansiedad.',
            'Nutrir la Sangre de Corazón y calmar la mente', ['C7', 'PC6', 'B6', 'V15', 'V20', 'E36'], ['C7', 'B6'], ['V20', 'E36', 'V15', 'B6'], 'si',
            ['palida', 'fina', 'pequena', 'z_punta', 'palpitaciones', 'insomnio', 'suenos', 'ansiedad', 'mareos'], ['C7', 'PC6', 'YINTANG', 'B6'],
            ['Horarios regulares de sueño y una rutina tranquila antes de dormir.', 'Sumá legumbres, verduras de hoja, remolacha y cereales integrales.'], '',
            ['oreja' => ['Bazo', 'Subcórtex']]),
        'corazon_yin' => $s('Corazón', 'Deficiencia de Yin de Corazón', 'palpitaciones, sueño inquieto, calores, ansiedad; punta de la lengua roja con poca saburra',
            'Falta de Yin (lo que nutre y refresca) en el Corazón: se relaciona con palpitaciones, sueño inquieto, calores de noche, boca seca y ansiedad.',
            'Nutrir el Yin de Corazón y calmar la mente', ['C6', 'C7', 'PC6', 'R3', 'R6', 'B6'], ['C6', 'R3'], [], 'no',
            ['punta_roja', 'roja', 'sin_saburra', 'grietas', 'z_punta', 'palpitaciones', 'insomnio', 'despierta', 'calores', 'sudor_noche', 'ansiedad', 'boca_seca'],
            ['C6', 'C7', 'PC6', 'R6', 'YINTANG', 'R1'],
            ['Evitá café, té negro, alcohol y pantallas de noche.', 'Cena liviana y temprana.'], 'Sin moxa (el Yin débil genera calor).',
            ['oreja' => ['Riñón', 'Subcórtex']]),
        'corazon_fuego' => $s('Corazón', 'Fuego de Corazón', 'agitación, insomnio, aftas, sed; punta de la lengua roja',
            'Calor intenso en el Corazón: se relaciona con agitación, insomnio, aftas en la boca o la lengua, sed y orina oscura.',
            'Drenar el fuego de Corazón y calmar la mente', ['PC8', 'C7', 'PC7', 'IG11'], ['C7', 'PC7'], [], 'no',
            ['punta_roja', 'roja', 'sab_amarilla', 'puntos_rojos', 'insomnio', 'ansiedad', 'sed_mucha', 'orina_oscura', 'caluroso', 'palpitaciones'],
            ['PC8', 'C7', 'PC7', 'IG11'], [$picantes, 'Tomá agua fresca (no helada) durante el día.'], 'Sin moxa.', ['oreja' => ['Punto Cero']]),
        'corazon_flema' => $s('Corazón', 'Flema que obstruye el Corazón', 'mente nublada, opresión, confusión; saburra gruesa y pegajosa',
            'Acumulación de «flema» que nubla la mente: se relaciona con cabeza nublada, opresión en el pecho, confusión o cambios de ánimo.',
            'Resolver la flema y abrir los orificios del Corazón', ['PC5', 'E40', 'PC6', 'RM12', 'C7'], ['E40', 'PC5'], ['RM12', 'E40'], 'cauto',
            ['sab_gruesa', 'sab_pegajosa', 'hinchada', 'z_punta', 'opresion', 'mareos', 'tristeza', 'humor', 'pesadez', 'somnolencia'], ['PC5', 'PC6', 'E40', 'C7'],
            ['Reducí lácteos, harinas refinadas, azúcar y fritos.', 'Caminatas diarias al aire libre.'],
            'Confusión o cambios bruscos de conducta: derivar al médico.', ['oreja' => ['Bazo', 'Subcórtex']]),
        'bazo_yang' => $s('Bazo', 'Deficiencia de Yang de Bazo', 'frío, heces blandas, cansancio, hinchazón; lengua pálida y húmeda',
            'Falta de calor en la digestión: se relaciona con frío, heces blandas, hinchazón y cansancio.',
            'Calentar y tonificar el Yang de Bazo', ['E36', 'RM12', 'V20', 'RM6'], ['E36', 'RM6'], ['E36', 'RM12', 'RM6', 'V20', 'RM8'], 'si',
            ['palida', 'muy_humeda', 'hinchada', 'marcas', 'sab_blanca', 'z_centro', 'friolento', 'heces_blandas', 'dig_lenta', 'poco_apetito', 'sed_poca'], ['E36', 'B6', 'PC6'],
            ['Comé tibio y cocido; limitá crudos, bebidas frías y exceso de lácteos.', 'Un poco de jengibre fresco en las comidas.'], '', ['oreja' => ['Simpático']]),
        'bazo_yin' => $s('Bazo', 'Deficiencia de Yin de Bazo', 'boca seca, poco apetito, heces secas; lengua seca con poca saburra en el centro',
            'Falta de Yin (lo que nutre e hidrata) en la digestión: se relaciona con boca y labios secos, poco apetito, heces secas y cansancio.',
            'Nutrir el Yin de Bazo y Estómago', ['B6', 'E36', 'RM12', 'B3', 'R6'], ['B6', 'B3'], [], 'no',
            ['seca', 'grietas', 'sin_saburra', 'z_centro', 'pequena', 'boca_seca', 'poco_apetito', 'constipacion', 'sed_poca', 'calores'], ['B6', 'B3', 'E36', 'R6'],
            ['Comidas tibias, húmedas y suaves: sopas, caldos, purés y compotas.', 'Evitá picantes, frituras y comidas muy secas o tostadas.'],
            'Sin moxa fuerte (el Yin débil genera calor).', ['oreja' => ['Endocrino']]),
        'bazo_humedad' => $s('Bazo', 'Humedad que invade el Bazo', 'pesadez, poco apetito, distensión, heces blandas; saburra blanca pegajosa',
            'Humedad que traba la digestión: se relaciona con pesadez, poco apetito, distensión y heces blandas.',
            'Resolver la humedad y fortalecer el Bazo', ['B9', 'E36', 'RM12', 'B3', 'RM9'], ['B9', 'RM12'], ['RM12', 'B9', 'E36', 'RM9'], 'si',
            ['sab_blanca', 'sab_pegajosa', 'sab_gruesa', 'hinchada', 'muy_humeda', 'z_centro', 'pesadez', 'poco_apetito', 'distension', 'heces_blandas', 'pastosa', 'sed_poca'],
            ['B9', 'E36', 'B3'], ['Reducí harinas refinadas, azúcar, lácteos, fritos y alcohol.', 'Verduras cocidas, legumbres, arroz y un poco de jengibre.'], '',
            ['oreja' => ['Endocrino', 'San Jiao']]),
        'bazo_sangre' => $s('Bazo', 'El Bazo no controla la Sangre', 'moretones fáciles, sangrados, reglas abundantes; lengua pálida',
            'La energía del Bazo no alcanza a contener la Sangre: se relaciona con moretones fáciles, sangrados o reglas abundantes, con cansancio.',
            'Tonificar el Qi de Bazo para contener la Sangre', ['B1', 'B6', 'E36', 'V20', 'B10'], ['B1', 'V20'], ['B1', 'V20', 'E36'], 'si',
            ['palida', 'marcas', 'sab_blanca', 'ciclo_abundante', 'poco_apetito'], ['E36', 'B6', 'B10'],
            ['Comidas tibias y nutritivas; evitá los ayunos largos.'],
            'Sangrados sin causa clara o reglas muy abundantes: al médico. Sin ventosas (fragilidad capilar).',
            ['no_cups' => true, 'oreja' => ['Hígado', 'Endocrino']]),
        'bazo_hundido' => $s('Bazo', 'Qi de Bazo hundido', 'sensación de peso hacia abajo, prolapsos, cansancio; lengua pálida',
            'La energía que sostiene está baja: se relaciona con sensación de peso hacia abajo, cansancio al estar de pie y tendencia a prolapsos o hemorroides.',
            'Tonificar y elevar el Qi', ['DU20', 'RM6', 'E36', 'V20'], ['DU20', 'RM6'], ['DU20', 'RM6', 'E36', 'V20'], 'si',
            ['palida', 'marcas', 'flacida', 'sab_blanca', 'pesadez', 'heces_blandas', 'sudor_dia', 'somnolencia'], ['E36', 'DU20'],
            ['Evitá cargar peso y estar mucho tiempo de pie.', 'Ejercicios suaves de piso pélvico (hipopresivos) si te los indicamos.'],
            'Prolapsos o sangrado: derivar al médico.', ['oreja' => ['Subcórtex']]),
        'pulmon_qi' => $s('Pulmón', 'Deficiencia de Qi de Pulmón', 'falta de aire, voz débil, resfríos frecuentes, sudor fácil; lengua pálida',
            'Energía del Pulmón baja: se relaciona con falta de aire al esfuerzo, voz débil, resfríos frecuentes y sudor fácil.',
            'Tonificar el Qi de Pulmón y fortalecer la defensa', ['P9', 'V13', 'E36', 'RM17', 'P7'], ['V13', 'E36'], ['V13', 'E36', 'RM17', 'DU14'], 'si',
            ['palida', 'z_punta', 'sab_blanca', 'sudor_dia', 'tristeza', 'somnolencia'], ['P9', 'P7', 'E36'],
            ['Abrigá el cuello y la espalda alta.', 'Caminatas al aire libre, respirando por la nariz.'], '', ['oreja' => ['Endocrino']]),
        'pulmon_yin' => $s('Pulmón', 'Deficiencia de Yin de Pulmón', 'tos seca, garganta seca, calores; lengua roja y seca',
            'Falta de Yin (lo que hidrata) en el Pulmón: se relaciona con tos seca, garganta seca y calores por la tarde o la noche.',
            'Nutrir el Yin de Pulmón', ['P7', 'R6', 'P9', 'V13', 'V43'], ['P7', 'R6'], [], 'no',
            ['roja', 'seca', 'sin_saburra', 'grietas', 'boca_seca', 'calores', 'sudor_noche'], ['P7', 'R6', 'P9', 'P5'],
            ['Peras cocidas, sopas y líquidos tibios; evitá picantes y ambientes muy secos.'],
            'Sin moxa. Tos de más de 3 semanas o con sangre: al médico.', ['oreja' => ['Riñón']]),
        'pulmon_viento_frio' => $s('Pulmón', 'Invasión de viento-frío', 'resfrío con escalofríos, estornudos, moco claro, contracturas; saburra blanca y fina',
            'Un resfrío «de frío»: escalofríos, estornudos, moco claro y contracturas en el cuello y la espalda.',
            'Liberar el exterior y dispersar el viento-frío', ['P7', 'IG4', 'VB20', 'DU14'], ['P7', 'IG4'], ['DU14', 'V13'], 'si',
            ['sab_blanca', 'sab_fina', 'friolento', 'cefalea', 'no_transpira'], ['P7', 'IG4', 'VB20'],
            ['Abrigate, tomá caldos calientes con jengibre y descansá.'], 'Fiebre alta o falta de aire: al médico.',
            ['ventosas' => ['zona' => 'zona dorsal alta (Dazhui DU14, Feishu V13)', 'modo' => 'fijas, 5 a 8 minutos'], 'oreja' => ['Punto Cero']]),
        'pulmon_viento_calor' => $s('Pulmón', 'Invasión de viento-calor', 'dolor de garganta, fiebre, moco amarillo; punta de la lengua roja',
            'Un resfrío «de calor»: dolor de garganta, fiebre, sed y moco amarillo.',
            'Liberar el exterior y dispersar el viento-calor', ['IG11', 'SJ5', 'IG4', 'DU14'], ['IG11', 'SJ5'], [], 'no',
            ['punta_roja', 'sab_amarilla', 'sab_fina', 'sed_mucha', 'cefalea', 'caluroso'], ['IG11', 'SJ5', 'IG4'],
            ['Líquidos tibios, descanso y comidas livianas.'], 'Sin moxa. Con fiebre alta, consultá al médico.',
            ['ventosas' => ['zona' => 'zona dorsal alta', 'modo' => 'rápidas, 3 minutos (no con fiebre alta)'], 'oreja' => ['Punto Cero']]),
        'pulmon_flema' => $s('Pulmón', 'Flema-humedad en el Pulmón', 'tos con flema blanca abundante, opresión; saburra blanca y pegajosa',
            'Acumulación de flema en los pulmones: se relaciona con tos con mucha flema, opresión en el pecho y cansancio.',
            'Resolver la flema y hacer descender el Qi de Pulmón', ['E40', 'P5', 'RM17', 'V13', 'P7'], ['E40', 'V13'], ['V13', 'RM17', 'E40'], 'si',
            ['sab_gruesa', 'sab_pegajosa', 'sab_blanca', 'hinchada', 'opresion', 'pesadez', 'pastosa'], ['E40', 'P5', 'P7', 'RM17'],
            ['Reducí lácteos, harinas refinadas, azúcar y fritos.'], '',
            ['ventosas' => ['zona' => 'zona dorsal alta y media (Feishu V13)', 'modo' => 'fijas, 5 a 8 minutos'], 'oreja' => ['Bazo']]),
        'rinon_yin' => $s('Riñón', 'Deficiencia de Yin de Riñón', 'calores, sudor nocturno, zumbidos, lumbalgia; lengua roja sin saburra',
            'Reservas de Yin bajas: se relaciona con calores o sudor de noche, zumbidos, boca seca y dolor lumbar.',
            'Nutrir el Yin de Riñón', ['R3', 'R6', 'B6', 'V23'], ['R3', 'R6'], [], 'no',
            ['roja', 'sin_saburra', 'sin_raiz', 'seca', 'grietas', 'z_raiz', 'pequena', 'calores', 'sudor_noche', 'zumbidos', 'lumbar', 'despierta', 'boca_seca'],
            ['R3', 'R6', 'B6', 'R1'],
            ['Descanso suficiente; evitá trasnochar y el exceso de trabajo.', 'Alimentos que nutren el Yin: sésamo negro, porotos negros, sopas y algas.'],
            'Sin moxa (Yin débil con calor).', ['tuina' => ['maniobras' => 'an (presión) y rou (amasado) suaves en la zona lumbar y en la planta del pie (Yongquan R1), sin fricción que caliente'], 'oreja' => ['Endocrino']]),
        'rinon_yang' => $s('Riñón', 'Deficiencia de Yang de Riñón', 'frío, lumbalgia, orina clara abundante, cansancio; lengua pálida y húmeda',
            'Falta de calor de base: se relaciona con frío en la espalda baja y los pies, orinar mucho, cansancio y pocas ganas.',
            'Calentar y tonificar el Yang de Riñón', ['V23', 'DU4', 'RM4', 'R3', 'R7'], ['V23', 'DU4'], ['V23', 'DU4', 'RM4', 'R3'], 'si',
            ['palida', 'muy_humeda', 'hinchada', 'z_raiz', 'friolento', 'orina_clara', 'nicturia', 'lumbar', 'somnolencia', 'tristeza'], ['R3', 'R7', 'R1'],
            ['Mantené abrigados la zona lumbar y los pies.', 'Comidas calientes: legumbres, sopas, semillas de sésamo y nueces.'], '',
            ['oreja' => ['Endocrino', 'Zona lumbar']]),
        'rinon_jing' => $s('Riñón', 'Deficiencia de Jing de Riñón', 'envejecimiento precoz, pelo y dientes débiles, memoria floja, rodillas débiles',
            'Reservas profundas bajas (en MTC, «Jing»): se relaciona con envejecimiento precoz, pelo y dientes débiles, memoria floja y rodillas débiles.',
            'Nutrir el Jing', ['R3', 'V23', 'VB39', 'RM4'], ['R3', 'VB39'], ['V23', 'RM4', 'VB39'], 'si',
            ['z_raiz', 'pequena', 'sin_raiz', 'lumbar', 'zumbidos', 'mareos'], ['R3', 'VB39', 'R1'],
            ['Descanso, ritmo de vida tranquilo y alimentos «de semilla»: frutos secos, sésamo y legumbres.'], '', ['oreja' => ['Endocrino', 'Subcórtex']]),
        'rinon_qi' => $s('Riñón', 'Qi de Riñón no firme', 'ganas frecuentes de orinar, goteo o pérdidas, lumbalgia débil; lengua pálida',
            'La energía del Riñón no sostiene: se relaciona con ganas frecuentes de orinar, goteo o pérdidas y debilidad lumbar.',
            'Tonificar y afirmar el Qi de Riñón', ['V23', 'RM4', 'R3', 'DU4'], ['V23', 'RM4'], ['V23', 'RM4', 'DU4'], 'si',
            ['palida', 'z_raiz', 'orina_clara', 'nicturia', 'lumbar'], ['R3', 'R7'],
            ['Ejercicios de piso pélvico y abrigo de la zona lumbar.'], '', ['oreja' => ['Vejiga', 'Endocrino']]),
        'rinon_no_recibe' => $s('Riñón', 'El Riñón no recibe el Qi', 'falta de aire al esfuerzo (más al inspirar), asma crónica, lumbalgia',
            'Falta de aire al esfuerzo que se relaciona con un Riñón débil que «no recibe» la respiración.',
            'Tonificar el Riñón para que reciba el Qi', ['R3', 'V23', 'RM17', 'P9', 'R7'], ['R3', 'V23'], ['V23', 'RM17', 'DU4'], 'si',
            ['palida', 'z_raiz', 'lumbar', 'sudor_dia', 'orina_clara', 'friolento'], ['R3', 'P9', 'R7'],
            ['Respiración abdominal diaria, lenta y sin forzar.'], 'Falta de aire nueva o en reposo: al médico.', ['oreja' => ['Pulmón']]),
        'estomago_yin' => $s('Estómago', 'Deficiencia de Yin de Estómago', 'boca seca, hambre sin ganas de comer, molestia sorda; lengua roja sin saburra en el centro',
            'Falta de Yin en el Estómago: se relaciona con boca seca, hambre sin ganas de comer, molestias sordas en el estómago y heces secas.',
            'Nutrir el Yin de Estómago', ['E36', 'RM12', 'B6', 'PC6'], ['E36', 'B6'], [], 'no',
            ['roja', 'sin_saburra', 'z_centro', 'grietas', 'seca', 'boca_seca', 'constipacion', 'acidez', 'poco_apetito'], ['E36', 'B6', 'PC6', 'R6'],
            ['Comidas tibias y húmedas; evitá picantes, café y frituras.'], 'Sin moxa.', ['oreja' => ['Endocrino']]),
        'estomago_fuego' => $s('Estómago', 'Fuego de Estómago', 'ardor o acidez, mucha hambre, sed, mal aliento; saburra amarilla',
            'Calor intenso en el Estómago: se relaciona con ardor o acidez, mucha hambre y sed, mal aliento o encías inflamadas.',
            'Drenar el fuego de Estómago', ['E44', 'IG11', 'RM12', 'PC6'], ['E44', 'IG11'], [], 'no',
            ['roja', 'sab_amarilla', 'seca', 'z_centro', 'acidez', 'mucho_apetito', 'sed_mucha', 'constipacion', 'caluroso'], ['E44', 'IG11', 'PC6', 'RM12'],
            [$picantes, 'Comidas frescas (no heladas): verduras cocidas, frutas y arroz.'], 'Sin moxa.', ['oreja' => ['Punto Cero']]),
        'estomago_frio' => $s('Estómago', 'Frío en el Estómago', 'dolor que mejora con calor, náuseas, digestión lenta; saburra blanca',
            'Frío en el Estómago: se relaciona con dolor que mejora con calor y comidas calientes, náuseas y digestión lenta.',
            'Calentar el Estómago y dispersar el frío', ['E36', 'RM12', 'PC6'], ['E36', 'RM12'], ['RM12', 'E36', 'RM8'], 'si',
            ['palida', 'sab_blanca', 'muy_humeda', 'z_centro', 'friolento', 'dig_lenta', 'sed_poca'], ['E36', 'PC6'],
            ['Evitá bebidas y comidas frías o heladas; preferí comidas calientes.', 'Un poco de jengibre en las comidas.'], '', ['oreja' => ['Simpático']]),
        'estomago_alimentos' => $s('Estómago', 'Retención de alimentos', 'plenitud, eructos, mal aliento, náuseas después de comer; saburra gruesa',
            'La comida se «estanca» en el estómago: se relaciona con plenitud, eructos, mal aliento y náuseas después de comer.',
            'Digerir la retención y hacer descender el Qi de Estómago', ['RM12', 'E36', 'PC6', 'E25', 'RM10'], ['RM12', 'E25'], ['RM12', 'E36'], 'cauto',
            ['sab_gruesa', 'sab_pegajosa', 'z_centro', 'distension', 'dig_lenta', 'acidez', 'pastosa'], ['PC6', 'E36', 'E44'],
            ['Porciones chicas, masticando despacio; cena liviana y temprana.', 'Caminata suave de 10 minutos después de comer.'], '', ['oreja' => ['Punto Cero']]),
        'vb_qi' => $s('Vesícula Biliar', 'Deficiencia de Qi de Vesícula Biliar', 'indecisión, sobresaltos, sueño liviano; lengua pálida',
            'La energía de la Vesícula Biliar está baja (en MTC se relaciona con el coraje y las decisiones): indecisión, sobresaltos y sueño liviano.',
            'Tonificar el Qi de Vesícula Biliar y calmar la mente', ['VB40', 'VB34', 'V19', 'C7'], ['VB40', 'V19'], ['V19', 'VB40', 'VB34'], 'si',
            ['palida', 'ansiedad', 'suenos', 'despierta', 'mareos'], ['VB40', 'C7', 'PC6'],
            ['Rutinas estables y decisiones de a una; respirá antes de decidir.'], '', ['oreja' => ['Corazón']]),
        'vb_humedad_calor' => $s('Vesícula Biliar', 'Humedad-calor de Vesícula Biliar', 'molestia en el costado derecho, náuseas con grasas, boca amarga; saburra amarilla pegajosa',
            'Calor con humedad en la Vesícula Biliar: se relaciona con molestias en el costado derecho, náuseas con comidas grasas y boca amarga.',
            'Drenar la humedad-calor de la Vesícula Biliar', ['VB34', 'VB24', 'V19', 'H14', 'B9'], ['VB34', 'VB24'], [], 'no',
            ['sab_amarilla', 'sab_pegajosa', 'z_laterales', 'amargo', 'costados', 'distension', 'pesadez'], ['VB34', 'B9', 'VB41'],
            ['Evitá grasas, frituras, alcohol y comidas pesadas de noche.'],
            'Dolor fuerte en el costado derecho, fiebre o piel amarilla: al médico de inmediato. Sin moxa.', ['oreja' => ['Punto Cero', 'Endocrino']]),
        'vb_yin' => $s('Vesícula Biliar', 'Deficiencia del Yin de Vesícula Biliar', 'boca amarga y seca, ojos secos, sueño liviano, calores; bordes rojos con poca saburra',
            'Falta de Yin (lo que nutre y refresca) en la Vesícula Biliar: se relaciona con boca seca o amarga, ojos secos, sueño liviano y calores.',
            'Nutrir el Yin de Hígado y Vesícula Biliar y calmar el calor', ['VB34', 'VB40', 'H8', 'R3', 'R6'], ['VB40', 'R3'], [], 'no',
            ['bordes_rojos', 'sin_saburra', 'seca', 'z_laterales', 'amargo', 'boca_seca', 'ojos_secos', 'suenos', 'despierta', 'calores'], ['VB34', 'VB40', 'R6', 'H8'],
            ['Dormí antes de las 23 h (horario de la Vesícula Biliar).', 'Evitá grasas, alcohol y picantes.'], 'Sin moxa (Yin débil con calor).',
            ['oreja' => ['Riñón']]),
    ];
    return $core + $more;
}

/** Indicaciones de fábrica (genéricas). therapist = requiere indicación del terapeuta: se ofrece, nunca se tilda sola. */
function mtc_seed_indications(): array
{
    $yinHeat = ['higado_yang', 'higado_yin', 'higado_fuego', 'corazon_yin', 'corazon_fuego', 'rinon_yin', 'vb_yin', 'bazo_yin', 'pulmon_yin', 'estomago_yin'];
    return [
        'ritmo' => ['category' => 'horarios', 'patterns' => ['*'], 'therapist' => false, 'caution' => '',
            'text' => 'Comer con horarios regulares: desayuno completo y cena liviana y temprana, dejando unas 12 horas sin comer hasta el día siguiente para que la digestión descanse.'],
        'sueno_organos' => ['category' => 'sueno', 'patterns' => ['higado*', 'vb*', 'corazon_yin', 'corazon_sangre', 'rinon_yin'], 'therapist' => false, 'caution' => '',
            'text' => 'Acostarse antes de las 23 h: en el reloj de órganos de la MTC, de 23 a 3 h trabajan Vesícula Biliar e Hígado, que se recuperan mejor durante el sueño. Dejar las pantallas media hora antes de dormir.'],
        'pediluvio' => ['category' => 'hidroterapia', 'patterns' => array_merge($yinHeat, ['higado', 'corazon_sangre']), 'therapist' => false,
            'caution' => 'Con diabetes o poca sensibilidad en los pies, controlá la temperatura del agua con la mano.',
            'text' => 'Baño de pies por la noche: agua tibia a caliente, siempre agradable (tibia si hay sofocos o calor), hasta los tobillos, 10 a 15 minutos antes de dormir; se puede sumar un puñado de sal.'],
        'bano_sal' => ['category' => 'hidroterapia', 'patterns' => [], 'therapist' => true,
            'caution' => 'No en hipertensión, problemas cardíacos, embarazo ni várices importantes. En patrones de Yin deficiente o Yang que asciende, preferí un baño tibio corto o el pediluvio.',
            'text' => 'Baño de inmersión caliente con sal: cantidad, duración y frecuencia según lo que indique el terapeuta.'],
        'tierra' => ['category' => 'habitos', 'patterns' => ['higado*', 'corazon*', 'vb*'], 'therapist' => false, 'caution' => '',
            'text' => 'Pasar un rato cada día al aire libre, en lo posible en contacto con plantas o pasto (caminar descalzo, jardinería).'],
        'frios' => ['category' => 'alimentacion', 'patterns' => ['bazo*', 'estomago_frio', 'humedad', 'higado', 'higado_sangre', 'rinon_yang', 'corazon_yang', 'pulmon_flema'], 'therapist' => false, 'caution' => '',
            'text' => 'Preferir bebidas y comidas tibias; dejar de lado lo helado, que en MTC enfría el Bazo y Estómago y enlentece la digestión.'],
        'microondas' => ['category' => 'habitos', 'patterns' => ['bazo*', 'estomago*'], 'therapist' => false, 'caution' => '',
            'text' => 'Recalentar la comida en olla, sartén u horno en lugar del microondas.'],
        'limon' => ['category' => 'alimentacion', 'patterns' => ['higado*', 'vb*', 'humedad', 'estomago_alimentos'], 'therapist' => false, 'caution' => 'Con acidez o gastritis, consultalo antes.',
            'text' => 'Al levantarse, un vaso de agua tibia con unas gotas o el jugo de medio limón.'],
        'bicarbonato' => ['category' => 'alimentacion', 'patterns' => [], 'therapist' => true,
            'caution' => 'No en hipertensión, enfermedad renal, dietas con poca sal ni embarazo; consultar al médico si toma medicación.',
            'text' => 'Bicarbonato de sodio en agua: dosis y cantidad de días según lo que indique el terapeuta.'],
        'ensaladas' => ['category' => 'alimentacion', 'patterns' => ['bazo*', 'estomago_frio', 'humedad', 'pulmon_flema', 'corazon_flema'], 'therapist' => false, 'caution' => '',
            'text' => 'Moderar los crudos: en MTC cuestan más al Bazo y favorecen la humedad. Mejor verduras cocidas (al vapor, salteadas u horneadas).'],
        'legumbres' => ['category' => 'alimentacion', 'patterns' => ['bazo', 'bazo_yang', 'rinon*', 'higado_sangre', 'corazon_sangre'], 'therapist' => false, 'caution' => '',
            'text' => 'Incluir legumbres y cereales integrales cocidos varias veces por semana.'],
        'picantes' => ['category' => 'alimentacion', 'patterns' => ['higado_fuego', 'higado_yang', 'higado_humedad_calor', 'corazon_fuego', 'estomago_fuego', 'vb_humedad_calor', 'vb_yin'], 'therapist' => false, 'caution' => '',
            'text' => 'Reducir picantes, frituras, alcohol y café.'],
        'aerobica' => ['category' => 'actividad', 'patterns' => ['*'], 'therapist' => false, 'caution' => '',
            'text' => 'Moverse todos los días con una actividad que disfrutes (caminar, bici, nadar) además de la práctica de chi kung.'],
        'fitoterapia' => ['category' => 'fitoterapia', 'patterns' => [], 'therapist' => true,
            'caution' => 'Revisar medicación, embarazo, lactancia y alergias antes de indicarla.',
            'text' => 'Fitoterapia: planta, preparación (infusión o tintura), dosis y duración según lo que indique el terapeuta; se revisa en el siguiente control.'],
        'anis' => ['category' => 'especias', 'patterns' => ['bazo*', 'estomago*'], 'therapist' => false, 'caution' => '',
            'text' => 'Usar especias suaves que ayudan a digerir, como anís, hinojo o cardamomo (en comidas o infusión).'],
        'jengibre' => ['category' => 'especias', 'patterns' => ['bazo_yang', 'estomago_frio', 'humedad', 'bazo_humedad', 'pulmon_viento_frio', 'rinon_yang'], 'therapist' => false,
            'caution' => 'No si hay mucho calor, acidez o toma anticoagulantes en dosis altas.',
            'text' => 'Sumar un poco de jengibre fresco a las comidas.'],
    ];
}

function mtc_catalog_schema(): void
{
    static $done = false;
    if ($done) {
        return;
    }
    $done = true;
    db()->exec("
      CREATE TABLE IF NOT EXISTS mtc_catalog (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        pkey TEXT NOT NULL UNIQUE,
        data TEXT NOT NULL,
        active INTEGER NOT NULL DEFAULT 1,
        sort INTEGER NOT NULL DEFAULT 0,
        is_seed INTEGER NOT NULL DEFAULT 0,
        created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
        updated_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
      )
    ");
    db()->exec("
      CREATE TABLE IF NOT EXISTS mtc_indications (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        ikey TEXT NOT NULL UNIQUE,
        data TEXT NOT NULL,
        active INTEGER NOT NULL DEFAULT 1,
        sort INTEGER NOT NULL DEFAULT 0,
        is_seed INTEGER NOT NULL DEFAULT 0,
        created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
        updated_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
      )
    ");
    $sort = 0;
    $ins = db()->prepare('INSERT OR IGNORE INTO mtc_catalog (pkey, data, sort, is_seed) VALUES (?, ?, ?, 1)');
    foreach (mtc_seed_patterns() as $key => $p) {
        $ins->execute([$key, json_encode($p, JSON_UNESCAPED_UNICODE), $sort += 10]);
    }
    $sort = 0;
    $ins = db()->prepare('INSERT OR IGNORE INTO mtc_indications (ikey, data, sort, is_seed) VALUES (?, ?, ?, 1)');
    foreach (mtc_seed_indications() as $key => $i) {
        $ins->execute([$key, json_encode($i, JSON_UNESCAPED_UNICODE), $sort += 10]);
    }
}

/** Pisa $base con $over: las listas se reemplazan enteras; tuina, qigong y ventosas se combinan por clave. */
function mtc_pattern_merge(array $base, array $over): array
{
    foreach ($over as $k => $v) {
        $base[$k] = in_array($k, ['tuina', 'qigong', 'ventosas'], true) && is_array($v) && is_array($base[$k] ?? null) ? array_replace($base[$k], $v) : $v;
    }
    return $base;
}

/** Patrón con todas las claves (lo guardado puede venir incompleto o de una versión anterior). */
function mtc_pattern_normalize(array $p): array
{
    $base = mtc_seed_pattern((string) ($p['organ'] ?? 'General'), '', '', '', '', [], [], [], (string) ($p['moxa_mode'] ?? 'si'), [], [], []);
    $p = mtc_pattern_merge($base, $p);
    $p['moxa_mode'] = in_array($p['moxa_mode'], ['si', 'cauto', 'no'], true) ? $p['moxa_mode'] : 'si';
    foreach (['points', 'root', 'moxa', 'oreja', 'recs'] as $k) {
        $p[$k] = array_values(array_filter(is_array($p[$k]) ? $p[$k] : [], 'is_string'));
    }
    return $p;
}

function mtc_indication_normalize(array $i): array
{
    $i += ['category' => 'habitos', 'text' => '', 'patterns' => [], 'therapist' => false, 'caution' => ''];
    $i['category'] = isset(MTC_IND_CATEGORIES[$i['category']]) ? $i['category'] : 'habitos';
    $i['patterns'] = array_values(array_filter(is_array($i['patterns']) ? $i['patterns'] : [], 'is_string'));
    $i['therapist'] = (bool) $i['therapist'];
    return $i;
}

/** @return array<string, array> [clave => patrón] (+ '_active', '_seed', '_sort'), activos o todos, en el orden del catálogo. */
function mtc_catalog_patterns(bool $all = false): array
{
    static $cache = null;
    if ($cache === null) {
        $cache = [];
        if (!function_exists('db_ready') || !db_ready()) {
            foreach (mtc_seed_patterns() as $k => $p) {
                $cache[$k] = mtc_pattern_normalize($p) + ['_active' => true, '_seed' => true, '_sort' => 0];
            }
        } else {
            mtc_catalog_schema();
            foreach (db()->query('SELECT * FROM mtc_catalog ORDER BY sort, id')->fetchAll() as $r) {
                $cache[(string) $r['pkey']] = mtc_pattern_normalize(json_decode((string) $r['data'], true) ?: [])
                    + ['_active' => (bool) $r['active'], '_seed' => (bool) $r['is_seed'], '_sort' => (int) $r['sort']];
            }
        }
    }
    return $all ? $cache : array_filter($cache, static fn ($p) => $p['_active']);
}

/** @return array<string, array> [clave => indicación] (+ '_active', '_seed'). */
function mtc_catalog_indications(bool $all = false): array
{
    static $cache = null;
    if ($cache === null) {
        $cache = [];
        if (!function_exists('db_ready') || !db_ready()) {
            foreach (mtc_seed_indications() as $k => $i) {
                $cache[$k] = mtc_indication_normalize($i) + ['_active' => true, '_seed' => true];
            }
        } else {
            mtc_catalog_schema();
            foreach (db()->query('SELECT * FROM mtc_indications ORDER BY sort, id')->fetchAll() as $r) {
                $cache[(string) $r['ikey']] = mtc_indication_normalize(json_decode((string) $r['data'], true) ?: [])
                    + ['_active' => (bool) $r['active'], '_seed' => (bool) $r['is_seed']];
            }
        }
    }
    return $all ? $cache : array_filter($cache, static fn ($i) => $i['_active']);
}

/** La indicación aplica a alguno de los patrones (lista con «*» = todos, o prefijos como «higado*»). */
function mtc_indication_matches(array $ind, array $patternKeys): bool
{
    foreach ($ind['patterns'] as $rule) {
        if ($rule === '*') {
            return true;
        }
        foreach ($patternKeys as $k) {
            if ($rule === $k || (str_ends_with($rule, '*') && str_starts_with($k, substr($rule, 0, -1)))) {
                return true;
            }
        }
    }
    return false;
}

/**
 * Indicaciones para un diagnóstico: 'auto' = se proponen tildadas (coinciden con los patrones y no requieren al terapeuta);
 * 'offer' = el resto de las activas (incluye las que requieren indicación del terapeuta), para sumarlas a mano.
 */
function mtc_indications_for(array $patternKeys): array
{
    $auto = [];
    $offer = [];
    foreach (mtc_catalog_indications() as $k => $i) {
        if (!$i['therapist'] && mtc_indication_matches($i, $patternKeys)) {
            $auto[$k] = $i;
        } else {
            $offer[$k] = $i;
        }
    }
    return ['auto' => $auto, 'offer' => $offer];
}

function mtc_slug(string $text): string
{
    $s = mb_strtolower(trim($text));
    $s = strtr($s, ['á' => 'a', 'é' => 'e', 'í' => 'i', 'ó' => 'o', 'ú' => 'u', 'ü' => 'u', 'ñ' => 'n']);
    $s = trim(preg_replace('/[^a-z0-9]+/', '_', $s) ?? '', '_');
    return substr($s !== '' ? $s : 'item', 0, 48);
}

/** Lista de puntos escrita a mano («E36, Taichong (H3), rm12») → códigos en mayúscula. */
function mtc_parse_codes(string $text): array
{
    preg_match_all('/\b([A-Za-z]{1,4}\d{1,3}|YINTANG|XIYAN)\b/u', $text, $m);
    return array_values(array_unique(array_map('strtoupper', $m[1])));
}

function mtc_parse_list(string $text, string $sep = ','): array
{
    return array_values(array_filter(array_map('trim', explode($sep, str_replace("\r", '', $text))), static fn ($v) => $v !== ''));
}

/** Patrón desde el formulario del catálogo (todo filtrado). */
function mtc_pattern_from_post(array $post): array
{
    $t = static fn (string $k, int $max = 1200): string => mb_substr(trim(str_replace("\r", '', (string) (is_string($post[$k] ?? null) ? $post[$k] : ''))), 0, $max);
    $organ = in_array($post['organ'] ?? '', MTC_ORGANS, true) ? (string) $post['organ'] : 'General';
    $evidence = [];
    $ev = is_array($post['evidence'] ?? null) ? $post['evidence'] : [];
    $fields = MTC_OPTIONS + MTC_MULTI;
    foreach ($ev as $field => $vals) {
        if (isset($fields[$field]) && is_array($vals)) {
            $ok = array_values(array_intersect($fields[$field], array_filter($vals, 'is_string')));
            if ($ok) {
                $evidence[$field] = $ok;
            }
        }
    }
    // Vacíos = los toma del órgano (meridiano, maniobras, chi kung, ventosas, oreja).
    $filled = static fn (array $a): array => array_filter($a, static fn ($v) => $v !== '' && $v !== []);
    return mtc_pattern_normalize($filled([
        'label' => $t('label', 160),
        'organ' => $organ,
        'signs' => $t('signs', 300),
        'plain' => $t('plain', 800),
        'principle' => $t('principle', 300),
        'meridian' => $t('meridian', 160),
        'points' => mtc_parse_codes($t('points')),
        'root' => mtc_parse_codes($t('root')),
        'evidence' => $evidence,
        'moxa' => mtc_parse_codes($t('moxa')),
        'moxa_mode' => in_array($post['moxa_mode'] ?? '', ['si', 'cauto', 'no'], true) ? (string) $post['moxa_mode'] : 'si',
        'moxa_note' => $t('moxa_note', 400),
        'no_cups' => ($post['no_cups'] ?? '') === '1',
        'tuina' => $filled(['maniobras' => $t('tuina_maniobras', 800), 'acupresion' => mtc_parse_codes($t('tuina_acupresion'))]),
        'qigong' => $filled(['sesion' => $t('qigong_sesion', 400), 'casa' => $t('qigong_casa', 600)]) + ['min' => max(5, min(30, (int) ($post['qigong_min'] ?? 10)))],
        'ventosas' => $filled(['zona' => $t('ventosas_zona', 300), 'modo' => $t('ventosas_modo', 200)]),
        'oreja' => mtc_parse_list($t('oreja', 400)),
        'recs' => mtc_parse_list($t('recs', 1500), "\n"),
        'contra_notes' => $t('contra_notes', 600),
        'ashi' => ($post['ashi'] ?? '') === '1',
    ]) + ['contra_notes' => '', 'signs' => '', 'principle' => '', 'points' => [], 'root' => [], 'moxa' => [], 'recs' => [], 'evidence' => []]);
}

function mtc_indication_from_post(array $post): array
{
    $patterns = is_array($post['patterns'] ?? null) ? array_values(array_filter($post['patterns'], 'is_string')) : [];
    $valid = array_keys(mtc_catalog_patterns(true));
    $patterns = in_array('*', $patterns, true) ? ['*'] : array_values(array_intersect($patterns, $valid));
    return mtc_indication_normalize([
        'category' => (string) ($post['category'] ?? ''),
        'text' => mb_substr(trim(str_replace("\r", '', (string) ($post['text'] ?? ''))), 0, 900),
        'patterns' => $patterns,
        'therapist' => ($post['therapist'] ?? '') === '1',
        'caution' => mb_substr(trim((string) ($post['caution'] ?? '')), 0, 400),
    ]);
}

/** Guarda un patrón (nuevo si $key es null). Devuelve la clave. */
function mtc_catalog_save_pattern(array $p, ?string $key): string
{
    mtc_catalog_schema();
    $json = json_encode($p, JSON_UNESCAPED_UNICODE);
    $now = date('Y-m-d H:i:s');
    if ($key !== null) {
        db()->prepare('UPDATE mtc_catalog SET data = ?, updated_at = ? WHERE pkey = ?')->execute([$json, $now, $key]);
        return $key;
    }
    $key = mtc_slug($p['label']);
    $exists = db()->prepare('SELECT COUNT(*) FROM mtc_catalog WHERE pkey = ?');
    for ($n = 2, $base = $key; ; $n++) {
        $exists->execute([$key]);
        if ((int) $exists->fetchColumn() === 0) {
            break;
        }
        $key = $base . '_' . $n;
    }
    $sort = (int) db()->query('SELECT COALESCE(MAX(sort), 0) + 10 FROM mtc_catalog')->fetchColumn();
    db()->prepare('INSERT INTO mtc_catalog (pkey, data, sort, is_seed, created_at, updated_at) VALUES (?, ?, ?, 0, ?, ?)')->execute([$key, $json, $sort, $now, $now]);
    return $key;
}

function mtc_catalog_save_indication(array $i, ?string $key): string
{
    mtc_catalog_schema();
    $json = json_encode($i, JSON_UNESCAPED_UNICODE);
    $now = date('Y-m-d H:i:s');
    if ($key !== null) {
        db()->prepare('UPDATE mtc_indications SET data = ?, updated_at = ? WHERE ikey = ?')->execute([$json, $now, $key]);
        return $key;
    }
    $key = mtc_slug(mb_substr($i['text'], 0, 40));
    $exists = db()->prepare('SELECT COUNT(*) FROM mtc_indications WHERE ikey = ?');
    for ($n = 2, $base = $key; ; $n++) {
        $exists->execute([$key]);
        if ((int) $exists->fetchColumn() === 0) {
            break;
        }
        $key = $base . '_' . $n;
    }
    $sort = (int) db()->query('SELECT COALESCE(MAX(sort), 0) + 10 FROM mtc_indications')->fetchColumn();
    db()->prepare('INSERT INTO mtc_indications (ikey, data, sort, is_seed, created_at, updated_at) VALUES (?, ?, ?, 0, ?, ?)')->execute([$key, $json, $sort, $now, $now]);
    return $key;
}

/** $table = 'patterns' | 'indications'. */
function mtc_catalog_set_active(string $table, string $key, bool $active): void
{
    [$t, $col] = $table === 'patterns' ? ['mtc_catalog', 'pkey'] : ['mtc_indications', 'ikey'];
    db()->prepare("UPDATE {$t} SET active = ?, updated_at = ? WHERE {$col} = ?")->execute([$active ? 1 : 0, date('Y-m-d H:i:s'), $key]);
}

/** Vuelve un elemento de fábrica a su texto original. */
function mtc_catalog_reset(string $table, string $key): bool
{
    $seed = $table === 'patterns' ? (mtc_seed_patterns()[$key] ?? null) : (mtc_seed_indications()[$key] ?? null);
    if ($seed === null) {
        return false;
    }
    $table === 'patterns' ? mtc_catalog_save_pattern(mtc_pattern_normalize($seed), $key) : mtc_catalog_save_indication(mtc_indication_normalize($seed), $key);
    return true;
}
