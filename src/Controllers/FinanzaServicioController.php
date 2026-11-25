<?php
namespace App\Controllers;

use App\Config\Database;
use App\Documentos\FacturaPdfGenerator;
use PDO;
use Exception;

if (file_exists(__DIR__ . '/CuentasPorCobrarController.php')) {
    require_once __DIR__ . '/CuentasPorCobrarController.php';
}
if (file_exists(__DIR__ . '/PresupuestoController.php')) {
    require_once __DIR__ . '/PresupuestoController.php';
}

class FinanzaServicioController {

    private static function asegurarColumnaEstado(PDO $pdo) {
        try {
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
        } catch (Exception $e) {
            // Ignorar si ya existe o no tiene privilegios DDL
        }
    }

    public static function asegurarEsquemaFinanciamientoYCobros(PDO $pdo): void {
        try {
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
                    `fecha` date DEFAULT NULL,
                    `fecha_credito` date DEFAULT NULL,
                    `descripcion` varchar(500) NOT NULL DEFAULT '',
                    `fecha_vencimiento` date DEFAULT NULL,
                    `cantidad_cuotas` int(3) NOT NULL DEFAULT 1,
                    `cuotas_semanales` int(3) NOT NULL DEFAULT 1,
                    `cuotas_pagadas` int(3) NOT NULL DEFAULT 0,
                    `monto_cuota_usd` decimal(12,2) NOT NULL DEFAULT 0.00,
                    `frecuencia_cuotas` varchar(20) NOT NULL DEFAULT 'semanal',
                    `debe` decimal(12,2) NOT NULL DEFAULT 0.00,
                    `debe_usd` decimal(12,2) NOT NULL DEFAULT 0.00,
                    `haber` decimal(12,2) NOT NULL DEFAULT 0.00,
                    `haber_usd` decimal(12,2) NOT NULL DEFAULT 0.00,
                    `saldo` decimal(12,2) NOT NULL DEFAULT 0.00,
                    `saldo_usd` decimal(12,2) NOT NULL DEFAULT 0.00,
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

            $colsExtraCxc = [
                'servicio_id'       => "ALTER TABLE `gm_cuentas_por_cobrar` ADD COLUMN `servicio_id` mediumint(9) DEFAULT NULL",
                'fecha'             => "ALTER TABLE `gm_cuentas_por_cobrar` ADD COLUMN `fecha` date DEFAULT NULL",
                'fecha_credito'     => "ALTER TABLE `gm_cuentas_por_cobrar` ADD COLUMN `fecha_credito` date DEFAULT NULL",
                'cantidad_cuotas'   => "ALTER TABLE `gm_cuentas_por_cobrar` ADD COLUMN `cantidad_cuotas` int(3) NOT NULL DEFAULT 1",
                'cuotas_semanales'  => "ALTER TABLE `gm_cuentas_por_cobrar` ADD COLUMN `cuotas_semanales` int(3) NOT NULL DEFAULT 1",
                'cuotas_pagadas'    => "ALTER TABLE `gm_cuentas_por_cobrar` ADD COLUMN `cuotas_pagadas` int(3) NOT NULL DEFAULT 0",
                'monto_cuota_usd'   => "ALTER TABLE `gm_cuentas_por_cobrar` ADD COLUMN `monto_cuota_usd` decimal(12,2) NOT NULL DEFAULT 0.00",
                'frecuencia_cuotas' => "ALTER TABLE `gm_cuentas_por_cobrar` ADD COLUMN `frecuencia_cuotas` varchar(20) NOT NULL DEFAULT 'semanal'",
                'debe'              => "ALTER TABLE `gm_cuentas_por_cobrar` ADD COLUMN `debe` decimal(12,2) NOT NULL DEFAULT 0.00",
                'debe_usd'          => "ALTER TABLE `gm_cuentas_por_cobrar` ADD COLUMN `debe_usd` decimal(12,2) NOT NULL DEFAULT 0.00",
                'haber'             => "ALTER TABLE `gm_cuentas_por_cobrar` ADD COLUMN `haber` decimal(12,2) NOT NULL DEFAULT 0.00",
                'haber_usd'         => "ALTER TABLE `gm_cuentas_por_cobrar` ADD COLUMN `haber_usd` decimal(12,2) NOT NULL DEFAULT 0.00",
                'saldo'             => "ALTER TABLE `gm_cuentas_por_cobrar` ADD COLUMN `saldo` decimal(12,2) NOT NULL DEFAULT 0.00",
                'saldo_usd'         => "ALTER TABLE `gm_cuentas_por_cobrar` ADD COLUMN `saldo_usd` decimal(12,2) NOT NULL DEFAULT 0.00",
                'debe_bs'           => "ALTER TABLE `gm_cuentas_por_cobrar` ADD COLUMN `debe_bs` decimal(12,2) NOT NULL DEFAULT 0.00",
                'haber_bs'          => "ALTER TABLE `gm_cuentas_por_cobrar` ADD COLUMN `haber_bs` decimal(12,2) NOT NULL DEFAULT 0.00",
                'saldo_bs'          => "ALTER TABLE `gm_cuentas_por_cobrar` ADD COLUMN `saldo_bs` decimal(12,2) NOT NULL DEFAULT 0.00",
                'tasa_bcv'          => "ALTER TABLE `gm_cuentas_por_cobrar` ADD COLUMN `tasa_bcv` decimal(10,4) NOT NULL DEFAULT 1.0000",
            ];

            foreach ($colsExtraCxc as $col => $alterSql) {
                $chk = $pdo->prepare("
                    SELECT COUNT(*)
                    FROM INFORMATION_SCHEMA.COLUMNS
                    WHERE TABLE_SCHEMA = DATABASE()
                      AND TABLE_NAME = 'gm_cuentas_por_cobrar'
                      AND COLUMN_NAME = :c
                ");
                $chk->execute([':c' => $col]);
                if ((int)$chk->fetchColumn() === 0) {
                    $pdo->exec($alterSql);
                }
            }

            $pdo->exec("
                CREATE TABLE IF NOT EXISTS `gm_pagos_cuotas` (
                    `id` mediumint(9) NOT NULL AUTO_INCREMENT,
                    `cuenta_id` mediumint(9) NOT NULL,
                    `medico_id` bigint(20) NOT NULL,
                    `paciente_id` bigint(20) NOT NULL,
                    `cuota_numero` int(3) NOT NULL DEFAULT 1,
                    `total_cuotas` int(3) NOT NULL DEFAULT 1,
                    `fecha_pago` date NOT NULL,
                    `metodo_pago` varchar(120) NOT NULL DEFAULT '',
                    `banco_destino` varchar(150) NOT NULL DEFAULT '',
                    `referencia` varchar(100) NOT NULL DEFAULT '',
                    `moneda_pagada` varchar(10) NOT NULL DEFAULT 'USD',
                    `monto_usd` decimal(12,2) NOT NULL DEFAULT 0.00,
                    `monto_bs` decimal(12,2) NOT NULL DEFAULT 0.00,
                    `tasa_bcv` decimal(10,4) NOT NULL DEFAULT 1.0000,
                    `comprobante_url` varchar(500) NOT NULL DEFAULT '',
                    `observaciones_paciente` varchar(500) NOT NULL DEFAULT '',
                    `motivo_rechazo` varchar(500) NOT NULL DEFAULT '',
                    `estatus` varchar(25) NOT NULL DEFAULT 'por_verificar',
                    `finanza_id` mediumint(9) DEFAULT NULL,
                    `fecha_validacion` datetime DEFAULT NULL,
                    `fecha_creacion` datetime DEFAULT CURRENT_TIMESTAMP,
                    PRIMARY KEY (`id`),
                    INDEX `idx_pc_cuenta` (`cuenta_id`),
                    INDEX `idx_pc_medico_estatus` (`medico_id`, `estatus`),
                    INDEX `idx_pc_paciente` (`paciente_id`)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
            ");
        } catch (Exception $e) {
            // Las tablas y columnas ya existen; evitar bloquear las consultas por permisos DDL
        }
    }

    // --- DELEGACIONES A CuentasPorCobrarController (mantiene compatibilidad con rutas existentes) ---
    public static function listarCuentasPorCobrar() {
        if (!class_exists(CuentasPorCobrarController::class)) {
            http_response_code(500);
            echo json_encode(["status" => "error", "mensaje" => "Falta el archivo CuentasPorCobrarController.php en el servidor."], JSON_UNESCAPED_UNICODE);
            return;
        }
        CuentasPorCobrarController::listarCuentasPorCobrar();
    }

    public static function reportarPagoCuotaPaciente($input = null) {
        if (!class_exists(CuentasPorCobrarController::class)) {
            http_response_code(500);
            echo json_encode(["status" => "error", "mensaje" => "Falta el archivo CuentasPorCobrarController.php en el servidor."], JSON_UNESCAPED_UNICODE);
            return;
        }
        CuentasPorCobrarController::reportarPagoCuotaPaciente($input);
    }

    public static function listarPagosReportados() {
        if (!class_exists(CuentasPorCobrarController::class)) {
            http_response_code(500);
            echo json_encode(["status" => "error", "mensaje" => "Falta el archivo CuentasPorCobrarController.php en el servidor."], JSON_UNESCAPED_UNICODE);
            return;
        }
        CuentasPorCobrarController::listarPagosReportados();
    }

    public static function validarPagoCuota($input = null) {
        if (!class_exists(CuentasPorCobrarController::class)) {
            http_response_code(500);
            echo json_encode(["status" => "error", "mensaje" => "Falta el archivo CuentasPorCobrarController.php en el servidor."], JSON_UNESCAPED_UNICODE);
            return;
        }
        CuentasPorCobrarController::validarPagoCuota($input);
    }

    public static function registrarAbonoCuentaPorCobrar($input = null) {
        if (!class_exists(CuentasPorCobrarController::class)) {
            http_response_code(500);
            echo json_encode(["status" => "error", "mensaje" => "Falta el archivo CuentasPorCobrarController.php en el servidor."], JSON_UNESCAPED_UNICODE);
            return;
        }
        CuentasPorCobrarController::registrarAbonoCuentaPorCobrar($input);
    }

    // --- DELEGACIONES A PresupuestoController ---
    public static function listarPresupuestos() {
        if (!class_exists(PresupuestoController::class)) {
            http_response_code(500);
            echo json_encode(["status" => "error", "mensaje" => "Falta el archivo PresupuestoController.php en el servidor."], JSON_UNESCAPED_UNICODE);
            return;
        }
        PresupuestoController::listarPresupuestos();
    }

    public static function aprobarYCargarPresupuesto(int $presupuestoId, $input = null) {
        if (!class_exists(PresupuestoController::class)) {
            http_response_code(500);
            echo json_encode(["status" => "error", "mensaje" => "Falta el archivo PresupuestoController.php en el servidor."], JSON_UNESCAPED_UNICODE);
            return;
        }
        PresupuestoController::aprobarYCargarPresupuesto($presupuestoId, $input);
    }

    public static function crearPresupuesto($input = null) {
        if (!class_exists(PresupuestoController::class)) {
            http_response_code(500);
            echo json_encode(["status" => "error", "mensaje" => "Falta el archivo PresupuestoController.php en el servidor."], JSON_UNESCAPED_UNICODE);
            return;
        }
        PresupuestoController::crearPresupuesto($input);
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
                $condiciones[] = "DATE(f.fecha) = :fecha_dia";
                $params[':fecha_dia'] = trim((string)$_GET['fecha_dia']);
            } elseif ($filtroTipo === 'mes' && !empty($_GET['mes_sel'])) {
                $condiciones[] = "DATE_FORMAT(f.fecha, '%Y-%m') = :mes_sel";
                $params[':mes_sel'] = trim((string)$_GET['mes_sel']);
            } elseif ($filtroTipo === 'custom' && !empty($_GET['f_inicio']) && !empty($_GET['f_fin'])) {
                $condiciones[] = "DATE(f.fecha) BETWEEN :f_inicio AND :f_fin";
                $params[':f_inicio'] = trim((string)$_GET['f_inicio']);
                $params[':f_fin']    = trim((string)$_GET['f_fin']);
            }

            $whereSql = implode(" AND ", $condiciones);

            $sql = "SELECT 
                        f.*,
                        CONCAT(p.nombre, ' ', p.apellido) AS paciente_nombre,
                        p.cedula AS paciente_cedula,
                        CONCAT('Dr(a). ', m.nombre, ' ', m.apellido) AS medico_nombre
                    FROM gm_finanzas f
                    LEFT JOIN gm_usuarios_extendidos p ON f.paciente_id = p.user_id
                    LEFT JOIN gm_usuarios_extendidos m ON f.medico_id = m.user_id
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
}