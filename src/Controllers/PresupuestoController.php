<?php
namespace App\Controllers;

use App\Config\Database;
use App\Documentos\FacturaPdfGenerator;
use App\Documentos\PresupuestoPdfGenerator;
use PDO;
use Exception;

class PresupuestoController {

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