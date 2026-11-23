<?php
namespace App\Controllers;

use App\Config\Database;
use App\Documentos\InformeMedicoPdfGenerator;
use PDO;
use Exception;

class HistoriaController {

    /**
     * Asegura que gm_historias_medicas y gm_historias_examenes tengan las columnas requeridas
     */
    private static function asegurarEsquemaHistorias(PDO $pdo): void {
        try {
            $cols = $pdo->query("SHOW COLUMNS FROM gm_historias_medicas")->fetchAll(PDO::FETCH_COLUMN);
            $requeridas = [
                'cita_id'                 => "ALTER TABLE gm_historias_medicas ADD COLUMN cita_id INT NULL AFTER medico_id",
                'motivo_consulta'         => "ALTER TABLE gm_historias_medicas ADD COLUMN motivo_consulta TEXT NULL",
                'antecedentes_personales' => "ALTER TABLE gm_historias_medicas ADD COLUMN antecedentes_personales TEXT NULL",
                'habitos_psicobiologicos' => "ALTER TABLE gm_historias_medicas ADD COLUMN habitos_psicobiologicos TEXT NULL",
                'antecedentes_familiares' => "ALTER TABLE gm_historias_medicas ADD COLUMN antecedentes_familiares TEXT NULL",
                'antecedentes_quirurgicos'=> "ALTER TABLE gm_historias_medicas ADD COLUMN antecedentes_quirurgicos TEXT NULL",
                'menarquia'               => "ALTER TABLE gm_historias_medicas ADD COLUMN menarquia VARCHAR(50) NULL",
                'sexarquia'               => "ALTER TABLE gm_historias_medicas ADD COLUMN sexarquia VARCHAR(50) NULL",
                'ciclos_menstruales'      => "ALTER TABLE gm_historias_medicas ADD COLUMN ciclos_menstruales VARCHAR(50) NULL",
                'fur'                     => "ALTER TABLE gm_historias_medicas ADD COLUMN fur VARCHAR(50) NULL",
                'activa_sexual'           => "ALTER TABLE gm_historias_medicas ADD COLUMN activa_sexual VARCHAR(20) NULL",
                'num_parejas'             => "ALTER TABLE gm_historias_medicas ADD COLUMN num_parejas VARCHAR(20) NULL",
                'gt'                      => "ALTER TABLE gm_historias_medicas ADD COLUMN gt VARCHAR(20) NULL",
                'pa'                      => "ALTER TABLE gm_historias_medicas ADD COLUMN pa VARCHAR(20) NULL",
                'ab'                      => "ALTER TABLE gm_historias_medicas ADD COLUMN ab VARCHAR(20) NULL",
                'cs'                      => "ALTER TABLE gm_historias_medicas ADD COLUMN cs VARCHAR(20) NULL",
                'metodos_acos'            => "ALTER TABLE gm_historias_medicas ADD COLUMN metodos_acos VARCHAR(150) NULL",
                'papanicolaou'            => "ALTER TABLE gm_historias_medicas ADD COLUMN papanicolaou VARCHAR(100) NULL",
                'tension_arterial'        => "ALTER TABLE gm_historias_medicas ADD COLUMN tension_arterial VARCHAR(50) NULL",
                'frecuencia_cardiaca'     => "ALTER TABLE gm_historias_medicas ADD COLUMN frecuencia_cardiaca VARCHAR(50) NULL",
                'examen_mamario'          => "ALTER TABLE gm_historias_medicas ADD COLUMN examen_mamario TEXT NULL",
                'examen_pelvico'          => "ALTER TABLE gm_historias_medicas ADD COLUMN examen_pelvico TEXT NULL",
                'plan_medico'             => "ALTER TABLE gm_historias_medicas ADD COLUMN plan_medico TEXT NULL",
                'tratamiento'             => "ALTER TABLE gm_historias_medicas ADD COLUMN tratamiento TEXT NULL",
                'notas'                   => "ALTER TABLE gm_historias_medicas ADD COLUMN notas TEXT NULL",
                'estado_historia'         => "ALTER TABLE gm_historias_medicas ADD COLUMN estado_historia VARCHAR(50) DEFAULT 'Completada'",
                'hash_validacion'         => "ALTER TABLE gm_historias_medicas ADD COLUMN hash_validacion VARCHAR(64) NULL",
                'pdf_path'                => "ALTER TABLE gm_historias_medicas ADD COLUMN pdf_path VARCHAR(255) NULL",
                'url_informe_pdf'         => "ALTER TABLE gm_historias_medicas ADD COLUMN url_informe_pdf VARCHAR(255) NULL",
            ];
            foreach ($requeridas as $col => $sqlAlter) {
                if (!in_array($col, $cols, true)) {
                    try {
                        $pdo->exec($sqlAlter);
                    } catch (Exception $e) {}
                }
            }
        } catch (Exception $e) {}
    }

    /**
     * GET /api/historias?paciente_id=X&medico_id=Y
     * Lista las historias médicas junto con sus exámenes complementarios asociados
     */
    public static function listar() {
        try {
            $pdo = Database::conectar();
            $condiciones = ["1=1"];
            $params = [];

            if (!empty($_GET['paciente_id'])) {
                $condiciones[] = "h.paciente_id = :pac";
                $params[':pac'] = (int)$_GET['paciente_id'];
            }

            if (!empty($_GET['medico_id'])) {
                $condiciones[] = "h.medico_id = :med";
                $params[':med'] = (int)$_GET['medico_id'];
            }

            $whereSql = implode(" AND ", $condiciones);

            $sql = "SELECT 
                        h.*,
                        CONCAT(p.nombre, ' ', p.apellido) AS paciente_nombre,
                        p.cedula AS paciente_cedula,
                        p.sexo AS paciente_sexo,
                        p.edad AS paciente_edad,
                        CONCAT(m.nombre, ' ', m.apellido) AS medico_nombre,
                        m.especialidad AS medico_especialidad,
                        m.mpps AS medico_mpps,
                        m.cmm AS medico_cmm
                    FROM gm_historias_medicas h
                    LEFT JOIN gm_usuarios_extendidos p ON h.paciente_id = p.user_id
                    LEFT JOIN gm_usuarios_extendidos m ON h.medico_id = m.user_id
                    WHERE $whereSql
                    ORDER BY h.fecha_registro DESC";

            $stmt = $pdo->prepare($sql);
            $stmt->execute($params);
            $historias = $stmt->fetchAll(PDO::FETCH_ASSOC);

            $examStmt = $pdo->prepare("SELECT * FROM gm_historias_examenes WHERE historia_id = :hid ORDER BY id ASC");
            foreach ($historias as &$hist) {
                $examStmt->execute([':hid' => $hist['id']]);
                $hist['examenes'] = $examStmt->fetchAll(PDO::FETCH_ASSOC);
                $pdfUrl = !empty($hist['url_informe_pdf'])
                    ? $hist['url_informe_pdf']
                    : (!empty($hist['url_pdf_informe'])
                        ? $hist['url_pdf_informe']
                        : (!empty($hist['pdf_path']) ? $hist['pdf_path'] : '/api/historias/pdf?id=' . $hist['id']));
                $hist['pdf_url']         = $pdfUrl;
                $hist['url_informe_pdf'] = $pdfUrl;
            }
            unset($hist);

            echo json_encode([
                "status" => "success",
                "total"  => count($historias),
                "data"   => $historias
            ], JSON_UNESCAPED_UNICODE);

        } catch (Exception $e) {
            http_response_code(500);
            echo json_encode(["status" => "error", "mensaje" => $e->getMessage()], JSON_UNESCAPED_UNICODE);
        }
    }

    /**
     * GET /api/historias/antecedentes?paciente_id=X
     * Precarga los últimos antecedentes médicos y gineco-obstétricos del paciente
     */
    public static function obtenerUltimosAntecedentes() {
        try {
            $pacienteId = isset($_GET['paciente_id']) ? (int)$_GET['paciente_id'] : 0;
            if ($pacienteId <= 0) {
                http_response_code(400);
                echo json_encode(["status" => "error", "mensaje" => "El parámetro paciente_id es obligatorio."], JSON_UNESCAPED_UNICODE);
                return;
            }

            $pdo = Database::conectar();
            $stmt = $pdo->prepare("SELECT 
                    antecedentes_personales, habitos_psicobiologicos, antecedentes_familiares, 
                    antecedentes_quirurgicos, menarquia, sexarquia, ciclos_menstruales, 
                    papanicolaou, num_parejas, metodos_acos, gt, pa, ab, cs 
                FROM gm_historias_medicas 
                WHERE paciente_id = :pac 
                ORDER BY fecha_registro DESC LIMIT 1");
            $stmt->execute([':pac' => $pacienteId]);
            $antecedentes = $stmt->fetch(PDO::FETCH_ASSOC);

            echo json_encode([
                "status" => "success",
                "data"   => $antecedentes ?: null
            ], JSON_UNESCAPED_UNICODE);

        } catch (Exception $e) {
            http_response_code(500);
            echo json_encode(["status" => "error", "mensaje" => $e->getMessage()], JSON_UNESCAPED_UNICODE);
        }
    }

    /**
     * POST /api/historias
     * Guarda la historia clínica completa, exámenes relacionales, finanza BCV,
     * cierra la cita, detiene el cronómetro de atención y genera el informe PDF
     */
    public static function crear($input) {
        $pdo = null;
        try {
            $pacienteId = (int)($input['paciente_id'] ?? 0);
            $medicoId   = (int)($input['medico_id'] ?? 0);
            $citaId     = !empty($input['cita_id']) ? (int)$input['cita_id'] : null;

            $motivoConsulta = trim((string)($input['motivo_consulta'] ?? ''));
            if ($motivoConsulta === '') {
                $motivoConsulta = 'Consulta Médica';
            }
            $diagnostico = trim((string)($input['diagnostico'] ?? ''));

            if ($pacienteId <= 0 || $medicoId <= 0 || $diagnostico === '') {
                http_response_code(400);
                echo json_encode([
                    "status"  => "error",
                    "mensaje" => "Los campos paciente_id, medico_id y diagnostico son obligatorios."
                ], JSON_UNESCAPED_UNICODE);
                return;
            }

            $pdo = Database::conectar();
            self::asegurarEsquemaHistorias($pdo);
            $pdo->beginTransaction();

            // Si no vino cita_id explícito, buscar cita activa del día de hoy para ese paciente y médico
            if (!$citaId) {
                $stmtCitaHoy = $pdo->prepare("
                    SELECT id FROM gm_citas 
                    WHERE medico_id = :med AND paciente_id = :pac AND fecha_cita = CURDATE() 
                      AND LOWER(COALESCE(estado_cita, '')) NOT IN ('cancelada', 'cancelled', 'completada', 'completado') 
                    ORDER BY CASE WHEN LOWER(estado_cita) = 'en_consulta' THEN 0 ELSE 1 END ASC, hora_cita ASC 
                    LIMIT 1
                ");
                $stmtCitaHoy->execute([':med' => $medicoId, ':pac' => $pacienteId]);
                $foundCitaId = (int)$stmtCitaHoy->fetchColumn();
                if ($foundCitaId > 0) {
                    $citaId = $foundCitaId;
                }
            }

            // 1. Actualizar antropometría del paciente si se envió peso o estatura
            $peso     = $input['peso_actual'] ?? $input['peso'] ?? null;
            $estatura = $input['estatura_actual'] ?? $input['estatura'] ?? null;

            if ($peso !== null || $estatura !== null) {
                $updCampos = [];
                $updParams = [':uid' => $pacienteId];
                if ($peso !== null && trim((string)$peso) !== '') {
                    $pesoNum = (float)str_replace(',', '.', (string)$peso);
                    $updCampos[] = "peso = :peso";
                    $updParams[':peso'] = $pesoNum;
                }
                if ($estatura !== null && trim((string)$estatura) !== '') {
                    $updCampos[] = "estatura = :est";
                    $updParams[':est'] = trim((string)$estatura);
                }
                if (!empty($updCampos)) {
                    $pdo->prepare("UPDATE gm_usuarios_extendidos SET " . implode(", ", $updCampos) . " WHERE user_id = :uid")
                        ->execute($updParams);
                }
            }

            // 2. Insertar Historia Clínica
            $sqlHist = "INSERT INTO gm_historias_medicas (
                paciente_id, medico_id, cita_id, motivo_consulta,
                antecedentes_personales, habitos_psicobiologicos, antecedentes_familiares, antecedentes_quirurgicos,
                menarquia, sexarquia, ciclos_menstruales, fur, activa_sexual, num_parejas,
                gt, pa, ab, cs, metodos_acos, papanicolaou,
                tension_arterial, frecuencia_cardiaca, examen_mamario, examen_pelvico,
                diagnostico, plan_medico, tratamiento, notas, estado_historia
            ) VALUES (
                :pac, :med, :cita, :motivo,
                :ant_pers, :habitos, :ant_fam, :ant_quir,
                :menarquia, :sexarquia, :ciclos, :fur, :activa_sex, :num_parejas,
                :gt, :pa, :ab, :cs, :met_acos, :papanicolaou,
                :tension, :fc, :ex_mamario, :ex_pelvico,
                :diagnostico, :plan, :tratamiento, :notas, 'Completada'
            )";

            $stmtHist = $pdo->prepare($sqlHist);
            $stmtHist->execute([
                ':pac'          => $pacienteId,
                ':med'          => $medicoId,
                ':cita'         => $citaId,
                ':motivo'       => $motivoConsulta,
                ':ant_pers'     => $input['antecedentes_personales'] ?? null,
                ':habitos'      => $input['habitos_psicobiologicos'] ?? null,
                ':ant_fam'      => $input['antecedentes_familiares'] ?? null,
                ':ant_quir'     => $input['antecedentes_quirurgicos'] ?? null,
                ':menarquia'    => $input['menarquia'] ?? null,
                ':sexarquia'    => $input['sexarquia'] ?? null,
                ':ciclos'       => $input['ciclos_menstruales'] ?? null,
                ':fur'          => $input['fur'] ?? null,
                ':activa_sex'   => $input['activa_sexual'] ?? null,
                ':num_parejas'  => $input['num_parejas'] ?? null,
                ':gt'           => $input['gt'] ?? null,
                ':pa'           => $input['pa'] ?? null,
                ':ab'           => $input['ab'] ?? null,
                ':cs'           => $input['cs'] ?? null,
                ':met_acos'     => $input['metodos_acos'] ?? null,
                ':papanicolaou' => $input['papanicolaou'] ?? null,
                ':tension'      => $input['tension_arterial'] ?? null,
                ':fc'           => $input['frecuencia_cardiaca'] ?? null,
                ':ex_mamario'   => $input['examen_mamario'] ?? null,
                ':ex_pelvico'   => $input['examen_pelvico'] ?? null,
                ':diagnostico'  => $diagnostico,
                ':plan'         => $input['plan_medico'] ?? null,
                ':tratamiento'  => $input['tratamiento'] ?? null,
                ':notas'        => $input['notas'] ?? null
            ]);

            $historiaId = (int)$pdo->lastInsertId();

            // Generar hash SHA-256 único para validación QR del informe
            $secret = getenv('APP_SECRET') ?: 'ginevapp_qr_secret_2026';
            $hashValidacion = hash('sha256', $historiaId . '|' . $pacienteId . '|' . microtime(true) . '|' . $secret);
            $pdo->prepare("UPDATE gm_historias_medicas SET hash_validacion = :hash WHERE id = :id")
                ->execute([':hash' => $hashValidacion, ':id' => $historiaId]);

            // 3. Registrar Exámenes Complementarios (gm_historias_examenes)
            $examenes = $input['examenes'] ?? [];
            if (is_string($examenes)) {
                $examenes = json_decode($examenes, true) ?? [];
            }

            if (!empty($examenes) && is_array($examenes)) {
                $sqlExam = "INSERT INTO gm_historias_examenes 
                    (historia_id, paciente_id, medico_id, tipo_examen, descripcion_solicitud, resultado, estado_examen, archivo_adjunto_url) 
                    VALUES (:hid, :pac, :med, :tipo, :desc, :res, :estado, :url)";
                $stmtExam = $pdo->prepare($sqlExam);

                foreach ($examenes as $idx => $ex) {
                    if (!is_array($ex) || (empty($ex['tipo']) && empty($ex['tipo_examen']))) {
                        continue;
                    }

                    $archivoUrl = $ex['archivo_adjunto_url'] ?? null;
                    $uploadDir = __DIR__ . '/../../public/uploads/examenes/';

                    $flatKey = 'examenes_archivo_' . $idx;
                    if (isset($_FILES[$flatKey]) && $_FILES[$flatKey]['error'] === UPLOAD_ERR_OK) {
                        if (!is_dir($uploadDir)) {
                            mkdir($uploadDir, 0775, true);
                        }
                        $ext = pathinfo($_FILES[$flatKey]['name'], PATHINFO_EXTENSION);
                        $fileName = 'exam_' . $historiaId . '_' . $idx . '_' . time() . ($ext ? '.' . $ext : '');
                        if (move_uploaded_file($_FILES[$flatKey]['tmp_name'], $uploadDir . $fileName)) {
                            $archivoUrl = '/uploads/examenes/' . $fileName;
                        }
                    } elseif (isset($_FILES['examenes']['name'][$idx]['archivo']) && $_FILES['examenes']['error'][$idx]['archivo'] === UPLOAD_ERR_OK) {
                        if (!is_dir($uploadDir)) {
                            mkdir($uploadDir, 0775, true);
                        }
                        $ext = pathinfo($_FILES['examenes']['name'][$idx]['archivo'], PATHINFO_EXTENSION);
                        $fileName = 'exam_' . $historiaId . '_' . $idx . '_' . time() . ($ext ? '.' . $ext : '');
                        if (move_uploaded_file($_FILES['examenes']['tmp_name'][$idx]['archivo'], $uploadDir . $fileName)) {
                            $archivoUrl = '/uploads/examenes/' . $fileName;
                        }
                    }

                    $obs = $ex['observaciones'] ?? $ex['descripcion_solicitud'] ?? null;
                    $stmtExam->execute([
                        ':hid'    => $historiaId,
                        ':pac'    => $pacienteId,
                        ':med'    => $medicoId,
                        ':tipo'   => trim((string)($ex['tipo'] ?? $ex['tipo_examen'])),
                        ':desc'   => $obs,
                        ':res'    => $ex['resultado'] ?? $obs,
                        ':estado' => trim((string)($ex['estatus'] ?? $ex['estado_examen'] ?? 'Pendiente')),
                        ':url'    => $archivoUrl
                    ]);
                }
            }

            // 4. Registrar Honorarios en gm_finanzas con conversión Tasa BCV
            $montoConsulta = isset($input['monto_consulta']) ? (float)$input['monto_consulta'] : 0.00;
            if ($montoConsulta > 0) {
                $pacStmt = $pdo->prepare("SELECT nombre, apellido FROM gm_usuarios_extendidos WHERE user_id = :uid LIMIT 1");
                $pacStmt->execute([':uid' => $pacienteId]);
                $pacInfo = $pacStmt->fetch(PDO::FETCH_ASSOC);
                $nombrePac = $pacInfo ? trim($pacInfo['nombre'] . ' ' . $pacInfo['apellido']) : 'Paciente';

                $tasaVal = $pdo->query("SELECT tasa_usd FROM gm_tasa_bcv ORDER BY fecha DESC LIMIT 1")->fetchColumn();
                $tasaBcv = ($tasaVal && (float)$tasaVal > 0) ? (float)$tasaVal : 1.0000;

                $debeBs  = round($montoConsulta * $tasaBcv, 2);
                $saldoBs = $debeBs;

                $sqlFin = "INSERT INTO gm_finanzas 
                    (medico_id, paciente_id, historia_id, fecha, concepto, debe, haber, saldo, debe_bs, haber_bs, saldo_bs, tasa_bcv) 
                    VALUES (:med, :pac, :hid, CURDATE(), :concepto, :debe, 0.00, :saldo, :debe_bs, 0.00, :saldo_bs, :tasa)";
                $pdo->prepare($sqlFin)->execute([
                    ':med'      => $medicoId,
                    ':pac'      => $pacienteId,
                    ':hid'      => $historiaId,
                    ':concepto' => 'Honorarios Médicos Consulta de ' . $nombrePac,
                    ':debe'     => $montoConsulta,
                    ':saldo'    => $montoConsulta,
                    ':debe_bs'  => $debeBs,
                    ':saldo_bs' => $saldoBs,
                    ':tasa'     => $tasaBcv
                ]);
            }

            // 5. Marcar la cita asociada como 'completada' y DETENER EL CRONÓMETRO en gm_tiempo_atencion
            if ($citaId) {
                $pdo->prepare("UPDATE gm_citas SET estado_cita = 'completada' WHERE id = :cid")
                    ->execute([':cid' => $citaId]);

                if (class_exists(CitaController::class) && method_exists(CitaController::class, 'finalizarTiempoAtencion')) {
                    CitaController::finalizarTiempoAtencion($pdo, (int)$citaId);
                } else {
                    $stmtT = $pdo->prepare("SELECT id FROM gm_tiempo_atencion WHERE cita_id = :cid AND estado = 'en_proceso' ORDER BY id DESC LIMIT 1");
                    $stmtT->execute([':cid' => $citaId]);
                    $tid = (int)$stmtT->fetchColumn();
                    if ($tid > 0) {
                        $pdo->prepare("UPDATE gm_tiempo_atencion SET fin = NOW(), tiempo = GREATEST(1, TIMESTAMPDIFF(SECOND, inicio, NOW())), estado = 'completado' WHERE id = :id")
                            ->execute([':id' => $tid]);
                    }
                }
            }

            // 6. Generar archivo oficial del Informe Médico con tema/colores dinámicos del médico
            $temaOverride = [
                'color_primario'   => $input['color_primario'] ?? null,
                'color_secundario' => $input['color_secundario'] ?? null,
            ];
            $generado = [];
            if (class_exists(InformeMedicoPdfGenerator::class)) {
                $generado = InformeMedicoPdfGenerator::generarInformeMedicoPdf($historiaId, $pdo, $temaOverride);
            }

            $pdfUrlFinal = !empty($generado['url']) ? $generado['url'] : ("/api/historias/pdf?id=" . $historiaId);
            $pdo->prepare("UPDATE gm_historias_medicas SET url_informe_pdf = :url1, pdf_path = :url2 WHERE id = :id")
                ->execute([':url1' => $pdfUrlFinal, ':url2' => $pdfUrlFinal, ':id' => $historiaId]);

            if ($pdo->inTransaction()) {
                $pdo->commit();
            }

            http_response_code(201);
            echo json_encode([
                "status"          => "success",
                "mensaje"         => "Historia clínica, exámenes y registro financiero guardados correctamente.",
                "historia_id"     => $historiaId,
                "cita_id"         => $citaId,
                "hash_validacion" => $hashValidacion,
                "pdf_url"         => $pdfUrlFinal
            ], JSON_UNESCAPED_UNICODE);

        } catch (Exception $e) {
            if ($pdo && $pdo->inTransaction()) {
                $pdo->rollBack();
            }
            http_response_code(500);
            echo json_encode(["status" => "error", "mensaje" => $e->getMessage()], JSON_UNESCAPED_UNICODE);
        }
    }

    /**
     * GET /api/historias/pdf?id=X
     * Genera/muestra la plantilla oficial corporativa dinámica desde InformeMedicoPdfGenerator
     */
    public static function imprimirPdf() {
        try {
            $historiaId = isset($_GET['id']) ? (int)$_GET['id'] : 0;
            if ($historiaId <= 0) {
                http_response_code(400);
                echo "ID de historia médica inválido.";
                return;
            }

            $temaOverride = [
                'color_primario'   => $_GET['color_primario'] ?? null,
                'color_secundario' => $_GET['color_secundario'] ?? null,
            ];

            $generado = InformeMedicoPdfGenerator::generarInformeMedicoPdf($historiaId, null, $temaOverride);
            if (empty($generado['html'])) {
                http_response_code(404);
                echo "Informe médico no encontrado.";
                return;
            }

            header('Content-Type: text/html; charset=utf-8');
            echo $generado['html'];
        } catch (Exception $e) {
            http_response_code(500);
            echo "Error generando informe PDF: " . htmlspecialchars($e->getMessage());
        }
    }

    /**
     * PUT /api/examenes
     */
    public static function actualizarExamen($input) {
        try {
            $examenId = (int)($input['id'] ?? $input['examen_id'] ?? 0);
            if ($examenId <= 0) {
                http_response_code(400);
                echo json_encode(["status" => "error", "mensaje" => "Debe indicar el 'id' del examen a actualizar."], JSON_UNESCAPED_UNICODE);
                return;
            }

            $pdo = Database::conectar();
            $campos = [];
            $params = [':id' => $examenId];

            if (!empty($input['estado_examen'])) {
                $campos[] = "estado_examen = :estado";
                $params[':estado'] = trim($input['estado_examen']);
            }
            if (isset($input['resultado'])) {
                $campos[] = "resultado = :res";
                $params[':res'] = trim($input['resultado']);
            }
            if (!empty($input['archivo_adjunto_url'])) {
                $campos[] = "archivo_adjunto_url = :url";
                $params[':url'] = trim($input['archivo_adjunto_url']);
            }

            if (empty($campos)) {
                http_response_code(400);
                echo json_encode(["status" => "error", "mensaje" => "No se enviaron datos para actualizar el examen."], JSON_UNESCAPED_UNICODE);
                return;
            }

            $sql = "UPDATE gm_historias_examenes SET " . implode(", ", $campos) . " WHERE id = :id";
            $pdo->prepare($sql)->execute($params);

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