<?php
namespace App\Controllers;

use App\Config\Database;
use PDO;
use Exception;

class CitaController {

    /**
     * Asegura que existan las columnas y tablas necesarias para cola, check-in QR y control de tiempos.
     * Alineado con la estructura real de gm_tiempo_atencion:
     * (id, cita_id, medico_id, paciente_id, dia, tiempo, estado, inicio, fin)
     */
    private static function asegurarEsquemaCola(PDO $pdo): void {
        try {
            $cols = $pdo->query("SHOW COLUMNS FROM gm_citas")->fetchAll(PDO::FETCH_COLUMN);
            if (!in_array('tipo_cita', $cols, true)) {
                $pdo->exec("ALTER TABLE gm_citas ADD COLUMN tipo_cita VARCHAR(50) DEFAULT 'Hora Programada'");
            }
            if (!in_array('numero_llegada', $cols, true)) {
                $pdo->exec("ALTER TABLE gm_citas ADD COLUMN numero_llegada INT DEFAULT 0");
            }
        } catch (Exception $e) {}

        try {
            $pdo->exec("
                CREATE TABLE IF NOT EXISTS gm_tiempo_atencion (
                    id INT AUTO_INCREMENT PRIMARY KEY,
                    cita_id INT NOT NULL,
                    medico_id INT NOT NULL,
                    paciente_id INT NOT NULL DEFAULT 0,
                    dia DATE NOT NULL,
                    tiempo INT NOT NULL DEFAULT 0,
                    estado VARCHAR(30) NOT NULL DEFAULT 'en_proceso',
                    inicio DATETIME NOT NULL,
                    fin DATETIME NULL
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
            ");
        } catch (Exception $e) {}
    }

    /**
     * Obtiene la paleta de colores dinámica configurada por el administrador en gm_configuracion.
     */
    private static function obtenerPaletaTema(PDO $pdo): array {
        $paleta = [
            'theme_primary'        => '#C2186B',
            'theme_primary_dark'   => '#4A0E2E',
            'theme_secondary'      => '#F45D5D',
            'theme_background'     => '#FDF6F8',
            'theme_surface'        => '#FFFFFF',
            'theme_text_primary'   => '#2D1422',
            'theme_text_secondary' => '#7A5C6B',
        ];

        try {
            $stmt = $pdo->query("SELECT `clave`, `valor` FROM `gm_configuracion` WHERE `clave` LIKE 'theme_%'");
            if ($stmt) {
                while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
                    $clave = trim((string)($row['clave'] ?? ''));
                    $valor = trim((string)($row['valor'] ?? ''));
                    if ($clave !== '' && preg_match('/^#[0-9A-Fa-f]{6}$/', $valor)) {
                        $paleta[$clave] = $valor;
                    }
                }
            }
        } catch (Exception $e) {}

        return $paleta;
    }

    /**
     * Calcula el tiempo promedio de atención (en minutos) de un médico
     * a partir de los registros completados en gm_tiempo_atencion.
     */
    private static function obtenerPromedioAtencionMinutos(PDO $pdo, int $medicoId): float {
        $promedioMin = 15.0; // Valor por defecto si aún no hay consultas completadas
        if ($medicoId <= 0) {
            return $promedioMin;
        }

        try {
            $promStmt = $pdo->prepare("
                SELECT AVG(tiempo) / 60 
                FROM gm_tiempo_atencion 
                WHERE medico_id = :med 
                  AND estado = 'completado' 
                  AND tiempo > 0
            ");
            $promStmt->execute([':med' => $medicoId]);
            $valProm = (float)$promStmt->fetchColumn();
            if ($valProm > 0) {
                $promedioMin = $valProm;
            }
        } catch (Exception $e) {}

        return $promedioMin;
    }

    /**
     * Determina la modalidad ('Orden de Llegada' o 'Hora Programada') de un calendario
     * para una fecha específica leyendo dias_config.
     */
    private static function detectarModalidadDia(?string $diasConfigJson, string $fechaCita): string {
        if (!$diasConfigJson) {
            return 'Hora Programada';
        }
        $cfg = json_decode($diasConfigJson, true);
        if (!is_array($cfg)) {
            return 'Hora Programada';
        }

        $ts = strtotime($fechaCita);
        if ($ts === false) {
            return 'Hora Programada';
        }

        $diasEn = ['sunday', 'monday', 'tuesday', 'wednesday', 'thursday', 'friday', 'saturday'];
        $diasEs = ['domingo', 'lunes', 'martes', 'miercoles', 'jueves', 'viernes', 'sabado'];
        $idx = (int)date('w', $ts);

        $diaData = $cfg[$diasEn[$idx]] ?? $cfg[$diasEs[$idx]] ?? null;
        if (is_array($diaData)) {
            $mod = strtolower(trim((string)($diaData['modalidad'] ?? $diaData['tipo'] ?? '')));
            if ($mod === 'orden_llegada' || $mod === 'orden de llegada' || $mod === 'orden') {
                return 'Orden de Llegada';
            }
        }

        return 'Hora Programada';
    }

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

    private static function calcularMontoServicios(PDO $pdo, int $medicoId, string $procedimiento, $serviciosInput = null): float {
        $res = self::resolverServiciosCita($pdo, $medicoId, $procedimiento, $serviciosInput);
        return (float)$res['total'];
    }

    /**
     * GET /api/citas?medico_id=X&paciente_id=Y&fecha=YYYY-MM-DD
     * Devuelve también pacientes_delante y tiempo_estimado_min calculados dinámicamente
     * para mostrarle al paciente su tiempo aproximado de espera.
     */
    public static function listar() {
        try {
            $pdo = Database::conectar();
            self::asegurarEsquemaCola($pdo);

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
                    ORDER BY 
                        c.fecha_cita ASC,
                        CASE 
                            WHEN c.estado_cita COLLATE utf8mb4_unicode_ci = 'en_consulta' THEN 1
                            WHEN c.estado_cita COLLATE utf8mb4_unicode_ci = 'en_espera' THEN 2
                            ELSE 3
                        END ASC,
                        CASE WHEN c.numero_llegada > 0 THEN c.numero_llegada ELSE 999999 END ASC,
                        c.hora_cita ASC,
                        c.id ASC";

            $stmt = $pdo->prepare($sql);
            $stmt->execute($params);
            $citas = $stmt->fetchAll(PDO::FETCH_ASSOC);

            $updMonto = $pdo->prepare("UPDATE gm_citas SET total_usd = :total WHERE id = :id");
            $cachePromedios = [];

            $stmtColaEnConsulta = $pdo->prepare("
                SELECT COUNT(*) FROM gm_citas 
                WHERE medico_id = :med AND fecha_cita = :fecha 
                  AND estado_cita COLLATE utf8mb4_unicode_ci = 'en_consulta'
            ");
            $stmtColaEsperaDelante = $pdo->prepare("
                SELECT COUNT(*) FROM gm_citas 
                WHERE medico_id = :med AND fecha_cita = :fecha 
                  AND estado_cita COLLATE utf8mb4_unicode_ci = 'en_espera'
                  AND id != :id
                  AND (
                      (:turno1 > 0 AND numero_llegada > 0 AND numero_llegada < :turno2)
                      OR (:turno3 <= 0)
                  )
            ");

            foreach ($citas as &$cita) {
                $medId = (int)$cita['medico_id'];
                $resServicios = self::resolverServiciosCita(
                    $pdo,
                    $medId,
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

                if (!isset($cachePromedios[$medId])) {
                    $cachePromedios[$medId] = self::obtenerPromedioAtencionMinutos($pdo, $medId);
                }
                $promMin = $cachePromedios[$medId];

                $pacientesDelante = 0;
                $tiempoEstimadoMin = 0;
                $estadoLower = strtolower(trim((string)($cita['estado_cita'] ?? '')));

                if (in_array($estadoLower, ['reservada', 'confirmada', 'en_espera'], true)) {
                    $stmtColaEnConsulta->execute([':med' => $medId, ':fecha' => $cita['fecha_cita']]);
                    $enConsulta = (int)$stmtColaEnConsulta->fetchColumn();

                    $turnoActual = (int)($cita['numero_llegada'] ?? 0);
                    $stmtColaEsperaDelante->execute([
                        ':med'    => $medId,
                        ':fecha'  => $cita['fecha_cita'],
                        ':id'     => (int)$cita['id'],
                        ':turno1' => $turnoActual,
                        ':turno2' => $turnoActual,
                        ':turno3' => $turnoActual,
                    ]);
                    $enEsperaDelante = (int)$stmtColaEsperaDelante->fetchColumn();

                    $pacientesDelante  = $enConsulta + $enEsperaDelante;
                    $tiempoEstimadoMin = (int)round($pacientesDelante * $promMin);
                }

                $montoFormateado               = number_format($montoGuardado, 2, '.', '');
                $cita['servicios']             = $resServicios['servicios'];
                $cita['total_usd']             = $montoFormateado;
                $cita['monto_usd']             = $montoFormateado;
                $cita['monto_total']           = $montoFormateado;
                $cita['numero_llegada']        = (int)($cita['numero_llegada'] ?? 0);
                $cita['tipo_cita']             = trim((string)($cita['tipo_cita'] ?? 'Hora Programada'));
                $cita['promedio_atencion_min'] = (int)round($promMin);
                $cita['pacientes_delante']     = $pacientesDelante;
                $cita['tiempo_estimado_min']   = $tiempoEstimadoMin;
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
     * Agenda una nueva cita detectando automáticamente si el día es por Orden de Llegada
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
            self::asegurarEsquemaCola($pdo);

            $medicoId  = (int)$input['medico_id'];
            $fechaCita = trim((string)$input['fecha_cita']);

            $calStmt = $pdo->prepare("SELECT cupos_por_bloque, default_status, dias_config FROM gm_calendarios WHERE id = :id LIMIT 1");
            $calStmt->execute([':id' => (int)$input['calendario_id']]);
            $cal = $calStmt->fetch(PDO::FETCH_ASSOC);

            if (!$cal) {
                http_response_code(404);
                echo json_encode(["status" => "error", "mensaje" => "Calendario no encontrado."], JSON_UNESCAPED_UNICODE);
                return;
            }

            $tipoCitaDetectado = !empty($input['tipo_cita'])
                ? trim((string)$input['tipo_cita'])
                : self::detectarModalidadDia($cal['dias_config'] ?? null, $fechaCita);

            $esOrdenLlegada  = (stripos($tipoCitaDetectado, 'orden') !== false);
            $horaNormalizada = strlen($input['hora_cita']) === 5 ? $input['hora_cita'] . ':00' : $input['hora_cita'];

            if ($esOrdenLlegada) {
                $ocupStmt = $pdo->prepare("
                    SELECT COUNT(*) 
                    FROM gm_citas 
                    WHERE medico_id = :med 
                      AND calendario_id = :cal 
                      AND fecha_cita = :fecha 
                      AND estado_cita COLLATE utf8mb4_unicode_ci != 'cancelada' COLLATE utf8mb4_unicode_ci
                ");
                $ocupStmt->execute([
                    ':med'   => $medicoId,
                    ':cal'   => (int)$input['calendario_id'],
                    ':fecha' => $fechaCita
                ]);
                $ocupados    = (int)$ocupStmt->fetchColumn();
                $limiteCupos = max(1, (int)$cal['cupos_por_bloque']);

                if ($ocupados >= $limiteCupos) {
                    http_response_code(409);
                    echo json_encode(["status" => "error", "mensaje" => "Ya se alcanzó el límite máximo de cupos para este día."], JSON_UNESCAPED_UNICODE);
                    return;
                }
            } else {
                $ocupStmt = $pdo->prepare("
                    SELECT COUNT(*) 
                    FROM gm_citas 
                    WHERE medico_id = :med 
                      AND fecha_cita = :fecha 
                      AND hora_cita = :hora 
                      AND estado_cita COLLATE utf8mb4_unicode_ci != 'cancelada' COLLATE utf8mb4_unicode_ci
                ");
                $ocupStmt->execute([
                    ':med'   => $medicoId,
                    ':fecha' => $fechaCita,
                    ':hora'  => $horaNormalizada
                ]);
                $ocupados = (int)$ocupStmt->fetchColumn();

                if ($ocupados >= (int)$cal['cupos_por_bloque']) {
                    http_response_code(409);
                    echo json_encode(["status" => "error", "mensaje" => "Este bloque horario ya alcanzó el límite máximo de cupos disponibles."], JSON_UNESCAPED_UNICODE);
                    return;
                }
            }

            $estadoInicial = $input['estado_cita'] ?? ($cal['default_status'] ?: 'reservada');

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

            $totalUsd = isset($input['total_usd'])
                ? (float)$input['total_usd']
                : (isset($input['monto_usd']) ? (float)$input['monto_usd'] : (isset($input['monto_total']) ? (float)$input['monto_total'] : 0.00));

            if ($totalUsd <= 0) {
                $totalUsd = self::calcularMontoServicios($pdo, $medicoId, $procedimiento, $input['servicios'] ?? null);
            }

            $numeroLlegada = 0;
            if ($estadoInicial === 'en_espera') {
                $maxStmt = $pdo->prepare("SELECT COALESCE(MAX(numero_llegada), 0) FROM gm_citas WHERE medico_id = :med AND fecha_cita = :fecha");
                $maxStmt->execute([':med' => $medicoId, ':fecha' => $fechaCita]);
                $numeroLlegada = (int)$maxStmt->fetchColumn() + 1;
            }

            $sql = "INSERT INTO gm_citas 
                    (calendario_id, paciente_id, medico_id, fecha_cita, hora_cita, procedimiento, total_usd, estado_cita, tipo_cita, numero_llegada)
                    VALUES 
                    (:cal, :pac, :med, :fecha, :hora, :proc, :total, :estado, :tipo, :turno)";
            $stmt = $pdo->prepare($sql);
            $stmt->execute([
                ':cal'    => (int)$input['calendario_id'],
                ':pac'    => (int)$input['paciente_id'],
                ':med'    => $medicoId,
                ':fecha'  => $fechaCita,
                ':hora'   => $horaNormalizada,
                ':proc'   => $procedimiento,
                ':total'  => round($totalUsd, 2),
                ':estado' => $estadoInicial,
                ':tipo'   => $tipoCitaDetectado,
                ':turno'  => $numeroLlegada
            ]);

            http_response_code(201);
            echo json_encode([
                "status"         => "success",
                "mensaje"        => "Cita médica agendada correctamente.",
                "cita_id"        => $pdo->lastInsertId(),
                "tipo_cita"      => $tipoCitaDetectado,
                "numero_llegada" => $numeroLlegada,
                "total_usd"      => number_format($totalUsd, 2, '.', '')
            ], JSON_UNESCAPED_UNICODE);

        } catch (Exception $e) {
            http_response_code(500);
            echo json_encode(["status" => "error", "mensaje" => $e->getMessage()], JSON_UNESCAPED_UNICODE);
        }
    }

    /**
     * PUT /api/citas
     * Permite cambiar estado (confirmar, en_espera, en_consulta, completada, cancelar),
     * reprogramar fecha/hora y gestionar automáticamente el cronómetro de gm_tiempo_atencion.
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
            self::asegurarEsquemaCola($pdo);

            $stmtActual = $pdo->prepare("SELECT * FROM gm_citas WHERE id = :id LIMIT 1");
            $stmtActual->execute([':id' => $citaId]);
            $citaActual = $stmtActual->fetch(PDO::FETCH_ASSOC);

            if (!$citaActual) {
                http_response_code(404);
                echo json_encode(["status" => "error", "mensaje" => "Cita no encontrada."], JSON_UNESCAPED_UNICODE);
                return;
            }

            $campos = [];
            $params = [':id' => $citaId];
            $nuevoEstado = '';

            if (!empty($input['estado_cita'])) {
                $nuevoEstado = trim((string)$input['estado_cita']);
                $campos[] = "estado_cita = :estado";
                $params[':estado'] = $nuevoEstado;

                if ($nuevoEstado === 'en_espera' && (int)($citaActual['numero_llegada'] ?? 0) <= 0) {
                    $maxStmt = $pdo->prepare("SELECT COALESCE(MAX(numero_llegada), 0) FROM gm_citas WHERE medico_id = :med AND fecha_cita = :fecha");
                    $maxStmt->execute([
                        ':med'   => (int)$citaActual['medico_id'],
                        ':fecha' => $citaActual['fecha_cita']
                    ]);
                    $siguienteTurno = (int)$maxStmt->fetchColumn() + 1;
                    $campos[] = "numero_llegada = :turno";
                    $params[':turno'] = $siguienteTurno;
                }
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

                $medId = (int)$citaActual['medico_id'];
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

            // Sincronización automática de gm_tiempo_atencion según el cambio de estado
            $estadoLower = strtolower($nuevoEstado);
            if ($estadoLower === 'en_consulta') {
                self::registrarInicioTiempoAtencion(
                    $pdo,
                    $citaId,
                    (int)$citaActual['medico_id'],
                    (int)$citaActual['paciente_id']
                );
            } elseif ($estadoLower === 'completada') {
                self::finalizarTiempoAtencion($pdo, $citaId);
            }

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
     * Inicia el registro de tiempo en gm_tiempo_atencion (evitando duplicados si ya está en_proceso)
     */
    public static function registrarInicioTiempoAtencion(PDO $pdo, int $citaId, int $medicoId, int $pacienteId = 0): void {
        self::asegurarEsquemaCola($pdo);
        if ($citaId <= 0) {
            return;
        }

        if ($medicoId <= 0 || $pacienteId <= 0) {
            $stmtC = $pdo->prepare("SELECT medico_id, paciente_id FROM gm_citas WHERE id = :cid LIMIT 1");
            $stmtC->execute([':cid' => $citaId]);
            $rowC = $stmtC->fetch(PDO::FETCH_ASSOC);
            if ($rowC) {
                if ($medicoId <= 0) $medicoId = (int)$rowC['medico_id'];
                if ($pacienteId <= 0) $pacienteId = (int)$rowC['paciente_id'];
            }
        }

        $check = $pdo->prepare("SELECT id FROM gm_tiempo_atencion WHERE cita_id = :cid AND estado = 'en_proceso' LIMIT 1");
        $check->execute([':cid' => $citaId]);
        if (!$check->fetchColumn()) {
            $ins = $pdo->prepare("
                INSERT INTO gm_tiempo_atencion (cita_id, medico_id, paciente_id, dia, tiempo, estado, inicio)
                VALUES (:cid, :med, :pac, CURDATE(), 0, 'en_proceso', NOW())
            ");
            $ins->execute([
                ':cid' => $citaId,
                ':med' => $medicoId,
                ':pac' => $pacienteId
            ]);
        }
    }

    /**
     * Detiene el cronómetro en gm_tiempo_atencion y guarda la duración en segundos
     */
    public static function finalizarTiempoAtencion(PDO $pdo, int $citaId): void {
        self::asegurarEsquemaCola($pdo);
        if ($citaId <= 0) {
            return;
        }

        $stmt = $pdo->prepare("
            SELECT id FROM gm_tiempo_atencion 
            WHERE cita_id = :cid AND estado = 'en_proceso' 
            ORDER BY id DESC LIMIT 1
        ");
        $stmt->execute([':cid' => $citaId]);
        $tiempoId = (int)$stmt->fetchColumn();

        if ($tiempoId > 0) {
            $upd = $pdo->prepare("
                UPDATE gm_tiempo_atencion 
                SET fin = NOW(),
                    tiempo = GREATEST(1, TIMESTAMPDIFF(SECOND, inicio, NOW())),
                    estado = 'completado'
                WHERE id = :id
            ");
            $upd->execute([':id' => $tiempoId]);
        }
    }

    /**
     * Lógica compartida de check-in en sala de espera por paciente_id o cédula
     */
    private static function ejecutarCheckIn(PDO $pdo, int $medicoId, int $pacienteId = 0, string $cedula = ''): array {
        self::asegurarEsquemaCola($pdo);
        $hoy = date('Y-m-d');

        if ($pacienteId <= 0 && trim($cedula) !== '') {
            $cedLimpia = preg_replace('/[^0-9]/', '', $cedula);
            $pacStmt = $pdo->prepare("
                SELECT user_id 
                FROM gm_usuarios_extendidos 
                WHERE REPLACE(REPLACE(REPLACE(cedula, '.', ''), '-', ''), ' ', '') LIKE :ced 
                LIMIT 1
            ");
            $pacStmt->execute([':ced' => '%' . $cedLimpia]);
            $pacienteId = (int)$pacStmt->fetchColumn();
        }

        if ($pacienteId <= 0) {
            return [
                'http'    => 404,
                'status'  => 'error',
                'mensaje' => 'No se encontró ningún paciente registrado con esos datos.'
            ];
        }

        $stmt = $pdo->prepare("
            SELECT c.*, CONCAT(p.nombre, ' ', p.apellido) AS paciente_nombre
            FROM gm_citas c
            LEFT JOIN gm_usuarios_extendidos p ON c.paciente_id = p.user_id
            WHERE c.medico_id = :med 
              AND c.paciente_id = :pac 
              AND c.fecha_cita = :hoy 
              AND c.estado_cita COLLATE utf8mb4_unicode_ci IN ('reservada', 'confirmada', 'en_espera', 'en_consulta')
            ORDER BY c.hora_cita ASC 
            LIMIT 1
        ");
        $stmt->execute([':med' => $medicoId, ':pac' => $pacienteId, ':hoy' => $hoy]);
        $cita = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$cita) {
            return [
                'http'    => 404,
                'status'  => 'error',
                'mensaje' => 'No tiene ninguna cita programada para el día de hoy con este médico.'
            ];
        }

        if ($cita['estado_cita'] === 'en_espera' && (int)$cita['numero_llegada'] > 0) {
            $turnoAsignado = (int)$cita['numero_llegada'];
        } else {
            $maxStmt = $pdo->prepare("SELECT COALESCE(MAX(numero_llegada), 0) FROM gm_citas WHERE medico_id = :med AND fecha_cita = :hoy");
            $maxStmt->execute([':med' => $medicoId, ':hoy' => $hoy]);
            $turnoAsignado = (int)$maxStmt->fetchColumn() + 1;

            $upd = $pdo->prepare("UPDATE gm_citas SET estado_cita = 'en_espera', numero_llegada = :turno WHERE id = :id");
            $upd->execute([':turno' => $turnoAsignado, ':id' => $cita['id']]);
        }

        $delanteStmt = $pdo->prepare("
            SELECT COUNT(*) 
            FROM gm_citas 
            WHERE medico_id = :med 
              AND fecha_cita = :hoy 
              AND estado_cita COLLATE utf8mb4_unicode_ci = 'en_espera' 
              AND numero_llegada > 0
              AND numero_llegada < :turno
        ");
        $delanteStmt->execute([':med' => $medicoId, ':hoy' => $hoy, ':turno' => $turnoAsignado]);
        $pacientesDelante = (int)$delanteStmt->fetchColumn();

        $enConsultaStmt = $pdo->prepare("
            SELECT COUNT(*) 
            FROM gm_citas 
            WHERE medico_id = :med 
              AND fecha_cita = :hoy 
              AND estado_cita COLLATE utf8mb4_unicode_ci = 'en_consulta'
        ");
        $enConsultaStmt->execute([':med' => $medicoId, ':hoy' => $hoy]);
        $pacientesDelante += (int)$enConsultaStmt->fetchColumn();

        $promedioMin       = self::obtenerPromedioAtencionMinutos($pdo, $medicoId);
        $tiempoEstimadoMin = (int)round($pacientesDelante * $promedioMin);

        return [
            'http'                => 200,
            'status'              => 'success',
            'mensaje'             => '¡Llegada registrada! Ha ingresado a la sala de espera.',
            'cita_id'             => (int)$cita['id'],
            'paciente_nombre'     => trim((string)($cita['paciente_nombre'] ?? 'Paciente')),
            'tipo_cita'           => trim((string)($cita['tipo_cita'] ?? 'Orden de Llegada')),
            'numero_turno'        => $turnoAsignado,
            'pacientes_delante'   => $pacientesDelante,
            'promedio_min'        => (int)round($promedioMin),
            'tiempo_estimado_min' => $tiempoEstimadoMin,
        ];
    }

    /**
     * POST /api/citas/check-in-qr
     */
    public static function checkInQr($input) {
        try {
            $medicoId   = (int)($input['medico_id'] ?? 0);
            $pacienteId = (int)($input['paciente_id'] ?? 0);
            $cedula     = trim((string)($input['cedula'] ?? ''));

            if ($medicoId <= 0 || ($pacienteId <= 0 && $cedula === '')) {
                http_response_code(400);
                echo json_encode([
                    "status"  => "error",
                    "mensaje" => "Debe indicar medico_id y el paciente_id o cédula."
                ], JSON_UNESCAPED_UNICODE);
                return;
            }

            $pdo = Database::conectar();
            $res = self::ejecutarCheckIn($pdo, $medicoId, $pacienteId, $cedula);
            http_response_code($res['http']);
            unset($res['http']);
            echo json_encode($res, JSON_UNESCAPED_UNICODE);

        } catch (Exception $e) {
            http_response_code(500);
            echo json_encode(["status" => "error", "mensaje" => $e->getMessage()], JSON_UNESCAPED_UNICODE);
        }
    }

    /**
     * GET /marcar-llegada?medico_id=X o ?wpgm_marcar_llegada=1&medico_id=X
     * Hereda dinámicamente los colores del tema activo configurado por el administrador en gm_configuracion.
     */
    public static function renderizarCheckInWeb() {
        header("Content-Type: text/html; charset=utf-8");
        $medicoId   = (int)($_GET['medico_id'] ?? $_GET['med'] ?? 0);
        $pacienteId = (int)($_GET['paciente_id'] ?? $_GET['pac'] ?? 0);
        $cedulaPost = trim((string)($_POST['cedula'] ?? ''));

        $pdo    = Database::conectar();
        $paleta = self::obtenerPaletaTema($pdo);

        $cPrimary     = htmlspecialchars($paleta['theme_primary'], ENT_QUOTES, 'UTF-8');
        $cPrimaryDark = htmlspecialchars($paleta['theme_primary_dark'], ENT_QUOTES, 'UTF-8');
        $cSecondary   = htmlspecialchars($paleta['theme_secondary'], ENT_QUOTES, 'UTF-8');
        $cBackground  = htmlspecialchars($paleta['theme_background'], ENT_QUOTES, 'UTF-8');
        $cSurface     = htmlspecialchars($paleta['theme_surface'], ENT_QUOTES, 'UTF-8');
        $cTextPrimary = htmlspecialchars($paleta['theme_text_primary'], ENT_QUOTES, 'UTF-8');
        $cTextSec     = htmlspecialchars($paleta['theme_text_secondary'], ENT_QUOTES, 'UTF-8');

        $nombreMedico = 'Consultorio Médico';
        $especialidad = '';

        if ($medicoId > 0) {
            $stmtMed = $pdo->prepare("SELECT nombre, apellido, especialidad FROM gm_usuarios_extendidos WHERE user_id = :id LIMIT 1");
            $stmtMed->execute([':id' => $medicoId]);
            $med = $stmtMed->fetch(PDO::FETCH_ASSOC);
            if ($med) {
                $nombreMedico = 'Dr(a). ' . trim(($med['nombre'] ?? '') . ' ' . ($med['apellido'] ?? ''));
                $especialidad = trim((string)($med['especialidad'] ?? ''));
            }
        }

        $resultado = null;
        if ($medicoId > 0 && ($pacienteId > 0 || ($_SERVER['REQUEST_METHOD'] === 'POST' && $cedulaPost !== ''))) {
            $resultado = self::ejecutarCheckIn($pdo, $medicoId, $pacienteId, $cedulaPost);
        }

        echo '<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Sala de Espera - Check-in QR</title>
<style>
  :root {
    --theme-primary: ' . $cPrimary . ';
    --theme-primary-dark: ' . $cPrimaryDark . ';
    --theme-secondary: ' . $cSecondary . ';
    --theme-background: ' . $cBackground . ';
    --theme-surface: ' . $cSurface . ';
    --theme-text-primary: ' . $cTextPrimary . ';
    --theme-text-secondary: ' . $cTextSec . ';
  }
  * { box-sizing: border-box; }
  body {
    font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif;
    background-color: var(--theme-background);
    color: var(--theme-text-primary);
    margin: 0;
    padding: 20px;
    display: flex;
    justify-content: center;
    align-items: center;
    min-height: 100vh;
  }
  .card {
    background-color: var(--theme-surface);
    border-radius: 18px;
    border-top: 6px solid var(--theme-primary);
    box-shadow: 0 10px 30px rgba(0, 0, 0, 0.08);
    max-width: 420px;
    width: 100%;
    padding: 30px 26px;
    text-align: center;
  }
  .badge-brand {
    display: inline-block;
    background-color: color-mix(in srgb, var(--theme-primary) 12%, transparent);
    color: var(--theme-primary);
    font-size: 11px;
    font-weight: 700;
    letter-spacing: 0.5px;
    text-transform: uppercase;
    padding: 5px 12px;
    border-radius: 20px;
    margin-bottom: 12px;
  }
  h1 {
    font-size: 22px;
    color: var(--theme-text-primary);
    margin: 0 0 6px;
    font-weight: 800;
  }
  .sub {
    font-size: 14px;
    color: var(--theme-text-secondary);
    margin-bottom: 22px;
  }
  .desc {
    font-size: 14px;
    color: var(--theme-text-primary);
    margin-bottom: 14px;
    line-height: 1.45;
  }
  input {
    width: 100%;
    padding: 14px;
    font-size: 16px;
    background-color: var(--theme-background);
    color: var(--theme-text-primary);
    border: 1.8px solid color-mix(in srgb, var(--theme-primary) 30%, #cbd5e1);
    border-radius: 12px;
    margin-bottom: 14px;
    text-align: center;
    transition: border-color 0.2s ease, box-shadow 0.2s ease;
  }
  input:focus {
    outline: none;
    border-color: var(--theme-primary);
    background-color: var(--theme-surface);
    box-shadow: 0 0 0 3px color-mix(in srgb, var(--theme-primary) 18%, transparent);
  }
  button {
    width: 100%;
    padding: 14px;
    font-size: 16px;
    font-weight: 700;
    background-color: var(--theme-primary);
    color: #ffffff;
    border: none;
    border-radius: 12px;
    cursor: pointer;
    box-shadow: 0 4px 14px color-mix(in srgb, var(--theme-primary) 32%, transparent);
    transition: background-color 0.2s ease, transform 0.1s ease;
  }
  button:hover {
    background-color: var(--theme-primary-dark);
  }
  button:active {
    transform: scale(0.99);
  }
  .turno-box {
    background-color: var(--theme-background);
    border: 2px solid var(--theme-primary);
    border-radius: 16px;
    padding: 20px;
    margin-top: 10px;
  }
  .turno-num {
    font-size: 48px;
    font-weight: 900;
    color: var(--theme-primary);
    line-height: 1.1;
    margin: 8px 0;
  }
  .err {
    background-color: color-mix(in srgb, var(--theme-secondary) 12%, #ffffff);
    border: 1.5px solid var(--theme-secondary);
    color: var(--theme-primary-dark);
    padding: 14px;
    border-radius: 12px;
    margin-bottom: 16px;
    font-size: 14px;
    font-weight: 500;
    line-height: 1.4;
  }
</style>
</head>
<body>
<div class="card">
  <div class="badge-brand">GinevApp • Sala de Espera</div>
  <h1>' . htmlspecialchars($nombreMedico) . '</h1>
  <div class="sub">' . htmlspecialchars($especialidad ?: 'Registro de Llegada a Consulta') . '</div>';

        if ($medicoId <= 0) {
            echo '<div class="err">Código QR inválido: falta el identificador del médico.</div>';
        } elseif ($resultado !== null && $resultado['status'] === 'success') {
            echo '<div class="turno-box">
                    <div style="font-size:13px;color:var(--theme-primary-dark);font-weight:700;letter-spacing:0.4px;">¡LLEGADA CONFIRMADA!</div>
                    <div style="font-size:16px;color:var(--theme-text-primary);font-weight:600;margin-top:4px;">' . htmlspecialchars($resultado['paciente_nombre']) . '</div>
                    <div style="font-size:11px;color:var(--theme-text-secondary);margin-top:12px;font-weight:600;">SU TURNO EN SALA DE ESPERA ES</div>
                    <div class="turno-num">#' . (int)$resultado['numero_turno'] . '</div>
                    <div style="font-size:14px;color:var(--theme-text-primary);">Pacientes delante: <strong>' . (int)$resultado['pacientes_delante'] . '</strong></div>
                    <div style="font-size:14px;color:var(--theme-text-primary);margin-top:4px;">Tiempo aproximado de espera: <strong>~' . (int)$resultado['tiempo_estimado_min'] . ' min</strong></div>
                  </div>';
        } else {
            if ($resultado !== null && $resultado['status'] === 'error') {
                echo '<div class="err">' . htmlspecialchars($resultado['mensaje']) . '</div>';
            }
            echo '<form method="POST">
                    <p class="desc">Ingresa tu número de cédula para anunciar tu llegada y asignar tu turno:</p>
                    <input type="text" name="cedula" placeholder="Ej. 18456789" required autofocus>
                    <button type="submit">Anunciar mi Llegada</button>
                  </form>';
        }

        echo '</div></body></html>';
        exit;
    }

    /**
     * POST /api/citas/iniciar-atencion
     * Inicia la consulta del paciente y guarda el registro inicial en gm_tiempo_atencion
     */
    public static function iniciarAtencion($input) {
        try {
            $citaId     = (int)($input['cita_id'] ?? 0);
            $medicoId   = (int)($input['medico_id'] ?? 0);
            $pacienteId = (int)($input['paciente_id'] ?? 0);

            if ($citaId <= 0 || $medicoId <= 0) {
                http_response_code(400);
                echo json_encode(["status" => "error", "mensaje" => "Los campos cita_id y medico_id son obligatorios."], JSON_UNESCAPED_UNICODE);
                return;
            }

            $pdo = Database::conectar();
            self::asegurarEsquemaCola($pdo);

            $pdo->prepare("UPDATE gm_citas SET estado_cita = 'en_consulta' WHERE id = :cid")
                ->execute([':cid' => $citaId]);

            self::registrarInicioTiempoAtencion($pdo, $citaId, $medicoId, $pacienteId);

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
                } catch (Exception $e) {}

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

                if (empty($sedes) && ($paisBase !== '' || $estadoBase !== '' || $dirBase !== '')) {
                    $sedes[] = [
                        'id'        => 0,
                        'nombre'    => 'Consultorio Principal',
                        'pais'      => $paisBase,
                        'estado'    => $estadoBase,
                        'direccion' => $dirBase,
                    ];
                }

                $stmtServ = $pdo->prepare("
                    SELECT id, tipo, nombre_servicio, descripcion, precio, items_combo, estado
                    FROM gm_servicios
                    WHERE medico_id = :mid
                      AND (estado IS NULL OR TRIM(LOWER(estado)) != 'inactivo')
                    ORDER BY CASE WHEN LOWER(TRIM(tipo)) = 'combo' THEN 0 ELSE 1 END, nombre_servicio ASC
                ");
                $stmtServ->execute([':mid' => $uid]);
                $servicios = $stmtServ->fetchAll(PDO::FETCH_ASSOC);

                $avatar = trim((string)($med['avatar_url'] ?? $med['avatar'] ?? ''));
                if ($avatar === '') {
                    try {
                        $stmtMeta = $pdo->prepare("SELECT valor FROM gm_configuracion WHERE clave = :k LIMIT 1");
                        $stmtMeta->execute([':k' => 'avatar_medico_' . $uid]);
                        $avatar = trim((string)($stmtMeta->fetchColumn() ?: ''));
                    } catch (Exception $e) {}
                }

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