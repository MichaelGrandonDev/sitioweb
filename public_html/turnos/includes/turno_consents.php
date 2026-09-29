<?php

declare(strict_types=1);

/*
 * Consentimiento informado por terapia (tabla turno_consents; therapy_id 0 = consentimiento general).
 * El texto propio de una terapia se usa solo cuando está aprobado; mientras sea borrador se usa el general.
 */

/** Consentimiento general: el texto genérico anterior a 2026-09, con los datos del paciente. */
const TURNO_CONSENT_GENERAL = <<<'TXT'
- CONSENTIMIENTO INFORMADO -

Yo, {nombre}, RUT / DNI N° {documento}, e-mail {email}; mediante la presente declaro que hoy {fecha}, en relación con la terapia que voy a recibir en FluxusTerapia, estoy consciente y acepto que:

PRIMERO: Fui informado/a de manera clara sobre la terapia que voy a recibir en FluxusTerapia (por ejemplo masoterapia, rehabilitación kinésica, técnicas de medicina tradicional china como acupuntura, ventosas o moxibustión, u otras prácticas complementarias): sus objetivos, cómo se realiza y su duración aproximada.

SEGUNDO: Entiendo que estas prácticas son complementarias y no reemplazan el diagnóstico ni el tratamiento médico. Me comprometo a continuar con las indicaciones de mi médico.

TERCERO: Conozco las posibles molestias o efectos transitorios: dolor o sensibilidad en la zona tratada, enrojecimiento, pequeños hematomas o marcas (sobre todo con ventosas), mareo leve, cansancio o somnolencia. En acupuntura puede haber un pequeño sangrado en el punto de punción; se usan agujas estériles y descartables.

CUARTO: Informé con veracidad mi estado de salud: enfermedades, medicación (en especial anticoagulantes), alergias, embarazo o posibilidad de estarlo, marcapasos u otros dispositivos, cirugías recientes, problemas de piel, diabetes, epilepsia u otras condiciones. Me comprometo a avisar cualquier cambio.

QUINTO: Puedo hacer todas las preguntas que necesite, pedir que se modifique o se detenga la sesión en cualquier momento y retirar este consentimiento cuando quiera.

SEXTO: Mis datos personales y de salud se tratan de forma confidencial y solo se usan para mi atención (Ley 25.326 de Protección de Datos Personales y Ley 26.529 de Derechos del Paciente).

SÉPTIMO: Leí y acepto las indicaciones previas a la sesión.

En conformidad con todo lo anterior, YO, {NOMBRE}, RUT / DNI N° {documento}, doy mi consentimiento libre y voluntario para recibir la terapia indicada.
TXT;

const TURNO_CONSENT_ORDINALS = ['PRIMERO', 'SEGUNDO', 'TERCERO', 'CUARTO', 'QUINTO', 'SEXTO', 'SÉPTIMO', 'OCTAVO', 'NOVENO', 'DÉCIMO', 'UNDÉCIMO', 'DUODÉCIMO'];

/** Tipo de consentimiento sugerido según el nombre de la terapia. */
function turno_consent_kind(string $therapyName): string
{
    return match (true) {
        (bool) preg_match('/china|mtc|acupunt|moxib/i', $therapyName) => 'mtc',
        (bool) preg_match('/online|virtual/i', $therapyName) => 'online',
        (bool) preg_match('/masot|masaje/i', $therapyName) => 'masoterapia',
        (bool) preg_match('/rehabilit|kinesi|kinési/iu', $therapyName) => 'rehabilitacion',
        (bool) preg_match('/hipopres/i', $therapyName) => 'hipopresivos',
        (bool) preg_match('/parto|embaraz|gestaci/i', $therapyName) => 'embarazo',
        default => 'otra',
    };
}

/** Arma un consentimiento con la misma estructura que el de acupuntura: título, introducción, cláusulas y aceptación. */
function turno_consent_compose(string $name, string $phrase, array $clauses, string $accept): string
{
    $lines = [
        '- CONSENTIMIENTO INFORMADO · ' . mb_strtoupper($name, 'UTF-8') . ' -',
        'Yo, {nombre}, RUT / DNI N° {documento}, e-mail {email}; mediante la presente declaro que hoy {fecha} he sido informado(a) de manera clara sobre '
            . $phrase . ' que voy a realizar en FluxusTerapia: en qué consiste, sus objetivos, indicaciones, contraindicaciones y posibles molestias o riesgos. '
            . 'Así mismo declaro en este acto estar consciente y aceptar que:',
    ];
    foreach (array_values($clauses) as $i => $clause) {
        $lines[] = (TURNO_CONSENT_ORDINALS[$i] ?? (string) ($i + 1)) . ': ' . $clause;
    }
    $lines[] = 'En conformidad con todo lo anterior, YO, {NOMBRE}, RUT / DNI N° {documento}, ACEPTO libre y voluntariamente ' . $accept
        . ', asumiendo las molestias y riesgos descriptos. Asimismo, me comprometo a entregar información veraz sobre mi estado de salud y a seguir las indicaciones del profesional a cargo.';
    return implode("\n\n", $lines);
}

/** Texto sugerido para una terapia. Medicina china: el consentimiento de acupuntura; el resto, un borrador adaptado. */
function turno_consent_suggestion(string $therapyName): string
{
    $name = trim($therapyName) !== '' ? trim($therapyName) : 'Terapia';
    $truthful = 'Informé con veracidad mi estado de salud: enfermedades, medicación (en especial anticoagulantes), alergias, embarazo o posibilidad de estarlo, '
        . 'marcapasos u otros dispositivos, cirugías o lesiones recientes, problemas de piel, presión arterial, diabetes, epilepsia u otras condiciones. '
        . 'Me comprometo a avisar cualquier cambio antes de cada sesión, y entiendo que ocultar o falsear esta información puede ponerme en riesgo.';
    $rights = 'Puedo hacer todas las preguntas que necesite, pedir que se modifique o se detenga la sesión en cualquier momento y retirar este consentimiento cuando quiera. '
        . 'Mis datos personales y de salud se tratan de forma confidencial y solo se usan para mi atención (Ley 25.326 de Protección de Datos Personales y Ley 26.529 de Derechos del Paciente).';
    $complementary = static fn (string $what): string => $what . ' es una práctica complementaria: no reemplaza el diagnóstico ni el tratamiento médico. '
        . 'Me comprometo a continuar con las indicaciones de mi médico y a consultarlo ante cualquier duda sobre mi salud. '
        . 'Los resultados dependen de cada persona y de la constancia, por lo que no se garantiza un resultado determinado.';

    return match (turno_consent_kind($name)) {
        'mtc' => TURNO_CONSENTIMIENTO_DEFAULT,
        'masoterapia' => turno_consent_compose($name, 'la ' . $name, [
            'La masoterapia es la aplicación de distintas técnicas de masaje manual (relajante, descontracturante, de tejido profundo, drenaje, entre otras) con fines de bienestar, alivio de tensiones musculares y mejora de la circulación.',
            'La sesión se realiza sobre una camilla, con el cuerpo cubierto salvo la zona a trabajar, usando las manos y, si corresponde, aceites o cremas. Puede complementarse con técnicas como ventosas, calor local o elongaciones. La presión se adapta a lo que yo indique y puedo pedir que se modifique en cualquier momento.',
            'Está indicada principalmente para contracturas y tensiones musculares, estrés, cansancio, dolores musculares leves y como complemento de otros tratamientos para mejorar el bienestar general.',
            'No debe realizarse sobre zonas con heridas, infecciones o enfermedades de la piel, inflamaciones agudas, fracturas o esguinces recientes, várices importantes, ni ante trombosis (o sospecha de ella), fiebre o malestar general. Requiere precaución o autorización médica en caso de embarazo, cáncer, problemas cardíacos o de presión arterial, trastornos de coagulación o uso de anticoagulantes, osteoporosis, diabetes, cirugías recientes o alergia a aceites o cremas.',
            'Aunque es una técnica segura, pueden aparecer molestias transitorias: dolor o sensibilidad muscular durante las 24 a 48 horas posteriores, enrojecimiento, pequeños hematomas o marcas (sobre todo con ventosas), mareo leve, cansancio o somnolencia, y reacciones alérgicas a los productos utilizados.',
            $complementary('La masoterapia'),
            $truthful,
            $rights,
        ], 'recibir sesiones de ' . $name),
        'rehabilitacion' => turno_consent_compose($name, 'el tratamiento de ' . $name, [
            'La rehabilitación kinésica es un tratamiento orientado a recuperar la movilidad, la fuerza y la función del cuerpo después de lesiones, cirugías o cuadros de dolor, y a prevenir recaídas.',
            'El tratamiento comienza con una evaluación y puede incluir ejercicios terapéuticos, terapia manual, movilizaciones, elongaciones y, según el caso, agentes físicos como calor, frío, electroestimulación o ultrasonido. También puede incluir ejercicios para realizar en casa, cuya constancia influye en los resultados.',
            'Está indicada principalmente para lesiones musculares, articulares, tendinosas o ligamentarias, recuperación después de cirugías o fracturas (con autorización médica), dolor lumbar o cervical, problemas posturales y recuperación funcional en general.',
            'No deben realizarse ejercicios o técnicas sobre fracturas no consolidadas o zonas operadas sin la autorización del médico tratante, ni ante infecciones, trombosis, fiebre o dolor agudo intenso sin diagnóstico. Requiere precaución en caso de embarazo, marcapasos u otros dispositivos (en especial con electroestimulación), cáncer, osteoporosis, problemas cardíacos o de presión arterial y alteraciones de la sensibilidad de la piel (con calor o frío).',
            'Pueden aparecer molestias transitorias: dolor o cansancio muscular después de la sesión, aumento pasajero del dolor al comenzar a mover la zona, enrojecimiento o irritación de la piel por los agentes físicos, mareo leve y, en raras ocasiones, quemaduras leves por calor o frío.',
            'El tratamiento kinésico se realiza a partir del diagnóstico y las indicaciones de mi médico tratante, y no reemplaza sus controles. Los resultados dependen de cada persona, de la lesión y de la constancia en el tratamiento, por lo que no se garantiza un resultado determinado.',
            $truthful,
            $rights,
        ], 'realizar el tratamiento de ' . $name),
        'hipopresivos' => turno_consent_compose($name, 'los ' . $name, [
            'La gimnasia abdominal hipopresiva es una técnica postural y respiratoria que busca disminuir la presión dentro del abdomen, tonificar la faja abdominal y el suelo pélvico y mejorar la postura.',
            'La práctica consiste en posturas y ejercicios de respiración que incluyen apneas espiratorias (sostener unos segundos sin aire después de exhalar) con apertura de las costillas, guiados por la profesional, en forma individual o grupal y siempre a mi propio ritmo.',
            'Está indicada principalmente para fortalecer el suelo pélvico y el abdomen, la recuperación después del parto (con alta médica), la incontinencia urinaria leve, la diástasis abdominal, la mejora postural y el dolor lumbar.',
            'No debe realizarse durante el embarazo, con hipertensión arterial no controlada, enfermedades cardíacas o respiratorias importantes, cirugía abdominal reciente sin alta médica ni hernias sin evaluación médica. Requiere precaución o consulta médica previa en el postparto (antes del alta), glaucoma, epilepsia u otras condiciones de salud.',
            'Durante las apneas pueden aparecer mareo, sensación de falta de aire, dolor de cabeza o cansancio, y después de la práctica, molestias musculares leves. En raras ocasiones puede producirse un desmayo. Ante cualquiera de estos síntomas debo detenerme y avisar.',
            $complementary('La gimnasia hipopresiva'),
            $truthful,
            $rights,
        ], 'participar en los ' . $name),
        'embarazo' => turno_consent_compose($name, 'el ' . $name, [
            'El curso es un programa de actividad física adaptado al embarazo y al postparto, orientado a mantener la condición física, preparar el cuerpo para el parto y acompañar la recuperación posterior.',
            'Las clases incluyen ejercicios de movilidad, fuerza, respiración, suelo pélvico, postura y relajación, de intensidad moderada y adaptados a la semana de gestación o a la etapa del postparto. Me comprometo a informar mi semana de gestación y los cambios que indiquen mis controles.',
            'Está indicado para embarazadas sin complicaciones y con autorización de su médico u obstetra, y para personas en el postparto con alta médica, como ayuda para aliviar dolores lumbares o pelvianos y recuperar la condición física.',
            'Necesito la autorización de mi médico u obstetra para participar. No debo realizar la actividad ante sangrado vaginal, pérdida de líquido, contracciones regulares o prematuras, placenta previa, riesgo de parto prematuro, preeclampsia o presión arterial alta, cuello uterino insuficiente, anemia severa o enfermedades cardíacas o pulmonares. En el postparto debo esperar el alta médica, en especial después de una cesárea.',
            'Pueden aparecer cansancio, molestias musculares o articulares (durante el embarazo los ligamentos están más laxos), mareo y pérdida de equilibrio con riesgo de caídas. Debo detenerme y avisar de inmediato ante dolor, sangrado, pérdida de líquido, contracciones, mareo, falta de aire, dolor de cabeza intenso o disminución de los movimientos del bebé.',
            'El curso no reemplaza los controles prenatales ni postparto con mi médico u obstetra, cuyas indicaciones tienen prioridad sobre cualquier ejercicio.',
            $truthful,
            $rights,
        ], 'participar en el ' . $name),
        'online' => turno_consent_compose($name, 'las clases de ' . $name, [
            'Las clases de ' . $name . ' son prácticas de movimiento suave, respiración y atención consciente de la tradición china, orientadas al bienestar físico y mental.',
            'Las clases se dictan en vivo por videollamada: practico desde mi casa o el lugar que elija, y la profesional guía y corrige a distancia, sin supervisión presencial. Soy responsable de preparar un espacio seguro (despejado, con piso firme y no resbaladizo, buena luz), de usar ropa cómoda, tener agua a mano y contar con una conexión adecuada.',
            'Están indicadas para mejorar la movilidad, el equilibrio, la postura y la respiración, reducir el estrés y favorecer el bienestar general. Los ejercicios pueden adaptarse, por ejemplo haciéndolos sentado/a.',
            'Debo consultar a mi médico antes de participar si tengo problemas cardíacos, presión arterial alta no controlada, mareos o vértigo, problemas de equilibrio, lesiones o cirugías recientes, osteoporosis o estoy embarazada. No debo forzar ningún movimiento y respeto mis límites en todo momento.',
            'Pueden aparecer cansancio o molestias musculares leves y mareo al cambiar de postura. Al practicar sin supervisión presencial existe riesgo de caídas o golpes relacionados con el espacio donde practico. Ante dolor, mareo o falta de aire debo detenerme y avisar.',
            $complementary('La práctica'),
            $truthful,
            'Puedo hacer todas las preguntas que necesite, pedir que se adapte un ejercicio, interrumpir mi participación en cualquier momento y retirar este consentimiento cuando quiera. '
                . 'Mis datos personales y de salud se tratan de forma confidencial y solo se usan para mi atención (Ley 25.326 de Protección de Datos Personales y Ley 26.529 de Derechos del Paciente).',
        ], 'participar en las clases de ' . $name),
        default => turno_consent_compose($name, 'la terapia ' . $name, [
            'Me explicaron en qué consiste ' . $name . ', sus objetivos, cómo se realiza y su duración aproximada.',
            'Conozco las posibles molestias o efectos transitorios de la práctica, como dolor o sensibilidad en la zona trabajada, cansancio, mareo leve o somnolencia, y sé que debo avisar si aparece cualquier síntoma durante o después de la sesión.',
            'Requiere precaución o consulta médica previa en caso de embarazo, enfermedades cardíacas o de presión arterial, trastornos de coagulación, lesiones o cirugías recientes u otras condiciones de salud.',
            $complementary('La práctica'),
            $truthful,
            $rights,
        ], 'realizar ' . $name),
    };
}

/** Crea la tabla y agrega un consentimiento para cada terapia que todavía no tiene (medicina china aprobado, el resto borrador). */
function turno_consents_migrate(PDO $pdo): void
{
    $pdo->exec("
      CREATE TABLE IF NOT EXISTS turno_consents (
        therapy_id INTEGER PRIMARY KEY,
        text TEXT NOT NULL,
        status TEXT NOT NULL DEFAULT 'draft',
        updated_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
        approved_at TEXT DEFAULT NULL
      )
    ");
    $now = date('Y-m-d H:i:s');
    $ins = $pdo->prepare('INSERT OR IGNORE INTO turno_consents (therapy_id, text, status, updated_at, approved_at) VALUES (?, ?, ?, ?, ?)');
    $ins->execute([0, TURNO_CONSENT_GENERAL, 'approved', $now, $now]);
    $missing = $pdo->query('SELECT id, name FROM therapies WHERE id NOT IN (SELECT therapy_id FROM turno_consents)')->fetchAll();
    foreach ($missing as $t) {
        $approved = turno_consent_kind((string) $t['name']) === 'mtc';
        $ins->execute([(int) $t['id'], turno_consent_suggestion((string) $t['name']), $approved ? 'approved' : 'draft', $now, $approved ? $now : null]);
    }

    // Una sola vez: el texto único que se había guardado desde el admin pasa al consentimiento que corresponde.
    $done = $pdo->query("SELECT value FROM deposit_settings WHERE key = 'consent_per_therapy'")->fetchColumn();
    if ($done !== '1') {
        $old = trim((string) $pdo->query("SELECT value FROM deposit_settings WHERE key = 'consentimiento_text'")->fetchColumn());
        if ($old !== '' && preg_match('/acupuntura|medicina tradicional china/iu', $old)) {
            $upd = $pdo->prepare('UPDATE turno_consents SET text = ?, updated_at = ? WHERE therapy_id = ?');
            foreach ($pdo->query('SELECT id, name FROM therapies')->fetchAll() as $t) {
                if (turno_consent_kind((string) $t['name']) === 'mtc') {
                    $upd->execute([$old, $now, (int) $t['id']]);
                }
            }
        } elseif ($old !== '') {
            $pdo->prepare('UPDATE turno_consents SET text = ?, updated_at = ? WHERE therapy_id = 0')->execute([$old, $now]);
        }
        $pdo->prepare("INSERT OR REPLACE INTO deposit_settings (key, value) VALUES ('consent_per_therapy', '1')")->execute();
    }
}

/** @return array<int, array{therapy_id: int, text: string, status: string, updated_at: string, approved_at: ?string}> */
function turno_consent_rows(): array
{
    $rows = [];
    foreach (db()->query('SELECT * FROM turno_consents')->fetchAll() as $row) {
        $rows[(int) $row['therapy_id']] = $row;
    }
    return $rows;
}

function turno_consent_general(): string
{
    $stmt = db()->prepare('SELECT text FROM turno_consents WHERE therapy_id = 0');
    $stmt->execute();
    $text = trim((string) $stmt->fetchColumn());
    return $text !== '' ? $text : TURNO_CONSENT_GENERAL;
}

/** Consentimiento propio de la terapia si está aprobado; si no, el general. */
function turno_consent_for_therapy(int $therapyId): string
{
    $stmt = db()->prepare("SELECT text FROM turno_consents WHERE therapy_id = ? AND status = 'approved'");
    $stmt->execute([$therapyId]);
    $text = trim((string) $stmt->fetchColumn());
    return $therapyId > 0 && $text !== '' ? $text : turno_consent_general();
}

function turno_consent_save(int $therapyId, string $text, ?string $status = null): void
{
    $text = trim(str_replace(["\r\n", "\r"], "\n", $text));
    if ($text === '') {
        throw new RuntimeException('El consentimiento no puede quedar vacío.');
    }
    if (mb_strlen($text) > 40000) {
        throw new RuntimeException('El consentimiento es demasiado largo.');
    }
    if ($therapyId > 0) {
        $stmt = db()->prepare('SELECT id FROM therapies WHERE id = ?');
        $stmt->execute([$therapyId]);
        if ($stmt->fetchColumn() === false) {
            throw new RuntimeException('Terapia no encontrada.');
        }
    }
    $current = turno_consent_rows()[$therapyId] ?? null;
    $status = $therapyId === 0 ? 'approved' : ($status ?? (string) ($current['status'] ?? 'draft'));
    $now = date('Y-m-d H:i:s');
    $approvedAt = $status === 'approved' ? ($current && $current['status'] === 'approved' && $current['text'] === $text ? $current['approved_at'] : $now) : null;
    db()->prepare('INSERT OR REPLACE INTO turno_consents (therapy_id, text, status, updated_at, approved_at) VALUES (?, ?, ?, ?, ?)')
        ->execute([$therapyId, $text, $status, $now, $approvedAt]);
}

/** Turno de ejemplo para la vista previa del consentimiento. */
function turno_consent_sample(int $therapyId): array
{
    $stmt = db()->prepare('SELECT id, name, duration_min FROM therapies WHERE id = ?');
    $stmt->execute([$therapyId]);
    $therapy = $stmt->fetch() ?: ['id' => 0, 'name' => 'Terapia de ejemplo', 'duration_min' => 60];
    return [
        'id' => 0,
        'code' => 'EJEMPLO',
        'token' => str_repeat('0', 32),
        'therapy_id' => (int) $therapy['id'],
        'therapy_name' => (string) $therapy['name'],
        'duration_min' => (int) $therapy['duration_min'],
        'date' => date('Y-m-d', strtotime('+1 day')),
        'time' => '10:00',
        'patient_name' => 'Nombre Apellido de Ejemplo',
        'patient_email' => 'paciente@ejemplo.com',
        'patient_phone' => '',
        'status' => 'confirmed',
        'consent_accepted_at' => null,
        'consent_text' => null,
    ];
}
