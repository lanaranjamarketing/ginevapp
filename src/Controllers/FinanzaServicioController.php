<?php
namespace App\Controllers;

use App\Config\Database;
use PDO;
use Exception;

class FinanzaServicioController {

    private static function asegurarColumnaEstado(PDO $pdo) {
        $stmt = $pdo->prepare("
            SELECT COUNT(*)
            FROM INFORMATION_SCHEMA.COLUMNS
            WHERE TABLE_SCHEMA = DATABASE()
              AND TABLE_NAME = 'gm_servicios'
              AND COLUMN_NAME = 'estado'
        ");
        $stmt->execute();
        $existe = (int)$stmt->fetchColumn() > 0;

        if (!$existe) {
            $pdo->exec("ALTER TABLE gm_servicios ADD COLUMN estado VARCHAR(20) DEFAULT 'activo'");
        }
    }

    /**
     * GET /api/servicios?medico_id=X
     */
    public static function listarServicios() {
        try {
            $pdo = Database::conectar();
            self::asegurarColumnaEstado($pdo);
            $medicoId = isset($_GET['medico_id']) ? (int)$_GET['medico_id'] : 0;

            if ($medicoId > 0) {
                $stmt = $pdo->prepare("SELECT * FROM gm_servicios WHERE medico_id = :med ORDER BY tipo ASC, nombre_servicio ASC");
                $stmt->execute([':med' => $medicoId]);
            } else {
                $stmt = $pdo->query("SELECT * FROM gm_servicios ORDER BY id DESC");
            }

            $servicios = $stmt->fetchAll(PDO::FETCH_ASSOC);
            foreach ($servicios as &$s) {
                $s['items_combo'] = !empty($s['items_combo']) ? json_decode($s['items_combo'], true) : [];
                $s['estado'] = !empty($s['estado']) ? $s['estado'] : 'activo';
            }

            echo json_encode([
                "status" => "success",
                "total"  => count($servicios),
                "data"   => $servicios
            ], JSON_UNESCAPED_UNICODE);

        } catch (Exception $e) {
            http_response_code(500);
            echo json_encode(["status" => "error", "mensaje" => $e->getMessage()], JSON_UNESCAPED_UNICODE);
        }
    }

    /**
     * POST /api/servicios
     * Crea o actualiza un servicio individual o combo (si viene 'id' > 0, actualiza)
     */
    public static function crearServicio($input = null) {
        try {
            if ($input === null) {
                $input = json_decode(file_get_contents('php://input'), true) ?: [];
            }

            $servId   = (int)($input['id'] ?? 0);
            $medicoId = (int)($input['medico_id'] ?? 0);
            $nombre   = trim($input['nombre_servicio'] ?? '');
            $precio   = (float)($input['precio'] ?? 0);

            if ($medicoId <= 0 || empty($nombre)) {
                http_response_code(400);
                echo json_encode(["status" => "error", "mensaje" => "Los campos medico_id y nombre_servicio son obligatorios."], JSON_UNESCAPED_UNICODE);
                return;
            }

            $pdo = Database::conectar();
            self::asegurarColumnaEstado($pdo);

            $tipo = trim($input['tipo'] ?? 'servicio');
            $itemsCombo = null;
            if ($tipo === 'combo' && !empty($input['items_combo'])) {
                $itemsCombo = is_string($input['items_combo'])
                    ? $input['items_combo']
                    : json_encode(array_map('intval', $input['items_combo']), JSON_UNESCAPED_UNICODE);
            }

            if ($servId > 0) {
                $sql = "UPDATE gm_servicios
                        SET tipo = :tipo, nombre_servicio = :nombre, descripcion = :desc, precio = :precio, items_combo = :items
                        WHERE id = :id AND medico_id = :med";
                $stmt = $pdo->prepare($sql);
                $stmt->execute([
                    ':tipo'   => $tipo,
                    ':nombre' => $nombre,
                    ':desc'   => $input['descripcion'] ?? '',
                    ':precio' => $precio,
                    ':items'  => $itemsCombo,
                    ':id'     => $servId,
                    ':med'    => $medicoId
                ]);

                echo json_encode([
                    "status"  => "success",
                    "mensaje" => "Servicio o combo actualizado correctamente.",
                    "id"      => $servId
                ], JSON_UNESCAPED_UNICODE);
            } else {
                $sql = "INSERT INTO gm_servicios (medico_id, nombre_servicio, descripcion, precio, tipo, items_combo, estado) 
                        VALUES (:med, :nombre, :desc, :precio, :tipo, :items, 'activo')";
                $stmt = $pdo->prepare($sql);
                $stmt->execute([
                    ':med'    => $medicoId,
                    ':nombre' => $nombre,
                    ':desc'   => $input['descripcion'] ?? '',
                    ':precio' => $precio,
                    ':tipo'   => $tipo,
                    ':items'  => $itemsCombo
                ]);

                http_response_code(201);
                echo json_encode([
                    "status"  => "success",
                    "mensaje" => "Servicio o combo registrado correctamente.",
                    "id"      => $pdo->lastInsertId()
                ], JSON_UNESCAPED_UNICODE);
            }

        } catch (Exception $e) {
            http_response_code(500);
            echo json_encode(["status" => "error", "mensaje" => $e->getMessage()], JSON_UNESCAPED_UNICODE);
        }
    }

    /**
     * POST /api/servicios/estado
     */
    public static function cambiarEstadoServicio($input = null) {
        try {
            if ($input === null) {
                $input = json_decode(file_get_contents('php://input'), true) ?: [];
            }
            $servId   = (int)($input['id'] ?? 0);
            $medicoId = (int)($input['medico_id'] ?? 0);
            $estado   = trim($input['estado'] ?? 'activo');

            if ($servId <= 0 || $medicoId <= 0) {
                http_response_code(400);
                echo json_encode(["status" => "error", "mensaje" => "Parámetros inválidos."], JSON_UNESCAPED_UNICODE);
                return;
            }

            $pdo = Database::conectar();
            self::asegurarColumnaEstado($pdo);

            $stmt = $pdo->prepare("UPDATE gm_servicios SET estado = :estado WHERE id = :id AND medico_id = :med");
            $stmt->execute([
                ':estado' => ($estado === 'inactivo') ? 'inactivo' : 'activo',
                ':id'     => $servId,
                ':med'    => $medicoId
            ]);

            echo json_encode([
                "status"  => "success",
                "mensaje" => "Estatus actualizado."
            ], JSON_UNESCAPED_UNICODE);

        } catch (Exception $e) {
            http_response_code(500);
            echo json_encode(["status" => "error", "mensaje" => $e->getMessage()], JSON_UNESCAPED_UNICODE);
        }
    }

    /**
     * DELETE /api/servicios?id=X&medico_id=Y
     */
    public static function eliminarServicio() {
        try {
            $servId   = (int)($_GET['id'] ?? 0);
            $medicoId = (int)($_GET['medico_id'] ?? 0);

            if ($servId <= 0 || $medicoId <= 0) {
                http_response_code(400);
                echo json_encode(["status" => "error", "mensaje" => "Parámetros inválidos para eliminar."], JSON_UNESCAPED_UNICODE);
                return;
            }

            $pdo = Database::conectar();
            $stmt = $pdo->prepare("DELETE FROM gm_servicios WHERE id = :id AND medico_id = :med");
            $stmt->execute([':id' => $servId, ':med' => $medicoId]);

            echo json_encode([
                "status"  => "success",
                "mensaje" => "Registro eliminado correctamente."
            ], JSON_UNESCAPED_UNICODE);

        } catch (Exception $e) {
            http_response_code(500);
            echo json_encode(["status" => "error", "mensaje" => $e->getMessage()], JSON_UNESCAPED_UNICODE);
        }
    }

    /**
     * GET /api/tasa-bcv
     */
    public static function obtenerTasaBcv() {
        try {
            $pdo = Database::conectar();
            $stmt = $pdo->query("SELECT id, fecha, tasa_usd FROM gm_tasa_bcv ORDER BY fecha DESC LIMIT 1");
            $tasa = $stmt->fetch(PDO::FETCH_ASSOC);

            echo json_encode([
                "status" => "success",
                "data"   => $tasa ?: ["tasa_usd" => "1.0000", "fecha" => date('Y-m-d H:i:s')]
            ], JSON_UNESCAPED_UNICODE);

        } catch (Exception $e) {
            http_response_code(500);
            echo json_encode(["status" => "error", "mensaje" => $e->getMessage()], JSON_UNESCAPED_UNICODE);
        }
    }

    /**
     * POST /api/tasa-bcv
     */
    public static function guardarTasaBcv($input = null) {
        try {
            if ($input === null) {
                $input = json_decode(file_get_contents('php://input'), true) ?: [];
            }
            $tasaUsd = (float)($input['tasa_usd'] ?? 0);
            if ($tasaUsd <= 0) {
                http_response_code(400);
                echo json_encode(["status" => "error", "mensaje" => "Debe indicar un valor válido para tasa_usd."], JSON_UNESCAPED_UNICODE);
                return;
            }

            $pdo = Database::conectar();
            $fecha = !empty($input['fecha']) ? trim($input['fecha']) : date('Y-m-d H:i:s');

            $sql = "INSERT INTO gm_tasa_bcv (fecha, tasa_usd) VALUES (:fecha, :tasa)
                    ON DUPLICATE KEY UPDATE tasa_usd = :tasa";
            $pdo->prepare($sql)->execute([
                ':fecha' => $fecha,
                ':tasa'  => $tasaUsd
            ]);

            echo json_encode([
                "status"   => "success",
                "mensaje"  => "Tasa BCV actualizada correctamente.",
                "tasa_usd" => $tasaUsd
            ], JSON_UNESCAPED_UNICODE);

        } catch (Exception $e) {
            http_response_code(500);
            echo json_encode(["status" => "error", "mensaje" => $e->getMessage()], JSON_UNESCAPED_UNICODE);
        }
    }

    /**
     * GET /api/finanzas?medico_id=X&paciente_id=Y
     */
    public static function listarFinanzas() {
        try {
            $pdo = Database::conectar();
            $condiciones = ["1=1"];
            $params = [];

            if (!empty($_GET['medico_id'])) {
                $condiciones[] = "f.medico_id = :med";
                $params[':med'] = (int)$_GET['medico_id'];
            }
            if (!empty($_GET['paciente_id'])) {
                $condiciones[] = "f.paciente_id = :pac";
                $params[':pac'] = (int)$_GET['paciente_id'];
            }

            $whereSql = implode(" AND ", $condiciones);

            $sql = "SELECT 
                        f.*,
                        CONCAT(p.nombre, ' ', p.apellido) AS paciente_nombre,
                        p.cedula AS paciente_cedula
                    FROM gm_finanzas f
                    LEFT JOIN gm_usuarios_extendidos p ON f.paciente_id = p.user_id
                    WHERE $whereSql
                    ORDER BY f.fecha DESC, f.id DESC";

            $stmt = $pdo->prepare($sql);
            $stmt->execute($params);
            $movimientos = $stmt->fetchAll(PDO::FETCH_ASSOC);

            $totalDebeUsd  = 0;
            $totalHaberUsd = 0;
            foreach ($movimientos as $m) {
                $totalDebeUsd  += (float)$m['debe'];
                $totalHaberUsd += (float)$m['haber'];
            }

            echo json_encode([
                "status" => "success",
                "resumen" => [
                    "total_cargos_usd" => round($totalDebeUsd, 2),
                    "total_abonos_usd" => round($totalHaberUsd, 2),
                    "saldo_pendiente_usd" => round($totalDebeUsd - $totalHaberUsd, 2)
                ],
                "total" => count($movimientos),
                "data"  => $movimientos
            ], JSON_UNESCAPED_UNICODE);

        } catch (Exception $e) {
            http_response_code(500);
            echo json_encode(["status" => "error", "mensaje" => $e->getMessage()], JSON_UNESCAPED_UNICODE);
        }
    }

    /**
     * POST /api/finanzas
     */
    public static function registrarMovimiento($input = null) {
        try {
            if ($input === null) {
                $input = json_decode(file_get_contents('php://input'), true) ?: [];
            }
            $medicoId   = (int)($input['medico_id'] ?? 0);
            $pacienteId = (int)($input['paciente_id'] ?? 0);
            $concepto   = trim($input['concepto'] ?? '');
            $debe       = (float)($input['debe'] ?? 0);
            $haber      = (float)($input['haber'] ?? 0);

            if ($medicoId <= 0 || $pacienteId <= 0 || empty($concepto) || ($debe <= 0 && $haber <= 0)) {
                http_response_code(400);
                echo json_encode(["status" => "error", "mensaje" => "Datos incompletos para el registro financiero."], JSON_UNESCAPED_UNICODE);
                return;
            }

            $pdo = Database::conectar();

            $tasaVal = $pdo->query("SELECT tasa_usd FROM gm_tasa_bcv ORDER BY fecha DESC LIMIT 1")->fetchColumn();
            $tasaBcv = !empty($input['tasa_bcv']) ? (float)$input['tasa_bcv'] : (($tasaVal && (float)$tasaVal > 0) ? (float)$tasaVal : 1.0000);

            $saldo   = round($debe - $haber, 2);
            $debeBs  = round($debe * $tasaBcv, 2);
            $haberBs = round($haber * $tasaBcv, 2);
            $saldoBs = round($saldo * $tasaBcv, 2);

            $sql = "INSERT INTO gm_finanzas 
                    (medico_id, paciente_id, historia_id, fecha, concepto, debe, haber, saldo, debe_bs, haber_bs, saldo_bs, tasa_bcv) 
                    VALUES 
                    (:med, :pac, :hid, CURDATE(), :concepto, :debe, :haber, :saldo, :debe_bs, :haber_bs, :saldo_bs, :tasa)";

            $pdo->prepare($sql)->execute([
                ':med'      => $medicoId,
                ':pac'      => $pacienteId,
                ':hid'      => !empty($input['historia_id']) ? (int)$input['historia_id'] : null,
                ':concepto' => $concepto,
                ':debe'     => $debe,
                ':haber'    => $haber,
                ':saldo'    => $saldo,
                ':debe_bs'  => $debeBs,
                ':haber_bs' => $haberBs,
                ':saldo_bs' => $saldoBs,
                ':tasa'     => $tasaBcv
            ]);

            http_response_code(201);
            echo json_encode([
                "status"  => "success",
                "mensaje" => "Movimiento financiero registrado correctamente.",
                "id"      => $pdo->lastInsertId()
            ], JSON_UNESCAPED_UNICODE);

        } catch (Exception $e) {
            http_response_code(500);
            echo json_encode(["status" => "error", "mensaje" => $e->getMessage()], JSON_UNESCAPED_UNICODE);
        }
    }

    /**
     * GET /api/tasa-bcv/sincronizar
     */
    public static function sincronizarTasaBcv() {
        try {
            $tasaUsd = 0.0;

            $ch = curl_init('https://www.bcv.org.ve/');
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
            curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);
            curl_setopt($ch, CURLOPT_TIMEOUT, 20);
            curl_setopt($ch, CURLOPT_HTTPHEADER, [
                'User-Agent: Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36'
            ]);
            $html = curl_exec($ch);
            curl_close($ch);

            if ($html && (
                preg_match('/id="dolar"[^>]*>.*?<strong>\s*([0-9.,]+)\s*<\/strong>/s', $html, $matches) ||
                preg_match('/field-content[^>]*>.*?<strong>\s*([0-9.,]+)\s*<\/strong>/s', $html, $matches)
            )) {
                $raw = str_replace('.', '', trim($matches[1]));
                $raw = str_replace(',', '.', $raw);
                $tasaUsd = (float)$raw;
            }

            if ($tasaUsd <= 0) {
                $chApi = curl_init('https://ve.dolarapi.com/v1/dolares/oficial');
                curl_setopt($chApi, CURLOPT_RETURNTRANSFER, true);
                curl_setopt($chApi, CURLOPT_TIMEOUT, 15);
                $jsonApi = curl_exec($chApi);
                curl_close($chApi);

                if ($jsonApi) {
                    $dataApi = json_decode($jsonApi, true);
                    if (!empty($dataApi['promedio'])) {
                        $tasaUsd = (float)$dataApi['promedio'];
                    }
                }
            }

            if ($tasaUsd <= 0) {
                http_response_code(502);
                echo json_encode(["status" => "error", "mensaje" => "No se pudo obtener la tasa desde BCV ni desde el respaldo."], JSON_UNESCAPED_UNICODE);
                return;
            }

            $pdo = Database::conectar();
            $ahora = date('Y-m-d H:i:s');

            $stmt = $pdo->prepare("INSERT INTO gm_tasa_bcv (fecha, tasa_usd) VALUES (:fecha, :tasa)");
            $stmt->execute([
                ':fecha' => $ahora,
                ':tasa'  => $tasaUsd
            ]);

            echo json_encode([
                "status"   => "success",
                "mensaje"  => "Tasa BCV sincronizada correctamente.",
                "fecha"    => $ahora,
                "tasa_usd" => $tasaUsd
            ], JSON_UNESCAPED_UNICODE);

        } catch (Exception $e) {
            http_response_code(500);
            echo json_encode(["status" => "error", "mensaje" => $e->getMessage()], JSON_UNESCAPED_UNICODE);
        }
    }
}