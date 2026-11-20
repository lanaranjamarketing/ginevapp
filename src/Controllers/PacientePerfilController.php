<?php
namespace App\Controllers;

use App\Config\Database;
use PDO;

class PacientePerfilController
{
    public static function obtenerPerfil(): void
    {
        if (ob_get_length()) {
            ob_clean();
        }
        header('Content-Type: application/json; charset=utf-8');

        $pacienteId = isset($_GET['paciente_id'])
            ? (int)$_GET['paciente_id']
            : (isset($_GET['user_id']) ? (int)$_GET['user_id'] : 0);

        if ($pacienteId <= 0) {
            http_response_code(400);
            echo json_encode(['status' => 'error', 'mensaje' => 'paciente_id requerido'], JSON_UNESCAPED_UNICODE);
            return;
        }

        try {
            $db = Database::conectar();
            $stmt = $db->prepare("SELECT * FROM gm_usuarios_extendidos WHERE user_id = :user_id LIMIT 1");
            $stmt->execute([':user_id' => $pacienteId]);
            $perfil = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];

            $avatarUrl = $perfil['avatar_url'] ?? '';

            $dirAvatar = self::directorioAvatar();
            $prefijo = "paciente_{$pacienteId}_";
            if (is_dir($dirAvatar)) {
                $archivos = glob($dirAvatar . '/' . $prefijo . '*');
                if ($archivos) {
                    usort($archivos, function ($a, $b) {
                        return filemtime($b) <=> filemtime($a);
                    });
                    $nombreArchivo = basename($archivos[0]);
                    $avatarUrl = self::construirUrlAvatar($nombreArchivo);
                }
            }

            if (!empty($avatarUrl)) {
                $perfil['avatar_url'] = $avatarUrl;
            }

            echo json_encode([
                'status' => 'success',
                'data'   => $perfil,
            ], JSON_UNESCAPED_UNICODE);
        } catch (\Throwable $e) {
            http_response_code(500);
            echo json_encode([
                'status'  => 'error',
                'mensaje' => 'Error al consultar perfil: ' . $e->getMessage(),
            ], JSON_UNESCAPED_UNICODE);
        }
    }

    public static function guardarPerfil(array $input = []): void
    {
        if (ob_get_length()) {
            ob_clean();
        }
        header('Content-Type: application/json; charset=utf-8');

        if (empty($input)) {
            $raw = file_get_contents('php://input');
            $decoded = json_decode($raw, true);
            $input = is_array($decoded) ? $decoded : $_POST;
        }

        $pacienteId = isset($input['paciente_id'])
            ? (int)$input['paciente_id']
            : (isset($input['user_id']) ? (int)$input['user_id'] : 0);

        if ($pacienteId <= 0) {
            http_response_code(400);
            echo json_encode(['status' => 'error', 'mensaje' => 'paciente_id inválido'], JSON_UNESCAPED_UNICODE);
            return;
        }

        $nombre           = trim((string)($input['nombre'] ?? ''));
        $segundoNombre    = trim((string)($input['segundo_nombre'] ?? ''));
        $apellido         = trim((string)($input['apellido'] ?? ''));
        $segundoApellido  = trim((string)($input['segundo_apellido'] ?? ''));
        $email            = trim((string)($input['email'] ?? ''));
        $whatsappPrefijo  = preg_replace('/[^0-9]/', '', (string)($input['whatsapp_prefijo'] ?? '58'));
        $whatsappNumero   = preg_replace('/[^0-9]/', '', (string)($input['whatsapp_numero'] ?? ''));
        $whatsapp         = !empty($input['whatsapp'])
            ? preg_replace('/[^0-9]/', '', (string)$input['whatsapp'])
            : ($whatsappPrefijo . $whatsappNumero);
        $sexo             = trim((string)($input['sexo'] ?? ''));
        $fechaNacimiento  = trim((string)($input['fecha_nacimiento'] ?? ''));
        $ocupacion        = trim((string)($input['ocupacion'] ?? $input['profesion'] ?? ''));
        $peso             = (isset($input['peso']) && $input['peso'] !== '') ? (string)$input['peso'] : null;
        $estatura         = (isset($input['estatura']) && $input['estatura'] !== '') ? (string)$input['estatura'] : null;
        $alergias         = trim((string)($input['alergias'] ?? ''));
        $pais             = trim((string)($input['pais'] ?? ''));
        $estado           = trim((string)($input['estado'] ?? ''));
        $direccionDetalle = trim((string)($input['direccion_detalle'] ?? ''));
        $avatarUrl        = trim((string)($input['avatar_url'] ?? ''));

        $edad = null;
        if (!empty($fechaNacimiento)) {
            try {
                $fn = new \DateTime($fechaNacimiento);
                $edad = (new \DateTime())->diff($fn)->y;
            } catch (\Throwable $e) {
                $edad = null;
            }
        }

        try {
            $db = Database::conectar();
            $columnasTabla = self::obtenerColumnasTabla($db, 'gm_usuarios_extendidos');

            $candidatos = [
                'nombre'            => $nombre,
                'segundo_nombre'    => $segundoNombre,
                'apellido'          => $apellido,
                'segundo_apellido'  => $segundoApellido,
                'email'             => $email,
                'whatsapp'          => $whatsapp,
                'sexo'              => $sexo,
                'fecha_nacimiento'  => $fechaNacimiento !== '' ? $fechaNacimiento : null,
                'edad'              => $edad,
                'ocupacion'         => $ocupacion,
                'peso'              => $peso,
                'estatura'          => $estatura,
                'alergias'          => $alergias,
                'pais'              => $pais,
                'estado'            => $estado,
                'direccion_detalle' => $direccionDetalle,
            ];

            if (!empty($avatarUrl) && in_array('avatar_url', $columnasTabla, true)) {
                $candidatos['avatar_url'] = $avatarUrl;
            }

            $camposFiltrados = [];
            foreach ($candidatos as $col => $val) {
                if (in_array($col, $columnasTabla, true)) {
                    $camposFiltrados[$col] = $val;
                }
            }

            $stmtCheck = $db->prepare("SELECT COUNT(*) FROM gm_usuarios_extendidos WHERE user_id = :user_id");
            $stmtCheck->execute([':user_id' => $pacienteId]);
            $existe = (int)$stmtCheck->fetchColumn() > 0;

            if ($existe) {
                if (!empty($camposFiltrados)) {
                    $sets = [];
                    $params = [':user_id' => $pacienteId];
                    foreach ($camposFiltrados as $col => $val) {
                        $ph = ':p_' . $col;
                        $sets[] = "`$col` = $ph";
                        $params[$ph] = $val;
                    }
                    $sql = "UPDATE gm_usuarios_extendidos SET " . implode(', ', $sets) . " WHERE user_id = :user_id";
                    $stmt = $db->prepare($sql);
                    $stmt->execute($params);
                }
            } else {
                $insertCols = ['user_id'];
                $insertVals = [':user_id'];
                $params = [':user_id' => $pacienteId];

                if (in_array('tipo_identificacion', $columnasTabla, true)) {
                    $insertCols[] = 'tipo_identificacion';
                    $insertVals[] = ':tipo_identificacion';
                    $params[':tipo_identificacion'] = 'V';
                }
                if (in_array('cedula', $columnasTabla, true)) {
                    $insertCols[] = 'cedula';
                    $insertVals[] = ':cedula';
                    $params[':cedula'] = 'PAC-' . $pacienteId;
                }
                if (in_array('rol', $columnasTabla, true)) {
                    $insertCols[] = 'rol';
                    $insertVals[] = ':rol';
                    $params[':rol'] = 'paciente';
                }
                if (in_array('rol_medico', $columnasTabla, true)) {
                    $insertCols[] = 'rol_medico';
                    $insertVals[] = ':rol_medico';
                    $params[':rol_medico'] = 'gm_paciente';
                }

                foreach ($camposFiltrados as $col => $val) {
                    $ph = ':p_' . $col;
                    $insertCols[] = "`$col`";
                    $insertVals[] = $ph;
                    $params[$ph] = $val;
                }

                $sql = "INSERT INTO gm_usuarios_extendidos (" . implode(', ', $insertCols) . ") VALUES (" . implode(', ', $insertVals) . ")";
                $stmt = $db->prepare($sql);
                $stmt->execute($params);
            }

            $stmtSel = $db->prepare("SELECT * FROM gm_usuarios_extendidos WHERE user_id = :user_id LIMIT 1");
            $stmtSel->execute([':user_id' => $pacienteId]);
            $actualizado = $stmtSel->fetch(PDO::FETCH_ASSOC) ?: [];
            if (!empty($avatarUrl)) {
                $actualizado['avatar_url'] = $avatarUrl;
            }

            echo json_encode([
                'status'  => 'success',
                'mensaje' => '¡Tus datos personales y clínicos han sido actualizados con éxito!',
                'data'    => $actualizado,
            ], JSON_UNESCAPED_UNICODE);
        } catch (\Throwable $e) {
            http_response_code(500);
            echo json_encode([
                'status'  => 'error',
                'mensaje' => 'Error al guardar perfil: ' . $e->getMessage(),
            ], JSON_UNESCAPED_UNICODE);
        }
    }

    public static function subirAvatar(): void
    {
        @ini_set('display_errors', '0');
        if (ob_get_length()) {
            ob_clean();
        }
        header('Content-Type: application/json; charset=utf-8');

        $pacienteId = isset($_POST['paciente_id'])
            ? (int)$_POST['paciente_id']
            : (isset($_POST['user_id']) ? (int)$_POST['user_id'] : 0);

        if ($pacienteId <= 0) {
            http_response_code(400);
            echo json_encode(['status' => 'error', 'mensaje' => 'paciente_id requerido'], JSON_UNESCAPED_UNICODE);
            return;
        }

        if (!isset($_FILES['avatar']) || $_FILES['avatar']['error'] !== UPLOAD_ERR_OK) {
            $errCode = $_FILES['avatar']['error'] ?? -1;
            http_response_code(400);
            echo json_encode([
                'status'  => 'error',
                'mensaje' => "No se recibió una imagen válida (código $errCode)",
            ], JSON_UNESCAPED_UNICODE);
            return;
        }

        $tmpName = $_FILES['avatar']['tmp_name'];
        $info = @getimagesize($tmpName);
        if ($info === false) {
            http_response_code(415);
            echo json_encode(['status' => 'error', 'mensaje' => 'El archivo no es una imagen válida'], JSON_UNESCAPED_UNICODE);
            return;
        }

        $mime = $info['mime'] ?? '';
        $extMap = [
            'image/jpeg' => 'jpg',
            'image/png'  => 'png',
            'image/webp' => 'webp',
        ];
        $ext = $extMap[$mime] ?? 'jpg';

        $dirDestino = self::directorioAvatar();
        if (!is_dir($dirDestino) && !@mkdir($dirDestino, 0777, true) && !is_dir($dirDestino)) {
            http_response_code(500);
            echo json_encode([
                'status'  => 'error',
                'mensaje' => "No se pudo crear la carpeta $dirDestino",
            ], JSON_UNESCAPED_UNICODE);
            return;
        }

        foreach (glob($dirDestino . "/paciente_{$pacienteId}_*") as $viejo) {
            @unlink($viejo);
        }

        $nombreArchivo = 'paciente_' . $pacienteId . '_' . time() . '.' . $ext;
        $rutaCompleta = $dirDestino . '/' . $nombreArchivo;

        if (!@move_uploaded_file($tmpName, $rutaCompleta)) {
            http_response_code(500);
            echo json_encode([
                'status'  => 'error',
                'mensaje' => "No se pudo guardar la imagen en $dirDestino",
            ], JSON_UNESCAPED_UNICODE);
            return;
        }

        $avatarUrl = self::construirUrlAvatar($nombreArchivo);

        // Persistir avatar_url en gm_usuarios_extendidos si existe la columna (o crearla si no existe)
        try {
            $db = Database::conectar();
            $columnas = self::obtenerColumnasTabla($db, 'gm_usuarios_extendidos');
            if (!in_array('avatar_url', $columnas, true)) {
                $db->exec("ALTER TABLE `gm_usuarios_extendidos` ADD COLUMN `avatar_url` VARCHAR(500) NULL");
            }
            $stmtUpd = $db->prepare("UPDATE `gm_usuarios_extendidos` SET `avatar_url` = :avatar_url WHERE `user_id` = :user_id");
            $stmtUpd->execute([
                ':avatar_url' => $avatarUrl,
                ':user_id'    => $pacienteId,
            ]);
        } catch (\Throwable $e) {
            // Si no se puede actualizar en BD, igual devolvemos la URL del archivo guardado en disco
        }

        echo json_encode([
            'status'     => 'success',
            'mensaje'    => 'Foto de perfil guardada en pacientes/avatar',
            'archivo'    => $nombreArchivo,
            'ruta'       => 'public/uploads/pacientes/avatar/' . $nombreArchivo,
            'avatar_url' => $avatarUrl,
        ], JSON_UNESCAPED_UNICODE);
    }

    public static function verAvatar(): void
    {
        $file = basename((string)($_GET['file'] ?? ''));
        if ($file === '' || !preg_match('/^paciente_[0-9]+_[0-9]+\.(jpg|jpeg|png|webp)$/i', $file)) {
            http_response_code(400);
            header('Content-Type: text/plain; charset=utf-8');
            echo 'Archivo no válido';
            return;
        }

        $ruta = self::directorioAvatar() . '/' . $file;
        if (!is_file($ruta)) {
            http_response_code(404);
            header('Content-Type: text/plain; charset=utf-8');
            echo 'Imagen no encontrada';
            return;
        }

        $ext = strtolower(pathinfo($ruta, PATHINFO_EXTENSION));
        $contentTypes = [
            'jpg'  => 'image/jpeg',
            'jpeg' => 'image/jpeg',
            'png'  => 'image/png',
            'webp' => 'image/webp',
        ];

        header('Access-Control-Allow-Origin: *');
        header('Content-Type: ' . ($contentTypes[$ext] ?? 'image/jpeg'));
        header('Cache-Control: no-cache, must-revalidate');
        header('Content-Length: ' . filesize($ruta));
        readfile($ruta);
    }

    private static function obtenerColumnasTabla(PDO $db, string $tabla): array
    {
        $stmt = $db->query("SHOW COLUMNS FROM `$tabla`");
        $cols = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            if (!empty($row['Field'])) {
                $cols[] = (string)$row['Field'];
            }
        }
        return $cols;
    }

    private static function directorioAvatar(): string
    {
        return dirname(__DIR__, 2) . '/public/uploads/pacientes/avatar';
    }

    private static function construirUrlAvatar(string $nombreArchivo): string
    {
        $host = $_SERVER['HTTP_HOST'] ?? 'api.ginevapp.com';
        return 'https://' . $host . '/api/paciente/avatar/ver?file=' . rawurlencode($nombreArchivo);
    }
}