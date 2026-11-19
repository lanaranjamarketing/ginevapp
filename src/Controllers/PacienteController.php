<?php
namespace App\Controllers;

use App\Config\Database;
use PDO;
use Exception;

class PacienteController {

    /**
     * GET /api/pacientes?medico_id=X&search=&orden=apellido_asc&limit=25&page=1
     */
    public static function listarPacientes() {
        try {
            $pdo = Database::conectar();
            $medicoId = isset($_GET['medico_id']) ? (int)$_GET['medico_id'] : 0;
            $search   = trim($_GET['search'] ?? '');
            $orden    = trim($_GET['orden'] ?? 'apellido_asc');
            $limitRaw = $_GET['limit'] ?? '25';
            $page     = max(1, (int)($_GET['page'] ?? 1));

            if ($medicoId <= 0) {
                http_response_code(400);
                echo json_encode(["status" => "error", "mensaje" => "El parámetro medico_id es obligatorio."], JSON_UNESCAPED_UNICODE);
                return;
            }

            // Obtiene pacientes con citas con el médico o creados/asignados al médico
            $whereParts = [
                "(EXISTS (SELECT 1 FROM gm_citas ci WHERE ci.paciente_id = u.user_id AND ci.medico_id = :med_citas)
                  OR u.creado_por = :med_creador)"
            ];
            $params = [
                ':med_citas'   => $medicoId,
                ':med_creador' => $medicoId,
                ':med_ult'     => $medicoId,
                ':med_prox'    => $medicoId,
            ];

            if ($search !== '') {
                $whereParts[] = "(u.nombre LIKE :q1 OR u.apellido LIKE :q2 OR u.cedula LIKE :q3 OR u.whatsapp LIKE :q4 OR u.email LIKE :q5)";
                $like = '%' . $search . '%';
                $params[':q1'] = $like;
                $params[':q2'] = $like;
                $params[':q3'] = $like;
                $params[':q4'] = $like;
                $params[':q5'] = $like;
            }

            $whereSql = implode(" AND ", $whereParts);

            switch ($orden) {
                case 'nombre_asc':
                    $orderSql = "ORDER BY u.nombre ASC, u.apellido ASC";
                    break;
                case 'nombre_desc':
                    $orderSql = "ORDER BY u.nombre DESC, u.apellido DESC";
                    break;
                case 'apellido_desc':
                    $orderSql = "ORDER BY u.apellido DESC, u.nombre DESC";
                    break;
                case 'apellido_asc':
                default:
                    $orderSql = "ORDER BY u.apellido ASC, u.nombre ASC";
                    break;
            }

            $sqlBase = "SELECT DISTINCT
                            u.user_id,
                            u.tipo_identificacion,
                            u.cedula,
                            u.nombre,
                            u.apellido,
                            u.whatsapp,
                            u.email,
                            u.edad,
                            u.fecha_nacimiento,
                            u.sexo,
                            (SELECT MAX(c.fecha_cita)
                             FROM gm_citas c
                             WHERE c.paciente_id = u.user_id
                               AND c.medico_id = :med_ult
                               AND c.fecha_cita < NOW()) AS ultima_cita,
                            (SELECT MIN(c.fecha_cita)
                             FROM gm_citas c
                             WHERE c.paciente_id = u.user_id
                               AND c.medico_id = :med_prox
                               AND c.fecha_cita >= NOW()
                               AND LOWER(COALESCE(c.estado_cita, '')) NOT IN ('cancelada', 'cancelled')) AS proxima_cita
                        FROM gm_usuarios_extendidos u
                        WHERE $whereSql
                        $orderSql";

            $stmtTotal = $pdo->prepare("SELECT COUNT(*) FROM ($sqlBase) AS sub");
            $stmtTotal->execute($params);
            $totalPacientes = (int)$stmtTotal->fetchColumn();

            if ($limitRaw !== 'todos' && is_numeric($limitRaw) && (int)$limitRaw > 0) {
                $limit  = (int)$limitRaw;
                $offset = ($page - 1) * $limit;
                $sqlPaginado = "$sqlBase LIMIT :limit OFFSET :offset";
                $stmt = $pdo->prepare($sqlPaginado);
                foreach ($params as $k => $v) {
                    $stmt->bindValue($k, $v);
                }
                $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
                $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
                $stmt->execute();
            } else {
                $stmt = $pdo->prepare($sqlBase);
                $stmt->execute($params);
            }

            $pacientes = $stmt->fetchAll(PDO::FETCH_ASSOC);

            echo json_encode([
                "status" => "success",
                "total"  => $totalPacientes,
                "page"   => $page,
                "data"   => $pacientes
            ], JSON_UNESCAPED_UNICODE);

        } catch (Exception $e) {
            http_response_code(500);
            echo json_encode(["status" => "error", "mensaje" => $e->getMessage()], JSON_UNESCAPED_UNICODE);
        }
    }

    /**
     * POST /api/pacientes
     * Registra un paciente nuevo y lo asocia al médico
     */
    public static function crearPaciente($input = null) {
        try {
            if ($input === null) {
                $input = json_decode(file_get_contents('php://input'), true) ?: [];
            }

            $medicoId  = (int)($input['medico_id'] ?? 0);
            $nombre    = trim($input['nombre'] ?? '');
            $apellido  = trim($input['apellido'] ?? '');
            $cedula    = trim($input['cedula'] ?? '');
            $whatsapp  = trim($input['whatsapp'] ?? '');

            if ($medicoId <= 0 || empty($nombre) || empty($apellido) || empty($cedula)) {
                http_response_code(400);
                echo json_encode([
                    "status"  => "error",
                    "mensaje" => "Los campos medico_id, cédula, nombre y apellido son obligatorios."
                ], JSON_UNESCAPED_UNICODE);
                return;
            }

            $pdo = Database::conectar();
            $tipoId   = trim($input['tipo_identificacion'] ?? 'V');
            $email    = trim($input['email'] ?? '');
            $edad     = isset($input['edad']) && $input['edad'] !== '' ? (int)$input['edad'] : null;
            $fechaNac = !empty($input['fecha_nacimiento']) ? trim($input['fecha_nacimiento']) : null;
            $sexo     = trim($input['sexo'] ?? 'Femenino');

            // Verificar si ya existe por cédula o whatsapp
            $stmtCheck = $pdo->prepare("SELECT user_id FROM gm_usuarios_extendidos WHERE cedula = :cedula LIMIT 1");
            $stmtCheck->execute([':cedula' => $cedula]);
            $existente = $stmtCheck->fetch(PDO::FETCH_ASSOC);

            if ($existente) {
                $userId = (int)$existente['user_id'];
                $stmtUpd = $pdo->prepare("
                    UPDATE gm_usuarios_extendidos
                    SET tipo_identificacion = :tipo_id,
                        nombre = :nombre,
                        apellido = :apellido,
                        whatsapp = COALESCE(NULLIF(:whatsapp, ''), whatsapp),
                        email = COALESCE(NULLIF(:email, ''), email),
                        edad = COALESCE(:edad, edad),
                        fecha_nacimiento = COALESCE(:fecha_nac, fecha_nacimiento),
                        sexo = COALESCE(NULLIF(:sexo, ''), sexo),
                        creado_por = COALESCE(creado_por, :med_id)
                    WHERE user_id = :uid
                ");
                $stmtUpd->execute([
                    ':tipo_id'   => $tipoId,
                    ':nombre'    => $nombre,
                    ':apellido'  => $apellido,
                    ':whatsapp'  => $whatsapp,
                    ':email'     => $email,
                    ':edad'      => $edad,
                    ':fecha_nac' => $fechaNac,
                    ':sexo'      => $sexo,
                    ':med_id'    => $medicoId,
                    ':uid'       => $userId,
                ]);

                echo json_encode([
                    "status"  => "success",
                    "mensaje" => "Paciente vinculado y actualizado correctamente.",
                    "user_id" => $userId
                ], JSON_UNESCAPED_UNICODE);
                return;
            }

            $sql = "INSERT INTO gm_usuarios_extendidos
                        (tipo_identificacion, cedula, nombre, apellido, whatsapp, email, edad, fecha_nacimiento, sexo, rol, creado_por)
                    VALUES
                        (:tipo_id, :cedula, :nombre, :apellido, :whatsapp, :email, :edad, :fecha_nac, :sexo, 'paciente', :creado_por)";
            $stmt = $pdo->prepare($sql);
            $stmt->execute([
                ':tipo_id'    => $tipoId,
                ':cedula'     => $cedula,
                ':nombre'     => $nombre,
                ':apellido'   => $apellido,
                ':whatsapp'   => $whatsapp,
                ':email'      => $email,
                ':edad'       => $edad,
                ':fecha_nac'  => $fechaNac,
                ':sexo'       => $sexo,
                ':creado_por' => $medicoId,
            ]);

            http_response_code(201);
            echo json_encode([
                "status"  => "success",
                "mensaje" => "Paciente registrado correctamente.",
                "user_id" => $pdo->lastInsertId()
            ], JSON_UNESCAPED_UNICODE);

        } catch (Exception $e) {
            http_response_code(500);
            echo json_encode(["status" => "error", "mensaje" => $e->getMessage()], JSON_UNESCAPED_UNICODE);
        }
    }
}