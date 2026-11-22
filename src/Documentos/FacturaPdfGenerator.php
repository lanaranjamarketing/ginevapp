<?php
namespace App\Documentos;

use App\Config\Database;
use PDO;
use Exception;

class FacturaPdfGenerator {

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

    private static function normalizarHex(?string $color, string $fallback): string {
        $c = trim((string)($color ?? ''));
        if ($c === '') return $fallback;
        if ($c[0] !== '#') $c = '#' . $c;
        return preg_match('/^#[0-9a-fA-F]{6}$/', $c) ? $c : $fallback;
    }

    private static function aclararHex(string $hex, float $mezcla = 0.92): string {
        $hex = ltrim($hex, '#');
        if (strlen($hex) !== 6) return '#fdf2f8';
        $r = (int)round(hexdec(substr($hex, 0, 2)) * (1 - $mezcla) + 255 * $mezcla);
        $g = (int)round(hexdec(substr($hex, 2, 2)) * (1 - $mezcla) + 255 * $mezcla);
        $b = (int)round(hexdec(substr($hex, 4, 2)) * (1 - $mezcla) + 255 * $mezcla);
        return sprintf('#%02x%02x%02x', $r, $g, $b);
    }

    /**
     * Genera la Factura Serie A en HTML/PDF, marca el registro en gm_finanzas como facturado = 1
     * y guarda numero_factura y url_factura_pdf.
     */
    public static function generarFacturaPdf(int $finanzaId, ?PDO $pdoExterno = null, array $temaOverride = []): array {
        $pdo = $pdoExterno ?? Database::conectar();

        $stmt = $pdo->prepare("
            SELECT f.*,
                   p.nombre AS p_nombre, p.segundo_nombre AS p_segundo_nombre,
                   p.apellido AS p_apellido, p.segundo_apellido AS p_segundo_apellido,
                   p.tipo_identificacion AS p_tipo_id, p.cedula AS p_cedula,
                   p.whatsapp AS p_whatsapp, p.email AS p_email,
                   p.pais AS p_pais, p.estado AS p_estado, p.direccion_detalle AS p_dir
            FROM gm_finanzas f
            LEFT JOIN gm_usuarios_extendidos p ON f.paciente_id = p.user_id
            WHERE f.id = :id
            LIMIT 1
        ");
        $stmt->execute([':id' => $finanzaId]);
        $finanza = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$finanza) {
            return ['html' => '', 'url' => '', 'numero_factura' => ''];
        }

        $stmtMed = $pdo->prepare("SELECT * FROM gm_usuarios_extendidos WHERE user_id = :uid LIMIT 1");
        $stmtMed->execute([':uid' => (int)$finanza['medico_id']]);
        $medico = $stmtMed->fetch(PDO::FETCH_ASSOC) ?: [];

        $sexoMed = strtolower(trim($medico['sexo'] ?? ''));
        $prefijoDr = ($sexoMed === 'femenino' || $sexoMed === 'f') ? 'Dra.' : 'Dr.';
        $nombreMedCompleto = trim(
            ($medico['nombre'] ?? '') . ' ' .
            ($medico['segundo_nombre'] ?? '') . ' ' .
            ($medico['apellido'] ?? '') . ' ' .
            ($medico['segundo_apellido'] ?? '')
        );
        $nombreMedCompleto = preg_replace('/\s+/', ' ', $nombreMedCompleto) ?: 'Médico Especialista';
        $tituloMedicoHeader = $prefijoDr . ' ' . $nombreMedCompleto;

        $rifTipo = trim($medico['rif_tipo'] ?? '');
        $rifNum  = trim($medico['rif_num'] ?? ($medico['rif'] ?? ''));
        if ($rifNum !== '') {
            $rifMedico = (strpos($rifNum, '-') !== false || $rifTipo === '') ? $rifNum : ($rifTipo . '-' . $rifNum);
        } elseif (!empty($medico['cedula'])) {
            $rifMedico = (!empty($medico['tipo_identificacion']) ? $medico['tipo_identificacion'] . '-' : '') . $medico['cedula'];
        } else {
            $rifMedico = 'J-00000000-0';
        }

        $domicilioMedico = !empty($medico['direccion_detalle'])
            ? $medico['direccion_detalle']
            : (!empty($medico['estado']) ? trim($medico['estado'] . ', ' . ($medico['pais'] ?? '')) : 'Domicilio Fiscal No Registrado');
        $telefonoMedico = !empty($medico['whatsapp']) ? $medico['whatsapp'] : ($medico['telefono'] ?? 'No especificado');

        // Color dinámico del tema
        $colorPrimario = self::normalizarHex(
            $temaOverride['color_primario'] ?? $medico['color_primario'] ?? null,
            '#B81D65'
        );
        $colorSuave = self::aclararHex($colorPrimario, 0.94);

        $numeroFactura = !empty($finanza['numero_factura'])
            ? trim((string)$finanza['numero_factura'])
            : str_pad((string)$finanzaId, 6, '0', STR_PAD_LEFT);

        $baseUrl = self::baseUrlPublica();
        $urlValidacion = $baseUrl . '/validar-factura?factura=' . urlencode($numeroFactura)
            . '&cp=' . urlencode($colorPrimario);
        $urlQrImagen = 'https://api.qrserver.com/v1/create-qr-code/?size=150x150&data=' . urlencode($urlValidacion);

        $razonSocial = trim(
            ($finanza['p_nombre'] ?? '') . ' ' .
            ($finanza['p_segundo_nombre'] ?? '') . ' ' .
            ($finanza['p_apellido'] ?? '') . ' ' .
            ($finanza['p_segundo_apellido'] ?? '')
        );
        $razonSocial = preg_replace('/\s+/', ' ', $razonSocial) ?: 'Cliente / Paciente General';

        $identificacionPac = (!empty($finanza['p_tipo_id']) ? $finanza['p_tipo_id'] . ': ' : '') . ($finanza['p_cedula'] ?? '—');
        $ubicacionPac = trim(($finanza['p_dir'] ?? '') . ' ' . ($finanza['p_estado'] ?? '') . ' ' . ($finanza['p_pais'] ?? ''));
        if ($ubicacionPac === '') {
            $ubicacionPac = 'No especificada';
        }

        // Monto en Bolívares desde debe_bs (o conversión por tasa_bcv)
        $montoBs = (float)($finanza['debe_bs'] ?? 0);
        if ($montoBs <= 0 && (float)($finanza['haber_bs'] ?? 0) > 0) {
            $montoBs = (float)$finanza['haber_bs'];
        }
        if ($montoBs <= 0 && (float)($finanza['debe'] ?? 0) > 0) {
            $tasa = (float)($finanza['tasa_bcv'] ?? 1);
            $montoBs = round((float)$finanza['debe'] * ($tasa > 0 ? $tasa : 1), 2);
        }

        $fechaEmision = !empty($finanza['fecha']) ? date('d/m/Y', strtotime($finanza['fecha'])) : date('d/m/Y');
        $esc = static fn($v) => htmlspecialchars((string)($v ?? ''), ENT_QUOTES, 'UTF-8');

        $html = '<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <title>Factura Serie A N° ' . $esc($numeroFactura) . '</title>
    <style>
        @page { size: portrait; margin: 15mm; }
        body { font-family: "Helvetica Neue", Helvetica, Arial, sans-serif; color: #1e293b; margin: 0; padding: 20px; background: #f8fafc; font-size: 13px; line-height: 1.5; }
        .invoice-box { max-width: 700px; margin: auto; background: #fff; padding: 30px; border: 1px solid #cbd5e1; border-radius: 8px; box-shadow: 0 4px 6px rgba(0,0,0,0.05); }
        .header-table { width: 100%; border-collapse: collapse; margin-bottom: 20px; }
        .header-table td { vertical-align: top; }
        .doctor-name { font-size: 20px; font-weight: bold; color: ' . $esc($colorPrimario) . '; margin: 0; }
        .invoice-title-red { font-size: 14px; font-weight: bold; text-align: right; color: #dc2626; }
        .invoice-number-red { font-size: 16px; color: #dc2626; font-weight: bold; }
        .patient-box { background: ' . $esc($colorSuave) . '; padding: 15px; border-radius: 6px; margin: 20px 0; border-left: 4px solid ' . $esc($colorPrimario) . '; }
        .patient-box table { width: 100%; border-collapse: collapse; }
        .patient-box td { padding: 4px 0; vertical-align: top; }
        .data-table { width: 100%; border-collapse: collapse; margin-top: 20px; font-size: 13px; }
        .data-table th { background: ' . $esc($colorPrimario) . '; color: #fff; padding: 10px; text-align: left; }
        .data-table td { padding: 10px; border-bottom: 1px solid #e2e8f0; }
        .text-right { text-align: right; }
        .qr-section { text-align: right; margin-top: 10px; }
        .footer-note { text-align: center; font-size: 11px; color: #64748b; margin-top: 30px; border-top: 1px solid #e2e8f0; padding-top: 15px; }
        .print-btn { text-align: center; margin-bottom: 20px; }
        .print-btn button { background: ' . $esc($colorPrimario) . '; color: #fff; border: none; padding: 10px 20px; border-radius: 5px; font-size: 15px; cursor: pointer; font-weight: bold; }
        @media print {
            .print-btn { display: none; }
            body { background: #fff; padding: 0; }
            .invoice-box { border: none; box-shadow: none; padding: 0; max-width: 100%; }
        }
    </style>
</head>
<body onload="window.print()">
    <div class="print-btn">
        <button onclick="window.print()">🖨️ Imprimir / Guardar PDF (Vertical)</button>
    </div>
    <div class="invoice-box">
        <table class="header-table">
            <tr>
                <td>
                    <div class="doctor-name">' . $esc($tituloMedicoHeader) . '</div>
                    <div style="font-size: 12px; color: #475569; margin-top: 5px;">
                        <strong>RIF / Identificación:</strong> ' . $esc($rifMedico) . '<br>
                        <strong>Domicilio Fiscal:</strong> ' . $esc($domicilioMedico) . '<br>
                        <strong>Teléfono:</strong> ' . $esc($telefonoMedico) . '
                    </div>
                </td>
                <td class="text-right">
                    <div class="invoice-title-red">
                        FACTURA SERIE A<br>
                        <span class="invoice-number-red">N° ' . $esc($numeroFactura) . '</span>
                    </div>
                    <div style="font-size: 12px; color: #64748b; margin-top: 5px;">
                        Fecha de Emisión: ' . $esc($fechaEmision) . '
                    </div>
                    <div class="qr-section">
                        <a href="' . $esc($urlValidacion) . '" target="_blank">
                            <img src="' . $esc($urlQrImagen) . '" alt="QR Validación" style="width: 85px; height: 85px; border: 1px solid #cbd5e1; padding: 2px; background: #fff;">
                        </a>
                    </div>
                </td>
            </tr>
        </table>

        <div class="patient-box">
            <table>
                <tr>
                    <td style="width: 30%;"><strong>Razón Social:</strong></td>
                    <td style="width: 70%;">' . $esc($razonSocial) . '</td>
                </tr>
                <tr>
                    <td><strong>RIF / Identificación:</strong></td>
                    <td>' . $esc($identificacionPac) . '</td>
                </tr>
                <tr>
                    <td><strong>Teléfono / WhatsApp:</strong></td>
                    <td>' . $esc(!empty($finanza['p_whatsapp']) ? $finanza['p_whatsapp'] : '—') . '</td>
                </tr>
                <tr>
                    <td><strong>Correo Electrónico:</strong></td>
                    <td>' . $esc(!empty($finanza['p_email']) ? $finanza['p_email'] : '—') . '</td>
                </tr>
                <tr>
                    <td><strong>Dirección:</strong></td>
                    <td>' . $esc($ubicacionPac) . '</td>
                </tr>
            </table>
        </div>

        <table class="data-table">
            <thead>
                <tr>
                    <th>Descripción del Concepto / Servicio</th>
                    <th class="text-right">Monto (Bs.)</th>
                </tr>
            </thead>
            <tbody>
                <tr>
                    <td>' . $esc($finanza['concepto'] ?? '') . '</td>
                    <td class="text-right">Bs. ' . number_format($montoBs, 2, ',', '.') . '</td>
                </tr>
            </tbody>
        </table>

        <table style="width: 100%; margin-top: 20px; font-size: 14px;">
            <tr>
                <td class="text-right" style="padding: 5px;"><strong>Total a Pagar:</strong></td>
                <td class="text-right" style="width: 180px; padding: 5px; font-size: 16px; font-weight: bold; color: ' . $esc($colorPrimario) . ';">
                    Bs. ' . number_format($montoBs, 2, ',', '.') . '
                </td>
            </tr>
        </table>

        <div class="footer-note">
            ESTE DOCUMENTO VA SIN TACHADURA NI ENMIENDA<br>
            Documento digital emitido conforme a las normativas de control fiscal interno. Verifique la autenticidad escaneando el código QR o ingresando en ' . $esc($baseUrl . '/validar-factura') . '<br>
            <strong>ORIGINAL CLIENTE</strong>
        </div>
    </div>
</body>
</html>';

        $dir = __DIR__ . '/../../public/uploads/facturas_medicas/';
        if (!is_dir($dir)) {
            @mkdir($dir, 0755, true);
        }

        $nombreArchivo = 'factura-' . $finanzaId . '-' . time() . '.html';
        @file_put_contents($dir . $nombreArchivo, $html);
        $urlArchivo = $baseUrl . '/uploads/facturas_medicas/' . $nombreArchivo;

        // Actualizar estado facturado en gm_finanzas (creando columnas si aún no existen)
        self::asegurarColumnasFacturacion($pdo);
        $upd = $pdo->prepare("
            UPDATE gm_finanzas 
            SET facturado = 1, numero_factura = :num, url_factura_pdf = :url 
            WHERE id = :id
        ");
        $upd->execute([
            ':num' => $numeroFactura,
            ':url' => $urlArchivo,
            ':id'  => $finanzaId
        ]);

        return [
            'html'           => $html,
            'url'            => $urlArchivo,
            'numero_factura' => $numeroFactura,
        ];
    }

    public static function asegurarColumnasFacturacion(PDO $pdo): void {
        try {
            $cols = $pdo->query("SHOW COLUMNS FROM gm_finanzas")->fetchAll(PDO::FETCH_COLUMN);
            if (!in_array('facturado', $cols, true)) {
                $pdo->exec("ALTER TABLE gm_finanzas ADD COLUMN facturado TINYINT(1) NOT NULL DEFAULT 0");
            }
            if (!in_array('numero_factura', $cols, true)) {
                $pdo->exec("ALTER TABLE gm_finanzas ADD COLUMN numero_factura VARCHAR(32) NULL");
            }
            if (!in_array('url_factura_pdf', $cols, true)) {
                $pdo->exec("ALTER TABLE gm_finanzas ADD COLUMN url_factura_pdf VARCHAR(500) NULL");
            }
        } catch (Exception $ignored) {}
    }

    /**
     * GET /validar-factura?factura=000001
     * Página pública de verificación de autenticidad de factura
     */
    public static function renderizarValidadorPublico(): void {
        header('Content-Type: text/html; charset=utf-8');
        $numRaw = isset($_GET['factura']) ? trim((string)$_GET['factura']) : (isset($_GET['numero']) ? trim((string)$_GET['numero']) : '');
        $colorPrimario = self::normalizarHex($_GET['cp'] ?? null, '#B81D65');
        $colorFondo    = self::aclararHex($colorPrimario, 0.95);
        $colorBadgeBg  = self::aclararHex($colorPrimario, 0.88);
        $esc = static fn($v) => htmlspecialchars((string)($v ?? ''), ENT_QUOTES, 'UTF-8');

        echo '<!DOCTYPE html><html lang="es"><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1.0"><title>Validación Pública de Factura Médica</title></head><body style="background:' . $esc($colorFondo) . '; margin:0; padding:16px;">';

        if ($numRaw === '') {
            echo '<div style="max-width:500px; margin:40px auto; padding:25px; background:#fff; border:1px solid #cbd5e1; border-radius:10px; text-align:center; font-family:sans-serif;">
                    <h3 style="color:#d97706; margin-top:0;">⚠️ Número de Factura no Proporcionado</h3>
                    <p style="color:#64748b; font-size:14px;">Por favor, escanee un código QR válido desde una factura oficial.</p>
                  </div></body></html>';
            return;
        }

        try {
            $pdo = Database::conectar();
            self::asegurarColumnasFacturacion($pdo);
            $idLimpio = (int)ltrim($numRaw, '0');
            $stmt = $pdo->prepare("
                SELECT f.*, p.nombre AS p_nombre, p.apellido AS p_apellido, p.tipo_identificacion AS p_tipo_id, p.cedula AS p_cedula
                FROM gm_finanzas f
                LEFT JOIN gm_usuarios_extendidos p ON f.paciente_id = p.user_id
                WHERE f.id = :id OR f.numero_factura = :num
                LIMIT 1
            ");
            $stmt->execute([':id' => $idLimpio, ':num' => $numRaw]);
            $factura = $stmt->fetch(PDO::FETCH_ASSOC);

            echo '<div style="max-width:600px; margin:32px auto; padding:30px; background:#ffffff; border:1px solid #e2e8f0; border-radius:14px; box-shadow:0 4px 12px rgba(0,0,0,0.06); font-family:\'Helvetica Neue\', Helvetica, Arial, sans-serif;">';

            if (!$factura) {
                echo '<div style="text-align:center; padding:20px;">
                        <div style="font-size:48px; margin-bottom:10px;">❌</div>
                        <h2 style="color:#dc2626; margin-top:0;">Factura No Encontrada o Inválida</h2>
                        <p style="color:#64748b; font-size:14px;">El número de factura proporcionado (' . $esc($numRaw) . ') no corresponde a ningún registro oficial.</p>
                      </div>';
            } else {
                $numFmt = !empty($factura['numero_factura']) ? $factura['numero_factura'] : str_pad((string)$factura['id'], 6, '0', STR_PAD_LEFT);
                $montoBs = (float)($factura['debe_bs'] ?? 0);
                if ($montoBs <= 0) {
                    $montoBs = round((float)($factura['debe'] ?? 0) * max(1.0, (float)($factura['tasa_bcv'] ?? 1)), 2);
                }
                $ced = (!empty($factura['p_tipo_id']) ? $factura['p_tipo_id'] . '-' : '') . ($factura['p_cedula'] ?? '');
                $fechaEmision = !empty($factura['fecha']) ? date('d/m/Y', strtotime($factura['fecha'])) : '—';

                echo '<div style="text-align:center; border-bottom:2px solid ' . $esc($colorPrimario) . '; padding-bottom:20px; margin-bottom:20px;">
                        <div style="display:inline-block; background:' . $esc($colorBadgeBg) . '; color:' . $esc($colorPrimario) . '; padding:6px 16px; border-radius:20px; font-weight:bold; font-size:13px; margin-bottom:10px;">
                            ✓ Factura Válida y Verificada
                        </div>
                        <h2 style="color:' . $esc($colorPrimario) . '; margin:0; font-size:20px; text-transform:uppercase;">VALIDACIÓN DE AUTENTICIDAD DE FACTURA</h2>
                      </div>
                      <table style="width:100%; border-collapse:collapse; font-size:14px;">
                        <tr style="border-bottom:1px solid #f1f5f9;">
                            <td style="padding:12px; color:#64748b; width:40%;"><strong>Número de Factura:</strong></td>
                            <td style="padding:12px; color:#dc2626; font-weight:bold; font-family:monospace;">SERIE A - N° ' . $esc($numFmt) . '</td>
                        </tr>
                        <tr style="border-bottom:1px solid #f1f5f9;">
                            <td style="padding:12px; color:#64748b;"><strong>Estado:</strong></td>
                            <td style="padding:12px; color:#166534; font-weight:bold;">Emitida / Facturada</td>
                        </tr>';
                if (!empty($factura['p_nombre'])) {
                    echo '<tr style="border-bottom:1px solid #f1f5f9;">
                            <td style="padding:12px; color:#64748b;"><strong>Paciente / Cliente:</strong></td>
                            <td style="padding:12px; color:#0f172a; font-weight:600;">' . $esc(trim($factura['p_nombre'] . ' ' . ($factura['p_apellido'] ?? ''))) . '</td>
                          </tr>
                          <tr style="border-bottom:1px solid #f1f5f9;">
                            <td style="padding:12px; color:#64748b;"><strong>Identificación:</strong></td>
                            <td style="padding:12px; color:#0f172a; font-family:monospace;">' . $esc($ced) . '</td>
                          </tr>';
                }
                echo '  <tr style="border-bottom:1px solid #f1f5f9;">
                            <td style="padding:12px; color:#64748b;"><strong>Concepto:</strong></td>
                            <td style="padding:12px; color:#0f172a;">' . $esc($factura['concepto']) . '</td>
                        </tr>
                        <tr style="border-bottom:1px solid #f1f5f9;">
                            <td style="padding:12px; color:#64748b;"><strong>Monto Total:</strong></td>
                            <td style="padding:12px; color:#0f172a; font-weight:bold;">Bs. ' . number_format($montoBs, 2, ',', '.') . '</td>
                        </tr>
                        <tr>
                            <td style="padding:12px; color:#64748b;"><strong>Fecha de Emisión:</strong></td>
                            <td style="padding:12px; color:#0f172a;">' . $esc($fechaEmision) . '</td>
                        </tr>
                      </table>';

                if (!empty($factura['url_factura_pdf'])) {
                    echo '<div style="margin-top:26px; text-align:center;">
                            <a href="' . $esc($factura['url_factura_pdf']) . '" target="_blank" style="display:inline-block; background-color:' . $esc($colorPrimario) . '; color:#ffffff; padding:12px 24px; border-radius:8px; font-weight:bold; text-decoration:none; font-size:14px;">
                                📥 Descargar Factura (PDF)
                            </a>
                          </div>';
                }
            }
            echo '</div>';
        } catch (Exception $e) {
            echo '<div style="max-width:500px; margin:40px auto; padding:20px; background:#fff; color:#b91c1c;">Error: ' . $esc($e->getMessage()) . '</div>';
        }

        echo '</body></html>';
    }
}