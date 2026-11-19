<?php
date_default_timezone_set('America/Caracas');
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type, Authorization");
header("Content-Type: application/json; charset=utf-8");

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

require_once __DIR__ . '/../src/Config/Database.php';
require_once __DIR__ . '/../src/Controllers/CatalogoController.php';
require_once __DIR__ . '/../src/Controllers/PacienteController.php';
require_once __DIR__ . '/../src/Controllers/AuthController.php';
require_once __DIR__ . '/../src/Controllers/CalendarioController.php';
require_once __DIR__ . '/../src/Controllers/CitaController.php';
require_once __DIR__ . '/../src/Controllers/HistoriaController.php';
require_once __DIR__ . '/../src/Controllers/FinanzaServicioController.php';
require_once __DIR__ . '/../src/Controllers/AjustesMedicoController.php';

use App\Controllers\CatalogoController;
use App\Controllers\PacienteController;
use App\Controllers\AuthController;
use App\Controllers\CalendarioController;
use App\Controllers\CitaController;
use App\Controllers\HistoriaController;
use App\Controllers\FinanzaServicioController;
use App\Controllers\AjustesMedicoController;

$uri = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
$method = $_SERVER['REQUEST_METHOD'];

$input = json_decode(file_get_contents('php://input'), true) ?? $_POST;

// Enrutador principal
if ($uri === '/' || $uri === '/api') {
    echo json_encode([
        "status" => "success",
        "mensaje" => "¡API de GinevApp funcionando correctamente en Easypanel!",
        "endpoints_disponibles" => [
            "POST /api/auth/enviar-otp",
            "POST /api/auth/verificar-otp",
            "GET /api/catalogos",
            "GET /api/pacientes",
            "POST /api/pacientes",
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
            "PUT /api/examenes",
            "GET /api/servicios",
            "POST /api/servicios",
            "GET /api/tasa-bcv",
            "POST /api/tasa-bcv",
            "GET /api/finanzas",
            "POST /api/finanzas",
            "GET /api/medico/ajustes",
            "POST /api/medico/perfil",
            "POST /api/medico/calendarios",
            "DELETE /api/medico/calendarios",
            "POST /api/medico/asistentes",
            "DELETE /api/medico/asistentes"
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

} elseif ($uri === '/api/pacientes' && $method === 'GET') {
    PacienteController::listar();

} elseif ($uri === '/api/pacientes' && $method === 'POST') {
    PacienteController::crear($input);

} elseif ($uri === '/api/calendarios' && $method === 'GET') {
    CalendarioController::listar();

} elseif ($uri === '/api/calendarios' && $method === 'POST') {
    CalendarioController::guardar($input);

} elseif ($uri === '/api/calendarios/disponibilidad' && $method === 'GET') {
    CalendarioController::disponibilidad();

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

} elseif ($uri === '/api/servicios' && $method === 'GET') {
    FinanzaServicioController::listarServicios();

} elseif ($uri === '/api/servicios' && $method === 'POST') {
    FinanzaServicioController::crearServicio($input);

} elseif ($uri === '/api/tasa-bcv' && $method === 'GET') {
    FinanzaServicioController::obtenerTasaBcv();

} elseif ($uri === '/api/tasa-bcv' && $method === 'POST') {
    FinanzaServicioController::guardarTasaBcv($input);

} elseif ($uri === '/api/finanzas' && $method === 'GET') {
    FinanzaServicioController::listarFinanzas();

} elseif ($uri === '/api/finanzas' && $method === 'POST') {
    FinanzaServicioController::registrarMovimiento($input);

} elseif ($uri === '/api/configuracion/tema' && $method === 'GET') {
    AuthController::obtenerTema();

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

} elseif ($uri === '/api/tasa-bcv/sincronizar' && $method === 'GET') {
    FinanzaServicioController::sincronizarTasaBcv();
    
} elseif ($uri === '/api/medico/avatar' && $method === 'POST') {
    AjustesMedicoController::subirAvatar();

    } elseif ($uri === '/api/medico/avatar/ver' && $method === 'GET') {
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