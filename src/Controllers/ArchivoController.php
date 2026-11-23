<?php
namespace App\Controllers;

use App\Config\Database;
use App\Documentos\InformeMedicoPdfGenerator;
use PDO;
use Exception;

class ArchivoController {

    private static bool $esquemaVerificado = false;

    /**
     * Garantiza de forma segura que gm_historias_examenes soporte exámenes
     * asociados a historias, múltiples archivos adjuntos, responsable de carga,
     * observaciones del médico y estudios independientes.
     */
    private static function asegurarEsquema(PDO $pdo): void {
        if (self::$esquemaVerificado) {
            return;
        }
        try {
            $pdo->exec("ALTER TABLE `gm_historias_examenes` MODIFY COLUMN `historia_id` mediumint(9) DEFAULT NULL");
            $pdo->exec("ALTER TABLE `gm_historias_examenes` MODIFY COLUMN `archivo_adjunto_url` text DEFAULT NULL");

            $columnas = [
                'paciente_id'          => "ALTER TABLE `gm_historias_examenes` ADD COLUMN `paciente_id` bigint(20) DEFAULT NULL AFTER `historia_id`",
                'medico_id'            => "ALTER TABLE `gm_historias_examenes` ADD COLUMN `medico_id` bigint(20) DEFAULT NULL AFTER `paciente_id`",
                'responsable_carga'    => "ALTER TABLE `gm_historias_examenes` ADD COLUMN `responsable_carga` varchar(30) NOT NULL DEFAULT 'medico' AFTER `tipo_examen`",
                'observaciones'        => "ALTER TABLE `gm_historias_examenes` ADD COLUMN `observaciones` text DEFAULT NULL AFTER `descripcion_solicitud`",
                'observaciones_dr'     => "ALTER TABLE `gm_historias_examenes` ADD COLUMN `observaciones_dr` text DEFAULT NULL AFTER `observaciones`",
                'observaciones_medico' => "ALTER TABLE `gm_historias_examenes` ADD COLUMN `observaciones_medico` text DEFAULT NULL AFTER `observaciones_dr`",
                'fecha_examen'         => "ALTER TABLE `gm_historias_examenes` ADD COLUMN `fecha_examen` date DEFAULT NULL AFTER `archivo_adjunto_url`",
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
     * Normaliza el responsable de carga ('medico' o 'paciente').
     */
    private static function normalizarResponsableCarga($valor): string {
        $v = strtolower(trim((string)($valor ?? 'medico')));
        return $v === 'paciente' ? 'paciente' : 'medico';
    }

    /**
     * Decodifica a array las rutas almacenadas previamente en archivo_adjunto_url.
     */
    private static function decodificarRutasExistentes($raw): array {
        if (empty($raw)) {
            return [];
        }
        if (is_array($raw)) {
            return array_values(array_filter(array_map('strval', $raw)));
        }
        $str = trim((string)$raw);
        if ($str === '') {
            return [];
        }
        if (strpos($str, '[') === 0) {
            $dec = json_decode($str, true);
            if (is_array($dec)) {
                return array_values(array_filter(array_map('strval', $dec)));
            }
        }
        return [$str];
    }

    /**
     * Codifica la lista de rutas para persistir en archivo_adjunto_url.
     */
    private static function codificarRutas(array $rutas): ?string {
        $limpias = [];
        foreach ($rutas as $r) {
            $v = trim((string)$r);
            if ($v !== '' && !in_array($v, $limpias, true)) {
                $limpias[] = $v;
            }
        }
        if (count($limpias) === 0) {
            return null;
        }
        if (count($limpias) === 1) {
            return $limpias[0];
        }
        return json_encode($limpias, JSON_UNESCAPED_SLASHES);
    }

    /**
     * Elimina archivos físicos de /public/uploads/examenes/ si ya no se utilizan.
     */
    private static function eliminarArchivosFisicos(array $rutas): void {
        $basePublic = realpath(__DIR__ . '/../../public');
        if (!$basePublic) {
            return;
        }
        foreach ($rutas as $ruta) {
            $str = trim((string)$ruta);
            if (preg_match('#(/uploads/examenes/[^?\s]+)#', $str, $m)) {
                $fullPath = $basePublic . $m[1];
                if (is_file($fullPath)) {
                    @unlink($fullPath);
                }
            }
        }
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

        // 1. Procesar archivos enviados en Base64 dentro del JSON
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

        // 2. Multipart/form-data ($_FILES)
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
     * Normaliza las filas de exámenes para asegurar observaciones_medico y responsable_carga consistentes.
     */
    private static function enriquecerFilaExamen(array $ex): array {
        $obsMed = trim((string)($ex['observaciones_medico'] ?? ''));
        if ($obsMed === '') {
            $obsMed = trim((string)($ex['observaciones_dr'] ?? ''));
        }
        if ($obsMed === '') {
            $obsMed = trim((string)($ex['observaciones'] ?? ''));
        }
        $ex['observaciones_medico'] = $obsMed;
        $ex['observaciones_dr']     = $obsMed;
        $ex['observaciones']        = $obsMed;
        $ex['responsable_carga']    = self::normalizarResponsableCarga($ex['responsable_carga'] ?? 'medico');
        return $ex;
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
                            p.peso AS paciente_peso,
                            p.estatura AS paciente_estatura,
                            p.ocupacion AS paciente_ocupacion,
                            p.direccion_detalle AS paciente_direccion,
                            p.email AS paciente_email,
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
                $exsRaw = $stmtExamPorHist->fetchAll(PDO::FETCH_ASSOC);
                $c['examenes'] = array_map([self::class, 'enriquecerFilaExamen'], $exsRaw);

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

            // 2. Exámenes y estudios sin historia/consulta asociada
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
            $examenesIndependientes = array_map([self::class, 'enriquecerFilaExamen'], $stmtEx->fetchAll(PDO::FETCH_ASSOC));

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
     */
    public static function crearExamen($input = []) {
        try {
            $pdo = Database::conectar();
            self::asegurarEsquema($pdo);

            $datos = self::obtenerDatosRequest($input);

            $medicoId         = (int)($datos['medico_id'] ?? 0);
            $pacienteId       = (int)($datos['paciente_id'] ?? 0);
            $historiaId       = !empty($datos['historia_id']) ? (int)$datos['historia_id'] : 0;
            $tipoExamen       = trim((string)($datos['tipo_examen'] ?? $datos['tipo'] ?? ''));
            $responsableCarga = self::normalizarResponsableCarga($datos['responsable_carga'] ?? 'medico');
            $descripcion      = trim((string)($datos['descripcion_solicitud'] ?? $datos['descripcion'] ?? ''));

            $obsMedico = trim((string)($datos['observaciones_medico'] ?? $datos['observaciones_dr'] ?? $datos['observaciones'] ?? ''));
            $resultado = trim((string)($datos['resultado'] ?? ''));
            $estado    = trim((string)($datos['estado_examen'] ?? $datos['estatus'] ?? 'Pendiente'));
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

            // Procesar uno o varios archivos adjuntos (si el responsable es médico)
            $rutasSubidas = ($responsableCarga === 'paciente')
                ? []
                : self::guardarArchivosAdjuntos($historiaId, $pacienteId, $datos);

            $archivoUrl = !empty($datos['archivo_adjunto_url']) ? trim((string)$datos['archivo_adjunto_url']) : null;
            if (!empty($rutasSubidas)) {
                $archivoUrl = self::codificarRutas($rutasSubidas);
            }

            $sql = "INSERT INTO gm_historias_examenes 
                    (historia_id, paciente_id, medico_id, tipo_examen, responsable_carga, descripcion_solicitud, observaciones, observaciones_dr, observaciones_medico, resultado, estado_examen, archivo_adjunto_url, fecha_examen)
                    VALUES 
                    (:hid, :pac, :med, :tipo, :resp, :desc, :obs, :obs_dr, :obs_med, :res, :estado, :url, :fecha)";

            $stmt = $pdo->prepare($sql);
            $stmt->execute([
                ':hid'     => $historiaId > 0 ? $historiaId : null,
                ':pac'     => $pacienteId,
                ':med'     => $medicoId,
                ':tipo'    => $tipoExamen,
                ':resp'    => $responsableCarga,
                ':desc'    => $descripcion !== '' ? $descripcion : null,
                ':obs'     => $obsMedico !== '' ? $obsMedico : null,
                ':obs_dr'  => $obsMedico !== '' ? $obsMedico : null,
                ':obs_med' => $obsMedico !== '' ? $obsMedico : null,
                ':res'     => $resultado !== '' ? $resultado : ($obsMedico !== '' ? $obsMedico : null),
                ':estado'  => $estado !== '' ? $estado : 'Pendiente',
                ':url'     => $archivoUrl,
                ':fecha'   => preg_match('/^\d{4}-\d{2}-\d{2}$/', $fechaExamen) ? $fechaExamen : date('Y-m-d'),
            ]);

            $nuevoId = (int)$pdo->lastInsertId();

            http_response_code(201);
            echo json_encode([
                "status"              => "success",
                "mensaje"             => $historiaId > 0
                    ? "Examen asociado a la consulta #$historiaId registrado correctamente."
                    : "Estudio registrado correctamente en el archivo.",
                "examen_id"           => $nuevoId,
                "historia_id"         => $historiaId > 0 ? $historiaId : null,
                "responsable_carga"   => $responsableCarga,
                "archivo_adjunto_url" => $archivoUrl,
                "archivos"            => $rutasSubidas
            ], JSON_UNESCAPED_UNICODE);

        } catch (Exception $e) {
            http_response_code(500);
            echo json_encode(["status" => "error", "mensaje" => $e->getMessage()], JSON_UNESCAPED_UNICODE);
        }
    }

    /**
     * PUT /api/archivo/examenes/{id}  |  POST /api/archivo/examenes/{id}  |  PUT /api/archivo/examenes
     */
    public static function actualizarExamen($input = [], int $idRuta = 0) {
        try {
            $datos = self::obtenerDatosRequest($input);
            $examenId = $idRuta > 0 ? $idRuta : (int)($datos['id'] ?? $datos['examen_id'] ?? 0);

            if ($examenId <= 0) {
                http_response_code(400);
                echo json_encode(["status" => "error", "mensaje" => "Debe indicar el ID del examen a actualizar."], JSON_UNESCAPED_UNICODE);
                return;
            }

            $pdo = Database::conectar();
            self::asegurarEsquema($pdo);

            $stmtActual = $pdo->prepare("SELECT * FROM gm_historias_examenes WHERE id = :id LIMIT 1");
            $stmtActual->execute([':id' => $examenId]);
            $examenActual = $stmtActual->fetch(PDO::FETCH_ASSOC);

            if (!$examenActual) {
                http_response_code(404);
                echo json_encode(["status" => "error", "mensaje" => "El examen #$examenId no existe."], JSON_UNESCAPED_UNICODE);
                return;
            }

            $campos = [];
            $params = [':id' => $examenId];

            if (array_key_exists('historia_id', $datos)) {
                $hid = (int)$datos['historia_id'];
                $campos[] = "historia_id = :hid";
                $params[':hid'] = $hid > 0 ? $hid : null;
            }
            if (!empty($datos['tipo_examen']) || !empty($datos['tipo'])) {
                $campos[] = "tipo_examen = :tipo";
                $params[':tipo'] = trim((string)($datos['tipo_examen'] ?? $datos['tipo']));
            }
            if (isset($datos['responsable_carga'])) {
                $campos[] = "responsable_carga = :resp";
                $params[':resp'] = self::normalizarResponsableCarga($datos['responsable_carga']);
            }
            if (isset($datos['descripcion_solicitud']) || isset($datos['descripcion'])) {
                $descVal = trim((string)($datos['descripcion_solicitud'] ?? $datos['descripcion'] ?? ''));
                $campos[] = "descripcion_solicitud = :desc";
                $params[':desc'] = $descVal !== '' ? $descVal : null;
            }
            if (!empty($datos['estado_examen']) || !empty($datos['estatus'])) {
                $campos[] = "estado_examen = :estado";
                $params[':estado'] = trim((string)($datos['estado_examen'] ?? $datos['estatus']));
            }
            if (isset($datos['resultado'])) {
                $resVal = trim((string)$datos['resultado']);
                $campos[] = "resultado = :res";
                $params[':res'] = $resVal !== '' ? $resVal : null;
            }
            if (isset($datos['observaciones_medico']) || isset($datos['observaciones_dr']) || isset($datos['observaciones'])) {
                $obsVal = trim((string)($datos['observaciones_medico'] ?? $datos['observaciones_dr'] ?? $datos['observaciones'] ?? ''));
                $obsLimpia = $obsVal !== '' ? $obsVal : null;
                $campos[] = "observaciones = :obs";
                $campos[] = "observaciones_dr = :obs_dr";
                $campos[] = "observaciones_medico = :obs_med";
                $params[':obs']     = $obsLimpia;
                $params[':obs_dr']  = $obsLimpia;
                $params[':obs_med'] = $obsLimpia;
            }
            if (!empty($datos['fecha_examen']) && preg_match('/^\d{4}-\d{2}-\d{2}$/', trim((string)$datos['fecha_examen']))) {
                $campos[] = "fecha_examen = :fecha";
                $params[':fecha'] = trim((string)$datos['fecha_examen']);
            }

            // Gestión de archivos: conservar los que no fueron eliminados en UI + nuevos subidos
            $hidParaArchivo = (int)($datos['historia_id'] ?? $examenActual['historia_id'] ?? 0);
            $pacParaArchivo = (int)($datos['paciente_id'] ?? $examenActual['paciente_id'] ?? 0);
            $rutasNuevas = self::guardarArchivosAdjuntos($hidParaArchivo, $pacParaArchivo, $datos);

            $seGestionanArchivos = array_key_exists('archivos_existentes', $datos)
                || !empty($rutasNuevas)
                || array_key_exists('archivo_adjunto_url', $datos);

            if ($seGestionanArchivos) {
                $rutasPreviasBD = self::decodificarRutasExistentes($examenActual['archivo_adjunto_url'] ?? '');
                $rutasConservadas = [];

                if (array_key_exists('archivos_existentes', $datos)) {
                    $rawExist = $datos['archivos_existentes'];
                    if (is_string($rawExist)) {
                        $dec = json_decode($rawExist, true);
                        $rutasConservadas = is_array($dec) ? $dec : self::decodificarRutasExistentes($rawExist);
                    } elseif (is_array($rawExist)) {
                        $rutasConservadas = $rawExist;
                    }
                    $rutasConservadas = array_map(function ($u) {
                        $str = trim((string)$u);
                        if (preg_match('#(/uploads/examenes/[^?\s]+)#', $str, $m)) {
                            return $m[1];
                        }
                        return $str;
                    }, $rutasConservadas);

                    // Eliminar del disco los archivos previos que el médico quitó al editar
                    $rutasEliminadas = array_diff($rutasPreviasBD, $rutasConservadas);
                    if (!empty($rutasEliminadas)) {
                        self::eliminarArchivosFisicos($rutasEliminadas);
                    }
                } else {
                    $rutasConservadas = $rutasPreviasBD;
                }

                $todasLasRutas = array_merge($rutasConservadas, $rutasNuevas);
                $urlFinal = self::codificarRutas($todasLasRutas);
                if (empty($todasLasRutas) && !empty($datos['archivo_adjunto_url'])) {
                    $urlFinal = trim((string)$datos['archivo_adjunto_url']);
                }

                $campos[] = "archivo_adjunto_url = :url";
                $params[':url'] = $urlFinal;
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

    /**
     * DELETE /api/archivo/examenes/{id}  |  DELETE /api/archivo/examenes?id=X
     */
    public static function eliminarExamen(int $idRuta = 0) {
        try {
            $datos = self::obtenerDatosRequest([]);
            $examenId = $idRuta > 0
                ? $idRuta
                : (int)($_GET['id'] ?? $_GET['examen_id'] ?? $datos['id'] ?? $datos['examen_id'] ?? 0);

            if ($examenId <= 0) {
                http_response_code(400);
                echo json_encode(["status" => "error", "mensaje" => "Debe indicar el ID del examen a eliminar."], JSON_UNESCAPED_UNICODE);
                return;
            }

            $pdo = Database::conectar();
            self::asegurarEsquema($pdo);

            $stmtCheck = $pdo->prepare("SELECT id, archivo_adjunto_url FROM gm_historias_examenes WHERE id = :id LIMIT 1");
            $stmtCheck->execute([':id' => $examenId]);
            $examen = $stmtCheck->fetch(PDO::FETCH_ASSOC);

            if (!$examen) {
                http_response_code(404);
                echo json_encode(["status" => "error", "mensaje" => "El examen #$examenId no existe o ya fue eliminado."], JSON_UNESCAPED_UNICODE);
                return;
            }

            // Borrar archivos físicos asociados
            $rutas = self::decodificarRutasExistentes($examen['archivo_adjunto_url'] ?? '');
            if (!empty($rutas)) {
                self::eliminarArchivosFisicos($rutas);
            }

            $pdo->prepare("DELETE FROM gm_historias_examenes WHERE id = :id")
                ->execute([':id' => $examenId]);

            echo json_encode([
                "status"  => "success",
                "mensaje" => "Examen eliminado correctamente."
            ], JSON_UNESCAPED_UNICODE);

        } catch (Exception $e) {
            http_response_code(500);
            echo json_encode(["status" => "error", "mensaje" => $e->getMessage()], JSON_UNESCAPED_UNICODE);
        }
    }

    /**
     * PUT /api/historias  y  PUT /api/archivo/historias
     */
    public static function actualizarHistoria($input = []) {
        try {
            $datos = self::obtenerDatosRequest($input);
            $historiaId = (int)($datos['id'] ?? $datos['historia_id'] ?? 0);
            if ($historiaId <= 0) {
                http_response_code(400);
                echo json_encode(["status" => "error", "mensaje" => "Debe indicar el 'id' de la historia médica."], JSON_UNESCAPED_UNICODE);
                return;
            }

            $pdo = Database::conectar();

            $stmtCheck = $pdo->prepare("SELECT * FROM gm_historias_medicas WHERE id = :id LIMIT 1");
            $stmtCheck->execute([':id' => $historiaId]);
            $histActual = $stmtCheck->fetch(PDO::FETCH_ASSOC);

            if (!$histActual) {
                http_response_code(404);
                echo json_encode(["status" => "error", "mensaje" => "La historia médica #$historiaId no existe."], JSON_UNESCAPED_UNICODE);
                return;
            }

            $camposEditables = [
                'motivo_consulta',
                'antecedentes_personales',
                'habitos_psicobiologicos',
                'antecedentes_familiares',
                'antecedentes_quirurgicos',
                'menarquia',
                'sexarquia',
                'ciclos_menstruales',
                'fur',
                'activa_sexual',
                'num_parejas',
                'gt',
                'pa',
                'ab',
                'cs',
                'metodos_acos',
                'papanicolaou',
                'tension_arterial',
                'frecuencia_cardiaca',
                'examen_mamario',
                'examen_pelvico',
                'diagnostico',
                'plan_medico',
                'tratamiento',
                'notas',
                'estado_historia',
            ];

            $sets = [];
            $params = [':id' => $historiaId];

            foreach ($camposEditables as $col) {
                if (array_key_exists($col, $datos)) {
                    $sets[] = "`$col` = :$col";
                    $val = $datos[$col];
                    $params[":$col"] = ($val === null) ? null : trim((string)$val);
                }
            }

            if (empty($sets)) {
                http_response_code(400);
                echo json_encode(["status" => "error", "mensaje" => "No se enviaron campos clínicos para actualizar."], JSON_UNESCAPED_UNICODE);
                return;
            }

            $pdo->prepare("UPDATE gm_historias_medicas SET " . implode(", ", $sets) . " WHERE id = :id")
                ->execute($params);

            $pacId = (int)$histActual['paciente_id'];
            if ($pacId > 0 && (isset($datos['peso_actual']) || isset($datos['peso']) || isset($datos['estatura_actual']) || isset($datos['estatura']))) {
                $peso = (float)($datos['peso_actual'] ?? $datos['peso'] ?? 0);
                $estatura = trim((string)($datos['estatura_actual'] ?? $datos['estatura'] ?? ''));
                if ($peso > 0 || $estatura !== '') {
                    $updPac = [];
                    $pPac = [':uid' => $pacId];
                    if ($peso > 0) {
                        $updPac[] = "peso = :peso";
                        $pPac[':peso'] = $peso;
                    }
                    if ($estatura !== '') {
                        $updPac[] = "estatura = :est";
                        $pPac[':est'] = $estatura;
                    }
                    if (!empty($updPac)) {
                        $pdo->prepare("UPDATE gm_usuarios_extendidos SET " . implode(", ", $updPac) . " WHERE user_id = :uid")
                            ->execute($pPac);
                    }
                }
            }

            if (class_exists(InformeMedicoPdfGenerator::class) && method_exists(InformeMedicoPdfGenerator::class, 'generar')) {
                try {
                    $nuevoPdf = InformeMedicoPdfGenerator::generar($pdo, $historiaId);
                    if (!empty($nuevoPdf)) {
                        $pdo->prepare("UPDATE gm_historias_medicas SET pdf_path = :p, url_informe_pdf = :p WHERE id = :id")
                            ->execute([':p' => $nuevoPdf, ':id' => $historiaId]);
                    }
                } catch (Exception $ePdf) {
                    // Continuar aunque el regenerado PDF falle
                }
            }

            echo json_encode([
                "status"      => "success",
                "mensaje"     => "Consulta médica #$historiaId actualizada correctamente.",
                "historia_id" => $historiaId
            ], JSON_UNESCAPED_UNICODE);

        } catch (Exception $e) {
            http_response_code(500);
            echo json_encode(["status" => "error", "mensaje" => $e->getMessage()], JSON_UNESCAPED_UNICODE);
        }
    }
}