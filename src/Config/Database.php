<?php
namespace App\Config;

use PDO;
use PDOException;
use Exception;

class Database {
    private static ?PDO $pdo = null;
    private static bool $collationsNormalizadas = false;
    private static bool $esquemaCitasVerificado = false;
    private static bool $esquemaFinanciamientoVerificado = false;

    public static function conectar(): PDO {
        if (self::$pdo instanceof PDO) {
            return self::$pdo;
        }

        $host = getenv('DB_HOST') ?: 'localhost';
        $db   = getenv('DB_NAME') ?: 'tu_base_de_datos';
        $user = getenv('DB_USER') ?: 'tu_usuario';
        $pass = getenv('DB_PASS') ?: 'tu_contrasena';

        try {
            $pdo = new PDO(
                "mysql:host=$host;dbname=$db;charset=utf8mb4",
                $user,
                $pass,
                [
                    PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                    PDO::ATTR_EMULATE_PREPARES   => false,
                ]
            );
            $pdo->exec("SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci");

            if (!self::$collationsNormalizadas) {
                self::$collationsNormalizadas = true;
                self::normalizarCollationsGm($pdo);
            }

            if (!self::$esquemaCitasVerificado) {
                self::$esquemaCitasVerificado = true;
                self::verificarColumnaMontoCitas($pdo);
            }

            if (!self::$esquemaFinanciamientoVerificado) {
                self::$esquemaFinanciamientoVerificado = true;
                self::verificarEsquemaFinanciamientoYCobros($pdo);
            }

            self::$pdo = $pdo;
            return self::$pdo;
        } catch (PDOException $e) {
            echo json_encode(["error" => "Error de conexión: " . $e->getMessage()]);
            exit();
        }
    }

    private static function normalizarCollationsGm(PDO $pdo): void {
        try {
            $stmt = $pdo->query("
                SELECT TABLE_NAME
                FROM INFORMATION_SCHEMA.TABLES
                WHERE TABLE_SCHEMA = DATABASE()
                  AND TABLE_NAME LIKE 'gm\_%'
                  AND TABLE_COLLATION IS NOT NULL
                  AND TABLE_COLLATION <> 'utf8mb4_unicode_ci'
            ");
            $tablas = $stmt->fetchAll(PDO::FETCH_ASSOC);

            foreach ($tablas as $fila) {
                $tabla = preg_replace('/[^a-zA-Z0-9_]/', '', (string)$fila['TABLE_NAME']);
                if ($tabla !== '') {
                    $pdo->exec("ALTER TABLE `{$tabla}` CONVERT TO CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
                }
            }
        } catch (Exception $e) {
            error_log("Aviso al normalizar collations en tablas gm_: " . $e->getMessage());
        }
    }

    private static function verificarColumnaMontoCitas(PDO $pdo): void {
        try {
            $stmtTabla = $pdo->query("SHOW TABLES LIKE 'gm_citas'");
            if ($stmtTabla && $stmtTabla->fetch()) {
                $columnasCitas = [
                    'total_usd'            => "ALTER TABLE `gm_citas` ADD COLUMN `total_usd` decimal(10,2) NOT NULL DEFAULT 0.00 AFTER `procedimiento`",
                    'es_financiado'        => "ALTER TABLE `gm_citas` ADD COLUMN `es_financiado` tinyint(1) NOT NULL DEFAULT 0 AFTER `total_usd`",
                    'monto_financiado_usd' => "ALTER TABLE `gm_citas` ADD COLUMN `monto_financiado_usd` decimal(10,2) NOT NULL DEFAULT 0.00 AFTER `es_financiado`",
                ];
                foreach ($columnasCitas as $columna => $sqlAlter) {
                    $stmtCol = $pdo->query("SHOW COLUMNS FROM `gm_citas` LIKE '{$columna}'");
                    if ($stmtCol && !$stmtCol->fetch()) {
                        $pdo->exec($sqlAlter);
                    }
                }
            }
        } catch (Exception $e) {
            error_log("Aviso al verificar columnas de monto en gm_citas: " . $e->getMessage());
        }
    }

    private static function verificarEsquemaFinanciamientoYCobros(PDO $pdo): void {
        try {
            $stmtTablaServ = $pdo->query("SHOW TABLES LIKE 'gm_servicios'");
            if ($stmtTablaServ && $stmtTablaServ->fetch()) {
                $columnasServicios = [
                    'permite_financiamiento' => "ALTER TABLE `gm_servicios` ADD COLUMN `permite_financiamiento` tinyint(1) NOT NULL DEFAULT 0 AFTER `precio`",
                    'cantidad_cuotas'        => "ALTER TABLE `gm_servicios` ADD COLUMN `cantidad_cuotas` int(3) NOT NULL DEFAULT 1 AFTER `permite_financiamiento`",
                    'frecuencia_cuotas'      => "ALTER TABLE `gm_servicios` ADD COLUMN `frecuencia_cuotas` varchar(20) NOT NULL DEFAULT 'semanal' AFTER `cantidad_cuotas`",
                ];

                foreach ($columnasServicios as $columna => $sqlAlter) {
                    $stmtCol = $pdo->query("SHOW COLUMNS FROM `gm_servicios` LIKE '{$columna}'");
                    if ($stmtCol && !$stmtCol->fetch()) {
                        $pdo->exec($sqlAlter);
                    }
                }
            }

            $pdo->exec("
                CREATE TABLE IF NOT EXISTS `gm_cuentas_por_cobrar` (
                    `id` mediumint(9) NOT NULL AUTO_INCREMENT,
                    `medico_id` bigint(20) NOT NULL,
                    `paciente_id` bigint(20) NOT NULL,
                    `cita_id` mediumint(9) DEFAULT NULL,
                    `servicio_id` mediumint(9) DEFAULT NULL,
                    `finanza_id` mediumint(9) DEFAULT NULL,
                    `fecha` date NOT NULL,
                    `fecha_credito` date DEFAULT NULL,
                    `descripcion` varchar(255) NOT NULL DEFAULT '',
                    `concepto` varchar(255) DEFAULT NULL,
                    `observaciones` text DEFAULT NULL,
                    `fecha_vencimiento` date NOT NULL,
                    `cuotas_semanales` int(3) NOT NULL DEFAULT 1,
                    `cantidad_cuotas` int(3) NOT NULL DEFAULT 1,
                    `cuotas_pagadas` int(3) NOT NULL DEFAULT 0,
                    `frecuencia_cuotas` varchar(20) NOT NULL DEFAULT 'semanal',
                    `monto_cuota` decimal(10,2) NOT NULL DEFAULT 0.00,
                    `monto_cuota_usd` decimal(10,2) NOT NULL DEFAULT 0.00,
                    `monto_cuota_bs` decimal(12,2) NOT NULL DEFAULT 0.00,
                    `monto_total_usd` decimal(10,2) NOT NULL DEFAULT 0.00,
                    `monto_total_bs` decimal(12,2) NOT NULL DEFAULT 0.00,
                    `saldo_pendiente_usd` decimal(10,2) NOT NULL DEFAULT 0.00,
                    `saldo_pendiente_bs` decimal(12,2) NOT NULL DEFAULT 0.00,
                    `debe` decimal(10,2) NOT NULL DEFAULT 0.00,
                    `haber` decimal(10,2) NOT NULL DEFAULT 0.00,
                    `saldo` decimal(10,2) NOT NULL DEFAULT 0.00,
                    `debe_usd` decimal(10,2) NOT NULL DEFAULT 0.00,
                    `haber_usd` decimal(10,2) NOT NULL DEFAULT 0.00,
                    `saldo_usd` decimal(10,2) NOT NULL DEFAULT 0.00,
                    `debe_bs` decimal(12,2) NOT NULL DEFAULT 0.00,
                    `haber_bs` decimal(12,2) NOT NULL DEFAULT 0.00,
                    `saldo_bs` decimal(12,2) NOT NULL DEFAULT 0.00,
                    `tasa_bcv` decimal(10,4) NOT NULL DEFAULT 1.0000,
                    `estado` varchar(20) NOT NULL DEFAULT 'pendiente',
                    `estatus` varchar(20) NOT NULL DEFAULT 'pendiente',
                    `url_pdf` varchar(500) DEFAULT NULL,
                    `fecha_creacion` datetime DEFAULT CURRENT_TIMESTAMP,
                    `updated_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                    PRIMARY KEY (`id`),
                    INDEX `idx_cxc_medico_fecha` (`medico_id`, `fecha`),
                    INDEX `idx_cxc_paciente` (`paciente_id`),
                    INDEX `idx_cxc_vencimiento` (`fecha_vencimiento`)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
            ");

            $columnasCxc = [
                'finanza_id'          => "ALTER TABLE `gm_cuentas_por_cobrar` ADD COLUMN `finanza_id` mediumint(9) DEFAULT NULL AFTER `servicio_id`",
                'fecha_credito'       => "ALTER TABLE `gm_cuentas_por_cobrar` ADD COLUMN `fecha_credito` date DEFAULT NULL AFTER `fecha`",
                'concepto'            => "ALTER TABLE `gm_cuentas_por_cobrar` ADD COLUMN `concepto` varchar(255) DEFAULT NULL AFTER `descripcion`",
                'observaciones'       => "ALTER TABLE `gm_cuentas_por_cobrar` ADD COLUMN `observaciones` text DEFAULT NULL AFTER `concepto`",
                'cuotas_semanales'    => "ALTER TABLE `gm_cuentas_por_cobrar` ADD COLUMN `cuotas_semanales` int(3) NOT NULL DEFAULT 1 AFTER `fecha_vencimiento`",
                'cantidad_cuotas'     => "ALTER TABLE `gm_cuentas_por_cobrar` ADD COLUMN `cantidad_cuotas` int(3) NOT NULL DEFAULT 1 AFTER `cuotas_semanales`",
                'cuotas_pagadas'      => "ALTER TABLE `gm_cuentas_por_cobrar` ADD COLUMN `cuotas_pagadas` int(3) NOT NULL DEFAULT 0 AFTER `cantidad_cuotas`",
                'frecuencia_cuotas'   => "ALTER TABLE `gm_cuentas_por_cobrar` ADD COLUMN `frecuencia_cuotas` varchar(20) NOT NULL DEFAULT 'semanal' AFTER `cuotas_pagadas`",
                'monto_cuota'         => "ALTER TABLE `gm_cuentas_por_cobrar` ADD COLUMN `monto_cuota` decimal(10,2) NOT NULL DEFAULT 0.00 AFTER `frecuencia_cuotas`",
                'monto_cuota_usd'     => "ALTER TABLE `gm_cuentas_por_cobrar` ADD COLUMN `monto_cuota_usd` decimal(10,2) NOT NULL DEFAULT 0.00 AFTER `monto_cuota`",
                'monto_cuota_bs'      => "ALTER TABLE `gm_cuentas_por_cobrar` ADD COLUMN `monto_cuota_bs` decimal(12,2) NOT NULL DEFAULT 0.00 AFTER `monto_cuota_usd`",
                'monto_total_usd'     => "ALTER TABLE `gm_cuentas_por_cobrar` ADD COLUMN `monto_total_usd` decimal(10,2) NOT NULL DEFAULT 0.00 AFTER `monto_cuota_bs`",
                'monto_total_bs'      => "ALTER TABLE `gm_cuentas_por_cobrar` ADD COLUMN `monto_total_bs` decimal(12,2) NOT NULL DEFAULT 0.00 AFTER `monto_total_usd`",
                'saldo_pendiente_usd' => "ALTER TABLE `gm_cuentas_por_cobrar` ADD COLUMN `saldo_pendiente_usd` decimal(10,2) NOT NULL DEFAULT 0.00 AFTER `monto_total_bs`",
                'saldo_pendiente_bs'  => "ALTER TABLE `gm_cuentas_por_cobrar` ADD COLUMN `saldo_pendiente_bs` decimal(12,2) NOT NULL DEFAULT 0.00 AFTER `saldo_pendiente_usd`",
                'debe_usd'            => "ALTER TABLE `gm_cuentas_por_cobrar` ADD COLUMN `debe_usd` decimal(10,2) NOT NULL DEFAULT 0.00 AFTER `saldo`",
                'haber_usd'           => "ALTER TABLE `gm_cuentas_por_cobrar` ADD COLUMN `haber_usd` decimal(10,2) NOT NULL DEFAULT 0.00 AFTER `debe_usd`",
                'saldo_usd'           => "ALTER TABLE `gm_cuentas_por_cobrar` ADD COLUMN `saldo_usd` decimal(10,2) NOT NULL DEFAULT 0.00 AFTER `haber_usd`",
                'estatus'             => "ALTER TABLE `gm_cuentas_por_cobrar` ADD COLUMN `estatus` varchar(20) NOT NULL DEFAULT 'pendiente' AFTER `estado`",
                'url_pdf'             => "ALTER TABLE `gm_cuentas_por_cobrar` ADD COLUMN `url_pdf` varchar(500) DEFAULT NULL AFTER `estatus`",
                'updated_at'          => "ALTER TABLE `gm_cuentas_por_cobrar` ADD COLUMN `updated_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP AFTER `fecha_creacion`",
            ];

            foreach ($columnasCxc as $columna => $sqlAlter) {
                $stmtCol = $pdo->query("SHOW COLUMNS FROM `gm_cuentas_por_cobrar` LIKE '{$columna}'");
                if ($stmtCol && !$stmtCol->fetch()) {
                    $pdo->exec($sqlAlter);
                }
            }
        } catch (Exception $e) {
            error_log("Aviso al verificar esquema de financiamiento y cuentas por cobrar: " . $e->getMessage());
        }
    }
}