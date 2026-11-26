<?php
namespace App\Controllers;

use App\Config\Database;
use PDO;

class FirmaMedicoController
{
    private static function resolverMedicoIds(PDO $pdo, int $idEntrada): array
    {
        $ids = [];
        if ($idEntrada > 0) {
            $ids[] = $idEntrada;
            $stmt = $pdo->prepare("SELECT id, user_id FROM gm_usuarios_extendidos WHERE id = ? OR user_id = ? LIMIT 1");
            $stmt->execute([$idEntrada, $idEntrada]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            if ($row) {
                $extId = (int)($row['id'] ?? 0);
                $usrId = (int)($row['user_id'] ?? 0);
                if ($extId > 0 && !in_array($extId, $ids, true)) {
                    $ids[] = $extId;
                }
                if ($usrId > 0 && !in_array($usrId, $ids, true)) {
                    $ids[] = $usrId;
                }
            }
        }
        return $ids;
    }

    private static function asegurarTablaMeta(PDO $pdo): void
    {
        $pdo->exec("
            CREATE TABLE IF NOT EXISTS gm_medico_meta (
                id INT AUTO_INCREMENT PRIMARY KEY,
                medico_id INT NOT NULL,
                meta_key VARCHAR(120) NOT NULL,
                meta_value LONGTEXT NULL,
                UNIQUE KEY uniq_medico_meta (medico_id, meta_key)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");
    }

    private static function guardarMeta(PDO $pdo, array $medicoIds, string $key, string $value): void
    {
        self::asegurarTablaMeta($pdo);
        $stmt = $pdo->prepare("
            INSERT INTO gm_medico_meta (medico_id, meta_key, meta_value)
            VALUES (?, ?, ?)
            ON DUPLICATE KEY UPDATE meta_value = VALUES(meta_value)
        ");
        foreach ($medicoIds as $mid) {
            $stmt->execute([$mid, $key, $value]);
        }
    }

    private static function leerMeta(PDO $pdo, array $medicoIds, string $key): string
    {
        if (empty($medicoIds)) {
            return '';
        }
        self::asegurarTablaMeta($pdo);
        $placeholders = implode(',', array_fill(0, count($medicoIds), '?'));
        $stmt = $pdo->prepare("
            SELECT meta_value FROM gm_medico_meta
            WHERE medico_id IN ($placeholders) AND meta_key = ? AND meta_value IS NOT NULL AND meta_value != ''
            LIMIT 1
        ");
        $params = array_merge($medicoIds, [$key]);
        $stmt->execute($params);
        $val = $stmt->fetchColumn();
        return $val !== false ? (string)$val : '';
    }

    private static function baseUrlPublica(): string
    {
        $proto = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
        if (!empty($_SERVER['HTTP_X_FORWARDED_PROTO'])) {
            $proto = explode(',', $_SERVER['HTTP_X_FORWARDED_PROTO'])[0];
        }
        $host = $_SERVER['HTTP_HOST'] ?? 'api.ginevapp.com';
        return trim($proto) . '://' . trim($host);
    }

    private static function directorioFirmas(): string
    {
        $dir = __DIR__ . '/../../public/uploads/firmas';
        if (!is_dir($dir)) {
            @mkdir($dir, 0775, true);
        }
        return $dir;
    }

    /**
     * GET /api/medico/firma?medico_id=X
     */
    public static function obtenerFirma(): void
    {
        $medicoId = (int)($_GET['medico_id'] ?? 0);
        if ($medicoId <= 0) {
            http_response_code(400);
            echo json_encode(['status' => 'error', 'mensaje' => 'medico_id requerido'], JSON_UNESCAPED_UNICODE);
            return;
        }

        $pdo = Database::conectar();
        $ids = self::resolverMedicoIds($pdo, $medicoId);

        $firmaUrl = self::leerMeta($pdo, $ids, 'firma_url');
        $firmaSvgUrl = self::leerMeta($pdo, $ids, 'firma_svg_url');
        $firmaTipo = self::leerMeta($pdo, $ids, 'firma_tipo');

        echo json_encode([
            'status'        => 'success',
            'tiene_firma'   => $firmaUrl !== '' || $firmaSvgUrl !== '',
            'firma_url'     => $firmaUrl,
            'firma_svg_url' => $firmaSvgUrl,
            'firma_tipo'    => $firmaTipo !== '' ? $firmaTipo : 'canvas',
        ], JSON_UNESCAPED_UNICODE);
    }

    /**
     * POST /api/medico/firma/canvas
     * Recibe svg_data y/o png_base64 generados directamente desde el pad táctil suavizado de Flutter.
     */
    public static function guardarDesdeCanvas(array $input): void
    {
        $medicoId = (int)($input['medico_id'] ?? 0);
        $svgData = trim((string)($input['svg_data'] ?? ''));
        $pngBase64 = trim((string)($input['png_base64'] ?? ''));

        if ($medicoId <= 0 || ($svgData === '' && $pngBase64 === '')) {
            http_response_code(400);
            echo json_encode([
                'status'  => 'error',
                'mensaje' => 'Datos de firma táctil incompletos.'
            ], JSON_UNESCAPED_UNICODE);
            return;
        }

        $pdo = Database::conectar();
        $ids = self::resolverMedicoIds($pdo, $medicoId);
        $idPrincipal = $ids[0] ?? $medicoId;
        $dir = self::directorioFirmas();
        $baseUrl = self::baseUrlPublica();
        $ts = time();

        $svgUrl = '';
        $pngUrl = '';

        // 1. Guardar archivo vectorizado SVG
        if ($svgData !== '' && stripos($svgData, '<svg') !== false) {
            $nombreSvg = "firma_{$idPrincipal}_{$ts}.svg";
            file_put_contents($dir . '/' . $nombreSvg, $svgData);
            $svgUrl = $baseUrl . '/api/medico/firma/ver?file=' . urlencode($nombreSvg);
        }

        // 2. Guardar respaldo PNG transparente de alta resolución (ideal para Dompdf)
        if ($pngBase64 !== '') {
            $limpio = preg_replace('#^data:image/\w+;base64,#i', '', $pngBase64);
            $binario = base64_decode($limpio, true);
            if ($binario !== false && strlen($binario) > 50) {
                $nombrePng = "firma_{$idPrincipal}_{$ts}.png";
                file_put_contents($dir . '/' . $nombrePng, $binario);
                $pngUrl = $baseUrl . '/api/medico/firma/ver?file=' . urlencode($nombrePng);
            }
        }

        $urlPrincipal = $pngUrl !== '' ? $pngUrl : $svgUrl;
        self::guardarMeta($pdo, $ids, 'firma_url', $urlPrincipal);
        self::guardarMeta($pdo, $ids, 'firma_svg_url', $svgUrl !== '' ? $svgUrl : $urlPrincipal);
        self::guardarMeta($pdo, $ids, 'firma_tipo', 'canvas');

        echo json_encode([
            'status'        => 'success',
            'mensaje'       => 'Firma digital vectorizada guardada correctamente.',
            'firma_url'     => $urlPrincipal,
            'firma_svg_url' => $svgUrl !== '' ? $svgUrl : $urlPrincipal,
            'firma_tipo'    => 'canvas',
        ], JSON_UNESCAPED_UNICODE);
    }

    /**
     * POST /api/medico/firma/imagen
     * Recibe 1 a 3 muestras en fondo blanco, valida consistencia, elige la mejor,
     * elimina el fondo blanco a canal alfa transparente y vectoriza a SVG + PNG.
     */
    public static function procesarImagenes(array $input): void
    {
        $medicoId = (int)($input['medico_id'] ?? $_POST['medico_id'] ?? 0);
        $colorTinta = trim((string)($input['color_tinta'] ?? $_POST['color_tinta'] ?? '#0F172A'));

        if ($medicoId <= 0) {
            http_response_code(400);
            echo json_encode(['status' => 'error', 'mensaje' => 'medico_id requerido'], JSON_UNESCAPED_UNICODE);
            return;
        }

        $muestrasBinarias = [];

        // Soporte JSON con arreglo muestras_base64
        if (!empty($input['muestras_base64']) && is_array($input['muestras_base64'])) {
            foreach ($input['muestras_base64'] as $b64) {
                $limpio = preg_replace('#^data:image/\w+;base64,#i', '', (string)$b64);
                $bin = base64_decode($limpio, true);
                if ($bin !== false && strlen($bin) > 100) {
                    $muestrasBinarias[] = $bin;
                }
            }
        }

        // Soporte Multipart ($_FILES)
        if (empty($muestrasBinarias) && !empty($_FILES)) {
            foreach ($_FILES as $fileEntry) {
                if (is_array($fileEntry['tmp_name'])) {
                    foreach ($fileEntry['tmp_name'] as $tmp) {
                        if ($tmp && is_uploaded_file($tmp)) {
                            $muestrasBinarias[] = file_get_contents($tmp);
                        }
                    }
                } elseif (!empty($fileEntry['tmp_name']) && is_uploaded_file($fileEntry['tmp_name'])) {
                    $muestrasBinarias[] = file_get_contents($fileEntry['tmp_name']);
                }
            }
        }

        if (empty($muestrasBinarias)) {
            http_response_code(400);
            echo json_encode([
                'status'  => 'error',
                'mensaje' => 'Debes enviar al menos 1 imagen de tu firma sobre fondo blanco.'
            ], JSON_UNESCAPED_UNICODE);
            return;
        }

        if (!function_exists('imagecreatefromstring')) {
            http_response_code(500);
            echo json_encode([
                'status'  => 'error',
                'mensaje' => 'La extensión PHP GD no está habilitada en el servidor.'
            ], JSON_UNESCAPED_UNICODE);
            return;
        }

        $evaluadas = [];
        foreach (array_slice($muestrasBinarias, 0, 3) as $bin) {
            $res = self::analizarYLimpiarMuestra($bin, $colorTinta);
            if ($res !== null) {
                $evaluadas[] = $res;
            }
        }

        if (empty($evaluadas)) {
            http_response_code(422);
            echo json_encode([
                'status'  => 'error',
                'mensaje' => 'No se detectaron trazos claros de firma. Asegúrate de usar tinta oscura sobre fondo blanco.'
            ], JSON_UNESCAPED_UNICODE);
            return;
        }

        // Validación de consistencia biométrica básica si envió 2 o 3 muestras
        if (count($evaluadas) >= 2) {
            $ratios = array_column($evaluadas, 'aspect_ratio');
            $minR = min($ratios);
            $maxR = max($ratios);
            if ($minR > 0 && ($maxR / $minR) > 3.2) {
                http_response_code(422);
                echo json_encode([
                    'status'  => 'error',
                    'mensaje' => 'Las muestras enviadas tienen proporciones muy distintas entre sí. Sube 3 fotos de la misma firma.'
                ], JSON_UNESCAPED_UNICODE);
                return;
            }
        }

        // Elegir la muestra con mejor contraste y nitidez
        usort($evaluadas, fn($a, $b) => $b['score'] <=> $a['score']);
        $mejor = $evaluadas[0];

        $pdo = Database::conectar();
        $ids = self::resolverMedicoIds($pdo, $medicoId);
        $idPrincipal = $ids[0] ?? $medicoId;
        $dir = self::directorioFirmas();
        $baseUrl = self::baseUrlPublica();
        $ts = time();

        $nombrePng = "firma_{$idPrincipal}_{$ts}.png";
        $nombreSvg = "firma_{$idPrincipal}_{$ts}.svg";

        file_put_contents($dir . '/' . $nombrePng, $mejor['png_bytes']);
        file_put_contents($dir . '/' . $nombreSvg, $mejor['svg_string']);

        $pngUrl = $baseUrl . '/api/medico/firma/ver?file=' . urlencode($nombrePng);
        $svgUrl = $baseUrl . '/api/medico/firma/ver?file=' . urlencode($nombreSvg);

        self::guardarMeta($pdo, $ids, 'firma_url', $pngUrl);
        self::guardarMeta($pdo, $ids, 'firma_svg_url', $svgUrl);
        self::guardarMeta($pdo, $ids, 'firma_tipo', 'imagen_vectorizada');

        echo json_encode([
            'status'             => 'success',
            'mensaje'            => 'Firma procesada, sin fondo y vectorizada correctamente (' . count($evaluadas) . ' muestra(s) evaluadas).',
            'muestras_evaluadas' => count($evaluadas),
            'firma_url'          => $pngUrl,
            'firma_svg_url'      => $svgUrl,
            'firma_tipo'         => 'imagen_vectorizada',
        ], JSON_UNESCAPED_UNICODE);
    }

    /**
     * Convierte una imagen en fondo blanco a PNG transparente recortado + SVG vectorizado por contornos.
     */
    private static function analizarYLimpiarMuestra(string $binario, string $hexColor): ?array
    {
        $src = @imagecreatefromstring($binario);
        if (!$src) {
            return null;
        }

        $origW = imagesx($src);
        $origH = imagesy($src);
        if ($origW < 20 || $origH < 20) {
            imagedestroy($src);
            return null;
        }

        // Normalizar tamaño de trabajo para procesamiento rápido y limpio
        $maxDim = 700;
        $scale = min(1.0, $maxDim / max($origW, $origH));
        $w = max(1, (int)round($origW * $scale));
        $h = max(1, (int)round($origH * $scale));

        $work = imagecreatetruecolor($w, $h);
        imagecopyresampled($work, $src, 0, 0, 0, 0, $w, $h, $origW, $origH);
        imagedestroy($src);

        // Calcular luminancia promedio del fondo (Otsu simplificado / adaptativo)
        $hist = array_fill(0, 256, 0);
        $totalPix = $w * $h;
        for ($y = 0; $y < $h; $y++) {
            for ($x = 0; $x < $w; $x++) {
                $rgb = imagecolorat($work, $x, $y);
                $r = ($rgb >> 16) & 0xFF;
                $g = ($rgb >> 8) & 0xFF;
                $b = $rgb & 0xFF;
                $lum = (int)round(0.299 * $r + 0.587 * $g + 0.114 * $b);
                $hist[$lum]++;
            }
        }

        // Método de Otsu para separar tinta oscura del papel blanco/sombra
        $sum = 0;
        for ($i = 0; $i < 256; $i++) {
            $sum += $i * $hist[$i];
        }
        $sumB = 0;
        $wB = 0;
        $varMax = 0.0;
        $threshold = 175;
        for ($t = 0; $t < 256; $t++) {
            $wB += $hist[$t];
            if ($wB === 0) {
                continue;
            }
            $wF = $totalPix - $wB;
            if ($wF === 0) {
                break;
            }
            $sumB += $t * $hist[$t];
            $mB = $sumB / $wB;
            $mF = ($sum - $sumB) / $wF;
            $varBetween = (float)$wB * (float)$wF * ($mB - $mF) * ($mB - $mF);
            if ($varBetween > $varMax) {
                $varMax = $varBetween;
                $threshold = $t;
            }
        }
        $threshold = max(90, min(215, $threshold - 10));

        // Color de tinta elegido
        $hex = ltrim($hexColor, '#');
        if (strlen($hex) !== 6) {
            $hex = '0F172A';
        }
        $inkR = hexdec(substr($hex, 0, 2));
        $inkG = hexdec(substr($hex, 2, 2));
        $inkB = hexdec(substr($hex, 4, 2));

        // Encontrar bounding box de la tinta y construir matriz binaria
        $minX = $w;
        $minY = $h;
        $maxX = 0;
        $maxY = 0;
        $inkCount = 0;
        $contrasteSum = 0.0;
        $mask = [];

        for ($y = 0; $y < $h; $y++) {
            $row = [];
            for ($x = 0; $x < $w; $x++) {
                $rgb = imagecolorat($work, $x, $y);
                $r = ($rgb >> 16) & 0xFF;
                $g = ($rgb >> 8) & 0xFF;
                $b = $rgb & 0xFF;
                $lum = 0.299 * $r + 0.587 * $g + 0.114 * $b;

                if ($lum < $threshold) {
                    // Suavizado alfa en bordes de tinta
                    $intensidad = min(1.0, ($threshold - $lum) / max(25.0, $threshold * 0.45));
                    $alpha = (int)round((1.0 - $intensidad) * 127);
                    $row[$x] = $alpha;

                    if ($intensidad > 0.25) {
                        $inkCount++;
                        $contrasteSum += $intensidad;
                        if ($x < $minX) $minX = $x;
                        if ($y < $minY) $minY = $y;
                        if ($x > $maxX) $maxX = $x;
                        if ($y > $maxY) $maxY = $y;
                    }
                } else {
                    $row[$x] = 127; // Totalmente transparente
                }
            }
            $mask[$y] = $row;
        }
        imagedestroy($work);

        if ($inkCount < 30 || $maxX <= $minX || $maxY <= $minY) {
            return null;
        }

        // Recortar con margen limpio
        $pad = 12;
        $cropX1 = max(0, $minX - $pad);
        $cropY1 = max(0, $minY - $pad);
        $cropX2 = min($w - 1, $maxX + $pad);
        $cropY2 = min($h - 1, $maxY + $pad);
        $cropW = $cropX2 - $cropX1 + 1;
        $cropH = $cropY2 - $cropY1 + 1;

        // Generar PNG transparente limpio
        $out = imagecreatetruecolor($cropW, $cropH);
        imagesavealpha($out, true);
        imagealphablending($out, false);
        $transparente = imagecolorallocatealpha($out, 0, 0, 0, 127);
        imagefill($out, 0, 0, $transparente);

        for ($y = 0; $y < $cropH; $y++) {
            for ($x = 0; $x < $cropW; $x++) {
                $alpha = $mask[$cropY1 + $y][$cropX1 + $x];
                if ($alpha < 127) {
                    $col = imagecolorallocatealpha($out, $inkR, $inkG, $inkB, $alpha);
                    imagesetpixel($out, $x, $y, $col);
                }
            }
        }

        ob_start();
        imagepng($out, null, 6);
        $pngBytes = ob_get_clean();
        imagedestroy($out);

        // Vectorizar trazos horizontales comprimidos (RLE paths suaves en SVG)
        $paths = [];
        for ($y = 0; $y < $cropH; $y++) {
            $runStart = -1;
            for ($x = 0; $x < $cropW; $x++) {
                $esTinta = $mask[$cropY1 + $y][$cropX1 + $x] < 85;
                if ($esTinta && $runStart === -1) {
                    $runStart = $x;
                } elseif (!$esTinta && $runStart !== -1) {
                    $len = $x - $runStart;
                    $paths[] = "M{$runStart} {$y}h{$len}";
                    $runStart = -1;
                }
            }
            if ($runStart !== -1) {
                $len = $cropW - $runStart;
                $paths[] = "M{$runStart} {$y}h{$len}";
            }
        }

        $dAttr = implode(' ', $paths);
        $svgString = '<?xml version="1.0" encoding="UTF-8"?>'
            . '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 ' . $cropW . ' ' . $cropH . '" width="' . $cropW . '" height="' . $cropH . '">'
            . '<path d="' . $dAttr . '" stroke="#' . $hex . '" stroke-width="1.35" stroke-linecap="round" fill="none"/>'
            . '</svg>';

        $aspectRatio = $cropW / max(1, $cropH);
        $score = ($contrasteSum / max(1, $inkCount)) * 100.0 + min(50.0, $inkCount / 200.0);

        return [
            'png_bytes'    => $pngBytes,
            'svg_string'   => $svgString,
            'aspect_ratio' => $aspectRatio,
            'score'        => $score,
        ];
    }

    /**
     * GET /api/medico/firma/ver?file=firma_X.png
     */
    public static function verFirma(): void
    {
        if (ob_get_length()) {
            ob_clean();
        }
        $archivo = basename((string)($_GET['file'] ?? ''));
        $ruta = self::directorioFirmas() . '/' . $archivo;

        if ($archivo === '' || !file_exists($ruta)) {
            http_response_code(404);
            exit;
        }

        $ext = strtolower(pathinfo($archivo, PATHINFO_EXTENSION));
        if ($ext === 'svg') {
            header('Content-Type: image/svg+xml; charset=utf-8');
        } else {
            header('Content-Type: image/png');
        }
        header('Cache-Control: public, max-age=86400');
        readfile($ruta);
        exit;
    }

    /**
     * DELETE /api/medico/firma?medico_id=X
     */
    public static function eliminarFirma(): void
    {
        $medicoId = (int)($_GET['medico_id'] ?? 0);
        if ($medicoId <= 0) {
            http_response_code(400);
            echo json_encode(['status' => 'error', 'mensaje' => 'medico_id requerido'], JSON_UNESCAPED_UNICODE);
            return;
        }

        $pdo = Database::conectar();
        $ids = self::resolverMedicoIds($pdo, $medicoId);
        self::guardarMeta($pdo, $ids, 'firma_url', '');
        self::guardarMeta($pdo, $ids, 'firma_svg_url', '');
        self::guardarMeta($pdo, $ids, 'firma_tipo', '');

        echo json_encode([
            'status'  => 'success',
            'mensaje' => 'Firma eliminada correctamente.'
        ], JSON_UNESCAPED_UNICODE);
    }
}