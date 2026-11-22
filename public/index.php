<?php
date_default_timezone_set('America/Caracas');
ini_set('display_errors', '0');
error_reporting(E_ALL);
ob_start();

header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type, Authorization");
header("Content-Type: application/json; charset=utf-8");

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    if (ob_get_length()) {
        ob_clean();
    }
    http_response_code(200);
    exit;
}

set_exception_handler(function ($e) {
    if (ob_get_length()) {
        ob_clean();
    }
    http_response_code(500);
    echo json_encode([
        "status"  => "error",
        "mensaje" => "Error en " . basename($e->getFile()) . " línea " . $e->getLine() . ": " . $e->getMessage()
    ], JSON_UNESCAPED_UNICODE);
    exit;
});

register_shutdown_function(function () {
    $error = error_get_last();
    if ($error !== null && in_array($error['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR], true)) {
        if (ob_get_length()) {
            ob_clean();
        }
        http_response_code(500);
        echo json_encode([
            "status"  => "error",
            "mensaje" => "Error en " . basename($error['file']) . " línea " . $error['line'] . ": " . $error['message']
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }
});

require_once __DIR__ . '/../src/Config/Database.php';
require_once __DIR__ . '/../src/Documentos/InformeMedicoPdfGenerator.php';
require_once __DIR__ . '/../src/Documentos/ValidadorInformePublico.php';
require_once __DIR__ . '/../src/Controllers/CatalogoController.php';
require_once __DIR__ . '/../src/Controllers/PacienteController.php';
require_once __DIR__ . '/../src/Controllers/PacientePerfilController.php';
require_once __DIR__ . '/../src/Controllers/AuthController.php';
require_once __DIR__ . '/../src/Controllers/CalendarioController.php';
require_once __DIR__ . '/../src/Controllers/CitaController.php';
require_once __DIR__ . '/../src/Controllers/HistoriaController.php';
require_once __DIR__ . '/../src/Controllers/FinanzaServicioController.php';
require_once __DIR__ . '/../src/Controllers/AjustesMedicoController.php';

use App\Controllers\CatalogoController;
use App\Controllers\PacienteController;
use App\Controllers\PacientePerfilController;
use App\Controllers\AuthController;
use App\Controllers\CalendarioController;
use App\Controllers\CitaController;
use App\Controllers\HistoriaController;
use App\Controllers\FinanzaServicioController;
use App\Controllers\AjustesMedicoController;
use App\Documentos\ValidadorInformePublico;

$rawUri = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH) ?: '/';
$uri = ($rawUri !== '/' && substr($rawUri, -1) === '/') ? rtrim($rawUri, '/') : $rawUri;
$method = $_SERVER['REQUEST_METHOD'];

$rawBody = file_get_contents('php://input');
$decodedBody = json_decode($rawBody, true);
$input = is_array($decodedBody) ? $decodedBody : $_POST;

if ($uri === '/' || $uri === '/api') {
    echo json_encode([
        "status" => "success",
        "mensaje" => "¡API de GinevApp funcionando correctamente en Easypanel!",
        "endpoints_disponibles" => [
            "POST /api/auth/enviar-otp",
            "POST /api/auth/verificar-otp",
            "POST /api/auth/registro-enviar-otp",
            "POST /api/auth/registro-completar",
            "GET /api/catalogos",
            "GET /api/configuracion/tema",
            "GET /api/marketplace/medicos",
            "GET /api/pacientes",
            "POST /api/pacientes",
            "GET /api/paciente/perfil",
            "POST /api/paciente/perfil",
            "POST /api/paciente/avatar",
            "GET /api/paciente/avatar/ver",
            "GET /api/calendarios",
            "POST /api/calendarios",
            "GET /api/calendarios/disponibilidad",
            "GET /api/citas",
            "POST /api/citas",
            "PUT /api/citas",
            "POST /api/citas/check-in-qr",
            "POST /api/citas/iniciar-atencion",
            "GET /api/historias",
            "GET /api/historias/antecedentes",
            "POST /api/historias",
            "GET /api/historias/pdf",
            "GET /validar-informe",
            "PUT /api/examenes",
            "GET /api/servicios",
            "POST /api/servicios",
            "POST /api/servicios/estado",
            "DELETE /api/servicios",
            "GET /api/tasa-bcv",
            "POST /api/tasa-bcv",
            "GET /api/tasa-bcv/sincronizar",
            "GET /api/finanzas",
            "POST /api/finanzas",
            "GET /api/medico/ajustes",
            "POST /api/medico/perfil",
            "POST /api/medico/calendarios",
            "DELETE /api/medico/calendarios",
            "POST /api/medico/asistentes",
            "DELETE /api/medico/asistentes",
            "POST /api/medico/avatar",
            "GET /api/medico/avatar/ver"
        ]
    ], JSON_UNESCAPED_UNICODE);

} elseif ($uri === '/api/auth/enviar-otp' && $method === 'POST') {
    AuthController::enviarOtp($input);

} elseif ($uri === '/api/auth/verificar-otp' && $method === 'POST') {
    AuthController::verificarOtp($input);

} elseif ($uri === '/api/auth/registro-enviar-otp' && $method === 'POST') {
    AuthController::registroEnviarOtp($input);

} elseif ($uri === '/api/auth/registro-completar' && $method === 'POST') {
    AuthController::registroCompletar($input);

} elseif ($uri === '/api/catalogos' && $method === 'GET') {
    CatalogoController::obtenerTodos();

} elseif ($uri === '/api/configuracion/tema' && $method === 'GET') {
    AuthController::obtenerTema();

} elseif ($uri === '/api/marketplace/medicos' && $method === 'GET') {
    CitaController::listarMarketplace();

} elseif ($uri === '/api/pacientes' && $method === 'GET') {
    if (method_exists(PacienteController::class, 'listarPacientes')) {
        PacienteController::listarPacientes();
    } else {
        PacienteController::listar();
    }

} elseif ($uri === '/api/pacientes' && $method === 'POST') {
    if (method_exists(PacienteController::class, 'crearPaciente')) {
        PacienteController::crearPaciente($input);
    } else {
        PacienteController::crear($input);
    }

} elseif ($uri === '/api/paciente/perfil' && $method === 'GET') {
    PacientePerfilController::obtenerPerfil();

} elseif ($uri === '/api/paciente/perfil' && $method === 'POST') {
    PacientePerfilController::guardarPerfil($input);

} elseif ($uri === '/api/paciente/avatar' && $method === 'POST') {
    PacientePerfilController::subirAvatar();

} elseif ($uri === '/api/paciente/avatar/ver' && $method === 'GET') {
    PacientePerfilController::verAvatar();

} elseif ($uri === '/api/calendarios' && $method === 'GET') {
    CalendarioController::listar();

} elseif ($uri === '/api/calendarios' && $method === 'POST') {
    CalendarioController::guardar($input);

} elseif ($uri === '/api/calendarios/disponibilidad' && $method === 'GET') {
    CalendarioController::disponibilidad();

} elseif ($uri === '/api/calendarios/disponibilidad-mes' && $method === 'GET') {
    CalendarioController::disponibilidadMes();

} elseif ($uri === '/api/citas' && $method === 'GET') {
    CitaController::listar();

} elseif ($uri === '/api/citas' && $method === 'POST') {
    CitaController::crear($input);

} elseif ($uri === '/api/citas' && $method === 'PUT') {
    CitaController::actualizar($input);

} elseif ($uri === '/api/citas/check-in-qr' && $method === 'POST') {
    CitaController::checkInQr($input);

} elseif ($uri === '/api/citas/iniciar-atencion' && $method === 'POST') {
    CitaController::iniciarAtencion($input);

} elseif ($uri === '/api/historias' && $method === 'GET') {
    HistoriaController::listar();

} elseif ($uri === '/api/historias/antecedentes' && $method === 'GET') {
    HistoriaController::obtenerUltimosAntecedentes();

} elseif ($uri === '/api/historias' && $method === 'POST') {
    HistoriaController::crear($input);

} elseif ($uri === '/api/examenes' && $method === 'PUT') {
    HistoriaController::actualizarExamen($input);

} elseif ($uri === '/api/historias/pdf' && $method === 'GET') {
    HistoriaController::imprimirPdf();

} elseif (($uri === '/validar-informe' || $uri === '/api/validar-informe') && $method === 'GET') {
    ValidadorInformePublico::renderizar();

} elseif ($uri === '/api/servicios' && $method === 'GET') {
    FinanzaServicioController::listarServicios();

} elseif ($uri === '/api/servicios' && $method === 'POST') {
    FinanzaServicioController::crearServicio($input);

} elseif ($uri === '/api/servicios/estado' && $method === 'POST') {
    FinanzaServicioController::cambiarEstadoServicio($input);

} elseif ($uri === '/api/servicios' && $method === 'DELETE') {
    FinanzaServicioController::eliminarServicio();

} elseif ($uri === '/api/tasa-bcv' && $method === 'GET') {
    FinanzaServicioController::obtenerTasaBcv();

} elseif ($uri === '/api/tasa-bcv' && $method === 'POST') {
    FinanzaServicioController::guardarTasaBcv($input);

} elseif ($uri === '/api/tasa-bcv/sincronizar' && $method === 'GET') {
    FinanzaServicioController::sincronizarTasaBcv();

} elseif ($uri === '/api/finanzas' && $method === 'GET') {
    FinanzaServicioController::listarFinanzas();

} elseif ($uri === '/api/finanzas' && $method === 'POST') {
    FinanzaServicioController::registrarMovimiento($input);

} elseif ($uri === '/api/medico/ajustes' && $method === 'GET') {
    AjustesMedicoController::obtenerTodo();

} elseif ($uri === '/api/medico/perfil' && $method === 'POST') {
    AjustesMedicoController::guardarPerfil();

} elseif ($uri === '/api/medico/calendarios' && $method === 'POST') {
    AjustesMedicoController::guardarCalendario();

} elseif ($uri === '/api/medico/calendarios' && $method === 'DELETE') {
    AjustesMedicoController::eliminarCalendario();

} elseif ($uri === '/api/medico/asistentes' && $method === 'POST') {
    AjustesMedicoController::crearAsistente();

} elseif ($uri === '/api/medico/asistentes' && $method === 'DELETE') {
    AjustesMedicoController::eliminarAsistente();

} elseif ($uri === '/api/medico/avatar' && $method === 'POST') {
    AjustesMedicoController::subirAvatar();

} elseif ($uri === '/api/medico/avatar/ver' && $method === 'GET') {
    if (ob_get_length()) {
        ob_clean();
    }
    $archivo = basename($_GET['file'] ?? '');
    $ruta = __DIR__ . '/uploads/avatars/' . $archivo;
    if ($archivo !== '' && file_exists($ruta)) {
        $info = @getimagesize($ruta);
        header('Content-Type: ' . ($info['mime'] ?? 'image/jpeg'));
        header('Cache-Control: public, max-age=86400');
        readfile($ruta);
        exit;
    }
    http_response_code(404);
    exit;

} else {
    http_response_code(404);
    echo json_encode([
        "status" => "error",
        "mensaje" => "Endpoint no encontrado: $method $uri"
    ], JSON_UNESCAPED_UNICODE);
}