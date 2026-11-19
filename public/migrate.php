<?php
header('Content-Type: application/json; charset=utf-8');
require_once __DIR__ . '/../src/Config/Database.php';

try {
    $pdo = App\Config\Database::conectar();

    $queries = [
        // =========================================================================
        // 1. TABLAS CATÁLOGO (NORMALIZACIÓN)
        // =========================================================================

        // Estatus de Cita
        "CREATE TABLE IF NOT EXISTS `gm_estatus_cita` (
            `id` int(11) NOT NULL AUTO_INCREMENT,
            `nombre` varchar(50) NOT NULL,
            `slug` varchar(50) NOT NULL,
            `color_hex` varchar(20) DEFAULT '#64748b',
            PRIMARY KEY (`id`),
            UNIQUE KEY `slug` (`slug`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;",

        "INSERT IGNORE INTO `gm_estatus_cita` (`nombre`, `slug`, `color_hex`) VALUES
            ('Reservada', 'reservada', '#3b82f6'),
            ('Confirmada', 'confirmada', '#10b981'),
            ('En Sala de Espera', 'en_espera', '#f59e0b'),
            ('En Consulta', 'en_consulta', '#8b5cf6'),
            ('Completada', 'completada', '#16a085'),
            ('Cancelada', 'cancelada', '#ef4444'),
            ('No Asistió', 'no_asistio', '#6b7280');",

        // Estatus de Paciente
        "CREATE TABLE IF NOT EXISTS `gm_estatus_paciente` (
            `id` int(11) NOT NULL AUTO_INCREMENT,
            `nombre` varchar(50) NOT NULL,
            `slug` varchar(50) NOT NULL,
            PRIMARY KEY (`id`),
            UNIQUE KEY `slug` (`slug`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;",

        "INSERT IGNORE INTO `gm_estatus_paciente` (`nombre`, `slug`) VALUES
            ('Activo', 'activo'),
            ('Inactivo', 'inactivo'),
            ('En Seguimiento', 'en_seguimiento'),
            ('Alta Médica', 'alta_medica'),
            ('Suspendido', 'suspendido');",

        // Estatus de Historia Médica
        "CREATE TABLE IF NOT EXISTS `gm_estatus_historia` (
            `id` int(11) NOT NULL AUTO_INCREMENT,
            `nombre` varchar(50) NOT NULL,
            `slug` varchar(50) NOT NULL,
            PRIMARY KEY (`id`),
            UNIQUE KEY `slug` (`slug`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;",

        "INSERT IGNORE INTO `gm_estatus_historia` (`nombre`, `slug`) VALUES
            ('Pendiente', 'pendiente'),
            ('En Proceso', 'en_proceso'),
            ('Pendiente por Exámenes', 'pendiente_examenes'),
            ('Completada', 'completada'),
            ('Anulada', 'anulada');",

        // Tipo de Usuario (Roles)
        "CREATE TABLE IF NOT EXISTS `gm_tipo_usuario` (
            `id` int(11) NOT NULL AUTO_INCREMENT,
            `nombre` varchar(50) NOT NULL,
            `slug` varchar(50) NOT NULL,
            PRIMARY KEY (`id`),
            UNIQUE KEY `slug` (`slug`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;",

        "INSERT IGNORE INTO `gm_tipo_usuario` (`nombre`, `slug`) VALUES
            ('Administrador', 'admin'),
            ('Médico', 'medico'),
            ('Asistente', 'asistente'),
            ('Paciente', 'paciente');",

        // Tipo de Identificación
        "CREATE TABLE IF NOT EXISTS `gm_tipo_identificacion` (
            `id` int(11) NOT NULL AUTO_INCREMENT,
            `codigo` varchar(10) NOT NULL,
            `nombre` varchar(50) NOT NULL,
            PRIMARY KEY (`id`),
            UNIQUE KEY `codigo` (`codigo`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;",

        "INSERT IGNORE INTO `gm_tipo_identificacion` (`codigo`, `nombre`) VALUES
            ('V', 'Venezolano'),
            ('E', 'Extranjero'),
            ('P', 'Pasaporte'),
            ('J', 'Jurídico / RIF'),
            ('G', 'Gubernamental');",

        // Especialidades Médicas
        "CREATE TABLE IF NOT EXISTS `gm_especialidades` (
            `id` int(11) NOT NULL AUTO_INCREMENT,
            `nombre` varchar(100) NOT NULL,
            `slug` varchar(100) NOT NULL,
            PRIMARY KEY (`id`),
            UNIQUE KEY `slug` (`slug`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;",

        "INSERT IGNORE INTO `gm_especialidades` (`nombre`, `slug`) VALUES
            ('Ginecología y Obstetricia', 'ginecologia_obstetricia'),
            ('Medicina General', 'medicina_general'),
            ('Pediatría', 'pediatria'),
            ('Cardiología', 'cardiologia'),
            ('Dermatología', 'dermatologia'),
            ('Endocrinología', 'endocrinologia'),
            ('Medicina Interna', 'medicina_interna'),
            ('Mastología', 'mastologia'),
            ('Urología', 'urologia'),
            ('Nutrición y Dietética', 'nutricion_dietetica');",

        // =========================================================================
        // 2. TABLAS OPERATIVAS DEL SISTEMA MÉDICO
        // =========================================================================

        "CREATE TABLE IF NOT EXISTS `gm_usuarios_extendidos` (
            `id` mediumint(9) NOT NULL AUTO_INCREMENT,
            `user_id` bigint(20) NOT NULL,
            `tipo_identificacion` varchar(50) NOT NULL,
            `cedula` varchar(50) NOT NULL,
            `whatsapp` varchar(30) NOT NULL,
            `nombre` varchar(100) NOT NULL,
            `segundo_nombre` varchar(100) DEFAULT NULL,
            `apellido` varchar(100) NOT NULL,
            `segundo_apellido` varchar(100) DEFAULT NULL,
            `edad` int(3) NOT NULL,
            `sexo` varchar(20) NOT NULL,
            `fecha_nacimiento` date DEFAULT NULL,
            `ocupacion` varchar(150) DEFAULT NULL,
            `peso` decimal(5,2) DEFAULT 0.00,
            `estatura` varchar(20) DEFAULT NULL,
            `pais` varchar(100) DEFAULT NULL,
            `estado` varchar(100) DEFAULT NULL,
            `direccion_detalle` text DEFAULT NULL,
            `especialidad` varchar(150) DEFAULT NULL,
            `universidad` varchar(150) DEFAULT NULL,
            `tagline` text DEFAULT NULL,
            `mpps` varchar(50) DEFAULT NULL,
            `cmm` varchar(50) DEFAULT NULL,
            `redes_sociales` text DEFAULT NULL,
            `email` varchar(100) NOT NULL,
            `rol_medico` varchar(30) NOT NULL,
            PRIMARY KEY (`id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;",

        "CREATE TABLE IF NOT EXISTS `gm_calendarios` (
            `id` mediumint(9) NOT NULL AUTO_INCREMENT,
            `medico_id` bigint(20) NOT NULL,
            `nombre_calendario` varchar(150) NOT NULL DEFAULT 'Calendario Médico',
            `dias_config` longtext NOT NULL,
            `duracion_bloque` int(3) NOT NULL DEFAULT 30,
            `cupos_por_bloque` int(3) NOT NULL DEFAULT 1,
            `appointment_buffer` varchar(30) NOT NULL DEFAULT 'none',
            `cancellation_buffer` varchar(30) NOT NULL DEFAULT '24 hours',
            `prevent_after_date` date DEFAULT NULL,
            `appointment_limit` int(3) NOT NULL DEFAULT 0,
            `default_status` varchar(30) NOT NULL DEFAULT 'reservada',
            `estado` varchar(20) NOT NULL DEFAULT 'activo',
            PRIMARY KEY (`id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;",

        "CREATE TABLE IF NOT EXISTS `gm_citas` (
            `id` mediumint(9) NOT NULL AUTO_INCREMENT,
            `calendario_id` mediumint(9) NOT NULL,
            `paciente_id` bigint(20) NOT NULL,
            `medico_id` bigint(20) NOT NULL,
            `fecha_cita` date NOT NULL,
            `hora_cita` time NOT NULL,
            `procedimiento` varchar(200) DEFAULT 'Consulta General',
            `estado_cita` varchar(30) NOT NULL DEFAULT 'reservada',
            `tipo_cita` varchar(50) DEFAULT 'Hora Programada',
            `numero_llegada` int(5) DEFAULT 0,
            `url_informe_pdf` varchar(255) DEFAULT NULL,
            `fecha_creacion` datetime DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (`id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;",

        "CREATE TABLE IF NOT EXISTS `gm_servicios` (
            `id` mediumint(9) NOT NULL AUTO_INCREMENT,
            `medico_id` bigint(20) NOT NULL,
            `nombre_servicio` varchar(150) NOT NULL,
            `descripcion` text DEFAULT NULL,
            `precio` decimal(10,2) NOT NULL DEFAULT 0.00,
            `tipo` varchar(20) NOT NULL DEFAULT 'servicio',
            `items_combo` text DEFAULT NULL,
            PRIMARY KEY (`id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;",

        "CREATE TABLE IF NOT EXISTS `gm_historias_medicas` (
            `id` mediumint(9) NOT NULL AUTO_INCREMENT,
            `paciente_id` bigint(20) NOT NULL,
            `medico_id` bigint(20) NOT NULL,
            `cita_id` mediumint(9) DEFAULT NULL,
            `motivo_consulta` text DEFAULT NULL,
            `antecedentes_personales` text DEFAULT NULL,
            `habitos_psicobiologicos` text DEFAULT NULL,
            `antecedentes_familiares` text DEFAULT NULL,
            `antecedentes_quirurgicos` text DEFAULT NULL,
            `menarquia` varchar(50) DEFAULT NULL,
            `sexarquia` varchar(50) DEFAULT NULL,
            `ciclos_menstruales` varchar(50) DEFAULT NULL,
            `fur` varchar(50) DEFAULT NULL,
            `activa_sexual` varchar(20) DEFAULT NULL,
            `num_parejas` varchar(20) DEFAULT NULL,
            `gt` varchar(20) DEFAULT NULL,
            `pa` varchar(20) DEFAULT NULL,
            `ab` varchar(20) DEFAULT NULL,
            `cs` varchar(20) DEFAULT NULL,
            `metodos_acos` varchar(150) DEFAULT NULL,
            `papanicolaou` varchar(100) DEFAULT NULL,
            `tension_arterial` varchar(50) DEFAULT NULL,
            `frecuencia_cardiaca` varchar(50) DEFAULT NULL,
            `examen_mamario` text DEFAULT NULL,
            `examen_pelvico` text DEFAULT NULL,
            `diagnostico` text NOT NULL,
            `plan_medico` text DEFAULT NULL,
            `tratamiento` text DEFAULT NULL,
            `notas` text DEFAULT NULL,
            `estado_historia` varchar(50) NOT NULL DEFAULT 'Pendiente',
            `hash_validacion` varchar(64) UNIQUE DEFAULT NULL,
            `pdf_path` varchar(255) DEFAULT NULL,
            `url_informe_pdf` varchar(255) DEFAULT NULL,
            `fecha_registro` datetime DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (`id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;",

        "CREATE TABLE IF NOT EXISTS `gm_historias_examenes` (
            `id` mediumint(9) NOT NULL AUTO_INCREMENT,
            `historia_id` mediumint(9) NOT NULL,
            `tipo_examen` varchar(150) NOT NULL,
            `descripcion_solicitud` text DEFAULT NULL,
            `resultado` text DEFAULT NULL,
            `estado_examen` varchar(50) DEFAULT 'Pendiente',
            `archivo_adjunto_url` varchar(255) DEFAULT NULL,
            `fecha_registro` datetime DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (`id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;",

        "CREATE TABLE IF NOT EXISTS `gm_finanzas` (
            `id` mediumint(9) NOT NULL AUTO_INCREMENT,
            `medico_id` bigint(20) NOT NULL,
            `paciente_id` bigint(20) NOT NULL,
            `historia_id` mediumint(9) DEFAULT NULL,
            `fecha` date NOT NULL,
            `concepto` varchar(255) NOT NULL,
            `debe` decimal(10,2) NOT NULL DEFAULT 0.00,
            `haber` decimal(10,2) NOT NULL DEFAULT 0.00,
            `saldo` decimal(10,2) NOT NULL DEFAULT 0.00,
            `debe_bs` decimal(12,2) NOT NULL DEFAULT 0.00,
            `haber_bs` decimal(12,2) NOT NULL DEFAULT 0.00,
            `saldo_bs` decimal(12,2) NOT NULL DEFAULT 0.00,
            `tasa_bcv` decimal(10,4) NOT NULL DEFAULT 1.0000,
            `facturado` tinyint(1) NOT NULL DEFAULT 0,
            `numero_factura` varchar(50) DEFAULT NULL,
            `url_factura_pdf` varchar(255) DEFAULT NULL,
            PRIMARY KEY (`id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;",

        "CREATE TABLE IF NOT EXISTS `gm_tasa_bcv` (
            `id` mediumint(9) NOT NULL AUTO_INCREMENT,
            `fecha` datetime NOT NULL,
            `tasa_usd` decimal(10,4) NOT NULL,
            PRIMARY KEY (`id`),
            UNIQUE KEY `fecha_hora_idx` (`fecha`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;",

        "CREATE TABLE IF NOT EXISTS `gm_tiempo_atencion` (
            `id` mediumint(9) NOT NULL AUTO_INCREMENT,
            `medico_id` bigint(20) NOT NULL,
            `cita_id` mediumint(9) NOT NULL,
            `hora_inicio` datetime NOT NULL,
            `hora_fin` datetime DEFAULT NULL,
            `duracion_minutos` int(5) DEFAULT 0,
            PRIMARY KEY (`id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;"
    ];

    foreach ($queries as $sql) {
        $pdo->exec($sql);
    }

    echo json_encode([
        "status" => "success",
        "mensaje" => "¡Todas las tablas del sistema médico y los catálogos fueron creados y poblados exitosamente!"
    ], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);

} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode([
        "status" => "error",
        "mensaje" => "Error en la migración: " . $e->getMessage()
    ], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
}