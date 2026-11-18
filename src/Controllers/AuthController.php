<?php
namespace App\Controllers;

use App\Config\Database;
use PDO;
use Exception;

class AuthController {

    /**
     * Asegura que la tabla temporal de códigos OTP exista en MySQL
     */
    private static function asegurarTablaOtp($pdo) {
        $sql = "CREATE TABLE IF NOT EXISTS `gm_otp_codes` (
            `id` int(11) NOT NULL AUTO_INCREMENT,
            `whatsapp` varchar(30) NOT NULL,
            `codigo` varchar(10) NOT NULL,
            `expira_en` datetime NOT NULL,
            PRIMARY KEY (`id`),
            KEY `whatsapp_idx` (`whatsapp`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;";
        $pdo->exec($sql);
    }

    /**
     * Enviar mensaje de texto mediante Evolution API usando cURL
     */
    private static function enviarWhatsApp($whatsapp, $mensaje) {
        $serverUrl = getenv('EVOLUTION_URL') ?: $_ENV['EVOLUTION_URL'] ?? '';
        $instance  = getenv('EVOLUTION_INSTANCE') ?: $_ENV['EVOLUTION_INSTANCE'] ?? '';
        $apiKey    = getenv('EVOLUTION_APIKEY') ?: $_ENV['EVOLUTION_APIKEY'] ?? '';

        if (empty($serverUrl) || empty($instance) || empty($apiKey)) {
            throw new Exception("Las variables de entorno de Evolution API (EVOLUTION_URL, EVOLUTION_INSTANCE, EVOLUTION_APIKEY) no están configuradas en Easypanel.");
        }

        $endpoint = rtrim($serverUrl, '/') . '/message/sendText/' . trim($instance);

        $payload = json_encode([
            'number'  => $whatsapp,
            'text'    => $mensaje,
            'options' => [
                'delay'       => 1200,
                'presence'    => 'composing',
                'linkPreview' => false
            ]
        ]);

        $ch = curl_init($endpoint);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $payload);
        curl_setopt($ch, CURLOPT_TIMEOUT, 30);
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            'Content-Type: application/json',
            'apikey: ' . $apiKey
        ]);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlErr  = curl_error($ch);
        curl_close($ch);

        if ($curlErr) {
            throw new Exception("Error de conexión con Evolution API: " . $curlErr);
        }

        if ($httpCode !== 200 && $httpCode !== 201) {
            throw new Exception("Evolution API respondió con error (HTTP $httpCode): " . $response);
        }

        return json_decode($response, true);
    }

    /**
     * POST /api/auth/enviar-otp
     * Recibe: { "identificador": "584121234567" } (acepta WhatsApp o Cédula)
     */
    public static function enviarOtp($input) {
        try {
            $identificador = trim($input['identificador'] ?? $input['whatsapp'] ?? '');

            if (empty($identificador)) {
                http_response_code(400);
                echo json_encode(["status" => "error", "mensaje" => "Debe ingresar su número de WhatsApp o cédula."], JSON_UNESCAPED_UNICODE);
                return;
            }

            $pdo = Database::conectar();
            self::asegurarTablaOtp($pdo);

            // Buscar usuario por WhatsApp o por Cédula en gm_usuarios_extendidos
            $stmt = $pdo->prepare("SELECT id, user_id, nombre, apellido, whatsapp, rol_medico FROM gm_usuarios_extendidos WHERE whatsapp = :id OR cedula = :id LIMIT 1");
            $stmt->execute([':id' => $identificador]);
            $usuario = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$usuario) {
                http_response_code(404);
                echo json_encode(["status" => "error", "mensaje" => "El número de WhatsApp o cédula no se encuentra registrado en el sistema."], JSON_UNESCAPED_UNICODE);
                return;
            }

            $whatsappDestino = $usuario['whatsapp'];
            $codigoOtp = str_pad((string)random_int(100000, 999999), 6, '0', STR_PAD_LEFT);
            $expiraEn  = date('Y-m-d H:i:s', strtotime('+5 minutes'));

            // Eliminar códigos anteriores de ese número y guardar el nuevo
            $pdo->prepare("DELETE FROM gm_otp_codes WHERE whatsapp = :wa")->execute([':wa' => $whatsappDestino]);
            $ins = $pdo->prepare("INSERT INTO gm_otp_codes (whatsapp, codigo, expira_en) VALUES (:wa, :cod, :exp)");
            $ins->execute([
                ':wa'  => $whatsappDestino,
                ':cod' => $codigoOtp,
                ':exp' => $expiraEn
            ]);

            // Enviar mensaje por WhatsApp
            $mensaje = "🏥 *GinevApp* - Su código de acceso médico es: *{$codigoOtp}*\n\nEste código expira en 5 minutos.";
            self::enviarWhatsApp($whatsappDestino, $mensaje);

            // Enmascarar número para mostrarlo de forma segura en Flutter
            $waOculto = substr($whatsappDestino, 0, 4) . '****' . substr($whatsappDestino, -3);

            echo json_encode([
                "status"           => "success",
                "mensaje"          => "Código OTP enviado con éxito a su WhatsApp.",
                "whatsapp"         => $whatsappDestino,
                "whatsapp_oculto"  => $waOculto,
                "expira_segundos"  => 300
            ], JSON_UNESCAPED_UNICODE);

        } catch (Exception $e) {
            http_response_code(500);
            echo json_encode(["status" => "error", "mensaje" => $e->getMessage()], JSON_UNESCAPED_UNICODE);
        }
    }

    /**
     * POST /api/auth/verificar-otp
     * Recibe: { "whatsapp": "584121234567", "codigo": "123456" }
     */
    public static function verificarOtp($input) {
        try {
            $whatsapp = trim($input['whatsapp'] ?? '');
            $codigo   = trim($input['codigo'] ?? '');

            if (empty($whatsapp) || empty($codigo)) {
                http_response_code(400);
                echo json_encode(["status" => "error", "mensaje" => "El número de WhatsApp y el código OTP son obligatorios."], JSON_UNESCAPED_UNICODE);
                return;
            }

            $pdo = Database::conectar();
            self::asegurarTablaOtp($pdo);

            // Verificar código y vigencia
            $stmt = $pdo->prepare("SELECT id, expira_en FROM gm_otp_codes WHERE whatsapp = :wa AND codigo = :cod ORDER BY id DESC LIMIT 1");
            $stmt->execute([
                ':wa'  => $whatsapp,
                ':cod' => $codigo
            ]);
            $registroOtp = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$registroOtp) {
                http_response_code(401);
                echo json_encode(["status" => "error", "mensaje" => "El código de acceso es incorrecto."], JSON_UNESCAPED_UNICODE);
                return;
            }

            if (strtotime($registroOtp['expira_en']) < time()) {
                $pdo->prepare("DELETE FROM gm_otp_codes WHERE id = :id")->execute([':id' => $registroOtp['id']]);
                http_response_code(401);
                echo json_encode(["status" => "error", "mensaje" => "El código ha expirado. Solicite uno nuevo."], JSON_UNESCAPED_UNICODE);
                return;
            }

            // Eliminar código usado para evitar reutilización
            $pdo->prepare("DELETE FROM gm_otp_codes WHERE whatsapp = :wa")->execute([':wa' => $whatsapp]);

            // Obtener perfil completo del usuario
            $userStmt = $pdo->prepare("SELECT * FROM gm_usuarios_extendidos WHERE whatsapp = :wa LIMIT 1");
            $userStmt->execute([':wa' => $whatsapp]);
            $usuario = $userStmt->fetch(PDO::FETCH_ASSOC);

            if (!$usuario) {
                http_response_code(404);
                echo json_encode(["status" => "error", "mensaje" => "No se encontraron datos de perfil asociados a este número."], JSON_UNESCAPED_UNICODE);
                return;
            }

            // Generar token de sesión para Flutter
            $token = bin2hex(random_bytes(32));

            echo json_encode([
                "status"  => "success",
                "mensaje" => "Autenticación exitosa.",
                "token"   => $token,
                "usuario" => $usuario
            ], JSON_UNESCAPED_UNICODE);

        } catch (Exception $e) {
            http_response_code(500);
            echo json_encode(["status" => "error", "mensaje" => $e->getMessage()], JSON_UNESCAPED_UNICODE);
        }
    }
}