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
        // Caso +5804143774011 -> 5804143774011 -> quitar el 0 tras 58 -> 584143774011
        if (strpos($digitos, '580') === 0 && strlen($digitos) >= 12) {
            return '58' . substr($digitos, 3);
        }
        // Caso local 04143774011 -> 584143774011
        if (strlen($digitos) === 11 && strpos($digitos, '0') === 0) {
            return '58' . substr($digitos, 1);
        }
        // Caso local 4143774011 -> 584143774011
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
        foreach (['gm_tasas_bcv', 'gm_tasa_bcv'] as $tablaTasa) {
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
            $calendariosPublicos = [];

            foreach ($calendariosRows as $cal) {
                $estadoCal = strtolower(trim((string)($cal['estado'] ?? 'activo')));
                if ($estadoCal === 'inactivo' || $estadoCal === 'eliminado') {
                    continue;
                }

                $diasConf = json_decode($cal['dias_config'] ?? '{}', true);
                if (!is_array($diasConf)) {
                    $diasConf = [];
                }

                $calItem = $cal;
                $calItem['id'] = intval($cal['id'] ?? 0);
                $calItem['medico_id'] = intval($cal['medico_id'] ?? $medicoId);
                $calItem['duracion_bloque'] = intval($cal['duracion_bloque'] ?? 30);
                $calItem['cupos_por_bloque'] = intval($cal['cupos_por_bloque'] ?? 1);
                $calItem['dias_config'] = $diasConf;
                $calendariosPublicos[] = $calItem;

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

            // 4. Servicios activos (incluyendo permite_financiamiento, cantidad_cuotas y frecuencia_cuotas)
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
                $frecuencia = trim((string)($sr['frecuencia_cuotas'] ?? $sr['frecuencia'] ?? 'semanal')) ?: 'semanal';

                $servicios[] = [
                    'id'                     => intval($sr['id'] ?? 0),
                    'nombre'                 => $sr['nombre'] ?? $sr['nombre_servicio'] ?? $sr['titulo'] ?? 'Servicio Médico',
                    'descripcion'            => $sr['descripcion'] ?? $sr['detalle'] ?? '',
                    'precio_usd'             => $sr['precio_usd'] ?? $sr['precio'] ?? $sr['monto_usd'] ?? '0',
                    'tipo'                   => $sr['tipo'] ?? 'servicio',
                    'es_combo'               => intval($sr['es_combo'] ?? 0),
                    'permite_financiamiento' => $permiteFin,
                    'cantidad_cuotas'        => $cuotas,
                    'cuotas'                 => $cuotas,
                    'frecuencia_cuotas'      => $frecuencia,
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
                    "calendarios"      => $calendariosPublicos,
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
            $tipoDoc  = strtoupper(trim((string)($input['tipo_identificacion'] ?? '')));

            if (strlen($whatsapp) < 8 && $cedula === '') {
                http_response_code(400);
                echo json_encode(["status" => "error", "mensaje" => "Ingresa el documento y WhatsApp del paciente."], JSON_UNESCAPED_UNICODE);
                return;
            }

            $pdo = Database::conectar();
            $pac = null;

            if ($cedula !== '' && strlen($whatsapp) >= 8) {
                $sql = "
                    SELECT * FROM gm_usuarios_extendidos
                    WHERE REPLACE(REPLACE(cedula, '.', ''), '-', '') = ?
                      AND REPLACE(REPLACE(REPLACE(whatsapp, '+', ''), ' ', ''), '-', '') = ?
                ";
                $params = [$cedula, $whatsapp];
                if ($tipoDoc !== '') {
                    $sql .= " AND UPPER(COALESCE(tipo_identificacion, 'V')) = ?";
                    $params[] = $tipoDoc;
                }
                $sql .= " LIMIT 1";
                $stmtAmbos = $pdo->prepare($sql);
                $stmtAmbos->execute($params);
                $pac = $stmtAmbos->fetch(PDO::FETCH_ASSOC);

                // Respaldo por últimos 10 dígitos del WhatsApp si estaba guardado sin código de país
                if (!$pac && strlen($whatsapp) >= 10) {
                    $ultimos10 = substr($whatsapp, -10);
                    $sql2 = "
                        SELECT * FROM gm_usuarios_extendidos
                        WHERE REPLACE(REPLACE(cedula, '.', ''), '-', '') = ?
                          AND REPLACE(REPLACE(REPLACE(whatsapp, '+', ''), ' ', ''), '-', '') LIKE ?
                    ";
                    $params2 = [$cedula, '%' . $ultimos10];
                    if ($tipoDoc !== '') {
                        $sql2 .= " AND UPPER(COALESCE(tipo_identificacion, 'V')) = ?";
                        $params2[] = $tipoDoc;
                    }
                    $sql2 .= " LIMIT 1";
                    $stmtAmbos2 = $pdo->prepare($sql2);
                    $stmtAmbos2->execute($params2);
                    $pac = $stmtAmbos2->fetch(PDO::FETCH_ASSOC);
                }
            } elseif ($cedula !== '') {
                $stmtCed = $pdo->prepare("
                    SELECT * FROM gm_usuarios_extendidos
                    WHERE REPLACE(REPLACE(cedula, '.', ''), '-', '') = ?
                    LIMIT 1
                ");
                $stmtCed->execute([$cedula]);
                $pac = $stmtCed->fetch(PDO::FETCH_ASSOC);
            } elseif (strlen($whatsapp) >= 8) {
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
                        "edad"                => intval($pac['edad'] ?? 0),
                        "fecha_nacimiento"    => $pac['fecha_nacimiento'] ?? '',
                        "sexo"                => $pac['sexo'] ?? 'Femenino',
                        "email"               => $pac['email'] ?? '',
                        "whatsapp"            => $pac['whatsapp'] ?? $whatsapp,
                        "pais"                => $pac['pais'] ?? '',
                        "estado"              => $pac['estado'] ?? '',
                        "direccion"           => $pac['direccion_detalle'] ?? ($pac['direccion'] ?? ''),
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
     * Obtiene o crea el paciente en gm_usuarios_extendidos y devuelve sus datos completos.
     */
    private static function obtenerOCrearPaciente(PDO $pdo, array $input, int $medicoId): array {
        $pacienteId = intval($input['paciente_id'] ?? 0);
        $tipoDoc    = strtoupper(trim((string)($input['tipo_identificacion'] ?? 'V'))) ?: 'V';
        $cedula     = preg_replace('/\D/', '', (string)($input['cedula'] ?? ''));
        $whatsapp   = self::normalizarWhatsapp((string)($input['whatsapp'] ?? ''));
        $nombre     = trim((string)($input['nombre'] ?? ''));
        $segNombre  = trim((string)($input['segundo_nombre'] ?? ''));
        $apellido   = trim((string)($input['apellido'] ?? ''));
        $segApel    = trim((string)($input['segundo_apellido'] ?? ''));
        $edadInput  = intval($input['edad'] ?? 0);
        $fnac       = trim((string)($input['fecha_nacimiento'] ?? ''));
        $sexo       = trim((string)($input['sexo'] ?? 'Femenino')) ?: 'Femenino';
        $email      = trim((string)($input['email'] ?? ''));
        $pais       = trim((string)($input['pais'] ?? ''));
        $estado     = trim((string)($input['estado'] ?? ''));
        $direccion  = trim((string)($input['direccion'] ?? ($input['direccion_detalle'] ?? '')));

        $colsUser = self::columnasTabla($pdo, 'gm_usuarios_extendidos');

        $row = null;
        if ($pacienteId > 0) {
            $stmtId = $pdo->prepare("SELECT * FROM gm_usuarios_extendidos WHERE user_id = ? OR id = ? LIMIT 1");
            $stmtId->execute([$pacienteId, $pacienteId]);
            $row = $stmtId->fetch(PDO::FETCH_ASSOC);
        }
        if (!$row && $cedula !== '' && strlen($whatsapp) >= 8) {
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
        if (!$row && strlen($whatsapp) >= 8) {
            $stmtWa = $pdo->prepare("SELECT * FROM gm_usuarios_extendidos WHERE REPLACE(REPLACE(REPLACE(whatsapp, '+', ''), ' ', ''), '-', '') = ? LIMIT 1");
            $stmtWa->execute([$whatsapp]);
            $row = $stmtWa->fetch(PDO::FETCH_ASSOC);
        }

        if ($row) {
            $uid = intval($row['user_id'] ?? $row['id']);
            $nomCompleto = trim(implode(' ', array_filter([
                $row['nombre'] ?: $nombre,
                $row['segundo_nombre'] ?: $segNombre,
                $row['apellido'] ?: $apellido,
                $row['segundo_apellido'] ?: $segApel,
            ])));
            $td  = trim((string)($row['tipo_identificacion'] ?: $tipoDoc)) ?: 'V';
            $ced = trim((string)($row['cedula'] ?: $cedula));
            $tel = trim((string)($row['whatsapp'] ?: $whatsapp));
            $dirBase  = trim((string)($row['direccion_detalle'] ?? ($row['direccion'] ?? ($row['domicilio'] ?? $direccion))));
            $estBase  = trim((string)($row['estado'] ?: $estado));
            $paisBase = trim((string)($row['pais'] ?: $pais));
            $dirCompleta = trim(implode(', ', array_filter([$dirBase, $estBase, $paisBase])));

            return [
                'paciente_id' => $uid,
                'nombre'      => $nomCompleto,
                'cedula'      => $ced !== '' ? "$td-$ced" : '',
                'telefono'    => $tel,
                'email'       => trim((string)($row['email'] ?: $email)),
                'pais'        => $paisBase,
                'estado'      => $estBase,
                'direccion'   => $dirCompleta,
            ];
        }

        $edad = $edadInput;
        if ($edad <= 0 && $fnac !== '' && strtotime($fnac) !== false) {
            $edad = max(0, (int)floor((time() - strtotime($fnac)) / 31557600));
        }

        $stmtMax = $pdo->query("SELECT COALESCE(MAX(user_id), 1000) + 1 AS sig FROM gm_usuarios_extendidos");
        $nuevoId = intval($stmtMax->fetch(PDO::FETCH_ASSOC)['sig']);

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
        if (in_array('pais', $colsUser, true) && $pais !== '') {
            $campos[] = 'pais';
            $valores[] = $pais;
        }
        if (in_array('estado', $colsUser, true) && $estado !== '') {
            $campos[] = 'estado';
            $valores[] = $estado;
        }
        if (in_array('direccion_detalle', $colsUser, true)) {
            $campos[] = 'direccion_detalle';
            $valores[] = $direccion;
        } elseif (in_array('direccion', $colsUser, true)) {
            $campos[] = 'direccion';
            $valores[] = $direccion;
        }

        $colsSql = implode(', ', $campos);
        $marksSql = implode(', ', array_fill(0, count($valores), '?'));
        $stmtIns = $pdo->prepare("INSERT INTO gm_usuarios_extendidos ($colsSql) VALUES ($marksSql)");
        $stmtIns->execute($valores);

        $nomCompleto = trim(implode(' ', array_filter([$nombre, $segNombre, $apellido, $segApel])));
        $dirCompleta = trim(implode(', ', array_filter([$direccion, $estado, $pais])));

        return [
            'paciente_id' => $nuevoId,
            'nombre'      => $nomCompleto,
            'cedula'      => $cedula !== '' ? "$tipoDoc-$cedula" : '',
            'telefono'    => $whatsapp,
            'email'       => $email,
            'pais'        => $pais,
            'estado'      => $estado,
            'direccion'   => $dirCompleta,
        ];
    }

    private static function intervaloDiasPorFrecuencia(string $frecuencia): int {
        $f = mb_strtolower(trim($frecuencia), 'UTF-8');
        if (strpos($f, 'quincen') !== false || strpos($f, 'biweek') !== false) return 15;
        if (strpos($f, 'mensual') !== false || strpos($f, 'mes') !== false || strpos($f, 'month') !== false) return 30;
        if (strpos($f, 'diari') !== false || strpos($f, 'day') !== false) return 1;
        return 7;
    }

    /**
     * Genera las cuotas de financiamiento en gm_cuentas_por_cobrar y/o gm_finanzas
     */
    private static function registrarCuentasPorCobrarFinanciamiento(
        PDO $pdo,
        int $medicoId,
        int $pacienteId,
        int $citaId,
        array $pacInfo,
        array $input
    ): void {
        $esFinanciado = intval($input['es_financiado'] ?? 0) === 1;
        $montoFinanciadoTotal = floatval($input['monto_financiado_usd'] ?? 0);
        if (!$esFinanciado && $montoFinanciadoTotal <= 0) {
            return;
        }

        $tasaBcv = self::obtenerTasaBcvActual($pdo);
        $fechaBase = trim((string)($input['fecha_credito'] ?? date('Y-m-d')));
        if (strtotime($fechaBase) === false) {
            $fechaBase = date('Y-m-d');
        }

        $detalleFin = is_array($input['detalle_financiamiento'] ?? null)
            ? $input['detalle_financiamiento']
            : [];

        // Construir lista de planes financiados (uno por servicio financiado o un plan global)
        $planes = [];
        foreach ($detalleFin as $item) {
            if (!is_array($item)) continue;
            $itemFin = !empty($item['financiado']) || (($item['modalidad_pago'] ?? '') === 'financiado');
            $precioItem = floatval($item['precio_usd'] ?? 0);
            if ($itemFin && $precioItem > 0) {
                $numCuotas = max(1, intval($item['cuotas_semanales'] ?? $item['cantidad_cuotas'] ?? 1));
                $frec = trim((string)($item['frecuencia_cuotas'] ?? 'semanal')) ?: 'semanal';
                $planes[] = [
                    'servicio_id'     => intval($item['servicio_id'] ?? 0),
                    'nombre_servicio' => trim((string)($item['nombre_servicio'] ?? 'Servicio financiado')),
                    'monto_total_usd' => $precioItem,
                    'num_cuotas'      => $numCuotas,
                    'frecuencia'      => $frec,
                ];
            }
        }

        if (empty($planes) && $montoFinanciadoTotal > 0) {
            $numCuotas = max(1, intval($input['cuotas_semanales'] ?? 1));
            $planes[] = [
                'servicio_id'     => 0,
                'nombre_servicio' => trim((string)($input['procedimiento'] ?? $input['motivo'] ?? 'Servicio médico financiado')),
                'monto_total_usd' => $montoFinanciadoTotal,
                'num_cuotas'      => $numCuotas,
                'frecuencia'      => trim((string)($input['frecuencia_cuotas'] ?? 'semanal')) ?: 'semanal',
            ];
        }

        // Detectar tablas disponibles de Cuentas por Cobrar
        $colsCxc = null;
        foreach (['gm_cuentas_por_cobrar', 'gm_cuentas_cobrar', 'gm_cxc'] as $tCxc) {
            try {
                $colsCxc = self::columnasTabla($pdo, $tCxc);
                $tablaCxc = $tCxc;
                break;
            } catch (Exception $_) {}
        }

        $colsFin = null;
        try {
            $colsFin = self::columnasTabla($pdo, 'gm_finanzas');
        } catch (Exception $_) {}

        foreach ($planes as $plan) {
            $numCuotas   = $plan['num_cuotas'];
            $montoTotal  = $plan['monto_total_usd'];
            $montoCuota  = round($montoTotal / $numCuotas, 2);
            $diasPaso    = self::intervaloDiasPorFrecuencia($plan['frecuencia']);
            $nomServicio = $plan['nombre_servicio'];

            for ($i = 1; $i <= $numCuotas; $i++) {
                // Ajustar centavos en la última cuota para cuadrar exacto
                $cuotaActualUsd = ($i === $numCuotas)
                    ? round($montoTotal - ($montoCuota * ($numCuotas - 1)), 2)
                    : $montoCuota;
                $cuotaActualBs = round($cuotaActualUsd * $tasaBcv, 2);

                $diasOffset = ($i - 1) * $diasPaso;
                $fechaVenc = date('Y-m-d', strtotime($fechaBase . " +{$diasOffset} days"));
                $concepto = "Cuota $i/$numCuotas - $nomServicio (Cita #$citaId)";

                // 1. Insertar en tabla específica de Cuentas por Cobrar si existe
                if ($colsCxc !== null && isset($tablaCxc)) {
                    $c = ['medico_id'];
                    $v = [$medicoId];

                    if (in_array('paciente_id', $colsCxc, true)) { $c[] = 'paciente_id'; $v[] = $pacienteId; }
                    if (in_array('cita_id', $colsCxc, true)) { $c[] = 'cita_id'; $v[] = $citaId; }
                    if (in_array('servicio_id', $colsCxc, true) && $plan['servicio_id'] > 0) { $c[] = 'servicio_id'; $v[] = $plan['servicio_id']; }
                    if (in_array('paciente_nombre', $colsCxc, true)) { $c[] = 'paciente_nombre'; $v[] = $pacInfo['nombre']; }
                    if (in_array('cliente_nombre', $colsCxc, true)) { $c[] = 'cliente_nombre'; $v[] = $pacInfo['nombre']; }
                    if (in_array('paciente_cedula', $colsCxc, true)) { $c[] = 'paciente_cedula'; $v[] = $pacInfo['cedula']; }
                    if (in_array('paciente_telefono', $colsCxc, true)) { $c[] = 'paciente_telefono'; $v[] = $pacInfo['telefono']; }
                    if (in_array('concepto', $colsCxc, true)) { $c[] = 'concepto'; $v[] = $concepto; }
                    elseif (in_array('descripcion', $colsCxc, true)) { $c[] = 'descripcion'; $v[] = $concepto; }
                    if (in_array('numero_cuota', $colsCxc, true)) { $c[] = 'numero_cuota'; $v[] = $i; }
                    if (in_array('total_cuotas', $colsCxc, true)) { $c[] = 'total_cuotas'; $v[] = $numCuotas; }
                    if (in_array('monto_usd', $colsCxc, true)) { $c[] = 'monto_usd'; $v[] = $cuotaActualUsd; }
                    elseif (in_array('monto', $colsCxc, true)) { $c[] = 'monto'; $v[] = $cuotaActualUsd; }
                    if (in_array('saldo_usd', $colsCxc, true)) { $c[] = 'saldo_usd'; $v[] = $cuotaActualUsd; }
                    elseif (in_array('saldo_pendiente', $colsCxc, true)) { $c[] = 'saldo_pendiente'; $v[] = $cuotaActualUsd; }
                    if (in_array('monto_bs', $colsCxc, true)) { $c[] = 'monto_bs'; $v[] = $cuotaActualBs; }
                    if (in_array('tasa_bcv', $colsCxc, true)) { $c[] = 'tasa_bcv'; $v[] = $tasaBcv; }
                    if (in_array('fecha_emision', $colsCxc, true)) { $c[] = 'fecha_emision'; $v[] = $fechaBase; }
                    if (in_array('fecha_vencimiento', $colsCxc, true)) { $c[] = 'fecha_vencimiento'; $v[] = $fechaVenc; }
                    if (in_array('estado', $colsCxc, true)) { $c[] = 'estado'; $v[] = 'pendiente'; }
                    elseif (in_array('estatus', $colsCxc, true)) { $c[] = 'estatus'; $v[] = 'pendiente'; }
                    if (in_array('origen', $colsCxc, true)) { $c[] = 'origen'; $v[] = 'biolink'; }

                    $sqlCxc = "INSERT INTO `$tablaCxc` (" . implode(', ', $c) . ") VALUES (" . implode(', ', array_fill(0, count($v), '?')) . ")";
                    $pdo->prepare($sqlCxc)->execute($v);
                }

                // 2. Registrar también en gm_finanzas como cuenta_por_cobrar si existe la tabla
                if ($colsFin !== null) {
                    $cf = ['medico_id'];
                    $vf = [$medicoId];

                    if (in_array('paciente_id', $colsFin, true)) { $cf[] = 'paciente_id'; $vf[] = $pacienteId; }
                    if (in_array('cita_id', $colsFin, true)) { $cf[] = 'cita_id'; $vf[] = $citaId; }
                    if (in_array('cliente_nombre', $colsFin, true)) { $cf[] = 'cliente_nombre'; $vf[] = $pacInfo['nombre']; }
                    elseif (in_array('paciente_nombre', $colsFin, true)) { $cf[] = 'paciente_nombre'; $vf[] = $pacInfo['nombre']; }
                    if (in_array('tipo', $colsFin, true)) { $cf[] = 'tipo'; $vf[] = 'cuenta_por_cobrar'; }
                    elseif (in_array('tipo_movimiento', $colsFin, true)) { $cf[] = 'tipo_movimiento'; $vf[] = 'cuenta_por_cobrar'; }
                    if (in_array('concepto', $colsFin, true)) { $cf[] = 'concepto'; $vf[] = $concepto; }
                    elseif (in_array('descripcion', $colsFin, true)) { $cf[] = 'descripcion'; $vf[] = $concepto; }
                    if (in_array('monto_usd', $colsFin, true)) { $cf[] = 'monto_usd'; $vf[] = $cuotaActualUsd; }
                    elseif (in_array('total_usd', $colsFin, true)) { $cf[] = 'total_usd'; $vf[] = $cuotaActualUsd; }
                    if (in_array('monto_bs', $colsFin, true)) { $cf[] = 'monto_bs'; $vf[] = $cuotaActualBs; }
                    elseif (in_array('total_bs', $colsFin, true)) { $cf[] = 'total_bs'; $vf[] = $cuotaActualBs; }
                    if (in_array('tasa_bcv', $colsFin, true)) { $cf[] = 'tasa_bcv'; $vf[] = $tasaBcv; }
                    if (in_array('estado', $colsFin, true)) { $cf[] = 'estado'; $vf[] = 'pendiente'; }
                    elseif (in_array('estatus', $colsFin, true)) { $cf[] = 'estatus'; $vf[] = 'pendiente'; }
                    if (in_array('fecha', $colsFin, true)) { $cf[] = 'fecha'; $vf[] = $fechaVenc; }
                    elseif (in_array('fecha_vencimiento', $colsFin, true)) { $cf[] = 'fecha_vencimiento'; $vf[] = $fechaVenc; }
                    elseif (in_array('fecha_registro', $colsFin, true)) { $cf[] = 'fecha_registro'; $vf[] = date('Y-m-d H:i:s'); }

                    $sqlFin = "INSERT INTO gm_finanzas (" . implode(', ', $cf) . ") VALUES (" . implode(', ', array_fill(0, count($vf), '?')) . ")";
                    $pdo->prepare($sqlFin)->execute($vf);
                }
            }
        }
    }

    /**
     * POST /api/publico/biolink/agendar
     */
    public static function agendarCita(array $input) {
        try {
            $medicoId     = intval($input['medico_id'] ?? 0);
            $calendarioId = intval($input['calendario_id'] ?? 0);
            $sedeId       = intval($input['sede_id'] ?? 0);
            $nombre       = trim((string)($input['nombre'] ?? ''));
            $apellido     = trim((string)($input['apellido'] ?? ''));
            $fechaCita    = trim((string)($input['fecha_cita'] ?? ''));
            $horaCita     = trim((string)($input['hora_cita'] ?? '08:00'));
            $modalidad    = trim((string)($input['modalidad'] ?? 'bloques'));
            $procedimiento = trim((string)($input['procedimiento'] ?? ''));
            $motivoRaw    = trim((string)($input['motivo'] ?? ''));
            $motivo       = $procedimiento !== ''
                ? ($motivoRaw !== '' ? "$procedimiento — $motivoRaw" : $procedimiento)
                : ($motivoRaw !== '' ? $motivoRaw : 'Cita agendada desde BioLink');

            if ($medicoId <= 0 || $nombre === '' || $apellido === '' || $fechaCita === '') {
                http_response_code(400);
                echo json_encode(["status" => "error", "mensaje" => "Complete todos los datos obligatorios de la cita."], JSON_UNESCAPED_UNICODE);
                return;
            }

            $pdo = Database::conectar();
            $pacInfo = self::obtenerOCrearPaciente($pdo, $input, $medicoId);
            $pacienteId = $pacInfo['paciente_id'];

            $colsCita = self::columnasTabla($pdo, 'gm_citas');
            $campos = ['medico_id', 'paciente_id', 'fecha_cita', 'hora_cita', 'estado_cita'];
            $valores = [$medicoId, $pacienteId, $fechaCita, $horaCita, 'reservada'];

            if ($calendarioId > 0 && in_array('calendario_id', $colsCita, true)) {
                $campos[] = 'calendario_id';
                $valores[] = $calendarioId;
            }
            if ($sedeId > 0 && in_array('sede_id', $colsCita, true)) {
                $campos[] = 'sede_id';
                $valores[] = $sedeId;
            }
            if ($modalidad !== '' && in_array('modalidad', $colsCita, true)) {
                $campos[] = 'modalidad';
                $valores[] = $modalidad;
            }
            if ($procedimiento !== '' && in_array('procedimiento', $colsCita, true)) {
                $campos[] = 'procedimiento';
                $valores[] = $procedimiento;
            }
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

            $totalUsd = floatval($input['total_usd'] ?? 0);
            if ($totalUsd > 0 && in_array('total_usd', $colsCita, true)) {
                $campos[] = 'total_usd';
                $valores[] = $totalUsd;
            }
            if (in_array('es_financiado', $colsCita, true)) {
                $campos[] = 'es_financiado';
                $valores[] = intval($input['es_financiado'] ?? 0);
            }

            $colsSql = implode(', ', $campos);
            $marksSql = implode(', ', array_fill(0, count($valores), '?'));
            $stmtCita = $pdo->prepare("INSERT INTO gm_citas ($colsSql) VALUES ($marksSql)");
            $stmtCita->execute($valores);
            $citaId = intval($pdo->lastInsertId());

            // Si la cita tiene financiamiento, calcular cuotas y almacenar en Cuentas por Cobrar
            self::registrarCuentasPorCobrarFinanciamiento($pdo, $medicoId, $pacienteId, $citaId, $pacInfo, $input);

            echo json_encode([
                "status"      => "success",
                "mensaje"     => "¡Cita reservada exitosamente! Te esperamos en consulta.",
                "cita_id"     => $citaId,
                "paciente_id" => $pacienteId,
            ], JSON_UNESCAPED_UNICODE);

        } catch (Exception $e) {
            http_response_code(500);
            echo json_encode(["status" => "error", "mensaje" => $e->getMessage()], JSON_UNESCAPED_UNICODE);
        }
    }

    /**
     * POST /api/publico/biolink/presupuesto
     * Solo acepta servicios de contado (permite_financiamiento = 0 y cantidad_cuotas <= 1)
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
            $pacInfo = self::obtenerOCrearPaciente($pdo, $input, $medicoId);
            $pacienteId = $pacInfo['paciente_id'];

            // Obtener tasa BCV actual si existe
            $tasaBcv = self::obtenerTasaBcvActual($pdo);

            $idsLimpios = array_values(array_filter(array_map('intval', $serviciosIds), fn($v) => $v > 0));
            $placeholders = implode(',', array_fill(0, count($idsLimpios), '?'));

            // Filtrar estrictamente servicios de contado (sin financiamiento y 1 cuota)
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
                $totalUsd += $precio;
                $items[] = [
                    'id'           => intval($it['id'] ?? 0),
                    'servicio_id'  => intval($it['id'] ?? 0),
                    'nombre'       => $it['nombre'] ?? $it['nombre_servicio'] ?? $it['titulo'] ?? 'Servicio',
                    'descripcion'  => $it['nombre'] ?? $it['nombre_servicio'] ?? $it['titulo'] ?? 'Servicio',
                    'cantidad'     => 1,
                    'precio_usd'   => $precio,
                    'subtotal_usd' => $precio,
                ];
            }
            $totalBs = round($totalUsd * $tasaBcv, 2);

            // Correlativo de presupuesto del médico
            $colsPres = self::columnasTabla($pdo, 'gm_presupuestos');
            $stmtCorr = $pdo->prepare("SELECT COUNT(*) AS cnt FROM gm_presupuestos WHERE medico_id = ?");
            $stmtCorr->execute([$medicoId]);
            $cntExistentes = intval($stmtCorr->fetch(PDO::FETCH_ASSOC)['cnt'] ?? 0);

            $stmtIni = $pdo->prepare("SELECT meta_value FROM gm_medico_meta WHERE medico_id = ? AND meta_key = 'inicio_presupuesto' LIMIT 1");
            $stmtIni->execute([$medicoId]);
            $iniPres = intval($stmtIni->fetchColumn() ?: 1);
            $numeroPres = $iniPres + $cntExistentes;

            $fechaEmision     = date('Y-m-d H:i:s');
            $fechaVencimiento = date('Y-m-d', strtotime('+3 days'));

            $campos = ['medico_id', 'paciente_id', 'total_usd'];
            $valores = [$medicoId, $pacienteId, $totalUsd];

            if (in_array('estado', $colsPres, true)) {
                $campos[] = 'estado';
                $valores[] = 'pendiente';
            } elseif (in_array('estatus', $colsPres, true)) {
                $campos[] = 'estatus';
                $valores[] = 'pendiente';
            }

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
            if (in_array('numero_presupuesto', $colsPres, true)) {
                $campos[] = 'numero_presupuesto';
                $valores[] = str_pad((string)$numeroPres, 6, '0', STR_PAD_LEFT);
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

            echo json_encode([
                "status"            => "success",
                "mensaje"           => "Presupuesto #$presupuestoId generado correctamente.",
                "presupuesto_id"    => $presupuestoId,
                "paciente_id"       => $pacienteId,
                "fecha_vencimiento" => $fechaVencimiento,
                "pdf_url"           => $pdfUrl,
            ], JSON_UNESCAPED_UNICODE);

        } catch (Exception $e) {
            http_response_code(500);
            echo json_encode(["status" => "error", "mensaje" => $e->getMessage()], JSON_UNESCAPED_UNICODE);
        }
    }
}