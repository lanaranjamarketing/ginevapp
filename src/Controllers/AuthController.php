<?php
namespace App\Controllers;

use App\Config\Database;
use PDO;
use Exception;

class AuthController {

    /**
     * Asegura que existan las tablas temporales y de configuración en MySQL
     */
    private static function asegurarTablas($pdo) {
        $pdo->exec("CREATE TABLE IF NOT EXISTS `gm_otp_codes` (
            `id` int(11) NOT NULL AUTO_INCREMENT,
            `whatsapp` varchar(30) NOT NULL,
            `codigo` varchar(10) NOT NULL,
            `expira_en` datetime NOT NULL,
            PRIMARY KEY (`id`),
            KEY `whatsapp_idx` (`whatsapp`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;");

        $pdo->exec("CREATE TABLE IF NOT EXISTS `gm_configuracion` (
            `id` int(11) NOT NULL AUTO_INCREMENT,
            `clave` varchar(100) NOT NULL UNIQUE,
            `valor` text NOT NULL,
            `descripcion` varchar(255) DEFAULT NULL,
            `actualizado_en` datetime DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (`id`),
            UNIQUE KEY `clave_idx` (`clave`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;");

        // Sembrar credenciales iniciales de Evolution API si aún no existen en BD
        $stmtCheck = $pdo->query("SELECT COUNT(*) FROM `gm_configuracion` WHERE `clave` = 'evolution_url'");
        if ((int)$stmtCheck->fetchColumn() === 0) {
            $ins = $pdo->prepare("INSERT IGNORE INTO `gm_configuracion` (`clave`, `valor`, `descripcion`) VALUES (:c, :v, :d)");
            $defaults = [
                ['evolution_url', 'https://auto-evolution-api.tidwyf.easypanel.host', 'Server URL (Evolution API)'],
                ['evolution_instance', 'la naranja', 'Instancia de Evolution API'],
                ['evolution_apikey', '429683C4C977415CAAFCCE10F7D57E11', 'API Key de Evolution API'],
                ['whatsapp_plantilla_otp', 'Su código de acceso es: {codigo}', 'Plantilla del Mensaje OTP']
            ];
            foreach ($defaults as $row) {
                $ins->execute([':c' => $row[0], ':v' => $row[1], ':d' => $row[2]]);
            }
        }
    }

    /**
     * Obtiene la configuración activa de Evolution API desde la base de datos
     */
    private static function obtenerConfigEvolution($pdo) {
        self::asegurarTablas($pdo);
        $stmt = $pdo->query("SELECT `clave`, `valor` FROM `gm_configuracion` WHERE `clave` IN ('evolution_url', 'evolution_instance', 'evolution_apikey', 'whatsapp_plantilla_otp')");
        $config = [];
        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $config[$row['clave']] = $row['valor'];
        }
        return $config;
    }

    /**
     * Enviar mensaje de texto mediante Evolution API leyendo los parámetros desde MySQL
     */
    private static function enviarWhatsApp($pdo, $whatsapp, $mensaje) {
        $config    = self::obtenerConfigEvolution($pdo);
        $serverUrl = trim($config['evolution_url'] ?? getenv('EVOLUTION_URL') ?: '');
        $instance  = trim($config['evolution_instance'] ?? getenv('EVOLUTION_INSTANCE') ?: '');
        $apiKey    = trim($config['evolution_apikey'] ?? getenv('EVOLUTION_APIKEY') ?: '');

        if (empty($serverUrl) || empty($instance) || empty($apiKey)) {
            throw new Exception("Los parámetros de Evolution API no están configurados en la base de datos.");
        }

        // rawurlencode asegura que instancias con espacios (ej: "la naranja") funcionen correctamente en la URL
        $endpoint = rtrim($serverUrl, '/') . '/message/sendText/' . rawurlencode($instance);

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
            $config = self::obtenerConfigEvolution($pdo);

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

            $pdo->prepare("DELETE FROM gm_otp_codes WHERE whatsapp = :wa")->execute([':wa' => $whatsappDestino]);
            $ins = $pdo->prepare("INSERT INTO gm_otp_codes (whatsapp, codigo, expira_en) VALUES (:wa, :cod, :exp)");
            $ins->execute([
                ':wa'  => $whatsappDestino,
                ':cod' => $codigoOtp,
                ':exp' => $expiraEn
            ]);

            $plantilla = $config['whatsapp_plantilla_otp'] ?? 'Su código de acceso es: {codigo}';
            $mensaje   = str_replace('{codigo}', $codigoOtp, $plantilla);

            self::enviarWhatsApp($pdo, $whatsappDestino, $mensaje);

            $waOculto = substr($whatsappDestino, 0, 4) . '****' . substr($whatsappDestino, -3);

            echo json_encode([
                "status"          => "success",
                "mensaje"         => "Código OTP enviado con éxito a su WhatsApp.",
                "whatsapp"        => $whatsappDestino,
                "whatsapp_oculto" => $waOculto,
                "expira_segundos" => 300
            ], JSON_UNESCAPED_UNICODE);

        } catch (Exception $e) {
            http_response_code(500);
            echo json_encode(["status" => "error", "mensaje" => $e->getMessage()], JSON_UNESCAPED_UNICODE);
        }
    }

    /**
     * POST /api/auth/verificar-otp
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
            self::asegurarTablas($pdo);

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

            $pdo->prepare("DELETE FROM gm_otp_codes WHERE whatsapp = :wa")->execute([':wa' => $whatsapp]);

            $userStmt = $pdo->prepare("SELECT * FROM gm_usuarios_extendidos WHERE whatsapp = :wa LIMIT 1");
            $userStmt->execute([':wa' => $whatsapp]);
            $usuario = $userStmt->fetch(PDO::FETCH_ASSOC);

            if (!$usuario) {
                http_response_code(404);
                echo json_encode(["status" => "error", "mensaje" => "No se encontraron datos de perfil asociados a este número."], JSON_UNESCAPED_UNICODE);
                return;
            }

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

    /**
     * POST /api/auth/registro-enviar-otp
     */
    public static function registroEnviarOtp($input) {
        try {
            $cedula   = trim($input['cedula'] ?? '');
            $whatsapp = preg_replace('/[^0-9]/', '', trim($input['whatsapp'] ?? ''));

            if (empty($cedula) || empty($whatsapp)) {
                http_response_code(400);
                echo json_encode(["status" => "error", "mensaje" => "El número de identificación y el WhatsApp son obligatorios."], JSON_UNESCAPED_UNICODE);
                return;
            }

            $pdo = Database::conectar();
            $config = self::obtenerConfigEvolution($pdo);

            $check = $pdo->prepare("SELECT id FROM gm_usuarios_extendidos WHERE cedula = :ced OR whatsapp = :wa LIMIT 1");
            $check->execute([':ced' => $cedula, ':wa' => $whatsapp]);
            if ($check->fetch()) {
                http_response_code(409);
                echo json_encode(["status" => "error", "mensaje" => "El número de identificación o el WhatsApp ya se encuentran registrados en el sistema."], JSON_UNESCAPED_UNICODE);
                return;
            }

            $codigoOtp = str_pad((string)random_int(100000, 999999), 6, '0', STR_PAD_LEFT);
            $expiraEn  = date('Y-m-d H:i:s', strtotime('+5 minutes'));

            $pdo->prepare("DELETE FROM gm_otp_codes WHERE whatsapp = :wa")->execute([':wa' => $whatsapp]);
            $ins = $pdo->prepare("INSERT INTO gm_otp_codes (whatsapp, codigo, expira_en) VALUES (:wa, :cod, :exp)");
            $ins->execute([':wa' => $whatsapp, ':cod' => $codigoOtp, ':exp' => $expiraEn]);

            $plantilla = $config['whatsapp_plantilla_otp'] ?? 'Su código de acceso es: {codigo}';
            $mensaje   = str_replace('{codigo}', $codigoOtp, $plantilla);

            self::enviarWhatsApp($pdo, $whatsapp, $mensaje);

            echo json_encode([
                "status"   => "success",
                "mensaje"  => "Por favor, revise su WhatsApp. Le hemos enviado un código de confirmación.",
                "whatsapp" => $whatsapp
            ], JSON_UNESCAPED_UNICODE);

        } catch (Exception $e) {
            http_response_code(500);
            echo json_encode(["status" => "error", "mensaje" => $e->getMessage()], JSON_UNESCAPED_UNICODE);
        }
    }

    /**
     * POST /api/auth/registro-completar
     */
    public static function registroCompletar($input) {
        try {
            $whatsapp = preg_replace('/[^0-9]/', '', trim($input['whatsapp'] ?? ''));
            $codigo   = trim($input['codigo'] ?? '');

            if (empty($whatsapp) || empty($codigo)) {
                http_response_code(400);
                echo json_encode(["status" => "error", "mensaje" => "El WhatsApp y el código OTP son obligatorios."], JSON_UNESCAPED_UNICODE);
                return;
            }

            $pdo = Database::conectar();
            self::asegurarTablas($pdo);

            $stmt = $pdo->prepare("SELECT id, expira_en FROM gm_otp_codes WHERE whatsapp = :wa AND codigo = :cod ORDER BY id DESC LIMIT 1");
            $stmt->execute([':wa' => $whatsapp, ':cod' => $codigo]);
            $regOtp = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$regOtp || strtotime($regOtp['expira_en']) < time()) {
                http_response_code(401);
                echo json_encode(["status" => "error", "mensaje" => "El código de confirmación es incorrecto o ha expirado."], JSON_UNESCAPED_UNICODE);
                return;
            }

            $pdo->prepare("DELETE FROM gm_otp_codes WHERE whatsapp = :wa")->execute([':wa' => $whatsapp]);

            $fNac = trim($input['fecha_nacimiento'] ?? '');
            $edad = (int)($input['edad'] ?? 0);
            if (!empty($fNac)) {
                $nac = new \DateTime($fNac);
                $edad = (new \DateTime())->diff($nac)->y;
            }

            $maxUid = (int)$pdo->query("SELECT COALESCE(MAX(user_id), 1000) FROM gm_usuarios_extendidos")->fetchColumn();
            $nuevoUserId = $maxUid + 1;

            $sql = "INSERT INTO gm_usuarios_extendidos 
                    (user_id, tipo_identificacion, cedula, whatsapp, nombre, segundo_nombre, apellido, segundo_apellido, edad, sexo, fecha_nacimiento, email, rol_medico) 
                    VALUES 
                    (:uid, :tipo_id, :ced, :wa, :nom, :snom, :ape, :sape, :edad, :sexo, :fnac, :email, 'gm_paciente')";

            $pdo->prepare($sql)->execute([
                ':uid'     => $nuevoUserId,
                ':tipo_id' => trim($input['tipo_identificacion'] ?? 'V'),
                ':ced'     => trim($input['cedula'] ?? ''),
                ':wa'      => $whatsapp,
                ':nom'     => trim($input['nombre'] ?? ''),
                ':snom'    => !empty($input['segundo_nombre']) ? trim($input['segundo_nombre']) : null,
                ':ape'     => trim($input['apellido'] ?? ''),
                ':sape'    => !empty($input['segundo_apellido']) ? trim($input['segundo_apellido']) : null,
                ':edad'    => $edad,
                ':sexo'    => trim($input['sexo'] ?? 'Femenino'),
                ':fnac'    => !empty($fNac) ? $fNac : null,
                ':email'   => trim($input['email'] ?? '')
            ]);

            $nuevoId = $pdo->lastInsertId();
            $userStmt = $pdo->prepare("SELECT * FROM gm_usuarios_extendidos WHERE id = :id LIMIT 1");
            $userStmt->execute([':id' => $nuevoId]);
            $usuario = $userStmt->fetch(PDO::FETCH_ASSOC);

            http_response_code(201);
            echo json_encode([
                "status"  => "success",
                "mensaje" => "¡Registro completado exitosamente!",
                "token"   => bin2hex(random_bytes(32)),
                "usuario" => $usuario
            ], JSON_UNESCAPED_UNICODE);

        } catch (Exception $e) {
            http_response_code(500);
            echo json_encode(["status" => "error", "mensaje" => $e->getMessage()], JSON_UNESCAPED_UNICODE);
        }
    }
public static function obtenerTema() {
    try {
        $pdo = Database::conectar();
        self::asegurarTablas($pdo);
        $stmt = $pdo->query("SELECT `clave`, `valor` FROM `gm_configuracion` WHERE `clave` LIKE 'theme_%'");
        $tema = [];
        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $tema[$row['clave']] = $row['valor'];
        }
        echo json_encode(["status" => "success", "tema" => $tema], JSON_UNESCAPED_UNICODE);
    } catch (Exception $e) {
        http_response_code(500);
        echo json_encode(["status" => "error", "mensaje" => $e->getMessage()], JSON_UNESCAPED_UNICODE);
    }
}
}