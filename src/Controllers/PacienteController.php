<?php
namespace App\Controllers;

use App\Config\Database;
use PDO;
use Exception;

class PacienteController {
    public static function listar() {
        try {
            $pdo = Database::conectar();
            $stmt = $pdo->query("SELECT id, user_id, tipo_identificacion, cedula, whatsapp, nombre, segundo_nombre, apellido, segundo_apellido, edad, sexo, fecha_nacimiento, email FROM gm_usuarios_extendidos WHERE rol_medico = 'paciente' ORDER BY id DESC");
            $pacientes = $stmt->fetchAll(PDO::FETCH_ASSOC);

            echo json_encode([
                "status" => "success",
                "total" => count($pacientes),
                "data" => $pacientes
            ], JSON_UNESCAPED_UNICODE);

        } catch (Exception $e) {
            http_response_code(500);
            echo json_encode(["status" => "error", "mensaje" => $e->getMessage()], JSON_UNESCAPED_UNICODE);
        }
    }

    public static function crear($input) {
        try {
            $requeridos = ['tipo_identificacion', 'cedula', 'whatsapp', 'nombre', 'apellido', 'edad', 'sexo', 'email'];
            foreach ($requeridos as $campo) {
                if (empty($input[$campo])) {
                    http_response_code(400);
                    echo json_encode(["status" => "error", "mensaje" => "El campo '$campo' es obligatorio."], JSON_UNESCAPED_UNICODE);
                    return;
                }
            }

            $pdo = Database::conectar();

            // Validar unicidad de Cédula y WhatsApp
            $check = $pdo->prepare("SELECT id FROM gm_usuarios_extendidos WHERE cedula = :cedula OR whatsapp = :whatsapp LIMIT 1");
            $check->execute([
                ':cedula'   => trim($input['cedula']),
                ':whatsapp' => trim($input['whatsapp'])
            ]);

            if ($check->fetch()) {
                http_response_code(409);
                echo json_encode(["status" => "error", "mensaje" => "Ya existe un usuario registrado con esa cédula o número de WhatsApp."], JSON_UNESCAPED_UNICODE);
                return;
            }

            // Generar un user_id correlativo independiente
            $maxUser = $pdo->query("SELECT COALESCE(MAX(user_id), 0) + 1 FROM gm_usuarios_extendidos")->fetchColumn();

            $sql = "INSERT INTO gm_usuarios_extendidos 
                (user_id, tipo_identificacion, cedula, whatsapp, nombre, segundo_nombre, apellido, segundo_apellido, edad, sexo, fecha_nacimiento, ocupacion, email, rol_medico) 
                VALUES 
                (:user_id, :tipo_id, :cedula, :whatsapp, :nombre, :seg_nombre, :apellido, :seg_apellido, :edad, :sexo, :fecha_nac, :ocupacion, :email, 'paciente')";

            $stmt = $pdo->prepare($sql);
            $stmt->execute([
                ':user_id'      => $maxUser,
                ':tipo_id'      => trim($input['tipo_identificacion']),
                ':cedula'       => trim($input['cedula']),
                ':whatsapp'     => trim($input['whatsapp']),
                ':nombre'       => trim($input['nombre']),
                ':seg_nombre'   => $input['segundo_nombre'] ?? null,
                ':apellido'     => trim($input['apellido']),
                ':seg_apellido' => $input['segundo_apellido'] ?? null,
                ':edad'         => (int)$input['edad'],
                ':sexo'         => trim($input['sexo']),
                ':fecha_nac'    => $input['fecha_nacimiento'] ?? null,
                ':ocupacion'    => $input['ocupacion'] ?? null,
                ':email'        => trim($input['email'])
            ]);

            http_response_code(201);
            echo json_encode([
                "status" => "success",
                "mensaje" => "Paciente registrado exitosamente.",
                "id" => $pdo->lastInsertId(),
                "user_id" => $maxUser
            ], JSON_UNESCAPED_UNICODE);

        } catch (Exception $e) {
            http_response_code(500);
            echo json_encode(["status" => "error", "mensaje" => $e->getMessage()], JSON_UNESCAPED_UNICODE);
        }
    }
}