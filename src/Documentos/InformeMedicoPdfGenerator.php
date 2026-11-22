<?php
namespace App\Documentos;

use App\Config\Database;
use PDO;
use DateTime;
use Exception;

class InformeMedicoPdfGenerator {

    private static function columnaExiste(PDO $pdo, string $tabla, string $columna): bool {
        try {
            $stmt = $pdo->prepare("SHOW COLUMNS FROM `$tabla` LIKE :col");
            $stmt->execute([':col' => $columna]);
            return (bool)$stmt->fetch(PDO::FETCH_ASSOC);
        } catch (Exception $e) {
            return false;
        }
    }

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

    private static function formatearFechaEs(?string $fechaRaw): string {
        if (empty($fechaRaw)) {
            return date('d M Y');
        }
        $ts = strtotime($fechaRaw);
        if (!$ts) {
            return $fechaRaw;
        }
        $meses = ['Ene', 'Feb', 'Mar', 'Abr', 'May', 'Jun', 'Jul', 'Ago', 'Sep', 'Oct', 'Nov', 'Dic'];
        $m = (int)date('n', $ts) - 1;
        return date('d', $ts) . ' ' . ($meses[$m] ?? date('M', $ts)) . ' ' . date('Y', $ts);
    }

    private static function normalizarHexColor(?string $color, string $fallback): string {
        $c = trim((string)($color ?? ''));
        if ($c === '') return $fallback;
        if ($c[0] !== '#') $c = '#' . $c;
        return preg_match('/^#[0-9a-fA-F]{6}$/', $c) ? $c : $fallback;
    }

    private static function oscurecerHex(string $hex, float $factor = 0.72): string {
        $hex = ltrim($hex, '#');
        if (strlen($hex) !== 6) return '#880e4f';
        $r = max(0, min(255, (int)round(hexdec(substr($hex, 0, 2)) * $factor)));
        $g = max(0, min(255, (int)round(hexdec(substr($hex, 2, 2)) * $factor)));
        $b = max(0, min(255, (int)round(hexdec(substr($hex, 4, 2)) * $factor)));
        return sprintf('#%02x%02x%02x', $r, $g, $b);
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
     * Genera el Informe Médico Oficial en HTML/PDF con datos y paleta de colores dinámicos del médico.
     */
    public static function generarInformeMedicoPdf(int $historiaId, ?PDO $pdoExterno = null, array $temaOverride = []): array {
        $pdo = $pdoExterno ?? Database::conectar();

        // 1. Obtener historia clínica
        $stmtHist = $pdo->prepare("SELECT * FROM gm_historias_medicas WHERE id = :id LIMIT 1");
        $stmtHist->execute([':id' => $historiaId]);
        $historia = $stmtHist->fetch(PDO::FETCH_ASSOC);

        if (!$historia) {
            return ['html' => '', 'url' => '', 'path' => ''];
        }

        // 2. Obtener datos del paciente
        $stmtPac = $pdo->prepare("SELECT * FROM gm_usuarios_extendidos WHERE user_id = :uid LIMIT 1");
        $stmtPac->execute([':uid' => (int)$historia['paciente_id']]);
        $paciente = $stmtPac->fetch(PDO::FETCH_ASSOC) ?: [];

        // 3. Obtener exámenes complementarios
        $stmtExam = $pdo->prepare("SELECT * FROM gm_historias_examenes WHERE historia_id = :hid ORDER BY id ASC");
        $stmtExam->execute([':hid' => $historiaId]);
        $examenes = $stmtExam->fetchAll(PDO::FETCH_ASSOC);

        // 4. Calcular edad
        $edadStr = 'N/A';
        if (!empty($paciente['fecha_nacimiento'])) {
            try {
                $edadStr = (new DateTime())->diff(new DateTime($paciente['fecha_nacimiento']))->y . ' AÑOS';
            } catch (Exception $e) {
                $edadStr = !empty($paciente['edad']) ? ($paciente['edad'] . ' AÑOS') : 'N/A';
            }
        } elseif (!empty($paciente['edad'])) {
            $edadStr = $paciente['edad'] . ' AÑOS';
        }

        // 5. Datos 100% dinámicos del médico emisor (se adapta a cada médico que atiende)
        $medicoId = (int)$historia['medico_id'];
        $stmtMed = $pdo->prepare("SELECT * FROM gm_usuarios_extendidos WHERE user_id = :uid LIMIT 1");
        $stmtMed->execute([':uid' => $medicoId]);
        $medExt = $stmtMed->fetch(PDO::FETCH_ASSOC) ?: [];

        $medicoGenero = strtolower(trim($medExt['sexo'] ?? ''));
        $prefijoMedico = ($medicoGenero === 'femenino' || $medicoGenero === 'f') ? 'Dra.' : 'Dr.';
        $nombreMedicoBase = trim($medExt['nombre'] ?? 'Médico');
        $apellidoMedico = trim($medExt['apellido'] ?? 'Tratante');
        $medicoNombreFull = trim("$prefijoMedico $nombreMedicoBase $apellidoMedico");

        $medicoEspecialidad = !empty($medExt['especialidad']) ? $medExt['especialidad'] : 'Médico Cirujano';
        $medicoUniversidad  = !empty($medExt['universidad']) ? $medExt['universidad'] : 'UCV';
        $medicoTagline      = trim($medExt['tagline'] ?? '');
        $medicoTelefono     = !empty($medExt['whatsapp']) ? $medExt['whatsapp'] : ($medExt['telefono'] ?? 'S/N');
        $medicoMpps         = !empty($medExt['mpps']) ? $medExt['mpps'] : 'S/N';
        $medicoCmm          = !empty($medExt['cmm']) ? $medExt['cmm'] : 'S/N';

        $rifTipo = trim($medExt['rif_tipo'] ?? '');
        $rifNum  = trim($medExt['rif_num'] ?? ($medExt['rif'] ?? ''));
        if (!empty($rifNum)) {
            $medicoRif = (strpos($rifNum, '-') !== false || empty($rifTipo)) ? $rifNum : ($rifTipo . '-' . $rifNum);
        } else {
            $medicoRif = 'S/N';
        }

        // Colores dinámicos según la configuración de tema/diseño del médico o configuración global
        $cfgColorPrimario = null;
        $cfgColorSecundario = null;
        try {
            $stmtCfg = $pdo->query("SELECT * FROM gm_configuracion LIMIT 1");
            $cfg = $stmtCfg ? $stmtCfg->fetch(PDO::FETCH_ASSOC) : null;
            if ($cfg) {
                $cfgColorPrimario = $cfg['color_primario'] ?? null;
                $cfgColorSecundario = $cfg['color_secundario'] ?? null;
            }
        } catch (Exception $ignored) {}

        $colorPrimarioRaw = $temaOverride['color_primario']
            ?? $medExt['color_primario']
            ?? $medExt['theme_primary']
            ?? $cfgColorPrimario
            ?? '#B81D65';
        $colorPrimario = self::normalizarHexColor($colorPrimarioRaw, '#B81D65');

        $colorOscuroRaw = $temaOverride['color_secundario']
            ?? $medExt['color_secundario']
            ?? $medExt['theme_dark']
            ?? $cfgColorSecundario
            ?? '';
        $colorOscuro = !empty($colorOscuroRaw)
            ? self::normalizarHexColor($colorOscuroRaw, self::oscurecerHex($colorPrimario))
            : self::oscurecerHex($colorPrimario);

        $colorSuave = self::aclararHex($colorPrimario, 0.92);
        $colorBadge = self::aclararHex($colorPrimario, 0.86);

        // Redes sociales almacenadas (JSON o texto)
        $redesJson = $medExt['redes_sociales'] ?? '';
        $redesArr = !empty($redesJson)
            ? (is_array($redesJson) ? $redesJson : json_decode((string)$redesJson, true))
            : [];
        $redesStr = '';
        if (is_array($redesArr) && !empty($redesArr)) {
            $partesRedes = [];
            foreach ($redesArr as $red) {
                if (!is_array($red)) continue;
                $nombreRed = $red['red'] ?? $red['plataforma'] ?? '';
                $handleRed = $red['usuario'] ?? $red['handle'] ?? '';
                if (!empty($nombreRed) && !empty($handleRed)) {
                    $partesRedes[] = ucfirst($nombreRed) . ': ' . $handleRed;
                }
            }
            $redesStr = implode(' | ', $partesRedes);
        }
        if (empty($redesStr)) {
            $slugMed = strtolower(preg_replace('/[^a-zA-Z0-9]/', '', $nombreMedicoBase . $apellidoMedico)) ?: 'medico';
            $redesStr = 'Instagram: @' . $slugMed;
        }

        // Datos del paciente
        $nombrePaciente = !empty($paciente)
            ? strtoupper(trim(($paciente['nombre'] ?? '') . ' ' . ($paciente['segundo_nombre'] ?? '') . ' ' . ($paciente['apellido'] ?? '')))
            : 'PACIENTE';
        $nombrePaciente = preg_replace('/\s+/', ' ', $nombrePaciente);
        $tipoIdPac      = !empty($paciente['tipo_identificacion']) ? $paciente['tipo_identificacion'] . '-' : '';
        $cedulaPaciente = !empty($paciente['cedula']) ? ($tipoIdPac . $paciente['cedula']) : 'N/A';
        $pesoPaciente   = !empty($paciente['peso']) ? ($paciente['peso'] . ' Kg') : '-';

        $fechaFormateada = self::formatearFechaEs($historia['fecha_registro'] ?? null);
        $codigoInf       = 'INF-' . str_pad((string)$historiaId, 8, '0', STR_PAD_LEFT);

        // Hash QR único persistido en BD
        $hashVal = trim($historia['hash_validacion'] ?? '');
        if (empty($hashVal)) {
            $secret = getenv('APP_SECRET') ?: 'ginevapp_qr_secret_2026';
            $hashVal = hash('sha256', $historiaId . '|' . ($historia['paciente_id'] ?? 0) . '|' . ($historia['fecha_registro'] ?? microtime(true)) . '|' . $secret);
            if (self::columnaExiste($pdo, 'gm_historias_medicas', 'hash_validacion')) {
                $pdo->prepare("UPDATE gm_historias_medicas SET hash_validacion = :h WHERE id = :id")
                    ->execute([':h' => $hashVal, ':id' => $historiaId]);
            }
        }

        $baseUrl       = self::baseUrlPublica();
        $urlValidacion = $baseUrl . '/validar-informe?hash=' . urlencode($hashVal)
            . '&cp=' . urlencode($colorPrimario)
            . '&cs=' . urlencode($colorOscuro);
        $apiQrUrl      = 'https://api.qrserver.com/v1/create-qr-code/?size=150x150&data=' . urlencode($urlValidacion);

        // Lista de exámenes dinámicos
        $htmlExamenes = '';
        if (!empty($examenes)) {
            foreach ($examenes as $ex) {
                $fEx = self::formatearFechaEs($ex['fecha_registro'] ?? ($historia['fecha_registro'] ?? null));
                $detalle = !empty($ex['resultado'])
                    ? $ex['resultado']
                    : ($ex['descripcion_solicitud'] ?? '');
                $htmlExamenes .= '<li><strong>' . htmlspecialchars($ex['tipo_examen'] ?? 'Estudio') . ' (' . htmlspecialchars($fEx) . '):</strong> '
                    . htmlspecialchars($ex['estado_examen'] ?? 'Pendiente')
                    . (!empty($detalle) ? ' - ' . htmlspecialchars($detalle) : '')
                    . '</li>';
            }
        } else {
            $htmlExamenes = '<li>No se registraron estudios complementarios en esta consulta.</li>';
        }

        $esc = static fn($v) => htmlspecialchars((string)($v ?? ''), ENT_QUOTES, 'UTF-8');

        // 6. Plantilla HTML Corporativa con Variables CSS Dinámicas
        $htmlContent = '<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="UTF-8">
<title>Informe Médico Oficial - ' . $esc($nombrePaciente) . '</title>
<style>
    :root {
        --primary-color: ' . $esc($colorPrimario) . ';
        --primary-dark: ' . $esc($colorOscuro) . ';
        --primary-soft: ' . $esc($colorSuave) . ';
        --primary-badge: ' . $esc($colorBadge) . ';
        --text-color: #2c3e50;
        --bg-light: #f8f9fa;
        --border-color: #e9ecef;
    }
    body {
        font-family: \'Helvetica Neue\', Helvetica, Arial, sans-serif;
        color: var(--text-color);
        margin: 0;
        padding: 20px;
        font-size: 13px;
        line-height: 1.5;
        background: #fff;
    }
    .document-container {
        max-width: 800px;
        margin: 0 auto;
        background: #fff;
        border: 1px solid #ddd;
        padding: 30px;
        border-radius: 8px;
    }
    .header {
        display: flex;
        justify-content: space-between;
        align-items: center;
        border-bottom: 2px solid var(--primary-color);
        padding-bottom: 15px;
        margin-bottom: 20px;
    }
    .doctor-info h2 {
        margin: 0;
        color: var(--primary-color);
        font-size: 18px;
        text-transform: uppercase;
    }
    .doctor-info p {
        margin: 2px 0;
        font-size: 11px;
        color: #555;
    }
    .qr-validation-box {
        text-align: center;
        border: 1px dashed var(--primary-color);
        padding: 8px;
        border-radius: 6px;
        background-color: var(--primary-soft);
    }
    .qr-validation-box img {
        width: 75px;
        height: 75px;
    }
    .qr-validation-box span {
        display: block;
        font-size: 9px;
        font-weight: bold;
        color: var(--primary-dark);
        margin-top: 4px;
    }
    .patient-card {
        background: var(--bg-light);
        border: 1px solid var(--border-color);
        border-radius: 6px;
        padding: 12px 15px;
        margin-bottom: 20px;
    }
    .patient-grid {
        display: grid;
        grid-template-columns: repeat(3, 1fr);
        gap: 8px;
    }
    .patient-field {
        font-size: 12px;
    }
    .patient-field strong {
        color: var(--primary-dark);
    }
    .badge-container {
        display: flex;
        gap: 8px;
        margin-top: 8px;
        flex-wrap: wrap;
    }
    .badge {
        background: var(--primary-badge);
        color: var(--primary-dark);
        padding: 3px 8px;
        border-radius: 12px;
        font-size: 11px;
        font-weight: bold;
    }
    .section-title {
        color: var(--primary-color);
        border-bottom: 1px solid var(--border-color);
        font-size: 14px;
        font-weight: bold;
        text-transform: uppercase;
        margin-top: 15px;
        margin-bottom: 8px;
        padding-bottom: 4px;
    }
    .content-box {
        text-align: justify;
        margin-bottom: 12px;
    }
    .exam-list {
        list-style: none;
        padding: 0;
        margin: 0;
    }
    .exam-list li {
        background: #fafafa;
        border-left: 3px solid var(--primary-color);
        padding: 6px 10px;
        margin-bottom: 5px;
        font-size: 12px;
    }
    .dx-box {
        background: #fff8f8;
        border: 1px solid #ffe3e3;
        border-left: 4px solid #d9534f;
        padding: 10px;
        border-radius: 4px;
        margin-bottom: 15px;
    }
    .treatment-box {
        background: #f4fdf8;
        border: 1px solid #d3f9e3;
        border-left: 4px solid #27ae60;
        padding: 10px;
        border-radius: 4px;
    }
    .footer-signature {
        margin-top: 40px;
        display: flex;
        justify-content: space-between;
        align-items: flex-end;
    }
    .signature-box {
        text-align: center;
        width: 250px;
    }
    .signature-line {
        border-top: 1px solid var(--primary-dark);
        margin-top: 40px;
        padding-top: 5px;
        font-size: 11px;
        font-weight: bold;
        color: var(--primary-dark);
    }
    @media print {
        body { padding: 0; background: #fff; }
        .document-container { border: none; padding: 0; box-shadow: none; max-width: 100%; }
        .no-print { display: none; }
    }
</style>
</head>
<body onload="window.print()">

<div class="no-print" style="text-align: right; margin-bottom: 15px;">
    <button onclick="window.print()" style="background: ' . $esc($colorPrimario) . '; color: #fff; border: none; padding: 10px 20px; font-weight: bold; border-radius: 6px; cursor: pointer;">🖨️ Imprimir / Guardar como PDF</button>
</div>

<div class="document-container">
    <div class="header">
        <div class="doctor-info">
            <h2>' . $esc($medicoNombreFull) . '</h2>
            <p><strong>' . $esc(trim($medicoEspecialidad . ' ' . $medicoUniversidad)) . '</strong></p>
            ' . (!empty($medicoTagline) ? '<p>' . $esc($medicoTagline) . '</p>' : '') . '
            <p>Teléfono: ' . $esc($medicoTelefono) . ' | ' . $esc($redesStr) . '</p>
            <p>MPPS: ' . $esc($medicoMpps) . ' | CMM: ' . $esc($medicoCmm) . ' | RIF: ' . $esc($medicoRif) . '</p>
        </div>
        <div class="qr-validation-box">
            <a href="' . $esc($urlValidacion) . '" target="_blank" title="Verificar autenticidad">
                <img src="' . $esc($apiQrUrl) . '" alt="Código QR Validación">
            </a>
            <span>VALIDACIÓN DE VERACIDAD<br>Código: ' . $esc($codigoInf) . '</span>
        </div>
    </div>

    <div class="patient-card">
        <div class="patient-grid">
            <div class="patient-field"><strong>PACIENTE:</strong> ' . $esc($nombrePaciente) . '</div>
            <div class="patient-field"><strong>C.I.:</strong> ' . $esc($cedulaPaciente) . '</div>
            <div class="patient-field"><strong>EDAD:</strong> ' . $esc($edadStr) . '</div>
            <div class="patient-field"><strong>FECHA:</strong> ' . $esc($fechaFormateada) . '</div>
            <div class="patient-field"><strong>F.U.R.:</strong> ' . $esc(!empty($historia['fur']) ? $historia['fur'] : 'N/A') . '</div>
            <div class="patient-field"><strong>P.A. / T.A.:</strong> ' . $esc(!empty($historia['tension_arterial']) ? $historia['tension_arterial'] . ' mmHg' : 'N/D') . '</div>
            <div class="patient-field"><strong>PESO:</strong> ' . $esc($pesoPaciente) . '</div>
            <div class="patient-field"><strong>F.C.:</strong> ' . $esc(!empty($historia['frecuencia_cardiaca']) ? $historia['frecuencia_cardiaca'] . ' lpm' : 'N/D') . '</div>
            <div class="patient-field"><strong>E.G.:</strong> -</div>
        </div>
        <div class="badge-container">
            <span class="badge">Gestas: ' . $esc(!empty($historia['gt']) ? $historia['gt'] : '-') . '</span>
            <span class="badge">Para: ' . $esc(!empty($historia['pa']) ? $historia['pa'] : '-') . '</span>
            <span class="badge">Cesáreas: ' . $esc(!empty($historia['cs']) ? $historia['cs'] : '-') . '</span>
            <span class="badge">Aborto: ' . $esc(!empty($historia['ab']) ? $historia['ab'] : '-') . '</span>
        </div>
    </div>

    <div class="section-title">Enfermedad Actual y Antecedentes</div>
    <div class="content-box">
        ' . nl2br($esc($historia['motivo_consulta'] ?? '')) . '<br><br>
        <strong>Antecedentes Personales:</strong> ' . nl2br($esc(!empty($historia['antecedentes_personales']) ? $historia['antecedentes_personales'] : 'Niega')) . '<br>
        <strong>Hábitos Psicobiológicos:</strong> ' . nl2br($esc(!empty($historia['habitos_psicobiologicos']) ? $historia['habitos_psicobiologicos'] : 'Niega')) . '<br>
        <strong>Antecedentes Familiares:</strong> ' . nl2br($esc(!empty($historia['antecedentes_familiares']) ? $historia['antecedentes_familiares'] : 'Niega')) . '<br>
        <strong>Antecedentes Quirúrgicos:</strong> ' . nl2br($esc(!empty($historia['antecedentes_quirurgicos']) ? $historia['antecedentes_quirurgicos'] : 'Niega')) . '<br>
        <strong>Menarquia:</strong> ' . $esc(!empty($historia['menarquia']) ? $historia['menarquia'] : 'N/D') . ' | <strong>Ciclos:</strong> ' . $esc(!empty($historia['ciclos_menstruales']) ? $historia['ciclos_menstruales'] : 'N/D') . ' | <strong>ACOS:</strong> ' . $esc(!empty($historia['metodos_acos']) ? $historia['metodos_acos'] : 'Ninguno') . ' | <strong>Citología:</strong> ' . $esc(!empty($historia['papanicolaou']) ? $historia['papanicolaou'] : 'N/D') . '
    </div>

    <div class="section-title">Examen Físico Segmentario</div>
    <div class="content-box">
        <strong>Examen Mamario:</strong> ' . nl2br($esc(!empty($historia['examen_mamario']) ? $historia['examen_mamario'] : 'Sin alteraciones aparentes')) . '<br>
        <strong>Examen Pélvico:</strong> ' . nl2br($esc(!empty($historia['examen_pelvico']) ? $historia['examen_pelvico'] : 'Sin alteraciones aparentes')) . '
    </div>

    <div class="section-title">Estudios Complementarios Realizados</div>
    <ul class="exam-list">
        ' . $htmlExamenes . '
    </ul>

    <div class="section-title">Juicio Clínico / Diagnósticos</div>
    <div class="dx-box">
        ' . nl2br($esc($historia['diagnostico'] ?? '')) . '
    </div>

    <div class="section-title">Plan de Trabajo y Tratamiento Prescrito</div>
    <div class="treatment-box">
        <strong>PLAN MÉDICO:</strong><br>' . nl2br($esc(!empty($historia['plan_medico']) ? $historia['plan_medico'] : 'Seguimiento ambulatorio')) . '<br><br>
        <strong>TRATAMIENTO / RÉCIPES:</strong><br>' . nl2br($esc(!empty($historia['tratamiento']) ? $historia['tratamiento'] : 'Según indicaciones médicas')) . '
    </div>

    <div class="footer-signature">
        <div style="font-size: 10px; color: #888; max-width: 300px;">
            Este documento es una copia auténtica emitida digitalmente. Puede verificar su autenticidad escaneando el código QR con cualquier dispositivo móvil.
        </div>
        <div class="signature-box">
            <div class="signature-line">
                ' . $esc($medicoNombreFull) . '<br>
                ' . $esc($medicoEspecialidad) . '<br>
                MPPS: ' . $esc($medicoMpps) . ' | CMM: ' . $esc($medicoCmm) . '
            </div>
        </div>
    </div>
</div>

</body>
</html>';

        // 7. Guardar físicamente en public/uploads/informes_medicos/
        $pdfDir = __DIR__ . '/../../public/uploads/informes_medicos/';
        if (!is_dir($pdfDir)) {
            @mkdir($pdfDir, 0755, true);
        }

        $nombreArchivo = 'informe-medico-' . $historiaId . '-' . time() . '.html';
        $rutaArchivo   = $pdfDir . $nombreArchivo;
        @file_put_contents($rutaArchivo, $htmlContent);

        $urlArchivo = $baseUrl . '/uploads/informes_medicos/' . $nombreArchivo;

        $updCols = [];
        $updParams = [':id' => $historiaId];
        if (self::columnaExiste($pdo, 'gm_historias_medicas', 'pdf_path')) {
            $updCols[] = "pdf_path = :pdf_path";
            $updParams[':pdf_path'] = $urlArchivo;
        }
        if (self::columnaExiste($pdo, 'gm_historias_medicas', 'url_pdf_informe')) {
            $updCols[] = "url_pdf_informe = :url_pdf";
            $updParams[':url_pdf'] = $urlArchivo;
        }
        if (!empty($updCols)) {
            $pdo->prepare("UPDATE gm_historias_medicas SET " . implode(', ', $updCols) . " WHERE id = :id")
                ->execute($updParams);
        }

        return [
            'html' => $htmlContent,
            'url'  => $urlArchivo,
            'path' => $rutaArchivo,
        ];
    }
}