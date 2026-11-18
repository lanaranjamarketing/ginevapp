<?php
namespace App\Config;

use PDO;
use PDOException;

class Database {
    public static function conectar() {
        $host = getenv('DB_HOST') ?: 'localhost';
        $db   = getenv('DB_NAME') ?: 'tu_base_de_datos';
        $user = getenv('DB_USER') ?: 'tu_usuario';
        $pass = getenv('DB_PASS') ?: 'tu_contrasena';
        
        try {
            $pdo = new PDO("mysql:host=$host;dbname=$db;charset=utf8mb4", $user, $pass);
            $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
            return $pdo;
        } catch (PDOException $e) {
            echo json_encode(["error" => "Error de conexión: " . $e->getMessage()]);
            exit();
        }
    }
}