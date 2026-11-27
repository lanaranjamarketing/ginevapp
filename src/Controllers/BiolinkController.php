<?php
namespace App\Controllers;

use App\Config\Database;
use PDO;
use Exception;

class BiolinkController {

    private static function normalizarSlug(string $raw): string {
        $s = mb_strtolower(trim($raw), 'UTF-8');
        $mapa = [
            'á' => 'a', 'à' => 'a', 'ä' => 'a', 'â' => 'a',
            'é' => 'e', 'è' => 'e', 'ë' => 'e', 'ê' => 'e',
            'í' => 'i', 'ì' => 'i', 'ï' => 'i', 'î' => 'i',
            'ó' => 'o', 'ò' => 'o', 'ö' => 'o', 'ô' => 'o',
            'ú' => 'u', 'ù' => 'u', 'ü' => 'u', 'û' => 'u',
            'ñ' => 'n',
        ];
        $s = strtr($s, $mapa);
        $s = preg_replace('/[^a-z0-9\-]+/', '-', $s);
        $s = preg_replace('/-{2,}/', '-', $s);
        return trim($s, '-');
    }

    private static function normalizarWhatsapp(string $raw): string {
        $digitos = preg_replace('/\D/', '', $raw);
        if (strlen($digitos) === 11 && strpos($digitos, '0') === 0) {
            return '58' . substr($digitos, 1);
        }
        if (strlen($digitos) === 10 && strpos($digitos, '4') === 0) {
            return '58' . $digitos;
        }
        return $digitos;
    }

    private static function formatearHora12(string $hora24): string {
        $ts = strtotime(trim($hora24));
        if ($ts === false) {
            return $hora24;
        }
        return date('g:i a', $ts);
    }

    private static function columnasTabla(PDO $pdo, string $tabla): array {
        $stmt = $pdo->query("DESCRIBE `$tabla`");
        $cols = [];
        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $cols[] = $row['Field'];
        }
        return $cols;
    }

    private static function obtenerTasaBcvActual(PDO $pdo): float {
        foreach (['gm_tasa_bcv', 'gm_tasas_bcv'] as $tablaTasa) {
            try {
                $stmt = $pdo->query("SELECT * FROM `$tablaTasa` ORDER BY id DESC LIMIT 1");
                if ($stmt) {
                    $row = $stmt->fetch(PDO::FETCH_ASSOC);
                    if ($row) {
                        $val = floatval($row['tasa_usd'] ?? $row['tasa'] ?? $row['valor'] ?? $row['monto'] ?? 0);
                        if ($val > 0) {
                            return $val;
                        }
                    }
                }
            } catch (Exception $_) {}
        }
        return 1.0;
    }

    private static function resolverMedicoPorSlugOId(PDO $pdo, string $slugOId): ?array {
        $limpio = trim($slugOId);
        if ($limpio === '') {
            return null;
        }

        $slugNorm = self::normalizarSlug($limpio);
        if ($slugNorm !== '') {
            $stmtMeta = $pdo->prepare("
                SELECT medico_id FROM gm_medico_meta
                WHERE meta_key IN ('biolink_slug', 'slug')
                  AND LOWER(meta_value) = ?
                LIMIT 1
            ");
            $stmtMeta->execute([$slugNorm]);
            $rowMeta = $stmtMeta->fetch(PDO::FETCH_ASSOC);
            if ($rowMeta && intval($rowMeta['medico_id']) > 0) {
                $mid = intval($rowMeta['medico_id']);
                $stmtU = $pdo->prepare("SELECT * FROM gm_usuarios_extendidos WHERE user_id = ? OR id = ? LIMIT 1");
                $stmtU->execute([$mid, $mid]);
                $u = $stmtU->fetch(PDO::FETCH_ASSOC);
                if ($u) return $u;
            }
        }

        if (ctype_digit($limpio)) {
            $idNum = intval($limpio);
            $stmtU = $pdo->prepare("
                SELECT * FROM gm_usuarios_extendidos
                WHERE (user_id = ? OR id = ?)
                  AND rol_medico IN ('gm_medico', 'medico', 'admin')
                LIMIT 1
            ");
            $stmtU->execute([$idNum, $idNum]);
            $u = $stmtU->fetch(PDO::FETCH_ASSOC);
            if ($u) return $u;
        }

        $stmtAll = $pdo->query("
            SELECT * FROM gm_usuarios_extendidos
            WHERE rol_medico IN ('gm_medico', 'medico', 'admin')
        ");
        while ($row = $stmtAll->fetch(PDO::FETCH_ASSOC)) {
            $pref = (strtolower($row['sexo'] ?? '') === 'femenino') ? 'dra' : 'dr';
            $slugGen = self::normalizarSlug($pref . '-' . ($row['nombre'] ?? '') . '-' . ($row['apellido'] ?? ''));
            $uid = intval($row['user_id'] ?? $row['id'] ?? 0);
            if ($slugNorm === $slugGen || $slugNorm === "$pref-medico-$uid") {
                return $row;
            }
        }

        return null;
    }

    /**
     * GET /api/publico/biolink?slug=...
     */
    public static function obtenerBiolink() {
        try {
            $slug = trim($_GET['slug'] ?? $_GET['medico_id'] ?? '');
            if ($slug === '') {
                http_response_code(400);
                echo json_encode(["status" => "error", "mensaje" => "Slug o ID de especialista requerido."], JSON_UNESCAPED_UNICODE);
                return;
            }

            $pdo = Database::conectar();
            $medico = self::resolverMedicoPorSlugOId($pdo, $slug);

            if (!$medico) {
                http_response_code(404);
                echo json_encode(["status" => "error", "mensaje" => "No se encontró el perfil público solicitado."], JSON_UNESCAPED_UNICODE);
                return;
            }

            $medicoId = intval($medico['user_id'] ?? $medico['id'] ?? 0);
            $rowId    = intval($medico['id'] ?? $medicoId);
            $ids = array_values(array_unique(array_filter([$medicoId, $rowId], fn($v) => $v > 0)));
            $placeholders = implode(',', array_fill(0, count($ids), '?'));

            // 1. Metadatos
            $stmtMeta = $pdo->prepare("SELECT meta_key, meta_value FROM gm_medico_meta WHERE medico_id IN ($placeholders)");
            $stmtMeta->execute($ids);
            $metas = [];
            while ($m = $stmtMeta->fetch(PDO::FETCH_ASSOC)) {
                $metas[$m['meta_key']] = $m['meta_value'];
            }

            // 2. Calendarios y horarios por sede
            $stmtCal = $pdo->prepare("SELECT * FROM gm_calendarios WHERE medico_id IN ($placeholders) ORDER BY id ASC");
            $stmtCal->execute($ids);
            $calendariosRows = $stmtCal ? $stmtCal->fetchAll(PDO::FETCH_ASSOC) : [];

            $diasOrden = [
                'Monday'    => 'Lunes',
                'Tuesday'   => 'Martes',
                'Wednesday' => 'Miércoles',
                'Thursday'  => 'Jueves',
                'Friday'    => 'Viernes',
                'Saturday'  => 'Sábado',
                'Sunday'    => 'Domingo',
            ];

            $horariosPorSede = [];
            $todosLosHorarios = [];

            foreach ($calendariosRows as $cal) {
                $diasConf = json_decode($cal['dias_config'] ?? '{}', true);
                if (!is_array($diasConf)) continue;

                foreach ($diasOrden as $engKey => $espLabel) {
                    if (!isset($diasConf[$engKey]) || !is_array($diasConf[$engKey])) continue;
                    $info = $diasConf[$engKey];
                    $sedeNombre = trim((string)($info['sede'] ?? ''));
                    $hIni = self::formatearHora12((string)($info['hora_inicio'] ?? '08:00'));
                    $hFin = self::formatearHora12((string)($info['hora_fin'] ?? '12:00'));
                    $mod = ($info['modalidad'] ?? 'bloques') === 'orden_llegada'
                        ? ' (Por orden de llegada)'
                        : '';

                    $textoDia = "$espLabel de $hIni a $hFin$mod";
                    $todosLosHorarios[] = [
                        'dia'         => $espLabel,
                        'hora_inicio' => $hIni,
                        'hora_fin'    => $hFin,
                        'sede'        => $sedeNombre,
                        'texto'       => $sedeNombre !== '' ? "$textoDia — $sedeNombre" : $textoDia,
                    ];

                    if ($sedeNombre !== '') {
                        $claveSede = mb_strtolower($sedeNombre, 'UTF-8');
                        if (!isset($horariosPorSede[$claveSede])) {
                            $horariosPorSede[$claveSede] = [];
                        }
                        $horariosPorSede[$claveSede][] = $textoDia;
                    }
                }
            }

            // 3. Sedes enriquecidas con coordenadas y horarios del calendario
            $sedesRaw = json_decode($metas['sedes_multiples'] ?? '[]', true);
            $sedes = [];
            if (is_array($sedesRaw)) {
                foreach ($sedesRaw as $idx => $s) {
                    if (!is_array($s) || empty($s['direccion'])) continue;

                    $dir = trim((string)$s['direccion']);
                    $claveDir = mb_strtolower($dir, 'UTF-8');
                    $horariosSede = $horariosPorSede[$claveDir] ?? [];

                    if (empty($horariosSede)) {
                        foreach ($horariosPorSede as $k => $listaH) {
                            if ($k !== '' && (strpos($k, $claveDir) !== false || strpos($claveDir, $k) !== false)) {
                                $horariosSede = array_merge($horariosSede, $listaH);
                            }
                        }
                    }

                    $tituloSede = !empty($s['ciudad'])
                        ? "Consultorio {$s['ciudad']}"
                        : (!empty($s['estado']) ? "Consultorio {$s['estado']}" : "Sede #" . ($idx + 1));

                    $sedes[] = [
                        'id'            => $idx + 1,
                        'nombre_sede'   => $tituloSede,
                        'pais'          => $s['pais'] ?? 'Venezuela',
                        'estado'        => $s['estado'] ?? '',
                        'ciudad'        => $s['ciudad'] ?? '',
                        'direccion'     => $dir,
                        'lat'           => trim((string)($s['lat'] ?? '')),
                        'lng'           => trim((string)($s['lng'] ?? '')),
                        'horarios'      => array_values(array_unique($horariosSede)),
                        'horario_texto' => implode(' • ', array_values(array_unique($horariosSede))),
                    ];
                }
            }

            // 4. Servicios activos
            $colsSrv = self::columnasTabla($pdo, 'gm_servicios');
            $whereEstado = in_array('estado', $colsSrv, true)
                ? "AND (estado = 'activo' OR estado IS NULL OR estado = '')"
                : "";
            $stmtSrv = $pdo->prepare("SELECT * FROM gm_servicios WHERE medico_id IN ($placeholders) $whereEstado");
            $stmtSrv->execute($ids);
            $serviciosRaw = $stmtSrv ? $stmtSrv->fetchAll(PDO::FETCH_ASSOC) : [];

            $servicios = [];
            foreach ($serviciosRaw as $sr) {
                $permiteFin = intval($sr['permite_financiamiento'] ?? 0);
                $cuotas = intval($sr['cantidad_cuotas'] ?? $sr['cuotas'] ?? $sr['num_cuotas'] ?? 1);
                if ($cuotas <= 0) $cuotas = 1;
                $esContado = ($permiteFin === 0 && $cuotas <= 1);

                $servicios[] = [
                    'id'                     => intval($sr['id'] ?? 0),
                    'nombre'                 => $sr['nombre'] ?? $sr['nombre_servicio'] ?? $sr['titulo'] ?? 'Servicio Médico',
                    'descripcion'            => $sr['descripcion'] ?? $sr['detalle'] ?? '',
                    'precio_usd'             => $sr['precio_usd'] ?? $sr['precio'] ?? $sr['monto_usd'] ?? '0',
                    'tipo'                   => $sr['tipo'] ?? 'servicio',
                    'permite_financiamiento' => $permiteFin,
                    'cantidad_cuotas'        => $cuotas,
                    'cuotas'                 => $cuotas,
                    'es_contado'             => $esContado,
                ];
            }

            // 5. Redes sociales
            $redesRaw = json_decode($medico['redes_sociales'] ?? ($metas['redes_sociales_lista'] ?? '[]'), true);
            $instagram = '';
            $tiktok = '';
            $web = '';
            if (is_array($redesRaw)) {
                foreach ($redesRaw as $r) {
                    $red = strtolower(trim($r['red'] ?? ''));
                    $usr = trim($r['usuario'] ?? '');
                    if ($red === 'instagram' && $usr !== '') $instagram = $usr;
                    if ($red === 'tiktok' && $usr !== '') $tiktok = $usr;
                    if (strpos($red, 'web') !== false && $usr !== '') $web = $usr;
                }
            }

            // 6. Avatar HTTPS
            $avatarUrl = $metas['avatar_url'] ?? '';
            if (!empty($avatarUrl) && strpos($avatarUrl, '/uploads/avatars/') !== false) {
                $archivo = basename(parse_url($avatarUrl, PHP_URL_PATH));
                $host = $_SERVER['HTTP_HOST'] ?? 'api.ginevapp.com';
                if ($archivo !== '') {
                    $avatarUrl = "https://$host/api/medico/avatar/ver?file=$archivo";
                }
            }

            echo json_encode([
                "status" => "success",
                "data"   => [
                    "medico" => [
                        "id"           => $medicoId,
                        "medico_id"    => $medicoId,
                        "nombre"       => $medico['nombre'] ?? '',
                        "apellido"     => $medico['apellido'] ?? '',
                        "sexo"         => $medico['sexo'] ?? 'Femenino',
                        "especialidad" => !empty($medico['especialidad']) ? $medico['especialidad'] : ($metas['especialidad'] ?? 'Especialista Médico'),
                        "tagline"      => !empty($medico['tagline']) ? $medico['tagline'] : ($metas['tagline'] ?? ''),
                        "bio"          => $metas['bio'] ?? '',
                        "avatar_url"   => $avatarUrl,
                        "instagram"    => $instagram,
                        "tiktok"       => $tiktok,
                        "sitio_web"    => $web,
                        "biolink_slug" => $metas['biolink_slug'] ?? $slug,
                    ],
                    "sedes"            => $sedes,
                    "horarios_general" => $todosLosHorarios,
                    "servicios"        => $servicios,
                ]
            ], JSON_UNESCAPED_UNICODE);

        } catch (Exception $e) {
            http_response_code(500);
            echo json_encode(["status" => "error", "mensaje" => $e->getMessage()], JSON_UNESCAPED_UNICODE);
        }
    }

    /**
     * POST /api/publico/biolink/verificar-whatsapp
     */
    public static function verificarWhatsapp(array $input) {
        try {
            $whatsapp = self::normalizarWhatsapp((string)($input['whatsapp'] ?? ''));
            $cedula   = preg_replace('/\D/', '', (string)($input['cedula'] ?? ''));

            if (strlen($whatsapp) < 10 && $cedula === '') {
                http_response_code(400);
                echo json_encode(["status" => "error", "mensaje" => "Ingresa el documento y WhatsApp del paciente."], JSON_UNESCAPED_UNICODE);
                return;
            }

            $pdo = Database::conectar();
            $pac = null;

            // Si se envían ambos (documento y WhatsApp), ambos deben pertenecer al mismo paciente
            if ($cedula !== '' && strlen($whatsapp) >= 10) {
                $stmtAmbos = $pdo->prepare("
                    SELECT * FROM gm_usuarios_extendidos
                    WHERE REPLACE(REPLACE(cedula, '.', ''), '-', '') = ?
                      AND REPLACE(REPLACE(REPLACE(whatsapp, '+', ''), ' ', ''), '-', '') = ?
                    LIMIT 1
                ");
                $stmtAmbos->execute([$cedula, $whatsapp]);
                $pac = $stmtAmbos->fetch(PDO::FETCH_ASSOC);
            } elseif ($cedula !== '') {
                $stmtCed = $pdo->prepare("
                    SELECT * FROM gm_usuarios_extendidos
                    WHERE REPLACE(REPLACE(cedula, '.', ''), '-', '') = ?
                    LIMIT 1
                ");
                $stmtCed->execute([$cedula]);
                $pac = $stmtCed->fetch(PDO::FETCH_ASSOC);
            } elseif (strlen($whatsapp) >= 10) {
                $stmtWa = $pdo->prepare("
                    SELECT * FROM gm_usuarios_extendidos
                    WHERE REPLACE(REPLACE(REPLACE(whatsapp, '+', ''), ' ', ''), '-', '') = ?
                    LIMIT 1
                ");
                $stmtWa->execute([$whatsapp]);
                $pac = $stmtWa->fetch(PDO::FETCH_ASSOC);
            }

            if ($pac) {
                echo json_encode([
                    "status"     => "success",
                    "encontrado" => true,
                    "paciente"   => [
                        "id"                  => intval($pac['user_id'] ?? $pac['id']),
                        "user_id"             => intval($pac['user_id'] ?? $pac['id']),
                        "tipo_identificacion" => $pac['tipo_identificacion'] ?? 'V',
                        "cedula"              => $pac['cedula'] ?? $cedula,
                        "nombre"              => $pac['nombre'] ?? '',
                        "segundo_nombre"      => $pac['segundo_nombre'] ?? '',
                        "apellido"            => $pac['apellido'] ?? '',
                        "segundo_apellido"    => $pac['segundo_apellido'] ?? '',
                        "fecha_nacimiento"    => $pac['fecha_nacimiento'] ?? '',
                        "sexo"                => $pac['sexo'] ?? 'Femenino',
                        "email"               => $pac['email'] ?? '',
                        "whatsapp"            => $pac['whatsapp'] ?? $whatsapp,
                    ]
                ], JSON_UNESCAPED_UNICODE);
            } else {
                echo json_encode([
                    "status"     => "success",
                    "encontrado" => false,
                    "paciente"   => null
                ], JSON_UNESCAPED_UNICODE);
            }
        } catch (Exception $e) {
            http_response_code(500);
            echo json_encode(["status" => "error", "mensaje" => $e->getMessage()], JSON_UNESCAPED_UNICODE);
        }
    }

    /**
     * Obtiene o crea el paciente y devuelve su registro completo (id, nombre, cedula, telefono, direccion)
     */
    private static function obtenerOCrearPacienteCompleto(PDO $pdo, array $input, int $medicoId): array {
        $pacienteId = intval($input['paciente_id'] ?? 0);
        $tipoDoc    = trim((string)($input['tipo_identificacion'] ?? 'V')) ?: 'V';
        $cedula     = preg_replace('/\D/', '', (string)($input['cedula'] ?? ''));
        $whatsapp   = self::normalizarWhatsapp((string)($input['whatsapp'] ?? ''));
        $nombre     = trim((string)($input['nombre'] ?? ''));
        $segNombre  = trim((string)($input['segundo_nombre'] ?? ''));
        $apellido   = trim((string)($input['apellido'] ?? ''));
        $segApel    = trim((string)($input['segundo_apellido'] ?? ''));
        $fnac       = trim((string)($input['fecha_nacimiento'] ?? ''));
        $sexo       = trim((string)($input['sexo'] ?? 'Femenino')) ?: 'Femenino';
        $email      = trim((string)($input['email'] ?? ''));

        $row = null;
        if ($pacienteId > 0) {
            $stmtId = $pdo->prepare("SELECT * FROM gm_usuarios_extendidos WHERE user_id = ? OR id = ? LIMIT 1");
            $stmtId->execute([$pacienteId, $pacienteId]);
            $row = $stmtId->fetch(PDO::FETCH_ASSOC);
        }
        if (!$row && $cedula !== '' && strlen($whatsapp) >= 10) {
            $stmtAmbos = $pdo->prepare("
                SELECT * FROM gm_usuarios_extendidos
                WHERE REPLACE(REPLACE(cedula, '.', ''), '-', '') = ?
                  AND REPLACE(REPLACE(REPLACE(whatsapp, '+', ''), ' ', ''), '-', '') = ?
                LIMIT 1
            ");
            $stmtAmbos->execute([$cedula, $whatsapp]);
            $row = $stmtAmbos->fetch(PDO::FETCH_ASSOC);
        }
        if (!$row && $cedula !== '') {
            $stmtCed = $pdo->prepare("SELECT * FROM gm_usuarios_extendidos WHERE REPLACE(REPLACE(cedula, '.', ''), '-', '') = ? LIMIT 1");
            $stmtCed->execute([$cedula]);
            $row = $stmtCed->fetch(PDO::FETCH_ASSOC);
        }
        if (!$row && strlen($whatsapp) >= 10) {
            $stmtWa = $pdo->prepare("SELECT * FROM gm_usuarios_extendidos WHERE REPLACE(REPLACE(REPLACE(whatsapp, '+', ''), ' ', ''), '-', '') = ? LIMIT 1");
            $stmtWa->execute([$whatsapp]);
            $row = $stmtWa->fetch(PDO::FETCH_ASSOC);
        }

        if ($row) {
            $uid = intval($row['user_id'] ?? $row['id']);
            $nomCompleto = trim(implode(' ', array_filter([
                $row['nombre'] ?? $nombre,
                $row['segundo_nombre'] ?? $segNombre,
                $row['apellido'] ?? $apellido,
                $row['segundo_apellido'] ?? $segApel,
            ])));
            $td = trim((string)($row['tipo_identificacion'] ?? $tipoDoc)) ?: 'V';
            $ced = trim((string)($row['cedula'] ?? $cedula));
            $tel = trim((string)($row['whatsapp'] ?? $whatsapp));
            $dir = trim((string)($row['direccion'] ?? $row['direccion_detalle'] ?? $row['domicilio'] ?? ''));

            return [
                'paciente_id' => $uid,
                'nombre'      => $nomCompleto,
                'cedula'      => $ced !== '' ? "$td-$ced" : '',
                'telefono'    => $tel,
                'direccion'   => $dir,
            ];
        }

        $edad = 0;
        if ($fnac !== '' && strtotime($fnac) !== false) {
            $edad = max(0, (int)floor((time() - strtotime($fnac)) / 31557600));
        }

        $stmtMax = $pdo->query("SELECT COALESCE(MAX(user_id), 1000) + 1 AS sig FROM gm_usuarios_extendidos");
        $nuevoId = intval($stmtMax->fetch(PDO::FETCH_ASSOC)['sig']);

        $colsUser = self::columnasTabla($pdo, 'gm_usuarios_extendidos');
        $campos = ['user_id', 'tipo_identificacion', 'cedula', 'whatsapp', 'nombre', 'apellido', 'edad', 'sexo', 'email', 'rol_medico'];
        $valores = [$nuevoId, $tipoDoc, $cedula, $whatsapp, $nombre, $apellido, $edad, $sexo, $email, 'gm_paciente'];

        if (in_array('creado_por', $colsUser, true)) {
            $campos[] = 'creado_por';
            $valores[] = $medicoId;
        }
        if (in_array('segundo_nombre', $colsUser, true)) {
            $campos[] = 'segundo_nombre';
            $valores[] = $segNombre;
        }
        if (in_array('segundo_apellido', $colsUser, true)) {
            $campos[] = 'segundo_apellido';
            $valores[] = $segApel;
        }
        if (in_array('fecha_nacimiento', $colsUser, true) && $fnac !== '') {
            $campos[] = 'fecha_nacimiento';
            $valores[] = $fnac;
        }

        $colsSql = implode(', ', $campos);
        $marksSql = implode(', ', array_fill(0, count($valores), '?'));
        $stmtIns = $pdo->prepare("INSERT INTO gm_usuarios_extendidos ($colsSql) VALUES ($marksSql)");
        $stmtIns->execute($valores);

        $nomCompleto = trim(implode(' ', array_filter([$nombre, $segNombre, $apellido, $segApel])));

        return [
            'paciente_id' => $nuevoId,
            'nombre'      => $nomCompleto,
            'cedula'      => $cedula !== '' ? "$tipoDoc-$cedula" : '',
            'telefono'    => $whatsapp,
            'direccion'   => '',
        ];
    }

    /**
     * POST /api/publico/biolink/agendar
     */
    public static function agendarCita(array $input) {
        try {
            $medicoId  = intval($input['medico_id'] ?? 0);
            $nombre    = trim((string)($input['nombre'] ?? ''));
            $apellido  = trim((string)($input['apellido'] ?? ''));
            $fechaCita = trim((string)($input['fecha_cita'] ?? ''));
            $horaCita  = trim((string)($input['hora_cita'] ?? '08:00'));
            $motivo    = trim((string)($input['motivo'] ?? 'Cita agendada desde BioLink'));

            if ($medicoId <= 0 || $nombre === '' || $apellido === '' || $fechaCita === '') {
                http_response_code(400);
                echo json_encode(["status" => "error", "mensaje" => "Complete todos los datos obligatorios de la cita."], JSON_UNESCAPED_UNICODE);
                return;
            }

            $pdo = Database::conectar();
            $pacInfo = self::obtenerOCrearPacienteCompleto($pdo, $input, $medicoId);
            $pacienteId = $pacInfo['paciente_id'];

            $colsCita = self::columnasTabla($pdo, 'gm_citas');
            $campos = ['medico_id', 'paciente_id', 'fecha_cita', 'hora_cita', 'estado_cita'];
            $valores = [$medicoId, $pacienteId, $fechaCita, $horaCita, 'reservada'];

            if (in_array('motivo_consulta', $colsCita, true)) {
                $campos[] = 'motivo_consulta';
                $valores[] = $motivo;
            } elseif (in_array('motivo', $colsCita, true)) {
                $campos[] = 'motivo';
                $valores[] = $motivo;
            }
            if (in_array('origen', $colsCita, true)) {
                $campos[] = 'origen';
                $valores[] = 'biolink';
            }

            $colsSql = implode(', ', $campos);
            $marksSql = implode(', ', array_fill(0, count($valores), '?'));
            $stmtCita = $pdo->prepare("INSERT INTO gm_citas ($colsSql) VALUES ($marksSql)");
            $stmtCita->execute($valores);

            echo json_encode([
                "status"  => "success",
                "mensaje" => "¡Cita reservada exitosamente! Te esperamos en consulta.",
                "cita_id" => intval($pdo->lastInsertId())
            ], JSON_UNESCAPED_UNICODE);

        } catch (Exception $e) {
            http_response_code(500);
            echo json_encode(["status" => "error", "mensaje" => $e->getMessage()], JSON_UNESCAPED_UNICODE);
        }
    }

    /**
     * POST /api/publico/biolink/presupuesto
     */
    public static function solicitarPresupuesto(array $input) {
        try {
            $medicoId     = intval($input['medico_id'] ?? 0);
            $nombre       = trim((string)($input['nombre'] ?? ''));
            $serviciosIds = is_array($input['servicios_ids'] ?? null) ? $input['servicios_ids'] : [];
            $obs          = trim((string)($input['observaciones'] ?? 'Presupuesto generado desde BioLink'));

            if ($medicoId <= 0 || $nombre === '' || empty($serviciosIds)) {
                http_response_code(400);
                echo json_encode(["status" => "error", "mensaje" => "Datos incompletos para generar el presupuesto."], JSON_UNESCAPED_UNICODE);
                return;
            }

            $pdo = Database::conectar();
            $pacInfo    = self::obtenerOCrearPacienteCompleto($pdo, $input, $medicoId);
            $pacienteId = $pacInfo['paciente_id'];

            // 1. Obtener tasa BCV real desde gm_tasa_bcv
            $tasaBcv = self::obtenerTasaBcvActual($pdo);

            // 2. Validar servicios estrictamente de contado
            $idsLimpios = array_values(array_filter(array_map('intval', $serviciosIds), fn($v) => $v > 0));
            $placeholders = implode(',', array_fill(0, count($idsLimpios), '?'));

            $colsSrv = self::columnasTabla($pdo, 'gm_servicios');
            $filtroFin = in_array('permite_financiamiento', $colsSrv, true)
                ? "AND COALESCE(permite_financiamiento, 0) = 0"
                : "";
            $filtroCuotas = in_array('cantidad_cuotas', $colsSrv, true)
                ? "AND COALESCE(cantidad_cuotas, 1) <= 1"
                : "";

            $stmtSrv = $pdo->prepare("
                SELECT * FROM gm_servicios
                WHERE id IN ($placeholders)
                  $filtroFin
                  $filtroCuotas
            ");
            $stmtSrv->execute($idsLimpios);
            $itemsRaw = $stmtSrv->fetchAll(PDO::FETCH_ASSOC);

            if (empty($itemsRaw)) {
                http_response_code(400);
                echo json_encode([
                    "status"  => "error",
                    "mensaje" => "Los servicios seleccionados no aplican para presupuesto de contado."
                ], JSON_UNESCAPED_UNICODE);
                return;
            }

            $items = [];
            $totalUsd = 0.0;
            foreach ($itemsRaw as $it) {
                $precio = floatval($it['precio_usd'] ?? $it['precio'] ?? $it['monto_usd'] ?? 0);
                $nomSrv = $it['nombre'] ?? $it['nombre_servicio'] ?? $it['titulo'] ?? 'Servicio';
                $descSrv = $it['descripcion'] ?? $nomSrv;
                $totalUsd += $precio;
                $items[] = [
                    'id'           => intval($it['id'] ?? 0),
                    'servicio_id'  => intval($it['id'] ?? 0),
                    'nombre'       => $nomSrv,
                    'descripcion'  => $descSrv,
                    'cantidad'     => 1,
                    'precio_usd'   => $precio,
                    'subtotal_usd' => $precio,
                ];
            }
            $totalBs = round($totalUsd * $tasaBcv, 2);

            // 3. Correlativo de presupuesto del médico
            $colsPres = self::columnasTabla($pdo, 'gm_presupuestos');
            $stmtCorr = $pdo->prepare("SELECT COUNT(*) AS cnt FROM gm_presupuestos WHERE medico_id = ?");
            $stmtCorr->execute([$medicoId]);
            $cntExistentes = intval($stmtCorr->fetch(PDO::FETCH_ASSOC)['cnt'] ?? 0);

            $stmtIni = $pdo->prepare("SELECT meta_value FROM gm_medico_meta WHERE medico_id = ? AND meta_key = 'inicio_presupuesto' LIMIT 1");
            $stmtIni->execute([$medicoId]);
            $iniPres = intval($stmtIni->fetchColumn() ?: 1);
            $numeroPresPadded = str_pad((string)($iniPres + $cntExistentes), 6, '0', STR_PAD_LEFT);

            $fechaEmision     = date('Y-m-d');
            $fechaVencimiento = date('Y-m-d', strtotime('+3 days'));

            $campos = ['medico_id', 'paciente_id', 'total_usd'];
            $valores = [$medicoId, $pacienteId, $totalUsd];

            if (in_array('paciente_nombre', $colsPres, true)) {
                $campos[] = 'paciente_nombre';
                $valores[] = $pacInfo['nombre'];
            }
            if (in_array('paciente_cedula', $colsPres, true)) {
                $campos[] = 'paciente_cedula';
                $valores[] = $pacInfo['cedula'];
            }
            if (in_array('paciente_telefono', $colsPres, true)) {
                $campos[] = 'paciente_telefono';
                $valores[] = $pacInfo['telefono'];
            }
            if (in_array('paciente_direccion', $colsPres, true)) {
                $campos[] = 'paciente_direccion';
                $valores[] = $pacInfo['direccion'];
            }
            if (in_array('estatus', $colsPres, true)) {
                $campos[] = 'estatus';
                $valores[] = 'pendiente';
            }
            if (in_array('estado', $colsPres, true)) {
                $campos[] = 'estado';
                $valores[] = 'pendiente';
            }
            if (in_array('numero_presupuesto', $colsPres, true)) {
                $campos[] = 'numero_presupuesto';
                $valores[] = $numeroPresPadded;
            }
            if (in_array('tasa_bcv', $colsPres, true)) {
                $campos[] = 'tasa_bcv';
                $valores[] = $tasaBcv;
            }
            if (in_array('total_bs', $colsPres, true)) {
                $campos[] = 'total_bs';
                $valores[] = $totalBs;
            }
            if (in_array('items_json', $colsPres, true)) {
                $campos[] = 'items_json';
                $valores[] = json_encode($items, JSON_UNESCAPED_UNICODE);
            } elseif (in_array('detalle_json', $colsPres, true)) {
                $campos[] = 'detalle_json';
                $valores[] = json_encode($items, JSON_UNESCAPED_UNICODE);
            }
            if (in_array('observaciones', $colsPres, true)) {
                $campos[] = 'observaciones';
                $valores[] = $obs;
            }
            if (in_array('fecha_emision', $colsPres, true)) {
                $campos[] = 'fecha_emision';
                $valores[] = $fechaEmision;
            }
            if (in_array('fecha_vencimiento', $colsPres, true)) {
                $campos[] = 'fecha_vencimiento';
                $valores[] = $fechaVencimiento;
            }

            $colsSql = implode(', ', $campos);
            $marksSql = implode(', ', array_fill(0, count($valores), '?'));
            $stmtPres = $pdo->prepare("INSERT INTO gm_presupuestos ($colsSql) VALUES ($marksSql)");
            $stmtPres->execute($valores);
            $presupuestoId = intval($pdo->lastInsertId());

            $host = $_SERVER['HTTP_HOST'] ?? 'api.ginevapp.com';
            $pdfUrl = "https://$host/api/presupuestos/pdf?id=$presupuestoId";

            // Guardar url_pdf en gm_presupuestos si la columna existe
            if (in_array('url_pdf', $colsPres, true)) {
                $stmtUpdUrl = $pdo->prepare("UPDATE gm_presupuestos SET url_pdf = ? WHERE id = ?");
                $stmtUpdUrl->execute([$pdfUrl, $presupuestoId]);
            }

            echo json_encode([
                "status"             => "success",
                "mensaje"            => "Presupuesto N° $numeroPresPadded generado correctamente.",
                "presupuesto_id"     => $presupuestoId,
                "numero_presupuesto" => $numeroPresPadded,
                "paciente_id"        => $pacienteId,
                "tasa_bcv"           => $tasaBcv,
                "total_bs"           => $totalBs,
                "fecha_vencimiento"  => $fechaVencimiento,
                "pdf_url"            => $pdfUrl,
            ], JSON_UNESCAPED_UNICODE);

        } catch (Exception $e) {
            http_response_code(500);
            echo json_encode(["status" => "error", "mensaje" => $e->getMessage()], JSON_UNESCAPED_UNICODE);
        }
    }
}