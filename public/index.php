<?php
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

use App\Controllers\CatalogoController;
use App\Controllers\PacienteController;

$uri = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
$method = $_SERVER['REQUEST_METHOD'];

// Leer cuerpo JSON en peticiones POST/PUT
$input = json_decode(file_get_contents('php://input'), true) ?? $_POST;

// Enrutador principal
if ($uri === '/' || $uri === '/api') {
    echo json_encode([
        "status" => "success",
        "mensaje" => "¡API de GinevApp funcionando correctamente en Easypanel!",
        "endpoints_disponibles" => [
            "GET /api/catalogos",
            "GET /api/pacientes",
            "POST /api/pacientes"
        ]
    ], JSON_UNESCAPED_UNICODE);

} elseif ($uri === '/api/catalogos' && $method === 'GET') {
    CatalogoController::obtenerTodos();

} elseif ($uri === '/api/pacientes' && $method === 'GET') {
    PacienteController::listar();

} elseif ($uri === '/api/pacientes' && $method === 'POST') {
    PacienteController::crear($input);

} else {
    http_response_code(404);
    echo json_encode([
        "status" => "error",
        "mensaje" => "Endpoint no encontrado: $method $uri"
    ], JSON_UNESCAPED_UNICODE);
}