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
     * Extrae la ciudad o estado del domicilio fiscal del médico para "LUGAR DE EMISIÓN"
     */
    private static function extraerLugarEmision(array $medico): string {
        $ciudad = trim((string)($medico['ciudad'] ?? $medico['municipio'] ?? ''));
        if ($ciudad !== '') {
            return mb_strtoupper($ciudad, 'UTF-8');
        }
        $estado = trim((string)($medico['estado'] ?? ''));
        if ($estado !== '') {
            return mb_strtoupper($estado, 'UTF-8');
        }
        $dir = trim((string)($medico['direccion_detalle'] ?? ''));
        if ($dir !== '') {
            $partes = array_filter(array_map('trim', explode(',', $dir)));
            if (!empty($partes)) {
                return mb_strtoupper((string)end($partes), 'UTF-8');
            }
        }
        return 'CARACAS';
    }

    /**
     * Genera la Factura digital replicando la estructura oficial con datos dinámicos del médico,
     * colores del tema activo, QR en lugar de logo y números de Factura y Control en rojo.
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

        // Obtener datos completos del médico (incluyendo campos de perfil / ajustes si existen)
        $stmtMed = $pdo->prepare("SELECT * FROM gm_usuarios_extendidos WHERE user_id = :uid LIMIT 1");
        $stmtMed->execute([':uid' => (int)$finanza['medico_id']]);
        $medico = $stmtMed->fetch(PDO::FETCH_ASSOC) ?: [];

        // Prefijo Dr. / Dra. y Nombre completo
        $sexoMed = strtolower(trim((string)($medico['sexo'] ?? '')));
        $prefijoDr = ($sexoMed === 'femenino' || $sexoMed === 'f') ? 'DRA.' : 'DR.';
        $prefijoDrFirma = ($sexoMed === 'femenino' || $sexoMed === 'f') ? 'Dra.' : 'Dr.';

        $nombreMedCompleto = trim(
            ($medico['nombre'] ?? '') . ' ' .
            ($medico['segundo_nombre'] ?? '') . ' ' .
            ($medico['apellido'] ?? '') . ' ' .
            ($medico['segundo_apellido'] ?? '')
        );
        $nombreMedCompleto = preg_replace('/\s+/', ' ', $nombreMedCompleto) ?: 'Médico Especialista';
        $tituloMedicoMayus = mb_strtoupper($prefijoDr . ' ' . $nombreMedCompleto, 'UTF-8');
        $tituloMedicoFirma = $prefijoDrFirma . ' ' . $nombreMedCompleto;

        // Especialidad
        $especialidad = trim((string)($medico['especialidad'] ?? $medico['especialidad_medica'] ?? 'MEDICINA ESPECIALIZADA'));
        $especialidadMayus = mb_strtoupper($especialidad, 'UTF-8');

        // Dirección fiscal y teléfono WhatsApp del médico
        $domicilioMedico = trim((string)($medico['direccion_detalle'] ?? ''));
        if ($domicilioMedico === '') {
            $domicilioMedico = trim(($medico['estado'] ?? '') . ', ' . ($medico['pais'] ?? ''), ', ');
        }
        if ($domicilioMedico === '') {
            $domicilioMedico = 'Domicilio Fiscal No Registrado';
        }
        $telefonoMedico = trim((string)($medico['whatsapp'] ?? $medico['telefono'] ?? 'No especificado'));
        $lugarEmision   = self::extraerLugarEmision($medico);

        // MPPS, CMM y RIF del médico
        $mpps = trim((string)($medico['mpps'] ?? $medico['registro_mpps'] ?? '—'));
        $cmm  = trim((string)($medico['cmg'] ?? $medico['cmm'] ?? $medico['colegio_medico'] ?? '—'));

        $rifTipo = trim((string)($medico['rif_tipo'] ?? ''));
        $rifNum  = trim((string)($medico['rif_num'] ?? $medico['rif'] ?? ''));
        if ($rifNum !== '') {
            $rifMedico = (strpos($rifNum, '-') !== false || $rifTipo === '') ? $rifNum : ($rifTipo . '-' . $rifNum);
        } elseif (!empty($medico['cedula'])) {
            $rifMedico = (!empty($medico['tipo_identificacion']) ? $medico['tipo_identificacion'] . '-' : 'V-') . $medico['cedula'];
        } else {
            $rifMedico = 'J-00000000-0';
        }

        $cedulaMedicoFirma = (!empty($medico['tipo_identificacion']) ? $medico['tipo_identificacion'] . '-' : 'V-') . ($medico['cedula'] ?? '—');

        // Colores dinámicos del tema activo de la app
        $colorPrimario = self::normalizarHex(
            $temaOverride['color_primario'] ?? $medico['color_primario'] ?? null,
            '#B81D65'
        );
        $colorSuave = self::aclararHex($colorPrimario, 0.93);
        $colorBorde = self::aclararHex($colorPrimario, 0.65);

        // Número de Factura y Número de Control (en rojo)
        $numeroFactura = !empty($finanza['numero_factura'])
            ? trim((string)$finanza['numero_factura'])
            : str_pad((string)$finanzaId, 6, '0', STR_PAD_LEFT);
        $numeroControl = '00-' . $numeroFactura;

        // QR de validación pública (reemplaza el logo)
        $baseUrl = self::baseUrlPublica();
        $urlValidacion = $baseUrl . '/validar-factura?factura=' . urlencode($numeroFactura)
            . '&cp=' . urlencode($colorPrimario);
        $urlQrImagen = 'https://api.qrserver.com/v1/create-qr-code/?size=160x160&data=' . urlencode($urlValidacion);

        // Datos del Paciente / Cliente
        $razonSocial = trim(
            ($finanza['p_nombre'] ?? '') . ' ' .
            ($finanza['p_segundo_nombre'] ?? '') . ' ' .
            ($finanza['p_apellido'] ?? '') . ' ' .
            ($finanza['p_segundo_apellido'] ?? '')
        );
        $razonSocial = mb_strtoupper(preg_replace('/\s+/', ' ', $razonSocial) ?: 'CLIENTE / PACIENTE GENERAL', 'UTF-8');

        $identificacionPac = (!empty($finanza['p_tipo_id']) ? $finanza['p_tipo_id'] . '-' : '') . ($finanza['p_cedula'] ?? '—');
        $ubicacionPac = trim(($finanza['p_dir'] ?? '') . ' ' . ($finanza['p_estado'] ?? '') . ' ' . ($finanza['p_pais'] ?? ''));
        if ($ubicacionPac === '') {
            $ubicacionPac = 'NO ESPECIFICADA';
        } else {
            $ubicacionPac = mb_strtoupper($ubicacionPac, 'UTF-8');
        }
        $telefonoPac = !empty($finanza['p_whatsapp']) ? $finanza['p_whatsapp'] : '—';

        // Fecha desglosada: DÍA / MES / AÑO
        $tsFecha = !empty($finanza['fecha']) ? strtotime((string)$finanza['fecha']) : time();
        $diaEmision  = date('d', $tsFecha);
        $mesEmision  = date('m', $tsFecha);
        $anioEmision = date('Y', $tsFecha);

        // Monto en Bolívares
        $montoBs = (float)($finanza['debe_bs'] ?? 0);
        if ($montoBs <= 0 && (float)($finanza['haber_bs'] ?? 0) > 0) {
            $montoBs = (float)$finanza['haber_bs'];
        }
        if ($montoBs <= 0 && (float)($finanza['debe'] ?? 0) > 0) {
            $tasa = (float)($finanza['tasa_bcv'] ?? 1);
            $montoBs = round((float)$finanza['debe'] * ($tasa > 0 ? $tasa : 1), 2);
        }
        $montoBsFmt = number_format($montoBs, 2, ',', '.');

        // Concepto exacto manejado en el sistema
        $concepto = trim((string)($finanza['concepto'] ?? 'HONORARIOS MÉDICOS'));

        $esc = static fn($v) => htmlspecialchars((string)($v ?? ''), ENT_QUOTES, 'UTF-8');

        $html = '<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <title>Factura N° ' . $esc($numeroFactura) . '</title>
    <style>
        @page { size: letter portrait; margin: 12mm; }
        * { box-sizing: border-box; }
        body {
            font-family: "Helvetica Neue", Helvetica, Arial, sans-serif;
            color: #1e293b;
            margin: 0;
            padding: 18px;
            background: #f1f5f9;
            font-size: 12px;
            line-height: 1.35;
        }
        .print-btn { text-align: center; margin-bottom: 14px; }
        .print-btn button {
            background: ' . $esc($colorPrimario) . ';
            color: #fff;
            border: none;
            padding: 10px 22px;
            border-radius: 6px;
            font-size: 14px;
            cursor: pointer;
            font-weight: bold;
            box-shadow: 0 2px 6px rgba(0,0,0,0.12);
        }
        .factura-sheet {
            max-width: 760px;
            margin: 0 auto;
            background: #ffffff;
            border: 2px solid ' . $esc($colorPrimario) . ';
            border-radius: 10px;
            padding: 22px 24px;
            box-shadow: 0 6px 18px rgba(0,0,0,0.06);
        }
        .top-grid {
            width: 100%;
            border-collapse: collapse;
            margin-bottom: 12px;
        }
        .top-grid td { vertical-align: top; }
        .qr-box {
            width: 96px;
            padding-right: 12px;
            text-align: center;
        }
        .qr-box img {
            width: 90px;
            height: 90px;
            border: 1.5px solid ' . $esc($colorPrimario) . ';
            border-radius: 6px;
            padding: 3px;
            background: #fff;
        }
        .qr-caption {
            font-size: 8.5px;
            color: #64748b;
            margin-top: 2px;
            font-weight: bold;
        }
        .doc-title {
            font-size: 18px;
            font-weight: 800;
            color: ' . $esc($colorPrimario) . ';
            letter-spacing: 0.3px;
            margin: 0 0 2px 0;
        }
        .doc-spec {
            font-size: 11.5px;
            font-weight: 700;
            color: ' . $esc($colorPrimario) . ';
            margin: 0 0 6px 0;
        }
        .doc-meta {
            font-size: 11px;
            color: #334155;
            line-height: 1.4;
        }
        .doc-fiscal-line {
            margin-top: 4px;
            font-size: 11px;
            font-weight: 700;
            color: #0f172a;
        }
        /* Números en ROJO según imagen de referencia */
        .factura-num-box {
            text-align: right;
            margin-bottom: 6px;
        }
        .factura-num-red {
            font-size: 15px;
            font-weight: 800;
            color: #dc2626;
            letter-spacing: 0.5px;
        }
        .control-num-red {
            font-size: 12.5px;
            font-weight: 800;
            color: #dc2626;
            margin-top: 2px;
        }
        .emision-table {
            width: 100%;
            border-collapse: collapse;
            margin-top: 6px;
            font-size: 10.5px;
        }
        .emision-table th {
            background: ' . $esc($colorPrimario) . ';
            color: #ffffff;
            border: 1px solid ' . $esc($colorPrimario) . ';
            padding: 4px 6px;
            text-align: center;
            font-weight: 700;
        }
        .emision-table td {
            border: 1px solid ' . $esc($colorPrimario) . ';
            padding: 5px 6px;
            text-align: center;
            font-weight: 700;
            color: #0f172a;
            background: #ffffff;
        }
        /* Caja de datos del cliente / paciente */
        .cliente-box {
            width: 100%;
            border-collapse: collapse;
            margin-top: 8px;
            margin-bottom: 12px;
            border: 1.5px solid ' . $esc($colorPrimario) . ';
            border-radius: 6px;
        }
        .cliente-box td {
            padding: 6px 10px;
            border-bottom: 1px solid ' . $esc($colorBorde) . ';
            font-size: 11.5px;
        }
        .cliente-box tr:last-child td { border-bottom: none; }
        .lbl-theme {
            color: ' . $esc($colorPrimario) . ';
            font-weight: 700;
        }
        .chk-box {
            display: inline-block;
            width: 13px;
            height: 13px;
            border: 1.5px solid ' . $esc($colorPrimario) . ';
            text-align: center;
            line-height: 11px;
            font-size: 10px;
            font-weight: bold;
            margin: 0 3px;
            vertical-align: middle;
        }
        /* Tabla principal de ítems */
        .items-table {
            width: 100%;
            border-collapse: collapse;
            border: 1.5px solid ' . $esc($colorPrimario) . ';
        }
        .items-table th {
            background: ' . $esc($colorPrimario) . ';
            color: #ffffff;
            padding: 7px 10px;
            font-size: 11.5px;
            font-weight: 700;
            border: 1px solid ' . $esc($colorPrimario) . ';
        }
        .items-table td {
            padding: 9px 10px;
            border-left: 1px solid ' . $esc($colorPrimario) . ';
            border-right: 1px solid ' . $esc($colorPrimario) . ';
            border-bottom: 1px solid ' . $esc($colorBorde) . ';
            font-size: 12px;
            vertical-align: top;
        }
        .items-body-min {
            height: 175px;
            vertical-align: top;
        }
        .firma-sello {
            margin-top: 26px;
            text-align: center;
            color: ' . $esc($colorPrimario) . ';
            font-size: 11px;
            line-height: 1.35;
        }
        .firma-linea {
            width: 240px;
            border-top: 1px solid ' . $esc($colorPrimario) . ';
            margin: 0 auto 4px auto;
        }
        /* Bloque inferior: Forma de pago + Totales */
        .bottom-grid {
            width: 100%;
            border-collapse: collapse;
            margin-top: 10px;
        }
        .bottom-grid td { vertical-align: top; }
        .pago-box {
            border: 1.5px solid ' . $esc($colorPrimario) . ';
            border-radius: 6px;
            padding: 8px 10px;
            font-size: 10.5px;
            background: ' . $esc($colorSuave) . ';
        }
        .totales-table {
            width: 100%;
            border-collapse: collapse;
            border: 1.5px solid ' . $esc($colorPrimario) . ';
            font-size: 11.5px;
        }
        .totales-table td {
            border: 1px solid ' . $esc($colorPrimario) . ';
            padding: 6px 8px;
        }
        .totales-lbl {
            background: ' . $esc($colorSuave) . ';
            color: ' . $esc($colorPrimario) . ';
            font-weight: 700;
        }
        .totales-val {
            text-align: right;
            font-weight: 800;
            color: #0f172a;
        }
        .pie-legal {
            margin-top: 10px;
            font-size: 9.5px;
            color: #475569;
            text-align: center;
            line-height: 1.4;
        }
        .original-tag {
            text-align: right;
            font-weight: 800;
            font-size: 11px;
            color: ' . $esc($colorPrimario) . ';
            margin-top: 4px;
        }
        @media print {
            .print-btn { display: none; }
            body { background: #ffffff; padding: 0; }
            .factura-sheet { box-shadow: none; max-width: 100%; border-radius: 0; }
        }
    </style>
</head>
<body onload="window.print()">
    <div class="print-btn">
        <button onclick="window.print()">🖨️ Imprimir / Guardar Factura PDF</button>
    </div>

    <div class="factura-sheet">
        <!-- CABECERA: QR + DATOS DEL MÉDICO (IZQ) | FACTURA / CONTROL ROJO + LUGAR Y FECHA (DER) -->
        <table class="top-grid">
            <tr>
                <td class="qr-box">
                    <a href="' . $esc($urlValidacion) . '" target="_blank" title="Verificar autenticidad">
                        <img src="' . $esc($urlQrImagen) . '" alt="QR Validación">
                    </a>
                    <div class="qr-caption">VALIDAR QR</div>
                </td>
                <td style="width: 52%; padding-right: 10px;">
                    <div class="doc-title">' . $esc($tituloMedicoMayus) . '</div>
                    <div class="doc-spec">' . $esc($especialidadMayus) . '</div>
                    <div class="doc-meta">
                        <strong>Dirección Fiscal:</strong> ' . $esc($domicilioMedico) . '<br>
                        <strong>Teléfono WhatsApp:</strong> ' . $esc($telefonoMedico) . '
                    </div>
                    <div class="doc-fiscal-line">
                        MPPS ' . $esc($mpps) . ' / CMM ' . $esc($cmm) . ' / R.I.F.: ' . $esc($rifMedico) . '
                    </div>
                </td>
                <td style="width: 34%;">
                    <div class="factura-num-box">
                        <div class="factura-num-red">FACTURA N° ' . $esc($numeroFactura) . '</div>
                        <div class="control-num-red">N° DE CONTROL ' . $esc($numeroControl) . '</div>
                    </div>
                    <table class="emision-table">
                        <thead>
                            <tr>
                                <th>LUGAR DE EMISIÓN</th>
                                <th>DÍA</th>
                                <th>MES</th>
                                <th>AÑO</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr>
                                <td>' . $esc($lugarEmision) . '</td>
                                <td>' . $esc($diaEmision) . '</td>
                                <td>' . $esc($mesEmision) . '</td>
                                <td>' . $esc($anioEmision) . '</td>
                            </tr>
                        </tbody>
                    </table>
                </td>
            </tr>
        </table>

        <!-- DATOS DEL PACIENTE / CLIENTE -->
        <table class="cliente-box">
            <tr>
                <td style="width: 66%;">
                    <span class="lbl-theme">Nombre o Razón Social:</span>
                    <strong>' . $esc($razonSocial) . '</strong>
                </td>
                <td style="width: 34%; border-left: 1px solid ' . $esc($colorBorde) . ';">
                    <span class="lbl-theme">R.I.F. / C.I.:</span>
                    <strong>' . $esc($identificacionPac) . '</strong>
                </td>
            </tr>
            <tr>
                <td colspan="2">
                    <span class="lbl-theme">Domicilio Fiscal:</span>
                    ' . $esc($ubicacionPac) . '
                </td>
            </tr>
            <tr>
                <td>
                    <span class="lbl-theme">TELÉFONO:</span>
                    ' . $esc($telefonoPac) . '
                </td>
                <td style="border-left: 1px solid ' . $esc($colorBorde) . ';">
                    <span class="lbl-theme">Cond. Pago:</span>
                    CONTADO <span class="chk-box">✓</span>
                    CRÉDITO <span class="chk-box">&nbsp;</span> DÍAS
                </td>
            </tr>
        </table>

        <!-- TABLA DE CONCEPTO Y MONTO -->
        <table class="items-table">
            <thead>
                <tr>
                    <th style="width: 10%; text-align: center;">CANT.</th>
                    <th style="width: 54%; text-align: left;">DESCRIPCIÓN</th>
                    <th style="width: 18%; text-align: right;">P. UNIT. (Bs.)</th>
                    <th style="width: 18%; text-align: right;">TOTAL (Bs.)</th>
                </tr>
            </thead>
            <tbody>
                <tr class="items-body-min">
                    <td style="text-align: center; font-weight: bold;">1</td>
                    <td>
                        <div style="font-weight: bold; color: #0f172a; margin-bottom: 6px;">
                            ' . $esc($concepto) . '
                        </div>
                        <div style="font-size: 11px; color: #475569;">
                            PACIENTE: <strong>' . $esc($razonSocial) . '</strong>
                        </div>

                        <div class="firma-sello">
                            <div class="firma-linea"></div>
                            <strong>' . $esc($tituloMedicoFirma) . '</strong><br>
                            ' . $esc($especialidad) . '<br>
                            M.P.P.S.: ' . $esc($mpps) . ' &nbsp;|&nbsp; C.I.: ' . $esc($cedulaMedicoFirma) . '
                        </div>
                    </td>
                    <td style="text-align: right; font-weight: bold;">' . $esc($montoBsFmt) . '</td>
                    <td style="text-align: right; font-weight: bold;">' . $esc($montoBsFmt) . '</td>
                </tr>
            </tbody>
        </table>

        <!-- PIE DE FACTURA: FORMA DE PAGO + RESUMEN FISCAL -->
        <table class="bottom-grid">
            <tr>
                <td style="width: 62%; padding-right: 10px;">
                    <div class="pago-box">
                        <div style="margin-bottom: 6px;">
                            <span class="lbl-theme">FORMA DE PAGO:</span>
                            EFEC. <span class="chk-box">&nbsp;</span>
                            PAGO MÓVIL <span class="chk-box">&nbsp;</span>
                            T. DÉBITO <span class="chk-box">&nbsp;</span>
                            TRANSF. <span class="chk-box">&nbsp;</span>
                        </div>
                        <div style="margin-bottom: 8px;">
                            <span class="lbl-theme">N° REF.:</span> ______________________
                            &nbsp;&nbsp;
                            <span class="lbl-theme">RECIBÍ CONFORME:</span> _________________
                        </div>
                        <div style="font-size: 10px; font-weight: bold; color: ' . $esc($colorPrimario) . '; text-align: center;">
                            ESTA FACTURA VA SIN ENMIENDA NI TACHADURAS
                        </div>
                    </div>
                </td>
                <td style="width: 38%;">
                    <table class="totales-table">
                        <tr>
                            <td class="totales-lbl" style="width: 55%;">BASE IMPONIBLE</td>
                            <td class="totales-val" style="width: 45%;">Bs. ' . $esc($montoBsFmt) . '</td>
                        </tr>
                        <tr>
                            <td class="totales-lbl">IVA &nbsp;&nbsp; 0,00 % (Exento)</td>
                            <td class="totales-val">Bs. 0,00</td>
                        </tr>
                        <tr>
                            <td class="totales-lbl" style="font-size: 12.5px;">VALOR TOTAL</td>
                            <td class="totales-val" style="font-size: 13px; color: ' . $esc($colorPrimario) . ';">Bs. ' . $esc($montoBsFmt) . '</td>
                        </tr>
                    </table>
                </td>
            </tr>
        </table>

        <div class="pie-legal">
            Documento digital emitido conforme a las normativas de control interno. Verifique la autenticidad escaneando el código QR o ingresando en <strong>' . $esc($baseUrl . '/validar-factura') . '</strong>
        </div>
        <div class="original-tag">ORIGINAL</div>
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
                            <td style="padding:12px; color:#dc2626; font-weight:bold; font-family:monospace;">N° ' . $esc($numFmt) . ' &nbsp;|&nbsp; Control: 00-' . $esc($numFmt) . '</td>
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