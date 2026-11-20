<?php
namespace App\Controllers;

use App\Config\Database;
use PDO;

class PacientePerfilController
{
    public static function obtenerPerfil(): void
    {
        header('Content-Type: application/json; charset=utf-8');
        $pacienteId = isset($_GET['paciente_id']) ? (int)$_GET['paciente_id'] : (isset($_GET['user_id']) ? (int)$_GET['user_id'] : 0);

        if ($pacienteId <= 0) {
            echo json_encode(['status' => 'error', 'mensaje' => 'paciente_id requerido']);
            return;
        }

        try {
            $db = Database::getConnection();
            $stmt = $db->prepare("SELECT * FROM gm_usuarios_extendidos WHERE user_id = :user_id LIMIT 1");
            $stmt->execute([':user_id' => $pacienteId]);
            $perfil = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];

            $avatarUrl = $perfil['avatar_url'] ?? '';
            if (empty($avatarUrl)) {
                $stmtMeta = $db->prepare("SELECT avatar_url FROM gm_usuarios_extendidos WHERE user_id = :user_id LIMIT 1");
                $stmtMeta->execute([':user_id' => $pacienteId]);
            }

            // Buscar archivo más reciente si existe en /app/public/uploads/pacientes/avatar
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
            ]);
        } catch (\Throwable $e) {
            http_response_code(500);
            echo json_encode(['status' => 'error', 'mensaje' => 'Error al consultar perfil: ' . $e->getMessage()]);
        }
    }

    public static function guardarPerfil(array $input = []): void
    {
        header('Content-Type: application/json; charset=utf-8');
        if (empty($input)) {
            $raw = file_get_contents('php://input');
            $decoded = json_decode($raw, true);
            $input = is_array($decoded) ? $decoded : $_POST;
        }

        $pacienteId = isset($input['paciente_id']) ? (int)$input['paciente_id'] : (isset($input['user_id']) ? (int)$input['user_id'] : 0);
        if ($pacienteId <= 0) {
            echo json_encode(['status' => 'error', 'mensaje' => 'paciente_id inválido']);
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
        $peso             = isset($input['peso']) && $input['peso'] !== '' ? (string)$input['peso'] : null;
        $estatura         = isset($input['estatura']) && $input['estatura'] !== '' ? (string)$input['estatura'] : null;
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
            $db = Database::getConnection();
            $stmtCheck = $db->prepare("SELECT COUNT(*) FROM gm_usuarios_extendidos WHERE user_id = :user_id");
            $stmtCheck->execute([':user_id' => $pacienteId]);
            $existe = (int)$stmtCheck->fetchColumn() > 0;

            if ($existe) {
                $sql = "UPDATE gm_usuarios_extendidos SET
                            nombre = :nombre,
                            segundo_nombre = :segundo_nombre,
                            apellido = :apellido,
                            segundo_apellido = :segundo_apellido,
                            email = :email,
                            whatsapp = :whatsapp,
                            sexo = :sexo,
                            fecha_nacimiento = :fecha_nacimiento,
                            edad = :edad,
                            ocupacion = :ocupacion,
                            peso = :peso,
                            estatura = :estatura,
                            alergias = :alergias,
                            pais = :pais,
                            estado = :estado,
                            direccion_detalle = :direccion_detalle
                        WHERE user_id = :user_id";
                $stmt = $db->prepare($sql);
                $stmt->execute([
                    ':nombre'            => $nombre,
                    ':segundo_nombre'    => $segundoNombre,
                    ':apellido'          => $apellido,
                    ':segundo_apellido'  => $segundoApellido,
                    ':email'             => $email,
                    ':whatsapp'          => $whatsapp,
                    ':sexo'              => $sexo,
                    ':fecha_nacimiento'  => $fechaNacimiento !== '' ? $fechaNacimiento : null,
                    ':edad'              => $edad,
                    ':ocupacion'         => $ocupacion,
                    ':peso'              => $peso,
                    ':estatura'          => $estatura,
                    ':alergias'          => $alergias,
                    ':pais'              => $pais,
                    ':estado'            => $estado,
                    ':direccion_detalle' => $direccionDetalle,
                    ':user_id'           => $pacienteId,
                ]);
            } else {
                $sql = "INSERT INTO gm_usuarios_extendidos (
                            user_id, tipo_identificacion, cedula, nombre, segundo_nombre, apellido, segundo_apellido,
                            email, whatsapp, sexo, fecha_nacimiento, edad, ocupacion, peso, estatura,
                            alergias, pais, estado, direccion_detalle, rol_medico
                        ) VALUES (
                            :user_id, 'V', :cedula, :nombre, :segundo_nombre, :apellido, :segundo_apellido,
                            :email, :whatsapp, :sexo, :fecha_nacimiento, :edad, :ocupacion, :peso, :estatura,
                            :alergias, :pais, :estado, :direccion_detalle, 'gm_paciente'
                        )";
                $stmt = $db->prepare($sql);
                $stmt->execute([
                    ':user_id'           => $pacienteId,
                    ':cedula'            => 'PAC-' . $pacienteId,
                    ':nombre'            => $nombre,
                    ':segundo_nombre'    => $segundoNombre,
                    ':apellido'          => $apellido,
                    ':segundo_apellido'  => $segundoApellido,
                    ':email'             => $email,
                    ':whatsapp'          => $whatsapp,
                    ':sexo'              => $sexo,
                    ':fecha_nacimiento'  => $fechaNacimiento !== '' ? $fechaNacimiento : null,
                    ':edad'              => $edad,
                    ':ocupacion'         => $ocupacion,
                    ':peso'              => $peso,
                    ':estatura'          => $estatura,
                    ':alergias'          => $alergias,
                    ':pais'              => $pais,
                    ':estado'            => $estado,
                    ':direccion_detalle' => $direccionDetalle,
                ]);
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
            ]);
        } catch (\Throwable $e) {
            http_response_code(500);
            echo json_encode(['status' => 'error', 'mensaje' => 'Error al guardar perfil: ' . $e->getMessage()]);
        }
    }

    public static function subirAvatar(): void
    {
        if (ob_get_length()) {
            ob_clean();
        }
        header('Content-Type: application/json; charset=utf-8');

        $pacienteId = isset($_POST['paciente_id']) ? (int)$_POST['paciente_id'] : (isset($_POST['user_id']) ? (int)$_POST['user_id'] : 0);
        if ($pacienteId <= 0) {
            echo json_encode(['status' => 'error', 'mensaje' => 'paciente_id requerido']);
            return;
        }

        if (!isset($_FILES['avatar']) || $_FILES['avatar']['error'] !== UPLOAD_ERR_OK) {
            echo json_encode(['status' => 'error', 'mensaje' => 'No se recibió una imagen válida']);
            return;
        }

        $tmpName = $_FILES['avatar']['tmp_name'];
        $info = @getimagesize($tmpName);
        if ($info === false) {
            echo json_encode(['status' => 'error', 'mensaje' => 'El archivo no es una imagen válida']);
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
        if (!is_dir($dirDestino) && !mkdir($dirDestino, 0775, true) && !is_dir($dirDestino)) {
            echo json_encode(['status' => 'error', 'mensaje' => 'No se pudo crear la carpeta pacientes/avatar']);
            return;
        }

        // Eliminar avatares previos del mismo paciente para evitar archivos huérfanos
        foreach (glob($dirDestino . "/paciente_{$pacienteId}_*") as $viejo) {
            @unlink($viejo);
        }

        $nombreArchivo = 'paciente_' . $pacienteId . '_' . time() . '.' . $ext;
        $rutaCompleta = $dirDestino . '/' . $nombreArchivo;

        if (!move_uploaded_file($tmpName, $rutaCompleta)) {
            echo json_encode(['status' => 'error', 'mensaje' => 'No se pudo guardar la imagen en pacientes/avatar']);
            return;
        }

        $avatarUrl = self::construirUrlAvatar($nombreArchivo);

        echo json_encode([
            'status'     => 'success',
            'mensaje'    => 'Foto de perfil guardada en pacientes/avatar',
            'archivo'    => $nombreArchivo,
            'ruta'       => 'public/uploads/pacientes/avatar/' . $nombreArchivo,
            'avatar_url' => $avatarUrl,
        ]);
    }

    public static function verAvatar(): void
    {
        $file = basename((string)($_GET['file'] ?? ''));
        if ($file === '' || !preg_match('/^paciente_[0-9]+_[0-9]+\.(jpg|jpeg|png|webp)$/i', $file)) {
            http_response_code(400);
            echo 'Archivo no válido';
            return;
        }

        $ruta = self::directorioAvatar() . '/' . $file;
        if (!is_file($ruta)) {
            http_response_code(404);
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
        header('Content-Length: ' . filesize($ruta));
        readfile($ruta);
    }

    private static function directorioAvatar(): string
    {
        // Resuelve a /app/public/uploads/pacientes/avatar en Easypanel
        return dirname(__DIR__, 2) . '/public/uploads/pacientes/avatar';
    }

    private static function construirUrlAvatar(string $nombreArchivo): string
    {
        $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
        $host = $_SERVER['HTTP_HOST'] ?? 'dashboard.ginevapp.com';
        return $scheme . '://' . $host . '/api/paciente/avatar/ver?file=' . rawurlencode($nombreArchivo);
    }
}