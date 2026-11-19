<?php
namespace App\Controllers;

use App\Config\Database;
use PDO;
use Exception;

class AjustesMedicoController {

    private static function asegurarTablasAuxiliares(PDO $pdo) {$pdo->exec("CREATE TABLE IF NOT EXISTS gm_medico_meta (
            id INT AUTO_INCREMENT PRIMARY KEY,
            medico_id INT NOT NULL,
            meta_key VARCHAR(100) NOT NULL,
            meta_value LONGTEXT NULL,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            UNIQUE KEY idx_medico_key (medico_id, meta_key)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;");
    }

    private static function obtenerMeta(PDO $pdo, int$medicoId): array {
        $stmt =$pdo->prepare("SELECT meta_key, meta_value FROM gm_medico_meta WHERE medico_id = ?");
        $stmt->execute([$medicoId]);$metas = [];
        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {$metas[$row['meta_key']] =$row['meta_value'];
        }
        return $metas;
    }

    private static function guardarMeta(PDO $pdo, int$medicoId, string $key,$value) {
        $stmt =$pdo->prepare("INSERT INTO gm_medico_meta (medico_id, meta_key, meta_value)
                               VALUES (?, ?, ?)
                               ON DUPLICATE KEY UPDATE meta_value = VALUES(meta_value)");
        $stmt->execute([$medicoId, $key,$value]);
    }

    /**
     * GET /api/medico/ajustes?medico_id=X
     */
    public static function obtenerTodo() {
        try {
            $medicoId = isset($_GET['medico_id']) ? intval($_GET['medico_id']) : 0;
            if ($medicoId <= 0) {
                http_response_code(400);
                echo json_encode(["status" => "error", "mensaje" => "ID de médico inválido"], JSON_UNESCAPED_UNICODE);
                return;
            }

            $pdo = Database::conectar();
            self::asegurarTablasAuxiliares($pdo);

            // 1. Perfil base desde gm_usuarios_extendidos
            $stmtPerf =$pdo->prepare("SELECT * FROM gm_usuarios_extendidos WHERE user_id = ? OR id = ? LIMIT 1");
            $stmtPerf->execute([$medicoId,$medicoId]);
            $perfilBase =$stmtPerf->fetch(PDO::FETCH_ASSOC) ?: [];
            $realMedicoId = !empty($perfilBase['user_id']) ? intval($perfilBase['user_id']) :$medicoId;

            // 2. Catálogo de especialidades desde gm_especialidades
            $stmtEsp =$pdo->query("SELECT id, nombre, slug FROM gm_especialidades ORDER BY nombre ASC");
            $especialidades = $stmtEsp ? $stmtEsp->fetchAll(PDO::FETCH_ASSOC) : [];

            // 3. Metadatos de los 5 bloques
            $metas = self::obtenerMeta($pdo,$realMedicoId);

            $redes = json_decode($metas['redes_sociales'] ?? '{}', true);
            if (!is_array($redes))$redes = [];

            $sedesRaw = json_decode($metas['sedes_multiples'] ?? '[]', true);$sedes = [];
            if (is_array($sedesRaw)) {
                foreach ($sedesRaw as$s) {
                    if (is_string($s)) {
                        $sedes[] = ['pais' => 'Venezuela', 'estado' => '', 'direccion' =>$s, 'lat' => '', 'lng' => ''];
                    } elseif (is_array($s)) {$sedes[] = [
                            'pais'      => $s['pais'] ?? 'Venezuela',
                            'estado'    => $s['estado'] ?? '',
                            'direccion' => $s['direccion'] ?? '',
                            'lat'       => $s['lat'] ?? '',
                            'lng'       => $s['lng'] ?? '',
                        ];
                    }
                }
            }

            $perfilCompleto = array_merge($perfilBase, [
                // Bloque 1: Perfil Público y Presencia Digital
                'avatar_url'      => $metas['avatar_url'] ?? '',
                'tagline'         => $metas['tagline'] ?? '',
                'bio'             => $metas['bio'] ?? '',
                'instagram'       => $redes['instagram'] ?? '',
                'linkedin'        => $redes['linkedin'] ?? '',
                'x_twitter'       => $redes['x_twitter'] ?? '',
                'web'             => $redes['web'] ?? '',

                // Bloque 2: Datos Personales
                'segundo_nombre'  => $metas['segundo_nombre'] ?? '',
                'segundo_apellido'=> $metas['segundo_apellido'] ?? '',

                // Bloque 3: Datos Profesionales
                'ocupacion'       => $metas['ocupacion'] ?? ($perfilBase['ocupacion'] ?? 'Médico Cirujano'),
                'universidad'     => $metas['universidad'] ?? '',
                'especialidad'    => $metas['especialidad'] ?? ($perfilBase['especialidad'] ?? ''),
                'mpps'            => $metas['mpps'] ?? '',
                'cmm'             => $metas['cmm'] ?? '',

                // Bloque 4: Datos Fiscales
                'rif_tipo'        => $metas['rif_tipo'] ?? 'V',
                'rif_num'         => $metas['rif_num'] ?? '',
                'pais_fiscal'     => $metas['pais_fiscal'] ?? 'Venezuela',
                'estado_fiscal'   => $metas['estado_fiscal'] ?? '',
                'direccion_fiscal'=> $metas['direccion_fiscal'] ?? ($perfilBase['direccion_detalle'] ?? ''),

                // Bloque 5: Sedes de Atención
                'sedes'           => $sedes
            ]);

            // 4. Calendarios del médico
            $stmtCal =$pdo->prepare("SELECT * FROM gm_calendarios WHERE medico_id = ? ORDER BY id DESC");
            $stmtCal->execute([$realMedicoId]);$calendariosRaw = $stmtCal->fetchAll(PDO::FETCH_ASSOC);$calendarios = [];
            foreach ($calendariosRaw as $cal) {$cal['dias_config'] = !empty($cal['dias_config']) ? json_decode($cal['dias_config'], true) : [];
                $calendarios[] =$cal;
            }

            // 5. Equipo de Asistentes
            $stmtAsis =$pdo->prepare("
                SELECT u.* FROM gm_usuarios_extendidos u
                INNER JOIN gm_medico_meta m ON m.meta_key = CONCAT('asistente_', u.user_id) AND m.medico_id = ?
                WHERE u.rol_medico = 'gm_asistente'
                ORDER BY u.id DESC
            ");
            $stmtAsis->execute([$realMedicoId]);
            $asistentes =$stmtAsis->fetchAll(PDO::FETCH_ASSOC);

            echo json_encode([
                "status" => "success",
                "data"   => [
                    "perfil"         => $perfilCompleto,
                    "especialidades" => $especialidades,
                    "calendarios"    => $calendarios,
                    "asistentes"     => $asistentes
                ]
            ], JSON_UNESCAPED_UNICODE);

        } catch (Exception $e) {
            http_response_code(500);
            echo json_encode(["status" => "error", "mensaje" => $e->getMessage()], JSON_UNESCAPED_UNICODE);
        }
    }

    /**
     * POST /api/medico/perfil (Guardado autónomo por bloque)
     */
    public static function guardarPerfil() {
        try {
            $input = json_decode(file_get_contents('php://input'), true) ?: [];
            $medicoId = intval($input['medico_id'] ?? 0);
            $seccion  = trim($input['seccion'] ?? 'todo');

            if ($medicoId <= 0) {
                http_response_code(400);
                echo json_encode(["status" => "error", "mensaje" => "ID de médico requerido"], JSON_UNESCAPED_UNICODE);
                return;
            }

            $pdo = Database::conectar();
            self::asegurarTablasAuxiliares($pdo);

            switch ($seccion) {
                case 'publico':
                    self::guardarMeta($pdo, $medicoId, 'avatar_url', trim($input['avatar_url'] ?? ''));
                    self::guardarMeta($pdo, $medicoId, 'tagline', trim($input['tagline'] ?? ''));
                    self::guardarMeta($pdo, $medicoId, 'bio', trim($input['bio'] ?? ''));
                    $redes = [
                        'instagram' => trim($input['instagram'] ?? ''),
                        'linkedin'  => trim($input['linkedin'] ?? ''),
                        'x_twitter' => trim($input['x_twitter'] ?? ''),
                        'web'       => trim($input['web'] ?? ''),
                    ];
                    self::guardarMeta($pdo, $medicoId, 'redes_sociales', json_encode($redes, JSON_UNESCAPED_UNICODE));
                    $msg = "Perfil público y presencia digital actualizados.";
                    break;

                case 'personales':
                    $nombre   = trim($input['nombre'] ?? '');
                    $apellido = trim($input['apellido'] ?? '');
                    $sexo     = trim($input['sexo'] ?? 'Femenino');
                    $fnac     = trim($input['fecha_nacimiento'] ?? '');

                    $stmt = $pdo->prepare("
                        UPDATE gm_usuarios_extendidos
                        SET nombre = ?, apellido = ?, sexo = ?, fecha_nacimiento = ?
                        WHERE user_id = ? OR id = ?
                    ");
                    $stmt->execute([$nombre, $apellido, $sexo, !empty($fnac) ? $fnac : null, $medicoId, $medicoId]);

                    self::guardarMeta($pdo, $medicoId, 'segundo_nombre', trim($input['segundo_nombre'] ?? ''));
                    self::guardarMeta($pdo, $medicoId, 'segundo_apellido', trim($input['segundo_apellido'] ?? ''));
                    $msg = "Datos personales actualizados.";
                    break;

                case 'profesionales':
                    self::guardarMeta($pdo, $medicoId, 'ocupacion', trim($input['ocupacion'] ?? ''));
                    self::guardarMeta($pdo, $medicoId, 'universidad', trim($input['universidad'] ?? ''));
                    self::guardarMeta($pdo, $medicoId, 'especialidad', trim($input['especialidad'] ?? ''));
                    self::guardarMeta($pdo, $medicoId, 'mpps', trim($input['mpps'] ?? ''));
                    self::guardarMeta($pdo, $medicoId, 'cmm', trim($input['cmm'] ?? ''));
                    $msg = "Datos profesionales actualizados.";
                    break;

                case 'fiscales':
                    self::guardarMeta($pdo, $medicoId, 'rif_tipo', trim($input['rif_tipo'] ?? 'V'));
                    self::guardarMeta($pdo, $medicoId, 'rif_num', trim($input['rif_num'] ?? ''));
                    self::guardarMeta($pdo, $medicoId, 'pais_fiscal', trim($input['pais_fiscal'] ?? 'Venezuela'));
                    self::guardarMeta($pdo, $medicoId, 'estado_fiscal', trim($input['estado_fiscal'] ?? ''));
                    self::guardarMeta($pdo, $medicoId, 'direccion_fiscal', trim($input['direccion_fiscal'] ?? ''));
                    $msg = "Datos fiscales actualizados.";
                    break;

                case 'sedes':
                    $sedesLimpias = [];
                    if (isset($input['sedes']) && is_array($input['sedes'])) {
                        foreach ($input['sedes'] as $s) {
                            if (is_array($s) && trim($s['direccion'] ?? '') !== '') {
                                $sedesLimpias[] = [
                                    'pais'      => trim($s['pais'] ?? 'Venezuela'),
                                    'estado'    => trim($s['estado'] ?? ''),
                                    'direccion' => trim($s['direccion'] ?? ''),
                                    'lat'       => trim($s['lat'] ?? ''),
                                    'lng'       => trim($s['lng'] ?? ''),
                                ];
                            }
                        }
                    }
                    self::guardarMeta($pdo, $medicoId, 'sedes_multiples', json_encode($sedesLimpias, JSON_UNESCAPED_UNICODE));
                    $msg = "Sedes de atención actualizadas.";
                    break;

                default:
                    $msg = "Cambios guardados.";
                    break;
            }

            echo json_encode(["status" => "success", "mensaje" => $msg], JSON_UNESCAPED_UNICODE);

        } catch (Exception $e) {
            http_response_code(500);
            echo json_encode(["status" => "error", "mensaje" => $e->getMessage()], JSON_UNESCAPED_UNICODE);
        }
    }

    /**
     * POST /api/medico/calendarios (Crear o Editar calendario)
     */
    public static function guardarCalendario() {
        try {
            $input = json_decode(file_get_contents('php://input'), true) ?: [];
            $medicoId = intval($input['medico_id'] ?? 0);
            $calId    = intval($input['id'] ?? 0);

            if ($medicoId <= 0) {
                http_response_code(400);
                echo json_encode(["status" => "error", "mensaje" => "ID de médico requerido"], JSON_UNESCAPED_UNICODE);
                return;
            }

            $pdo = Database::conectar();
            self::asegurarTablasAuxiliares($pdo);

            $metas = self::obtenerMeta($pdo, $medicoId);
            $rifNum = trim($metas['rif_num'] ?? '');
            $sedes  = json_decode($metas['sedes_multiples'] ?? '[]', true);

            if ($rifNum === '' || empty($sedes)) {
                http_response_code(422);
                echo json_encode([
                    "status"  => "error",
                    "mensaje" => "Debe registrar su RIF en Datos Fiscales y al menos una Sede de Atención antes de crear calendarios."
                ], JSON_UNESCAPED_UNICODE);
                return;
            }

            $nombreCal = trim($input['nombre_calendario'] ?? '');
            if ($nombreCal === '') {
                http_response_code(400);
                echo json_encode(["status" => "error", "mensaje" => "El nombre del calendario es obligatorio."], JSON_UNESCAPED_UNICODE);
                return;
            }

            $diasConfigJson     = json_encode($input['dias_config'] ?? [], JSON_UNESCAPED_UNICODE);
            $duracionBloque     = intval($input['duracion_bloque'] ?? 30);
            $cuposPorBloque     = intval($input['cupos_por_bloque'] ?? 1);
            $appointmentBuffer  = trim($input['appointment_buffer'] ?? '24 hours');
            $cancellationBuffer = trim($input['cancellation_buffer'] ?? '24 hours');
            $preventAfterDate   = !empty($input['prevent_after_date']) ? trim($input['prevent_after_date']) : null;
            $defaultStatus      = trim($input['default_status'] ?? 'reservada');

            if ($calId > 0) {
                $stmt = $pdo->prepare("
                    UPDATE gm_calendarios
                    SET nombre_calendario = ?, dias_config = ?, duracion_bloque = ?, cupos_por_bloque = ?,
                        appointment_buffer = ?, cancellation_buffer = ?, prevent_after_date = ?, default_status = ?
                    WHERE id = ? AND medico_id = ?
                ");
                $stmt->execute([
                    $nombreCal, $diasConfigJson, $duracionBloque, $cuposPorBloque,
                    $appointmentBuffer, $cancellationBuffer, $preventAfterDate, $defaultStatus,
                    $calId, $medicoId
                ]);
                $msg = "Calendario actualizado correctamente.";
            } else {
                $stmt = $pdo->prepare("
                    INSERT INTO gm_calendarios
                    (medico_id, nombre_calendario, dias_config, duracion_bloque, cupos_por_bloque, appointment_buffer, cancellation_buffer, prevent_after_date, default_status, estado)
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, 'activo')
                ");
                $stmt->execute([
                    $medicoId, $nombreCal, $diasConfigJson, $duracionBloque, $cuposPorBloque,
                    $appointmentBuffer, $cancellationBuffer, $preventAfterDate, $defaultStatus
                ]);
                $msg = "Calendario creado con éxito.";
            }

            echo json_encode(["status" => "success", "mensaje" => $msg], JSON_UNESCAPED_UNICODE);

        } catch (Exception $e) {
            http_response_code(500);
            echo json_encode(["status" => "error", "mensaje" => $e->getMessage()], JSON_UNESCAPED_UNICODE);
        }
    }

    /**
     * DELETE /api/medico/calendarios?id=X&medico_id=Y
     */
    public static function eliminarCalendario() {
        try {
            $id       = intval($_GET['id'] ?? 0);
            $medicoId = intval($_GET['medico_id'] ?? 0);

            if ($id <= 0 || $medicoId <= 0) {
                http_response_code(400);
                echo json_encode(["status" => "error", "mensaje" => "Parámetros inválidos"], JSON_UNESCAPED_UNICODE);
                return;
            }

            $pdo = Database::conectar();
            $stmt = $pdo->prepare("DELETE FROM gm_calendarios WHERE id = ? AND medico_id = ?");
            $stmt->execute([$id, $medicoId]);

            echo json_encode(["status" => "success", "mensaje" => "Calendario eliminado."], JSON_UNESCAPED_UNICODE);
        } catch (Exception $e) {
            http_response_code(500);
            echo json_encode(["status" => "error", "mensaje" => $e->getMessage()], JSON_UNESCAPED_UNICODE);
        }
    }

    /**
     * POST /api/medico/asistentes
     */
    public static function crearAsistente() {
        try {
            $input = json_decode(file_get_contents('php://input'), true) ?: [];
            $medicoId = intval($input['medico_id'] ?? 0);
            $nombre   = trim($input['nombre'] ?? '');
            $apellido = trim($input['apellido'] ?? '');
            $cedula   = trim($input['cedula'] ?? '');
            $whatsapp = trim($input['whatsapp'] ?? '');
            $email    = trim($input['email'] ?? '');

            if ($medicoId <= 0 || $nombre === '' || $cedula === '' || $whatsapp === '') {
                http_response_code(400);
                echo json_encode(["status" => "error", "mensaje" => "Complete todos los campos obligatorios del asistente."], JSON_UNESCAPED_UNICODE);
                return;
            }

            $pdo = Database::conectar();
            self::asegurarTablasAuxiliares($pdo);

            $stmtCheck = $pdo->prepare("SELECT id, user_id FROM gm_usuarios_extendidos WHERE cedula = ? OR whatsapp = ? LIMIT 1");
            $stmtCheck->execute([$cedula, $whatsapp]);
            if ($stmtCheck->fetch(PDO::FETCH_ASSOC)) {
                http_response_code(409);
                echo json_encode(["status" => "error", "mensaje" => "Ya existe un usuario registrado con esa cédula o WhatsApp."], JSON_UNESCAPED_UNICODE);
                return;
            }

            $stmtMax = $pdo->query("SELECT COALESCE(MAX(user_id), 1000) + 1 AS siguiente FROM gm_usuarios_extendidos");
            $nuevoUserId = intval($stmtMax->fetch(PDO::FETCH_ASSOC)['siguiente']);

            $stmtIns = $pdo->prepare("
                INSERT INTO gm_usuarios_extendidos
                (user_id, tipo_identificacion, cedula, whatsapp, nombre, apellido, edad, sexo, email, rol_medico)
                VALUES (?, 'V', ?, ?, ?, ?, 0, 'Femenino', ?, 'gm_asistente')
            ");
            $stmtIns->execute([$nuevoUserId, $cedula, $whatsapp, $nombre, $apellido, $email]);

            self::guardarMeta($pdo, $medicoId, 'asistente_' . $nuevoUserId, (string)$nuevoUserId);

            echo json_encode([
                "status"  => "success",
                "mensaje" => "Asistente registrado y vinculado exitosamente."
            ], JSON_UNESCAPED_UNICODE);

        } catch (Exception $e) {
            http_response_code(500);
            echo json_encode(["status" => "error", "mensaje" => $e->getMessage()], JSON_UNESCAPED_UNICODE);
        }
    }

    /**
     * DELETE /api/medico/asistentes?user_id=X&medico_id=Y
     */
    public static function eliminarAsistente() {
        try {
            $asisUserId = intval($_GET['user_id'] ?? 0);
            $medicoId   = intval($_GET['medico_id'] ?? 0);

            if ($asisUserId <= 0 || $medicoId <= 0) {
                http_response_code(400);
                echo json_encode(["status" => "error", "mensaje" => "Parámetros inválidos"], JSON_UNESCAPED_UNICODE);
                return;
            }

            $pdo = Database::conectar();
            $stmtMeta = $pdo->prepare("DELETE FROM gm_medico_meta WHERE medico_id = ? AND meta_key = ?");
            $stmtMeta->execute([$medicoId, 'asistente_' . $asisUserId]);

            $stmtUser = $pdo->prepare("DELETE FROM gm_usuarios_extendidos WHERE user_id = ? AND rol_medico = 'gm_asistente'");
            $stmtUser->execute([$asisUserId]);

            echo json_encode(["status" => "success", "mensaje" => "Asistente eliminado correctamente."], JSON_UNESCAPED_UNICODE);
        } catch (Exception $e) {
            http_response_code(500);
            echo json_encode(["status" => "error", "mensaje" => $e->getMessage()], JSON_UNESCAPED_UNICODE);
        }
    }
}