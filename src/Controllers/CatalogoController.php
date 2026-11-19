<?php
namespace App\Controllers;

use App\Config\Database;
use PDO;
use Exception;

class CatalogoController {
    public static function obtenerTodos() {
        try {
            $pdo = Database::conectar();

            $tiposIdentificacion = $pdo->query("SELECT id, codigo, nombre FROM gm_tipo_identificacion ORDER BY id ASC")->fetchAll(PDO::FETCH_ASSOC);
            $tiposUsuario        = $pdo->query("SELECT id, nombre, slug FROM gm_tipo_usuario ORDER BY id ASC")->fetchAll(PDO::FETCH_ASSOC);
            $estatusCita         = $pdo->query("SELECT id, nombre, slug, color_hex FROM gm_estatus_cita ORDER BY id ASC")->fetchAll(PDO::FETCH_ASSOC);
            $estatusPaciente     = $pdo->query("SELECT id, nombre, slug FROM gm_estatus_paciente ORDER BY id ASC")->fetchAll(PDO::FETCH_ASSOC);
            $estatusHistoria     = $pdo->query("SELECT id, nombre, slug FROM gm_estatus_historia ORDER BY id ASC")->fetchAll(PDO::FETCH_ASSOC);
            $especialidades      = $pdo->query("SELECT id, nombre, slug FROM gm_especialidades ORDER BY nombre ASC")->fetchAll(PDO::FETCH_ASSOC);

            $stmtTasa   = $pdo->query("SELECT tasa_usd, fecha FROM gm_tasa_bcv ORDER BY id DESC LIMIT 1");
            $ultimaTasa = $stmtTasa ? $stmtTasa->fetch(PDO::FETCH_ASSOC) : null;

            echo json_encode([
                "status" => "success",
                "data"   => [
                    "tipos_identificacion" => $tiposIdentificacion,
                    "tipos_usuario"        => $tiposUsuario,
                    "estatus_cita"         => $estatusCita,
                    "estatus_paciente"     => $estatusPaciente,
                    "estatus_historia"     => $estatusHistoria,
                    "especialidades"       => $especialidades,
                    "tasa_bcv"             => $ultimaTasa ? $ultimaTasa['tasa_usd'] : null,
                    "tasa_bcv_fecha"       => $ultimaTasa ? $ultimaTasa['fecha'] : null
                ]
            ], JSON_UNESCAPED_UNICODE);

        } catch (Exception $e) {
            http_response_code(500);
            echo json_encode(["status" => "error", "mensaje" => $e->getMessage()], JSON_UNESCAPED_UNICODE);
        }
    }
}