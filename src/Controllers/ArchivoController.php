<?php
namespace App\Controllers;

use App\Config\Database;
use PDO;
use Exception;

class ArchivoController {

    private static bool $esquemaVerificado = false;

    /**
     * Garantiza de forma segura que gm_historias_examenes soporte exámenes
     * asociados a historias y exámenes independientes sin consulta asociada.
     */
    private static function asegurarEsquema(PDO $pdo): void {
        if (self::$esquemaVerificado) {
            return;
        }
        try {
            $pdo->exec("ALTER TABLE `gm_historias_examenes` MODIFY COLUMN `historia_id` mediumint(9) DEFAULT NULL");

            $columnas = [
                'paciente_id'   => "ALTER TABLE `gm_historias_examenes` ADD COLUMN `paciente_id` bigint(20) DEFAULT NULL AFTER `historia_id`",
                'medico_id'     => "ALTER TABLE `gm_historias_examenes` ADD COLUMN `medico_id` bigint(20) DEFAULT NULL AFTER `paciente_id`",
                'observaciones' => "ALTER TABLE `gm_historias_examenes` ADD COLUMN `observaciones` text DEFAULT NULL AFTER `descripcion_solicitud`",
                'fecha_examen'  => "ALTER TABLE `gm_historias_examenes` ADD COLUMN `fecha_examen` date DEFAULT NULL AFTER `archivo_adjunto_url`",
            ];

            foreach ($columnas as $col => $sql) {
                $chk = $pdo->query("SHOW COLUMNS FROM `gm_historias_examenes` LIKE '$col'");
                if ($chk && !$chk->fetch()) {
                    $pdo->exec($sql);
                }
            }
        } catch (Exception $e) {
            // No interrumpir la petición si el usuario de BD no tiene permisos DDL
        }
        self::$esquemaVerificado = true;
    }

    /**
     * GET /api/archivo?medico_id=X&paciente_id=Y
     * Devuelve:
     * 1) Consultas en estatus 'Completada' con sus exámenes asociados y enlace PDF
     * 2) Exámenes/estudios independientes sin historia clínica asociada
     */
    public static function listar() {
        try {
            $pdo = Database::conectar();
            self::asegurarEsquema($pdo);

            $medicoId   = isset($_GET['medico_id']) ? (int)$_GET['medico_id'] : 0;
            $pacienteId = isset($_GET['paciente_id']) ? (int)$_GET['paciente_id'] : 0;

            if ($medicoId <= 0 && $pacienteId <= 0) {
                http_response_code(400);
                echo json_encode([
                    "status"  => "error",
                    "mensaje" => "Debe indicar medico_id o paciente_id."
                ], JSON_UNESCAPED_UNICODE);
                return;
            }

            // 1. Consultas en estatus Completada
            $condHist = [
                "LOWER(TRIM(COALESCE(h.estado_historia, ''))) IN ('completada', 'completado', 'consulta terminada')"
            ];
            $paramsHist = [];

            if ($medicoId > 0) {
                $condHist[] = "h.medico_id = :med";
                $paramsHist[':med'] = $medicoId;
            }
            if ($pacienteId > 0) {
                $condHist[] = "h.paciente_id = :pac";
                $paramsHist[':pac'] = $pacienteId;
            }

            $whereHist = implode(" AND ", $condHist);

            $sqlHist = "SELECT 
                            h.*,
                            CONCAT(p.nombre, ' ', p.apellido) AS paciente_nombre,
                            p.cedula AS paciente_cedula,
                            p.cedula AS cedula,
                            p.whatsapp AS paciente_whatsapp,
                            p.whatsapp AS whatsapp,
                            p.sexo AS paciente_sexo,
                            p.edad AS paciente_edad,
                            CONCAT(m.nombre, ' ', m.apellido) AS medico_nombre,
                            m.especialidad AS medico_especialidad
                        FROM gm_historias_medicas h
                        LEFT JOIN gm_usuarios_extendidos p ON h.paciente_id = p.user_id
                        LEFT JOIN gm_usuarios_extendidos m ON h.medico_id = m.user_id
                        WHERE $whereHist
                        ORDER BY h.fecha_registro DESC";

            $stmtHist = $pdo->prepare($sqlHist);
            $stmtHist->execute($paramsHist);
            $consultas = $stmtHist->fetchAll(PDO::FETCH_ASSOC);

            $stmtExamPorHist = $pdo->prepare("SELECT * FROM gm_historias_examenes WHERE historia_id = :hid ORDER BY id ASC");
            foreach ($consultas as &$c) {
                $stmtExamPorHist->execute([':hid' => (int)$c['id']]);
                $c['examenes'] = $stmtExamPorHist->fetchAll(PDO::FETCH_ASSOC);

                $pdfUrl = '';
                if (!empty($c['url_informe_pdf'])) {
                    $pdfUrl = $c['url_informe_pdf'];
                } elseif (!empty($c['url_pdf_informe'])) {
                    $pdfUrl = $c['url_pdf_informe'];
                } elseif (!empty($c['pdf_path'])) {
                    $pdfUrl = $c['pdf_path'];
                } elseif (!empty($c['hash_validacion'])) {
                    $pdfUrl = '/api/historias/pdf?id=' . (int)$c['id'];
                }
                $c['pdf_url']         = $pdfUrl;
                $c['url_informe_pdf'] = $pdfUrl;
            }
            unset($c);

            // 2. Exámenes y estudios sin historia/consulta asociada (historia_id IS NULL OR historia_id = 0)
            $condEx = ["(e.historia_id IS NULL OR e.historia_id = 0)"];
            $paramsEx = [];

            if ($medicoId > 0) {
                $condEx[] = "e.medico_id = :med";
                $paramsEx[':med'] = $medicoId;
            }
            if ($pacienteId > 0) {
                $condEx[] = "e.paciente_id = :pac";
                $paramsEx[':pac'] = $pacienteId;
            }

            $whereEx = implode(" AND ", $condEx);

            $sqlEx = "SELECT 
                        e.*,
                        CONCAT(p.nombre, ' ', p.apellido) AS paciente_nombre,
                        p.cedula AS paciente_cedula,
                        p.cedula AS cedula,
                        p.whatsapp AS paciente_whatsapp,
                        p.whatsapp AS whatsapp,
                        CONCAT(m.nombre, ' ', m.apellido) AS medico_nombre
                      FROM gm_historias_examenes e
                      LEFT JOIN gm_usuarios_extendidos p ON e.paciente_id = p.user_id
                      LEFT JOIN gm_usuarios_extendidos m ON e.medico_id = m.user_id
                      WHERE $whereEx
                      ORDER BY COALESCE(e.fecha_examen, DATE(e.fecha_registro)) DESC, e.id DESC";

            $stmtEx = $pdo->prepare($sqlEx);
            $stmtEx->execute($paramsEx);
            $examenesIndependientes = $stmtEx->fetchAll(PDO::FETCH_ASSOC);

            echo json_encode([
                "status"                  => "success",
                "total_consultas"         => count($consultas),
                "total_examenes_sueltos"  => count($examenesIndependientes),
                "data"                    => $consultas,
                "consultas_completadas"   => $consultas,
                "examenes_independientes" => $examenesIndependientes,
                "examenes_sueltos"        => $examenesIndependientes
            ], JSON_UNESCAPED_UNICODE);

        } catch (Exception $e) {
            http_response_code(500);
            echo json_encode(["status" => "error", "mensaje" => $e->getMessage()], JSON_UNESCAPED_UNICODE);
        }
    }

    /**
     * POST /api/archivo/examenes
     * Registra un examen asociado a una consulta existente (historia_id > 0)
     * o un estudio independiente sin historia asociada (historia_id = 0 / null).
     */
    public static function crearExamen($input) {
        try {
            $pdo = Database::conectar();
            self::asegurarEsquema($pdo);

            $medicoId    = (int)($input['medico_id'] ?? 0);
            $pacienteId  = (int)($input['paciente_id'] ?? 0);
            $historiaId  = !empty($input['historia_id']) ? (int)$input['historia_id'] : 0;
            $tipoExamen  = trim((string)($input['tipo_examen'] ?? $input['tipo'] ?? ''));
            $descripcion = trim((string)($input['descripcion_solicitud'] ?? $input['descripcion'] ?? ''));
            $observacion = trim((string)($input['observaciones'] ?? ''));
            $resultado   = trim((string)($input['resultado'] ?? ''));
            $estado      = trim((string)($input['estado_examen'] ?? $input['estatus'] ?? 'Pendiente'));
            $fechaExamen = trim((string)($input['fecha_examen'] ?? date('Y-m-d')));

            if ($tipoExamen === '') {
                http_response_code(400);
                echo json_encode([
                    "status"  => "error",
                    "mensaje" => "El tipo de examen o estudio es obligatorio."
                ], JSON_UNESCAPED_UNICODE);
                return;
            }

            // Si viene historia_id, validar existencia y heredar paciente_id / medico_id si faltan
            if ($historiaId > 0) {
                $stmtH = $pdo->prepare("SELECT id, paciente_id, medico_id FROM gm_historias_medicas WHERE id = :hid LIMIT 1");
                $stmtH->execute([':hid' => $historiaId]);
                $hist = $stmtH->fetch(PDO::FETCH_ASSOC);

                if (!$hist) {
                    http_response_code(404);
                    echo json_encode([
                        "status"  => "error",
                        "mensaje" => "La consulta/historia #$historiaId no existe."
                    ], JSON_UNESCAPED_UNICODE);
                    return;
                }

                if ($pacienteId <= 0) {
                    $pacienteId = (int)$hist['paciente_id'];
                }
                if ($medicoId <= 0) {
                    $medicoId = (int)$hist['medico_id'];
                }
            }

            if ($pacienteId <= 0 || $medicoId <= 0) {
                http_response_code(400);
                echo json_encode([
                    "status"  => "error",
                    "mensaje" => "Los campos medico_id y paciente_id son obligatorios."
                ], JSON_UNESCAPED_UNICODE);
                return;
            }

            // Procesar archivo adjunto opcional (multipart/form-data)
            $archivoUrl = !empty($input['archivo_adjunto_url']) ? trim((string)$input['archivo_adjunto_url']) : null;
            $fileKey = isset($_FILES['archivo']) ? 'archivo' : (isset($_FILES['file']) ? 'file' : null);

            if ($fileKey && isset($_FILES[$fileKey]['error']) && $_FILES[$fileKey]['error'] === UPLOAD_ERR_OK) {
                $uploadDir = __DIR__ . '/../../public/uploads/examenes/';
                if (!is_dir($uploadDir)) {
                    mkdir($uploadDir, 0755, true);
                }
                $ext = pathinfo($_FILES[$fileKey]['name'], PATHINFO_EXTENSION) ?: 'pdf';
                $prefijo = $historiaId > 0 ? ('exam_h' . $historiaId) : ('exam_ind_p' . $pacienteId);
                $fileName = $prefijo . '_' . time() . '_' . mt_rand(100, 999) . '.' . strtolower($ext);

                if (move_uploaded_file($_FILES[$fileKey]['tmp_name'], $uploadDir . $fileName)) {
                    $archivoUrl = '/uploads/examenes/' . $fileName;
                }
            }

            $sql = "INSERT INTO gm_historias_examenes 
                    (historia_id, paciente_id, medico_id, tipo_examen, descripcion_solicitud, observaciones, resultado, estado_examen, archivo_adjunto_url, fecha_examen)
                    VALUES 
                    (:hid, :pac, :med, :tipo, :desc, :obs, :res, :estado, :url, :fecha)";

            $stmt = $pdo->prepare($sql);
            $stmt->execute([
                ':hid'    => $historiaId > 0 ? $historiaId : null,
                ':pac'    => $pacienteId,
                ':med'    => $medicoId,
                ':tipo'   => $tipoExamen,
                ':desc'   => $descripcion !== '' ? $descripcion : null,
                ':obs'    => $observacion !== '' ? $observacion : null,
                ':res'    => $resultado !== '' ? $resultado : ($observacion !== '' ? $observacion : null),
                ':estado' => $estado !== '' ? $estado : 'Pendiente',
                ':url'    => $archivoUrl,
                ':fecha'  => preg_match('/^\d{4}-\d{2}-\d{2}$/', $fechaExamen) ? $fechaExamen : date('Y-m-d'),
            ]);

            $nuevoId = (int)$pdo->lastInsertId();

            http_response_code(201);
            echo json_encode([
                "status"              => "success",
                "mensaje"             => $historiaId > 0
                    ? "Examen asociado a la consulta #$historiaId registrado correctamente."
                    : "Estudio independiente registrado correctamente en el archivo.",
                "examen_id"           => $nuevoId,
                "historia_id"         => $historiaId > 0 ? $historiaId : null,
                "archivo_adjunto_url" => $archivoUrl
            ], JSON_UNESCAPED_UNICODE);

        } catch (Exception $e) {
            http_response_code(500);
            echo json_encode(["status" => "error", "mensaje" => $e->getMessage()], JSON_UNESCAPED_UNICODE);
        }
    }

    /**
     * PUT /api/archivo/examenes
     * Actualiza estatus, resultado o adjunto de cualquier examen (asociado o independiente).
     */
    public static function actualizarExamen($input) {
        try {
            $examenId = (int)($input['id'] ?? $input['examen_id'] ?? 0);
            if ($examenId <= 0) {
                http_response_code(400);
                echo json_encode(["status" => "error", "mensaje" => "Debe indicar el 'id' del examen."], JSON_UNESCAPED_UNICODE);
                return;
            }

            $pdo = Database::conectar();
            self::asegurarEsquema($pdo);

            $campos = [];
            $params = [':id' => $examenId];

            if (!empty($input['estado_examen'])) {
                $campos[] = "estado_examen = :estado";
                $params[':estado'] = trim((string)$input['estado_examen']);
            }
            if (isset($input['resultado'])) {
                $campos[] = "resultado = :res";
                $params[':res'] = trim((string)$input['resultado']);
            }
            if (isset($input['observaciones'])) {
                $campos[] = "observaciones = :obs";
                $params[':obs'] = trim((string)$input['observaciones']);
            }
            if (!empty($input['archivo_adjunto_url'])) {
                $campos[] = "archivo_adjunto_url = :url";
                $params[':url'] = trim((string)$input['archivo_adjunto_url']);
            }

            if (empty($campos)) {
                http_response_code(400);
                echo json_encode(["status" => "error", "mensaje" => "No se enviaron campos para actualizar."], JSON_UNESCAPED_UNICODE);
                return;
            }

            $pdo->prepare("UPDATE gm_historias_examenes SET " . implode(", ", $campos) . " WHERE id = :id")
                ->execute($params);

            echo json_encode([
                "status"  => "success",
                "mensaje" => "Examen actualizado correctamente."
            ], JSON_UNESCAPED_UNICODE);

        } catch (Exception $e) {
            http_response_code(500);
            echo json_encode(["status" => "error", "mensaje" => $e->getMessage()], JSON_UNESCAPED_UNICODE);
        }
    }
}