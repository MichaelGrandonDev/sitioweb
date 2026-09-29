<?php

declare(strict_types=1);

/**
 * Plan de Medicina Tradicional China (MTC) por paciente.
 * Consulta 1: diagnóstico (el único de la etapa) y tonificación general. Consultas 2 a 5: primer bloque de tratamiento.
 * Desde la 6: controles semanales hasta completar la primera etapa de 25 consultas.
 * Requiere bootstrap.php (db, h, turno_*).
 */

require_once __DIR__ . '/mtc_catalog.php';
require_once __DIR__ . '/mtc_pdf.php';

const MTC_TOTAL = 25;
const MTC_BLOCK = 5;
const MTC_WEEK_DAYS = 7;
const MTC_TEXT_MAX = 4000;

const MTC_SESSION_TITLES = [
    1 => 'Diagnóstico y tonificación general',
    2 => 'Inicio del tratamiento',
    3 => 'Profundizar',
    4 => 'Consolidar',
    5 => 'Cierre del bloque y evaluación',
];

const MTC_POINTS = [
    'E36' => 'Zusanli', 'RM4' => 'Guanyuan', 'RM6' => 'Qihai', 'R3' => 'Taixi', 'PC6' => 'Neiguan',
    'DU20' => 'Baihui', 'YINTANG' => 'Yintang', 'B6' => 'Sanyinjiao', 'V20' => 'Pishu', 'RM12' => 'Zhongwan',
    'H3' => 'Taichong', 'IG4' => 'Hegu', 'VB34' => 'Yanglingquan', 'V23' => 'Shenshu', 'V17' => 'Geshu',
    'B10' => 'Xuehai', 'E40' => 'Fenglong', 'B9' => 'Yinlingquan', 'VB20' => 'Fengchi', 'VB21' => 'Jianjing',
    'ID3' => 'Houxi', 'SJ5' => 'Waiguan', 'V25' => 'Dachangshu', 'V40' => 'Weizhong', 'V60' => 'Kunlun',
    'IG15' => 'Jianyu', 'SJ14' => 'Jianliao', 'IG11' => 'Quchi', 'E35' => 'Dubi', 'XIYAN' => 'Xiyan',
    'V18' => 'Ganshu', 'V21' => 'Weishu', 'DU4' => 'Mingmen', 'R1' => 'Yongquan', 'V57' => 'Chengshan',
    'H2' => 'Xingjian', 'H8' => 'Ququan', 'H14' => 'Qimen', 'VB24' => 'Riyue', 'VB39' => 'Xuanzhong', 'VB40' => 'Qiuxu',
    'VB41' => 'Zulinqi', 'VB43' => 'Xiaxi', 'V13' => 'Feishu', 'V14' => 'Jueyinshu', 'V15' => 'Xinshu', 'V19' => 'Danshu',
    'V43' => 'Gaohuangshu', 'V52' => 'Zhishi', 'C6' => 'Yinxi', 'C7' => 'Shenmen', 'PC5' => 'Jianshi', 'PC7' => 'Daling',
    'PC8' => 'Laogong', 'RM8' => 'Shenque', 'RM9' => 'Shuifen', 'RM10' => 'Xiawan', 'RM17' => 'Shanzhong', 'P5' => 'Chize',
    'P7' => 'Lieque', 'P9' => 'Taiyuan', 'DU14' => 'Dazhui', 'R6' => 'Zhaohai', 'R7' => 'Fuliu', 'B1' => 'Yinbai',
    'B3' => 'Taibai', 'E21' => 'Liangmen', 'E25' => 'Tianshu', 'E44' => 'Neiting',
];

/** En embarazo no se usan (ni moxa ni acupresión): Sanyinjiao y Hegu, más los clásicamente desaconsejados y los del bajo abdomen. */
const MTC_PREGNANCY_AVOID = ['B6', 'IG4', 'VB21', 'V60', 'RM4', 'RM6'];

/** En embarazo tampoco se moxa el abdomen ni la zona lumbosacra. */
const MTC_PREGNANCY_NO_MOXA = ['B6', 'IG4', 'VB21', 'V60', 'RM4', 'RM6', 'RM12', 'V23', 'V25', 'DU4', 'RM8', 'RM9', 'RM10', 'E25', 'V52'];

const MTC_TONIFY = ['E36', 'RM4', 'RM6', 'R3', 'PC6', 'DU20'];

const MTC_CONTRA = [
    'embarazo' => 'Embarazo',
    'anticoagulantes' => 'Anticoagulantes',
    'marcapasos' => 'Marcapasos',
    'piel' => 'Piel lesionada en la zona',
    'calor' => 'Fiebre o calor agudo',
    'sensibilidad' => 'Diabetes / sensibilidad disminuida',
];

/**
 * Moxibustión por punto (el plan no indica agujas: los puntos se moxan o se presionan en el tuina).
 * Valor string = método de MTC_MOXA_METHODS. Valor array = [motivo de MTC_MOXA_SKIP, punto alternativo para moxar o null]:
 * ese punto va con acupresión dentro del tuina.
 */
const MTC_MOXA = [
    'E36' => 'jengibre', 'RM4' => 'caja', 'RM6' => 'caja', 'RM12' => 'caja', 'R3' => 'baston', 'PC6' => 'suave',
    'DU20' => 'cabeza', 'YINTANG' => ['cara', null], 'B6' => 'baston', 'V20' => 'caja', 'V21' => 'caja',
    'H3' => ['dispersa', 'VB34'], 'IG4' => ['dispersa', null], 'VB34' => 'picoteo', 'V23' => 'caja', 'V17' => 'baston',
    'B10' => 'baston', 'E40' => 'baston', 'B9' => 'baston', 'VB20' => ['nuca', null], 'VB21' => 'baston', 'ID3' => 'picoteo',
    'SJ5' => 'picoteo', 'V25' => 'caja', 'V40' => ['varices', 'V57'], 'V57' => 'baston', 'V60' => 'baston', 'IG15' => 'baston',
    'SJ14' => 'baston', 'IG11' => 'picoteo', 'E35' => 'baston', 'XIYAN' => 'baston', 'V18' => 'picoteo', 'DU4' => 'caja',
    'R1' => ['planta', null],
    'H2' => ['dispersa', null], 'E44' => ['dispersa', null], 'PC8' => ['dispersa', null], 'VB43' => ['dispersa', null],
    'P9' => ['arteria', 'P7'], 'RM8' => 'sal', 'B1' => 'grano', 'C7' => 'suave', 'C6' => 'suave', 'PC7' => 'suave',
    'PC5' => 'suave', 'P7' => 'picoteo', 'P5' => 'picoteo', 'H14' => 'picoteo', 'VB24' => 'picoteo', 'VB40' => 'picoteo',
    'VB41' => 'picoteo', 'R6' => 'suave', 'R7' => 'baston', 'B3' => 'baston', 'E21' => 'caja', 'E25' => 'caja',
    'RM9' => 'caja', 'RM10' => 'caja', 'V52' => 'caja',
];

const MTC_MOXA_METHODS = [
    'baston' => 'bastón indirecta a 2–3 cm, 10 a 15 min o hasta enrojecer suave',
    'picoteo' => 'bastón en picoteo (gorrión), 5 a 7 pasadas',
    'caja' => 'caja de moxa, 15 a 20 min',
    'jengibre' => 'conos sobre rodaja de jengibre, 3 a 5 conos (o bastón 10 a 15 min)',
    'suave' => 'bastón suave, 5 min (o solo acupresión)',
    'cabeza' => 'bastón a distancia, 5 min, cuidando el pelo (no si hay presión alta o calor en la cabeza)',
    'sal' => 'conos sobre sal en el ombligo, 3 a 5 conos (o caja de moxa 15 min)',
    'grano' => 'bastón en picoteo muy breve, 3 a 5 pasadas',
];

const MTC_MOXA_SKIP = [
    'cara' => 'cara: sin moxa',
    'dispersa' => 'punto de dispersión: la moxa suma calor',
    'nuca' => 'nuca y pelo: mejor presión',
    'varices' => 'hueco de la rodilla, zona de várices',
    'planta' => 'planta del pie: mejor presión',
    'arteria' => 'sobre la arteria radial: mejor presión suave',
];

/** Las cinco técnicas del plan (sin acupuntura), con la explicación simple que va al paciente. */
const MTC_TECHNIQUES = [
    'tuina' => [
        'label' => 'Tuina',
        'que' => 'Masaje terapéutico chino: empujes, amasamientos, presiones y rodamientos sobre los meridianos y sobre los mismos puntos del tratamiento (acupresión).',
        'sentir' => 'Presión firme pero tolerable; algunos puntos pueden estar sensibles. Después suele aparecer relajación, calor o liviandad.',
        'cuidados' => 'Tomá agua y evitá esfuerzos intensos ese día. Si algo duele de más durante la sesión, avisanos.',
    ],
    'qigong' => [
        'label' => 'Chi kung',
        'que' => 'Ejercicios suaves que combinan movimiento, respiración y atención. Los aprendés en la sesión y los practicás en casa.',
        'sentir' => 'Calor, hormigueo suave o calma. No tienen que doler ni cansarte.',
        'cuidados' => 'Practicá sin forzar, respirando por la nariz y sin retener el aire. Mejor poco todos los días que mucho de vez en cuando.',
    ],
    'moxa' => [
        'label' => 'Moxibustión',
        'que' => 'Calor de la planta artemisa (moxa) aplicado cerca de la piel sobre los puntos del tratamiento, en lugar de agujas.',
        'sentir' => 'Un calor agradable que penetra; la piel puede quedar un poco rosada. Nunca tiene que quemar.',
        'cuidados' => 'Avisá enseguida si sentís que quema. No expongas la zona al frío ni al agua fría ese día. Si apareciera una ampollita, no la revientes y avisanos. Puede quedar olor a humo en la ropa.',
    ],
    'ventosas' => [
        'label' => 'Ventosas',
        'que' => 'Copas que hacen una succión suave sobre la piel para mover la circulación y soltar tensión muscular.',
        'sentir' => 'Un tirón o estiramiento de la piel, sin dolor. Pueden quedar marcas redondas rojizas o violáceas que se van en 3 a 7 días: no son golpes.',
        'cuidados' => 'Abrigá la zona, evitá corrientes de aire y baños muy calientes o fríos ese día, y tomá agua.',
    ],
    'auriculo' => [
        'label' => 'Auriculoterapia',
        'que' => 'Estimulamos puntos de la oreja con pequeñas semillas (vaccaria) o balines pegados con cinta. Sin agujas.',
        'sentir' => 'Al presionar, un dolorcito leve o calor en la oreja: es normal.',
        'cuidados' => 'Presioná cada semilla como te indicamos. Te podés bañar con ellas. Si pican, irritan o se despegan, sacalas. Las cambiamos cada semana y alternamos de oreja.',
    ],
];

const MTC_OPTIONS = [
    'sueno' => ['Bueno', 'Le cuesta dormirse', 'Se despierta seguido', 'Sueño liviano / muchos sueños', 'Somnolencia de día'],
    'digestion' => ['Normal', 'Lenta / pesadez', 'Distensión / gases', 'Acidez / reflujo', 'Heces blandas', 'Constipación'],
    'sed' => ['Normal', 'Mucha sed (bebidas frías)', 'Poca sed', 'Boca seca sin sed'],
    'frio_calor' => ['Equilibrado', 'Friolento / manos y pies fríos', 'Caluroso', 'Calores / sudor nocturno', 'Alterna'],
    'animo' => ['Estable', 'Estrés / irritabilidad', 'Ansiedad / preocupación', 'Tristeza / desgano', 'Cambios de humor'],
    'ciclo' => ['No corresponde', 'Regular', 'Irregular', 'Dolor menstrual', 'Abundante', 'Escaso', 'Menopausia'],
    'sudor' => ['Normal', 'Sudor espontáneo de día', 'Sudor nocturno', 'Casi no transpira'],
    'dolor' => ['Sin dolor', 'Cabeza', 'Fijo / punzante', 'Se mueve de lugar', 'Pesadez en el cuerpo', 'Lumbar / rodillas débiles'],
    'orina' => ['Normal', 'Clara y abundante', 'Oscura / escasa', 'Se levanta a orinar de noche'],
    'apetito' => ['Normal', 'Poco apetito', 'Mucho apetito', 'Sabor amargo', 'Boca pastosa / dulce'],
    'torax' => ['Sin molestias', 'Opresión en el pecho', 'Suspira seguido', 'Tensión en los costados', 'Palpitaciones'],
    'sentidos' => ['Sin molestias', 'Zumbidos', 'Ojos secos / vista cansada', 'Mareos', 'Ojos rojos'],
    'lengua_color' => ['Rosada (normal)', 'Pálida', 'Roja', 'Roja en la punta', 'Bordes rojos', 'Violácea'],
    'lengua_tamano' => ['Normal', 'Grande / ancha', 'Pequeña / delgada'],
    'lengua_humedad' => ['Normal', 'Seca', 'Muy húmeda'],
    'lengua_movilidad' => ['Normal', 'Temblorosa', 'Desviada', 'Rígida', 'Flácida'],
    'lengua_venas' => ['Normales', 'Dilatadas / oscuras', 'Pálidas / poco visibles'],
    'saburra_color' => ['Blanca', 'Amarilla', 'Gris / oscura', 'Sin saburra (pelada)'],
    'saburra_espesor' => ['Fina', 'Gruesa'],
    'saburra_distribucion' => ['Pareja', 'Más en la raíz', 'Más en el centro', 'A parches (geográfica)'],
    'saburra_raiz' => ['Con raíz (firme)', 'Sin raíz (se desprende)'],
    'saburra_humedad' => ['Normal', 'Seca', 'Húmeda', 'Pegajosa / grasosa'],
];

const MTC_MULTI = [
    'lengua_forma' => ['Hinchada', 'Fina', 'Marcas dentales', 'Grietas', 'Puntos rojos / petequias', 'Manchas violáceas'],
    'zonas' => ['Punta (Corazón / Pulmón)', 'Centro (Bazo / Estómago)', 'Laterales (Hígado / Vesícula)', 'Raíz (Riñón)'],
];

/** Interrogatorio de los 10 puntos (más ánimo y ciclo), en el orden del formulario. */
const MTC_INTERVIEW = [
    'frio_calor' => '1. Frío / calor', 'sudor' => '2. Sudoración', 'dolor' => '3. Cabeza y cuerpo (dolor)', 'orina' => '4. Orina',
    'digestion' => '5. Digestión y heces', 'apetito' => '6. Apetito y sabor', 'torax' => '7. Tórax y costados',
    'sentidos' => '8. Oídos y ojos', 'sed' => '9. Sed', 'sueno' => '10. Sueño', 'animo' => 'Ánimo', 'ciclo' => 'Ciclo menstrual',
];

/** Cómo se nombra cada dato de la lengua al explicar un patrón («color pálida», «saburra gruesa»). */
const MTC_TONGUE_FIELDS = [
    'lengua_color' => 'color', 'lengua_forma' => '', 'lengua_tamano' => 'tamaño', 'lengua_humedad' => 'humedad',
    'lengua_movilidad' => 'movilidad', 'lengua_venas' => 'venas sublinguales', 'saburra_color' => 'saburra',
    'saburra_espesor' => 'saburra', 'saburra_distribucion' => 'saburra', 'saburra_raiz' => 'saburra', 'saburra_humedad' => 'saburra',
    'zonas' => 'zona',
];

/** Señales de calor (lengua e interrogatorio): con dos o más, la moxa queda breve. */
const MTC_HEAT_SIGNS = [
    'lengua_color' => ['Roja', 'Roja en la punta'], 'saburra_color' => ['Amarilla', 'Sin saburra (pelada)'],
    'lengua_humedad' => ['Seca'], 'saburra_humedad' => ['Seca'], 'lengua_forma' => ['Puntos rojos / petequias'],
    'sed' => ['Mucha sed (bebidas frías)'], 'frio_calor' => ['Caluroso', 'Calores / sudor nocturno'],
    'sudor' => ['Sudor nocturno'], 'orina' => ['Oscura / escasa'], 'sentidos' => ['Ojos rojos'],
];

/** diagnostico = línea «Diagnóstico desde la MTC» (editable); diagnostico_auto = la última compuesta sola (para saber si se puede rehacer). */
const MTC_DIAG_TEXT = ['motivo', 'antecedentes', 'medicacion', 'interrog_notas', 'lengua_notas', 'pulso', 'fuentes', 'patron_otro', 'sintoma', 'tonificacion', 'notas', 'diagnostico', 'diagnostico_auto'];

const MTC_AFTER_BLOCK = 'En la consulta 5 decidimos juntos cómo seguir: controles semanales hasta completar 25 consultas, el alta, o una derivación médica si hiciera falta.';

const MTC_DECISIONS = [
    '' => 'A definir en la consulta 5',
    'continuar' => 'Continuar con controles semanales hasta completar 25 consultas',
    'alta' => 'Alta',
    'derivacion' => 'Derivación médica',
];

/**
 * Patrones activos del catálogo «Diagnósticos y tratamientos» (editable en mtc_catalogo.php; ver includes/mtc_catalog.php).
 * Mismas claves y forma que siempre (bazo, higado, rinon, sangre, humedad, musculo + el resto del zang-fu).
 */
function mtc_patterns(): array
{
    return mtc_catalog_patterns();
}

/**
 * Plantillas de fábrica de los 6 primeros patrones. Claves (las usa también el módulo Pacientes; solo se agregan claves nuevas):
 * - label, signs, plain (texto para el paciente), meridian, points (puntos de referencia), root (raíz), recs (para casa), ashi.
 * - evidence: [campo de diag => valores] que apoyan el patrón (lengua primero; el pulso no se usa).
 * - moxa: puntos para moxar en orden de prioridad (ver MTC_MOXA; los que no admiten moxa pasan a acupresión).
 * - tuina: ['maniobras' => texto, 'acupresion' => puntos]; qigong: ['sesion', 'casa', 'min' (minutos por día)];
 *   ventosas: ['zona', 'modo']; oreja: puntos auriculares (semillas, sin agujas).
 * - techs: legado (ya no se usa para generar).
 * El resto (organ, principle, moxa_mode, no_cups, contra_notes) está en mtc_catalog.php.
 */
function mtc_seed_core(): array
{
    return [
        'bazo' => [
            'label' => 'Vacío de Qi de Bazo',
            'signs' => 'cansancio, digestión lenta, lengua pálida e hinchada',
            'plain' => 'Energía digestiva baja (en MTC, «vacío de Qi de Bazo»): se relaciona con el cansancio, la digestión lenta y la pesadez después de comer.',
            'meridian' => 'Bazo y Estómago',
            'points' => ['E36', 'B6', 'V20', 'RM12'],
            'root' => ['E36', 'V20'],
            'techs' => ['moxa'],
            'evidence' => [
                'lengua_color' => ['Pálida'], 'lengua_tamano' => ['Grande / ancha'], 'lengua_forma' => ['Hinchada', 'Marcas dentales'],
                'lengua_humedad' => ['Muy húmeda'], 'zonas' => ['Centro (Bazo / Estómago)'], 'saburra_distribucion' => ['Más en el centro'],
                'digestion' => ['Lenta / pesadez', 'Heces blandas', 'Distensión / gases'], 'apetito' => ['Poco apetito'],
                'sudor' => ['Sudor espontáneo de día'], 'sueno' => ['Somnolencia de día'], 'frio_calor' => ['Friolento / manos y pies fríos'],
            ],
            'moxa' => ['E36', 'RM12', 'V20', 'RM6'],
            'tuina' => [
                'maniobras' => 'mo (frotación circular) en el abdomen en sentido horario, rou (amasado) y an (presión) sobre Pishu (V20) y Weishu (V21), tui (empuje) por el meridiano de Estómago en la pierna',
                'acupresion' => ['E36', 'B6', 'PC6'],
            ],
            'qigong' => [
                'sesion' => 'respiración abdominal y la 3.ª pieza del Ba Duan Jin («Separar cielo y tierra», regula Bazo y Estómago)',
                'casa' => 'respiración abdominal 5 minutos, 8 repeticiones de «Separar cielo y tierra» y el sonido «Hu» del Liu Zi Jue (6 veces) después de comer',
                'min' => 10,
            ],
            'ventosas' => ['zona' => 'Shu dorsales de Bazo y Estómago (V20, V21)', 'modo' => 'fijas suaves, 5 minutos'],
            'oreja' => ['Shenmen', 'Bazo', 'Estómago', 'Simpático'],
            'recs' => [
                'Comé tibio y cocido (sopas, guisos, arroz, calabaza, zanahoria). Limitá los crudos, las bebidas frías y el exceso de lácteos.',
                'Tratá de comer en horarios regulares, masticando tranquilo y sin pantallas.',
                'Caminatas suaves: moverte ayuda, agotarte no.',
            ],
        ],
        'higado' => [
            'label' => 'Estancamiento de Qi de Hígado',
            'signs' => 'estrés, irritabilidad, bordes de la lengua rojos',
            'plain' => 'Tensión acumulada (en MTC, «estancamiento de Qi de Hígado»): suele acompañar al estrés, la irritabilidad y las contracturas.',
            'meridian' => 'Hígado y Vesícula Biliar',
            'points' => ['H3', 'IG4', 'VB34', 'PC6'],
            'root' => ['H3', 'PC6'],
            'techs' => [],
            'evidence' => [
                'lengua_color' => ['Bordes rojos'], 'zonas' => ['Laterales (Hígado / Vesícula)'], 'lengua_movilidad' => ['Desviada', 'Rígida'],
                'animo' => ['Estrés / irritabilidad', 'Cambios de humor'], 'torax' => ['Suspira seguido', 'Tensión en los costados'],
                'ciclo' => ['Dolor menstrual', 'Irregular'], 'dolor' => ['Se mueve de lugar', 'Cabeza'], 'apetito' => ['Sabor amargo'],
                'sentidos' => ['Ojos rojos'], 'digestion' => ['Distensión / gases'],
            ],
            'moxa' => ['VB34', 'PC6', 'H3'],
            'moxa_note' => 'Patrón que tiende al calor: moxa breve y en picoteo; si aparece calor, pasar a acupresión.',
            'tuina' => [
                'maniobras' => 'na (pinzado) y gun (rodamiento) en trapecios y hombros, tui (empuje) por los costados (Vesícula Biliar) y por la cara interna de la pierna (Hígado), rou (amasado) sobre Ganshu (V18)',
                'acupresion' => ['H3', 'IG4', 'PC6', 'VB34'],
            ],
            'qigong' => [
                'sesion' => 'respiración abdominal con exhalación larga y la 7.ª pieza del Ba Duan Jin («Cerrar los puños con mirada firme»)',
                'casa' => 'respiración con exhalación larga 5 minutos (2 o 3 veces al día), el sonido «Xu» del Liu Zi Jue (6 veces) y 8 repeticiones de «Cerrar los puños con mirada firme»',
                'min' => 10,
            ],
            'ventosas' => ['zona' => 'trapecios y zona dorsal media (Ganshu V18)', 'modo' => 'deslizantes con aceite, 5 a 8 minutos'],
            'oreja' => ['Shenmen', 'Hígado', 'Punto Cero', 'Subcórtex'],
            'recs' => [
                'Hacé pausas durante el día con respiración lenta (5 minutos, unas 3 veces).',
                'Actividad física moderada: caminar, bici, chi kung o taichi.',
                'Reducí alcohol, café y comidas muy grasas o fritas.',
                'Tratá de acostarte antes de las 23 hs.',
            ],
        ],
        'rinon' => [
            'label' => 'Vacío de Riñón',
            'signs' => 'lumbalgia, cansancio, raíz de la lengua alterada',
            'plain' => 'Reservas de energía bajas (en MTC, «vacío de Riñón»): se relaciona con el cansancio, el dolor lumbar y la sensación de frío.',
            'meridian' => 'Riñón y Vejiga',
            'points' => ['R3', 'V23', 'RM4'],
            'root' => ['R3', 'V23'],
            'techs' => ['moxa'],
            'evidence' => [
                'zonas' => ['Raíz (Riñón)'], 'saburra_color' => ['Sin saburra (pelada)'], 'saburra_raiz' => ['Sin raíz (se desprende)'],
                'lengua_forma' => ['Grietas'], 'saburra_distribucion' => ['A parches (geográfica)'],
                'dolor' => ['Lumbar / rodillas débiles'], 'orina' => ['Clara y abundante', 'Se levanta a orinar de noche'],
                'frio_calor' => ['Friolento / manos y pies fríos', 'Calores / sudor nocturno'], 'sudor' => ['Sudor nocturno'],
                'sentidos' => ['Zumbidos'], 'sueno' => ['Se despierta seguido'],
            ],
            'moxa' => ['V23', 'DU4', 'RM4', 'R3'],
            'tuina' => [
                'maniobras' => 'ca (fricción) transversal en la zona lumbar sobre Shenshu (V23) y Mingmen (DU4) hasta sentir calor, an (presión) y rou (amasado) en la planta del pie y alrededor del tobillo interno',
                'acupresion' => ['R3', 'R1', 'E36'],
            ],
            'qigong' => [
                'sesion' => 'frotar los riñones con las palmas y la 6.ª pieza del Ba Duan Jin («Tocar los pies para fortalecer los riñones»), adaptada',
                'casa' => 'frotar la zona lumbar con las palmas 1 minuto, 6 repeticiones de «Tocar los pies» sin forzar y el sonido «Chui» del Liu Zi Jue (6 veces), a la mañana',
                'min' => 10,
            ],
            'ventosas' => ['zona' => 'zona lumbar (Shenshu V23) y glúteos', 'modo' => 'fijas suaves, 5 minutos, después de la moxa'],
            'oreja' => ['Shenmen', 'Riñón', 'Endocrino', 'Zona lumbar'],
            'recs' => [
                'Priorizá el descanso y evitá trasnochar.',
                'Mantené abrigados la zona lumbar y los pies.',
                'Comidas calientes: legumbres, sopas, semillas de sésamo y nueces.',
                'Movimiento suave: chi kung y estiramientos.',
            ],
        ],
        'sangre' => [
            'label' => 'Estasis de Sangre',
            'signs' => 'dolor fijo y punzante, lengua violácea',
            'plain' => 'Circulación enlentecida en la zona (en MTC, «estasis de Sangre»): se relaciona con un dolor fijo o punzante.',
            'meridian' => 'Bazo e Hígado (circulación de la Sangre)',
            'points' => ['V17', 'B10', 'B6'],
            'root' => ['V17', 'B10'],
            'techs' => ['ventosas'],
            'ashi' => true,
            'evidence' => [
                'lengua_color' => ['Violácea'], 'lengua_forma' => ['Manchas violáceas'], 'lengua_venas' => ['Dilatadas / oscuras'],
                'dolor' => ['Fijo / punzante'], 'ciclo' => ['Dolor menstrual'], 'torax' => ['Opresión en el pecho'],
            ],
            'moxa' => ['V17', 'B10', 'B6'],
            'tuina' => [
                'maniobras' => 'rou (amasado) y an (presión) alrededor de la zona dolorosa, tui (empuje) en el sentido del meridiano para mover la circulación, gun (rodamiento) en los músculos grandes',
                'acupresion' => ['V17', 'B10', 'B6'],
            ],
            'qigong' => [
                'sesion' => 'respiración abdominal y la 8.ª pieza del Ba Duan Jin («Elevar los talones y sacudir el cuerpo»)',
                'casa' => '8 elevaciones de talones con sacudida suave, el sonido «He» del Liu Zi Jue (6 veces) y una caminata de 20 minutos',
                'min' => 10,
            ],
            'ventosas' => ['zona' => 'zona del dolor y Geshu (V17)', 'modo' => 'deslizantes, 5 a 10 minutos'],
            'oreja' => ['Shenmen', 'Corazón', 'Hígado', 'Simpático'],
            'recs' => [
                'Movimiento diario y suave para activar la circulación.',
                'Calor local en la zona de dolor si te alivia (no si está hinchada o caliente).',
                'Evitá quedarte mucho tiempo en la misma postura.',
            ],
        ],
        'humedad' => [
            'label' => 'Humedad / Flema',
            'signs' => 'pesadez, saburra gruesa y pegajosa',
            'plain' => 'Acumulación de humedad (en MTC, «humedad / flema»): se relaciona con la pesadez, la hinchazón y el cansancio.',
            'meridian' => 'Bazo y Estómago',
            'points' => ['E40', 'B9', 'RM12'],
            'root' => ['E40', 'RM12'],
            'techs' => ['ventosas', 'moxa'],
            'evidence' => [
                'saburra_espesor' => ['Gruesa'], 'saburra_humedad' => ['Pegajosa / grasosa', 'Húmeda'], 'lengua_forma' => ['Hinchada'],
                'lengua_humedad' => ['Muy húmeda'], 'saburra_distribucion' => ['Más en la raíz'],
                'dolor' => ['Pesadez en el cuerpo'], 'apetito' => ['Boca pastosa / dulce'], 'sentidos' => ['Mareos'], 'sed' => ['Poca sed'],
                'digestion' => ['Lenta / pesadez', 'Heces blandas'], 'torax' => ['Opresión en el pecho'],
            ],
            'moxa' => ['RM12', 'E40', 'B9', 'E36'],
            'tuina' => [
                'maniobras' => 'mo (frotación circular) en el abdomen, tui (empuje) y na (pinzado) en brazos y piernas para drenar, rou (amasado) sobre Fenglong (E40) y Yinlingquan (B9)',
                'acupresion' => ['E40', 'B9', 'E36'],
            ],
            'qigong' => [
                'sesion' => 'caminata de chi kung con respiración nasal y la 3.ª pieza del Ba Duan Jin («Separar cielo y tierra»)',
                'casa' => 'caminata de chi kung 15 minutos, 8 repeticiones de «Separar cielo y tierra» y el sonido «Hu» del Liu Zi Jue (6 veces)',
                'min' => 15,
            ],
            'ventosas' => ['zona' => 'espalda (Shu dorsales V20, V21) y cara externa de los muslos', 'modo' => 'deslizantes, 5 a 10 minutos'],
            'oreja' => ['Shenmen', 'Bazo', 'Estómago', 'Endocrino'],
            'recs' => [
                'Reducí harinas refinadas, azúcar, lácteos, fritos y alcohol.',
                'Preferí verduras cocidas, legumbres, arroz y un poco de jengibre.',
                'Actividad física regular para «mover» la pesadez.',
            ],
        ],
        'musculo' => [
            'label' => 'Dolor musculoesquelético',
            'signs' => 'cervical, lumbar o articular',
            'plain' => 'Dolor musculoesquelético: trabajamos la zona y los canales de energía (meridianos) que la recorren.',
            'meridian' => '',
            'points' => [],
            'root' => [],
            'techs' => ['ventosas', 'moxa'],
            'ashi' => true,
            'evidence' => ['dolor' => ['Fijo / punzante', 'Lumbar / rodillas débiles', 'Cabeza']],
            'moxa' => [],
            'tuina' => [
                'maniobras' => 'gun (rodamiento) y rou (amasado) en la musculatura de la zona, na (pinzado) en las zonas tensas, an (presión) en los puntos Ashi y movilización pasiva suave',
                'acupresion' => [],
            ],
            'qigong' => [
                'sesion' => 'respiración abdominal y movilidad suave de la zona',
                'casa' => 'movilidad suave de la zona 2 veces al día, sin dolor',
                'min' => 10,
            ],
            'ventosas' => ['zona' => 'la zona del dolor', 'modo' => 'fijas o deslizantes, 5 a 10 minutos'],
            'oreja' => ['Shenmen', 'Subcórtex'],
            'recs' => [
                'Movilidad suave de la zona todos los días, sin forzar el dolor.',
                'Calor local 15 a 20 minutos si te alivia.',
                'Cuidá la postura y hacé pausas activas.',
            ],
        ],
    ];
}

/** Zonas de dolor: puntos locales/distales y lo propio de cada zona para chi kung, ventosas y oreja. */
function mtc_pain_zones(): array
{
    return [
        'cervical' => [
            'label' => 'Cervical / hombros altos', 'local' => ['VB20', 'VB21'], 'distal' => ['ID3', 'SJ5'], 'meridian' => 'Vesícula Biliar, Intestino Delgado y Triple Calentador',
            'ear' => 'Cervicales', 'cups' => 'trapecios y zona cervicodorsal',
            'qigong' => ['sesion' => 'la 5.ª pieza del Ba Duan Jin («Mirar hacia atrás») y rotaciones suaves de hombros', 'casa' => '8 repeticiones de «Mirar hacia atrás» y rotaciones de hombros, 2 veces al día'],
        ],
        'lumbar' => [
            'label' => 'Lumbar', 'local' => ['V23', 'V25'], 'distal' => ['V40', 'V60'], 'meridian' => 'Vejiga y Riñón',
            'ear' => 'Zona lumbar', 'cups' => 'zona lumbar (Shenshu V23, Dachangshu V25)',
            'qigong' => ['sesion' => 'báscula de pelvis y la 6.ª pieza del Ba Duan Jin («Tocar los pies»), adaptada', 'casa' => '10 básculas de pelvis acostado y 6 repeticiones de «Tocar los pies» sin forzar, a la mañana'],
        ],
        'hombro' => [
            'label' => 'Hombro', 'local' => ['IG15', 'SJ14'], 'distal' => ['IG11', 'SJ5'], 'meridian' => 'Intestino Grueso y Triple Calentador',
            'ear' => 'Hombro', 'cups' => 'hombro y escápula',
            'qigong' => ['sesion' => 'la 1.ª pieza del Ba Duan Jin («Sostener el cielo con las manos») hasta donde no duela', 'casa' => '8 repeticiones de «Sostener el cielo» y péndulo del brazo 1 minuto, 2 veces al día'],
        ],
        'rodilla' => [
            'label' => 'Rodilla', 'local' => ['XIYAN', 'E35'], 'distal' => ['VB34', 'E36'], 'meridian' => 'Estómago, Bazo y Vesícula Biliar',
            'ear' => 'Rodilla', 'cups' => 'muslo por encima de la rodilla (nunca sobre la rótula)',
            'qigong' => ['sesion' => 'postura de pie con rodillas flojas y círculos suaves de rodilla', 'casa' => '2 minutos de pie con rodillas flojas y 10 círculos de rodilla por lado'],
        ],
        'otra' => [
            'label' => 'Otra zona', 'local' => [], 'distal' => [], 'meridian' => '',
            'ear' => 'zona de la oreja que corresponde al dolor', 'cups' => 'la zona del dolor', 'qigong' => null,
        ],
    ];
}

function mtc_is_therapy(string $name): bool
{
    return (bool) preg_match('/china|mtc|acupunt|moxib/i', $name);
}

function mtc_point_label(string $code): string
{
    $name = MTC_POINTS[$code] ?? $code;
    return in_array($code, ['YINTANG', 'XIYAN'], true) ? $name : $name . ' (' . $code . ')';
}

function mtc_points_text(array $codes): string
{
    return implode(', ', array_map('mtc_point_label', array_values(array_unique($codes))));
}

function mtc_schema(): void
{
    static $done = false;
    if ($done) {
        return;
    }
    $done = true;
    db()->exec("
      CREATE TABLE IF NOT EXISTS mtc_plans (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        appointment_id INTEGER NOT NULL UNIQUE,
        status TEXT NOT NULL DEFAULT 'borrador',
        data TEXT NOT NULL DEFAULT '{}',
        sent_at TEXT DEFAULT NULL,
        created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
        updated_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
      )
    ");
    db()->exec('
      CREATE TABLE IF NOT EXISTS mtc_plan_visits (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        plan_id INTEGER NOT NULL,
        appointment_id INTEGER NOT NULL UNIQUE,
        consult_no INTEGER NOT NULL,
        created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
      )
    ');
    db()->exec("
      CREATE TABLE IF NOT EXISTS mtc_plan_versions (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        plan_id INTEGER NOT NULL,
        data TEXT NOT NULL,
        reason TEXT NOT NULL DEFAULT '',
        created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
      )
    ");
    db()->exec('CREATE INDEX IF NOT EXISTS idx_mtc_plan_visits_plan ON mtc_plan_visits(plan_id)');
    db()->exec('CREATE INDEX IF NOT EXISTS idx_mtc_plan_versions_plan ON mtc_plan_versions(plan_id, id)');
    db()->exec('CREATE INDEX IF NOT EXISTS idx_mtc_plans_updated ON mtc_plans(updated_at)');
}

/** Versión de las plantillas: 2 = cinco técnicas (tuina, chi kung, moxa, ventosas, auriculoterapia), sin agujas. */
const MTC_TEMPLATE = 2;

/**
 * Estructura vacía del plan. Las claves fijan qué se guarda.
 * patient.casa = práctica en casa (chi kung y semillas en la oreja); patient.indicaciones = lista «- ...» de la hoja INDICACIONES;
 * template = versión con la que se generó (0 = plan viejo, con agujas).
 */
function mtc_blank(): array
{
    $diag = array_fill_keys(MTC_DIAG_TEXT, '') + array_fill_keys(array_keys(MTC_OPTIONS), '')
        + ['contra' => [], 'lengua_forma' => [], 'zonas' => [], 'patrones' => [], 'zona_dolor' => '', 'escala' => null];
    $sessions = [];
    for ($n = 2; $n <= MTC_BLOCK; $n++) {
        $sessions[$n] = ['objetivo' => '', 'puntos' => '', 'tecnica' => '', 'fecha' => '', 'escala' => null, 'control' => '', 'realizada' => false];
    }
    $controls = [];
    for ($n = MTC_BLOCK + 1; $n <= MTC_TOTAL; $n++) {
        $controls[$n] = ['fecha' => '', 'escala' => null, 'cambios' => '', 'puntos' => '', 'realizada' => false];
    }
    return [
        'diag' => $diag,
        'sessions' => $sessions,
        'controls' => $controls,
        'patient' => ['resumen' => '', 'recomendaciones' => '', 'casa' => '', 'indicaciones' => '', 'include_points' => false, 'decision' => ''],
        'generated' => false,
        'template' => 0,
    ];
}

function mtc_clean_text(mixed $v, int $max = MTC_TEXT_MAX): string
{
    if (!is_string($v)) {
        return '';
    }
    $v = str_replace(["\r\n", "\r"], "\n", $v);
    $v = preg_replace('/[\x00-\x08\x0B-\x1F\x7F]/u', '', $v) ?? '';
    return mb_substr(trim($v), 0, $max);
}

function mtc_clean_scale(mixed $v): ?int
{
    if (!is_string($v) || trim($v) === '' || !ctype_digit(trim($v))) {
        return null;
    }
    return max(0, min(10, (int) $v));
}

function mtc_clean_date(mixed $v): string
{
    if (!is_string($v)) {
        return '';
    }
    $d = DateTimeImmutable::createFromFormat('!Y-m-d', trim($v));
    return $d && $d->format('Y-m-d') === trim($v) ? trim($v) : '';
}

/** Datos del formulario, siempre filtrados contra la estructura de mtc_blank(). */
function mtc_from_post(array $post, array $prev): array
{
    $plan = mtc_blank();
    $d = is_array($post['diag'] ?? null) ? $post['diag'] : [];
    foreach (MTC_DIAG_TEXT as $k) {
        $plan['diag'][$k] = mtc_clean_text($d[$k] ?? '');
    }
    foreach (MTC_OPTIONS as $k => $opts) {
        $v = is_string($d[$k] ?? null) ? $d[$k] : '';
        $plan['diag'][$k] = in_array($v, $opts, true) ? $v : '';
    }
    $multi = MTC_MULTI + ['contra' => array_keys(MTC_CONTRA), 'patrones' => array_keys(mtc_catalog_patterns(true))];
    foreach ($multi as $k => $opts) {
        $vals = is_array($d[$k] ?? null) ? $d[$k] : [];
        $plan['diag'][$k] = array_values(array_intersect($opts, array_filter($vals, 'is_string')));
    }
    $zone = is_string($d['zona_dolor'] ?? null) ? $d['zona_dolor'] : '';
    $plan['diag']['zona_dolor'] = isset(mtc_pain_zones()[$zone]) ? $zone : '';
    $plan['diag']['escala'] = mtc_clean_scale($d['escala'] ?? null);

    $s = is_array($post['s'] ?? null) ? $post['s'] : [];
    foreach ($plan['sessions'] as $n => $blank) {
        $row = is_array($s[$n] ?? null) ? $s[$n] : [];
        $plan['sessions'][$n] = [
            'objetivo' => mtc_clean_text($row['objetivo'] ?? ''),
            'puntos' => mtc_clean_text($row['puntos'] ?? ''),
            'tecnica' => mtc_clean_text($row['tecnica'] ?? ''),
            'fecha' => mtc_clean_date($row['fecha'] ?? ''),
            'escala' => mtc_clean_scale($row['escala'] ?? null),
            'control' => mtc_clean_text($row['control'] ?? ''),
            'realizada' => ($row['realizada'] ?? '') === '1',
        ];
    }
    $c = is_array($post['c'] ?? null) ? $post['c'] : [];
    foreach ($plan['controls'] as $n => $blank) {
        $row = is_array($c[$n] ?? null) ? $c[$n] : [];
        $plan['controls'][$n] = [
            'fecha' => mtc_clean_date($row['fecha'] ?? ''),
            'escala' => mtc_clean_scale($row['escala'] ?? null),
            'cambios' => mtc_clean_text($row['cambios'] ?? '', 1500),
            'puntos' => mtc_clean_text($row['puntos'] ?? '', 1500),
            'realizada' => ($row['realizada'] ?? '') === '1',
        ];
    }
    $p = is_array($post['p'] ?? null) ? $post['p'] : [];
    $decision = is_string($p['decision'] ?? null) ? $p['decision'] : '';
    $plan['patient'] = [
        'resumen' => mtc_clean_text($p['resumen'] ?? ''),
        'recomendaciones' => mtc_clean_text($p['recomendaciones'] ?? ''),
        'casa' => mtc_clean_text($p['casa'] ?? ''),
        'indicaciones' => mtc_clean_text($p['indicaciones'] ?? '', 8000),
        'include_points' => ($p['include_points'] ?? '') === '1',
        'decision' => array_key_exists($decision, MTC_DECISIONS) ? $decision : '',
    ];
    $plan['generated'] = (bool) ($prev['generated'] ?? false);
    $plan['template'] = (int) ($prev['template'] ?? 0);
    return $plan;
}

/** Mezcla lo guardado con la estructura actual (planes viejos o JSON incompleto). */
function mtc_normalize(array $saved): array
{
    $plan = mtc_blank();
    foreach (['diag', 'patient'] as $k) {
        if (is_array($saved[$k] ?? null)) {
            $plan[$k] = array_intersect_key($saved[$k], $plan[$k]) + $plan[$k];
        }
    }
    foreach (['sessions', 'controls'] as $k) {
        foreach ($plan[$k] as $n => $blank) {
            $row = $saved[$k][$n] ?? $saved[$k][(string) $n] ?? null;
            if (is_array($row)) {
                $plan[$k][$n] = array_intersect_key($row, $blank) + $blank;
            }
        }
    }
    $plan['generated'] = (bool) ($saved['generated'] ?? false);
    $plan['template'] = (int) ($saved['template'] ?? 0);
    $forma = is_array($plan['diag']['lengua_forma']) ? $plan['diag']['lengua_forma'] : [];
    foreach (['Temblorosa', 'Desviada'] as $old) {
        if (in_array($old, $forma, true) && ($plan['diag']['lengua_movilidad'] ?? '') === '') {
            $plan['diag']['lengua_movilidad'] = $old;
        }
    }
    $plan['diag']['lengua_forma'] = array_values(array_unique(array_map(
        static fn ($v) => $v === 'Puntos rojos' ? 'Puntos rojos / petequias' : $v,
        array_diff($forma, ['Temblorosa', 'Desviada'])
    )));
    return $plan;
}

function mtc_plan_for_appointment(int $apptId): ?array
{
    mtc_schema();
    $stmt = db()->prepare('SELECT * FROM mtc_plans WHERE appointment_id = ? LIMIT 1');
    $stmt->execute([$apptId]);
    $row = $stmt->fetch();
    if (!$row) {
        return null;
    }
    $row['plan'] = mtc_normalize(json_decode((string) $row['data'], true) ?: []);
    return $row;
}

/** Turno de un plan (consulta 2 en adelante) → [plan_id, appointment_id del plan, consult_no]. */
function mtc_visit_of(int $apptId): ?array
{
    mtc_schema();
    $stmt = db()->prepare('SELECT v.plan_id, v.consult_no, p.appointment_id FROM mtc_plan_visits v JOIN mtc_plans p ON p.id = v.plan_id WHERE v.appointment_id = ? LIMIT 1');
    $stmt->execute([$apptId]);
    return $stmt->fetch() ?: null;
}

/** Guarda el plan. Si cambia algo, la versión anterior queda en mtc_plan_versions (últimas 40). */
function mtc_save(int $apptId, array $plan, ?array $existing, string $reason = 'Antes de guardar cambios'): int
{
    $json = json_encode($plan, JSON_UNESCAPED_UNICODE);
    $now = date('Y-m-d H:i:s');
    if ($existing) {
        $id = (int) $existing['id'];
        if ($json !== json_encode(mtc_normalize(json_decode((string) $existing['data'], true) ?: []), JSON_UNESCAPED_UNICODE)) {
            $pdo = db();
            $pdo->beginTransaction();
            $pdo->prepare('INSERT INTO mtc_plan_versions (plan_id, data, reason, created_at) VALUES (?, ?, ?, ?)')
                ->execute([$id, (string) $existing['data'], $reason, $now]);
            $pdo->prepare('UPDATE mtc_plans SET data = ?, updated_at = ? WHERE id = ?')->execute([$json, $now, $id]);
            $pdo->prepare('DELETE FROM mtc_plan_versions WHERE plan_id = ? AND id NOT IN (SELECT id FROM mtc_plan_versions WHERE plan_id = ? ORDER BY id DESC LIMIT 40)')
                ->execute([$id, $id]);
            $pdo->commit();
        }
        return $id;
    }
    db()->prepare('INSERT INTO mtc_plans (appointment_id, data, created_at, updated_at) VALUES (?, ?, ?, ?)')->execute([$apptId, $json, $now, $now]);
    return (int) db()->lastInsertId();
}

function mtc_versions(int $planId): array
{
    $stmt = db()->prepare('SELECT * FROM mtc_plan_versions WHERE plan_id = ? ORDER BY id DESC');
    $stmt->execute([$planId]);
    return $stmt->fetchAll();
}

function mtc_version(int $planId, int $versionId): ?array
{
    $stmt = db()->prepare('SELECT * FROM mtc_plan_versions WHERE plan_id = ? AND id = ? LIMIT 1');
    $stmt->execute([$planId, $versionId]);
    return $stmt->fetch() ?: null;
}

/** Plan como el POST del formulario, para volver a pasarlo por mtc_from_post() (que valida todo). */
function mtc_to_post(array $plan): array
{
    $flat = static function (array $row): array {
        $out = [];
        foreach ($row as $k => $v) {
            $out[$k] = match (true) {
                is_bool($v) => $v ? '1' : '',
                is_array($v) => array_values(array_filter($v, 'is_string')),
                is_scalar($v) => (string) $v,
                default => '',
            };
        }
        return $out;
    };
    return [
        'diag' => $flat($plan['diag']),
        's' => array_map($flat, $plan['sessions']),
        'c' => array_map($flat, $plan['controls']),
        'p' => $flat($plan['patient']),
    ];
}

/** Estado de la consulta 1 que viaja oculto en la vista previa (nada se guarda hasta «Guardar plan»). */
function mtc_state_encode(array $plan): string
{
    return (string) json_encode($plan, JSON_UNESCAPED_UNICODE);
}

function mtc_state_decode(mixed $raw): ?array
{
    $data = is_string($raw) ? json_decode($raw, true) : null;
    if (!is_array($data) || !is_array($data['diag'] ?? null)) {
        return null;
    }
    $plan = mtc_normalize($data);
    return mtc_from_post(mtc_to_post($plan), ['generated' => $plan['generated'], 'template' => $plan['template']]);
}

/** Propuesta sin guardar: el plan con los textos generados y qué protocolo se aplicó. */
function mtc_propose(array $plan): array
{
    $proposal = mtc_apply_generated($plan);
    return [$proposal, mtc_generate($proposal)['protocol']];
}

/** Textos de la vista previa (quizás editados) aplicados sobre el estado de la consulta 1. */
function mtc_merge_proposal(array $state, array $post): array
{
    $plan = $state;
    $d = is_array($post['diag'] ?? null) ? $post['diag'] : [];
    $plan['diag']['tonificacion'] = mtc_clean_text($d['tonificacion'] ?? '');
    $plan['diag']['diagnostico'] = mtc_clean_text($d['diagnostico'] ?? '', 1000);
    $s = is_array($post['s'] ?? null) ? $post['s'] : [];
    foreach ($plan['sessions'] as $n => $row) {
        foreach (['objetivo', 'puntos', 'tecnica'] as $k) {
            $plan['sessions'][$n][$k] = mtc_clean_text(is_array($s[$n] ?? null) ? ($s[$n][$k] ?? '') : '');
        }
    }
    $p = is_array($post['p'] ?? null) ? $post['p'] : [];
    $plan['patient']['resumen'] = mtc_clean_text($p['resumen'] ?? '');
    $plan['patient']['recomendaciones'] = mtc_clean_text($p['recomendaciones'] ?? '');
    $plan['patient']['casa'] = mtc_clean_text($p['casa'] ?? '');
    $extra = mtc_indication_lines(is_array($post['ind_add'] ?? null) ? $post['ind_add'] : []);
    $plan['patient']['indicaciones'] = mtc_clean_text(trim(($p['indicaciones'] ?? '') . "\n" . $extra), 8000);
    $plan['generated'] = true;
    $plan['template'] = MTC_TEMPLATE;
    return $plan;
}

const MTC_DIAG_LABELS = [
    'medicacion' => 'medicación', 'fuentes' => 'teoría y fuentes', 'sudor' => 'sudoración', 'dolor' => 'dolor', 'orina' => 'orina',
    'apetito' => 'apetito', 'torax' => 'tórax', 'sentidos' => 'oídos y ojos', 'lengua_tamano' => 'tamaño de la lengua',
    'lengua_humedad' => 'humedad de la lengua', 'lengua_movilidad' => 'movilidad de la lengua', 'lengua_venas' => 'venas sublinguales',
    'saburra_distribucion' => 'distribución de la saburra', 'saburra_raiz' => 'raíz de la saburra',
    'motivo' => 'motivo de consulta', 'antecedentes' => 'antecedentes', 'interrog_notas' => 'notas del interrogatorio',
    'lengua_notas' => 'notas de la lengua', 'pulso' => 'pulso', 'patron_otro' => 'otro patrón', 'sintoma' => 'síntoma',
    'notas' => 'notas internas', 'sueno' => 'sueño', 'digestion' => 'digestión', 'sed' => 'sed', 'frio_calor' => 'frío / calor',
    'animo' => 'ánimo', 'ciclo' => 'ciclo', 'lengua_color' => 'color de la lengua', 'saburra_color' => 'color de la saburra',
    'saburra_espesor' => 'espesor de la saburra', 'saburra_humedad' => 'humedad de la saburra', 'contra' => 'contraindicaciones',
    'lengua_forma' => 'forma de la lengua', 'zonas' => 'zonas de la lengua', 'patrones' => 'patrones', 'zona_dolor' => 'zona del dolor',
    'escala' => 'escala', 'diagnostico' => 'diagnóstico',
];

/** Datos de la consulta 1 que cambian respecto de lo guardado (sin la tonificación, que se compara aparte). */
function mtc_diag_changes(array $saved, array $new): array
{
    $out = [];
    foreach (MTC_DIAG_LABELS as $k => $label) {
        if (($saved['diag'][$k] ?? null) !== ($new['diag'][$k] ?? null)) {
            $out[] = $label;
        }
    }
    return $out;
}

/** Resumen corto de una versión para el historial. */
function mtc_plan_brief(array $plan): string
{
    $labels = array_map(static fn ($k) => mtc_catalog_patterns(true)[$k]['label'] ?? $k, $plan['diag']['patrones']);
    if ($plan['diag']['patron_otro'] !== '') {
        $labels[] = $plan['diag']['patron_otro'];
    }
    return ($labels ? implode(', ', $labels) : 'sin patrón') . ' · ' . ($plan['generated'] ? 'plan generado' : 'solo diagnóstico')
        . ' · ' . mtc_done($plan) . ' de ' . MTC_TOTAL . ' consultas hechas';
}

/** Turnos agendados del plan (sin cancelados), por número de consulta. */
function mtc_visits(int $planId): array
{
    $stmt = db()->prepare("
      SELECT v.consult_no, a.id, a.date, a.time, a.status, a.code
      FROM mtc_plan_visits v JOIN appointments a ON a.id = v.appointment_id
      WHERE v.plan_id = ? AND a.status IN ('confirmed', 'pending_deposit')
      ORDER BY v.consult_no
    ");
    $stmt->execute([$planId]);
    $out = [];
    foreach ($stmt->fetchAll() as $row) {
        $out[(int) $row['consult_no']] = $row;
    }
    return $out;
}

function mtc_diagnosed(array $plan): bool
{
    return $plan['diag']['patrones'] !== [] || $plan['diag']['motivo'] !== '' || $plan['diag']['patron_otro'] !== '';
}

/** Última consulta registrada como hecha (1 = solo el diagnóstico). */
function mtc_done(array $plan): int
{
    $last = mtc_diagnosed($plan) ? 1 : 0;
    foreach ($plan['sessions'] + $plan['controls'] as $n => $row) {
        if (!empty($row['realizada'])) {
            $last = max($last, (int) $n);
        }
    }
    return $last;
}

function mtc_pregnant(array $plan): bool
{
    return in_array('embarazo', $plan['diag']['contra'], true);
}

function mtc_filter_points(array $codes, bool $pregnant): array
{
    return array_values(array_unique($pregnant ? array_diff($codes, MTC_PREGNANCY_AVOID) : $codes));
}

/** Contexto clínico que filtra las cinco técnicas (contraindicaciones marcadas y señales de calor). */
function mtc_context(array $plan): array
{
    $c = $plan['diag']['contra'];
    $heat = mtc_heat_signs($plan);
    return [
        'pregnant' => in_array('embarazo', $c, true),
        'anticoag' => in_array('anticoagulantes', $c, true),
        'pacemaker' => in_array('marcapasos', $c, true),
        'skin' => in_array('piel', $c, true),
        'fever' => in_array('calor', $c, true),
        'sens' => in_array('sensibilidad', $c, true),
        'heat' => $heat,
        'heatish' => count($heat) >= 2,
        'hotdx' => array_values(array_map(static fn ($p) => $p['label'], array_filter(mtc_chosen_patterns($plan), static fn ($p) => $p['moxa_mode'] === 'no'))),
    ];
}

/** Datos de la lengua y del interrogatorio que coinciden con [campo => valores], en palabras. */
function mtc_matches(array $diag, array $rules): array
{
    $out = ['lengua' => [], 'interrogatorio' => []];
    foreach ($rules as $field => $values) {
        $have = $diag[$field] ?? '';
        $hits = is_array($have) ? array_values(array_intersect($have, $values)) : (in_array($have, $values, true) ? [$have] : []);
        foreach ($hits as $v) {
            if (array_key_exists($field, MTC_TONGUE_FIELDS)) {
                $name = MTC_TONGUE_FIELDS[$field];
                $out['lengua'][] = mb_stripos($v, $name) !== false ? mb_strtolower($v) : trim($name . ' ' . mb_strtolower($v));
            } else {
                $label = preg_replace('/^\d+\.\s*/', '', MTC_INTERVIEW[$field] ?? $field) ?? $field;
                $out['interrogatorio'][] = mb_strtolower($label) . ': ' . mb_strtolower($v);
            }
        }
    }
    return $out;
}

function mtc_heat_signs(array $plan): array
{
    $m = mtc_matches($plan['diag'], MTC_HEAT_SIGNS);
    return array_merge($m['lengua'], $m['interrogatorio']);
}

/**
 * Por qué cada patrón: señales de la lengua (pesan doble) y del interrogatorio. El pulso no se usa.
 * @return array<string, array{lengua: list<string>, interrogatorio: list<string>, score: int}>
 */
function mtc_pattern_evidence(array $plan): array
{
    $out = [];
    foreach (mtc_catalog_patterns(true) as $key => $p) {
        if (!$p['_active'] && !in_array($key, $plan['diag']['patrones'], true)) {
            continue;
        }
        $m = mtc_matches($plan['diag'], $p['evidence'] ?? []);
        $zone = mtc_pain_zones()[$plan['diag']['zona_dolor']] ?? null;
        if ($key === 'musculo' && $zone) {
            array_unshift($m['interrogatorio'], 'zona del dolor: ' . mb_strtolower($zone['label']));
        }
        $m['score'] = 2 * count($m['lengua']) + count($m['interrogatorio']);
        $out[$key] = $m;
    }
    return $out;
}

/** Patrones que sugieren la lengua y el interrogatorio (al menos una señal de lengua y 3 puntos, o 3 del interrogatorio), de más a menos. */
function mtc_suggested_patterns(array $plan): array
{
    $active = mtc_patterns();
    $ev = array_filter(mtc_pattern_evidence($plan), static fn ($m, $k) => isset($active[$k]) && ($m['lengua'] !== [] ? $m['score'] >= 3 : count($m['interrogatorio']) >= 3), ARRAY_FILTER_USE_BOTH);
    uasort($ev, static fn ($a, $b) => $b['score'] <=> $a['score']);
    return array_slice($ev, 0, 6, true);
}

/** Patrones elegidos en la consulta 1 (también los que después se desactivaron en el catálogo), en el orden en que se eligieron. */
function mtc_chosen_patterns(array $plan): array
{
    $all = mtc_catalog_patterns(true);
    $out = [];
    foreach ($plan['diag']['patrones'] as $k) {
        if (isset($all[$k])) {
            $out[$k] = $all[$k];
        }
    }
    return $out;
}

/**
 * Línea de diagnóstico como la lee el paciente: «Exacerbación del Yang de Hígado, deficiencia de Yin de Bazo.»
 * (patrones elegidos + otro patrón escrito a mano).
 */
function mtc_compose_diagnosis(array $plan): string
{
    $zone = mtc_pain_zones()[$plan['diag']['zona_dolor']] ?? null;
    $parts = [];
    foreach (mtc_chosen_patterns($plan) as $p) {
        $parts[] = $p['label'] === 'Dolor musculoesquelético' && $zone ? $p['label'] . ' (zona ' . mb_strtolower($zone['label']) . ')' : $p['label'];
    }
    foreach (preg_split('/\s*[,;\n]\s*/u', trim($plan['diag']['patron_otro'])) ?: [] as $o) {
        if ($o !== '') {
            $parts[] = rtrim($o, '. ');
        }
    }
    foreach ($parts as $i => $t) {
        if ($i > 0 && !preg_match('/^(Qi|Yin|Yang|Jing)\b/u', $t)) {
            $parts[$i] = mb_strtolower(mb_substr($t, 0, 1)) . mb_substr($t, 1);
        }
    }
    return $parts ? implode(', ', $parts) . '.' : '';
}

/**
 * Recuadro «Diagnóstico desde la Medicina Tradicional China» en HTML (crema, serif y rama de ciruelo en SVG propio),
 * igual al del PDF. Lo usan la vista previa y lo puede usar Pacientes. Estilos: .mtc-dx en assets/plan_mtc.css (tiene estilos en línea de respaldo).
 */
function mtc_diagnosis_html(string $line): string
{
    $flower = static function (float $cx, float $cy, float $r, float $turn): string {
        $out = '';
        for ($i = 0; $i < 5; $i++) {
            $a = $turn + $i * 2 * M_PI / 5;
            $out .= sprintf('<circle cx="%.1f" cy="%.1f" r="%.1f" fill="#c21f24"/>', $cx + cos($a) * $r * 0.95, $cy - sin($a) * $r * 0.95, $r * 0.72);
        }
        return $out . sprintf('<circle cx="%.1f" cy="%.1f" r="%.1f" fill="#fadb8f"/>', $cx, $cy, $r * 0.42);
    };
    $svg = '<svg class="mtc-dx-branch" viewBox="0 0 170 120" aria-hidden="true" focusable="false">'
        . '<g fill="none" stroke="#54331f" stroke-linecap="round">'
        . '<path d="M168 114 C140 100 118 90 98 74" stroke-width="4.2"/><path d="M98 74 C80 60 62 50 44 28" stroke-width="3"/>'
        . '<path d="M44 28 C34 16 24 10 12 6" stroke-width="1.8"/><path d="M118 90 C128 70 126 54 138 34" stroke-width="2"/>'
        . '<path d="M138 34 C144 24 152 18 160 16" stroke-width="1.3"/><path d="M74 56 C70 70 60 80 48 84" stroke-width="1.5"/></g>'
        . $flower(44, 28, 7.5, 0.3) . $flower(96, 70, 8.5, 1.1) . $flower(136, 38, 7, 0.7) . $flower(56, 82, 6, 0)
        . '<g fill="#b31a1f"><circle cx="18" cy="8" r="2.6"/><circle cx="160" cy="16" r="2.8"/><circle cx="72" cy="54" r="2.4"/><circle cx="126" cy="60" r="2.2"/></g>'
        . '</svg>';
    return '<div class="mtc-dx" style="background:#fdf6e8;border:1px solid #d6ba91">'
        . '<p class="mtc-dx-title">DIAGNÓSTICO DESDE LA MEDICINA TRADICIONAL CHINA</p>'
        . '<p class="mtc-dx-text">' . ($line !== '' ? h($line) : '<span class="muted">(se completa al elegir los patrones)</span>') . '</p>'
        . $svg . '</div>';
}

/** Diagnóstico para mostrar: el texto (editable) guardado o, si está vacío, el compuesto. */
function mtc_diagnosis_line(array $plan): string
{
    return $plan['diag']['diagnostico'] !== '' ? $plan['diag']['diagnostico'] : mtc_compose_diagnosis($plan);
}

function mtc_evidence_text(array $m): string
{
    $parts = [];
    if ($m['lengua']) {
        $parts[] = 'lengua: ' . implode(', ', $m['lengua']);
    }
    if ($m['interrogatorio']) {
        $parts[] = 'interrogatorio: ' . implode(', ', $m['interrogatorio']);
    }
    return implode('; ', $parts);
}

/** Glosodiagnosis en una línea (cuerpo, saburra y zonas). */
function mtc_tongue_summary(array $diag): string
{
    $body = array_filter([
        $diag['lengua_color'], $diag['lengua_tamano'] !== 'Normal' ? $diag['lengua_tamano'] : '', implode(', ', $diag['lengua_forma']),
        $diag['lengua_humedad'] !== '' && $diag['lengua_humedad'] !== 'Normal' ? 'humedad ' . $diag['lengua_humedad'] : '',
        $diag['lengua_movilidad'] !== '' && $diag['lengua_movilidad'] !== 'Normal' ? $diag['lengua_movilidad'] : '',
        $diag['lengua_venas'] !== '' && $diag['lengua_venas'] !== 'Normales' ? 'venas sublinguales ' . $diag['lengua_venas'] : '',
    ]);
    $coat = array_filter([$diag['saburra_color'], $diag['saburra_espesor'], $diag['saburra_humedad'] !== 'Normal' ? $diag['saburra_humedad'] : '', $diag['saburra_distribucion'], $diag['saburra_raiz']]);
    $parts = [];
    if ($body) {
        $parts[] = 'cuerpo ' . mb_strtolower(implode(', ', $body));
    }
    if ($coat) {
        $parts[] = 'saburra ' . mb_strtolower(implode(', ', $coat));
    }
    if ($diag['zonas']) {
        $parts[] = 'zonas alteradas: ' . mb_strtolower(implode(', ', $diag['zonas']));
    }
    return $parts ? implode('; ', $parts) : '';
}

/**
 * Puntos para moxar (en lugar de agujas) con el contexto clínico.
 * @return array{moxa: array<string, string>, press: array<string, string>, notes: list<string>} moxa = [punto => método], press = [punto => motivo] (van con acupresión en el tuina)
 */
function mtc_moxa_plan(array $codes, array $ctx): array
{
    $moxa = [];
    $press = [];
    $notes = [];
    $codes = array_values(array_unique($codes));
    if ($ctx['pregnant']) {
        $codes = array_values(array_diff($codes, MTC_PREGNANCY_NO_MOXA));
    }
    foreach ($codes as $c) {
        $rule = MTC_MOXA[$c] ?? 'baston';
        if (is_array($rule)) {
            $press[$c] = MTC_MOXA_SKIP[$rule[0]];
            $alt = $rule[1];
            if ($alt !== null && !isset($moxa[$alt]) && !in_array($alt, $codes, true) && !($ctx['pregnant'] && in_array($alt, MTC_PREGNANCY_NO_MOXA, true))) {
                $moxa[$alt] = MTC_MOXA_METHODS[MTC_MOXA[$alt]] . ' (alternativa a ' . mtc_point_label($c) . ')';
            }
            continue;
        }
        if ($ctx['sens'] && $rule === 'jengibre') {
            $rule = 'baston';
        }
        $moxa[$c] = MTC_MOXA_METHODS[$rule];
    }
    if ($ctx['fever']) {
        foreach (array_keys($moxa) as $c) {
            $press[$c] = 'fiebre o calor agudo';
        }
        $moxa = [];
        $notes[] = 'Sin moxa mientras haya fiebre o calor agudo: los puntos van con acupresión en el tuina.';
    } elseif (($ctx['heatish'] || !empty($ctx['hotdx'])) && $moxa) {
        $keep = array_slice(array_keys($moxa), 0, 2);
        $why = $ctx['heatish'] ? 'signos de calor' : 'patrón con calor o Yang que asciende';
        foreach (array_keys($moxa) as $c) {
            if (in_array($c, $keep, true)) {
                $moxa[$c] = MTC_MOXA_METHODS['picoteo'] . ', breve';
            } else {
                $press[$c] = $why;
                unset($moxa[$c]);
            }
        }
        if ($ctx['heatish']) {
            $notes[] = 'Signos de calor (' . implode(', ', $ctx['heat']) . '): moxa breve en picoteo y solo si no aumenta el calor; el resto, acupresión.';
        }
        if (!empty($ctx['hotdx'])) {
            $notes[] = 'Combinación con ' . implode(', ', $ctx['hotdx']) . ' (Yin deficiente con calor o Yang que asciende): se prioriza tuina y acupresión; '
                . 'la moxa queda breve, en picoteo y en 1 o 2 puntos, y se suspende si aparece calor, sed o enrojecimiento.';
        }
    } elseif (!empty($ctx['brief']) && $moxa) {
        foreach (array_keys($moxa) as $c) {
            $moxa[$c] = MTC_MOXA_METHODS['picoteo'];
        }
        $notes[] = 'Moxa con cautela: en picoteo; si aparece calor, pasar a acupresión.';
    }
    if ($moxa && $ctx['sens']) {
        $notes[] = 'Diabetes o sensibilidad disminuida: solo moxa indirecta, a más distancia y controlando la piel cada minuto (sin conos).';
    }
    if ($moxa && $ctx['skin']) {
        $notes[] = 'No moxar sobre la piel lesionada.';
    }
    if ($ctx['pregnant']) {
        $notes[] = 'Embarazo: sin moxa en abdomen ni zona lumbosacra, ni en ' . mtc_points_text(['B6', 'IG4', 'V60', 'VB21']) . '.';
    }
    if ($moxa) {
        $notes[] = 'Moxa: nunca en cara, ojos, mucosas, heridas, várices ni zonas sin sensibilidad.';
    }
    return ['moxa' => $moxa, 'press' => $press, 'notes' => $notes];
}

function mtc_moxa_lines(array $mp, bool $withPress = true): string
{
    $lines = [];
    foreach ($mp['moxa'] as $c => $m) {
        $lines[] = '- ' . mtc_point_label($c) . ': ' . $m . '.';
    }
    if ($withPress && $mp['press']) {
        $lines[] = 'Con acupresión en el tuina (sin moxa): '
            . implode('; ', array_map(static fn ($c, $r) => mtc_point_label($c) . ' (' . $r . ')', array_keys($mp['press']), $mp['press'])) . '.';
    }
    return implode("\n", $lines);
}

/** Saca de una lista de maniobras las que tocan zonas no permitidas («abdomen», «zona lumbar»). */
function mtc_drop_clauses(string $text, array $words): string
{
    $keep = array_filter(explode(', ', $text), static function (string $c) use ($words): bool {
        foreach ($words as $w) {
            if (str_contains($c, $w)) {
                return false;
            }
        }
        return true;
    });
    return implode(', ', $keep);
}

/**
 * Las cinco técnicas del plan, ya filtradas por contraindicaciones (las usa la vista previa, el PDF y el módulo Pacientes).
 * @return array<string, array{label: string, use: bool, summary: string, cautions: list<string>}>
 *   Claves: tuina, qigong, moxa, ventosas, auriculo. Extras: moxa.plan (mtc_moxa_plan), moxa.root (plan de la raíz),
 *   qigong.sesiones / qigong.casa / qigong.min, auriculo.points, ventosas.zones.
 */
function mtc_techniques(array $plan): array
{
    $ctx = mtc_context($plan);
    $diag = $plan['diag'];
    $chosen = array_values(mtc_chosen_patterns($plan));
    $zone = mtc_pain_zones()[$diag['zona_dolor']] ?? null;
    $ashi = (bool) array_filter($chosen, static fn ($p) => !empty($p['ashi']));
    $isMuscle = static fn (array $p): bool => $p['label'] === 'Dolor musculoesquelético';

    $codes = [];
    $root = [];
    $hot = [];
    $hotPress = [];
    $modes = [];
    foreach ($chosen as $p) {
        $modes[$p['moxa_mode']] = true;
        if ($p['moxa_mode'] === 'no') {
            $hot[] = $p['label'];
            $hotPress = array_merge($hotPress, $p['root'], $p['moxa']);
            continue;
        }
        $codes = array_merge($codes, $p['moxa'] ?? []);
        $root = array_merge($root, $p['root']);
    }
    if ($zone) {
        $codes = array_merge($codes, $zone['local'], $zone['distal']);
        $root = array_merge($root, $zone['local']);
    }
    if (!$codes && !$hot) {
        $codes = ['E36', 'RM6', 'R3'];
    }
    $mctx = $ctx + ['brief' => isset($modes['cauto']) && !isset($modes['si'])];
    $none = ['moxa' => [], 'press' => [], 'notes' => []];
    $mp = $codes ? mtc_moxa_plan($codes, $mctx) : $none;
    foreach ($chosen as $p) {
        if ($p['moxa_mode'] !== 'no' && !empty($p['moxa_note']) && $mp['moxa']) {
            $mp['notes'][] = $p['moxa_note'];
        }
    }
    if ($hot && !$codes) {
        $mp['notes'][] = 'Sin moxa: ' . implode(', ', $hot) . ' (Yin deficiente con calor o Yang que asciende). Se prioriza tuina y acupresión.';
    }
    $rootPlan = $root || $mp['moxa'] ? mtc_moxa_plan($root ?: array_keys($mp['moxa']), $mctx) : $none;
    if (!$rootPlan['moxa'] && $mp['moxa']) {
        $rootPlan['moxa'] = array_slice($mp['moxa'], 0, 2, true);
    }
    $moxaUse = $mp['moxa'] !== [];
    $moxaSummary = $moxaUse
        ? 'Puntos para moxar: ' . mtc_points_text(array_keys($mp['moxa'])) . ($ashi && !$ctx['fever'] ? ', más puntos Ashi' : '') . '.'
        : 'No se usa en este caso' . ($hot && !$ctx['fever'] ? ' (' . implode(', ', $hot) . ')' : '') . ': los puntos van con acupresión en el tuina.';

    $man = [];
    $press = [];
    $gentle = false;
    foreach ($chosen as $p) {
        $m = $ctx['pregnant'] ? mtc_drop_clauses($p['tuina']['maniobras'], ['abdomen', 'zona lumbar']) : $p['tuina']['maniobras'];
        if (str_contains($m, 'sin generar calor')) {
            $gentle = true;
            $m = mtc_drop_clauses($m, ['sin generar calor']);
        }
        if ($m !== '' && count($man) < 3) {
            $man[] = $m;
        }
    }
    foreach ($chosen as $p) {
        $press = array_merge($press, array_slice($p['tuina']['acupresion'], 0, 2));
    }
    foreach ($chosen as $p) {
        $press = array_merge($press, $p['tuina']['acupresion']);
    }
    if (!$man) {
        $man[] = 'tui (empuje) y rou (amasado) en la espalda sobre los Shu dorsales, an (presión) en los puntos del plan';
    }
    if ($gentle) {
        $man[count($man) - 1] .= '; todo suave y sin generar calor';
    }
    $press = array_merge($press, $hotPress, array_keys($mp['press']));
    if ($zone) {
        $press = array_merge($press, array_diff($zone['local'], array_keys($mp['moxa'])));
    }
    if ($ctx['pregnant']) {
        $press = array_diff($press, MTC_PREGNANCY_AVOID, ['RM8', 'RM9', 'RM10', 'RM12', 'E25', 'E21']);
    }
    $press = array_slice(array_values(array_unique($press)), 0, 8);
    $tc = [];
    if ($ctx['pregnant']) {
        $tc[] = 'Embarazo: sin trabajo sobre el abdomen ni presión fuerte en la zona lumbosacra.';
    }
    if ($ctx['anticoag']) {
        $tc[] = 'Anticoagulantes: presión suave, sin gun ni pinzados fuertes (moretones).';
    }
    if ($ctx['skin']) {
        $tc[] = 'No trabajar sobre la piel lesionada.';
    }
    if ($ctx['fever']) {
        $tc[] = 'Con fiebre: tuina corto y suave, o reprogramar la sesión.';
    }
    if ($ctx['sens']) {
        $tc[] = 'Sensibilidad disminuida: presión moderada y revisar la piel.';
    }
    $tuinaSummary = 'Tuina (15 a 20 min): ' . implode('; ', array_values(array_unique($man))) . '.'
        . ($press || $ashi ? ' Acupresión (1 a 2 min por punto): ' . trim(mtc_points_text($press) . ($ashi ? ($press ? ' y ' : '') . 'puntos Ashi' : '')) . '.' : '');

    $ses = [];
    $casa = [];
    $min = 10;
    foreach ($chosen as $p) {
        $q = $isMuscle($p) && $zone && $zone['qigong'] ? $zone['qigong'] + ['min' => 10] : $p['qigong'];
        $ses[] = $q['sesion'];
        $casa[] = $q['casa'];
        $min = max($min, (int) ($p['qigong']['min'] ?? 10));
    }
    if (!$chosen && $zone && $zone['qigong']) {
        $ses[] = $zone['qigong']['sesion'];
        $casa[] = $zone['qigong']['casa'];
    }
    if (!$ses) {
        $ses[] = 'respiración abdominal y el Ba Duan Jin completo, suave';
        $casa[] = 'respiración abdominal 5 minutos y el Ba Duan Jin suave';
    }
    $ses = array_slice(array_values(array_unique($ses)), 0, 2);
    $casa = array_slice(array_values(array_unique($casa)), 0, 2);
    $min = min(20, $min + 5 * (count($casa) - 1));
    $qc = [];
    if ($ctx['pregnant']) {
        $qc[] = 'Embarazo: movimientos suaves, sin retener el aire, sin flexiones profundas ni torsiones del abdomen.';
    }
    if ($ctx['fever']) {
        $qc[] = 'Con fiebre: solo respiración abdominal suave, en reposo, hasta que pase.';
    }

    $cups = [];
    foreach ($chosen as $p) {
        $cups[] = ($isMuscle($p) && $zone ? $zone['cups'] : $p['ventosas']['zona']) . ', ' . $p['ventosas']['modo'];
    }
    if (!$chosen && $zone) {
        $cups[] = $zone['cups'] . ', fijas o deslizantes, 5 a 10 minutos';
    }
    if (!$cups) {
        $cups[] = 'espalda (Shu dorsales), fijas suaves, 5 minutos';
    }
    if ($ctx['pregnant']) {
        $cups = array_map(static fn ($z) => preg_match('/lumbar|abdomen|glúteo/iu', $z) ? 'zona dorsal alta y trapecios (sin zona lumbar ni abdomen), fijas suaves, 5 minutos' : $z, $cups);
    }
    $cups = array_slice(array_values(array_unique($cups)), 0, 2);
    $fragile = array_values(array_map(static fn ($p) => $p['label'], array_filter($chosen, static fn ($p) => !empty($p['no_cups']))));
    $cupsUse = !$ctx['anticoag'] && !$ctx['fever'] && !$fragile;
    $why = array_values(array_filter([$ctx['anticoag'] ? 'anticoagulantes' : '', $ctx['fever'] ? 'fiebre o calor agudo' : '',
        $fragile ? 'fragilidad capilar: ' . implode(', ', $fragile) : '']));
    $vc = ['Nunca sobre piel lesionada, várices, lunares ni zonas con fragilidad capilar.'];
    if ($ctx['sens']) {
        $vc[] = 'Sensibilidad disminuida: succión suave, 3 a 5 minutos.';
    }
    if ($ctx['pregnant']) {
        $vc[] = 'Embarazo: nunca en abdomen ni zona lumbosacra.';
    }
    $cupsSummary = $cupsUse
        ? 'Ventosas: ' . implode('; ', $cups) . '.'
        : 'Sin ventosas (' . implode(' y ', $why) . '): en su lugar, tui (empuje) y rou (amasado) sobre la misma zona.';

    $ear = ['Shenmen'];
    foreach ($chosen as $p) {
        $ear = array_merge($ear, $p['oreja']);
    }
    if ($zone) {
        $ear[] = $zone['ear'];
    }
    if (count($ear) === 1) {
        $ear = array_merge($ear, ['Punto Cero', 'Simpático']);
    }
    if ($ctx['pregnant']) {
        $ear = array_diff($ear, ['Endocrino']);
    }
    $ear = array_slice(array_values(array_unique($ear)), 0, 6);
    $ac = [];
    if ($ctx['pregnant']) {
        $ac[] = 'Embarazo: sin Útero, Ovario ni Endocrino; presión suave.';
    }
    if ($ctx['pacemaker']) {
        $ac[] = 'Marcapasos: sin electroestimulación auricular (solo semillas).';
    }
    if ($ctx['anticoag']) {
        $ac[] = 'Anticoagulantes: presión suave.';
    }
    if ($ctx['skin']) {
        $ac[] = 'Solo sobre piel sana de la oreja.';
    }

    return [
        'tuina' => ['label' => 'Tuina', 'use' => true, 'summary' => $tuinaSummary, 'cautions' => $tc, 'press' => $press],
        'qigong' => [
            'label' => 'Chi kung', 'use' => true, 'cautions' => $qc, 'sesiones' => $ses, 'casa' => $casa, 'min' => $min,
            'summary' => 'Chi kung: en la sesión, ' . implode('; después, ', $ses) . '. En casa: ' . implode(' y ', $casa) . ' (' . $min . ' minutos por día).',
        ],
        'moxa' => ['label' => 'Moxibustión', 'use' => $moxaUse, 'summary' => $moxaSummary, 'cautions' => $mp['notes'], 'plan' => $mp, 'root' => $rootPlan, 'ashi' => $ashi],
        'ventosas' => ['label' => 'Ventosas', 'use' => $cupsUse, 'summary' => $cupsSummary, 'cautions' => $cupsUse ? $vc : [], 'zones' => $cups],
        'auriculo' => [
            'label' => 'Auriculoterapia', 'use' => true, 'cautions' => $ac, 'points' => $ear,
            'summary' => 'Auriculoterapia con semillas de vaccaria o balines (sin agujas): ' . implode(', ', $ear)
                . '. Una oreja por semana, alternando. El paciente presiona cada punto 30 segundos, 3 veces al día; se retiran a los 5 a 7 días o si irritan.',
        ],
    ];
}

/** Práctica en casa para el paciente (chi kung, semillas y cuidados después de la sesión). */
function mtc_home_text(array $t, array $ctx): string
{
    $lines = [];
    $lines[] = '- Chi kung: ' . implode('; y ', $t['qigong']['casa']) . '. En total, unos ' . $t['qigong']['min'] . ' minutos por día.';
    $lines[] = '- Semillas en la oreja: presioná cada una 30 segundos, 3 veces al día (y cuando tengas dolor o ansiedad). Te podés bañar con ellas; si pican o irritan, sacalas. Las cambiamos cada semana y alternamos de oreja.';
    $after = array_values(array_filter([$t['moxa']['use'] ? 'la moxa' : '', $t['ventosas']['use'] ? 'las ventosas' : '']));
    if ($after) {
        $lines[] = '- Después de ' . implode(' o ', $after) . ': abrigá la zona y evitá el frío y el agua fría ese día.';
    }
    if ($ctx['pregnant']) {
        $lines[] = '- En el embarazo hacé los ejercicios suaves, sin retener el aire ni hacer flexiones profundas.';
    }
    if ($ctx['fever']) {
        $lines[] = '- Mientras tengas fiebre, solo respiración abdominal suave y descanso.';
    }
    return implode("\n", $lines);
}

/** Tonificación general de la consulta 1: moxa, tuina, semillas y chi kung (sin agujas). $ctx = mtc_context(). */
function mtc_default_tonify(bool $pregnant, array $ctx = []): string
{
    if (!empty($ctx['fever'])) {
        $moxa = 'Moxa: no (fiebre o calor agudo); acupresión de Zusanli (E36) y ' . ($pregnant ? 'Taixi (R3)' : 'Qihai (RM6)') . '.';
    } elseif (!empty($ctx['hotdx'])) {
        $moxa = 'Moxa: no (el diagnóstico tiene calor o Yang que asciende); acupresión de Taixi (R3)' . ($pregnant ? ' y Yongquan (R1).' : ', Sanyinjiao (B6) y Yongquan (R1).');
    } elseif (!empty($ctx['heatish'])) {
        $moxa = 'Moxa: breve, en picoteo, 5 pasadas en Zusanli (E36) (hay signos de calor).';
    } elseif ($pregnant) {
        $moxa = 'Moxa: Zusanli (E36) con bastón suave, 10 min (sin abdomen ni zona lumbosacra).';
    } else {
        $moxa = 'Moxa: Zusanli (E36) con ' . (!empty($ctx['sens']) ? 'bastón indirecta a distancia, 10 min' : 'conos sobre jengibre (3 por lado)') . ' y Qihai (RM6) con caja de moxa, 15 min.';
    }
    $lines = [
        $moxa,
        'Tuina: tui (empuje) y rou (amasado) en la espalda sobre los Shu dorsales, 10 min' . ($pregnant ? ', suave y sin zona lumbosacra' : '')
            . '; acupresión de Neiguan (PC6), Taixi (R3), Baihui (DU20) y Yintang.',
        'Auriculoterapia: semillas en Shenmen y Punto Cero (una oreja)' . ($pregnant ? ', presión suave' : '') . '.',
        'Chi kung: enseñar respiración abdominal, 5 min' . ($pregnant ? ', sin retener el aire' : '') . '.',
    ];
    if ($pregnant) {
        $lines[] = 'Embarazo: sin Sanyinjiao (B6), Hegu (IG4), Kunlun (V60), Jianjing (VB21) ni abdomen o zona lumbosacra.';
    }
    return implode("\n", $lines);
}

/** Texto de tonificación de la plantilla anterior (con agujas), para reconocerlo al regenerar. */
function mtc_legacy_tonify(bool $pregnant): string
{
    $codes = mtc_filter_points(MTC_TONIFY, $pregnant);
    $first = mtc_point_label(array_shift($codes)) . ' bilateral';
    $text = implode(', ', array_merge([$first], array_map('mtc_point_label', $codes))) . ' o Yintang' . ".\n"
        . ($pregnant ? "Moxa suave en Zusanli (E36) si hay frío o cansancio.\n" : "Moxa en Zusanli (E36) / Qihai (RM6) si hay frío o cansancio.\n")
        . 'Retención 20–30 min.';
    if ($pregnant) {
        $text .= "\nEmbarazo: sin Sanyinjiao (B6), Hegu (IG4) ni puntos del bajo abdomen.";
    }
    return $text;
}

/** La tonificación sigue siendo un texto sugerido (vacío, de la plantilla vieja o de la actual): se puede reemplazar al generar. */
function mtc_is_default_tonify(string $text): bool
{
    if (trim($text) === '') {
        return true;
    }
    foreach ([false, true] as $p) {
        if ($text === mtc_legacy_tonify($p)) {
            return true;
        }
        foreach ([[], ['fever' => true], ['heatish' => true], ['sens' => true], ['hotdx' => ['x']]] as $ctx) {
            if ($text === mtc_default_tonify($p, $ctx)) {
                return true;
            }
        }
    }
    return false;
}

/** Avisos por contraindicaciones, señales de calor y textos que no corresponden (agujas, puntos no aconsejados). */
function mtc_warnings(array $plan, ?array $appt = null): array
{
    $w = [];
    $ctx = mtc_context($plan);
    $texts = $plan['diag']['tonificacion'];
    foreach ($plan['sessions'] as $s) {
        $texts .= "\n" . $s['objetivo'] . "\n" . $s['puntos'] . "\n" . $s['tecnica'];
    }
    foreach ($plan['controls'] as $c) {
        $texts .= "\n" . $c['puntos'];
    }
    if ($ctx['pregnant']) {
        $w[] = 'Embarazo: el plan no moxa ni presiona ' . mtc_points_text(MTC_PREGNANCY_AVOID)
            . ', ni trabaja abdomen o zona lumbosacra (moxa, tuina y ventosas). Chi kung suave; en la oreja, sin Útero, Ovario ni Endocrino.';
        $scan = preg_replace('/Embarazo:[^\n]*?(\.(\s|$)|$)/mu', '', $texts) ?? $texts;
        $found = [];
        foreach (MTC_PREGNANCY_AVOID as $code) {
            if (preg_match('/\b' . $code . '\b|' . preg_quote(MTC_POINTS[$code], '/') . '/i', $scan)) {
                $found[] = mtc_point_label($code);
            }
        }
        if ($found) {
            $w[] = 'Atención: en los textos del plan todavía aparece ' . implode(', ', $found) . '. Revisalo (embarazo).';
        }
    }
    if ($ctx['pacemaker']) {
        $w[] = 'Marcapasos: sin electroestimulación (tampoco en la oreja). Tuina, moxa, ventosas, semillas y chi kung, sí.';
    }
    if ($ctx['anticoag']) {
        $w[] = 'Anticoagulantes: sin ventosas; tuina y presión de las semillas suaves; revisar la piel después de la moxa.';
    }
    if ($ctx['skin']) {
        $w[] = 'Piel lesionada: sin tuina, moxa ni ventosas sobre la zona; semillas solo en piel sana.';
    }
    if ($ctx['fever']) {
        $w[] = 'Fiebre o calor agudo: sin moxa ni ventosas; tuina suave y acupresión; chi kung solo respiración en reposo.';
    } elseif ($ctx['heatish']) {
        $w[] = 'Signos de calor en la lengua o el interrogatorio (' . implode(', ', $ctx['heat']) . '): moxa breve y en pocos puntos.';
    }
    if ($ctx['sens']) {
        $w[] = 'Diabetes o sensibilidad disminuida: moxa solo indirecta y a distancia (sin conos), controlando la piel; ventosas suaves y cortas.';
    }
    $chosen = mtc_chosen_patterns($plan);
    $hot = array_values(array_map(static fn ($p) => $p['label'], array_filter($chosen, static fn ($p) => $p['moxa_mode'] === 'no')));
    $warm = array_values(array_map(static fn ($p) => $p['label'], array_filter($chosen, static fn ($p) => $p['moxa_mode'] !== 'no' && $p['moxa'])));
    if ($hot && $warm) {
        $w[] = 'Conflicto de moxa: ' . implode(', ', $hot) . ' (Yin deficiente con calor o Yang que asciende) no admite moxa, y '
            . implode(', ', $warm) . ' sí. El plan prioriza tuina y acupresión y deja la moxa breve, en picoteo y en 1 o 2 puntos.';
    } elseif ($hot && !$ctx['fever']) {
        $w[] = 'Sin moxa por el diagnóstico (' . implode(', ', $hot) . '): tuina, acupresión, semillas y chi kung.';
    }
    foreach ($chosen as $p) {
        $note = trim(preg_replace('/Sin moxa[^.]*\.\s*/u', '', $p['contra_notes']) ?? '');
        if ($note !== '') {
            $w[] = $p['label'] . ': ' . $note;
        }
    }
    if (preg_match('/punturar|electroacupuntura|retenci[oó]n\s+\d|agujas?\s+(colocad|fin)/iu', $texts)) {
        $w[] = 'Este plan todavía menciona agujas (plantilla anterior). Para el plan sin acupuntura, tocá «Generar plan» de nuevo.';
    }
    if ($appt !== null && empty($appt['consent_accepted_at'])) {
        $w[] = 'El consentimiento informado no está firmado online: revisá que lo haya firmado en papel.';
    }
    return $w;
}

/** Sugerencia para los controles semanales 6 a 25 (no se guarda: se arma con el diagnóstico). */
function mtc_control_guide(array $plan): string
{
    $t = mtc_techniques($plan);
    $root = $t['moxa']['root']['moxa'];
    return 'Cada semana: control breve (escala 0–10 y cambios), tuina 15 min, '
        . ($t['moxa']['use'] && $root ? 'moxa en la raíz (' . mtc_points_text(array_keys($root)) . ')' : 'acupresión en la raíz')
        . ', semillas en la oreja alternando de oreja'
        . ($t['ventosas']['use'] ? ', ventosas cada 2 o 3 semanas si hay tensión' : '')
        . ' y revisión del chi kung en casa. En las consultas 10, 15, 20 y 25: re-evaluación comparando con la consulta 1 (escala y foto de la lengua).';
}

/**
 * Arma los textos de las consultas 2 a 5 (cinco técnicas, sin agujas), el resumen para el paciente, las recomendaciones
 * y la práctica en casa, a partir de la consulta 1 (glosodiagnosis e información del paciente).
 *
 * @return array{protocol: list<array{0: string, 1: string}>, techniques: array, sessions: array<int, array{objetivo: string, puntos: string, tecnica: string}>,
 *   resumen: string, recomendaciones: string, casa: string}
 */
function mtc_generate(array $plan): array
{
    $diag = $plan['diag'];
    $ctx = mtc_context($plan);
    $all = mtc_catalog_patterns(true);
    $chosen = mtc_chosen_patterns($plan);
    $zones = mtc_pain_zones();
    $zone = $zones[$diag['zona_dolor']] ?? null;
    $t = mtc_techniques($plan);
    $mp = $t['moxa']['plan'];
    $rootPlan = $t['moxa']['root'];
    $closePlan = $t['moxa']['use'] ? mtc_moxa_plan(['E36', 'RM6'], $ctx) : ['moxa' => [], 'press' => [], 'notes' => []];
    $moxaUse = $t['moxa']['use'];
    $cupsUse = $t['ventosas']['use'];

    $meridians = [];
    foreach (array_merge(array_column($chosen, 'meridian'), [$zone['meridian'] ?? '']) as $m) {
        $parts = preg_split('/\s+(?:y|e)\s+|,\s*/u', $m, -1, PREG_SPLIT_NO_EMPTY) ?: [];
        sort($parts);
        if ($parts && !isset($meridians[implode('|', $parts)])) {
            $meridians[implode('|', $parts)] = $m;
        }
    }
    $meridianText = $meridians ? implode('; ', array_values($meridians)) : 'los meridianos relacionados con tu consulta';
    $otro = $diag['patron_otro'];
    $zoneLabel = $zone ? mb_strtolower($zone['label']) : '';
    $control = 'Control breve: escala 0–10, cambios en la semana, reacciones a la sesión anterior (sin nuevo diagnóstico).';
    $cupsPlain = preg_replace('/\s*\([^)]*\)/u', '', explode(', ', $t['ventosas']['zones'][0])[0]) ?? '';
    $pointsHead = 'Puntos para moxar (en lugar de agujas):';
    $ashiLine = $t['moxa']['ashi'] && $moxaUse ? "\n- Puntos Ashi (dolorosos a la palpación): bastón indirecta, 5 a 10 min." : '';
    $moxaList = $moxaUse ? mtc_points_text(array_keys($mp['moxa'])) : '';
    $cautions = array_values(array_unique(array_merge($t['tuina']['cautions'], $t['qigong']['cautions'], $t['moxa']['cautions'], $t['ventosas']['cautions'], $t['auriculo']['cautions'])));
    $qig = $t['qigong']['sesiones'];
    $pressText = mtc_points_text(array_slice($t['tuina']['press'], 0, 4)) ?: mtc_point_label('E36');
    $withMoxa = static fn (array $p, string $head): string => $p['moxa'] ? $head . "\n" . mtc_moxa_lines($p, false)
        : 'Sin moxa: acupresión de ' . ($p['press'] ? mtc_points_text(array_keys($p['press'])) : $pressText) . ' en el tuina.';

    $sessions = [
        2 => [
            'objetivo' => 'Empezamos el tratamiento de lo que vimos en la primera consulta, sobre los canales de energía (meridianos) de ' . $meridianText
                . ($zoneLabel !== '' ? ', junto con la zona ' . $zoneLabel : '') . '. Combinamos tuina (masaje chino), '
                . ($moxaUse ? 'moxibustión (calor de artemisa) en los puntos elegidos' : 'presión en los puntos elegidos')
                . ' y semillas en la oreja (auriculoterapia), y te enseñamos un ejercicio de chi kung para hacer en casa. '
                . 'Al comenzar hacemos un control breve: cómo pasaste la semana y cómo está tu molestia de 0 a 10.',
            'puntos' => $moxaUse || $mp['press']
                ? trim(($moxaUse ? $pointsHead . "\n" : '') . mtc_moxa_lines($mp) . $ashiLine)
                : 'Sin moxa' . ($ctx['fever'] ? ' (fiebre o calor agudo)' : ' (el diagnóstico tiene calor o Yang que asciende)')
                    . '. Acupresión en el tuina, 1 a 2 min por punto: ' . (mtc_points_text($t['tuina']['press']) ?: 'puntos del plan') . '.',
            'tecnica' => implode("\n", array_filter([
                $control,
                $t['tuina']['summary'],
                $t['auriculo']['summary'] . ' Hoy: oreja derecha; enseñar a presionar.',
                'Chi kung: enseñar ' . $qig[0] . '.',
                $cupsUse ? 'Ventosas: desde la consulta 3.' : $t['ventosas']['summary'],
                $cautions ? 'Cuidados: ' . implode(' ', $cautions) : '',
            ])),
        ],
        3 => [
            'objetivo' => 'Mantenemos la base de la consulta anterior y la ajustamos según cómo respondiste. '
                . ($cupsUse ? 'Sumamos ventosas (copas de succión suave) en ' . $cupsPlain . ' para mover la circulación y soltar tensión. ' : '')
                . 'Cambiamos las semillas de oreja y sumamos un ejercicio de chi kung.',
            'puntos' => $moxaUse
                ? 'Puntos para moxar: los mismos de la consulta 2 (' . $moxaList . '), ajustando según la respuesta.'
                : 'Acupresión en los mismos puntos de la consulta 2, ajustando según la respuesta.',
            'tecnica' => implode("\n", [
                $control,
                $t['ventosas']['summary'],
                'Tuina: igual que la consulta 2, ajustando según la respuesta.',
                'Auriculoterapia: semillas nuevas en la oreja izquierda (misma fórmula, ajustada).',
                'Chi kung: revisar la práctica en casa y sumar ' . ($qig[1] ?? 'la secuencia completa para casa') . '.',
            ]),
        ],
        4 => [
            'objetivo' => 'Consolidamos lo logrado: menos puntos y más precisos para fortalecer la raíz del desequilibrio, con tuina, '
                . ($moxaUse ? 'moxa' : 'presión en los puntos') . ($cupsUse ? ', ventosas si te hicieron bien' : '')
                . ' y semillas en la oreja. Revisamos cómo te sale el chi kung en casa.',
            'puntos' => $withMoxa($rootPlan, 'Puntos para moxar en la raíz:')
                . ($zone && $zone['local'] ? "\nLocales solo si persiste la molestia: " . mtc_points_text(mtc_filter_points($zone['local'], $ctx['pregnant'])) . '.' : ''),
            'tecnica' => implode("\n", [
                $control,
                'Tuina focalizado en la raíz (10 a 15 min), con acupresión de ' . (mtc_points_text(array_slice($t['tuina']['press'], 0, 3)) ?: 'los puntos principales') . '.',
                $cupsUse ? 'Ventosas solo si en la consulta 3 le hicieron bien: ' . $t['ventosas']['zones'][0] . '.' : $t['ventosas']['summary'],
                'Auriculoterapia: oreja derecha.',
                'Chi kung: corregir la práctica en casa.',
            ]),
        ],
        5 => [
            'objetivo' => 'Hacemos el tratamiento final de este primer bloque junto con una tonificación general, comparamos cómo estás con la primera consulta '
                . 'y te dejamos por escrito tu práctica de chi kung y las semillas para casa. '
                . 'Decidimos juntos cómo seguir: continuar con controles semanales hasta completar 25 consultas, alta, o derivación médica si hiciera falta.',
            'puntos' => $withMoxa($rootPlan, 'Tratamiento final (moxa):') . "\n"
                . ($closePlan['moxa'] ? 'Tonificación general: ' . implode('; ', array_map(static fn ($c, $m) => mtc_point_label($c) . ' (' . $m . ')', array_keys($closePlan['moxa']), $closePlan['moxa'])) . '.' : 'Tonificación general con acupresión de Zusanli (E36).'),
            'tecnica' => implode("\n", [
                $control,
                'Comparar con la escala de la consulta 1' . ($diag['escala'] !== null ? ' (' . $diag['escala'] . '/10)' : '') . '. Foto de la lengua para comparar.',
                'Tuina de cierre y tonificación general en la espalda (15 min).',
                'Auriculoterapia: oreja izquierda; indicar si sigue con semillas en casa.',
                'Chi kung: dejar por escrito la práctica para casa (' . $t['qigong']['min'] . ' minutos por día).',
                'Registrar la decisión.',
            ]),
        ],
    ];

    $resumen = [];
    $motivo = $diag['motivo'];
    $resumen[] = 'En la primera consulta hablamos de tu motivo de consulta' . ($motivo !== '' ? ' (' . rtrim(mb_strtolower(mb_substr($motivo, 0, 1)) . mb_substr($motivo, 1), '. ') . ')' : '')
        . ', observamos en detalle tu lengua (glosodiagnosis), repasamos tu salud con preguntas y realizamos una tonificación general.';
    $resumen[] = 'Lo que encontramos, en palabras simples:';
    foreach ($chosen as $p) {
        $resumen[] = '- ' . ($p['label'] === 'Dolor musculoesquelético' && $zoneLabel !== ''
            ? 'Dolor musculoesquelético (zona ' . $zoneLabel . '): trabajamos la zona y los canales de energía (meridianos) que la recorren.'
            : $p['plain']);
    }
    if ($otro !== '') {
        $resumen[] = '- ' . $otro;
    }
    if (!$chosen && $otro === '') {
        $resumen[] = '- (completar)';
    }
    $used = array_values(array_filter([
        'tuina (masaje chino)', $moxaUse ? 'moxibustión' : '', $cupsUse ? 'ventosas' : '', 'semillas en la oreja', 'chi kung',
    ]));
    $resumen[] = 'El tratamiento no usa agujas: combina ' . implode(', ', array_slice($used, 0, -1)) . ' y ' . end($used) . '.';
    if ($diag['escala'] !== null) {
        $resumen[] = 'Hoy tu molestia principal' . ($diag['sintoma'] !== '' ? ' (' . $diag['sintoma'] . ')' : '') . ' está en ' . $diag['escala']
            . ' de 10. La vamos a volver a medir en cada control semanal para ver cómo avanzás.';
    }

    $recs = [
        'Tomá agua durante el día y evitá comidas pesadas justo antes y después de cada sesión.',
        'Anotá cómo te sentís durante la semana para contarlo en el control.',
    ];
    $ind = mtc_indications_for(array_keys($chosen));
    $indLines = array_map(static fn ($i) => $i['text'] . ($i['caution'] !== '' ? ' ' . $i['caution'] : ''), array_values($ind['auto']));
    $stems = static function (string $s): array {
        static $stop = ['evit', 'trat', 'redu', 'suma', 'pref', 'deja', 'cada', 'para', 'como', 'todo', 'toda', 'dias', 'vece', 'desp',
            'ante', 'much', 'poco', 'meno', 'mejo', 'siem', 'nunc', 'hast', 'esta', 'esto', 'tamb', 'pued', 'hace', 'haci', 'sobr', 'con'];
        $s = strtr(mb_strtolower($s), ['á' => 'a', 'é' => 'e', 'í' => 'i', 'ó' => 'o', 'ú' => 'u']);
        preg_match_all('/[a-zñ]{4,}/u', $s, $m);
        return array_values(array_diff(array_unique(array_map(static fn ($w) => mb_substr($w, 0, 4), $m[0])), $stop));
    };
    $seen = [];
    foreach ($indLines as $have) {
        $seen += array_flip($stems($have));
    }
    foreach ($chosen as $p) {
        foreach ($p['recs'] as $r) {
            $mine = $stems($r);
            if ($mine && count(array_intersect_key(array_flip($mine), $seen)) / count($mine) >= 0.6) {
                continue;
            }
            $indLines[] = $r;
            $seen += array_flip($mine);
        }
    }

    $evidence = mtc_pattern_evidence($plan);
    $protocol = [];
    $protocol[] = ['Base del diagnóstico', 'Glosodiagnosis e información del paciente'
        . ($diag['fuentes'] !== '' ? '; teoría consultada: ' . $diag['fuentes'] : '') . '. El pulso no se usa para proponer patrones.'];
    $tongue = mtc_tongue_summary($diag);
    $protocol[] = ['Lengua', $tongue !== '' ? $tongue . '.' : 'Sin datos de la lengua cargados: completá la glosodiagnosis en la consulta 1.'];
    foreach ($chosen as $key => $p) {
        $ev = mtc_evidence_text($evidence[$key] ?? ['lengua' => [], 'interrogatorio' => []]);
        $protocol[] = ['Patrón', $p['label'] . ($p['principle'] !== '' ? ' (principio: ' . mb_strtolower($p['principle']) . ')' : '')
            . '. Por qué: ' . ($ev !== '' ? $ev : 'lo elegiste vos; no hay señales cargadas en la lengua ni en el interrogatorio que lo apoyen') . '.'];
    }
    $others = array_diff_key(mtc_suggested_patterns($plan), $chosen);
    if ($others) {
        $protocol[] = ['Sugerencia', 'La lengua y el interrogatorio también apuntan a: '
            . implode('; ', array_map(static fn ($k, $m) => $all[$k]['label'] . ' (' . mtc_evidence_text($m) . ')', array_keys($others), $others))
            . '. Si corresponde, sumalo en la consulta 1.'];
    }
    if ($otro !== '') {
        $protocol[] = ['Otro patrón', $otro . ' (sin plantilla propia: completá los puntos a mano).'];
    }
    if (!$chosen && $otro === '') {
        $protocol[] = ['Sin patrón', 'No elegiste ningún patrón: el plan queda genérico. Volvé a la consulta 1 para elegirlo.'];
    }
    if ($zone) {
        $protocol[] = ['Zona', $zone['label'] . ($zone['local'] ? ': locales ' . mtc_points_text($zone['local']) . '; distales ' . mtc_points_text($zone['distal'])
            . ' (meridianos ' . $zone['meridian'] . ')' : ': locales y distales del meridiano que recorre la zona') . '.'];
    }
    if ($ctx['pregnant']) {
        $protocol[] = ['Filtro embarazo', 'sin moxa ni acupresión en ' . mtc_points_text(MTC_PREGNANCY_AVOID) . '; sin abdomen ni zona lumbosacra.'];
    }
    $protocol[] = ['Controles 6 a 25', mtc_control_guide($plan)];
    $protocol[] = ['Estructura', 'consulta 1 = diagnóstico y tonificación (ya hecha); consultas 2 a 5 = primer bloque; 6 a 25 = controles semanales con re-evaluación en la 10, 15, 20 y 25.'];

    return [
        'protocol' => $protocol,
        'techniques' => $t,
        'sessions' => $sessions,
        'resumen' => implode("\n", $resumen),
        'recomendaciones' => implode("\n", array_map(static fn ($r) => '- ' . $r, $recs)),
        'casa' => mtc_home_text($t, $ctx),
        'diagnostico' => mtc_compose_diagnosis($plan),
        'indicaciones' => implode("\n", array_map(static fn ($r) => '- ' . $r, array_values(array_unique($indLines)))),
        'ind_offer' => $ind['offer'],
    ];
}

/** Indicaciones del catálogo que no se tildaron solas, elegidas en la vista previa ($keys), como líneas «- texto». */
function mtc_indication_lines(array $keys): string
{
    $cat = mtc_catalog_indications();
    $lines = [];
    foreach ($keys as $k) {
        if (is_string($k) && isset($cat[$k])) {
            $lines[] = '- ' . $cat[$k]['text'] . ($cat[$k]['caution'] !== '' ? ' ' . $cat[$k]['caution'] : '');
        }
    }
    return implode("\n", $lines);
}

/** Lista «- a\n- b» (o párrafos sueltos) → ítems sin el guion. */
function mtc_list_items(string $text): array
{
    $items = [];
    foreach (explode("\n", $text) as $line) {
        $line = trim($line);
        if ($line === '') {
            continue;
        }
        if (preg_match('/^[-•➢*]\s*(.*)$/u', $line, $m) || !$items) {
            $items[] = $m[1] ?? $line;
        } else {
            $items[count($items) - 1] .= ' ' . $line;
        }
    }
    return $items;
}

function mtc_apply_generated(array $plan): array
{
    if (mtc_is_default_tonify($plan['diag']['tonificacion'])) {
        $plan['diag']['tonificacion'] = mtc_default_tonify(mtc_pregnant($plan), mtc_context($plan));
    }
    $gen = mtc_generate($plan);
    foreach ($gen['sessions'] as $n => $texts) {
        $plan['sessions'][$n] = $texts + $plan['sessions'][$n];
    }
    if ($plan['diag']['diagnostico'] === '' || $plan['diag']['diagnostico'] === $plan['diag']['diagnostico_auto']) {
        $plan['diag']['diagnostico'] = $gen['diagnostico'];
    }
    $plan['diag']['diagnostico_auto'] = $gen['diagnostico'];
    $plan['patient']['resumen'] = $gen['resumen'];
    $plan['patient']['recomendaciones'] = $gen['recomendaciones'];
    $plan['patient']['casa'] = $gen['casa'];
    $plan['patient']['indicaciones'] = $gen['indicaciones'];
    $plan['generated'] = true;
    $plan['template'] = MTC_TEMPLATE;
    return $plan;
}

/* ---------- Fotos de la lengua: privadas en data/ («Deny from all»), se ven solo con plan_mtc_foto.php (admin) ---------- */

const MTC_PHOTO_MAX = 10 * 1024 * 1024;

function mtc_photo_dir(): string
{
    return dirname(__DIR__) . '/data/mtc_fotos';
}

function mtc_photo_path(int $planId, int $n): ?string
{
    $f = mtc_photo_dir() . '/plan-' . $planId . '-c' . $n . '.jpg';
    return is_file($f) ? $f : null;
}

/** Consultas del plan con foto: [número de consulta => ruta]. */
function mtc_photos(int $planId): array
{
    $out = [];
    foreach (glob(mtc_photo_dir() . '/plan-' . $planId . '-c*.jpg') ?: [] as $f) {
        if (preg_match('/-c(\d+)\.jpg$/', $f, $m)) {
            $out[(int) $m[1]] = $f;
        }
    }
    ksort($out);
    return $out;
}

/**
 * Guarda la foto de la lengua de una consulta como JPEG re-codificado: sin EXIF ni otros metadatos, girado según la orientación
 * y de 1600 px como máximo. Acepta JPEG, PNG, WebP y HEIC (HEIC solo si el servidor tiene Imagick con HEIC).
 * Devuelve null si salió bien, o el motivo del error.
 */
function mtc_photo_store(int $planId, int $n, array $file): ?string
{
    $err = (int) ($file['error'] ?? UPLOAD_ERR_NO_FILE);
    if (in_array($err, [UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE], true) || (int) ($file['size'] ?? 0) > MTC_PHOTO_MAX) {
        return 'la foto pesa demasiado (máximo 10 MB).';
    }
    if ($err !== UPLOAD_ERR_OK || !is_uploaded_file((string) ($file['tmp_name'] ?? ''))) {
        return 'no se pudo subir la foto.';
    }
    if (!function_exists('imagecreatefromstring')) {
        return 'el servidor no puede procesar imágenes.';
    }
    $tmp = (string) $file['tmp_name'];
    $mime = (string) ((new finfo(FILEINFO_MIME_TYPE))->file($tmp) ?: '');
    $img = false;
    if (in_array($mime, ['image/heic', 'image/heif'], true)) {
        if (!class_exists('Imagick')) {
            return 'el servidor no puede convertir fotos HEIC: sacala en JPG (iPhone: Ajustes › Cámara › Formatos › Más compatible).';
        }
        try {
            $im = new Imagick($tmp);
            $im->setImageFormat('jpeg');
            $img = @imagecreatefromstring($im->getImageBlob());
            $im->clear();
        } catch (Throwable) {
            return 'no se pudo leer la foto HEIC.';
        }
    } elseif (in_array($mime, ['image/jpeg', 'image/png', 'image/webp'], true)) {
        $img = @imagecreatefromstring((string) file_get_contents($tmp));
    } else {
        return 'formato no admitido (usá JPG o PNG).';
    }
    if (!$img) {
        return 'no se pudo leer la imagen.';
    }
    if ($mime === 'image/jpeg' && function_exists('exif_read_data')) {
        $exif = @exif_read_data($tmp);
        $angle = [3 => 180, 6 => -90, 8 => 90][(int) ($exif['Orientation'] ?? 1)] ?? 0;
        if ($angle !== 0) {
            $img = imagerotate($img, $angle, 0) ?: $img;
        }
    }
    $w = imagesx($img);
    $h = imagesy($img);
    $scale = min(1, 1600 / max($w, $h));
    $out = imagecreatetruecolor(max(1, (int) round($w * $scale)), max(1, (int) round($h * $scale)));
    imagefill($out, 0, 0, imagecolorallocate($out, 255, 255, 255));
    imagecopyresampled($out, $img, 0, 0, 0, 0, imagesx($out), imagesy($out), $w, $h);
    $dir = mtc_photo_dir();
    if (!is_dir($dir) && !@mkdir($dir, 0750, true)) {
        return 'no se pudo crear la carpeta de fotos.';
    }
    if (!is_file($dir . '/.htaccess')) {
        file_put_contents($dir . '/.htaccess', "Deny from all\n");
    }
    $dest = $dir . '/plan-' . $planId . '-c' . $n . '.jpg';
    if (!imagejpeg($out, $dest . '.tmp', 85) || !rename($dest . '.tmp', $dest)) {
        @unlink($dest . '.tmp');
        return 'no se pudo guardar la foto.';
    }
    @chmod($dest, 0640);
    return null;
}

/** Fotos del formulario del plan: foto[n] (archivo) y foto_borrar[n] = '1'. Devuelve [cantidad guardada, errores]. */
function mtc_photo_uploads(int $planId, array $files, array $delete): array
{
    foreach ($delete as $n => $v) {
        if ($v === '1' && (int) $n >= 1 && (int) $n <= MTC_TOTAL) {
            mtc_photo_delete($planId, (int) $n);
        }
    }
    $f = $files['foto'] ?? null;
    if (!is_array($f) || !is_array($f['name'] ?? null)) {
        return [0, []];
    }
    $saved = 0;
    $errors = [];
    foreach (array_keys($f['name']) as $n) {
        $err = (int) ($f['error'][$n] ?? UPLOAD_ERR_NO_FILE);
        if ($err === UPLOAD_ERR_NO_FILE || (int) $n < 1 || (int) $n > MTC_TOTAL) {
            continue;
        }
        $e = mtc_photo_store($planId, (int) $n, ['tmp_name' => $f['tmp_name'][$n] ?? '', 'error' => $err, 'size' => $f['size'][$n] ?? 0]);
        if ($e === null) {
            $saved++;
        } else {
            $errors[] = 'Foto de la consulta ' . (int) $n . ': ' . $e;
        }
    }
    return [$saved, $errors];
}

function mtc_photo_delete(int $planId, int $n): void
{
    $f = mtc_photo_path($planId, $n);
    if ($f !== null) {
        @unlink($f);
    }
}

/** Borra un plan con sus versiones, los vínculos con turnos (los turnos quedan) y sus fotos. Usar esto para borrar planes (también desde Pacientes). */
function mtc_delete_plan(int $planId): void
{
    $pdo = db();
    $pdo->beginTransaction();
    foreach (['DELETE FROM mtc_plan_versions WHERE plan_id = ?', 'DELETE FROM mtc_plan_visits WHERE plan_id = ?', 'DELETE FROM mtc_plans WHERE id = ?'] as $sql) {
        $pdo->prepare($sql)->execute([$planId]);
    }
    $pdo->commit();
    foreach (mtc_photos($planId) as $f) {
        @unlink($f);
    }
}

/** Borra fotos de planes que ya no existen (por ejemplo, borrados desde otro módulo). */
function mtc_photo_cleanup(): void
{
    $files = glob(mtc_photo_dir() . '/plan-*-c*.jpg') ?: [];
    if (!$files) {
        return;
    }
    $ids = array_flip(array_map('intval', db()->query('SELECT id FROM mtc_plans')->fetchAll(PDO::FETCH_COLUMN)));
    foreach ($files as $f) {
        if (preg_match('/plan-(\d+)-c\d+\.jpg$/', $f, $m) && !isset($ids[(int) $m[1]])) {
            @unlink($f);
        }
    }
}

/** Fecha de una consulta: la del turno agendado, o la anotada a mano. */
function mtc_consult_date(int $n, array $plan, array $appt, array $visits): string
{
    if ($n === 1) {
        return (string) $appt['date'];
    }
    if (isset($visits[$n])) {
        return (string) $visits[$n]['date'];
    }
    return (string) ($n <= MTC_BLOCK ? $plan['sessions'][$n]['fecha'] : $plan['controls'][$n]['fecha']);
}

/** $template = versión con la que se generó el plan (los planes viejos conservan su texto, que hablaba de agujas). */
function mtc_frequency_text(int $template = MTC_TEMPLATE): string
{
    return ($template >= 2
            ? 'Las sesiones son semanales, de 50 a 60 minutos, y combinan, según lo que necesites en cada etapa, tuina (masaje chino), moxibustión, ventosas, auriculoterapia con semillas y chi kung: no usamos agujas. '
            : 'Las sesiones son semanales, de 50 a 60 minutos (las agujas quedan colocadas entre 20 y 30 minutos). ')
        . 'La primera etapa del tratamiento es de hasta 25 consultas semanales: las consultas 1 a 5 forman el primer bloque que te detallamos abajo, '
        . 'y desde la consulta 6 seguimos con controles y tratamiento cada semana. Re-evaluamos cómo vas en la consulta 5 y después cada 5 consultas '
        . '(10, 15, 20 y 25). Si alcanzamos los objetivos antes, podés recibir el alta antes de completar las 25.';
}

function mtc_disclaimer(): string
{
    return 'Este plan es un acompañamiento desde la Medicina Tradicional China y no reemplaza el diagnóstico ni el tratamiento médico. '
        . 'No suspendas ni cambies tu medicación sin hablar con tu médico. Si aparecen síntomas nuevos o empeoran, consultá a tu médico y avisanos.';
}

/** Renglones "- algo" → lista; el resto, párrafos. */
function mtc_lines(string $text): array
{
    $out = [];
    foreach (explode("\n", $text) as $line) {
        $line = trim($line);
        if ($line !== '') {
            $out[] = preg_match('/^[-•*]\s*(.+)$/u', $line, $m) ? ['item', $m[1]] : ['p', $line];
        }
    }
    return $out;
}

function mtc_pdf_text(MtcPdf $pdf, string $text, float $size = 10.5): void
{
    foreach (mtc_lines($text) as [$type, $line]) {
        $pdf->paragraph([[$line, 'r']], $type === 'item' ? 'dot' : '', 0, $size);
    }
}

function mtc_pdf_heading(MtcPdf $pdf, string $text): void
{
    $pdf->heading($text);
}

function mtc_date_label(string $ymd): string
{
    return $ymd !== '' ? format_date_es($ymd) : '';
}

/** Por qué una técnica no se usa, en palabras para el paciente. */
function mtc_skip_reason(string $key, array $plan): string
{
    $ctx = mtc_context($plan);
    $hot = array_filter(mtc_chosen_patterns($plan), static fn ($p) => $p['moxa_mode'] === 'no');
    $why = match ($key) {
        'moxa' => [$ctx['fever'] ? 'hay fiebre o calor' : '', $hot && !$ctx['fever'] ? 'en tu diagnóstico hay calor interno y la moxa suma calor' : ''],
        'ventosas' => [$ctx['anticoag'] ? 'tomás anticoagulantes' : '', $ctx['fever'] ? 'hay fiebre o calor' : '',
            array_filter(mtc_chosen_patterns($plan), static fn ($p) => !empty($p['no_cups'])) ? 'hay fragilidad de los vasos chiquitos de la piel' : ''],
        default => [],
    };
    $why = array_values(array_filter($why));
    return $why ? implode(' y ', $why) : 'lo vamos a evaluar en cada consulta';
}

/**
 * PDF para el paciente con el modelo «INDICACIONES»: hoja con nombre y fecha, recuadro «Diagnóstico desde la Medicina Tradicional China»
 * e indicaciones con ➢; después el plan (consultas, técnicas, práctica en casa). Sin pulso, notas internas ni técnica del terapeuta
 * (salvo «incluir puntos»). Nunca incluye fotos.
 */
function mtc_plan_pdf(array $plan, array $appt, array $visits): string
{
    global $config;
    $name = (string) $appt['patient_name'];
    $pdf = new MtcPdf();
    $pdf->setFooter('FluxusTerapia · Plan de Medicina Tradicional China · ' . $name);
    $pdf->setHeader('Medicina Tradicional China');
    $modern = $plan['template'] >= 2;
    $items = mtc_list_items($plan['patient']['indicaciones']);
    $first = format_date_es((string) $appt['date']);

    if ($modern || $items) {
        $ts = strtotime((string) $appt['date']);
        $pdf->sheetTitle('INDICACIONES', $name, $ts ? date('d - m - Y', $ts) : '');
        $pdf->diagnosisBox('DIAGNÓSTICO DESDE LA MEDICINA TRADICIONAL CHINA', mtc_diagnosis_line($plan));
        foreach ($items as $item) {
            $pdf->paragraph([[$item, 'r']], 'arrow', 0, 11);
        }
        if (!$items) {
            $pdf->note('Las indicaciones para tu día a día te las damos en la próxima consulta.');
        }
        $pdf->addPage();
    }

    $pdf->paragraph([['Tu plan de tratamiento', 'sb']], '', 0, 20);
    $pdf->paragraph([['Hola, ' . $name . ':', 'b']], '', 0, 11);
    $pdf->paragraph([['Te compartimos el plan que armamos a partir de tu primera consulta del ' . mb_strtolower(mb_substr($first, 0, 1)) . mb_substr($first, 1)
        . '. Lo vamos ajustando semana a semana según cómo te sientas.', 'r']]);

    mtc_pdf_heading($pdf, 'Lo que vimos en tu primera consulta');
    mtc_pdf_text($pdf, $plan['patient']['resumen']);

    mtc_pdf_heading($pdf, 'Cómo es el tratamiento');
    mtc_pdf_text($pdf, mtc_frequency_text($plan['template']));

    if ($modern) {
        mtc_pdf_heading($pdf, 'Las técnicas de tu tratamiento');
        $techs = mtc_techniques($plan);
        foreach (MTC_TECHNIQUES as $key => $info) {
            $pdf->keepTogether(76);
            $pdf->paragraph([[$info['label'], 'b'], [' · ' . $info['que'], 'r']], 'arrow', 0, 10.5);
            if (!$techs[$key]['use']) {
                $pdf->paragraph([['En tu caso no la usamos por ahora (' . mtc_skip_reason($key, $plan) . ').', 'r']], '', 16, 10);
                continue;
            }
            $pdf->paragraph([['Qué vas a sentir: ', 'b'], [$info['sentir'], 'r']], '', 16, 10);
            $pdf->paragraph([['Cuidados: ', 'b'], [$info['cuidados'], 'r']], '', 16, 10);
        }
    }

    mtc_pdf_heading($pdf, 'Primer bloque: consultas 1 a 5');
    $tonCtx = mtc_context($plan);
    for ($n = 1; $n <= MTC_BLOCK; $n++) {
        $date = mtc_date_label(mtc_consult_date($n, $plan, $appt, $visits));
        $pdf->keepTogether(64);
        $pdf->paragraph([['Consulta ' . $n . ' de ' . MTC_TOTAL . ' · ' . MTC_SESSION_TITLES[$n] . ($date !== '' ? ' (' . $date . ')' : ''), 'b']]);
        $text = $n === 1
            ? ($modern
                ? 'Evaluamos tu estado general con una observación detallada de tu lengua (glosodiagnosis) y preguntas sobre tu salud, y hacemos una tonificación general con '
                    . ($tonCtx['fever'] || $tonCtx['hotdx'] ? 'tuina, presión en los puntos' : 'moxa, tuina') . ' y semillas en la oreja. '
                : 'Evaluamos tu estado general (preguntas, observación de la lengua y pulso) y hacemos una tonificación general para equilibrar tu energía. ')
                . 'El diagnóstico se hace solo en esta consulta; en las siguientes hacemos un control breve al comenzar.'
            : $plan['sessions'][$n]['objetivo'];
        mtc_pdf_text($pdf, $text);
        if ($plan['patient']['include_points'] && $n > 1 && $plan['sessions'][$n]['puntos'] !== '') {
            foreach (mtc_lines($plan['sessions'][$n]['puntos']) as [$type, $line]) {
                $pdf->paragraph([[$line, 'r']], $type === 'item' ? 'dot' : '', 14, 9.5);
            }
        }
    }

    mtc_pdf_heading($pdf, 'Después de la consulta 5: controles semanales');
    $decision = $plan['patient']['decision'];
    mtc_pdf_text($pdf, 'En la consulta 5 decidimos juntos cómo seguir:');
    foreach (['continuar', 'alta', 'derivacion'] as $k) {
        $pdf->paragraph([[MTC_DECISIONS[$k] . ($k === 'derivacion' ? ', si hiciera falta.' : '.'), $decision === $k ? 'b' : 'r']], 'dot');
    }
    if ($decision !== '') {
        $pdf->paragraph([['Lo que decidimos: ', 'b'], [MTC_DECISIONS[$decision] . '.', 'r']]);
    }
    mtc_pdf_text($pdf, 'Si seguimos, cada semana hacemos un control breve (cómo estás de 0 a 10, cambios y reacciones) y el tratamiento de ese día, hasta completar 25 consultas. '
        . 'Si al terminar esta etapa hace falta continuar, empezamos una nueva con un nuevo diagnóstico.');
    $upcoming = [];
    $today = date('Y-m-d');
    foreach ($visits as $n => $v) {
        if ((string) $v['date'] >= $today) {
            $upcoming[] = 'Consulta ' . $n . ': ' . format_date_es((string) $v['date']) . ' · ' . format_time_es((string) $v['time']);
        }
    }
    if ($upcoming) {
        $pdf->paragraph([['Tus próximos turnos:', 'b']]);
        foreach ($upcoming as $u) {
            $pdf->paragraph([[$u, 'r']], 'dot');
        }
        $pdf->paragraph([['Lugar: ' . turno_location_of($appt)['label'], 'r']]);
    }

    if ($plan['patient']['casa'] !== '') {
        mtc_pdf_heading($pdf, 'Tu práctica en casa');
        mtc_pdf_text($pdf, $plan['patient']['casa']);
    }

    if ($plan['patient']['recomendaciones'] !== '') {
        mtc_pdf_heading($pdf, $modern ? 'Para los días de sesión' : 'Recomendaciones para casa');
        mtc_pdf_text($pdf, $plan['patient']['recomendaciones']);
    }

    $pdf->keepTogether(150);
    mtc_pdf_heading($pdf, 'Importante');
    mtc_pdf_text($pdf, mtc_disclaimer());
    $pdf->note('WhatsApp: +' . $config['whatsapp'] . ' · Web: ' . $config['site_url'] . ' · Gracias por confiar en FluxusTerapia.');
    return $pdf->render();
}

function mtc_pdf_name(array $appt): string
{
    return 'Plan-MTC-' . preg_replace('/[^A-Za-z0-9]+/', '', (string) $appt['code']) . '.pdf';
}

/** Manda el plan al mail del turno. Devuelve 'sent', 'no_email' o 'failed'. */
function mtc_send_plan(array $row, array $appt, array $visits): string
{
    global $config;
    $to = trim((string) $appt['patient_email']);
    if ($to === '' || !filter_var($to, FILTER_VALIDATE_EMAIL)) {
        return 'no_email';
    }
    $plan = $row['plan'];
    $diagLine = $plan['template'] >= 2 || $plan['patient']['indicaciones'] !== '' ? mtc_diagnosis_line($plan) : '';
    $indItems = mtc_list_items($plan['patient']['indicaciones']);
    $e = static fn ($v): string => htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8');
    $wa = 'https://wa.me/' . preg_replace('/\D+/', '', (string) $config['whatsapp']);
    $h2 = static fn (string $t): string => '<h2 style="margin:22px 0 8px;font-family:Georgia,serif;font-size:19px;color:#0f3d36">' . $t . '</h2>';
    $htmlText = static function (string $text) use ($e): string {
        $out = '';
        $list = false;
        foreach (mtc_lines($text) as [$type, $line]) {
            if ($type === 'item' && !$list) {
                $out .= '<ul style="margin:0 0 10px;padding-left:20px">';
                $list = true;
            } elseif ($type !== 'item' && $list) {
                $out .= '</ul>';
                $list = false;
            }
            $out .= $type === 'item' ? '<li style="margin:0 0 4px">' . $e($line) . '</li>' : '<p style="margin:0 0 10px">' . $e($line) . '</p>';
        }
        return $out . ($list ? '</ul>' : '');
    };

    $sessionsHtml = '<ol style="margin:0;padding-left:20px">';
    $sessionsText = '';
    for ($n = 1; $n <= MTC_BLOCK; $n++) {
        $date = mtc_date_label(mtc_consult_date($n, $plan, $appt, $visits));
        $sessionsHtml .= '<li style="margin:0 0 4px"><strong>' . $e(MTC_SESSION_TITLES[$n]) . '</strong>' . ($date !== '' ? ' · ' . $e($date) : '') . '</li>';
        $sessionsText .= $n . '. ' . MTC_SESSION_TITLES[$n] . ($date !== '' ? ' · ' . $date : '') . "\n";
    }
    $sessionsHtml .= '</ol>';

    $subject = 'Tu plan de tratamiento · Medicina Tradicional China · FluxusTerapia';
    $html = '<!DOCTYPE html><html lang="es"><head><meta charset="UTF-8"></head>'
        . '<body style="margin:0;padding:24px 12px;background:#eef3ef;font-family:Arial,sans-serif;color:#12201c">'
        . '<table role="presentation" width="100%" cellspacing="0" cellpadding="0"><tr><td align="center">'
        . '<table role="presentation" width="560" cellspacing="0" cellpadding="0" style="max-width:560px;background:#fff;border-radius:12px;overflow:hidden;border:1px solid rgba(15,61,54,.12)">'
        . '<tr><td style="background:#0f3d36;color:#fff;padding:20px 26px;font-family:Georgia,serif;font-size:24px">FluxusTerapia</td></tr>'
        . '<tr><td style="padding:26px;font-size:15px;line-height:1.6">'
        . '<p style="margin:0 0 6px;font-size:13px;letter-spacing:.12em;text-transform:uppercase;color:#2f8f6b;font-weight:700">Medicina Tradicional China</p>'
        . '<h1 style="margin:0 0 14px;font-family:Georgia,serif;font-size:26px">Hola, ' . $e($appt['patient_name']) . '</h1>'
        . '<p style="margin:0 0 12px;color:#4f635a">Te mandamos tu plan de tratamiento, armado a partir de tu primera consulta. Lo tenés completo en el PDF adjunto.</p>'
        . ($diagLine !== '' ? '<div style="margin:18px 0;padding:16px 18px;background:#fdf6e8;border:1px solid #d6ba91;border-radius:4px">'
            . '<p style="margin:0 0 8px;text-align:center;font-family:Georgia,\'Times New Roman\',serif;font-weight:700;font-size:15px;color:#2a1c17">DIAGNÓSTICO DESDE LA MEDICINA TRADICIONAL CHINA</p>'
            . '<p style="margin:0;font-family:Georgia,\'Times New Roman\',serif;font-style:italic;font-size:16px;color:#2a1c17">' . $e($diagLine) . '</p></div>' : '')
        . ($indItems ? $h2('Indicaciones') . '<ul style="margin:0 0 10px;padding-left:20px;list-style:\'➢  \'">'
            . implode('', array_map(static fn ($i) => '<li style="margin:0 0 6px">' . $e($i) . '</li>', $indItems)) . '</ul>' : '')
        . $h2('Lo que vimos') . $htmlText($plan['patient']['resumen'])
        . $h2('Cómo sigue') . '<p style="margin:0 0 10px">' . $e(mtc_frequency_text($plan['template'])) . '</p>'
        . '<p style="margin:0 0 6px"><strong>Primer bloque (consultas 1 a 5):</strong></p>' . $sessionsHtml
        . '<p style="margin:10px 0 0">' . $e(MTC_AFTER_BLOCK) . '</p>'
        . ($plan['patient']['casa'] !== '' ? $h2('Tu práctica en casa') . $htmlText($plan['patient']['casa']) : '')
        . ($plan['patient']['recomendaciones'] !== '' ? $h2('Para casa') . $htmlText($plan['patient']['recomendaciones']) : '')
        . '<p style="margin:18px 0;padding:12px 14px;background:#eef3ef;border-radius:8px;font-size:13px;color:#4f635a">' . $e(mtc_disclaimer()) . '</p>'
        . '<p style="margin:0 0 18px"><a href="' . $e($wa) . '" style="display:inline-block;background:#2f8f6b;color:#fff;text-decoration:none;padding:11px 18px;border-radius:6px;font-weight:700">Consultas por WhatsApp</a></p>'
        . '<p style="margin:0;color:#4f635a">Con cariño,<br><strong>FluxusTerapia</strong></p>'
        . '</td></tr></table></td></tr></table></body></html>';

    $text = 'Hola, ' . $appt['patient_name'] . "\n\n"
        . "Te mandamos tu plan de tratamiento de Medicina Tradicional China (completo en el PDF adjunto).\n\n"
        . ($diagLine !== '' ? "DIAGNÓSTICO DESDE LA MEDICINA TRADICIONAL CHINA\n" . $diagLine . "\n\n" : '')
        . ($indItems ? "INDICACIONES\n" . implode("\n", array_map(static fn ($i) => '> ' . $i, $indItems)) . "\n\n" : '')
        . "LO QUE VIMOS\n" . $plan['patient']['resumen'] . "\n\n"
        . "CÓMO SIGUE\n" . mtc_frequency_text($plan['template']) . "\n\nPrimer bloque (consultas 1 a 5):\n" . $sessionsText
        . MTC_AFTER_BLOCK . "\n\n"
        . ($plan['patient']['casa'] !== '' ? "TU PRÁCTICA EN CASA\n" . $plan['patient']['casa'] . "\n\n" : '')
        . ($plan['patient']['recomendaciones'] !== '' ? "PARA CASA\n" . $plan['patient']['recomendaciones'] . "\n\n" : '')
        . mtc_disclaimer() . "\n\nConsultas por WhatsApp: " . $wa . "\n\nFluxusTerapia\n";

    $ok = turno_send_mail($to, $subject, $html, $text, [mtc_pdf_name($appt) => mtc_plan_pdf($plan, $appt, $visits)]);
    if (!$ok) {
        return 'failed';
    }
    db()->prepare("UPDATE mtc_plans SET status = 'enviado', sent_at = ? WHERE id = ?")->execute([date('Y-m-d H:i:s'), (int) $row['id']]);
    return 'sent';
}

/** Primera consulta sin agendar y fecha sugerida (7 días después del último turno del plan, nunca en el pasado). */
function mtc_schedule_start(array $plan, array $appt, array $visits): array
{
    $next = max(2, mtc_done($plan) + 1, ($visits ? max(array_keys($visits)) : 0) + 1);
    $base = $visits ? (string) $visits[max(array_keys($visits))]['date'] : (string) $appt['date'];
    $date = (new DateTimeImmutable($base))->modify('+' . MTC_WEEK_DAYS . ' days');
    $tomorrow = new DateTimeImmutable('tomorrow');
    while ($date < $tomorrow) {
        $date = $date->modify('+' . MTC_WEEK_DAYS . ' days');
    }
    return [$next, $date];
}

/**
 * Crea los turnos semanales elegidos con la carga manual del admin, en el mismo lugar y terapia de la consulta 1.
 * $rows = [[consult_no, date, time], …]. Devuelve [creados, errores].
 */
function mtc_schedule(array $row, array $appt, array $rows, int $deposit): array
{
    $loc = turno_location_of($appt);
    $taken = array_keys(mtc_visits((int) $row['id']));
    $created = [];
    $errors = [];
    foreach ($rows as [$n, $date, $time]) {
        if ($n < 2 || $n > MTC_TOTAL || in_array($n, $taken, true)) {
            $errors[] = 'Consulta ' . $n . ': ya está agendada o fuera de las 25.';
            continue;
        }
        try {
            $new = create_manual_appointment([
                'name' => (string) $appt['patient_name'],
                'email' => (string) $appt['patient_email'],
                'phone' => (string) $appt['patient_phone'],
                'therapy_id' => (int) $appt['therapy_id'],
                'date' => $date,
                'time' => $time,
                'notes' => 'Plan MTC · consulta ' . $n . ' de ' . MTC_TOTAL,
                'deposit_amount' => $deposit,
                'location' => ['name' => $loc['name'], 'address' => $loc['address'], 'notes' => $loc['notes'], 'maps' => $loc['maps']],
            ]);
        } catch (RuntimeException $e) {
            $errors[] = 'Consulta ' . $n . ': ' . $e->getMessage();
            continue;
        }
        db()->prepare('INSERT INTO mtc_plan_visits (plan_id, appointment_id, consult_no) VALUES (?, ?, ?)')
            ->execute([(int) $row['id'], $new['id'], $n]);
        $taken[] = $n;
        $created[$n] = $new['id'];
    }
    return [$created, $errors];
}

/** Un solo mail con todas las fechas nuevas y un calendario con todos los turnos. */
function mtc_send_schedule_mail(array $appt, array $created): string
{
    global $config;
    $to = trim((string) $appt['patient_email']);
    if ($to === '' || !filter_var($to, FILTER_VALIDATE_EMAIL)) {
        return 'no_email';
    }
    $e = static fn ($v): string => htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8');
    $loc = turno_location_of($appt);
    $events = '';
    $itemsHtml = '';
    $itemsText = '';
    foreach ($created as $n => $id) {
        $a = turno_by_id((int) $id);
        if (!$a) {
            continue;
        }
        $when = format_date_es((string) $a['date']) . ' · ' . format_time_es((string) $a['time']);
        $itemsHtml .= '<li style="margin:0 0 4px">Consulta ' . (int) $n . ' de ' . MTC_TOTAL . ': <strong>' . $e($when) . '</strong></li>';
        $itemsText .= '- Consulta ' . $n . ' de ' . MTC_TOTAL . ': ' . $when . "\n";
        if (preg_match('/BEGIN:VEVENT.*END:VEVENT\r\n/s', turno_ics($a), $m)) {
            $events .= $m[0];
        }
    }
    if ($itemsText === '') {
        return 'failed';
    }
    $ics = "BEGIN:VCALENDAR\r\nVERSION:2.0\r\nPRODID:-//FluxusTerapia//Turnos//ES\r\nCALSCALE:GREGORIAN\r\nMETHOD:PUBLISH\r\n" . $events . "END:VCALENDAR\r\n";
    $wa = 'https://wa.me/' . preg_replace('/\D+/', '', (string) $config['whatsapp']);
    $subject = 'Tus próximos turnos semanales · Medicina Tradicional China · FluxusTerapia';
    $html = '<!DOCTYPE html><html lang="es"><head><meta charset="UTF-8"></head>'
        . '<body style="margin:0;padding:24px 12px;background:#eef3ef;font-family:Arial,sans-serif;color:#12201c">'
        . '<table role="presentation" width="100%" cellspacing="0" cellpadding="0"><tr><td align="center">'
        . '<table role="presentation" width="560" cellspacing="0" cellpadding="0" style="max-width:560px;background:#fff;border-radius:12px;overflow:hidden;border:1px solid rgba(15,61,54,.12)">'
        . '<tr><td style="background:#0f3d36;color:#fff;padding:20px 26px;font-family:Georgia,serif;font-size:24px">FluxusTerapia</td></tr>'
        . '<tr><td style="padding:26px;font-size:15px;line-height:1.6">'
        . '<h1 style="margin:0 0 14px;font-family:Georgia,serif;font-size:26px">Hola, ' . $e($appt['patient_name']) . '</h1>'
        . '<p style="margin:0 0 10px">Te dejamos agendadas tus próximas sesiones semanales de ' . $e($appt['therapy_name']) . ':</p>'
        . '<ul style="margin:0 0 12px;padding-left:20px">' . $itemsHtml . '</ul>'
        . '<p style="margin:0 0 10px">Lugar: <strong>' . $e($loc['label']) . '</strong>'
        . ($loc['map_link'] !== '' ? ' · <a href="' . $e($loc['map_link']) . '" style="color:#0f3d36;font-weight:700">Cómo llegar</a>' : '') . '</p>'
        . '<p style="margin:0 0 18px">Adjuntamos un archivo para agregarlas todas a tu calendario. Si no podés venir, avisanos con al menos 24 horas de anticipación.</p>'
        . '<p style="margin:0 0 18px"><a href="' . $e($wa) . '" style="display:inline-block;background:#2f8f6b;color:#fff;text-decoration:none;padding:11px 18px;border-radius:6px;font-weight:700">Consultas por WhatsApp</a></p>'
        . '<p style="margin:0;color:#4f635a">Con cariño,<br><strong>FluxusTerapia</strong></p>'
        . '</td></tr></table></td></tr></table></body></html>';
    $text = 'Hola, ' . $appt['patient_name'] . "\n\nTe dejamos agendadas tus próximas sesiones semanales de " . $appt['therapy_name'] . ":\n"
        . $itemsText . "\nLugar: " . $loc['label'] . "\n" . ($loc['map_link'] !== '' ? 'Cómo llegar: ' . $loc['map_link'] . "\n" : '')
        . "\nAdjuntamos un archivo para agregarlas a tu calendario. Si no podés venir, avisanos con al menos 24 horas de anticipación.\n\n"
        . 'Consultas por WhatsApp: ' . $wa . "\n\nFluxusTerapia\n";
    return turno_send_mail($to, $subject, $html, $text, ['Turnos-MTC-' . preg_replace('/[^A-Za-z0-9]+/', '', (string) $appt['code']) . '.ics' => $ics]) ? 'sent' : 'failed';
}

/** Botón para la lista de turnos del admin: plan de la consulta 1 o turno agendado desde un plan. */
function mtc_admin_link(array $appt): string
{
    static $plans = null;
    static $visits = null;
    if ($plans === null) {
        mtc_schema();
        $plans = array_flip(array_map('intval', db()->query('SELECT appointment_id FROM mtc_plans')->fetchAll(PDO::FETCH_COLUMN)));
        $visits = [];
        foreach (db()->query('SELECT v.appointment_id, v.consult_no, p.appointment_id AS first_id FROM mtc_plan_visits v JOIN mtc_plans p ON p.id = v.plan_id')->fetchAll() as $v) {
            $visits[(int) $v['appointment_id']] = $v;
        }
    }
    $id = (int) $appt['id'];
    if (isset($visits[$id])) {
        return '<a class="btn ghost" href="plan_mtc.php?id=' . (int) $visits[$id]['first_id'] . '#consulta-' . (int) $visits[$id]['consult_no'] . '">Plan MTC · consulta '
            . (int) $visits[$id]['consult_no'] . ' de ' . MTC_TOTAL . '</a>';
    }
    if (isset($plans[$id])) {
        return '<a class="btn ghost" href="plan_mtc.php?id=' . $id . '">Plan MTC · consulta 1 de ' . MTC_TOTAL . '</a>';
    }
    if (mtc_is_therapy((string) ($appt['therapy_name'] ?? ''))) {
        return '<a class="btn ghost" href="plan_mtc.php?id=' . $id . '">Plan MTC (5 sesiones)</a>';
    }
    return '';
}

if (db_ready()) {
    mtc_schema();
}
