<?php
namespace App\Config;

use PDO;
use PDOException;
use Exception;

class Database {
    private static ?PDO $pdo = null;
    private static bool $collationsNormalizadas = false;
    private static bool $esquemaCitasVerificado = false;

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
                $stmtCol = $pdo->query("SHOW COLUMNS FROM `gm_citas` LIKE 'total_usd'");
                if ($stmtCol && !$stmtCol->fetch()) {
                    $pdo->exec("ALTER TABLE `gm_citas` ADD COLUMN `total_usd` decimal(10,2) NOT NULL DEFAULT 0.00 AFTER `procedimiento`");
                }
            }
        } catch (Exception $e) {
            error_log("Aviso al verificar columna total_usd en gm_citas: " . $e->getMessage());
        }
    }
}