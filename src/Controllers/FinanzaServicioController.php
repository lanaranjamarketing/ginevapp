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

        // Tabla de reportes de pagos por cuota semanal (Paciente -> Revisión -> Médico)
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
    }

    private static function obtenerBaseUrlPublica(): string {
        $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
        $host   = $_SERVER['HTTP_HOST'] ?? 'api.ginevapp.com';
        return $scheme . '://' . $host;
    }

    private static function obtenerMetodosCobroMedico(PDO $pdo, int $medicoId): array {
        if ($medicoId <= 0) {
            return [];
        }
        try {
            $stmt = $pdo->prepare("SELECT metodos_cobro FROM gm_usuarios_extendidos WHERE user_id = :med LIMIT 1");
            $stmt->execute([':med' => $medicoId]);
            $raw = $stmt->fetchColumn();
            if (!empty($raw)) {
                $decoded = json_decode((string)$raw, true);
                if (is_array($decoded)) {
                    return $decoded;
                }
            }
        } catch (Exception $e) {
            // Si la columna aún no existe en algún entorno antiguo, devolvemos vacío sin romper
        }
        return [];
    }

    private static function construirCronogramaSemanal(array $cuenta, array $reportesCuenta): array {
        $totalCuotas = max(1, (int)($cuenta['cantidad_cuotas'] ?? $cuenta['cuotas_semanales'] ?? 1));
        $debeUsd     = max((float)($cuenta['debe'] ?? 0), (float)($cuenta['debe_usd'] ?? 0));
        $haberUsd    = max((float)($cuenta['haber'] ?? 0), (float)($cuenta['haber_usd'] ?? 0));
        $tasaBcv     = (float)($cuenta['tasa_bcv'] ?? 1.0) > 0 ? (float)$cuenta['tasa_bcv'] : 1.0;

        $montoCuotaBase = (float)($cuenta['monto_cuota_usd'] ?? 0) > 0
            ? round((float)$cuenta['monto_cuota_usd'], 2)
            : round($debeUsd / $totalCuotas, 2);

        $fechaBaseStr = !empty($cuenta['fecha']) ? $cuenta['fecha'] : (!empty($cuenta['fecha_credito']) ? $cuenta['fecha_credito'] : date('Y-m-d'));
        $tsBase = strtotime($fechaBaseStr) ?: time();

        $reportesPorCuota = [];
        foreach ($reportesCuenta as $rep) {
            $num = (int)($rep['cuota_numero'] ?? 0);
            if ($num > 0) {
                $reportesPorCuota[$num][] = $rep;
            }
        }

        // Cuotas ya cubiertas según el Haber acumulado en la cuenta por cobrar
        $cuotasCubiertasPorHaber = ($montoCuotaBase > 0)
            ? (int)floor(($haberUsd + 0.01) / $montoCuotaBase)
            : 0;
        if (($debeUsd - $haberUsd) <= 0.01) {
            $cuotasCubiertasPorHaber = $totalCuotas;
        }

        $cronograma = [];
        $proximaCuotaPendiente = null;

        for ($i = 1; $i <= $totalCuotas; $i++) {
            $montoEstaCuota = ($i === $totalCuotas)
                ? round(max(0, $debeUsd - ($montoCuotaBase * ($totalCuotas - 1))), 2)
                : $montoCuotaBase;

            $diasOffset = ($i - 1) * 7;
            $fechaVencCuota = date('Y-m-d', strtotime("+{$diasOffset} days", $tsBase));

            $repsEsta = $reportesPorCuota[$i] ?? [];
            $ultimoRep = !empty($repsEsta) ? $repsEsta[0] : null;

            $estatusCuota = 'pendiente';
            if ($i <= $cuotasCubiertasPorHaber) {
                $estatusCuota = 'pagada';
            } elseif ($ultimoRep && ($ultimoRep['estatus'] ?? '') === 'aprobado') {
                $estatusCuota = 'pagada';
            } elseif ($ultimoRep && ($ultimoRep['estatus'] ?? '') === 'por_verificar') {
                $estatusCuota = 'por_verificar';
            } elseif ($ultimoRep && ($ultimoRep['estatus'] ?? '') === 'rechazado') {
                $estatusCuota = 'rechazada';
            }

            if ($estatusCuota !== 'pagada' && $estatusCuota !== 'por_verificar' && $proximaCuotaPendiente === null) {
                $proximaCuotaPendiente = [
                    'cuota_numero'      => $i,
                    'total_cuotas'      => $totalCuotas,
                    'fecha_vencimiento' => $fechaVencCuota,
                    'monto_usd'         => $montoEstaCuota,
                    'monto_bs'          => round($montoEstaCuota * $tasaBcv, 2),
                    'estatus'           => $estatusCuota,
                ];
            }

            $cronograma[] = [
                'cuota_numero'      => $i,
                'total_cuotas'      => $totalCuotas,
                'semana_label'      => "Cuota {$i} de {$totalCuotas}",
                'fecha_vencimiento' => $fechaVencCuota,
                'monto_usd'         => $montoEstaCuota,
                'monto_bs'          => round($montoEstaCuota * $tasaBcv, 2),
                'estatus'           => $estatusCuota,
                'ultimo_reporte'    => $ultimoRep,
            ];
        }

        return [
            'cronograma_cuotas'       => $cronograma,
            'cuotas_pagadas_calc'     => min($totalCuotas, $cuotasCubiertasPorHaber),
            'proxima_cuota_pendiente' => $proximaCuotaPendiente,
        ];
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
     * GET /api/finanzas/cuentas-por-cobrar?medico_id=X&paciente_id=Y
     * Devuelve cada cuenta por cobrar enriquecida con:
     * - cronograma_cuotas (Semana 1..N con estatus: pagada / por_verificar / pendiente / rechazada)
     * - proxima_cuota_pendiente
     * - reportes_pagos (historial de cuotas reportadas)
     * - metodos_cobro_medico (Pago Móvil / Transferencias del especialista)
     */
    public static function listarCuentasPorCobrar() {
        try {
            $pdo = Database::conectar();
            self::asegurarEsquemaFinanciamientoYCobros($pdo);

            $condiciones = ["1=1"];
            $params = [];

            if (!empty($_GET['medico_id'])) {
                $condiciones[] = "c.medico_id = :med";
                $params[':med'] = (int)$_GET['medico_id'];
            }
            if (!empty($_GET['paciente_id'])) {
                $condiciones[] = "c.paciente_id = :pac";
                $params[':pac'] = (int)$_GET['paciente_id'];
            }

            $whereSql = implode(" AND ", $condiciones);

            $sql = "SELECT
                        c.*,
                        COALESCE(c.fecha, c.fecha_credito) AS fecha,
                        COALESCE(NULLIF(c.cantidad_cuotas, 0), NULLIF(c.cuotas_semanales, 0), 1) AS cantidad_cuotas,
                        COALESCE(NULLIF(c.cuotas_semanales, 0), NULLIF(c.cantidad_cuotas, 0), 1) AS cuotas_semanales,
                        COALESCE(NULLIF(c.debe, 0), c.debe_usd, 0) AS debe,
                        COALESCE(NULLIF(c.debe, 0), c.debe_usd, 0) AS monto_total_usd,
                        COALESCE(NULLIF(c.haber, 0), c.haber_usd, 0) AS haber,
                        COALESCE(NULLIF(c.haber, 0), c.haber_usd, 0) AS total_abonos_usd,
                        COALESCE(NULLIF(c.saldo, 0), c.saldo_usd, 0) AS saldo,
                        COALESCE(NULLIF(c.saldo, 0), c.saldo_usd, 0) AS saldo_pendiente_usd,
                        CONCAT(p.nombre, ' ', p.apellido) AS cliente,
                        p.cedula AS paciente_cedula,
                        CONCAT('Dr(a). ', m.nombre, ' ', m.apellido) AS medico_nombre
                    FROM gm_cuentas_por_cobrar c
                    LEFT JOIN gm_usuarios_extendidos p ON c.paciente_id = p.user_id
                    LEFT JOIN gm_usuarios_extendidos m ON c.medico_id = m.user_id
                    WHERE $whereSql
                    ORDER BY COALESCE(c.fecha, c.fecha_credito) DESC, c.id DESC";

            $stmt = $pdo->prepare($sql);
            $stmt->execute($params);
            $cuentas = $stmt->fetchAll(PDO::FETCH_ASSOC);

            $tasaVal = $pdo->query("SELECT tasa_usd FROM gm_tasa_bcv ORDER BY fecha DESC LIMIT 1")->fetchColumn();
            $tasaActual = ($tasaVal && (float)$tasaVal > 0) ? (float)$tasaVal : 1.0000;

            $metodosCache = [];
            $cuentasIds = array_map(fn($row) => (int)$row['id'], $cuentas);
            $reportesPorCuenta = [];

            if (!empty($cuentasIds)) {
                $inSql = implode(',', array_fill(0, count($cuentasIds), '?'));
                $stmtRep = $pdo->prepare("
                    SELECT *
                    FROM gm_pagos_cuotas
                    WHERE cuenta_id IN ($inSql)
                    ORDER BY cuota_numero ASC, id DESC
                ");
                $stmtRep->execute($cuentasIds);
                foreach ($stmtRep->fetchAll(PDO::FETCH_ASSOC) as $rep) {
                    $cid = (int)$rep['cuenta_id'];
                    $reportesPorCuenta[$cid][] = $rep;
                }
            }

            foreach ($cuentas as &$c) {
                $cid = (int)$c['id'];
                $medId = (int)$c['medico_id'];
                if (!isset($metodosCache[$medId])) {
                    $metodosCache[$medId] = self::obtenerMetodosCobroMedico($pdo, $medId);
                }
                if ((float)($c['tasa_bcv'] ?? 0) <= 0 || (float)$c['tasa_bcv'] == 1.0) {
                    $c['tasa_bcv'] = $tasaActual;
                }

                $reps = $reportesPorCuenta[$cid] ?? [];
                $infoCrono = self::construirCronogramaSemanal($c, $reps);

                $c['cuotas_pagadas']          = max((int)($c['cuotas_pagadas'] ?? 0), $infoCrono['cuotas_pagadas_calc']);
                $c['cronograma_cuotas']       = $infoCrono['cronograma_cuotas'];
                $c['proxima_cuota_pendiente'] = $infoCrono['proxima_cuota_pendiente'];
                $c['reportes_pagos']          = $reps;
                $c['pagos_por_verificar']     = count(array_filter($reps, fn($r) => ($r['estatus'] ?? '') === 'por_verificar'));
                $c['metodos_cobro_medico']    = $metodosCache[$medId];
            }

            echo json_encode([
                "status"   => "success",
                "tasa_bcv" => $tasaActual,
                "total"    => count($cuentas),
                "data"     => $cuentas
            ], JSON_UNESCAPED_UNICODE);

        } catch (Exception $e) {
            http_response_code(500);
            echo json_encode(["status" => "error", "mensaje" => $e->getMessage()], JSON_UNESCAPED_UNICODE);
        }
    }

    /**
     * POST /api/finanzas/cuentas-por-cobrar/reportar-pago
     * Permite al paciente reportar el pago de una cuota semanal (con comprobante opcional)
     * dejando el registro en estado 'por_verificar' hasta que el médico o asistente lo apruebe.
     */
    public static function reportarPagoCuotaPaciente($input = null) {
        try {
            if ($input === null) {
                $raw = file_get_contents('php://input');
                $decoded = json_decode($raw, true);
                $input = is_array($decoded) ? $decoded : $_POST;
            }

            $cuentaId   = (int)($input['cuenta_id'] ?? 0);
            $pacienteId = (int)($input['paciente_id'] ?? 0);
            $cuotaNum   = max(1, (int)($input['cuota_numero'] ?? 1));
            $metodoPago = trim((string)($input['metodo_pago'] ?? 'Pago Móvil'));
            $bancoDest  = trim((string)($input['banco_destino'] ?? ''));
            $referencia = trim((string)($input['referencia'] ?? ''));
            $moneda     = strtoupper(trim((string)($input['moneda_pagada'] ?? 'USD')));
            $obsPac     = trim((string)($input['observaciones'] ?? ''));
            $fechaPago  = !empty($input['fecha_pago']) ? substr(trim((string)$input['fecha_pago']), 0, 10) : date('Y-m-d');

            if ($cuentaId <= 0 || $referencia === '') {
                http_response_code(400);
                echo json_encode(["status" => "error", "mensaje" => "La cuenta por pagar y el número de referencia son obligatorios."], JSON_UNESCAPED_UNICODE);
                return;
            }

            $pdo = Database::conectar();
            self::asegurarEsquemaFinanciamientoYCobros($pdo);

            $stmtCxc = $pdo->prepare("SELECT * FROM gm_cuentas_por_cobrar WHERE id = :id LIMIT 1");
            $stmtCxc->execute([':id' => $cuentaId]);
            $cuenta = $stmtCxc->fetch(PDO::FETCH_ASSOC);

            if (!$cuenta) {
                http_response_code(404);
                echo json_encode(["status" => "error", "mensaje" => "Cuenta por pagar no encontrada."], JSON_UNESCAPED_UNICODE);
                return;
            }

            $medicoId   = (int)$cuenta['medico_id'];
            $pacienteId = $pacienteId > 0 ? $pacienteId : (int)$cuenta['paciente_id'];
            $totalCuotas = max(1, (int)($cuenta['cantidad_cuotas'] ?? $cuenta['cuotas_semanales'] ?? 1));

            // Verificar que esa cuota semanal no esté ya aprobada o en revisión
            $chkDup = $pdo->prepare("
                SELECT id, estatus
                FROM gm_pagos_cuotas
                WHERE cuenta_id = :cid AND cuota_numero = :cnum AND estatus IN ('por_verificar', 'aprobado')
                LIMIT 1
            ");
            $chkDup->execute([':cid' => $cuentaId, ':cnum' => $cuotaNum]);
            $existente = $chkDup->fetch(PDO::FETCH_ASSOC);
            if ($existente) {
                $estadoTxt = ($existente['estatus'] === 'aprobado') ? 'ya fue aprobada' : 'ya está en revisión por el consultorio';
                http_response_code(400);
                echo json_encode(["status" => "error", "mensaje" => "La cuota #{$cuotaNum} {$estadoTxt}."], JSON_UNESCAPED_UNICODE);
                return;
            }

            $tasaVal = $pdo->query("SELECT tasa_usd FROM gm_tasa_bcv ORDER BY fecha DESC LIMIT 1")->fetchColumn();
            $tasaBcv = !empty($input['tasa_bcv']) && (float)$input['tasa_bcv'] > 0
                ? (float)$input['tasa_bcv']
                : (($tasaVal && (float)$tasaVal > 0) ? (float)$tasaVal : 1.0000);

            $montoUsd = (float)($input['monto_usd'] ?? 0);
            $montoBs  = (float)($input['monto_bs'] ?? 0);

            if ($montoUsd <= 0 && $montoBs > 0) {
                $montoUsd = round($montoBs / $tasaBcv, 2);
            } elseif ($montoBs <= 0 && $montoUsd > 0) {
                $montoBs = round($montoUsd * $tasaBcv, 2);
            } else {
                $montoUsd = round($montoUsd, 2);
                $montoBs  = round($montoBs, 2);
            }

            if ($montoUsd <= 0) {
                http_response_code(400);
                echo json_encode(["status" => "error", "mensaje" => "Debe indicar un monto válido para la cuota."], JSON_UNESCAPED_UNICODE);
                return;
            }

            // Guardar comprobante si viene por $_FILES['comprobante'] o en Base64 ('comprobante_base64')
            $comprobanteUrl = trim((string)($input['comprobante_url'] ?? ''));
            $dirUploads = __DIR__ . '/../../public/uploads/comprobantes';
            if (!is_dir($dirUploads)) {
                @mkdir($dirUploads, 0775, true);
            }

            if (!empty($_FILES['comprobante']['tmp_name']) && is_uploaded_file($_FILES['comprobante']['tmp_name'])) {
                $ext = strtolower(pathinfo($_FILES['comprobante']['name'], PATHINFO_EXTENSION)) ?: 'jpg';
                if (!in_array($ext, ['jpg', 'jpeg', 'png', 'webp', 'pdf'], true)) {
                    $ext = 'jpg';
                }
                $nombreArchivo = 'comp_' . $cuentaId . '_c' . $cuotaNum . '_' . time() . '.' . $ext;
                if (move_uploaded_file($_FILES['comprobante']['tmp_name'], $dirUploads . '/' . $nombreArchivo)) {
                    $comprobanteUrl = self::obtenerBaseUrlPublica() . '/uploads/comprobantes/' . $nombreArchivo;
                }
            } elseif (!empty($input['comprobante_base64'])) {
                $b64 = (string)$input['comprobante_base64'];
                $ext = 'jpg';
                if (preg_match('/^data:image\/(\w+);base64,/', $b64, $m)) {
                    $ext = strtolower($m[1]) === 'jpeg' ? 'jpg' : strtolower($m[1]);
                    $b64 = substr($b64, strpos($b64, ',') + 1);
                } elseif (preg_match('/^data:application\/pdf;base64,/', $b64)) {
                    $ext = 'pdf';
                    $b64 = substr($b64, strpos($b64, ',') + 1);
                }
                $bin = base64_decode($b64, true);
                if ($bin !== false && strlen($bin) > 50) {
                    $nombreArchivo = 'comp_' . $cuentaId . '_c' . $cuotaNum . '_' . time() . '.' . $ext;
                    if (@file_put_contents($dirUploads . '/' . $nombreArchivo, $bin)) {
                        $comprobanteUrl = self::obtenerBaseUrlPublica() . '/uploads/comprobantes/' . $nombreArchivo;
                    }
                }
            }

            $ins = $pdo->prepare("
                INSERT INTO gm_pagos_cuotas
                (cuenta_id, medico_id, paciente_id, cuota_numero, total_cuotas, fecha_pago, metodo_pago, banco_destino,
                 referencia, moneda_pagada, monto_usd, monto_bs, tasa_bcv, comprobante_url, observaciones_paciente, estatus)
                VALUES
                (:cid, :med, :pac, :cnum, :tc, :fpago, :met, :banco, :ref, :mon, :musd, :mbs, :tasa, :comp, :obs, 'por_verificar')
            ");
            $ins->execute([
                ':cid'   => $cuentaId,
                ':med'   => $medicoId,
                ':pac'   => $pacienteId,
                ':cnum'  => $cuotaNum,
                ':tc'    => $totalCuotas,
                ':fpago' => $fechaPago,
                ':met'   => $metodoPago,
                ':banco' => $bancoDest,
                ':ref'   => $referencia,
                ':mon'   => ($moneda === 'BS' || $moneda === 'VES') ? 'BS' : 'USD',
                ':musd'  => $montoUsd,
                ':mbs'   => $montoBs,
                ':tasa'  => $tasaBcv,
                ':comp'  => $comprobanteUrl,
                ':obs'   => $obsPac,
            ]);

            http_response_code(201);
            echo json_encode([
                "status"          => "success",
                "mensaje"         => "Reporte de pago enviado (Cuota #{$cuotaNum}). Queda en revisión por el consultorio.",
                "reporte_id"      => (int)$pdo->lastInsertId(),
                "comprobante_url" => $comprobanteUrl,
                "estatus"         => "por_verificar"
            ], JSON_UNESCAPED_UNICODE);

        } catch (Exception $e) {
            http_response_code(500);
            echo json_encode(["status" => "error", "mensaje" => $e->getMessage()], JSON_UNESCAPED_UNICODE);
        }
    }

    /**
     * GET /api/finanzas/pagos-reportados?medico_id=X&paciente_id=Y&cuenta_id=Z&estatus=por_verificar
     */
    public static function listarPagosReportados() {
        try {
            $pdo = Database::conectar();
            self::asegurarEsquemaFinanciamientoYCobros($pdo);

            $condiciones = ["1=1"];
            $params = [];

            if (!empty($_GET['medico_id'])) {
                $condiciones[] = "r.medico_id = :med";
                $params[':med'] = (int)$_GET['medico_id'];
            }
            if (!empty($_GET['paciente_id'])) {
                $condiciones[] = "r.paciente_id = :pac";
                $params[':pac'] = (int)$_GET['paciente_id'];
            }
            if (!empty($_GET['cuenta_id'])) {
                $condiciones[] = "r.cuenta_id = :cid";
                $params[':cid'] = (int)$_GET['cuenta_id'];
            }
            if (!empty($_GET['estatus'])) {
                $condiciones[] = "r.estatus = :est";
                $params[':est'] = trim((string)$_GET['estatus']);
            }

            $whereSql = implode(" AND ", $condiciones);
            $stmt = $pdo->prepare("
                SELECT
                    r.*,
                    c.descripcion AS cuenta_descripcion,
                    CONCAT(p.nombre, ' ', p.apellido) AS paciente_nombre,
                    p.cedula AS paciente_cedula
                FROM gm_pagos_cuotas r
                LEFT JOIN gm_cuentas_por_cobrar c ON r.cuenta_id = c.id
                LEFT JOIN gm_usuarios_extendidos p ON r.paciente_id = p.user_id
                WHERE $whereSql
                ORDER BY FIELD(r.estatus, 'por_verificar', 'aprobado', 'rechazado'), r.id DESC
            ");
            $stmt->execute($params);
            $lista = $stmt->fetchAll(PDO::FETCH_ASSOC);

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
     * POST /api/finanzas/cuentas-por-cobrar/validar-pago
     * Permite al médico o asistente APROBAR o RECHAZAR una cuota reportada.
     * Al aprobar: suma al HABER de gm_cuentas_por_cobrar, descuenta del SALDO y asienta en el DEBE de gm_finanzas.
     */
    public static function validarPagoCuota($input = null) {
        try {
            if ($input === null) {
                $input = json_decode(file_get_contents('php://input'), true) ?: [];
            }

            $reporteId = (int)($input['reporte_id'] ?? $input['id'] ?? 0);
            $accion    = strtolower(trim((string)($input['accion'] ?? 'aprobar')));
            $motivo    = trim((string)($input['motivo_rechazo'] ?? ''));

            if ($reporteId <= 0 || !in_array($accion, ['aprobar', 'rechazar'], true)) {
                http_response_code(400);
                echo json_encode(["status" => "error", "mensaje" => "Parámetros inválidos para validar el pago."], JSON_UNESCAPED_UNICODE);
                return;
            }

            $pdo = Database::conectar();
            self::asegurarEsquemaFinanciamientoYCobros($pdo);
            $pdo->beginTransaction();

            $stmtRep = $pdo->prepare("SELECT * FROM gm_pagos_cuotas WHERE id = :id LIMIT 1 FOR UPDATE");
            $stmtRep->execute([':id' => $reporteId]);
            $rep = $stmtRep->fetch(PDO::FETCH_ASSOC);

            if (!$rep) {
                $pdo->rollBack();
                http_response_code(404);
                echo json_encode(["status" => "error", "mensaje" => "Reporte de pago no encontrado."], JSON_UNESCAPED_UNICODE);
                return;
            }

            if (($rep['estatus'] ?? '') !== 'por_verificar') {
                $pdo->rollBack();
                http_response_code(400);
                echo json_encode(["status" => "error", "mensaje" => "Este pago ya fue procesado anteriormente ({$rep['estatus']})."], JSON_UNESCAPED_UNICODE);
                return;
            }

            if ($accion === 'rechazar') {
                $updRech = $pdo->prepare("
                    UPDATE gm_pagos_cuotas
                    SET estatus = 'rechazado',
                        motivo_rechazo = :motivo,
                        fecha_validacion = NOW()
                    WHERE id = :id
                ");
                $updRech->execute([
                    ':motivo' => $motivo !== '' ? $motivo : 'Comprobante no verificado',
                    ':id'     => $reporteId
                ]);
                $pdo->commit();

                echo json_encode([
                    "status"  => "success",
                    "mensaje" => "Pago rechazado. El paciente podrá reportar nuevamente la cuota #{$rep['cuota_numero']}."
                ], JSON_UNESCAPED_UNICODE);
                return;
            }

            // ACCIÓN = APROBAR
            $cuentaId = (int)$rep['cuenta_id'];
            $stmtCxc = $pdo->prepare("SELECT * FROM gm_cuentas_por_cobrar WHERE id = :id LIMIT 1 FOR UPDATE");
            $stmtCxc->execute([':id' => $cuentaId]);
            $cuenta = $stmtCxc->fetch(PDO::FETCH_ASSOC);

            if (!$cuenta) {
                $pdo->rollBack();
                http_response_code(404);
                echo json_encode(["status" => "error", "mensaje" => "Cuenta por cobrar asociada no encontrada."], JSON_UNESCAPED_UNICODE);
                return;
            }

            $montoUsd = round((float)$rep['monto_usd'], 2);
            $montoBs  = round((float)$rep['monto_bs'], 2);
            $tasaBcv  = (float)$rep['tasa_bcv'] > 0 ? (float)$rep['tasa_bcv'] : 1.0000;

            $debeActualUsd  = max((float)($cuenta['debe'] ?? 0), (float)($cuenta['debe_usd'] ?? 0));
            $haberActualUsd = max((float)($cuenta['haber'] ?? 0), (float)($cuenta['haber_usd'] ?? 0));
            $nuevoHaberUsd  = round(min($debeActualUsd, $haberActualUsd + $montoUsd), 2);
            $nuevoSaldoUsd  = round(max(0, $debeActualUsd - $nuevoHaberUsd), 2);

            $debeActualBs  = (float)($cuenta['debe_bs'] ?? 0) > 0 ? (float)$cuenta['debe_bs'] : round($debeActualUsd * $tasaBcv, 2);
            $haberActualBs = (float)($cuenta['haber_bs'] ?? 0) > 0 ? (float)$cuenta['haber_bs'] : round($haberActualUsd * $tasaBcv, 2);
            $nuevoHaberBs  = round($haberActualBs + $montoBs, 2);
            $nuevoSaldoBs  = round(max(0, $debeActualBs - $nuevoHaberBs), 2);
            $nuevoEstado   = ($nuevoSaldoUsd <= 0.009) ? 'pagado' : 'parcial';

            $totalCuotas   = max(1, (int)($cuenta['cantidad_cuotas'] ?? $cuenta['cuotas_semanales'] ?? $rep['total_cuotas'] ?? 1));
            $cuotasPagadas = min($totalCuotas, max((int)($cuenta['cuotas_pagadas'] ?? 0) + 1, (int)$rep['cuota_numero']));

            $updCxc = $pdo->prepare("
                UPDATE gm_cuentas_por_cobrar
                SET haber = :haber1,
                    haber_usd = :haber2,
                    saldo = :saldo1,
                    saldo_usd = :saldo2,
                    haber_bs = :haber_bs,
                    saldo_bs = :saldo_bs,
                    cuotas_pagadas = :cpag,
                    estado = :estado
                WHERE id = :id
            ");
            $updCxc->execute([
                ':haber1'   => $nuevoHaberUsd,
                ':haber2'   => $nuevoHaberUsd,
                ':saldo1'   => $nuevoSaldoUsd,
                ':saldo2'   => $nuevoSaldoUsd,
                ':haber_bs' => $nuevoHaberBs,
                ':saldo_bs' => $nuevoSaldoBs,
                ':cpag'     => $cuotasPagadas,
                ':estado'   => $nuevoEstado,
                ':id'       => $cuentaId,
            ]);

            $descBase = trim((string)($cuenta['descripcion'] ?? 'Servicio financiado'));
            $conceptoFin = "Cuota semanal ({$rep['cuota_numero']}/{$totalCuotas}) - {$descBase} - {$rep['metodo_pago']} Ref: {$rep['referencia']}";

            $insFin = $pdo->prepare("
                INSERT INTO gm_finanzas
                (medico_id, paciente_id, historia_id, fecha, concepto, debe, haber, saldo, debe_bs, haber_bs, saldo_bs, tasa_bcv)
                VALUES
                (:med, :pac, NULL, :fecha, :concepto, :debe, 0.00, :saldo, :debe_bs, 0.00, :saldo_bs, :tasa)
            ");
            $insFin->execute([
                ':med'      => (int)$rep['medico_id'],
                ':pac'      => (int)$rep['paciente_id'],
                ':fecha'    => $rep['fecha_pago'],
                ':concepto' => mb_substr($conceptoFin, 0, 250),
                ':debe'     => $montoUsd,
                ':saldo'    => $montoUsd,
                ':debe_bs'  => $montoBs,
                ':saldo_bs' => $montoBs,
                ':tasa'     => $tasaBcv,
            ]);
            $finanzaId = (int)$pdo->lastInsertId();

            $updRep = $pdo->prepare("
                UPDATE gm_pagos_cuotas
                SET estatus = 'aprobado',
                    finanza_id = :fid,
                    fecha_validacion = NOW()
                WHERE id = :id
            ");
            $updRep->execute([
                ':fid' => $finanzaId,
                ':id'  => $reporteId,
            ]);

            $pdo->commit();

            echo json_encode([
                "status"          => "success",
                "mensaje"         => "Cuota #{$rep['cuota_numero']} aprobada y acreditada al Haber de Cuentas por Cobrar y al Debe de Finanzas.",
                "finanza_id"      => $finanzaId,
                "nuevo_saldo_usd" => $nuevoSaldoUsd,
                "nuevo_saldo_bs"  => $nuevoSaldoBs
            ], JSON_UNESCAPED_UNICODE);

        } catch (Exception $e) {
            if (isset($pdo) && $pdo->inTransaction()) {
                $pdo->rollBack();
            }
            http_response_code(500);
            echo json_encode(["status" => "error", "mensaje" => $e->getMessage()], JSON_UNESCAPED_UNICODE);
        }
    }

    /**
     * POST /api/finanzas/cuentas-por-cobrar/abono
     * Abono directo registrado por el médico/asistente en consultorio.
     */
    public static function registrarAbonoCuentaPorCobrar($input = null) {
        try {
            if ($input === null) {
                $input = json_decode(file_get_contents('php://input'), true) ?: [];
            }

            $cuentaId   = (int)($input['cuenta_id'] ?? $input['id'] ?? 0);
            $medicoId   = (int)($input['medico_id'] ?? 0);
            $pacienteId = (int)($input['paciente_id'] ?? 0);
            $concepto   = trim((string)($input['concepto'] ?? 'Abono a cuenta por cobrar'));

            $pdo = Database::conectar();
            self::asegurarEsquemaFinanciamientoYCobros($pdo);

            $tasaVal = $pdo->query("SELECT tasa_usd FROM gm_tasa_bcv ORDER BY fecha DESC LIMIT 1")->fetchColumn();
            $tasaBcv = !empty($input['tasa_bcv']) && (float)$input['tasa_bcv'] > 0
                ? (float)$input['tasa_bcv']
                : (($tasaVal && (float)$tasaVal > 0) ? (float)$tasaVal : 1.0000);

            $montoUsd = (float)($input['monto_usd'] ?? $input['haber_usd'] ?? 0);
            $montoBs  = (float)($input['monto_bs'] ?? $input['haber_bs'] ?? 0);

            if ($montoUsd <= 0 && $montoBs > 0) {
                $montoUsd = round($montoBs / $tasaBcv, 2);
            } elseif ($montoBs <= 0 && $montoUsd > 0) {
                $montoBs = round($montoUsd * $tasaBcv, 2);
            } else {
                $montoUsd = round($montoUsd, 2);
                $montoBs  = round($montoBs, 2);
            }

            if ($montoUsd <= 0 || $montoBs <= 0) {
                http_response_code(400);
                echo json_encode(["status" => "error", "mensaje" => "Debe indicar un monto válido para el abono."], JSON_UNESCAPED_UNICODE);
                return;
            }

            $pdo->beginTransaction();

            $nuevoSaldoUsd = null;
            $nuevoSaldoBs  = null;

            if ($cuentaId > 0) {
                $stmtCxc = $pdo->prepare("SELECT * FROM gm_cuentas_por_cobrar WHERE id = :id LIMIT 1 FOR UPDATE");
                $stmtCxc->execute([':id' => $cuentaId]);
                $cuenta = $stmtCxc->fetch(PDO::FETCH_ASSOC);

                if (!$cuenta) {
                    $pdo->rollBack();
                    http_response_code(404);
                    echo json_encode(["status" => "error", "mensaje" => "Cuenta por cobrar no encontrada."], JSON_UNESCAPED_UNICODE);
                    return;
                }

                $medicoId   = $medicoId > 0 ? $medicoId : (int)$cuenta['medico_id'];
                $pacienteId = $pacienteId > 0 ? $pacienteId : (int)$cuenta['paciente_id'];

                $debeActualUsd  = max((float)($cuenta['debe'] ?? 0), (float)($cuenta['debe_usd'] ?? 0));
                $haberActualUsd = max((float)($cuenta['haber'] ?? 0), (float)($cuenta['haber_usd'] ?? 0));
                $saldoActualUsd = max(0, $debeActualUsd - $haberActualUsd);

                if ($montoUsd > ($saldoActualUsd + 0.01)) {
                    $pdo->rollBack();
                    http_response_code(400);
                    echo json_encode(["status" => "error", "mensaje" => "El abono excede el saldo pendiente de la cuenta por cobrar."], JSON_UNESCAPED_UNICODE);
                    return;
                }

                $nuevoHaberUsd = round($haberActualUsd + $montoUsd, 2);
                $nuevoSaldoUsd = round(max(0, $debeActualUsd - $nuevoHaberUsd), 2);

                $debeActualBs  = (float)($cuenta['debe_bs'] ?? 0) > 0 ? (float)$cuenta['debe_bs'] : round($debeActualUsd * $tasaBcv, 2);
                $haberActualBs = (float)($cuenta['haber_bs'] ?? 0) > 0 ? (float)$cuenta['haber_bs'] : round($haberActualUsd * $tasaBcv, 2);
                $nuevoHaberBs  = round($haberActualBs + $montoBs, 2);
                $nuevoSaldoBs  = round(max(0, $debeActualBs - $nuevoHaberBs), 2);
                $nuevoEstado   = ($nuevoSaldoUsd <= 0.009) ? 'pagado' : 'parcial';

                $totalCuotas   = max(1, (int)($cuenta['cantidad_cuotas'] ?? $cuenta['cuotas_semanales'] ?? 1));
                $montoCuota    = ($totalCuotas > 0) ? ($debeActualUsd / $totalCuotas) : $debeActualUsd;
                $cuotasPagadas = ($montoCuota > 0) ? min($totalCuotas, (int)floor(($nuevoHaberUsd + 0.01) / $montoCuota)) : 1;
                if ($nuevoSaldoUsd <= 0.009) {
                    $cuotasPagadas = $totalCuotas;
                }

                $updCxc = $pdo->prepare("
                    UPDATE gm_cuentas_por_cobrar
                    SET haber = :haber1,
                        haber_usd = :haber2,
                        saldo = :saldo1,
                        saldo_usd = :saldo2,
                        haber_bs = :haber_bs,
                        saldo_bs = :saldo_bs,
                        cuotas_pagadas = :cpag,
                        estado = :estado
                    WHERE id = :id
                ");
                $updCxc->execute([
                    ':haber1'   => $nuevoHaberUsd,
                    ':haber2'   => $nuevoHaberUsd,
                    ':saldo1'   => $nuevoSaldoUsd,
                    ':saldo2'   => $nuevoSaldoUsd,
                    ':haber_bs' => $nuevoHaberBs,
                    ':saldo_bs' => $nuevoSaldoBs,
                    ':cpag'     => $cuotasPagadas,
                    ':estado'   => $nuevoEstado,
                    ':id'       => $cuentaId,
                ]);
            }

            if ($medicoId <= 0) {
                $pdo->rollBack();
                http_response_code(400);
                echo json_encode(["status" => "error", "mensaje" => "El campo medico_id es obligatorio."], JSON_UNESCAPED_UNICODE);
                return;
            }

            $fechaMov = !empty($input['fecha']) ? substr(trim((string)$input['fecha']), 0, 10) : date('Y-m-d');
            $conceptoFinal = (stripos($concepto, 'abono') === false)
                ? "Abono CxC: {$concepto}"
                : $concepto;

            $insFin = $pdo->prepare("
                INSERT INTO gm_finanzas
                (medico_id, paciente_id, historia_id, fecha, concepto, debe, haber, saldo, debe_bs, haber_bs, saldo_bs, tasa_bcv)
                VALUES
                (:med, :pac, NULL, :fecha, :concepto, :debe, 0.00, :saldo, :debe_bs, 0.00, :saldo_bs, :tasa)
            ");
            $insFin->execute([
                ':med'      => $medicoId,
                ':pac'      => $pacienteId,
                ':fecha'    => $fechaMov,
                ':concepto' => $conceptoFinal,
                ':debe'     => $montoUsd,
                ':saldo'    => $montoUsd,
                ':debe_bs'  => $montoBs,
                ':saldo_bs' => $montoBs,
                ':tasa'     => $tasaBcv,
            ]);

            $finanzaId = (int)$pdo->lastInsertId();
            $pdo->commit();

            http_response_code(201);
            echo json_encode([
                "status"            => "success",
                "mensaje"           => "Abono registrado: sumado al Haber de Cuentas por Cobrar y al Debe de Finanzas.",
                "finanza_id"        => $finanzaId,
                "cuenta_id"         => $cuentaId,
                "monto_abonado_usd" => $montoUsd,
                "monto_abonado_bs"  => $montoBs,
                "nuevo_saldo_usd"   => $nuevoSaldoUsd,
                "nuevo_saldo_bs"    => $nuevoSaldoBs
            ], JSON_UNESCAPED_UNICODE);

        } catch (Exception $e) {
            if (isset($pdo) && $pdo->inTransaction()) {
                $pdo->rollBack();
            }
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

    /**
     * GET /api/presupuestos
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