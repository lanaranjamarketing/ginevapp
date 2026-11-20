<?php
namespace App\Controllers;

use App\Config\Database;
use PDO;
use Exception;

class CitaController {

    /**
     * GET /api/citas?medico_id=X&paciente_id=Y&fecha=YYYY-MM-DD
     */
    public static function listar() {
        try {
            $pdo = Database::conectar();
            $condiciones = ["c.estado_cita COLLATE utf8mb4_unicode_ci != 'cancelada' COLLATE utf8mb4_unicode_ci"];
            $params = [];

            if (!empty($_GET['incluir_canceladas']) && $_GET['incluir_canceladas'] === '1') {
                $condiciones = ["1=1"];
            }

            if (!empty($_GET['medico_id'])) {
                $condiciones[] = "c.medico_id = :med";
                $params[':med'] = (int)$_GET['medico_id'];
            }

            if (!empty($_GET['paciente_id'])) {
                $condiciones[] = "c.paciente_id = :pac";
                $params[':pac'] = (int)$_GET['paciente_id'];
            }

            if (!empty($_GET['fecha'])) {
                $condiciones[] = "c.fecha_cita = :fecha";
                $params[':fecha'] = trim($_GET['fecha']);
            }

            $whereSql = implode(" AND ", $condiciones);

            $sql = "SELECT 
                        c.*,
                        CONCAT(p.nombre, ' ', p.apellido) AS paciente_nombre,
                        p.cedula AS paciente_cedula,
                        p.whatsapp AS paciente_whatsapp,
                        CONCAT(m.nombre, ' ', m.apellido) AS medico_nombre,
                        m.especialidad AS medico_especialidad,
                        ec.nombre AS estado_nombre,
                        ec.color_hex AS estado_color
                    FROM gm_citas c
                    LEFT JOIN gm_usuarios_extendidos p ON c.paciente_id = p.user_id
                    LEFT JOIN gm_usuarios_extendidos m ON c.medico_id = m.user_id
                    LEFT JOIN gm_estatus_cita ec ON c.estado_cita COLLATE utf8mb4_unicode_ci = ec.slug COLLATE utf8mb4_unicode_ci
                    WHERE $whereSql
                    ORDER BY c.fecha_cita ASC, c.hora_cita ASC";

            $stmt = $pdo->prepare($sql);
            $stmt->execute($params);
            $citas = $stmt->fetchAll(PDO::FETCH_ASSOC);

            echo json_encode([
                "status" => "success",
                "total"  => count($citas),
                "data"   => $citas
            ], JSON_UNESCAPED_UNICODE);

        } catch (Exception $e) {
            http_response_code(500);
            echo json_encode(["status" => "error", "mensaje" => $e->getMessage()], JSON_UNESCAPED_UNICODE);
        }
    }

    /**
     * POST /api/citas
     * Agenda una nueva cita verificando cupos disponibles
     */
    public static function crear($input) {
        try {
            $requeridos = ['calendario_id', 'paciente_id', 'medico_id', 'fecha_cita', 'hora_cita'];
            foreach ($requeridos as $campo) {
                if (empty($input[$campo])) {
                    http_response_code(400);
                    echo json_encode(["status" => "error", "mensaje" => "El campo '$campo' es obligatorio."], JSON_UNESCAPED_UNICODE);
                    return;
                }
            }

            $pdo = Database::conectar();

            // Verificar capacidad del bloque en el calendario
            $calStmt = $pdo->prepare("SELECT cupos_por_bloque, default_status FROM gm_calendarios WHERE id = :id LIMIT 1");
            $calStmt->execute([':id' => (int)$input['calendario_id']]);
            $cal = $calStmt->fetch(PDO::FETCH_ASSOC);

            if (!$cal) {
                http_response_code(404);
                echo json_encode(["status" => "error", "mensaje" => "Calendario no encontrado."], JSON_UNESCAPED_UNICODE);
                return;
            }

            $horaNormalizada = strlen($input['hora_cita']) === 5 ? $input['hora_cita'] . ':00' : $input['hora_cita'];

            $ocupStmt = $pdo->prepare("SELECT COUNT(*) FROM gm_citas WHERE medico_id = :med AND fecha_cita = :fecha AND hora_cita = :hora AND estado_cita COLLATE utf8mb4_unicode_ci != 'cancelada' COLLATE utf8mb4_unicode_ci");
            $ocupStmt->execute([
                ':med'   => (int)$input['medico_id'],
                ':fecha' => $input['fecha_cita'],
                ':hora'  => $horaNormalizada
            ]);
            $ocupados = (int)$ocupStmt->fetchColumn();

            if ($ocupados >= (int)$cal['cupos_por_bloque']) {
                http_response_code(409);
                echo json_encode(["status" => "error", "mensaje" => "Este bloque horario ya alcanzó el límite máximo de cupos disponibles."], JSON_UNESCAPED_UNICODE);
                return;
            }

            $estadoInicial = $input['estado_cita'] ?? ($cal['default_status'] ?: 'reservada');

            $sql = "INSERT INTO gm_citas 
                    (calendario_id, paciente_id, medico_id, fecha_cita, hora_cita, procedimiento, estado_cita, tipo_cita)
                    VALUES 
                    (:cal, :pac, :med, :fecha, :hora, :proc, :estado, :tipo)";
            $stmt = $pdo->prepare($sql);
            $stmt->execute([
                ':cal'    => (int)$input['calendario_id'],
                ':pac'    => (int)$input['paciente_id'],
                ':med'    => (int)$input['medico_id'],
                ':fecha'  => $input['fecha_cita'],
                ':hora'   => $horaNormalizada,
                ':proc'   => trim($input['procedimiento'] ?? 'Consulta General'),
                ':estado' => $estadoInicial,
                ':tipo'   => trim($input['tipo_cita'] ?? 'Hora Programada')
            ]);

            http_response_code(201);
            echo json_encode([
                "status"  => "success",
                "mensaje" => "Cita médica agendada correctamente.",
                "cita_id" => $pdo->lastInsertId()
            ], JSON_UNESCAPED_UNICODE);

        } catch (Exception $e) {
            http_response_code(500);
            echo json_encode(["status" => "error", "mensaje" => $e->getMessage()], JSON_UNESCAPED_UNICODE);
        }
    }

    /**
     * PUT /api/citas
     * Permite cambiar estado (confirmar, cancelar) o reprogramar fecha/hora
     */
    public static function actualizar($input) {
        try {
            $citaId = (int)($input['id'] ?? $input['cita_id'] ?? 0);
            if ($citaId <= 0) {
                http_response_code(400);
                echo json_encode(["status" => "error", "mensaje" => "Debe indicar el 'id' de la cita a actualizar."], JSON_UNESCAPED_UNICODE);
                return;
            }

            $pdo = Database::conectar();
            $campos = [];
            $params = [':id' => $citaId];

            if (!empty($input['estado_cita'])) {
                $campos[] = "estado_cita = :estado";
                $params[':estado'] = trim($input['estado_cita']);
            }
            if (!empty($input['fecha_cita'])) {
                $campos[] = "fecha_cita = :fecha";
                $params[':fecha'] = trim($input['fecha_cita']);
            }
            if (!empty($input['hora_cita'])) {
                $horaNorm = strlen($input['hora_cita']) === 5 ? $input['hora_cita'] . ':00' : $input['hora_cita'];
                $campos[] = "hora_cita = :hora";
                $params[':hora'] = $horaNorm;
            }
            if (!empty($input['procedimiento'])) {
                $campos[] = "procedimiento = :proc";
                $params[':proc'] = trim($input['procedimiento']);
            }

            if (empty($campos)) {
                http_response_code(400);
                echo json_encode(["status" => "error", "mensaje" => "No se enviaron campos para actualizar."], JSON_UNESCAPED_UNICODE);
                return;
            }

            $sql = "UPDATE gm_citas SET " . implode(", ", $campos) . " WHERE id = :id";
            $stmt = $pdo->prepare($sql);
            $stmt->execute($params);

            echo json_encode([
                "status"  => "success",
                "mensaje" => "Cita actualizada correctamente."
            ], JSON_UNESCAPED_UNICODE);

        } catch (Exception $e) {
            http_response_code(500);
            echo json_encode(["status" => "error", "mensaje" => $e->getMessage()], JSON_UNESCAPED_UNICODE);
        }
    }

    /**
     * POST /api/citas/check-in-qr
     * Se ejecuta cuando el paciente escanea el QR del médico en la clínica
     * Recibe: { "medico_id": 1, "paciente_id": 5 }
     */
    public static function checkInQr($input) {
        try {
            $medicoId   = (int)($input['medico_id'] ?? 0);
            $pacienteId = (int)($input['paciente_id'] ?? 0);

            if ($medicoId <= 0 || $pacienteId <= 0) {
                http_response_code(400);
                echo json_encode(["status" => "error", "mensaje" => "Los campos medico_id y paciente_id son obligatorios."], JSON_UNESCAPED_UNICODE);
                return;
            }

            $pdo = Database::conectar();
            $hoy = date('Y-m-d');

            // Buscar cita del paciente para HOY con ese médico
            $stmt = $pdo->prepare("SELECT * FROM gm_citas WHERE medico_id = :med AND paciente_id = :pac AND fecha_cita = :hoy AND estado_cita COLLATE utf8mb4_unicode_ci IN ('reservada', 'confirmada', 'en_espera') ORDER BY hora_cita ASC LIMIT 1");
            $stmt->execute([':med' => $medicoId, ':pac' => $pacienteId, ':hoy' => $hoy]);
            $cita = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$cita) {
                http_response_code(404);
                echo json_encode(["status" => "error", "mensaje" => "No tiene ninguna cita programada para el día de hoy con este médico."], JSON_UNESCAPED_UNICODE);
                return;
            }

            // Si ya estaba en espera, devolver su turno actual
            if ($cita['estado_cita'] === 'en_espera' && (int)$cita['numero_llegada'] > 0) {
                $turnoAsignado = (int)$cita['numero_llegada'];
            } else {
                // Calcular siguiente número correlativo de llegada para ese médico hoy
                $maxStmt = $pdo->prepare("SELECT COALESCE(MAX(numero_llegada), 0) FROM gm_citas WHERE medico_id = :med AND fecha_cita = :hoy");
                $maxStmt->execute([':med' => $medicoId, ':hoy' => $hoy]);
                $turnoAsignado = (int)$maxStmt->fetchColumn() + 1;

                $upd = $pdo->prepare("UPDATE gm_citas SET estado_cita = 'en_espera', numero_llegada = :turno WHERE id = :id");
                $upd->execute([':turno' => $turnoAsignado, ':id' => $cita['id']]);
            }

            // Calcular pacientes por delante y tiempo estimado de espera
            $delanteStmt = $pdo->prepare("SELECT COUNT(*) FROM gm_citas WHERE medico_id = :med AND fecha_cita = :hoy AND estado_cita COLLATE utf8mb4_unicode_ci = 'en_espera' AND numero_llegada < :turno");
            $delanteStmt->execute([':med' => $medicoId, ':hoy' => $hoy, ':turno' => $turnoAsignado]);
            $pacientesDelante = (int)$delanteStmt->fetchColumn();

            $promStmt = $pdo->prepare("SELECT AVG(duracion_minutos) FROM gm_tiempo_atencion WHERE medico_id = :med AND duracion_minutos > 0");
            $promStmt->execute([':med' => $medicoId]);
            $promedioMin = (float)$promStmt->fetchColumn();
            if ($promedioMin <= 0) {
                $promedioMin = 20; // Promedio base si aún no hay histórico
            }

            $tiempoEstimadoMin = round($pacientesDelante * $promedioMin);

            echo json_encode([
                "status"               => "success",
                "mensaje"              => "¡Llegada registrada! Ha ingresado a la sala de espera.",
                "cita_id"              => (int)$cita['id'],
                "numero_turno"         => $turnoAsignado,
                "pacientes_delante"    => $pacientesDelante,
                "tiempo_estimado_min"  => $tiempoEstimadoMin
            ], JSON_UNESCAPED_UNICODE);

        } catch (Exception $e) {
            http_response_code(500);
            echo json_encode(["status" => "error", "mensaje" => $e->getMessage()], JSON_UNESCAPED_UNICODE);
        }
    }

    /**
     * POST /api/citas/iniciar-atencion
     * El médico pasa al paciente a consulta y registra la hora de inicio
     */
    public static function iniciarAtencion($input) {
        try {
            $citaId   = (int)($input['cita_id'] ?? 0);
            $medicoId = (int)($input['medico_id'] ?? 0);

            if ($citaId <= 0 || $medicoId <= 0) {
                http_response_code(400);
                echo json_encode(["status" => "error", "mensaje" => "Los campos cita_id y medico_id son obligatorios."], JSON_UNESCAPED_UNICODE);
                return;
            }

            $pdo = Database::conectar();
            $pdo->prepare("UPDATE gm_citas SET estado_cita = 'en_consulta' WHERE id = :cid")->execute([':cid' => $citaId]);

            $pdo->prepare("INSERT INTO gm_tiempo_atencion (medico_id, cita_id, hora_inicio) VALUES (:med, :cid, NOW())")
                ->execute([':med' => $medicoId, ':cid' => $citaId]);

            echo json_encode([
                "status"  => "success",
                "mensaje" => "Consulta iniciada y cronómetro de atención activado."
            ], JSON_UNESCAPED_UNICODE);

        } catch (Exception $e) {
            http_response_code(500);
            echo json_encode(["status" => "error", "mensaje" => $e->getMessage()], JSON_UNESCAPED_UNICODE);
        }
    }

}