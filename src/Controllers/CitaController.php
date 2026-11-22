<?php
namespace App\Controllers;

use App\Config\Database;
use PDO;
use Exception;

class CitaController {

    /**
     * Desglosa los servicios seleccionados en una cita (desde el texto 'procedimiento'
     * o un arreglo 'servicios') y obtiene su precio real en gm_servicios.
     */
    private static function resolverServiciosCita(PDO $pdo, int $medicoId, string $procedimiento, $serviciosInput = null): array {
        $itemsNombres = [];

        if (is_string($serviciosInput) && trim($serviciosInput) !== '') {
            $decoded = json_decode($serviciosInput, true);
            if (is_array($decoded)) {
                $serviciosInput = $decoded;
            }
        }

        if (is_array($serviciosInput) && !empty($serviciosInput)) {
            foreach ($serviciosInput as $srv) {
                if (is_string($srv) && trim($srv) !== '') {
                    $itemsNombres[] = trim($srv);
                } elseif (is_array($srv)) {
                    $nom = trim((string)($srv['nombre_servicio'] ?? $srv['nombre'] ?? $srv['titulo'] ?? ''));
                    if ($nom !== '') {
                        $itemsNombres[] = $nom;
                    }
                }
            }
        }

        if (empty($itemsNombres) && trim($procedimiento) !== '') {
            $partes = preg_split('/\s*(?:,|\+|\||;)\s*/', trim($procedimiento)) ?: [];
            foreach ($partes as $p) {
                $p = trim($p);
                if ($p !== '') {
                    $itemsNombres[] = $p;
                }
            }
        }

        if (empty($itemsNombres) || $medicoId <= 0) {
            return ['servicios' => [], 'total' => 0.00];
        }

        $stmt = $pdo->prepare("
            SELECT id, nombre_servicio, precio 
            FROM gm_servicios 
            WHERE medico_id = :med 
              AND TRIM(LOWER(nombre_servicio)) = TRIM(LOWER(:nombre)) 
            LIMIT 1
        ");

        $detalle = [];
        $total   = 0.00;

        foreach ($itemsNombres as $rawItem) {
            $precioInline = null;
            if (preg_match('/\(\$\s*([0-9]+(?:[.,][0-9]+)?)\)\s*$/', $rawItem, $m)) {
                $precioInline = (float)str_replace(',', '.', $m[1]);
            }
            $nombreLimpio = trim((string)preg_replace('/\s*\(\$\s*[0-9.,]+\)\s*$/', '', $rawItem));
            if ($nombreLimpio === '') {
                continue;
            }

            $stmt->execute([
                ':med'    => $medicoId,
                ':nombre' => $nombreLimpio
            ]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);

            if ($row) {
                $precio = (float)$row['precio'];
                $total += $precio;
                $detalle[] = [
                    'id'              => (int)$row['id'],
                    'nombre_servicio' => $row['nombre_servicio'],
                    'nombre'          => $row['nombre_servicio'],
                    'precio'          => number_format($precio, 2, '.', ''),
                    'precio_usd'      => number_format($precio, 2, '.', ''),
                ];
            } elseif ($precioInline !== null && $precioInline > 0) {
                $total += $precioInline;
                $detalle[] = [
                    'id'              => 0,
                    'nombre_servicio' => $nombreLimpio,
                    'nombre'          => $nombreLimpio,
                    'precio'          => number_format($precioInline, 2, '.', ''),
                    'precio_usd'      => number_format($precioInline, 2, '.', ''),
                ];
            }
        }

        return [
            'servicios' => $detalle,
            'total'     => round($total, 2),
        ];
    }

    /**
     * Calcula el monto total en USD cruzando la lista de servicios seleccionados
     * contra el catálogo gm_servicios del médico.
     */
    private static function calcularMontoServicios(PDO $pdo, int $medicoId, string $procedimiento, $serviciosInput = null): float {
        $res = self::resolverServiciosCita($pdo, $medicoId, $procedimiento, $serviciosInput);
        return (float)$res['total'];
    }

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

            // Asegurar que cada cita devuelva su desglose de servicios y monto real
            $updMonto = $pdo->prepare("UPDATE gm_citas SET total_usd = :total WHERE id = :id");
            foreach ($citas as &$cita) {
                $resServicios = self::resolverServiciosCita(
                    $pdo,
                    (int)$cita['medico_id'],
                    (string)($cita['procedimiento'] ?? '')
                );

                $montoGuardado = isset($cita['total_usd']) ? (float)$cita['total_usd'] : 0.00;
                if ($montoGuardado <= 0 && $resServicios['total'] > 0) {
                    $montoGuardado = $resServicios['total'];
                    $updMonto->execute([
                        ':total' => $montoGuardado,
                        ':id'    => (int)$cita['id']
                    ]);
                }

                $montoFormateado     = number_format($montoGuardado, 2, '.', '');
                $cita['servicios']   = $resServicios['servicios'];
                $cita['total_usd']   = $montoFormateado;
                $cita['monto_usd']   = $montoFormateado;
                $cita['monto_total'] = $montoFormateado;
            }
            unset($cita);

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
     * Agenda una nueva cita verificando cupos disponibles y guardando el monto total_usd
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
            $medicoId = (int)$input['medico_id'];

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
                ':med'   => $medicoId,
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

            // Si los servicios vienen como arreglo sin 'procedimiento', construir el texto separado por coma
            $procedimiento = trim((string)($input['procedimiento'] ?? ''));
            if ($procedimiento === '' && !empty($input['servicios']) && is_array($input['servicios'])) {
                $nombres = [];
                foreach ($input['servicios'] as $srv) {
                    if (is_string($srv) && trim($srv) !== '') {
                        $nombres[] = trim($srv);
                    } elseif (is_array($srv)) {
                        $n = trim((string)($srv['nombre_servicio'] ?? $srv['nombre'] ?? ''));
                        if ($n !== '') {
                            $nombres[] = $n;
                        }
                    }
                }
                $procedimiento = implode(', ', $nombres);
            }
            if ($procedimiento === '') {
                $procedimiento = 'Consulta General';
            }

            // Calcular monto desde gm_servicios o tomar el enviado si es mayor a 0
            $totalUsd = isset($input['total_usd'])
                ? (float)$input['total_usd']
                : (isset($input['monto_usd']) ? (float)$input['monto_usd'] : (isset($input['monto_total']) ? (float)$input['monto_total'] : 0.00));

            if ($totalUsd <= 0) {
                $totalUsd = self::calcularMontoServicios($pdo, $medicoId, $procedimiento, $input['servicios'] ?? null);
            }

            $sql = "INSERT INTO gm_citas 
                    (calendario_id, paciente_id, medico_id, fecha_cita, hora_cita, procedimiento, total_usd, estado_cita, tipo_cita)
                    VALUES 
                    (:cal, :pac, :med, :fecha, :hora, :proc, :total, :estado, :tipo)";
            $stmt = $pdo->prepare($sql);
            $stmt->execute([
                ':cal'    => (int)$input['calendario_id'],
                ':pac'    => (int)$input['paciente_id'],
                ':med'    => $medicoId,
                ':fecha'  => $input['fecha_cita'],
                ':hora'   => $horaNormalizada,
                ':proc'   => $procedimiento,
                ':total'  => round($totalUsd, 2),
                ':estado' => $estadoInicial,
                ':tipo'   => trim($input['tipo_cita'] ?? 'Hora Programada')
            ]);

            http_response_code(201);
            echo json_encode([
                "status"    => "success",
                "mensaje"   => "Cita médica agendada correctamente.",
                "cita_id"   => $pdo->lastInsertId(),
                "total_usd" => number_format($totalUsd, 2, '.', '')
            ], JSON_UNESCAPED_UNICODE);

        } catch (Exception $e) {
            http_response_code(500);
            echo json_encode(["status" => "error", "mensaje" => $e->getMessage()], JSON_UNESCAPED_UNICODE);
        }
    }

    /**
     * PUT /api/citas
     * Permite cambiar estado (confirmar, cancelar), reprogramar fecha/hora o actualizar procedimiento/monto
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
                $procNuevo = trim($input['procedimiento']);
                $campos[] = "procedimiento = :proc";
                $params[':proc'] = $procNuevo;

                // Recalcular total_usd si se actualizaron los servicios
                $medStmt = $pdo->prepare("SELECT medico_id FROM gm_citas WHERE id = :id LIMIT 1");
                $medStmt->execute([':id' => $citaId]);
                $medId = (int)$medStmt->fetchColumn();
                if ($medId > 0) {
                    $nuevoTotal = isset($input['total_usd'])
                        ? (float)$input['total_usd']
                        : self::calcularMontoServicios($pdo, $medId, $procNuevo, $input['servicios'] ?? null);
                    $campos[] = "total_usd = :total";
                    $params[':total'] = round($nuevoTotal, 2);
                }
            } elseif (isset($input['total_usd'])) {
                $campos[] = "total_usd = :total";
                $params[':total'] = round((float)$input['total_usd'], 2);
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

    /**
     * GET /api/marketplace/medicos
     * Devuelve el directorio completo de médicos para el Marketplace de Servicios:
     * perfil, bio, foto, credenciales, sedes (país/estado) y servicios/combos activos.
     */
    public static function listarMarketplace() {
        try {
            $pdo = Database::conectar();

            $sql = "SELECT *
                    FROM gm_usuarios_extendidos
                    WHERE LOWER(TRIM(rol_medico)) IN ('medico', 'gm_medico')
                    ORDER BY nombre ASC, apellido ASC";
            $medicos = $pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC);

            $resultado = [];
            foreach ($medicos as $med) {
                $uid = (int)($med['user_id'] ?? 0);
                if ($uid <= 0) {
                    $uid = (int)($med['id'] ?? 0);
                }
                if ($uid <= 0) {
                    continue;
                }

                // 1. Resolver sedes registradas (tabla gm_sedes, configuración del médico y/o calendarios)
                $sedes = [];
                $paisBase   = trim((string)($med['pais'] ?? ''));
                $estadoBase = trim((string)($med['estado'] ?? ''));
                $dirBase    = trim((string)($med['direccion_detalle'] ?? ''));

                try {
                    $stmtSedes = $pdo->prepare("SELECT * FROM gm_sedes WHERE medico_id = :mid");
                    $stmtSedes->execute([':mid' => $uid]);
                    $rowsSedes = $stmtSedes->fetchAll(PDO::FETCH_ASSOC);
                    foreach ($rowsSedes as $rs) {
                        $sedes[] = [
                            'id'        => (int)($rs['id'] ?? 0),
                            'nombre'    => trim((string)($rs['nombre'] ?? $rs['nombre_sede'] ?? 'Sede')),
                            'pais'      => trim((string)($rs['pais'] ?? $paisBase)),
                            'estado'    => trim((string)($rs['estado'] ?? $rs['ciudad'] ?? $estadoBase)),
                            'direccion' => trim((string)($rs['direccion'] ?? $rs['direccion_detalle'] ?? $dirBase)),
                        ];
                    }
                } catch (Exception $e) {
                    // La tabla gm_sedes puede no existir en algunas instalaciones
                }

                // Revisar si tiene sedes guardadas en gm_configuracion (JSON sedes_medico_X)
                if (empty($sedes)) {
                    try {
                        $stmtCfg = $pdo->prepare("SELECT valor FROM gm_configuracion WHERE clave = :k LIMIT 1");
                        $stmtCfg->execute([':k' => 'sedes_medico_' . $uid]);
                        $jsonSedes = $stmtCfg->fetchColumn();
                        if ($jsonSedes) {
                            $parsed = json_decode((string)$jsonSedes, true);
                            if (is_array($parsed)) {
                                foreach ($parsed as $idx => $sItem) {
                                    if (is_array($sItem)) {
                                        $sedes[] = [
                                            'id'        => $idx + 1,
                                            'nombre'    => trim((string)($sItem['nombre'] ?? 'Sede ' . ($idx + 1))),
                                            'pais'      => trim((string)($sItem['pais'] ?? $paisBase)),
                                            'estado'    => trim((string)($sItem['estado'] ?? $estadoBase)),
                                            'direccion' => trim((string)($sItem['direccion'] ?? $dirBase)),
                                        ];
                                    } elseif (is_string($sItem) && trim($sItem) !== '') {
                                        $sedes[] = [
                                            'id'        => $idx + 1,
                                            'nombre'    => trim($sItem),
                                            'pais'      => $paisBase,
                                            'estado'    => $estadoBase,
                                            'direccion' => $dirBase,
                                        ];
                                    }
                                }
                            }
                        }
                    } catch (Exception $e) {}
                }

                // Consultar calendario principal y sedes configuradas en dias_config
                $calendarioId = 0;
                try {
                    $stmtCal = $pdo->prepare("SELECT id, nombre_calendario, dias_config FROM gm_calendarios WHERE medico_id = :mid ORDER BY id ASC");
                    $stmtCal->execute([':mid' => $uid]);
                    $cals = $stmtCal->fetchAll(PDO::FETCH_ASSOC);
                    if (!empty($cals)) {
                        $calendarioId = (int)$cals[0]['id'];
                        if (empty($sedes)) {
                            $nombresVistos = [];
                            foreach ($cals as $calRow) {
                                $diasCfg = json_decode((string)($calRow['dias_config'] ?? ''), true);
                                if (is_array($diasCfg)) {
                                    foreach ($diasCfg as $dInfo) {
                                        if (is_array($dInfo) && !empty($dInfo['sede'])) {
                                            $nomSede = trim((string)$dInfo['sede']);
                                            if ($nomSede !== '' && !in_array($nomSede, $nombresVistos, true)) {
                                                $nombresVistos[] = $nomSede;
                                                $sedes[] = [
                                                    'id'        => count($sedes) + 1,
                                                    'nombre'    => $nomSede,
                                                    'pais'      => $paisBase,
                                                    'estado'    => $estadoBase,
                                                    'direccion' => $dirBase,
                                                ];
                                            }
                                        }
                                    }
                                }
                            }
                        }
                    }
                } catch (Exception $e) {}

                // Respaldo con país/estado del perfil si aún no hay sedes detalladas
                if (empty($sedes) && ($paisBase !== '' || $estadoBase !== '' || $dirBase !== '')) {
                    $sedes[] = [
                        'id'        => 0,
                        'nombre'    => 'Consultorio Principal',
                        'pais'      => $paisBase,
                        'estado'    => $estadoBase,
                        'direccion' => $dirBase,
                    ];
                }

                // 2. Resolver servicios y combos activos del médico
                $stmtServ = $pdo->prepare("
                    SELECT id, tipo, nombre_servicio, descripcion, precio, items_combo, estado
                    FROM gm_servicios
                    WHERE medico_id = :mid
                      AND (estado IS NULL OR TRIM(LOWER(estado)) != 'inactivo')
                    ORDER BY CASE WHEN LOWER(TRIM(tipo)) = 'combo' THEN 0 ELSE 1 END, nombre_servicio ASC
                ");
                $stmtServ->execute([':mid' => $uid]);
                $servicios = $stmtServ->fetchAll(PDO::FETCH_ASSOC);

                // 3. Resolver avatar_url
                $avatar = trim((string)($med['avatar_url'] ?? $med['avatar'] ?? ''));
                if ($avatar === '') {
                    try {
                        $stmtMeta = $pdo->prepare("SELECT valor FROM gm_configuracion WHERE clave = :k LIMIT 1");
                        $stmtMeta->execute([':k' => 'avatar_medico_' . $uid]);
                        $avatar = trim((string)($stmtMeta->fetchColumn() ?: ''));
                    } catch (Exception $e) {}
                }

                // Decodificar redes sociales si vienen en JSON
                $redesRaw = $med['redes_sociales'] ?? '';
                $redesDecoded = is_string($redesRaw) ? json_decode($redesRaw, true) : $redesRaw;

                $resultado[] = [
                    'id'                => (int)($med['id'] ?? $uid),
                    'user_id'           => $uid,
                    'calendario_id'     => $calendarioId,
                    'nombre'            => trim((string)($med['nombre'] ?? '')),
                    'segundo_nombre'    => trim((string)($med['segundo_nombre'] ?? '')),
                    'apellido'          => trim((string)($med['apellido'] ?? '')),
                    'segundo_apellido'  => trim((string)($med['segundo_apellido'] ?? '')),
                    'sexo'              => trim((string)($med['sexo'] ?? '')),
                    'especialidad'      => trim((string)($med['especialidad'] ?? 'Especialista Médico')),
                    'universidad'       => trim((string)($med['universidad'] ?? '')),
                    'tagline'           => trim((string)($med['tagline'] ?? '')),
                    'mpps'              => trim((string)($med['mpps'] ?? '')),
                    'cmm'               => trim((string)($med['cmm'] ?? '')),
                    'redes_sociales'    => is_array($redesDecoded) ? $redesDecoded : [],
                    'whatsapp'          => trim((string)($med['whatsapp'] ?? '')),
                    'email'             => trim((string)($med['email'] ?? '')),
                    'pais'              => $paisBase,
                    'estado'            => $estadoBase,
                    'direccion_detalle' => $dirBase,
                    'avatar_url'        => $avatar,
                    'sedes'             => $sedes,
                    'servicios'         => $servicios,
                ];
            }

            echo json_encode([
                "status"  => "success",
                "total"   => count($resultado),
                "medicos" => $resultado
            ], JSON_UNESCAPED_UNICODE);

        } catch (Exception $e) {
            http_response_code(500);
            echo json_encode(["status" => "error", "mensaje" => $e->getMessage()], JSON_UNESCAPED_UNICODE);
        }
    }

}