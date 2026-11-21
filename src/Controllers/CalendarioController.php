<?php
namespace App\Controllers;

use App\Config\Database;
use PDO;
use Exception;
use DateTime;
use DateInterval;

class CalendarioController {

    /**
     * GET /api/calendarios?medico_id=X
     */
    public static function listar() {
        try {
            $pdo = Database::conectar();$medicoId = isset($_GET['medico_id']) ? (int)$_GET['medico_id'] : 0;

            if ($medicoId > 0) {
                $stmt =$pdo->prepare("SELECT * FROM gm_calendarios WHERE medico_id = :med ORDER BY id DESC");
                $stmt->execute([':med' =>$medicoId]);
            } else {
                $stmt =$pdo->query("SELECT * FROM gm_calendarios WHERE estado = 'activo' ORDER BY id DESC");
            }

            $calendarios =$stmt->fetchAll(PDO::FETCH_ASSOC);

            foreach ($calendarios as &$cal) {
                $cal['dias_config'] = json_decode($cal['dias_config'], true) ?? [];
            }

            echo json_encode([
                "status" => "success",
                "total"  => count($calendarios),
                "data"   => $calendarios
            ], JSON_UNESCAPED_UNICODE);

        } catch (Exception $e) {
            http_response_code(500);
            echo json_encode(["status" => "error", "mensaje" => $e->getMessage()], JSON_UNESCAPED_UNICODE);
        }
    }

    /**
     * POST /api/calendarios
     * Crea o actualiza el calendario del médico (máximo 1 por médico)
     */
    public static function guardar($input) {
        try {
            $medicoId = (int)($input['medico_id'] ?? 0);
            $nombre   = trim($input['nombre_calendario'] ?? 'Consulta Médica');
            $diasConf =$input['dias_config'] ?? [];

            if ($medicoId <= 0 \vert{}\vert{} empty($diasConf)) {
                http_response_code(400);
                echo json_encode(["status" => "error", "mensaje" => "El medico_id y la configuración de días (dias_config) son obligatorios."], JSON_UNESCAPED_UNICODE);
                return;
            }

            $pdo = Database::conectar();
            $diasJson = is_string($diasConf) ? $diasConf : json_encode($diasConf, JSON_UNESCAPED_UNICODE);

            $check =$pdo->prepare("SELECT id FROM gm_calendarios WHERE medico_id = :med LIMIT 1");
            $check->execute([':med' =>$medicoId]);
            $existente =$check->fetch(PDO::FETCH_ASSOC);

            if ($existente) {$sql = "UPDATE gm_calendarios SET 
                        nombre_calendario = :nombre,
                        dias_config = :dias,
                        duracion_bloque = :duracion,
                        cupos_por_bloque = :cupos,
                        appointment_buffer = :app_buf,
                        cancellation_buffer = :can_buf,
                        prevent_after_date = :prev_date,
                        default_status = :def_status,
                        estado = :estado
                        WHERE id = :id";
                $stmt =$pdo->prepare($sql);$stmt->execute([
                    ':nombre'     => $nombre,
                    ':dias'       => $diasJson,
                    ':duracion'   => (int)($input['duracion_bloque'] ?? 30),
                    ':cupos'      => (int)($input['cupos_por_bloque'] ?? 1), ':app_buf'    =>$input['appointment_buffer'] ?? 'none',
                    ':can_buf'    => $input['cancellation_buffer'] ?? '24 hours',
                    ':prev_date'  => !empty($input['prevent_after_date']) ?$input['prevent_after_date'] : null,
                    ':def_status' => $input['default_status'] ?? 'reservada',
                    ':estado'     => $input['estado'] ?? 'activo',
                    ':id'         => $existente['id']
                ]);

                echo json_encode([
                    "status"  => "success",
                    "mensaje" => "Calendario actualizado correctamente.",
                    "id"      => $existente['id']
                ], JSON_UNESCAPED_UNICODE);
            } else {
                $sql = "INSERT INTO gm_calendarios 
                        (medico_id, nombre_calendario, dias_config, duracion_bloque, cupos_por_bloque, appointment_buffer, cancellation_buffer, prevent_after_date, default_status, estado)
                        VALUES 
                        (:med, :nombre, :dias, :duracion, :cupos, :app_buf, :can_buf, :prev_date, :def_status, 'activo')";
                $stmt =$pdo->prepare($sql);$stmt->execute([
                    ':med'        => $medicoId,
                    ':nombre'     => $nombre,
                    ':dias'       => $diasJson,
                    ':duracion'   => (int)($input['duracion_bloque'] ?? 30),
                    ':cupos'      => (int)($input['cupos_por_bloque'] ?? 1), ':app_buf'    =>$input['appointment_buffer'] ?? 'none',
                    ':can_buf'    => $input['cancellation_buffer'] ?? '24 hours',
                    ':prev_date'  => !empty($input['prevent_after_date']) ?$input['prevent_after_date'] : null,
                    ':def_status' => $input['default_status'] ?? 'reservada'
                ]);

                http_response_code(201);
                echo json_encode([
                    "status"  => "success",
                    "mensaje" => "Calendario creado exitosamente.",
                    "id"      => $pdo->lastInsertId()
                ], JSON_UNESCAPED_UNICODE);
            }

        } catch (Exception $e) {
            http_response_code(500);
            echo json_encode(["status" => "error", "mensaje" => $e->getMessage()], JSON_UNESCAPED_UNICODE);
        }
    }

    /**
     * GET /api/calendarios/disponibilidad-mes?medico_id=X&anio=YYYY&mes=MM
     * Devuelve el mapa diario del mes con estado (disponible, agotado, no_laboral) y cupos libres
     */
    public static function disponibilidadMes() {
        try {
            $medicoId = isset($_GET['medico_id']) ? (int)$_GET['medico_id'] : 0;
            $anio     = isset($_GET['anio']) ? (int)$_GET['anio'] : (int)date('Y');$mes      = isset($_GET['mes']) ? (int)$_GET['mes'] : (int)date('m');

            if ($medicoId <= 0 || $mes < 1 \vert{}\vert{}$mes > 12) {
                http_response_code(400);
                echo json_encode(["status" => "error", "mensaje" => "Parámetros medico_id, anio y mes inválidos."], JSON_UNESCAPED_UNICODE);
                return;
            }

            $pdo = Database::conectar();
            $stmt =$pdo->prepare("SELECT * FROM gm_calendarios WHERE medico_id = :med AND estado = 'activo' LIMIT 1");
            $stmt->execute([':med' =>$medicoId]);
            $cal =$stmt->fetch(PDO::FETCH_ASSOC);

            if (!$cal) {
                echo json_encode([
                    "status" => "success",
                    "dias"   => new \stdClass()
                ], JSON_UNESCAPED_UNICODE);
                return;
            }

            $diasConfig     = json_decode($cal['dias_config'], true) ?? [];
            $duracionMin    = max(5, (int)($cal['duracion_bloque'] ?? 30));
            $cuposPorBloque = max(1, (int)($cal['cupos_por_bloque'] ?? 1));
            $preventAfter   =$cal['prevent_after_date'] ?? null;
            $hoyStr         = date('Y-m-d');

            $primerDia = sprintf('%04d-%02d-01', $anio,$mes);
            $ultimoDia = date('Y-m-t', strtotime($primerDia));

            // Contar citas no canceladas del mes agrupadas por fecha
            $citasStmt =$pdo->prepare("SELECT fecha_cita, COUNT(*) as total FROM gm_citas WHERE medico_id = :med AND fecha_cita BETWEEN :ini AND :fin AND estado_cita != 'cancelada' GROUP BY fecha_cita");
            $citasStmt->execute([':med' => $medicoId, ':ini' =>$primerDia, ':fin' => $ultimoDia]);$citasPorFecha = [];
            while ($row = $citasStmt->fetch(PDO::FETCH_ASSOC)) {$citasPorFecha[$row['fecha_cita']] = (int)$row['total'];
            }

            $totalDiasMes = (int)date('t', strtotime($primerDia));$resultadoDias = [];

            for ($d = 1; $d <= $totalDiasMes; $d++) {
                $fechaStr = sprintf('\%04d-\%02d-\%02d',$anio, $mes,$d);
                if ($fechaStr < $hoyStr \vert{}\vert{} (!empty($preventAfter) && $fechaStr >$preventAfter)) {
                    $resultadoDias[$fechaStr] = [
                        "estado" => "no_laboral",
                        "cupos_disponibles" => 0
                    ];
                    continue;
                }

                $dt = new DateTime($fechaStr);$diaEn = $dt->format('l');$configDia = null;
                foreach ($diasConfig as $k =>$v) {
                    if (strcasecmp($k,$diaEn) === 0) {
                        $configDia =$v;
                        break;
                    }
                }

                if (!$configDia) {
                    $resultadoDias[$fechaStr] = [
                        "estado" => "no_laboral",
                        "cupos_disponibles" => 0
                    ];
                    continue;
                }

                $modalidadRaw   = strtolower(trim((string)($configDia['modalidad'] ?? $configDia['tipo_atencion'] ?? 'horario_especifico')));
                $esOrdenLlegada = ($modalidadRaw === 'orden_llegada' || $modalidadRaw === 'orden de llegada');$ocupados       = $citasPorFecha[$fechaStr] ?? 0;

                if ($esOrdenLlegada) {
                    $cuposTotales = (int)($configDia['cupos_dia'] ?? $configDia['cupos'] ?? $cuposPorBloque);
                    if ($cuposTotales <= 0) $cuposTotales = 15; } else {$hIni = new DateTime($fechaStr . ' ' . ($configDia['hora_inicio'] ?? '08:00'));
                    $hFin = new DateTime($fechaStr . ' ' . ($configDia['hora_fin'] ?? '16:00'));$mins = max(0, ($hFin->getTimestamp() -$hIni->getTimestamp()) / 60);
                    $numBloques = (int)floor($mins / $duracionMin);$cuposTotales = max(1, $numBloques) *$cuposPorBloque;
                }

                $libres = max(0, $cuposTotales -$ocupados);
                $resultadoDias[$fechaStr] = [
                    "estado"            => $libres > 0 ? "disponible" : "agotado",
                    "modalidad"         => $esOrdenLlegada ? "orden_llegada" : "horario_especifico",
                    "cupos_disponibles" => $libres
                ];
            }

            echo json_encode([
                "status" => "success",
                "anio"   => $anio,
                "mes"    => $mes,
                "dias"   => $resultadoDias
            ], JSON_UNESCAPED_UNICODE);

        } catch (Exception $e) {
            http_response_code(500);
            echo json_encode(["status" => "error", "mensaje" => $e->getMessage()], JSON_UNESCAPED_UNICODE);
        }
    }

    /**
     * GET /api/calendarios/disponibilidad?medico_id=X&fecha=YYYY-MM-DD
     * Genera los bloques horarios o el cupo diario por orden de llegada
     */
    public static function disponibilidad() {
        try {
            $medicoId = isset($_GET['medico_id']) ? (int)$_GET['medico_id'] : 0;
            $fecha    = trim($_GET['fecha'] ?? '');

            if ($medicoId <= 0 \vert{}\vert{} empty($fecha)) {
                http_response_code(400);
                echo json_encode(["status" => "error", "mensaje" => "Los parámetros medico_id y fecha (YYYY-MM-DD) son obligatorios."], JSON_UNESCAPED_UNICODE);
                return;
            }

            $pdo = Database::conectar();
            $stmt =$pdo->prepare("SELECT * FROM gm_calendarios WHERE medico_id = :med AND estado = 'activo' LIMIT 1");
            $stmt->execute([':med' =>$medicoId]);
            $cal =$stmt->fetch(PDO::FETCH_ASSOC);

            if (!$cal) {
                http_response_code(404);
                echo json_encode(["status" => "error", "mensaje" => "El médico no tiene un calendario activo configurado."], JSON_UNESCAPED_UNICODE);
                return;
            }

            if (!empty($cal['prevent_after_date']) && $fecha >$cal['prevent_after_date']) {
                echo json_encode([
                    "status"     => "success",
                    "disponible" => false,
                    "mensaje"    => "No se permiten reservas después del " . $cal['prevent_after_date'],
                    "bloques"    => []
                ], JSON_UNESCAPED_UNICODE);
                return;
            }

            $dt = new DateTime($fecha);
            $diaSemanaEn =$dt->format('l');
            $diasConfig  = json_decode($cal['dias_config'], true) ?? [];

            $configDia = null;
            foreach ($diasConfig as $k =>$v) {
                if (strcasecmp($k,$diaSemanaEn) === 0) {
                    $configDia =$v;
                    break;
                }
            }

            if (!$configDia) {
                echo json_encode([
                    "status"     => "success",
                    "disponible" => false,
                    "mensaje"    => "El médico no atiende este día de la semana.",
                    "bloques"    => []
                ], JSON_UNESCAPED_UNICODE);
                return;
            }

            $sede           =$configDia['sede'] ?? 'Consultorio Principal';
            $modalidadRaw   = strtolower(trim((string)($configDia['modalidad'] ?? $configDia['tipo_atencion'] ?? 'horario_especifico')));$esOrdenLlegada = ($modalidadRaw === 'orden_llegada' \vert{}\vert{}$modalidadRaw === 'orden de llegada');

            $horaInicioStr  =$configDia['hora_inicio'] ?? '08:00';
            $horaFinStr     =$configDia['hora_fin'] ?? '16:00';
            $horaInicio     = new DateTime($fecha . ' ' . $horaInicioStr);$horaFin        = new DateTime($fecha . ' ' .$horaFinStr);
            $duracionMin    = max(5, (int)($cal['duracion_bloque'] ?? 30));
            $cuposPorBloque = max(1, (int)($cal['cupos_por_bloque'] ?? 1));

            if ($esOrdenLlegada) {
                $cuposDia = (int)($configDia['cupos_dia'] ?? $configDia['cupos'] ?? $cuposPorBloque);
                if ($cuposDia <= 0) {$cuposDia = 15;
                }

                $citasDiaStmt =$pdo->prepare("SELECT COUNT(*) FROM gm_citas WHERE medico_id = :med AND fecha_cita = :fecha AND estado_cita != 'cancelada'");
                $citasDiaStmt->execute([':med' =>$medicoId, ':fecha' => $fecha]);$ocupadosDia = (int)$citasDiaStmt->fetchColumn();$libresDia   = max(0, $cuposDia -$ocupadosDia);

                $bloques = [[
                    "modalidad"         => "orden_llegada",
                    "hora"              => $horaInicio->format('H:i'),
                    "hora_fin"          => $horaFin->format('H:i'),
                    "hora_formato_12"   => $horaInicio->format('h:i A'),
                    "sede"              => $sede,
                    "cupos_totales"     => $cuposDia,
                    "cupos_ocupados"    => $ocupadosDia,
                    "cupos_disponibles" => $libresDia,
                    "disponible"        => $libresDia > 0
                ]];

                echo json_encode([
                    "status"            => "success",
                    "calendario_id"     => (int)$cal['id'],
                    "fecha"             => $fecha,
                    "dia_semana"        => $diaSemanaEn,
                    "modalidad"         => "orden_llegada",
                    "sede"              => $sede,
                    "hora_inicio"       => $horaInicio->format('H:i'),
                    "hora_fin"          => $horaFin->format('H:i'),
                    "cupos_totales"     => $cuposDia,
                    "cupos_disponibles" => $libresDia,
                    "disponible"        => $libresDia > 0,
                    "bloques"           => $bloques
                ], JSON_UNESCAPED_UNICODE);
                return;
            }

            $citasStmt =$pdo->prepare("SELECT hora_cita, COUNT(*) as ocupados FROM gm_citas WHERE medico_id = :med AND fecha_cita = :fecha AND estado_cita != 'cancelada' GROUP BY hora_cita");
            $citasStmt->execute([':med' =>$medicoId, ':fecha' => $fecha]);$ocupacionMap = [];
            while ($row = $citasStmt->fetch(PDO::FETCH_ASSOC)) {$horaKey = substr($row['hora_cita'], 0, 5);$ocupacionMap[$horaKey] = (int)$row['ocupados'];
            }

            $bloques = [];
            $actual  = clone$horaInicio;
            $intervalo = new DateInterval('PT' .$duracionMin . 'M');

            while ($actual < $horaFin) {$horaStr = $actual->format('H:i');$ocupados = $ocupacionMap[$horaStr] ?? 0;
                $libres   = max(0, $cuposPorBloque -$ocupados);

                $bloques[] = [
                    "modalidad"         => "horario_especifico",
                    "hora"              => $horaStr,
                    "hora_formato_12"   => $actual->format('h:i A'),
                    "sede"              => $sede,
                    "cupos_totales"     => $cuposPorBloque,
                    "cupos_ocupados"    => $ocupados,
                    "cupos_disponibles" => $libres,
                    "disponible"        => $libres > 0
                ];

                $actual->add($intervalo);
            }

            echo json_encode([
                "status"        => "success",
                "calendario_id" => (int)$cal['id'],
                "fecha"         => $fecha,
                "dia_semana"    => $diaSemanaEn,
                "modalidad"     => "horario_especifico",
                "sede"          => $sede,
                "duracion_min"  => $duracionMin,
                "disponible"    => true,
                "bloques"       => $bloques
            ], JSON_UNESCAPED_UNICODE);

        } catch (Exception $e) {
            http_response_code(500);
            echo json_encode(["status" => "error", "mensaje" => $e->getMessage()], JSON_UNESCAPED_UNICODE);
        }
    }
}