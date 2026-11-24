<?php
namespace App\Controllers;

use App\Config\Database;
use App\Documentos\FacturaPdfGenerator;
use App\Documentos\PresupuestoPdfGenerator;
use PDO;
use Exception;

class FinanzaServicioController {

    private static function asegurarColumnaEstado(PDO $pdo) {
        $stmt = $pdo->prepare("
            SELECT COUNT(*)
            FROM INFORMATION_SCHEMA.COLUMNS
            WHERE TABLE_SCHEMA = DATABASE()
              AND TABLE_NAME = 'gm_servicios'
              AND COLUMN_NAME = 'estado'
        ");
        $stmt->execute();
        $existe = (int)$stmt->fetchColumn() > 0;

        if (!$existe) {
            $pdo->exec("ALTER TABLE gm_servicios ADD COLUMN estado VARCHAR(20) DEFAULT 'activo'");
        }
    }

    public static function asegurarEsquemaFinanciamientoYCobros(PDO $pdo): void {
        self::asegurarColumnaEstado($pdo);

        $colsServicios = [
            'permite_financiamiento' => "ALTER TABLE `gm_servicios` ADD COLUMN `permite_financiamiento` tinyint(1) NOT NULL DEFAULT 0 AFTER `precio`",
            'cantidad_cuotas'        => "ALTER TABLE `gm_servicios` ADD COLUMN `cantidad_cuotas` int(3) NOT NULL DEFAULT 1 AFTER `permite_financiamiento`",
            'frecuencia_cuotas'      => "ALTER TABLE `gm_servicios` ADD COLUMN `frecuencia_cuotas` varchar(20) NOT NULL DEFAULT 'semanal' AFTER `cantidad_cuotas`",
        ];

        foreach ($colsServicios as $col => $sqlAlter) {
            $stmt = $pdo->prepare("
                SELECT COUNT(*)
                FROM INFORMATION_SCHEMA.COLUMNS
                WHERE TABLE_SCHEMA = DATABASE()
                  AND TABLE_NAME = 'gm_servicios'
                  AND COLUMN_NAME = :col
            ");
            $stmt->execute([':col' => $col]);
            if ((int)$stmt->fetchColumn() === 0) {
                $pdo->exec($sqlAlter);
            }
        }

        $pdo->exec("
            CREATE TABLE IF NOT EXISTS `gm_cuentas_por_cobrar` (
                `id` mediumint(9) NOT NULL AUTO_INCREMENT,
                `medico_id` bigint(20) NOT NULL,
                `paciente_id` bigint(20) NOT NULL,
                `cita_id` mediumint(9) DEFAULT NULL,
                `servicio_id` mediumint(9) DEFAULT NULL,
                `fecha` date NOT NULL,
                `descripcion` varchar(255) NOT NULL,
                `fecha_vencimiento` date NOT NULL,
                `cantidad_cuotas` int(3) NOT NULL DEFAULT 1,
                `frecuencia_cuotas` varchar(20) NOT NULL DEFAULT 'semanal',
                `debe` decimal(10,2) NOT NULL DEFAULT 0.00,
                `haber` decimal(10,2) NOT NULL DEFAULT 0.00,
                `saldo` decimal(10,2) NOT NULL DEFAULT 0.00,
                `debe_bs` decimal(12,2) NOT NULL DEFAULT 0.00,
                `haber_bs` decimal(12,2) NOT NULL DEFAULT 0.00,
                `saldo_bs` decimal(12,2) NOT NULL DEFAULT 0.00,
                `tasa_bcv` decimal(10,4) NOT NULL DEFAULT 1.0000,
                `estado` varchar(20) NOT NULL DEFAULT 'pendiente',
                `fecha_creacion` datetime DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (`id`),
                INDEX `idx_cxc_medico_fecha` (`medico_id`, `fecha`),
                INDEX `idx_cxc_paciente` (`paciente_id`),
                INDEX `idx_cxc_vencimiento` (`fecha_vencimiento`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
        ");
    }

    /**
     * GET /api/servicios?medico_id=X
     */
    public static function listarServicios() {
        try {
            $pdo = Database::conectar();
            self::asegurarEsquemaFinanciamientoYCobros($pdo);
            $medicoId = isset($_GET['medico_id']) ? (int)$_GET['medico_id'] : 0;

            if ($medicoId > 0) {
                $stmt = $pdo->prepare("SELECT * FROM gm_servicios WHERE medico_id = :med ORDER BY tipo ASC, nombre_servicio ASC");
                $stmt->execute([':med' => $medicoId]);
            } else {
                $stmt = $pdo->query("SELECT * FROM gm_servicios ORDER BY id DESC");
            }

            $servicios = $stmt->fetchAll(PDO::FETCH_ASSOC);
            foreach ($servicios as &$s) {
                $s['items_combo'] = !empty($s['items_combo']) ? json_decode($s['items_combo'], true) : [];
                $s['estado'] = !empty($s['estado']) ? $s['estado'] : 'activo';
                $s['permite_financiamiento'] = (int)($s['permite_financiamiento'] ?? 0);
                $s['cantidad_cuotas'] = max(1, (int)($s['cantidad_cuotas'] ?? 1));
                $s['frecuencia_cuotas'] = !empty($s['frecuencia_cuotas']) ? $s['frecuencia_cuotas'] : 'semanal';
            }

            echo json_encode([
                "status" => "success",
                "total"  => count($servicios),
                "data"   => $servicios
            ], JSON_UNESCAPED_UNICODE);

        } catch (Exception $e) {
            http_response_code(500);
            echo json_encode(["status" => "error", "mensaje" => $e->getMessage()], JSON_UNESCAPED_UNICODE);
        }
    }

    /**
     * POST /api/servicios
     * Crea o actualiza un servicio individual o combo (si viene 'id' > 0, actualiza)
     */
    public static function crearServicio($input = null) {
        try {
            if ($input === null) {
                $input = json_decode(file_get_contents('php://input'), true) ?: [];
            }

            $servId   = (int)($input['id'] ?? 0);
            $medicoId = (int)($input['medico_id'] ?? 0);
            $nombre   = trim($input['nombre_servicio'] ?? '');
            $precio   = (float)($input['precio'] ?? 0);

            if ($medicoId <= 0 || empty($nombre)) {
                http_response_code(400);
                echo json_encode(["status" => "error", "mensaje" => "Los campos medico_id y nombre_servicio son obligatorios."], JSON_UNESCAPED_UNICODE);
                return;
            }

            $pdo = Database::conectar();
            self::asegurarEsquemaFinanciamientoYCobros($pdo);

            $tipo = trim($input['tipo'] ?? 'servicio');
            $itemsCombo = null;
            if ($tipo === 'combo' && !empty($input['items_combo'])) {
                $itemsCombo = is_string($input['items_combo'])
                    ? $input['items_combo']
                    : json_encode(array_map('intval', $input['items_combo']), JSON_UNESCAPED_UNICODE);
            }

            $permiteFinanciamiento = !empty($input['permite_financiamiento']) ? 1 : 0;
            $cantidadCuotas = $permiteFinanciamiento ? max(1, (int)($input['cantidad_cuotas'] ?? 1)) : 1;
            $frecuenciaCuotas = trim((string)($input['frecuencia_cuotas'] ?? 'semanal'));
            if ($frecuenciaCuotas === '') {
                $frecuenciaCuotas = 'semanal';
            }

            if ($servId > 0) {
                $sql = "UPDATE gm_servicios
                        SET tipo = :tipo, nombre_servicio = :nombre, descripcion = :desc, precio = :precio,
                            permite_financiamiento = :pfin, cantidad_cuotas = :ccuo, frecuencia_cuotas = :fcuo,
                            items_combo = :items
                        WHERE id = :id AND medico_id = :med";
                $stmt = $pdo->prepare($sql);
                $stmt->execute([
                    ':tipo'   => $tipo,
                    ':nombre' => $nombre,
                    ':desc'   => $input['descripcion'] ?? '',
                    ':precio' => $precio,
                    ':pfin'   => $permiteFinanciamiento,
                    ':ccuo'   => $cantidadCuotas,
                    ':fcuo'   => $frecuenciaCuotas,
                    ':items'  => $itemsCombo,
                    ':id'     => $servId,
                    ':med'    => $medicoId
                ]);

                echo json_encode([
                    "status"  => "success",
                    "mensaje" => "Servicio o combo actualizado correctamente.",
                    "id"      => $servId
                ], JSON_UNESCAPED_UNICODE);
            } else {
                $sql = "INSERT INTO gm_servicios (medico_id, nombre_servicio, descripcion, precio, permite_financiamiento, cantidad_cuotas, frecuencia_cuotas, tipo, items_combo, estado) 
                        VALUES (:med, :nombre, :desc, :precio, :pfin, :ccuo, :fcuo, :tipo, :items, 'activo')";
                $stmt = $pdo->prepare($sql);
                $stmt->execute([
                    ':med'    => $medicoId,
                    ':nombre' => $nombre,
                    ':desc'   => $input['descripcion'] ?? '',
                    ':precio' => $precio,
                    ':pfin'   => $permiteFinanciamiento,
                    ':ccuo'   => $cantidadCuotas,
                    ':fcuo'   => $frecuenciaCuotas,
                    ':tipo'   => $tipo,
                    ':items'  => $itemsCombo
                ]);

                http_response_code(201);
                echo json_encode([
                    "status"  => "success",
                    "mensaje" => "Servicio o combo registrado correctamente.",
                    "id"      => $pdo->lastInsertId()
                ], JSON_UNESCAPED_UNICODE);
            }

        } catch (Exception $e) {
            http_response_code(500);
            echo json_encode(["status" => "error", "mensaje" => $e->getMessage()], JSON_UNESCAPED_UNICODE);
        }
    }

    /**
     * POST /api/servicios/estado
     */
    public static function cambiarEstadoServicio($input = null) {
        try {
            if ($input === null) {
                $input = json_decode(file_get_contents('php://input'), true) ?: [];
            }
            $servId   = (int)($input['id'] ?? 0);
            $medicoId = (int)($input['medico_id'] ?? 0);
            $estado   = trim($input['estado'] ?? 'activo');

            if ($servId <= 0 || $medicoId <= 0) {
                http_response_code(400);
                echo json_encode(["status" => "error", "mensaje" => "Parámetros inválidos."], JSON_UNESCAPED_UNICODE);
                return;
            }

            $pdo = Database::conectar();
            self::asegurarColumnaEstado($pdo);

            $stmt = $pdo->prepare("UPDATE gm_servicios SET estado = :estado WHERE id = :id AND medico_id = :med");
            $stmt->execute([
                ':estado' => ($estado === 'inactivo') ? 'inactivo' : 'activo',
                ':id'     => $servId,
                ':med'    => $medicoId
            ]);

            echo json_encode([
                "status"  => "success",
                "mensaje" => "Estatus actualizado."
            ], JSON_UNESCAPED_UNICODE);

        } catch (Exception $e) {
            http_response_code(500);
            echo json_encode(["status" => "error", "mensaje" => $e->getMessage()], JSON_UNESCAPED_UNICODE);
        }
    }

    /**
     * DELETE /api/servicios?id=X&medico_id=Y
     */
    public static function eliminarServicio() {
        try {
            $servId   = (int)($_GET['id'] ?? 0);
            $medicoId = (int)($_GET['medico_id'] ?? 0);

            if ($servId <= 0 || $medicoId <= 0) {
                http_response_code(400);
                echo json_encode(["status" => "error", "mensaje" => "Parámetros inválidos para eliminar."], JSON_UNESCAPED_UNICODE);
                return;
            }

            $pdo = Database::conectar();
            $stmt = $pdo->prepare("DELETE FROM gm_servicios WHERE id = :id AND medico_id = :med");
            $stmt->execute([':id' => $servId, ':med' => $medicoId]);

            echo json_encode([
                "status"  => "success",
                "mensaje" => "Registro eliminado correctamente."
            ], JSON_UNESCAPED_UNICODE);

        } catch (Exception $e) {
            http_response_code(500);
            echo json_encode(["status" => "error", "mensaje" => $e->getMessage()], JSON_UNESCAPED_UNICODE);
        }
    }

    /**
     * GET /api/tasa-bcv
     */
    public static function obtenerTasaBcv() {
        try {
            $pdo = Database::conectar();
            $stmt = $pdo->query("SELECT id, fecha, tasa_usd FROM gm_tasa_bcv ORDER BY fecha DESC LIMIT 1");
            $tasa = $stmt->fetch(PDO::FETCH_ASSOC);

            echo json_encode([
                "status" => "success",
                "data"   => $tasa ?: ["tasa_usd" => "1.0000", "fecha" => date('Y-m-d H:i:s')]
            ], JSON_UNESCAPED_UNICODE);

        } catch (Exception $e) {
            http_response_code(500);
            echo json_encode(["status" => "error", "mensaje" => $e->getMessage()], JSON_UNESCAPED_UNICODE);
        }
    }

    /**
     * POST /api/tasa-bcv
     */
    public static function guardarTasaBcv($input = null) {
        try {
            if ($input === null) {
                $input = json_decode(file_get_contents('php://input'), true) ?: [];
            }
            $tasaUsd = (float)($input['tasa_usd'] ?? 0);
            if ($tasaUsd <= 0) {
                http_response_code(400);
                echo json_encode(["status" => "error", "mensaje" => "Debe indicar un valor válido para tasa_usd."], JSON_UNESCAPED_UNICODE);
                return;
            }

            $pdo = Database::conectar();
            $fecha = !empty($input['fecha']) ? trim($input['fecha']) : date('Y-m-d H:i:s');

            $sql = "INSERT INTO gm_tasa_bcv (fecha, tasa_usd) VALUES (:fecha, :tasa)
                    ON DUPLICATE KEY UPDATE tasa_usd = VALUES(tasa_usd)";
            $pdo->prepare($sql)->execute([
                ':fecha' => $fecha,
                ':tasa'  => $tasaUsd
            ]);

            echo json_encode([
                "status"   => "success",
                "mensaje"  => "Tasa BCV actualizada correctamente.",
                "tasa_usd" => $tasaUsd
            ], JSON_UNESCAPED_UNICODE);

        } catch (Exception $e) {
            http_response_code(500);
            echo json_encode(["status" => "error", "mensaje" => $e->getMessage()], JSON_UNESCAPED_UNICODE);
        }
    }

    /**
     * GET /api/finanzas?medico_id=X&paciente_id=Y&filtro_tipo=dia|mes|custom
     */
    public static function listarFinanzas() {
        try {
            $pdo = Database::conectar();
            if (class_exists(FacturaPdfGenerator::class)) {
                FacturaPdfGenerator::asegurarColumnasFacturacion($pdo);
            }

            $condiciones = ["1=1"];
            $params = [];

            if (!empty($_GET['medico_id'])) {
                $condiciones[] = "f.medico_id = :med";
                $params[':med'] = (int)$_GET['medico_id'];
            }
            if (!empty($_GET['paciente_id'])) {
                $condiciones[] = "f.paciente_id = :pac";
                $params[':pac'] = (int)$_GET['paciente_id'];
            }

            $filtroTipo = trim((string)($_GET['filtro_tipo'] ?? ''));
            if ($filtroTipo === 'dia' && !empty($_GET['fecha_dia'])) {
                $condiciones[] = "f.fecha = :fecha_dia";
                $params[':fecha_dia'] = trim((string)$_GET['fecha_dia']);
            } elseif ($filtroTipo === 'mes' && !empty($_GET['mes_sel'])) {
                $condiciones[] = "DATE_FORMAT(f.fecha, '%Y-%m') = :mes_sel";
                $params[':mes_sel'] = trim((string)$_GET['mes_sel']);
            } elseif ($filtroTipo === 'custom' && !empty($_GET['f_inicio']) && !empty($_GET['f_fin'])) {
                $condiciones[] = "f.fecha BETWEEN :f_inicio AND :f_fin";
                $params[':f_inicio'] = trim((string)$_GET['f_inicio']);
                $params[':f_fin']    = trim((string)$_GET['f_fin']);
            }

            $whereSql = implode(" AND ", $condiciones);

            $sql = "SELECT 
                        f.*,
                        CONCAT(p.nombre, ' ', p.apellido) AS paciente_nombre,
                        p.cedula AS paciente_cedula
                    FROM gm_finanzas f
                    LEFT JOIN gm_usuarios_extendidos p ON f.paciente_id = p.user_id
                    WHERE $whereSql
                    ORDER BY f.fecha DESC, f.id DESC";

            $stmt = $pdo->prepare($sql);
            $stmt->execute($params);
            $movimientos = $stmt->fetchAll(PDO::FETCH_ASSOC);

            $totalDebeUsd  = 0;
            $totalHaberUsd = 0;
            $totalDebeBs   = 0;
            $totalHaberBs  = 0;

            foreach ($movimientos as $m) {
                $totalDebeUsd  += (float)$m['debe'];
                $totalHaberUsd += (float)$m['haber'];
                $totalDebeBs   += (float)($m['debe_bs'] ?? 0);
                $totalHaberBs  += (float)($m['haber_bs'] ?? 0);
            }

            echo json_encode([
                "status" => "success",
                "resumen" => [
                    "total_cargos_usd"    => round($totalDebeUsd, 2),
                    "total_abonos_usd"    => round($totalHaberUsd, 2),
                    "saldo_pendiente_usd" => round($totalDebeUsd - $totalHaberUsd, 2),
                    "total_debe_bs"       => round($totalDebeBs, 2),
                    "total_haber_bs"      => round($totalHaberBs, 2),
                    "saldo_neto_bs"       => round($totalDebeBs - $totalHaberBs, 2)
                ],
                "total" => count($movimientos),
                "data"  => $movimientos
            ], JSON_UNESCAPED_UNICODE);

        } catch (Exception $e) {
            http_response_code(500);
            echo json_encode(["status" => "error", "mensaje" => $e->getMessage()], JSON_UNESCAPED_UNICODE);
        }
    }

    /**
     * POST /api/finanzas
     */
    public static function registrarMovimiento($input = null) {
        try {
            if ($input === null) {
                $input = json_decode(file_get_contents('php://input'), true) ?: [];
            }
            $medicoId   = (int)($input['medico_id'] ?? 0);
            $pacienteId = (int)($input['paciente_id'] ?? 0);
            $concepto   = trim($input['concepto'] ?? '');
            $debe       = (float)($input['debe'] ?? 0);
            $haber      = (float)($input['haber'] ?? 0);

            if ($medicoId <= 0 || $pacienteId <= 0 || empty($concepto) || ($debe <= 0 && $haber <= 0)) {
                http_response_code(400);
                echo json_encode(["status" => "error", "mensaje" => "Datos incompletos para el registro financiero."], JSON_UNESCAPED_UNICODE);
                return;
            }

            $pdo = Database::conectar();

            $tasaVal = $pdo->query("SELECT tasa_usd FROM gm_tasa_bcv ORDER BY fecha DESC LIMIT 1")->fetchColumn();
            $tasaBcv = !empty($input['tasa_bcv']) ? (float)$input['tasa_bcv'] : (($tasaVal && (float)$tasaVal > 0) ? (float)$tasaVal : 1.0000);

            $saldo   = round($debe - $haber, 2);
            $debeBs  = round($debe * $tasaBcv, 2);
            $haberBs = round($haber * $tasaBcv, 2);
            $saldoBs = round($saldo * $tasaBcv, 2);

            $sql = "INSERT INTO gm_finanzas 
                    (medico_id, paciente_id, historia_id, fecha, concepto, debe, haber, saldo, debe_bs, haber_bs, saldo_bs, tasa_bcv) 
                    VALUES 
                    (:med, :pac, :hid, CURDATE(), :concepto, :debe, :haber, :saldo, :debe_bs, :haber_bs, :saldo_bs, :tasa)";

            $pdo->prepare($sql)->execute([
                ':med'      => $medicoId,
                ':pac'      => $pacienteId,
                ':hid'      => !empty($input['historia_id']) ? (int)$input['historia_id'] : null,
                ':concepto' => $concepto,
                ':debe'     => $debe,
                ':haber'    => $haber,
                ':saldo'    => $saldo,
                ':debe_bs'  => $debeBs,
                ':haber_bs' => $haberBs,
                ':saldo_bs' => $saldoBs,
                ':tasa'     => $tasaBcv
            ]);

            http_response_code(201);
            echo json_encode([
                "status"  => "success",
                "mensaje" => "Movimiento financiero registrado correctamente.",
                "id"      => $pdo->lastInsertId()
            ], JSON_UNESCAPED_UNICODE);

        } catch (Exception $e) {
            http_response_code(500);
            echo json_encode(["status" => "error", "mensaje" => $e->getMessage()], JSON_UNESCAPED_UNICODE);
        }
    }

    /**
     * POST /api/finanzas/egreso
     * Registro rápido de salida de dinero / gastos operativos en USD o BS
     */
    public static function registrarEgreso($input = null) {
        try {
            if ($input === null) {
                $input = json_decode(file_get_contents('php://input'), true) ?: [];
            }

            $medicoId = (int)($input['medico_id'] ?? 0);
            $concepto = trim((string)($input['concepto'] ?? ''));
            $monto    = (float)($input['monto'] ?? 0);
            $moneda   = strtoupper(trim((string)($input['moneda'] ?? 'USD')));

            if ($medicoId <= 0 || $concepto === '' || $monto <= 0) {
                http_response_code(400);
                echo json_encode(["status" => "error", "mensaje" => "Médico, concepto y monto válido son obligatorios."], JSON_UNESCAPED_UNICODE);
                return;
            }

            $pdo = Database::conectar();
            $tasaVal = $pdo->query("SELECT tasa_usd FROM gm_tasa_bcv ORDER BY fecha DESC LIMIT 1")->fetchColumn();
            $tasa = ($tasaVal && (float)$tasaVal > 0) ? (float)$tasaVal : 1.0000;

            if ($moneda === 'BS' || $moneda === 'VES') {
                $haberBs  = round($monto, 2);
                $haberUsd = round($haberBs / $tasa, 2);
            } else {
                $haberUsd = round($monto, 2);
                $haberBs  = round($haberUsd * $tasa, 2);
            }

            $sql = "INSERT INTO gm_finanzas 
                    (medico_id, paciente_id, historia_id, fecha, concepto, debe, haber, saldo, debe_bs, haber_bs, saldo_bs, tasa_bcv)
                    VALUES (:med, 0, NULL, CURDATE(), :con, 0.00, :haber, :saldo, 0.00, :haber_bs, :saldo_bs, :tasa)";
            $pdo->prepare($sql)->execute([
                ':med'      => $medicoId,
                ':con'      => '[EGRESO] ' . mb_substr($concepto, 0, 200),
                ':haber'    => $haberUsd,
                ':saldo'    => -$haberUsd,
                ':haber_bs' => $haberBs,
                ':saldo_bs' => -$haberBs,
                ':tasa'     => $tasa,
            ]);

            http_response_code(201);
            echo json_encode([
                "status"  => "success",
                "mensaje" => "Egreso registrado exitosamente en Dólares y Bolívares.",
                "id"      => $pdo->lastInsertId()
            ], JSON_UNESCAPED_UNICODE);

        } catch (Exception $e) {
            http_response_code(500);
            echo json_encode(["status" => "error", "mensaje" => $e->getMessage()], JSON_UNESCAPED_UNICODE);
        }
    }

    /**
     * POST /api/finanzas/eliminar-egresos
     * Eliminación múltiple de egresos seleccionados
     */
    public static function eliminarEgresos($input = null) {
        try {
            if ($input === null) {
                $input = json_decode(file_get_contents('php://input'), true) ?: [];
            }

            $medicoId = (int)($input['medico_id'] ?? 0);
            $ids = is_array($input['ids'] ?? null) ? array_values(array_filter(array_map('intval', $input['ids']))) : [];

            if ($medicoId <= 0 || empty($ids)) {
                http_response_code(400);
                echo json_encode(["status" => "error", "mensaje" => "Debe seleccionar al menos un egreso."], JSON_UNESCAPED_UNICODE);
                return;
            }

            $pdo = Database::conectar();
            $placeholders = implode(',', array_fill(0, count($ids), '?'));
            $params = array_merge([$medicoId], $ids);

            $stmt = $pdo->prepare("DELETE FROM gm_finanzas WHERE medico_id = ? AND id IN ($placeholders) AND (haber > 0 OR concepto LIKE '%[EGRESO]%')");
            $stmt->execute($params);

            echo json_encode([
                "status"     => "success",
                "eliminados" => $stmt->rowCount(),
                "mensaje"    => "Se eliminaron " . $stmt->rowCount() . " egreso(s) seleccionado(s) correctamente."
            ], JSON_UNESCAPED_UNICODE);

        } catch (Exception $e) {
            http_response_code(500);
            echo json_encode(["status" => "error", "mensaje" => $e->getMessage()], JSON_UNESCAPED_UNICODE);
        }
    }

    /**
     * POST /api/finanzas/facturar
     * Emite la Factura Serie A digital, marca facturado = 1 y devuelve la URL del documento
     */
    public static function facturarMovimiento($input = null) {
        try {
            if ($input === null) {
                $input = json_decode(file_get_contents('php://input'), true) ?: [];
            }

            $finanzaId = (int)($input['finanza_id'] ?? $input['id'] ?? 0);
            if ($finanzaId <= 0) {
                http_response_code(400);
                echo json_encode(["status" => "error", "mensaje" => "ID de transacción inválido."], JSON_UNESCAPED_UNICODE);
                return;
            }

            $temaOverride = [
                'color_primario' => $input['color_primario'] ?? null,
            ];

            $gen = FacturaPdfGenerator::generarFacturaPdf($finanzaId, null, $temaOverride);
            if (empty($gen['url'])) {
                http_response_code(404);
                echo json_encode(["status" => "error", "mensaje" => "No se pudo generar la factura para este registro."], JSON_UNESCAPED_UNICODE);
                return;
            }

            echo json_encode([
                "status"          => "success",
                "mensaje"         => "Factura Serie A N° " . $gen['numero_factura'] . " generada correctamente.",
                "numero_factura"  => $gen['numero_factura'],
                "url_factura_pdf" => $gen['url']
            ], JSON_UNESCAPED_UNICODE);

        } catch (Exception $e) {
            http_response_code(500);
            echo json_encode(["status" => "error", "mensaje" => $e->getMessage()], JSON_UNESCAPED_UNICODE);
        }
    }

    /**
     * GET /api/tasa-bcv/sincronizar
     */
    public static function sincronizarTasaBcv() {
        try {
            $tasaUsd = 0.0;

            $ch = curl_init('https://www.bcv.org.ve/');
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
            curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);
            curl_setopt($ch, CURLOPT_TIMEOUT, 20);
            curl_setopt($ch, CURLOPT_HTTPHEADER, [
                'User-Agent: Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36'
            ]);
            $html = curl_exec($ch);
            curl_close($ch);

            if ($html && (
                preg_match('/id="dolar"[^>]*>.*?<strong>\s*([0-9.,]+)\s*<\/strong>/s', $html, $matches) ||
                preg_match('/field-content[^>]*>.*?<strong>\s*([0-9.,]+)\s*<\/strong>/s', $html, $matches)
            )) {
                $raw = str_replace('.', '', trim($matches[1]));
                $raw = str_replace(',', '.', $raw);
                $tasaUsd = (float)$raw;
            }

            if ($tasaUsd <= 0) {
                $chApi = curl_init('https://ve.dolarapi.com/v1/dolares/oficial');
                curl_setopt($chApi, CURLOPT_RETURNTRANSFER, true);
                curl_setopt($chApi, CURLOPT_TIMEOUT, 15);
                $jsonApi = curl_exec($chApi);
                curl_close($chApi);

                if ($jsonApi) {
                    $dataApi = json_decode($jsonApi, true);
                    if (!empty($dataApi['promedio'])) {
                        $tasaUsd = (float)$dataApi['promedio'];
                    }
                }
            }

            if ($tasaUsd <= 0) {
                http_response_code(502);
                echo json_encode(["status" => "error", "mensaje" => "No se pudo obtener la tasa desde BCV ni desde el respaldo."], JSON_UNESCAPED_UNICODE);
                return;
            }

            $pdo = Database::conectar();
            $ahora = date('Y-m-d H:i:s');

            $stmt = $pdo->prepare("INSERT INTO gm_tasa_bcv (fecha, tasa_usd) VALUES (:fecha, :tasa)");
            $stmt->execute([
                ':fecha' => $ahora,
                ':tasa'  => $tasaUsd
            ]);

            echo json_encode([
                "status"   => "success",
                "mensaje"  => "Tasa BCV sincronizada correctamente.",
                "fecha"    => $ahora,
                "tasa_usd" => $tasaUsd
            ], JSON_UNESCAPED_UNICODE);

        } catch (Exception $e) {
            http_response_code(500);
            echo json_encode(["status" => "error", "mensaje" => $e->getMessage()], JSON_UNESCAPED_UNICODE);
        }
    }

    /**
     * GET /api/presupuestos?medico_id=X&estatus=todos|vigente|vencido|aprobado&fecha_desde=Y&fecha_hasta=Z
     */
    public static function listarPresupuestos() {
        try {
            $pdo = Database::conectar();
            PresupuestoPdfGenerator::asegurarTablaPresupuestos($pdo);

            $condiciones = ["1=1"];
            $params = [];

            if (!empty($_GET['medico_id'])) {
                $condiciones[] = "medico_id = :med";
                $params[':med'] = (int)$_GET['medico_id'];
            }
            if (!empty($_GET['fecha_desde'])) {
                $condiciones[] = "fecha_emision >= :f_desde";
                $params[':f_desde'] = trim((string)$_GET['fecha_desde']);
            }
            if (!empty($_GET['fecha_hasta'])) {
                $condiciones[] = "fecha_emision <= :f_hasta";
                $params[':f_hasta'] = trim((string)$_GET['fecha_hasta']);
            }

            $estatus = strtolower(trim((string)($_GET['estatus'] ?? '')));
            if ($estatus === 'aprobado') {
                $condiciones[] = "estatus = 'aprobado'";
            } elseif ($estatus === 'vigente') {
                $condiciones[] = "estatus <> 'aprobado' AND CURDATE() <= fecha_vencimiento";
            } elseif ($estatus === 'vencido') {
                $condiciones[] = "estatus <> 'aprobado' AND CURDATE() > fecha_vencimiento";
            }

            $whereSql = implode(" AND ", $condiciones);
            $stmt = $pdo->prepare("
                SELECT *,
                       CASE
                           WHEN estatus = 'aprobado' THEN 'aprobado'
                           WHEN CURDATE() <= fecha_vencimiento THEN 'vigente'
                           ELSE 'vencido'
                       END AS estatus_calculado
                FROM gm_presupuestos
                WHERE $whereSql
                ORDER BY id DESC
            ");
            $stmt->execute($params);
            $lista = $stmt->fetchAll(PDO::FETCH_ASSOC);

            foreach ($lista as &$item) {
                $item['items'] = !empty($item['items_json']) ? (json_decode($item['items_json'], true) ?: []) : [];
            }

            echo json_encode([
                "status" => "success",
                "total"  => count($lista),
                "data"   => $lista
            ], JSON_UNESCAPED_UNICODE);

        } catch (Exception $e) {
            http_response_code(500);
            echo json_encode(["status" => "error", "mensaje" => $e->getMessage()], JSON_UNESCAPED_UNICODE);
        }
    }

    /**
     * POST /api/presupuestos/{id}/aprobar
     * Carga el monto del presupuesto al DEBE y SALDO de la cuenta del paciente en gm_finanzas y cambia su estatus a 'aprobado'
     */
    public static function aprobarYCargarPresupuesto(int $presupuestoId, $input = null) {
        try {
            if ($input === null) {
                $input = json_decode(file_get_contents('php://input'), true) ?: [];
            }

            $pdo = Database::conectar();
            PresupuestoPdfGenerator::asegurarTablaPresupuestos($pdo);
            if (class_exists(FacturaPdfGenerator::class)) {
                FacturaPdfGenerator::asegurarColumnasFacturacion($pdo);
            }

            $stmt = $pdo->prepare("SELECT * FROM gm_presupuestos WHERE id = :id LIMIT 1");
            $stmt->execute([':id' => $presupuestoId]);
            $pres = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$pres) {
                http_response_code(404);
                echo json_encode(["status" => "error", "mensaje" => "Presupuesto no encontrado."], JSON_UNESCAPED_UNICODE);
                return;
            }

            if (($pres['estatus'] ?? '') === 'aprobado') {
                echo json_encode(["status" => "success", "mensaje" => "Este presupuesto ya fue aprobado y cargado anteriormente."], JSON_UNESCAPED_UNICODE);
                return;
            }

            $pacienteId = (int)($input['paciente_id'] ?? $pres['paciente_id'] ?? 0);
            if ($pacienteId <= 0) {
                http_response_code(400);
                echo json_encode(["status" => "error", "mensaje" => "El presupuesto debe estar asociado a un paciente registrado para cargarlo a cuenta."], JSON_UNESCAPED_UNICODE);
                return;
            }

            $tasaVal = $pdo->query("SELECT tasa_usd FROM gm_tasa_bcv ORDER BY fecha DESC LIMIT 1")->fetchColumn();
            $tasaBcv = !empty($input['tasa_bcv']) ? (float)$input['tasa_bcv'] : (($tasaVal && (float)$tasaVal > 0) ? (float)$tasaVal : (float)($pres['tasa_bcv'] ?? 1.0));

            $items = json_decode((string)($pres['items_json'] ?? '[]'), true) ?: [];
            $partesConcepto = [];
            foreach ($items as $it) {
                $cant = max(1, (int)($it['cantidad'] ?? 1));
                $nom  = trim((string)($it['nombre'] ?? 'Servicio'));
                $partesConcepto[] = "{$cant}x {$nom}";
            }
            $numPres = !empty($pres['numero_presupuesto']) ? $pres['numero_presupuesto'] : str_pad((string)$presupuestoId, 6, '0', STR_PAD_LEFT);
            $conceptoFinal = "Presupuesto N° {$numPres}: " . implode(', ', $partesConcepto);

            $debeUsd = round((float)($pres['total_usd'] ?? 0), 2);
            $debeBs  = round($debeUsd * $tasaBcv, 2);

            // Insertar cargo en gm_finanzas reflejando debe, saldo, debe_bs y saldo_bs
            $insFin = $pdo->prepare("
                INSERT INTO gm_finanzas
                (medico_id, paciente_id, fecha, concepto, debe, haber, saldo, debe_bs, haber_bs, saldo_bs, tasa_bcv, facturado)
                VALUES
                (:med, :pac, CURDATE(), :conc, :debe, 0.00, :saldo, :debe_bs, 0.00, :saldo_bs, :tasa, 0)
            ");
            $insFin->execute([
                ':med'      => (int)$pres['medico_id'],
                ':pac'      => $pacienteId,
                ':conc'     => $conceptoFinal,
                ':debe'     => $debeUsd,
                ':saldo'    => $debeUsd,
                ':debe_bs'  => $debeBs,
                ':saldo_bs' => $debeBs,
                ':tasa'     => $tasaBcv,
            ]);
            $finanzaId = (int)$pdo->lastInsertId();

            // Marcar presupuesto como APROBADO
            $updPres = $pdo->prepare("
                UPDATE gm_presupuestos
                SET estatus = 'aprobado', paciente_id = :pac, finanza_id = :fid
                WHERE id = :id
            ");
            $updPres->execute([
                ':pac' => $pacienteId,
                ':fid' => $finanzaId,
                ':id'  => $presupuestoId,
            ]);

            echo json_encode([
                "status"     => "success",
                "mensaje"    => "Presupuesto N° {$numPres} aprobado y cargado a la cuenta del paciente.",
                "finanza_id" => $finanzaId
            ], JSON_UNESCAPED_UNICODE);

        } catch (Exception $e) {
            http_response_code(500);
            echo json_encode(["status" => "error", "mensaje" => $e->getMessage()], JSON_UNESCAPED_UNICODE);
        }
    }

    /**
     * POST /api/presupuestos
     * Guarda el presupuesto en BD con vencimiento automático de +3 días y genera el PDF/HTML oficial
     */
    public static function crearPresupuesto($input = null) {
        try {
            if ($input === null) {
                $input = json_decode(file_get_contents('php://input'), true) ?: [];
            }

            $medicoId   = (int)($input['medico_id'] ?? 0);
            $pacienteId = (int)($input['paciente_id'] ?? 0);
            $pacNombre  = trim((string)($input['paciente_nombre'] ?? ''));
            $pacCedula  = trim((string)($input['paciente_cedula'] ?? ''));
            $pacTel     = trim((string)($input['paciente_telefono'] ?? ''));
            $pacDir     = trim((string)($input['paciente_direccion'] ?? ''));
            $items      = is_array($input['items'] ?? null) ? $input['items'] : [];
            $obs        = trim((string)($input['observaciones'] ?? ''));

            if ($medicoId <= 0 || empty($items)) {
                http_response_code(400);
                echo json_encode(["status" => "error", "mensaje" => "Médico y al menos un servicio son obligatorios."], JSON_UNESCAPED_UNICODE);
                return;
            }

            $pdo = Database::conectar();
            PresupuestoPdfGenerator::asegurarTablaPresupuestos($pdo);

            $tasaVal = $pdo->query("SELECT tasa_usd FROM gm_tasa_bcv ORDER BY fecha DESC LIMIT 1")->fetchColumn();
            $tasaBcv = !empty($input['tasa_bcv']) ? (float)$input['tasa_bcv'] : (($tasaVal && (float)$tasaVal > 0) ? (float)$tasaVal : 1.0000);

            $totalUsd = 0.0;
            foreach ($items as $it) {
                $cant = max(1, (int)($it['cantidad'] ?? 1));
                $prec = (float)($it['precio_usd'] ?? $it['precio'] ?? 0);
                $totalUsd += ($cant * $prec);
            }
            $totalUsd = round($totalUsd, 2);
            $totalBs  = round($totalUsd * $tasaBcv, 2);

            $fechaEmision     = date('Y-m-d');
            $fechaVencimiento = date('Y-m-d', strtotime('+3 days'));

            $sql = "INSERT INTO gm_presupuestos
                    (medico_id, paciente_id, paciente_nombre, paciente_cedula, paciente_telefono, paciente_direccion,
                     fecha_emision, fecha_vencimiento, items_json, total_usd, total_bs, tasa_bcv, observaciones)
                    VALUES
                    (:med, :pac, :pnom, :pced, :ptel, :pdir, :femi, :fvenc, :items, :tusd, :tbs, :tasa, :obs)";
            $pdo->prepare($sql)->execute([
                ':med'   => $medicoId,
                ':pac'   => $pacienteId,
                ':pnom'  => $pacNombre,
                ':pced'  => $pacCedula,
                ':ptel'  => $pacTel,
                ':pdir'  => $pacDir,
                ':femi'  => $fechaEmision,
                ':fvenc' => $fechaVencimiento,
                ':items' => json_encode($items, JSON_UNESCAPED_UNICODE),
                ':tusd'  => $totalUsd,
                ':tbs'   => $totalBs,
                ':tasa'  => $tasaBcv,
                ':obs'   => $obs,
            ]);

            $presId = (int)$pdo->lastInsertId();
            $temaOverride = ['color_primario' => $input['color_primario'] ?? null];
            $gen = PresupuestoPdfGenerator::generarPresupuestoPdf($presId, $pdo, $temaOverride);

            http_response_code(201);
            echo json_encode([
                "status"             => "success",
                "mensaje"            => "Presupuesto N° " . $gen['numero_presupuesto'] . " generado (válido hasta " . date('d/m/Y', strtotime($fechaVencimiento)) . ").",
                "id"                 => $presId,
                "numero_presupuesto" => $gen['numero_presupuesto'],
                "fecha_emision"      => $fechaEmision,
                "fecha_vencimiento"  => $fechaVencimiento,
                "url_pdf"            => $gen['url']
            ], JSON_UNESCAPED_UNICODE);

        } catch (Exception $e) {
            http_response_code(500);
            echo json_encode(["status" => "error", "mensaje" => $e->getMessage()], JSON_UNESCAPED_UNICODE);
        }
    }
}