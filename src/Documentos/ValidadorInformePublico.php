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

    private static function normalizarHexColor(?string $color, string $fallback): string {
        $c = trim((string)($color ?? ''));
        if ($c === '') return $fallback;
        if ($c[0] !== '#') $c = '#' . $c;
        return preg_match('/^#[0-9a-fA-F]{6}$/', $c) ? $c : $fallback;
    }

    private static function aclararHex(string $hex, float $mezcla = 0.90): string {
        $hex = ltrim($hex, '#');
        if (strlen($hex) !== 6) return '#fce7f3';
        $r = (int)round(hexdec(substr($hex, 0, 2)) * (1 - $mezcla) + 255 * $mezcla);
        $g = (int)round(hexdec(substr($hex, 2, 2)) * (1 - $mezcla) + 255 * $mezcla);
        $b = (int)round(hexdec(substr($hex, 4, 2)) * (1 - $mezcla) + 255 * $mezcla);
        return sprintf('#%02x%02x%02x', $r, $g, $b);
    }

    private static function oscurecerHex(string $hex, float $factor = 0.72): string {
        $hex = ltrim($hex, '#');
        if (strlen($hex) !== 6) return '#880e4f';
        $r = max(0, min(255, (int)round(hexdec(substr($hex, 0, 2)) * $factor)));
        $g = max(0, min(255, (int)round(hexdec(substr($hex, 2, 2)) * $factor)));
        $b = max(0, min(255, (int)round(hexdec(substr($hex, 4, 2)) * $factor)));
        return sprintf('#%02x%02x%02x', $r, $g, $b);
    }

    /**
     * GET /validar-informe?hash=...
     * Renderiza la verificación pública con los colores dinámicos del tema de GinevApp
     */
    public static function renderizar() {
        header('Content-Type: text/html; charset=utf-8');
        $hash = isset($_GET['hash']) ? trim((string)$_GET['hash']) : '';

        // Colores dinámicos desde URL (?cp=...&cs=...) o tema corporativo por defecto de GinevApp (#B81D65)
        $colorPrimario = self::normalizarHexColor($_GET['cp'] ?? ($_GET['color_primario'] ?? null), '#B81D65');
        $colorOscuro   = self::normalizarHexColor($_GET['cs'] ?? ($_GET['color_secundario'] ?? null), self::oscurecerHex($colorPrimario));

        echo '<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Validación Pública de Informe Médico</title>
</head>
<body style="background:#fdf2f8; margin:0; padding:16px;">';

        if (empty($hash)) {
            echo '<div style="max-width: 500px; margin: 40px auto; padding: 25px; background: #fff; border: 1px solid #cbd5e1; border-radius: 12px; text-align: center; font-family: sans-serif;">
                    <h3 style="color: #d97706; margin-top:0;">⚠️ Código de Validación no Proporcionado</h3>
                    <p style="color: #64748b; font-size: 14px;">Por favor, escanee un código QR válido desde un informe médico oficial.</p>
                  </div></body></html>';
            return;
        }

        try {
            $pdo = Database::conectar();
            $stmt = $pdo->prepare("SELECT h.*,
                        p.nombre AS p_nombre, p.apellido AS p_apellido, p.tipo_identificacion AS p_tipo_id, p.cedula AS p_cedula,
                        m.*
                    FROM gm_historias_medicas h
                    JOIN gm_usuarios_extendidos p ON h.paciente_id = p.user_id
                    JOIN gm_usuarios_extendidos m ON h.medico_id = m.user_id
                    WHERE h.hash_validacion = :hash
                    LIMIT 1");
            $stmt->execute([':hash' => $hash]);
            $registro = $stmt->fetch(PDO::FETCH_ASSOC);

            // Si no vinieron en la URL, tomar los colores guardados del médico o configuración global
            if ($registro && empty($_GET['cp']) && empty($_GET['color_primario'])) {
                $dbColor = $registro['color_primario'] ?? $registro['theme_primary'] ?? null;
                if (!empty($dbColor)) {
                    $colorPrimario = self::normalizarHexColor($dbColor, $colorPrimario);
                    $dbDark = $registro['color_secundario'] ?? $registro['theme_dark'] ?? null;
                    $colorOscuro = !empty($dbDark)
                        ? self::normalizarHexColor($dbDark, self::oscurecerHex($colorPrimario))
                        : self::oscurecerHex($colorPrimario);
                } else {
                    try {
                        $stmtCfg = $pdo->query("SELECT * FROM gm_configuracion LIMIT 1");
                        $cfg = $stmtCfg ? $stmtCfg->fetch(PDO::FETCH_ASSOC) : null;
                        if ($cfg && !empty($cfg['color_primario'])) {
                            $colorPrimario = self::normalizarHexColor($cfg['color_primario'], $colorPrimario);
                            $colorOscuro = !empty($cfg['color_secundario'])
                                ? self::normalizarHexColor($cfg['color_secundario'], self::oscurecerHex($colorPrimario))
                                : self::oscurecerHex($colorPrimario);
                        }
                    } catch (Exception $ignored) {}
                }
            }

            $colorFondoPagina = self::aclararHex($colorPrimario, 0.95);
            $colorBadgeBg     = self::aclararHex($colorPrimario, 0.88);
            $colorBordeSuave  = self::aclararHex($colorPrimario, 0.80);

            echo '<script>document.body.style.background = ' . json_encode($colorFondoPagina) . ';</script>';

            echo '<div style="max-width: 600px; margin: 32px auto; padding: 30px; background: #ffffff; border: 1px solid ' . htmlspecialchars($colorBordeSuave) . '; border-radius: 14px; box-shadow: 0 6px 18px rgba(0,0,0,0.06); font-family: \'Helvetica Neue\', Helvetica, Arial, sans-serif;">';

            if (!$registro) {
                echo '<div style="text-align: center; padding: 20px;">
                        <div style="font-size: 48px; margin-bottom: 10px;">❌</div>
                        <h2 style="color: #dc2626; margin-top: 0;">Documento No Encontrado o Inválido</h2>
                        <p style="color: #64748b; font-size: 14px;">El código de seguridad proporcionado no corresponde a ningún registro médico oficial en nuestra base de datos.</p>
                      </div>';
            } else {
                // Reconsultar explícitamente nombres de paciente y médico para evitar colisión de columnas m.*
                $stmtNombres = $pdo->prepare("SELECT
                        p.nombre AS p_nombre, p.apellido AS p_apellido, p.tipo_identificacion AS p_tipo_id, p.cedula AS p_cedula,
                        m.nombre AS m_nombre, m.apellido AS m_apellido, m.especialidad AS m_esp, m.sexo AS m_sexo
                    FROM gm_historias_medicas h
                    JOIN gm_usuarios_extendidos p ON h.paciente_id = p.user_id
                    JOIN gm_usuarios_extendidos m ON h.medico_id = m.user_id
                    WHERE h.id = :hid LIMIT 1");
                $stmtNombres->execute([':hid' => (int)$registro['id']]);
                $datosPers = $stmtNombres->fetch(PDO::FETCH_ASSOC) ?: $registro;

                $sexoMed = strtolower(trim($datosPers['m_sexo'] ?? ''));
                $tituloDr = ($sexoMed === 'femenino' || $sexoMed === 'f') ? 'Dra.' : 'Dr.';

                $urlDescarga = '';
                if (!empty($registro['url_pdf_informe'])) {
                    $urlDescarga = $registro['url_pdf_informe'];
                } elseif (!empty($registro['pdf_path'])) {
                    $urlDescarga = $registro['pdf_path'];
                }

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
                    $gen = InformeMedicoPdfGenerator::generarInformeMedicoPdf((int)$registro['id'], $pdo, [
                        'color_primario'   => $colorPrimario,
                        'color_secundario' => $colorOscuro,
                    ]);
                    $urlDescarga = $gen['url'] ?? (self::baseUrlPublica() . '/api/historias/pdf?id=' . (int)$registro['id']);
                }

                $cedulaPac = (!empty($datosPers['p_tipo_id']) ? $datosPers['p_tipo_id'] . '-' : '') . ($datosPers['p_cedula'] ?? '');
                $fechaAtencion = !empty($registro['fecha_registro'])
                    ? date('d/m/Y h:i A', strtotime($registro['fecha_registro']))
                    : '';

                $medLinea = trim($tituloDr . ' ' . ($datosPers['m_nombre'] ?? '') . ' ' . ($datosPers['m_apellido'] ?? ''));
                if (!empty($datosPers['m_esp'])) {
                    $medLinea .= ' - ' . $datosPers['m_esp'];
                }

                $esc = static fn($v) => htmlspecialchars((string)($v ?? ''), ENT_QUOTES, 'UTF-8');

                echo '<div style="text-align: center; border-bottom: 2px solid ' . $esc($colorPrimario) . '; padding-bottom: 20px; margin-bottom: 20px;">
                        <div style="display:inline-block; background: ' . $esc($colorBadgeBg) . '; color: ' . $esc($colorOscuro) . '; padding: 6px 16px; border-radius: 20px; font-weight: bold; font-size: 13px; margin-bottom: 10px;">
                            ✓ Documento Válido y Verificado
                        </div>
                        <h2 style="color: ' . $esc($colorPrimario) . '; margin: 0; font-size: 22px;">Validación de Autenticidad Médica</h2>
                        <p style="color: #64748b; font-size: 13px; margin: 5px 0 0;">Emitido oficialmente por el Sistema Médico</p>
                      </div>

                      <table style="width: 100%; border-collapse: collapse; font-size: 14px; margin-bottom: 20px;">
                        <tr style="border-bottom: 1px solid #f1f5f9;">
                            <td style="padding: 12px; color: #64748b; width: 40%;"><strong>Estado del Documento:</strong></td>
                            <td style="padding: 12px; color: #0f172a; font-weight: bold;">' . $esc($registro['estado_historia'] ?? 'Completada') . '</td>
                        </tr>
                        <tr style="border-bottom: 1px solid #f1f5f9;">
                            <td style="padding: 12px; color: #64748b;"><strong>Nombre de la Paciente:</strong></td>
                            <td style="padding: 12px; color: #0f172a; font-weight: 600;">' . $esc(trim(($datosPers['p_nombre'] ?? '') . ' ' . ($datosPers['p_apellido'] ?? ''))) . '</td>
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
                            <td style="padding: 12px; color: #0f172a;">' . $esc($medLinea) . '</td>
                        </tr>
                        <tr>
                            <td style="padding: 12px; color: #64748b;"><strong>Código de Validación:</strong></td>
                            <td style="padding: 12px; color: #475569; font-family: monospace; font-size: 11px; word-break: break-all;">' . $esc($registro['hash_validacion']) . '</td>
                        </tr>
                      </table>

                      <div style="text-align: center; border-top: 1px solid #f1f5f9; padding-top: 20px;">
                        <a href="' . $esc($urlDescarga) . '" target="_blank" style="background-color: ' . $esc($colorPrimario) . '; color: #ffffff; padding: 13px 24px; border-radius: 8px; font-weight: bold; text-decoration: none; display: inline-block; font-size: 14px; box-shadow: 0 3px 8px rgba(0,0,0,0.12);">
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