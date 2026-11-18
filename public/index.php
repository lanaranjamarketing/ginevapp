<?php
// Permitir peticiones desde tu app de Flutter (CORS)
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type, Authorization");

if ($_SERVER['REQUEST_METHOD'] == 'OPTIONS') {
    exit(0);
}

header('Content-Type: application/json');

// Obtener la ruta solicitada
$uri = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);

// Ruta de prueba inicial
if ($uri === '/api/estado' || $uri === '/') {
    echo json_encode([
        "status" => "success",
        "mensaje" => "¡API de GinevApp funcionando correctamente en Easypanel!"
    ]);
    exit();
}

// Si la ruta no existe
http_response_code(404);
echo json_encode(["error" => "Ruta no encontrada"]);