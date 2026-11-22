<?php
namespace App\Documentos;

use App\Config\Database;
use PDO;
use Exception;

class ValidadorInformePublico {

    private static function baseUrlPublica(): string {
        $envUrl = getenv('APP_PUBLIC_URL') ?: getenv('APP_URL');
        if (!empty($envUrl)) {
            return rtrim($envUrl, '/');
        }
        $proto = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
            || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https')
            ? 'https'
            : 'http';
        $host = $_SERVER['HTTP_HOST'] ?? 'api.ginevapp.com';
        return $proto . '://' . $host;
    }

    /**
     * GET /validar-informe?hash=...
     * Renderiza la verificación pública del informe médico escaneado desde el QR
     */
    public static function renderizar() {
        header('Content-Type: text/html; charset=utf-8');
        $hash = isset($_GET['hash']) ? trim((string)$_GET['hash']) : '';

        echo '<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Validación Pública de Informe Médico</title>
</head>
<body style="background:#f8fafc; margin:0; padding:16px;">';

        if (empty($hash)) {
            echo '<div style="max-width: 500px; margin: 40px auto; padding: 25px; background: #fff; border: 1px solid #cbd5e1; border-radius: 8px; text-align: center; font-family: sans-serif;">
                    <h3 style="color: #d97706; margin-top:0;">⚠️ Código de Validación no Proporcionado</h3>
                    <p style="color: #64748b; font-size: 14px;">Por favor, escanee un código QR válido desde un informe médico oficial.</p>
                  </div></body></html>';
            return;
        }

        try {
            $pdo = Database::conectar();
            $stmt = $pdo->prepare("SELECT h.*,
                        p.nombre AS p_nombre, p.apellido AS p_apellido, p.tipo_identificacion AS p_tipo_id, p.cedula AS p_cedula,
                        m.nombre AS m_nombre, m.apellido AS m_apellido, m.especialidad AS m_esp, m.sexo AS m_sexo
                    FROM gm_historias_medicas h
                    JOIN gm_usuarios_extendidos p ON h.paciente_id = p.user_id
                    JOIN gm_usuarios_extendidos m ON h.medico_id = m.user_id
                    WHERE h.hash_validacion = :hash
                    LIMIT 1");
            $stmt->execute([':hash' => $hash]);
            $registro = $stmt->fetch(PDO::FETCH_ASSOC);

            echo '<div style="max-width: 600px; margin: 40px auto; padding: 30px; background: #ffffff; border: 1px solid #e2e8f0; border-radius: 12px; box-shadow: 0 4px 6px -1px rgba(0,0,0,0.05); font-family: \'Helvetica Neue\', Helvetica, Arial, sans-serif;">';

            if (!$registro) {
                echo '<div style="text-align: center; padding: 20px;">
                        <div style="font-size: 48px; margin-bottom: 10px;">❌</div>
                        <h2 style="color: #dc2626; margin-top: 0;">Documento No Encontrado o Inválido</h2>
                        <p style="color: #64748b; font-size: 14px;">El código de seguridad proporcionado no corresponde a ningún registro médico oficial en nuestra base de datos.</p>
                      </div>';
            } else {
                $sexoMed = strtolower(trim($registro['m_sexo'] ?? ''));
                $tituloDr = ($sexoMed === 'femenino' || $sexoMed === 'f') ? 'Dra.' : 'Dr.';

                $urlDescarga = '';
                if (!empty($registro['url_pdf_informe'])) {
                    $urlDescarga = $registro['url_pdf_informe'];
                } elseif (!empty($registro['pdf_path'])) {
                    $urlDescarga = $registro['pdf_path'];
                }

                // Respaldo dinámico buscando el archivo en uploads/informes_medicos/ o regenerándolo
                if (empty($urlDescarga)) {
                    $dirBusqueda = __DIR__ . '/../../public/uploads/informes_medicos/';
                    if (is_dir($dirBusqueda)) {
                        $archivos = glob($dirBusqueda . 'informe-medico-' . (int)$registro['id'] . '-*.html') ?: [];
                        if (!empty($archivos)) {
                            rsort($archivos);
                            $urlDescarga = self::baseUrlPublica() . '/uploads/informes_medicos/' . basename($archivos[0]);
                        }
                    }
                }

                if (empty($urlDescarga)) {
                    $gen = InformeMedicoPdfGenerator::generarInformeMedicoPdf((int)$registro['id'], $pdo);
                    $urlDescarga = $gen['url'] ?? (self::baseUrlPublica() . '/api/historias/pdf?id=' . (int)$registro['id']);
                }

                $cedulaPac = (!empty($registro['p_tipo_id']) ? $registro['p_tipo_id'] . '-' : '') . ($registro['p_cedula'] ?? '');
                $fechaAtencion = !empty($registro['fecha_registro'])
                    ? date('d/m/Y h:i A', strtotime($registro['fecha_registro']))
                    : '';

                $esc = static fn($v) => htmlspecialchars((string)($v ?? ''), ENT_QUOTES, 'UTF-8');

                echo '<div style="text-align: center; border-bottom: 2px solid #008080; padding-bottom: 20px; margin-bottom: 20px;">
                        <div style="display:inline-block; background: #dcfce7; color: #166534; padding: 6px 16px; border-radius: 20px; font-weight: bold; font-size: 13px; margin-bottom: 10px;">
                            ✓ Documento Válido y Verificado
                        </div>
                        <h2 style="color: #008080; margin: 0; font-size: 22px;">Validación de Autenticidad Médica</h2>
                        <p style="color: #64748b; font-size: 13px; margin: 5px 0 0;">Emitido oficialmente por el Sistema Médico</p>
                      </div>

                      <table style="width: 100%; border-collapse: collapse; font-size: 14px; margin-bottom: 20px;">
                        <tr style="border-bottom: 1px solid #f1f5f9;">
                            <td style="padding: 12px; color: #64748b; width: 40%;"><strong>Estado del Documento:</strong></td>
                            <td style="padding: 12px; color: #0f172a; font-weight: bold;">' . $esc($registro['estado_historia'] ?? 'Completada') . '</td>
                        </tr>
                        <tr style="border-bottom: 1px solid #f1f5f9;">
                            <td style="padding: 12px; color: #64748b;"><strong>Nombre de la Paciente:</strong></td>
                            <td style="padding: 12px; color: #0f172a; font-weight: 600;">' . $esc(trim(($registro['p_nombre'] ?? '') . ' ' . ($registro['p_apellido'] ?? ''))) . '</td>
                        </tr>
                        <tr style="border-bottom: 1px solid #f1f5f9;">
                            <td style="padding: 12px; color: #64748b;"><strong>Cédula de Identidad:</strong></td>
                            <td style="padding: 12px; color: #0f172a; font-family: monospace;">' . $esc($cedulaPac) . '</td>
                        </tr>
                        <tr style="border-bottom: 1px solid #f1f5f9;">
                            <td style="padding: 12px; color: #64748b;"><strong>Fecha de Atención:</strong></td>
                            <td style="padding: 12px; color: #0f172a;">' . $esc($fechaAtencion) . '</td>
                        </tr>
                        <tr style="border-bottom: 1px solid #f1f5f9;">
                            <td style="padding: 12px; color: #64748b;"><strong>Médico Tratante:</strong></td>
                            <td style="padding: 12px; color: #0f172a;">' . $esc($tituloDr . ' ' . ($registro['m_nombre'] ?? '') . ' ' . ($registro['m_apellido'] ?? '') . ' - ' . ($registro['m_esp'] ?? '')) . '</td>
                        </tr>
                        <tr>
                            <td style="padding: 12px; color: #64748b;"><strong>Código de Validación:</strong></td>
                            <td style="padding: 12px; color: #475569; font-family: monospace; font-size: 11px; word-break: break-all;">' . $esc($registro['hash_validacion']) . '</td>
                        </tr>
                      </table>

                      <div style="text-align: center; border-top: 1px solid #f1f5f9; padding-top: 20px;">
                        <a href="' . $esc($urlDescarga) . '" target="_blank" style="background-color: #008080; color: #ffffff; padding: 12px 24px; border-radius: 6px; font-weight: bold; text-decoration: none; display: inline-block; font-size: 14px; box-shadow: 0 2px 4px rgba(0,0,0,0.1);">
                            📥 Ver e Imprimir / Guardar Informe PDF
                        </a>
                      </div>';
            }

            echo '</div>';
        } catch (Exception $e) {
            echo '<div style="max-width:500px; margin:40px auto; padding:20px; background:#fff; border:1px solid #fecaca; border-radius:8px; color:#b91c1c;">Error al validar el documento: ' . htmlspecialchars($e->getMessage()) . '</div>';
        }

        echo '</body></html>';
    }
}