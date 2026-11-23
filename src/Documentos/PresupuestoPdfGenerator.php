<?php
namespace App\Documentos;

use App\Config\Database;
use PDO;
use Exception;

class PresupuestoPdfGenerator {

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

    private static function primerTextoNoVacio(array $candidatos, string $fallback = ''): string {
        foreach ($candidatos as $val) {
            $limpio = trim((string)($val ?? ''));
            if ($limpio !== '') {
                return $limpio;
            }
        }
        return $fallback;
    }

    private static function extraerLugarEmision(array $medico, array $metas = []): string {
        $ciudad = self::primerTextoNoVacio([$medico['ciudad'] ?? '', $medico['municipio'] ?? '']);
        if ($ciudad !== '') {
            return mb_strtoupper($ciudad, 'UTF-8');
        }
        $estado = self::primerTextoNoVacio([$medico['estado'] ?? '', $metas['estado_fiscal'] ?? '']);
        if ($estado !== '') {
            return mb_strtoupper($estado, 'UTF-8');
        }
        $dir = self::primerTextoNoVacio([$medico['direccion_detalle'] ?? '', $metas['direccion_fiscal'] ?? '']);
        if ($dir !== '') {
            $partes = array_filter(array_map('trim', explode(',', $dir)));
            if (!empty($partes)) {
                return mb_strtoupper((string)end($partes), 'UTF-8');
            }
        }
        return 'CARACAS';
    }

    private static function obtenerMetasMedico(PDO $pdo, int $medicoId): array {
        $metas = [];
        try {
            $stmt = $pdo->prepare("SELECT meta_key, meta_value FROM gm_medico_meta WHERE medico_id = ?");
            $stmt->execute([$medicoId]);
            while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
                $metas[$row['meta_key']] = $row['meta_value'];
            }
        } catch (Exception $ignored) {}
        return $metas;
    }

    /**
     * Calcula el correlativo independiente de presupuesto para cada médico:
     * Toma MAX(numero_presupuesto) de ese mismo médico + 1 (nunca menor al inicio_presupuesto configurado).
     */
    private static function obtenerSiguienteNumeroPresupuestoMedico(PDO $pdo, int $medicoId, array $metas): string {
        $inicioConfigurado = max(1, intval($metas['inicio_presupuesto'] ?? 1));

        $stmtMax = $pdo->prepare("
            SELECT COALESCE(MAX(CAST(numero_presupuesto AS UNSIGNED)), 0)
            FROM gm_presupuestos
            WHERE medico_id = :med
              AND numero_presupuesto IS NOT NULL
              AND numero_presupuesto <> ''
        ");
        $stmtMax->execute([':med' => $medicoId]);
        $maxActual = (int)$stmtMax->fetchColumn();

        $siguiente = ($maxActual >= $inicioConfigurado) ? ($maxActual + 1) : $inicioConfigurado;
        return str_pad((string)$siguiente, 6, '0', STR_PAD_LEFT);
    }

    public static function asegurarTablaPresupuestos(PDO $pdo): void {
        try {
            $pdo->exec("
                CREATE TABLE IF NOT EXISTS gm_presupuestos (
                    id INT AUTO_INCREMENT PRIMARY KEY,
                    numero_presupuesto VARCHAR(32) NULL,
                    medico_id INT NOT NULL,
                    paciente_id INT NOT NULL DEFAULT 0,
                    paciente_nombre VARCHAR(200) NOT NULL DEFAULT '',
                    paciente_cedula VARCHAR(50) NOT NULL DEFAULT '',
                    paciente_telefono VARCHAR(50) NULL,
                    paciente_direccion VARCHAR(300) NULL,
                    fecha_emision DATE NOT NULL,
                    fecha_vencimiento DATE NOT NULL,
                    items_json LONGTEXT NOT NULL,
                    total_usd DECIMAL(12,2) NOT NULL DEFAULT 0.00,
                    total_bs DECIMAL(14,2) NOT NULL DEFAULT 0.00,
                    tasa_bcv DECIMAL(12,4) NOT NULL DEFAULT 1.0000,
                    observaciones TEXT NULL,
                    estatus VARCHAR(20) NOT NULL DEFAULT 'pendiente',
                    finanza_id INT NULL,
                    url_pdf VARCHAR(500) NULL,
                    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                    INDEX idx_medico_fecha (medico_id, fecha_emision),
                    INDEX idx_vencimiento (fecha_vencimiento)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
            ");

            $cols = $pdo->query("SHOW COLUMNS FROM gm_presupuestos")->fetchAll(PDO::FETCH_COLUMN);
            if (!in_array('estatus', $cols, true)) {
                $pdo->exec("ALTER TABLE gm_presupuestos ADD COLUMN estatus VARCHAR(20) NOT NULL DEFAULT 'pendiente'");
            }
            if (!in_array('finanza_id', $cols, true)) {
                $pdo->exec("ALTER TABLE gm_presupuestos ADD COLUMN finanza_id INT NULL");
            }
        } catch (Exception $ignored) {}
    }

    /**
     * Genera el documento HTML/PDF del Presupuesto Médico con numeración correlativa independiente por médico,
     * especialidad real desde BD, validez de 3 días, QR y colores dinámicos del tema activo.
     */
    public static function generarPresupuestoPdf(int $presupuestoId, ?PDO $pdoExterno = null, array $temaOverride = []): array {
        $pdo = $pdoExterno ?? Database::conectar();
        self::asegurarTablaPresupuestos($pdo);

        $stmt = $pdo->prepare("
            SELECT pr.*,
                   p.nombre AS p_nombre, p.segundo_nombre AS p_segundo_nombre,
                   p.apellido AS p_apellido, p.segundo_apellido AS p_segundo_apellido,
                   p.tipo_identificacion AS p_tipo_id, p.cedula AS p_cedula,
                   p.whatsapp AS p_whatsapp, p.email AS p_email,
                   p.pais AS p_pais, p.estado AS p_estado, p.direccion_detalle AS p_dir
            FROM gm_presupuestos pr
            LEFT JOIN gm_usuarios_extendidos p ON pr.paciente_id = p.user_id
            WHERE pr.id = :id
            LIMIT 1
        ");
        $stmt->execute([':id' => $presupuestoId]);
        $pres = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$pres) {
            return ['html' => '', 'url' => '', 'numero_presupuesto' => ''];
        }

        $medicoId = (int)$pres['medico_id'];
        $stmtMed = $pdo->prepare("SELECT * FROM gm_usuarios_extendidos WHERE user_id = :uid1 OR id = :uid2 LIMIT 1");
        $stmtMed->execute([':uid1' => $medicoId, ':uid2' => $medicoId]);
        $medico = $stmtMed->fetch(PDO::FETCH_ASSOC) ?: [];
        $realMedicoId = !empty($medico['user_id']) ? (int)$medico['user_id'] : $medicoId;

        $metas = self::obtenerMetasMedico($pdo, $realMedicoId);

        $sexoMed = strtolower(trim((string)($medico['sexo'] ?? '')));
        $prefijoDr = ($sexoMed === 'femenino' || $sexoMed === 'f') ? 'DRA.' : 'DR.';
        $prefijoDrFirma = ($sexoMed === 'femenino' || $sexoMed === 'f') ? 'Dra.' : 'Dr.';

        $nombreMedCompleto = trim(
            ($medico['nombre'] ?? '') . ' ' .
            ($medico['segundo_nombre'] ?? ($metas['segundo_nombre'] ?? '')) . ' ' .
            ($medico['apellido'] ?? '') . ' ' .
            ($medico['segundo_apellido'] ?? ($metas['segundo_apellido'] ?? ''))
        );
        $nombreMedCompleto = preg_replace('/\s+/', ' ', $nombreMedCompleto) ?: 'Médico Especialista';
        $tituloMedicoMayus = mb_strtoupper($prefijoDr . ' ' . $nombreMedCompleto, 'UTF-8');
        $tituloMedicoFirma = $prefijoDrFirma . ' ' . $nombreMedCompleto;

        // Especialidad real almacenada en BD (gm_usuarios_extendidos o gm_medico_meta)
        $especialidad = self::primerTextoNoVacio([
            $medico['especialidad'] ?? '',
            $medico['especialidad_medica'] ?? '',
            $metas['especialidad'] ?? '',
            $medico['ocupacion'] ?? '',
            $metas['ocupacion'] ?? '',
        ], 'MEDICINA ESPECIALIZADA');
        $especialidadMayus = mb_strtoupper($especialidad, 'UTF-8');

        $domicilioMedico = self::primerTextoNoVacio([
            $medico['direccion_detalle'] ?? '',
            $metas['direccion_fiscal'] ?? '',
        ]);
        if ($domicilioMedico === '') {
            $domicilioMedico = trim(($medico['estado'] ?? ($metas['estado_fiscal'] ?? '')) . ', ' . ($medico['pais'] ?? ($metas['pais_fiscal'] ?? '')), ', ');
        }
        if ($domicilioMedico === '') {
            $domicilioMedico = 'Domicilio Fiscal No Registrado';
        }
        $telefonoMedico = self::primerTextoNoVacio([$medico['whatsapp'] ?? '', $medico['telefono'] ?? ''], 'No especificado');
        $lugarEmision   = self::extraerLugarEmision($medico, $metas);

        $mpps = self::primerTextoNoVacio([$medico['mpps'] ?? '', $metas['mpps'] ?? ''], '—');
        $cmm  = self::primerTextoNoVacio([$medico['cmg'] ?? '', $medico['cmm'] ?? '', $metas['cmm'] ?? ''], '—');

        $rifTipo = self::primerTextoNoVacio([$medico['rif_tipo'] ?? '', $metas['rif_tipo'] ?? '']);
        $rifNum  = self::primerTextoNoVacio([$medico['rif_num'] ?? '', $medico['rif'] ?? '', $metas['rif_num'] ?? '']);
        if ($rifNum !== '') {
            $rifMedico = (strpos($rifNum, '-') !== false || $rifTipo === '') ? $rifNum : ($rifTipo . '-' . $rifNum);
        } elseif (!empty($medico['cedula'])) {
            $rifMedico = (!empty($medico['tipo_identificacion']) ? $medico['tipo_identificacion'] . '-' : 'V-') . $medico['cedula'];
        } else {
            $rifMedico = 'J-00000000-0';
        }
        $cedulaMedicoFirma = (!empty($medico['tipo_identificacion']) ? $medico['tipo_identificacion'] . '-' : 'V-') . ($medico['cedula'] ?? '—');

        $colorPrimario = self::normalizarHex(
            $temaOverride['color_primario'] ?? $medico['color_primario'] ?? null,
            '#B81D65'
        );
        $colorSuave = self::aclararHex($colorPrimario, 0.93);
        $colorBorde = self::aclararHex($colorPrimario, 0.65);

        // Número de presupuesto independiente por médico
        $numeroPres = !empty($pres['numero_presupuesto'])
            ? str_pad(trim((string)$pres['numero_presupuesto']), 6, '0', STR_PAD_LEFT)
            : self::obtenerSiguienteNumeroPresupuestoMedico($pdo, $realMedicoId, $metas);
        $numeroControl = 'PR-' . $numeroPres;

        $baseUrl = self::baseUrlPublica();
        $urlValidacion = $baseUrl . '/validar-presupuesto?presupuesto=' . urlencode($numeroPres)
            . '&med=' . $realMedicoId
            . '&cp=' . urlencode($colorPrimario);
        $urlQrImagen = 'https://api.qrserver.com/v1/create-qr-code/?size=160x160&data=' . urlencode($urlValidacion);

        // Datos del paciente
        $razonSocial = trim((string)($pres['paciente_nombre'] ?? ''));
        if ($razonSocial === '') {
            $razonSocial = trim(
                ($pres['p_nombre'] ?? '') . ' ' .
                ($pres['p_segundo_nombre'] ?? '') . ' ' .
                ($pres['p_apellido'] ?? '') . ' ' .
                ($pres['p_segundo_apellido'] ?? '')
            );
        }
        $razonSocial = mb_strtoupper(preg_replace('/\s+/', ' ', $razonSocial) ?: 'CLIENTE / PACIENTE GENERAL', 'UTF-8');

        $identificacionPac = trim((string)($pres['paciente_cedula'] ?? ''));
        if ($identificacionPac === '') {
            $identificacionPac = (!empty($pres['p_tipo_id']) ? $pres['p_tipo_id'] . '-' : '') . ($pres['p_cedula'] ?? '—');
        }

        $ubicacionPac = trim((string)($pres['paciente_direccion'] ?? ''));
        if ($ubicacionPac === '') {
            $ubicacionPac = trim(($pres['p_dir'] ?? '') . ' ' . ($pres['p_estado'] ?? '') . ' ' . ($pres['p_pais'] ?? ''));
        }
        $ubicacionPac = $ubicacionPac !== '' ? mb_strtoupper($ubicacionPac, 'UTF-8') : 'NO ESPECIFICADA';

        $telefonoPac = trim((string)($pres['paciente_telefono'] ?? ($pres['p_whatsapp'] ?? '—'))) ?: '—';

        // Fechas de emisión y vencimiento (+3 días)
        $tsEmision = !empty($pres['fecha_emision']) ? strtotime((string)$pres['fecha_emision']) : time();
        $tsVenc    = !empty($pres['fecha_vencimiento']) ? strtotime((string)$pres['fecha_vencimiento']) : strtotime('+3 days', $tsEmision);

        $diaEmision  = date('d', $tsEmision);
        $mesEmision  = date('m', $tsEmision);
        $anioEmision = date('Y', $tsEmision);
        $vencFmt     = date('d/m/Y', $tsVenc);

        $items = json_decode((string)($pres['items_json'] ?? '[]'), true);
        if (!is_array($items)) $items = [];

        $tasaBcv  = max(1.0, (float)($pres['tasa_bcv'] ?? 1.0));
        $totalUsd = (float)($pres['total_usd'] ?? 0);
        $totalBs  = (float)($pres['total_bs'] ?? ($totalUsd * $tasaBcv));

        $obs = trim((string)($pres['observaciones'] ?? ''));
        $esc = static fn($v) => htmlspecialchars((string)($v ?? ''), ENT_QUOTES, 'UTF-8');

        $filasHtml = '';
        foreach ($items as $it) {
            $cant = max(1, (int)($it['cantidad'] ?? 1));
            $nom  = trim((string)($it['nombre'] ?? $it['nombre_servicio'] ?? 'Servicio Médico'));
            $desc = trim((string)($it['descripcion'] ?? ''));
            $pUnitUsd = (float)($it['precio_usd'] ?? $it['precio'] ?? 0);
            $subUsd   = round($pUnitUsd * $cant, 2);
            $pUnitBs  = round($pUnitUsd * $tasaBcv, 2);
            $subBs    = round($subUsd * $tasaBcv, 2);

            $filasHtml .= '
                <tr>
                    <td style="text-align: center; font-weight: bold;">' . $cant . '</td>
                    <td>
                        <div style="font-weight: bold; color: #0f172a;">' . $esc($nom) . '</div>
                        ' . ($desc !== '' ? '<div style="font-size: 10.5px; color: #475569;">' . $esc($desc) . '</div>' : '') . '
                    </td>
                    <td style="text-align: right;">
                        <strong>$' . number_format($pUnitUsd, 2) . '</strong><br>
                        <span style="font-size: 10px; color: #64748b;">Bs. ' . number_format($pUnitBs, 2, ',', '.') . '</span>
                    </td>
                    <td style="text-align: right;">
                        <strong>$' . number_format($subUsd, 2) . '</strong><br>
                        <span style="font-size: 10px; color: #64748b;">Bs. ' . number_format($subBs, 2, ',', '.') . '</span>
                    </td>
                </tr>';
        }

        if ($filasHtml === '') {
            $filasHtml = '<tr><td colspan="4" style="text-align:center; color:#64748b;">Sin ítems detallados</td></tr>';
        }

        $html = '<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <title>Presupuesto N° ' . $esc($numeroPres) . '</title>
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
        .top-grid { width: 100%; border-collapse: collapse; margin-bottom: 12px; }
        .top-grid td { vertical-align: top; }
        .qr-box { width: 96px; padding-right: 12px; text-align: center; }
        .qr-box img {
            width: 90px;
            height: 90px;
            border: 1.5px solid ' . $esc($colorPrimario) . ';
            border-radius: 6px;
            padding: 3px;
            background: #fff;
        }
        .qr-caption { font-size: 8.5px; color: #64748b; margin-top: 2px; font-weight: bold; }
        .doc-title { font-size: 18px; font-weight: 800; color: ' . $esc($colorPrimario) . '; margin: 0 0 2px 0; }
        .doc-spec { font-size: 11.5px; font-weight: 700; color: ' . $esc($colorPrimario) . '; margin: 0 0 6px 0; }
        .doc-meta { font-size: 11px; color: #334155; line-height: 1.4; }
        .doc-fiscal-line { margin-top: 4px; font-size: 11px; font-weight: 700; color: #0f172a; }
        .factura-num-box { text-align: right; margin-bottom: 6px; }
        .factura-num-red { font-size: 15px; font-weight: 800; color: #dc2626; letter-spacing: 0.5px; }
        .control-num-red { font-size: 12px; font-weight: 800; color: #dc2626; margin-top: 2px; }
        .emision-table { width: 100%; border-collapse: collapse; margin-top: 6px; font-size: 10.5px; }
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
        .cliente-box {
            width: 100%;
            border-collapse: collapse;
            margin-top: 8px;
            margin-bottom: 12px;
            border: 1.5px solid ' . $esc($colorPrimario) . ';
        }
        .cliente-box td {
            padding: 6px 10px;
            border-bottom: 1px solid ' . $esc($colorBorde) . ';
            font-size: 11.5px;
        }
        .cliente-box tr:last-child td { border-bottom: none; }
        .lbl-theme { color: ' . $esc($colorPrimario) . '; font-weight: 700; }
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
            padding: 8px 10px;
            border-left: 1px solid ' . $esc($colorPrimario) . ';
            border-right: 1px solid ' . $esc($colorPrimario) . ';
            border-bottom: 1px solid ' . $esc($colorBorde) . ';
            font-size: 11.5px;
            vertical-align: top;
        }
        .firma-sello {
            margin-top: 22px;
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
        .bottom-grid { width: 100%; border-collapse: collapse; margin-top: 10px; }
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
        .totales-val { text-align: right; font-weight: 800; color: #0f172a; }
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
        <button onclick="window.print()">🖨️ Imprimir / Guardar Presupuesto PDF</button>
    </div>

    <div class="factura-sheet">
        <table class="top-grid">
            <tr>
                <td class="qr-box">
                    <a href="' . $esc($urlValidacion) . '" target="_blank" title="Verificar presupuesto">
                        <img src="' . $esc($urlQrImagen) . '" alt="QR Validación">
                    </a>
                    <div class="qr-caption">VALIDAR QR</div>
                </td>
                <td style="width: 50%; padding-right: 10px;">
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
                <td style="width: 36%;">
                    <div class="factura-num-box">
                        <div class="factura-num-red">PRESUPUESTO N° ' . $esc($numeroPres) . '</div>
                        <div class="control-num-red">REF. CONTROL ' . $esc($numeroControl) . '</div>
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
                    <span class="lbl-theme">VÁLIDO HASTA:</span>
                    <strong style="color: #dc2626;">' . $esc($vencFmt) . ' (3 días)</strong>
                </td>
            </tr>
        </table>

        <table class="items-table">
            <thead>
                <tr>
                    <th style="width: 9%; text-align: center;">CANT.</th>
                    <th style="width: 53%; text-align: left;">DESCRIPCIÓN DEL SERVICIO / PROCEDIMIENTO</th>
                    <th style="width: 19%; text-align: right;">P. UNIT.</th>
                    <th style="width: 19%; text-align: right;">TOTAL</th>
                </tr>
            </thead>
            <tbody>
                ' . $filasHtml . '
            </tbody>
        </table>

        <table class="bottom-grid">
            <tr>
                <td style="width: 60%; padding-right: 10px;">
                    <div class="pago-box">
                        <div style="margin-bottom: 5px;">
                            <span class="lbl-theme">CONDICIONES Y OBSERVACIONES:</span><br>
                            ' . ($obs !== '' ? $esc($obs) : 'Presupuesto válido por tres (3) días continuos a partir de su fecha de emisión. Sujeto a variación de tasa oficial BCV.') . '
                        </div>
                        <div style="margin-top: 6px; font-size: 10px; color: #334155;">
                            <strong>Tasa Referencial BCV:</strong> Bs. ' . number_format($tasaBcv, 4, ',', '.') . ' / USD
                        </div>
                    </div>

                    <div class="firma-sello">
                        <div class="firma-linea"></div>
                        <strong>' . $esc($tituloMedicoFirma) . '</strong><br>
                        ' . $esc($especialidad) . ' &nbsp;|&nbsp; M.P.P.S.: ' . $esc($mpps) . ' &nbsp;|&nbsp; C.I.: ' . $esc($cedulaMedicoFirma) . '
                    </div>
                </td>
                <td style="width: 40%;">
                    <table class="totales-table">
                        <tr>
                            <td class="totales-lbl" style="width: 52%;">TOTAL USD</td>
                            <td class="totales-val" style="width: 48%;">$' . number_format($totalUsd, 2) . '</td>
                        </tr>
                        <tr>
                            <td class="totales-lbl">IVA &nbsp; 0,00 % (Exento)</td>
                            <td class="totales-val">Bs. 0,00</td>
                        </tr>
                        <tr>
                            <td class="totales-lbl" style="font-size: 12.5px;">TOTAL EN BOLÍVARES</td>
                            <td class="totales-val" style="font-size: 13px; color: ' . $esc($colorPrimario) . ';">Bs. ' . number_format($totalBs, 2, ',', '.') . '</td>
                        </tr>
                    </table>
                </td>
            </tr>
        </table>

        <div class="pie-legal">
            ESTE DOCUMENTO ES UNA COTIZACIÓN / PRESUPUESTO MÉDICO (NO ES FACTURA FISCAL).<br>
            Verifique la validez escaneando el código QR o en <strong>' . $esc($baseUrl . '/validar-presupuesto') . '</strong>
        </div>
        <div class="original-tag">PRESUPUESTO DIGITAL</div>
    </div>
</body>
</html>';

        $dir = __DIR__ . '/../../public/uploads/presupuestos_medicos/';
        if (!is_dir($dir)) {
            @mkdir($dir, 0755, true);
        }

        $nombreArchivo = 'presupuesto-' . $realMedicoId . '-' . $numeroPres . '-' . time() . '.html';
        @file_put_contents($dir . $nombreArchivo, $html);
        $urlArchivo = $baseUrl . '/uploads/presupuestos_medicos/' . $nombreArchivo;

        $upd = $pdo->prepare("UPDATE gm_presupuestos SET numero_presupuesto = :num, url_pdf = :url WHERE id = :id");
        $upd->execute([
            ':num' => $numeroPres,
            ':url' => $urlArchivo,
            ':id'  => $presupuestoId
        ]);

        return [
            'html'               => $html,
            'url'                => $urlArchivo,
            'numero_presupuesto' => $numeroPres,
        ];
    }

    /**
     * GET /validar-presupuesto?presupuesto=000001&med=12
     */
    public static function renderizarValidadorPublico(): void {
        header('Content-Type: text/html; charset=utf-8');
        $numRaw = isset($_GET['presupuesto']) ? trim((string)$_GET['presupuesto']) : '';
        $medId  = isset($_GET['med']) ? (int)$_GET['med'] : 0;
        $colorPrimario = self::normalizarHex($_GET['cp'] ?? null, '#B81D65');
        $colorFondo    = self::aclararHex($colorPrimario, 0.95);
        $colorBadgeBg  = self::aclararHex($colorPrimario, 0.88);
        $esc = static fn($v) => htmlspecialchars((string)($v ?? ''), ENT_QUOTES, 'UTF-8');

        echo '<!DOCTYPE html><html lang="es"><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1.0"><title>Validación Pública de Presupuesto Médico</title></head><body style="background:' . $esc($colorFondo) . '; margin:0; padding:16px;">';

        if ($numRaw === '') {
            echo '<div style="max-width:500px; margin:40px auto; padding:25px; background:#fff; border:1px solid #cbd5e1; border-radius:10px; text-align:center; font-family:sans-serif;">
                    <h3 style="color:#d97706; margin-top:0;">⚠️ Número de Presupuesto no Proporcionado</h3>
                  </div></body></html>';
            return;
        }

        try {
            $pdo = Database::conectar();
            self::asegurarTablaPresupuestos($pdo);
            $numPad = str_pad($numRaw, 6, '0', STR_PAD_LEFT);

            if ($medId > 0) {
                $stmt = $pdo->prepare("SELECT * FROM gm_presupuestos WHERE medico_id = :med AND (numero_presupuesto = :num OR numero_presupuesto = :numPad) LIMIT 1");
                $stmt->execute([':med' => $medId, ':num' => $numRaw, ':numPad' => $numPad]);
            } else {
                $idLimpio = (int)ltrim($numRaw, '0');
                $stmt = $pdo->prepare("SELECT * FROM gm_presupuestos WHERE numero_presupuesto = :num OR numero_presupuesto = :numPad OR id = :id LIMIT 1");
                $stmt->execute([':num' => $numRaw, ':numPad' => $numPad, ':id' => $idLimpio]);
            }
            $pres = $stmt->fetch(PDO::FETCH_ASSOC);

            echo '<div style="max-width:600px; margin:32px auto; padding:30px; background:#ffffff; border:1px solid #e2e8f0; border-radius:14px; font-family:\'Helvetica Neue\', Helvetica, Arial, sans-serif;">';

            if (!$pres) {
                echo '<div style="text-align:center; padding:20px;">
                        <h2 style="color:#dc2626; margin-top:0;">❌ Presupuesto No Encontrado</h2>
                      </div>';
            } else {
                $hoy = date('Y-m-d');
                $vigente = ($hoy <= (string)$pres['fecha_vencimiento']);
                $numFmt = !empty($pres['numero_presupuesto']) ? str_pad((string)$pres['numero_presupuesto'], 6, '0', STR_PAD_LEFT) : str_pad((string)$pres['id'], 6, '0', STR_PAD_LEFT);

                echo '<div style="text-align:center; border-bottom:2px solid ' . $esc($colorPrimario) . '; padding-bottom:18px; margin-bottom:18px;">
                        <div style="display:inline-block; background:' . ($vigente ? $esc($colorBadgeBg) : '#fee2e2') . '; color:' . ($vigente ? $esc($colorPrimario) : '#dc2626') . '; padding:6px 16px; border-radius:20px; font-weight:bold; font-size:13px; margin-bottom:10px;">
                            ' . ($vigente ? '✓ Presupuesto Vigente' : '⚠️ Presupuesto Vencido') . '
                        </div>
                        <h2 style="color:' . $esc($colorPrimario) . '; margin:0; font-size:20px;">VALIDACIÓN DE PRESUPUESTO MÉDICO</h2>
                      </div>
                      <table style="width:100%; border-collapse:collapse; font-size:14px;">
                        <tr style="border-bottom:1px solid #f1f5f9;">
                            <td style="padding:10px; color:#64748b;"><strong>Número:</strong></td>
                            <td style="padding:10px; color:#dc2626; font-weight:bold;">N° ' . $esc($numFmt) . '</td>
                        </tr>
                        <tr style="border-bottom:1px solid #f1f5f9;">
                            <td style="padding:10px; color:#64748b;"><strong>Paciente:</strong></td>
                            <td style="padding:10px; font-weight:600;">' . $esc($pres['paciente_nombre']) . '</td>
                        </tr>
                        <tr style="border-bottom:1px solid #f1f5f9;">
                            <td style="padding:10px; color:#64748b;"><strong>Fecha Emisión:</strong></td>
                            <td style="padding:10px;">' . date('d/m/Y', strtotime((string)$pres['fecha_emision'])) . '</td>
                        </tr>
                        <tr style="border-bottom:1px solid #f1f5f9;">
                            <td style="padding:10px; color:#64748b;"><strong>Válido Hasta:</strong></td>
                            <td style="padding:10px; font-weight:bold; color:' . ($vigente ? '#166534' : '#dc2626') . ';">' . date('d/m/Y', strtotime((string)$pres['fecha_vencimiento'])) . '</td>
                        </tr>
                        <tr>
                            <td style="padding:10px; color:#64748b;"><strong>Monto Total:</strong></td>
                            <td style="padding:10px; font-weight:bold;">$' . number_format((float)$pres['total_usd'], 2) . ' USD / Bs. ' . number_format((float)$pres['total_bs'], 2, ',', '.') . '</td>
                        </tr>
                      </table>';

                if (!empty($pres['url_pdf'])) {
                    echo '<div style="margin-top:24px; text-align:center;">
                            <a href="' . $esc($pres['url_pdf']) . '" target="_blank" style="display:inline-block; background:' . $esc($colorPrimario) . '; color:#fff; padding:12px 24px; border-radius:8px; font-weight:bold; text-decoration:none;">
                                📥 Ver / Descargar Presupuesto
                            </a>
                          </div>';
                }
            }
            echo '</div>';
        } catch (Exception $e) {
            echo '<p style="color:red;">Error: ' . $esc($e->getMessage()) . '</p>';
        }

        echo '</body></html>';
    }
}