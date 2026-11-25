<?php
namespace App\Controllers;

use App\Config\Database;
use PDO;
use Exception;

class CuentasPorCobrarController {

    private static function obtenerBaseUrlPublica(): string {
        $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
        $host   = $_SERVER['HTTP_HOST'] ?? 'api.ginevapp.com';
        return $scheme . '://' . $host;
    }

    /**
     * Lee los métodos de cobro configurados por el especialista:
     * 1) Prioriza gm_medico_meta (meta_key = 'metodos_cobro'), donde se guardan desde Ajustes del Médico.
     * 2) Respalda con gm_usuarios_extendidos.metodos_cobro si existiera.
     */
    public static function obtenerMetodosCobroMedico(PDO $pdo, int $medicoId): array {
        if ($medicoId <= 0) {
            return [];
        }

        // 1. Buscar en gm_medico_meta (fuente principal)
        try {
            $stmtMeta = $pdo->prepare("
                SELECT meta_value
                FROM gm_medico_meta
                WHERE medico_id = :med AND meta_key = 'metodos_cobro'
                ORDER BY id DESC
                LIMIT 1
            ");
            $stmtMeta->execute([':med' => $medicoId]);
            $rawMeta = $stmtMeta->fetchColumn();
            if (!empty($rawMeta)) {
                $decoded = json_decode((string)$rawMeta, true);
                if (is_array($decoded) && !empty($decoded)) {
                    return $decoded;
                }
            }
        } catch (Exception $e) {
            // Continuar al fallback si la tabla no existe en algún entorno
        }

        // 2. Fallback: buscar en gm_usuarios_extendidos
        try {
            $stmtExt = $pdo->prepare("
                SELECT metodos_cobro
                FROM gm_usuarios_extendidos
                WHERE user_id = :med
                LIMIT 1
            ");
            $stmtExt->execute([':med' => $medicoId]);
            $rawExt = $stmtExt->fetchColumn();
            if (!empty($rawExt)) {
                $decoded = json_decode((string)$rawExt, true);
                if (is_array($decoded) && !empty($decoded)) {
                    return $decoded;
                }
            }
        } catch (Exception $e) {
            // Columna opcional
        }

        return [];
    }

    public static function construirCronogramaSemanal(array $cuenta, array $reportesCuenta): array {
        $totalCuotas = max(1, (int)($cuenta['cantidad_cuotas'] ?? $cuenta['cuotas_semanales'] ?? 1));
        $debeUsd     = max((float)($cuenta['debe'] ?? 0), (float)($cuenta['debe_usd'] ?? 0));
        $haberUsd    = max((float)($cuenta['haber'] ?? 0), (float)($cuenta['haber_usd'] ?? 0));
        $tasaBcv     = (float)($cuenta['tasa_bcv'] ?? 1.0) > 0 ? (float)$cuenta['tasa_bcv'] : 1.0;

        $montoCuotaBase = (float)($cuenta['monto_cuota_usd'] ?? 0) > 0
            ? round((float)$cuenta['monto_cuota_usd'], 2)
            : round($debeUsd / $totalCuotas, 2);

        $fechaBaseStr = !empty($cuenta['fecha'])
            ? $cuenta['fecha']
            : (!empty($cuenta['fecha_credito']) ? $cuenta['fecha_credito'] : date('Y-m-d'));
        $tsBase = strtotime($fechaBaseStr) ?: time();

        $reportesPorCuota = [];
        foreach ($reportesCuenta as $rep) {
            $num = (int)($rep['cuota_numero'] ?? 0);
            if ($num > 0) {
                $reportesPorCuota[$num][] = $rep;
            }
        }

        $cuotasCubiertasPorHaber = ($montoCuotaBase > 0)
            ? (int)floor(($haberUsd + 0.01) / $montoCuotaBase)
            : 0;
        if (($debeUsd - $haberUsd) <= 0.01) {
            $cuotasCubiertasPorHaber = $totalCuotas;
        }

        $cronograma = [];
        $proximaCuotaPendiente = null;

        for ($i = 1; $i <= $totalCuotas; $i++) {
            $montoEstaCuota = ($i === $totalCuotas)
                ? round(max(0, $debeUsd - ($montoCuotaBase * ($totalCuotas - 1))), 2)
                : $montoCuotaBase;

            $diasOffset = ($i - 1) * 7;
            $fechaVencCuota = date('Y-m-d', strtotime("+{$diasOffset} days", $tsBase));

            $repsEsta = $reportesPorCuota[$i] ?? [];
            $ultimoRep = !empty($repsEsta) ? $repsEsta[0] : null;

            $estatusCuota = 'pendiente';
            if ($i <= $cuotasCubiertasPorHaber) {
                $estatusCuota = 'pagada';
            } elseif ($ultimoRep && ($ultimoRep['estatus'] ?? '') === 'aprobado') {
                $estatusCuota = 'pagada';
            } elseif ($ultimoRep && ($ultimoRep['estatus'] ?? '') === 'por_verificar') {
                $estatusCuota = 'por_verificar';
            } elseif ($ultimoRep && ($ultimoRep['estatus'] ?? '') === 'rechazado') {
                $estatusCuota = 'rechazada';
            }

            if ($estatusCuota !== 'pagada' && $estatusCuota !== 'por_verificar' && $proximaCuotaPendiente === null) {
                $proximaCuotaPendiente = [
                    'cuota_numero'      => $i,
                    'total_cuotas'      => $totalCuotas,
                    'fecha_vencimiento' => $fechaVencCuota,
                    'monto_usd'         => $montoEstaCuota,
                    'monto_bs'          => round($montoEstaCuota * $tasaBcv, 2),
                    'estatus'           => $estatusCuota,
                ];
            }

            $cronograma[] = [
                'cuota_numero'      => $i,
                'total_cuotas'      => $totalCuotas,
                'semana_label'      => "Cuota {$i} de {$totalCuotas}",
                'fecha_vencimiento' => $fechaVencCuota,
                'monto_usd'         => $montoEstaCuota,
                'monto_bs'          => round($montoEstaCuota * $tasaBcv, 2),
                'estatus'           => $estatusCuota,
                'ultimo_reporte'    => $ultimoRep,
            ];
        }

        return [
            'cronograma_cuotas'       => $cronograma,
            'cuotas_pagadas_calc'     => min($totalCuotas, $cuotasCubiertasPorHaber),
            'proxima_cuota_pendiente' => $proximaCuotaPendiente,
        ];
    }

    /**
     * GET /api/finanzas/cuentas-por-cobrar?medico_id=X&paciente_id=Y
     */
    public static function listarCuentasPorCobrar() {
        try {
            $pdo = Database::conectar();
            FinanzaServicioController::asegurarEsquemaFinanciamientoYCobros($pdo);

            $condiciones = ["1=1"];
            $params = [];

            if (!empty($_GET['medico_id'])) {
                $condiciones[] = "c.medico_id = :med";
                $params[':med'] = (int)$_GET['medico_id'];
            }
            if (!empty($_GET['paciente_id'])) {
                $condiciones[] = "c.paciente_id = :pac";
                $params[':pac'] = (int)$_GET['paciente_id'];
            }

            $whereSql = implode(" AND ", $condiciones);

            $sql = "SELECT
                        c.*,
                        COALESCE(c.fecha, c.fecha_credito) AS fecha,
                        COALESCE(NULLIF(c.cantidad_cuotas, 0), NULLIF(c.cuotas_semanales, 0), 1) AS cantidad_cuotas,
                        COALESCE(NULLIF(c.cuotas_semanales, 0), NULLIF(c.cantidad_cuotas, 0), 1) AS cuotas_semanales,
                        COALESCE(NULLIF(c.debe, 0), c.debe_usd, 0) AS debe,
                        COALESCE(NULLIF(c.debe, 0), c.debe_usd, 0) AS monto_total_usd,
                        COALESCE(NULLIF(c.haber, 0), c.haber_usd, 0) AS haber,
                        COALESCE(NULLIF(c.haber, 0), c.haber_usd, 0) AS total_abonos_usd,
                        COALESCE(NULLIF(c.saldo, 0), c.saldo_usd, 0) AS saldo,
                        COALESCE(NULLIF(c.saldo, 0), c.saldo_usd, 0) AS saldo_pendiente_usd,
                        CONCAT(p.nombre, ' ', p.apellido) AS cliente,
                        p.cedula AS paciente_cedula,
                        CONCAT('Dr(a). ', m.nombre, ' ', m.apellido) AS medico_nombre
                    FROM gm_cuentas_por_cobrar c
                    LEFT JOIN gm_usuarios_extendidos p ON c.paciente_id = p.user_id
                    LEFT JOIN gm_usuarios_extendidos m ON c.medico_id = m.user_id
                    WHERE $whereSql
                    ORDER BY COALESCE(c.fecha, c.fecha_credito) DESC, c.id DESC";

            $stmt = $pdo->prepare($sql);
            $stmt->execute($params);
            $cuentas = $stmt->fetchAll(PDO::FETCH_ASSOC);

            $tasaVal = $pdo->query("SELECT tasa_usd FROM gm_tasa_bcv ORDER BY fecha DESC LIMIT 1")->fetchColumn();
            $tasaActual = ($tasaVal && (float)$tasaVal > 0) ? (float)$tasaVal : 1.0000;

            $metodosCache = [];
            $cuentasIds = array_map(fn($row) => (int)$row['id'], $cuentas);
            $reportesPorCuenta = [];

            if (!empty($cuentasIds)) {
                $inSql = implode(',', array_fill(0, count($cuentasIds), '?'));
                $stmtRep = $pdo->prepare("
                    SELECT *
                    FROM gm_pagos_cuotas
                    WHERE cuenta_id IN ($inSql)
                    ORDER BY cuota_numero ASC, id DESC
                ");
                $stmtRep->execute($cuentasIds);
                foreach ($stmtRep->fetchAll(PDO::FETCH_ASSOC) as $rep) {
                    $cid = (int)$rep['cuenta_id'];
                    $reportesPorCuenta[$cid][] = $rep;
                }
            }

            foreach ($cuentas as &$c) {
                $cid = (int)$c['id'];
                $medId = (int)$c['medico_id'];
                if (!isset($metodosCache[$medId])) {
                    $metodosCache[$medId] = self::obtenerMetodosCobroMedico($pdo, $medId);
                }
                if ((float)($c['tasa_bcv'] ?? 0) <= 0 || (float)$c['tasa_bcv'] == 1.0) {
                    $c['tasa_bcv'] = $tasaActual;
                }

                $reps = $reportesPorCuenta[$cid] ?? [];
                $infoCrono = self::construirCronogramaSemanal($c, $reps);

                $c['cuotas_pagadas']          = max((int)($c['cuotas_pagadas'] ?? 0), $infoCrono['cuotas_pagadas_calc']);
                $c['cronograma_cuotas']       = $infoCrono['cronograma_cuotas'];
                $c['proxima_cuota_pendiente'] = $infoCrono['proxima_cuota_pendiente'];
                $c['reportes_pagos']          = $reps;
                $c['pagos_por_verificar']     = count(array_filter($reps, fn($r) => ($r['estatus'] ?? '') === 'por_verificar'));
                $c['metodos_cobro_medico']    = $metodosCache[$medId];
                $c['metodos_cobro']           = $metodosCache[$medId];
            }

            echo json_encode([
                "status"   => "success",
                "tasa_bcv" => $tasaActual,
                "total"    => count($cuentas),
                "data"     => $cuentas
            ], JSON_UNESCAPED_UNICODE);

        } catch (Exception $e) {
            http_response_code(500);
            echo json_encode(["status" => "error", "mensaje" => $e->getMessage()], JSON_UNESCAPED_UNICODE);
        }
    }

    /**
     * POST /api/finanzas/cuentas-por-cobrar/reportar-pago
     */
    public static function reportarPagoCuotaPaciente($input = null) {
        try {
            if ($input === null) {
                $raw = file_get_contents('php://input');
                $decoded = json_decode($raw, true);
                $input = is_array($decoded) ? $decoded : $_POST;
            }

            $cuentaId   = (int)($input['cuenta_id'] ?? 0);
            $pacienteId = (int)($input['paciente_id'] ?? 0);
            $cuotaNum   = max(1, (int)($input['cuota_numero'] ?? 1));
            $metodoPago = trim((string)($input['metodo_pago'] ?? 'Pago Móvil'));
            $bancoDest  = trim((string)($input['banco_destino'] ?? ''));
            $referencia = trim((string)($input['referencia'] ?? ''));
            $moneda     = strtoupper(trim((string)($input['moneda_pagada'] ?? 'USD')));
            $obsPac     = trim((string)($input['observaciones'] ?? ''));
            $fechaPago  = !empty($input['fecha_pago']) ? substr(trim((string)$input['fecha_pago']), 0, 10) : date('Y-m-d');

            if ($cuentaId <= 0 || $referencia === '') {
                http_response_code(400);
                echo json_encode(["status" => "error", "mensaje" => "La cuenta por pagar y el número de referencia son obligatorios."], JSON_UNESCAPED_UNICODE);
                return;
            }

            $pdo = Database::conectar();
            FinanzaServicioController::asegurarEsquemaFinanciamientoYCobros($pdo);

            $stmtCxc = $pdo->prepare("SELECT * FROM gm_cuentas_por_cobrar WHERE id = :id LIMIT 1");
            $stmtCxc->execute([':id' => $cuentaId]);
            $cuenta = $stmtCxc->fetch(PDO::FETCH_ASSOC);

            if (!$cuenta) {
                http_response_code(404);
                echo json_encode(["status" => "error", "mensaje" => "Cuenta por pagar no encontrada."], JSON_UNESCAPED_UNICODE);
                return;
            }

            $medicoId    = (int)$cuenta['medico_id'];
            $pacienteId  = $pacienteId > 0 ? $pacienteId : (int)$cuenta['paciente_id'];
            $totalCuotas = max(1, (int)($cuenta['cantidad_cuotas'] ?? $cuenta['cuotas_semanales'] ?? 1));

            $chkDup = $pdo->prepare("
                SELECT id, estatus
                FROM gm_pagos_cuotas
                WHERE cuenta_id = :cid AND cuota_numero = :cnum AND estatus IN ('por_verificar', 'aprobado')
                LIMIT 1
            ");
            $chkDup->execute([':cid' => $cuentaId, ':cnum' => $cuotaNum]);
            $existente = $chkDup->fetch(PDO::FETCH_ASSOC);
            if ($existente) {
                $estadoTxt = ($existente['estatus'] === 'aprobado') ? 'ya fue aprobada' : 'ya está en revisión por el consultorio';
                http_response_code(400);
                echo json_encode(["status" => "error", "mensaje" => "La cuota #{$cuotaNum} {$estadoTxt}."], JSON_UNESCAPED_UNICODE);
                return;
            }

            $tasaVal = $pdo->query("SELECT tasa_usd FROM gm_tasa_bcv ORDER BY fecha DESC LIMIT 1")->fetchColumn();
            $tasaBcv = !empty($input['tasa_bcv']) && (float)$input['tasa_bcv'] > 0
                ? (float)$input['tasa_bcv']
                : (($tasaVal && (float)$tasaVal > 0) ? (float)$tasaVal : 1.0000);

            $montoUsd = (float)($input['monto_usd'] ?? 0);
            $montoBs  = (float)($input['monto_bs'] ?? 0);

            if ($montoUsd <= 0 && $montoBs > 0) {
                $montoUsd = round($montoBs / $tasaBcv, 2);
            } elseif ($montoBs <= 0 && $montoUsd > 0) {
                $montoBs = round($montoUsd * $tasaBcv, 2);
            } else {
                $montoUsd = round($montoUsd, 2);
                $montoBs  = round($montoBs, 2);
            }

            if ($montoUsd <= 0) {
                http_response_code(400);
                echo json_encode(["status" => "error", "mensaje" => "Debe indicar un monto válido para la cuota."], JSON_UNESCAPED_UNICODE);
                return;
            }

            $comprobanteUrl = trim((string)($input['comprobante_url'] ?? ''));
            $dirUploads = __DIR__ . '/../../public/uploads/comprobantes';
            if (!is_dir($dirUploads)) {
                @mkdir($dirUploads, 0775, true);
            }

            if (!empty($_FILES['comprobante']['tmp_name']) && is_uploaded_file($_FILES['comprobante']['tmp_name'])) {
                $ext = strtolower(pathinfo($_FILES['comprobante']['name'], PATHINFO_EXTENSION)) ?: 'jpg';
                if (!in_array($ext, ['jpg', 'jpeg', 'png', 'webp', 'pdf'], true)) {
                    $ext = 'jpg';
                }
                $nombreArchivo = 'comp_' . $cuentaId . '_c' . $cuotaNum . '_' . time() . '.' . $ext;
                if (move_uploaded_file($_FILES['comprobante']['tmp_name'], $dirUploads . '/' . $nombreArchivo)) {
                    $comprobanteUrl = self::obtenerBaseUrlPublica() . '/uploads/comprobantes/' . $nombreArchivo;
                }
            } elseif (!empty($input['comprobante_base64'])) {
                $b64 = (string)$input['comprobante_base64'];
                $ext = 'jpg';
                if (preg_match('/^data:image\/(\w+);base64,/', $b64, $m)) {
                    $ext = strtolower($m[1]) === 'jpeg' ? 'jpg' : strtolower($m[1]);
                    $b64 = substr($b64, strpos($b64, ',') + 1);
                } elseif (preg_match('/^data:application\/pdf;base64,/', $b64)) {
                    $ext = 'pdf';
                    $b64 = substr($b64, strpos($b64, ',') + 1);
                }
                $bin = base64_decode($b64, true);
                if ($bin !== false && strlen($bin) > 50) {
                    $nombreArchivo = 'comp_' . $cuentaId . '_c' . $cuotaNum . '_' . time() . '.' . $ext;
                    if (@file_put_contents($dirUploads . '/' . $nombreArchivo, $bin)) {
                        $comprobanteUrl = self::obtenerBaseUrlPublica() . '/uploads/comprobantes/' . $nombreArchivo;
                    }
                }
            }

            $ins = $pdo->prepare("
                INSERT INTO gm_pagos_cuotas
                (cuenta_id, medico_id, paciente_id, cuota_numero, total_cuotas, fecha_pago, metodo_pago, banco_destino,
                 referencia, moneda_pagada, monto_usd, monto_bs, tasa_bcv, comprobante_url, observaciones_paciente, estatus)
                VALUES
                (:cid, :med, :pac, :cnum, :tc, :fpago, :met, :banco, :ref, :mon, :musd, :mbs, :tasa, :comp, :obs, 'por_verificar')
            ");
            $ins->execute([
                ':cid'   => $cuentaId,
                ':med'   => $medicoId,
                ':pac'   => $pacienteId,
                ':cnum'  => $cuotaNum,
                ':tc'    => $totalCuotas,
                ':fpago' => $fechaPago,
                ':met'   => $metodoPago,
                ':banco' => $bancoDest,
                ':ref'   => $referencia,
                ':mon'   => ($moneda === 'BS' || $moneda === 'VES') ? 'BS' : 'USD',
                ':musd'  => $montoUsd,
                ':mbs'   => $montoBs,
                ':tasa'  => $tasaBcv,
                ':comp'  => $comprobanteUrl,
                ':obs'   => $obsPac,
            ]);

            http_response_code(201);
            echo json_encode([
                "status"          => "success",
                "mensaje"         => "Reporte de pago enviado (Cuota #{$cuotaNum}). Queda en revisión por el consultorio.",
                "reporte_id"      => (int)$pdo->lastInsertId(),
                "comprobante_url" => $comprobanteUrl,
                "estatus"         => "por_verificar"
            ], JSON_UNESCAPED_UNICODE);

        } catch (Exception $e) {
            http_response_code(500);
            echo json_encode(["status" => "error", "mensaje" => $e->getMessage()], JSON_UNESCAPED_UNICODE);
        }
    }

    /**
     * GET /api/finanzas/pagos-reportados?medico_id=X&paciente_id=Y&cuenta_id=Z&estatus=por_verificar
     */
    public static function listarPagosReportados() {
        try {
            $pdo = Database::conectar();
            FinanzaServicioController::asegurarEsquemaFinanciamientoYCobros($pdo);

            $condiciones = ["1=1"];
            $params = [];

            if (!empty($_GET['medico_id'])) {
                $condiciones[] = "r.medico_id = :med";
                $params[':med'] = (int)$_GET['medico_id'];
            }
            if (!empty($_GET['paciente_id'])) {
                $condiciones[] = "r.paciente_id = :pac";
                $params[':pac'] = (int)$_GET['paciente_id'];
            }
            if (!empty($_GET['cuenta_id'])) {
                $condiciones[] = "r.cuenta_id = :cid";
                $params[':cid'] = (int)$_GET['cuenta_id'];
            }
            if (!empty($_GET['estatus'])) {
                $condiciones[] = "r.estatus = :est";
                $params[':est'] = trim((string)$_GET['estatus']);
            }

            $whereSql = implode(" AND ", $condiciones);
            $stmt = $pdo->prepare("
                SELECT
                    r.*,
                    c.descripcion AS cuenta_descripcion,
                    CONCAT(p.nombre, ' ', p.apellido) AS paciente_nombre,
                    p.cedula AS paciente_cedula
                FROM gm_pagos_cuotas r
                LEFT JOIN gm_cuentas_por_cobrar c ON r.cuenta_id = c.id
                LEFT JOIN gm_usuarios_extendidos p ON r.paciente_id = p.user_id
                WHERE $whereSql
                ORDER BY FIELD(r.estatus, 'por_verificar', 'aprobado', 'rechazado'), r.id DESC
            ");
            $stmt->execute($params);
            $lista = $stmt->fetchAll(PDO::FETCH_ASSOC);

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
     * POST /api/finanzas/cuentas-por-cobrar/validar-pago
     */
    public static function validarPagoCuota($input = null) {
        try {
            if ($input === null) {
                $input = json_decode(file_get_contents('php://input'), true) ?: [];
            }

            $reporteId = (int)($input['reporte_id'] ?? $input['id'] ?? 0);
            $accion    = strtolower(trim((string)($input['accion'] ?? 'aprobar')));
            $motivo    = trim((string)($input['motivo_rechazo'] ?? ''));

            if ($reporteId <= 0 || !in_array($accion, ['aprobar', 'rechazar'], true)) {
                http_response_code(400);
                echo json_encode(["status" => "error", "mensaje" => "Parámetros inválidos para validar el pago."], JSON_UNESCAPED_UNICODE);
                return;
            }

            $pdo = Database::conectar();
            FinanzaServicioController::asegurarEsquemaFinanciamientoYCobros($pdo);
            $pdo->beginTransaction();

            $stmtRep = $pdo->prepare("SELECT * FROM gm_pagos_cuotas WHERE id = :id LIMIT 1 FOR UPDATE");
            $stmtRep->execute([':id' => $reporteId]);
            $rep = $stmtRep->fetch(PDO::FETCH_ASSOC);

            if (!$rep) {
                $pdo->rollBack();
                http_response_code(404);
                echo json_encode(["status" => "error", "mensaje" => "Reporte de pago no encontrado."], JSON_UNESCAPED_UNICODE);
                return;
            }

            if (($rep['estatus'] ?? '') !== 'por_verificar') {
                $pdo->rollBack();
                http_response_code(400);
                echo json_encode(["status" => "error", "mensaje" => "Este pago ya fue procesado anteriormente ({$rep['estatus']})."], JSON_UNESCAPED_UNICODE);
                return;
            }

            if ($accion === 'rechazar') {
                $updRech = $pdo->prepare("
                    UPDATE gm_pagos_cuotas
                    SET estatus = 'rechazado',
                        motivo_rechazo = :motivo,
                        fecha_validacion = NOW()
                    WHERE id = :id
                ");
                $updRech->execute([
                    ':motivo' => $motivo !== '' ? $motivo : 'Comprobante no verificado',
                    ':id'     => $reporteId
                ]);
                $pdo->commit();

                echo json_encode([
                    "status"  => "success",
                    "mensaje" => "Pago rechazado. El paciente podrá reportar nuevamente la cuota #{$rep['cuota_numero']}."
                ], JSON_UNESCAPED_UNICODE);
                return;
            }

            $cuentaId = (int)$rep['cuenta_id'];
            $stmtCxc = $pdo->prepare("SELECT * FROM gm_cuentas_por_cobrar WHERE id = :id LIMIT 1 FOR UPDATE");
            $stmtCxc->execute([':id' => $cuentaId]);
            $cuenta = $stmtCxc->fetch(PDO::FETCH_ASSOC);

            if (!$cuenta) {
                $pdo->rollBack();
                http_response_code(404);
                echo json_encode(["status" => "error", "mensaje" => "Cuenta por cobrar asociada no encontrada."], JSON_UNESCAPED_UNICODE);
                return;
            }

            $montoUsd = round((float)$rep['monto_usd'], 2);
            $montoBs  = round((float)$rep['monto_bs'], 2);
            $tasaBcv  = (float)$rep['tasa_bcv'] > 0 ? (float)$rep['tasa_bcv'] : 1.0000;

            $debeActualUsd  = max((float)($cuenta['debe'] ?? 0), (float)($cuenta['debe_usd'] ?? 0));
            $haberActualUsd = max((float)($cuenta['haber'] ?? 0), (float)($cuenta['haber_usd'] ?? 0));
            $nuevoHaberUsd  = round(min($debeActualUsd, $haberActualUsd + $montoUsd), 2);
            $nuevoSaldoUsd  = round(max(0, $debeActualUsd - $nuevoHaberUsd), 2);

            $debeActualBs  = (float)($cuenta['debe_bs'] ?? 0) > 0 ? (float)$cuenta['debe_bs'] : round($debeActualUsd * $tasaBcv, 2);
            $haberActualBs = (float)($cuenta['haber_bs'] ?? 0) > 0 ? (float)$cuenta['haber_bs'] : round($haberActualUsd * $tasaBcv, 2);
            $nuevoHaberBs  = round($haberActualBs + $montoBs, 2);
            $nuevoSaldoBs  = round(max(0, $debeActualBs - $nuevoHaberBs), 2);
            $nuevoEstado   = ($nuevoSaldoUsd <= 0.009) ? 'pagado' : 'parcial';

            $totalCuotas   = max(1, (int)($cuenta['cantidad_cuotas'] ?? $cuenta['cuotas_semanales'] ?? $rep['total_cuotas'] ?? 1));
            $cuotasPagadas = min($totalCuotas, max((int)($cuenta['cuotas_pagadas'] ?? 0) + 1, (int)$rep['cuota_numero']));

            $updCxc = $pdo->prepare("
                UPDATE gm_cuentas_por_cobrar
                SET haber = :haber1,
                    haber_usd = :haber2,
                    saldo = :saldo1,
                    saldo_usd = :saldo2,
                    haber_bs = :haber_bs,
                    saldo_bs = :saldo_bs,
                    cuotas_pagadas = :cpag,
                    estado = :estado
                WHERE id = :id
            ");
            $updCxc->execute([
                ':haber1'   => $nuevoHaberUsd,
                ':haber2'   => $nuevoHaberUsd,
                ':saldo1'   => $nuevoSaldoUsd,
                ':saldo2'   => $nuevoSaldoUsd,
                ':haber_bs' => $nuevoHaberBs,
                ':saldo_bs' => $nuevoSaldoBs,
                ':cpag'     => $cuotasPagadas,
                ':estado'   => $nuevoEstado,
                ':id'       => $cuentaId,
            ]);

            $descBase = trim((string)($cuenta['descripcion'] ?? 'Servicio financiado'));
            $conceptoFin = "Cuota semanal ({$rep['cuota_numero']}/{$totalCuotas}) - {$descBase} - {$rep['metodo_pago']} Ref: {$rep['referencia']}";

            $insFin = $pdo->prepare("
                INSERT INTO gm_finanzas
                (medico_id, paciente_id, historia_id, fecha, concepto, debe, haber, saldo, debe_bs, haber_bs, saldo_bs, tasa_bcv)
                VALUES
                (:med, :pac, NULL, :fecha, :concepto, :debe, 0.00, :saldo, :debe_bs, 0.00, :saldo_bs, :tasa)
            ");
            $insFin->execute([
                ':med'      => (int)$rep['medico_id'],
                ':pac'      => (int)$rep['paciente_id'],
                ':fecha'    => $rep['fecha_pago'],
                ':concepto' => mb_substr($conceptoFin, 0, 250),
                ':debe'     => $montoUsd,
                ':saldo'    => $montoUsd,
                ':debe_bs'  => $montoBs,
                ':saldo_bs' => $montoBs,
                ':tasa'     => $tasaBcv,
            ]);
            $finanzaId = (int)$pdo->lastInsertId();

            $updRep = $pdo->prepare("
                UPDATE gm_pagos_cuotas
                SET estatus = 'aprobado',
                    finanza_id = :fid,
                    fecha_validacion = NOW()
                WHERE id = :id
            ");
            $updRep->execute([
                ':fid' => $finanzaId,
                ':id'  => $reporteId,
            ]);

            $pdo->commit();

            echo json_encode([
                "status"          => "success",
                "mensaje"         => "Cuota #{$rep['cuota_numero']} aprobada y acreditada al Haber de Cuentas por Cobrar y al Debe de Finanzas.",
                "finanza_id"      => $finanzaId,
                "nuevo_saldo_usd" => $nuevoSaldoUsd,
                "nuevo_saldo_bs"  => $nuevoSaldoBs
            ], JSON_UNESCAPED_UNICODE);

        } catch (Exception $e) {
            if (isset($pdo) && $pdo->inTransaction()) {
                $pdo->rollBack();
            }
            http_response_code(500);
            echo json_encode(["status" => "error", "mensaje" => $e->getMessage()], JSON_UNESCAPED_UNICODE);
        }
    }

    /**
     * POST /api/finanzas/cuentas-por-cobrar/abono
     */
    public static function registrarAbonoCuentaPorCobrar($input = null) {
        try {
            if ($input === null) {
                $input = json_decode(file_get_contents('php://input'), true) ?: [];
            }

            $cuentaId   = (int)($input['cuenta_id'] ?? $input['id'] ?? 0);
            $medicoId   = (int)($input['medico_id'] ?? 0);
            $pacienteId = (int)($input['paciente_id'] ?? 0);
            $concepto   = trim((string)($input['concepto'] ?? 'Abono a cuenta por cobrar'));

            $pdo = Database::conectar();
            FinanzaServicioController::asegurarEsquemaFinanciamientoYCobros($pdo);

            $tasaVal = $pdo->query("SELECT tasa_usd FROM gm_tasa_bcv ORDER BY fecha DESC LIMIT 1")->fetchColumn();
            $tasaBcv = !empty($input['tasa_bcv']) && (float)$input['tasa_bcv'] > 0
                ? (float)$input['tasa_bcv']
                : (($tasaVal && (float)$tasaVal > 0) ? (float)$tasaVal : 1.0000);

            $montoUsd = (float)($input['monto_usd'] ?? $input['haber_usd'] ?? 0);
            $montoBs  = (float)($input['monto_bs'] ?? $input['haber_bs'] ?? 0);

            if ($montoUsd <= 0 && $montoBs > 0) {
                $montoUsd = round($montoBs / $tasaBcv, 2);
            } elseif ($montoBs <= 0 && $montoUsd > 0) {
                $montoBs = round($montoUsd * $tasaBcv, 2);
            } else {
                $montoUsd = round($montoUsd, 2);
                $montoBs  = round($montoBs, 2);
            }

            if ($montoUsd <= 0 || $montoBs <= 0) {
                http_response_code(400);
                echo json_encode(["status" => "error", "mensaje" => "Debe indicar un monto válido para el abono."], JSON_UNESCAPED_UNICODE);
                return;
            }

            $pdo->beginTransaction();

            $nuevoSaldoUsd = null;
            $nuevoSaldoBs  = null;

            if ($cuentaId > 0) {
                $stmtCxc = $pdo->prepare("SELECT * FROM gm_cuentas_por_cobrar WHERE id = :id LIMIT 1 FOR UPDATE");
                $stmtCxc->execute([':id' => $cuentaId]);
                $cuenta = $stmtCxc->fetch(PDO::FETCH_ASSOC);

                if (!$cuenta) {
                    $pdo->rollBack();
                    http_response_code(404);
                    echo json_encode(["status" => "error", "mensaje" => "Cuenta por cobrar no encontrada."], JSON_UNESCAPED_UNICODE);
                    return;
                }

                $medicoId   = $medicoId > 0 ? $medicoId : (int)$cuenta['medico_id'];
                $pacienteId = $pacienteId > 0 ? $pacienteId : (int)$cuenta['paciente_id'];

                $debeActualUsd  = max((float)($cuenta['debe'] ?? 0), (float)($cuenta['debe_usd'] ?? 0));
                $haberActualUsd = max((float)($cuenta['haber'] ?? 0), (float)($cuenta['haber_usd'] ?? 0));
                $saldoActualUsd = max(0, $debeActualUsd - $haberActualUsd);

                if ($montoUsd > ($saldoActualUsd + 0.01)) {
                    $pdo->rollBack();
                    http_response_code(400);
                    echo json_encode(["status" => "error", "mensaje" => "El abono excede el saldo pendiente de la cuenta por cobrar."], JSON_UNESCAPED_UNICODE);
                    return;
                }

                $nuevoHaberUsd = round($haberActualUsd + $montoUsd, 2);
                $nuevoSaldoUsd = round(max(0, $debeActualUsd - $nuevoHaberUsd), 2);

                $debeActualBs  = (float)($cuenta['debe_bs'] ?? 0) > 0 ? (float)$cuenta['debe_bs'] : round($debeActualUsd * $tasaBcv, 2);
                $haberActualBs = (float)($cuenta['haber_bs'] ?? 0) > 0 ? (float)$cuenta['haber_bs'] : round($haberActualUsd * $tasaBcv, 2);
                $nuevoHaberBs  = round($haberActualBs + $montoBs, 2);
                $nuevoSaldoBs  = round(max(0, $debeActualBs - $nuevoHaberBs), 2);
                $nuevoEstado   = ($nuevoSaldoUsd <= 0.009) ? 'pagado' : 'parcial';

                $totalCuotas   = max(1, (int)($cuenta['cantidad_cuotas'] ?? $cuenta['cuotas_semanales'] ?? 1));
                $montoCuota    = ($totalCuotas > 0) ? ($debeActualUsd / $totalCuotas) : $debeActualUsd;
                $cuotasPagadas = ($montoCuota > 0) ? min($totalCuotas, (int)floor(($nuevoHaberUsd + 0.01) / $montoCuota)) : 1;
                if ($nuevoSaldoUsd <= 0.009) {
                    $cuotasPagadas = $totalCuotas;
                }

                $updCxc = $pdo->prepare("
                    UPDATE gm_cuentas_por_cobrar
                    SET haber = :haber1,
                        haber_usd = :haber2,
                        saldo = :saldo1,
                        saldo_usd = :saldo2,
                        haber_bs = :haber_bs,
                        saldo_bs = :saldo_bs,
                        cuotas_pagadas = :cpag,
                        estado = :estado
                    WHERE id = :id
                ");
                $updCxc->execute([
                    ':haber1'   => $nuevoHaberUsd,
                    ':haber2'   => $nuevoHaberUsd,
                    ':saldo1'   => $nuevoSaldoUsd,
                    ':saldo2'   => $nuevoSaldoUsd,
                    ':haber_bs' => $nuevoHaberBs,
                    ':saldo_bs' => $nuevoSaldoBs,
                    ':cpag'     => $cuotasPagadas,
                    ':estado'   => $nuevoEstado,
                    ':id'       => $cuentaId,
                ]);
            }

            if ($medicoId <= 0) {
                $pdo->rollBack();
                http_response_code(400);
                echo json_encode(["status" => "error", "mensaje" => "El campo medico_id es obligatorio."], JSON_UNESCAPED_UNICODE);
                return;
            }

            $fechaMov = !empty($input['fecha']) ? substr(trim((string)$input['fecha']), 0, 10) : date('Y-m-d');
            $conceptoFinal = (stripos($concepto, 'abono') === false)
                ? "Abono CxC: {$concepto}"
                : $concepto;

            $insFin = $pdo->prepare("
                INSERT INTO gm_finanzas
                (medico_id, paciente_id, historia_id, fecha, concepto, debe, haber, saldo, debe_bs, haber_bs, saldo_bs, tasa_bcv)
                VALUES
                (:med, :pac, NULL, :fecha, :concepto, :debe, 0.00, :saldo, :debe_bs, 0.00, :saldo_bs, :tasa)
            ");
            $insFin->execute([
                ':med'      => $medicoId,
                ':pac'      => $pacienteId,
                ':fecha'    => $fechaMov,
                ':concepto' => $conceptoFinal,
                ':debe'     => $montoUsd,
                ':saldo'    => $montoUsd,
                ':debe_bs'  => $montoBs,
                ':saldo_bs' => $montoBs,
                ':tasa'     => $tasaBcv,
            ]);

            $finanzaId = (int)$pdo->lastInsertId();
            $pdo->commit();

            http_response_code(201);
            echo json_encode([
                "status"            => "success",
                "mensaje"           => "Abono registrado: sumado al Haber de Cuentas por Cobrar y al Debe de Finanzas.",
                "finanza_id"        => $finanzaId,
                "cuenta_id"         => $cuentaId,
                "monto_abonado_usd" => $montoUsd,
                "monto_abonado_bs"  => $montoBs,
                "nuevo_saldo_usd"   => $nuevoSaldoUsd,
                "nuevo_saldo_bs"    => $nuevoSaldoBs
            ], JSON_UNESCAPED_UNICODE);

        } catch (Exception $e) {
            if (isset($pdo) && $pdo->inTransaction()) {
                $pdo->rollBack();
            }
            http_response_code(500);
            echo json_encode(["status" => "error", "mensaje" => $e->getMessage()], JSON_UNESCAPED_UNICODE);
        }
    }
}