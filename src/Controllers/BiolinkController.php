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

    private static function columnasTabla(PDO $pdo, string $tabla): array {
        $stmt = $pdo->query("DESCRIBE `$tabla`");
        $cols = [];
        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $cols[] = $row['Field'];
        }
        return $cols;
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

            // Metadatos
            $stmtMeta = $pdo->prepare("SELECT meta_key, meta_value FROM gm_medico_meta WHERE medico_id IN ($placeholders)");
            $stmtMeta->execute($ids);
            $metas = [];
            while ($m = $stmtMeta->fetch(PDO::FETCH_ASSOC)) {
                $metas[$m['meta_key']] = $m['meta_value'];
            }

            // Sedes
            $sedesRaw = json_decode($metas['sedes_multiples'] ?? '[]', true);
            $sedes = [];
            if (is_array($sedesRaw)) {
                foreach ($sedesRaw as $idx => $s) {
                    if (is_array($s) && !empty($s['direccion'])) {
                        $sedes[] = [
                            'id'          => $idx + 1,
                            'nombre_sede' => !empty($s['estado']) ? "Consultorio {$s['estado']}" : "Sede #" . ($idx + 1),
                            'pais'        => $s['pais'] ?? 'Venezuela',
                            'estado'      => $s['estado'] ?? '',
                            'direccion'   => $s['direccion'] ?? '',
                            'lat'         => $s['lat'] ?? '',
                            'lng'         => $s['lng'] ?? '',
                        ];
                    }
                }
            }

            // Servicios activos (detectando nombres reales de columnas)
            $colsSrv = self::columnasTabla($pdo, 'gm_servicios');
            $whereEstado = in_array('estado', $colsSrv, true)
                ? "AND (estado = 'activo' OR estado IS NULL OR estado = '')"
                : "";
            $stmtSrv = $pdo->prepare("SELECT * FROM gm_servicios WHERE medico_id IN ($placeholders) $whereEstado");
            $stmtSrv->execute($ids);
            $serviciosRaw = $stmtSrv ? $stmtSrv->fetchAll(PDO::FETCH_ASSOC) : [];

            $servicios = [];
            foreach ($serviciosRaw as $sr) {
                $servicios[] = [
                    'id'          => intval($sr['id'] ?? 0),
                    'nombre'      => $sr['nombre'] ?? $sr['nombre_servicio'] ?? $sr['titulo'] ?? 'Servicio Médico',
                    'descripcion' => $sr['descripcion'] ?? $sr['detalle'] ?? '',
                    'precio_usd'  => $sr['precio_usd'] ?? $sr['precio'] ?? $sr['monto_usd'] ?? '0',
                    'tipo'        => $sr['tipo'] ?? 'servicio',
                ];
            }

            // Redes sociales
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

            // Avatar HTTPS
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
                    "sedes"     => $sedes,
                    "servicios" => $servicios,
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
            if (strlen($whatsapp) < 10) {
                http_response_code(400);
                echo json_encode(["status" => "error", "mensaje" => "Número de WhatsApp inválido."], JSON_UNESCAPED_UNICODE);
                return;
            }

            $ultimos10 = substr($whatsapp, -10);
            $pdo = Database::conectar();
            $stmt = $pdo->prepare("
                SELECT id, user_id, nombre, apellido, cedula, email, whatsapp
                FROM gm_usuarios_extendidos
                WHERE REPLACE(REPLACE(REPLACE(whatsapp, '+', ''), ' ', ''), '-', '') LIKE ?
                LIMIT 1
            ");
            $stmt->execute(['%' . $ultimos10]);
            $pac = $stmt->fetch(PDO::FETCH_ASSOC);

            if ($pac) {
                echo json_encode([
                    "status"     => "success",
                    "encontrado" => true,
                    "paciente"   => [
                        "id"       => intval($pac['user_id'] ?? $pac['id']),
                        "user_id"  => intval($pac['user_id'] ?? $pac['id']),
                        "nombre"   => $pac['nombre'] ?? '',
                        "apellido" => $pac['apellido'] ?? '',
                        "cedula"   => $pac['cedula'] ?? '',
                        "email"    => $pac['email'] ?? '',
                        "whatsapp" => $pac['whatsapp'] ?? $whatsapp,
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
     * POST /api/publico/biolink/agendar
     */
    public static function agendarCita(array $input) {
        try {
            $medicoId  = intval($input['medico_id'] ?? 0);
            $whatsapp  = self::normalizarWhatsapp((string)($input['whatsapp'] ?? ''));
            $nombre    = trim((string)($input['nombre'] ?? ''));
            $apellido  = trim((string)($input['apellido'] ?? ''));
            $cedula    = preg_replace('/\D/', '', (string)($input['cedula'] ?? ''));
            $email     = trim((string)($input['email'] ?? ''));
            $fechaCita = trim((string)($input['fecha_cita'] ?? ''));
            $horaCita  = trim((string)($input['hora_cita'] ?? '08:00'));
            $motivo    = trim((string)($input['motivo'] ?? 'Cita agendada desde BioLink'));

            if ($medicoId <= 0 || $nombre === '' || $apellido === '' || $fechaCita === '') {
                http_response_code(400);
                echo json_encode(["status" => "error", "mensaje" => "Complete todos los datos obligatorios de la cita."], JSON_UNESCAPED_UNICODE);
                return;
            }

            $pdo = Database::conectar();
            $pacienteId = intval($input['paciente_id'] ?? 0);

            if ($pacienteId <= 0 && strlen($whatsapp) >= 10) {
                $ultimos10 = substr($whatsapp, -10);
                $stmtPac = $pdo->prepare("
                    SELECT id, user_id FROM gm_usuarios_extendidos
                    WHERE REPLACE(REPLACE(REPLACE(whatsapp, '+', ''), ' ', ''), '-', '') LIKE ?
                    LIMIT 1
                ");
                $stmtPac->execute(['%' . $ultimos10]);
                $rowPac = $stmtPac->fetch(PDO::FETCH_ASSOC);

                if ($rowPac) {
                    $pacienteId = intval($rowPac['user_id'] ?? $rowPac['id']);
                } else {
                    $stmtMax = $pdo->query("SELECT COALESCE(MAX(user_id), 1000) + 1 AS sig FROM gm_usuarios_extendidos");
                    $pacienteId = intval($stmtMax->fetch(PDO::FETCH_ASSOC)['sig']);
                    $stmtInsPac = $pdo->prepare("
                        INSERT INTO gm_usuarios_extendidos
                        (user_id, tipo_identificacion, cedula, whatsapp, nombre, apellido, edad, sexo, email, rol_medico, creado_por)
                        VALUES (?, 'V', ?, ?, ?, ?, 0, 'Femenino', ?, 'gm_paciente', ?)
                    ");
                    $stmtInsPac->execute([$pacienteId, $cedula, $whatsapp, $nombre, $apellido, $email, $medicoId]);
                }
            }

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
            $whatsapp     = self::normalizarWhatsapp((string)($input['whatsapp'] ?? ''));
            $nombre       = trim((string)($input['nombre'] ?? ''));
            $apellido     = trim((string)($input['apellido'] ?? ''));
            $cedula       = preg_replace('/\D/', '', (string)($input['cedula'] ?? ''));
            $email        = trim((string)($input['email'] ?? ''));
            $serviciosIds = is_array($input['servicios_ids'] ?? null) ? $input['servicios_ids'] : [];
            $obs          = trim((string)($input['observaciones'] ?? 'Solicitud desde BioLink'));

            if ($medicoId <= 0 || $nombre === '' || empty($serviciosIds)) {
                http_response_code(400);
                echo json_encode(["status" => "error", "mensaje" => "Datos incompletos para generar el presupuesto."], JSON_UNESCAPED_UNICODE);
                return;
            }

            $pdo = Database::conectar();
            $pacienteId = intval($input['paciente_id'] ?? 0);

            if ($pacienteId <= 0 && strlen($whatsapp) >= 10) {
                $ultimos10 = substr($whatsapp, -10);
                $stmtPac = $pdo->prepare("
                    SELECT id, user_id FROM gm_usuarios_extendidos
                    WHERE REPLACE(REPLACE(REPLACE(whatsapp, '+', ''), ' ', ''), '-', '') LIKE ?
                    LIMIT 1
                ");
                $stmtPac->execute(['%' . $ultimos10]);
                $rowPac = $stmtPac->fetch(PDO::FETCH_ASSOC);

                if ($rowPac) {
                    $pacienteId = intval($rowPac['user_id'] ?? $rowPac['id']);
                } else {
                    $stmtMax = $pdo->query("SELECT COALESCE(MAX(user_id), 1000) + 1 AS sig FROM gm_usuarios_extendidos");
                    $pacienteId = intval($stmtMax->fetch(PDO::FETCH_ASSOC)['sig']);
                    $stmtInsPac = $pdo->prepare("
                        INSERT INTO gm_usuarios_extendidos
                        (user_id, tipo_identificacion, cedula, whatsapp, nombre, apellido, edad, sexo, email, rol_medico, creado_por)
                        VALUES (?, 'V', ?, ?, ?, ?, 0, 'Femenino', ?, 'gm_paciente', ?)
                    ");
                    $stmtInsPac->execute([$pacienteId, $cedula, $whatsapp, $nombre, $apellido, $email, $medicoId]);
                }
            }

            $idsLimpios = array_values(array_filter(array_map('intval', $serviciosIds), fn($v) => $v > 0));
            $placeholders = implode(',', array_fill(0, count($idsLimpios), '?'));
            $stmtSrv = $pdo->prepare("SELECT * FROM gm_servicios WHERE id IN ($placeholders)");
            $stmtSrv->execute($idsLimpios);
            $itemsRaw = $stmtSrv->fetchAll(PDO::FETCH_ASSOC);

            $items = [];
            $totalUsd = 0.0;
            foreach ($itemsRaw as $it) {
                $precio = floatval($it['precio_usd'] ?? $it['precio'] ?? $it['monto_usd'] ?? 0);
                $totalUsd += $precio;
                $items[] = [
                    'id'         => intval($it['id'] ?? 0),
                    'nombre'     => $it['nombre'] ?? $it['nombre_servicio'] ?? $it['titulo'] ?? 'Servicio',
                    'precio_usd' => $precio,
                ];
            }

            $colsPres = self::columnasTabla($pdo, 'gm_presupuestos');
            $campos = ['medico_id', 'paciente_id', 'total_usd', 'estado'];
            $valores = [$medicoId, $pacienteId, $totalUsd, 'pendiente'];

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
                $valores[] = date('Y-m-d H:i:s');
            }

            $colsSql = implode(', ', $campos);
            $marksSql = implode(', ', array_fill(0, count($valores), '?'));
            $stmtPres = $pdo->prepare("INSERT INTO gm_presupuestos ($colsSql) VALUES ($marksSql)");
            $stmtPres->execute($valores);

            echo json_encode([
                "status"         => "success",
                "mensaje"        => "Solicitud de presupuesto registrada correctamente.",
                "presupuesto_id" => intval($pdo->lastInsertId())
            ], JSON_UNESCAPED_UNICODE);

        } catch (Exception $e) {
            http_response_code(500);
            echo json_encode(["status" => "error", "mensaje" => $e->getMessage()], JSON_UNESCAPED_UNICODE);
        }
    }
}