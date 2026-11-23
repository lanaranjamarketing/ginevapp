<?php
namespace App\Controllers;

use App\Config\Database;
use PDO;
use Exception;

class ArchivoController {

    private static bool $esquemaVerificado = false;

    /**
     * Garantiza de forma segura que gm_historias_examenes soporte exámenes
     * asociados a historias, múltiples archivos adjuntos y estudios independientes.
     */
    private static function asegurarEsquema(PDO $pdo): void {
        if (self::$esquemaVerificado) {
            return;
        }
        try {
            $pdo->exec("ALTER TABLE `gm_historias_examenes` MODIFY COLUMN `historia_id` mediumint(9) DEFAULT NULL");
            $pdo->exec("ALTER TABLE `gm_historias_examenes` MODIFY COLUMN `archivo_adjunto_url` text DEFAULT NULL");

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
     * Unifica los parámetros recibidos tanto por JSON (php://input) como por multipart/form-data ($_POST).
     */
    private static function obtenerDatosRequest($input): array {
        $datos = is_array($input) ? $input : [];
        if (!empty($_POST)) {
            $datos = array_merge($datos, $_POST);
        }
        if (empty($datos)) {
            $raw = file_get_contents('php://input');
            if ($raw) {
                $decoded = json_decode($raw, true);
                if (is_array($decoded)) {
                    $datos = $decoded;
                }
            }
        }
        return $datos;
    }

    /**
     * Procesa archivos adjuntos enviados en Base64 dentro del JSON ('archivos_base64')
     * o mediante $_FILES ('archivos[]', 'archivo', 'file') y los guarda en /public/uploads/examenes/.
     */
    private static function guardarArchivosAdjuntos(int $historiaId, int $pacienteId, array $datos = []): array {
        @ini_set('upload_max_filesize', '250M');
        @ini_set('post_max_size', '255M');
        @ini_set('memory_limit', '512M');
        @ini_set('max_execution_time', '300');
        @ini_set('max_input_time', '300');

        $uploadDir = __DIR__ . '/../../public/uploads/examenes/';
        if (!is_dir($uploadDir)) {
            @mkdir($uploadDir, 0775, true);
        }

        $maxBytes = 250 * 1024 * 1024; // 250 MB
        $prefijo  = $historiaId > 0 ? ('exam_h' . $historiaId) : ('exam_ind_p' . $pacienteId);
        $rutasGuardadas = [];

        // 1. Procesar archivos enviados en Base64 dentro del JSON (sin multipart/form-data)
        if (!empty($datos['archivos_base64']) && is_array($datos['archivos_base64'])) {
            foreach ($datos['archivos_base64'] as $idx => $item) {
                if (!is_array($item) || empty($item['data'])) {
                    continue;
                }
                $nombreOrig = (string)($item['name'] ?? ('archivo_' . $idx . '.pdf'));
                $b64 = (string)$item['data'];
                if (strpos($b64, 'base64,') !== false) {
                    $b64 = explode('base64,', $b64, 2)[1];
                }
                $binario = base64_decode($b64, true);
                if ($binario === false || strlen($binario) === 0 || strlen($binario) > $maxBytes) {
                    continue;
                }

                $ext = strtolower(pathinfo($nombreOrig, PATHINFO_EXTENSION) ?: 'pdf');
                $extLimpia = preg_replace('/[^a-z0-9]/', '', $ext) ?: 'pdf';
                $fileName = $prefijo . '_' . time() . '_' . $idx . '_' . mt_rand(100, 999) . '.' . $extLimpia;
                $destino  = $uploadDir . $fileName;

                if (file_put_contents($destino, $binario) !== false) {
                    $rutasGuardadas[] = '/uploads/examenes/' . $fileName;
                }
            }
        }

        // 2. Respaldo para multipart/form-data tradicional ($_FILES)
        if (!empty($_FILES)) {
            $archivosParaProcesar = [];

            if (isset($_FILES['archivos']) && is_array($_FILES['archivos']['name'])) {
                $total = count($_FILES['archivos']['name']);
                for ($i = 0; $i < $total; $i++) {
                    $archivosParaProcesar[] = [
                        'name'     => $_FILES['archivos']['name'][$i] ?? '',
                        'tmp_name' => $_FILES['archivos']['tmp_name'][$i] ?? '',
                        'error'    => $_FILES['archivos']['error'][$i] ?? UPLOAD_ERR_NO_FILE,
                        'size'     => (int)($_FILES['archivos']['size'][$i] ?? 0),
                    ];
                }
            } else {
                $fileKey = isset($_FILES['archivo']) ? 'archivo' : (isset($_FILES['file']) ? 'file' : null);
                if ($fileKey && isset($_FILES[$fileKey]['name'])) {
                    $archivosParaProcesar[] = [
                        'name'     => $_FILES[$fileKey]['name'],
                        'tmp_name' => $_FILES[$fileKey]['tmp_name'],
                        'error'    => $_FILES[$fileKey]['error'],
                        'size'     => (int)($_FILES[$fileKey]['size'] ?? 0),
                    ];
                }
            }

            foreach ($archivosParaProcesar as $idx => $item) {
                if (($item['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
                    continue;
                }
                if (($item['size'] ?? 0) > $maxBytes || empty($item['tmp_name'])) {
                    continue;
                }

                $ext = strtolower(pathinfo((string)$item['name'], PATHINFO_EXTENSION) ?: 'pdf');
                $extLimpia = preg_replace('/[^a-z0-9]/', '', $ext) ?: 'pdf';
                $fileName = $prefijo . '_' . time() . '_m' . $idx . '_' . mt_rand(100, 999) . '.' . $extLimpia;
                $destino  = $uploadDir . $fileName;

                if (move_uploaded_file($item['tmp_name'], $destino)) {
                    $rutasGuardadas[] = '/uploads/examenes/' . $fileName;
                }
            }
        }

        return $rutasGuardadas;
    }

    /**
     * GET /api/archivo?medico_id=X&paciente_id=Y
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
     * Soporta application/json (con o sin archivos_base64) y multipart/form-data.
     */
    public static function crearExamen($input = []) {
        try {
            $pdo = Database::conectar();
            self::asegurarEsquema($pdo);

            $datos = self::obtenerDatosRequest($input);

            $medicoId    = (int)($datos['medico_id'] ?? 0);
            $pacienteId  = (int)($datos['paciente_id'] ?? 0);
            $historiaId  = !empty($datos['historia_id']) ? (int)$datos['historia_id'] : 0;
            $tipoExamen  = trim((string)($datos['tipo_examen'] ?? $datos['tipo'] ?? ''));
            $descripcion = trim((string)($datos['descripcion_solicitud'] ?? $datos['descripcion'] ?? ''));
            $observacion = trim((string)($datos['observaciones'] ?? ''));
            $resultado   = trim((string)($datos['resultado'] ?? ''));
            $estado      = trim((string)($datos['estado_examen'] ?? $datos['estatus'] ?? 'Pendiente'));
            $fechaExamen = trim((string)($datos['fecha_examen'] ?? date('Y-m-d')));

            if ($tipoExamen === '') {
                http_response_code(400);
                echo json_encode([
                    "status"  => "error",
                    "mensaje" => "El tipo de examen o estudio es obligatorio."
                ], JSON_UNESCAPED_UNICODE);
                return;
            }

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

            // Procesar uno o varios archivos adjuntos (Base64 en JSON o multipart/form-data)
            $rutasSubidas = self::guardarArchivosAdjuntos($historiaId, $pacienteId, $datos);
            $archivoUrl = !empty($datos['archivo_adjunto_url']) ? trim((string)$datos['archivo_adjunto_url']) : null;

            if (count($rutasSubidas) === 1) {
                $archivoUrl = $rutasSubidas[0];
            } elseif (count($rutasSubidas) > 1) {
                $archivoUrl = json_encode($rutasSubidas, JSON_UNESCAPED_SLASHES);
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
                "archivo_adjunto_url" => $archivoUrl,
                "archivos"            => $rutasSubidas
            ], JSON_UNESCAPED_UNICODE);

        } catch (Exception $e) {
            http_response_code(500);
            echo json_encode(["status" => "error", "mensaje" => $e->getMessage()], JSON_UNESCAPED_UNICODE);
        }
    }

    /**
     * PUT /api/archivo/examenes
     */
    public static function actualizarExamen($input = []) {
        try {
            $datos = self::obtenerDatosRequest($input);
            $examenId = (int)($datos['id'] ?? $datos['examen_id'] ?? 0);
            if ($examenId <= 0) {
                http_response_code(400);
                echo json_encode(["status" => "error", "mensaje" => "Debe indicar el 'id' del examen."], JSON_UNESCAPED_UNICODE);
                return;
            }

            $pdo = Database::conectar();
            self::asegurarEsquema($pdo);

            $campos = [];
            $params = [':id' => $examenId];

            if (!empty($datos['estado_examen'])) {
                $campos[] = "estado_examen = :estado";
                $params[':estado'] = trim((string)$datos['estado_examen']);
            }
            if (isset($datos['resultado'])) {
                $campos[] = "resultado = :res";
                $params[':res'] = trim((string)$datos['resultado']);
            }
            if (isset($datos['observaciones'])) {
                $campos[] = "observaciones = :obs";
                $params[':obs'] = trim((string)$datos['observaciones']);
            }
            if (!empty($datos['archivo_adjunto_url'])) {
                $campos[] = "archivo_adjunto_url = :url";
                $params[':url'] = trim((string)$datos['archivo_adjunto_url']);
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