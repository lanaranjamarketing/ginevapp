<?php
header('Content-Type: application/json; charset=utf-8');
require_once __DIR__ . '/../src/Config/Database.php';

try {
    $pdo = App\Config\Database::conectar();

    $queries = [
        // =========================================================================
        // 1. TABLAS CATÁLOGO (NORMALIZACIÓN)
        // =========================================================================

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

        "CREATE TABLE IF NOT EXISTS `gm_medico_meta` (
            `id` int(11) NOT NULL AUTO_INCREMENT,
            `medico_id` int(11) NOT NULL,
            `meta_key` varchar(100) NOT NULL,
            `meta_value` longtext DEFAULT NULL,
            `updated_at` timestamp DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (`id`),
            UNIQUE KEY `idx_medico_key` (`medico_id`, `meta_key`)
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
            `total_usd` decimal(10,2) NOT NULL DEFAULT 0.00,
            `es_financiado` tinyint(1) NOT NULL DEFAULT 0,
            `monto_financiado_usd` decimal(10,2) NOT NULL DEFAULT 0.00,
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
            `permite_financiamiento` tinyint(1) NOT NULL DEFAULT 0,
            `cantidad_cuotas` int(3) NOT NULL DEFAULT 1,
            `frecuencia_cuotas` varchar(20) NOT NULL DEFAULT 'semanal',
            `tipo` varchar(20) NOT NULL DEFAULT 'servicio',
            `items_combo` text DEFAULT NULL,
            `estado` varchar(20) DEFAULT 'activo',
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
            `historia_id` mediumint(9) DEFAULT NULL,
            `paciente_id` bigint(20) DEFAULT NULL,
            `medico_id` bigint(20) DEFAULT NULL,
            `tipo_examen` varchar(150) NOT NULL,
            `responsable_carga` varchar(30) NOT NULL DEFAULT 'medico',
            `descripcion_solicitud` text DEFAULT NULL,
            `observaciones` text DEFAULT NULL,
            `observaciones_dr` text DEFAULT NULL,
            `resultado` text DEFAULT NULL,
            `estado_examen` varchar(50) DEFAULT 'Pendiente',
            `archivo_adjunto_url` text DEFAULT NULL,
            `fecha_examen` date DEFAULT NULL,
            `fecha_registro` datetime DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (`id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;",

        "CREATE TABLE IF NOT EXISTS `gm_finanzas` (
            `id` mediumint(9) NOT NULL AUTO_INCREMENT,
            `medico_id` bigint(20) NOT NULL,
            `paciente_id` bigint(20) NOT NULL,
            `historia_id` mediumint(9) DEFAULT NULL,
            `cita_id` mediumint(9) DEFAULT NULL,
            `servicio_id` mediumint(9) DEFAULT NULL,
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
            `url_factura_pdf` varchar(500) DEFAULT NULL,
            PRIMARY KEY (`id`),
            INDEX `idx_finanzas_medico_factura` (`medico_id`, `numero_factura`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;",

        "CREATE TABLE IF NOT EXISTS `gm_cuentas_por_cobrar` (
            `id` mediumint(9) NOT NULL AUTO_INCREMENT,
            `medico_id` bigint(20) NOT NULL DEFAULT 0,
            `paciente_id` bigint(20) NOT NULL DEFAULT 0,
            `cita_id` mediumint(9) DEFAULT NULL,
            `servicio_id` mediumint(9) DEFAULT NULL,
            `finanza_id` mediumint(9) DEFAULT NULL,
            `fecha` date DEFAULT NULL,
            `fecha_credito` date DEFAULT NULL,
            `descripcion` varchar(255) NOT NULL DEFAULT '',
            `concepto` varchar(255) DEFAULT NULL,
            `observaciones` text DEFAULT NULL,
            `fecha_vencimiento` date DEFAULT NULL,
            `cuotas_semanales` int(3) NOT NULL DEFAULT 1,
            `cantidad_cuotas` int(3) NOT NULL DEFAULT 1,
            `cuotas_pagadas` int(3) NOT NULL DEFAULT 0,
            `frecuencia_cuotas` varchar(20) NOT NULL DEFAULT 'semanal',
            `monto_cuota` decimal(10,2) NOT NULL DEFAULT 0.00,
            `monto_cuota_usd` decimal(10,2) NOT NULL DEFAULT 0.00,
            `monto_cuota_bs` decimal(12,2) NOT NULL DEFAULT 0.00,
            `monto_total_usd` decimal(10,2) NOT NULL DEFAULT 0.00,
            `monto_total_bs` decimal(12,2) NOT NULL DEFAULT 0.00,
            `saldo_pendiente_usd` decimal(10,2) NOT NULL DEFAULT 0.00,
            `saldo_pendiente_bs` decimal(12,2) NOT NULL DEFAULT 0.00,
            `debe` decimal(10,2) NOT NULL DEFAULT 0.00,
            `haber` decimal(10,2) NOT NULL DEFAULT 0.00,
            `saldo` decimal(10,2) NOT NULL DEFAULT 0.00,
            `debe_usd` decimal(10,2) NOT NULL DEFAULT 0.00,
            `haber_usd` decimal(10,2) NOT NULL DEFAULT 0.00,
            `saldo_usd` decimal(10,2) NOT NULL DEFAULT 0.00,
            `debe_bs` decimal(12,2) NOT NULL DEFAULT 0.00,
            `haber_bs` decimal(12,2) NOT NULL DEFAULT 0.00,
            `saldo_bs` decimal(12,2) NOT NULL DEFAULT 0.00,
            `tasa_bcv` decimal(10,4) NOT NULL DEFAULT 1.0000,
            `estado` varchar(20) NOT NULL DEFAULT 'pendiente',
            `estatus` varchar(20) NOT NULL DEFAULT 'pendiente',
            `url_pdf` varchar(500) DEFAULT NULL,
            `fecha_creacion` datetime DEFAULT CURRENT_TIMESTAMP,
            `updated_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (`id`),
            INDEX `idx_cxc_medico_fecha` (`medico_id`, `fecha`),
            INDEX `idx_cxc_paciente` (`paciente_id`),
            INDEX `idx_cxc_cita` (`cita_id`),
            INDEX `idx_cxc_vencimiento` (`fecha_vencimiento`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;",

        "CREATE TABLE IF NOT EXISTS `gm_presupuestos` (
            `id` int(11) NOT NULL AUTO_INCREMENT,
            `numero_presupuesto` varchar(32) DEFAULT NULL,
            `medico_id` int(11) NOT NULL,
            `paciente_id` int(11) NOT NULL DEFAULT 0,
            `paciente_nombre` varchar(200) NOT NULL DEFAULT '',
            `paciente_cedula` varchar(50) NOT NULL DEFAULT '',
            `paciente_telefono` varchar(50) DEFAULT NULL,
            `paciente_direccion` varchar(300) DEFAULT NULL,
            `fecha_emision` date NOT NULL,
            `fecha_vencimiento` date NOT NULL,
            `items_json` longtext NOT NULL,
            `total_usd` decimal(12,2) NOT NULL DEFAULT 0.00,
            `total_bs` decimal(14,2) NOT NULL DEFAULT 0.00,
            `tasa_bcv` decimal(12,4) NOT NULL DEFAULT 1.0000,
            `observaciones` text DEFAULT NULL,
            `estatus` varchar(20) NOT NULL DEFAULT 'pendiente',
            `finanza_id` int(11) DEFAULT NULL,
            `url_pdf` varchar(500) DEFAULT NULL,
            `created_at` timestamp DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (`id`),
            INDEX `idx_medico_fecha` (`medico_id`, `fecha_emision`),
            INDEX `idx_medico_presupuesto` (`medico_id`, `numero_presupuesto`),
            INDEX `idx_vencimiento` (`fecha_vencimiento`)
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
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;",

        // =========================================================================
        // 3. TABLA DE CONFIGURACIÓN GLOBAL Y TOKENS DINÁMICOS DE TEMA
        // =========================================================================
        "CREATE TABLE IF NOT EXISTS `gm_configuracion` (
            `id` int(11) NOT NULL AUTO_INCREMENT,
            `clave` varchar(100) NOT NULL UNIQUE,
            `valor` text NOT NULL,
            `descripcion` varchar(255) DEFAULT NULL,
            `actualizado_en` datetime DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (`id`),
            UNIQUE KEY `clave_idx` (`clave`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;"
    ];

    foreach ($queries as $sql) {
        $pdo->exec($sql);
    }

    // Permitir NULL por defecto en fecha y fecha_vencimiento en gm_cuentas_por_cobrar
    $pdo->exec("ALTER TABLE `gm_cuentas_por_cobrar` MODIFY COLUMN `fecha` date DEFAULT NULL");
    $pdo->exec("ALTER TABLE `gm_cuentas_por_cobrar` MODIFY COLUMN `fecha_vencimiento` date DEFAULT NULL");
    $pdo->exec("ALTER TABLE `gm_cuentas_por_cobrar` MODIFY COLUMN `descripcion` varchar(255) NOT NULL DEFAULT ''");

    // Asegurar columnas de financiamiento en gm_servicios si ya existía previamente
    $colsServicios = [
        'estado'                 => "ALTER TABLE `gm_servicios` ADD COLUMN `estado` varchar(20) DEFAULT 'activo'",
        'permite_financiamiento' => "ALTER TABLE `gm_servicios` ADD COLUMN `permite_financiamiento` tinyint(1) NOT NULL DEFAULT 0 AFTER `precio`",
        'cantidad_cuotas'        => "ALTER TABLE `gm_servicios` ADD COLUMN `cantidad_cuotas` int(3) NOT NULL DEFAULT 1 AFTER `permite_financiamiento`",
        'frecuencia_cuotas'      => "ALTER TABLE `gm_servicios` ADD COLUMN `frecuencia_cuotas` varchar(20) NOT NULL DEFAULT 'semanal' AFTER `cantidad_cuotas`",
    ];
    foreach ($colsServicios as $col => $alterSql) {
        $chk = $pdo->query("SHOW COLUMNS FROM `gm_servicios` LIKE '$col'");
        if ($chk && !$chk->fetch()) {
            $pdo->exec($alterSql);
        }
    }

    // Asegurar TODAS las columnas posibles en gm_cuentas_por_cobrar (incluyendo cita_id y servicio_id)
    $colsCxc = [
        'cita_id'             => "ALTER TABLE `gm_cuentas_por_cobrar` ADD COLUMN `cita_id` mediumint(9) DEFAULT NULL AFTER `paciente_id`",
        'servicio_id'         => "ALTER TABLE `gm_cuentas_por_cobrar` ADD COLUMN `servicio_id` mediumint(9) DEFAULT NULL AFTER `cita_id`",
        'finanza_id'          => "ALTER TABLE `gm_cuentas_por_cobrar` ADD COLUMN `finanza_id` mediumint(9) DEFAULT NULL AFTER `servicio_id`",
        'fecha_credito'       => "ALTER TABLE `gm_cuentas_por_cobrar` ADD COLUMN `fecha_credito` date DEFAULT NULL AFTER `fecha`",
        'concepto'            => "ALTER TABLE `gm_cuentas_por_cobrar` ADD COLUMN `concepto` varchar(255) DEFAULT NULL AFTER `descripcion`",
        'observaciones'       => "ALTER TABLE `gm_cuentas_por_cobrar` ADD COLUMN `observaciones` text DEFAULT NULL AFTER `concepto`",
        'cuotas_semanales'    => "ALTER TABLE `gm_cuentas_por_cobrar` ADD COLUMN `cuotas_semanales` int(3) NOT NULL DEFAULT 1 AFTER `fecha_vencimiento`",
        'cantidad_cuotas'     => "ALTER TABLE `gm_cuentas_por_cobrar` ADD COLUMN `cantidad_cuotas` int(3) NOT NULL DEFAULT 1 AFTER `cuotas_semanales`",
        'cuotas_pagadas'      => "ALTER TABLE `gm_cuentas_por_cobrar` ADD COLUMN `cuotas_pagadas` int(3) NOT NULL DEFAULT 0 AFTER `cantidad_cuotas`",
        'frecuencia_cuotas'   => "ALTER TABLE `gm_cuentas_por_cobrar` ADD COLUMN `frecuencia_cuotas` varchar(20) NOT NULL DEFAULT 'semanal' AFTER `cuotas_pagadas`",
        'monto_cuota'         => "ALTER TABLE `gm_cuentas_por_cobrar` ADD COLUMN `monto_cuota` decimal(10,2) NOT NULL DEFAULT 0.00 AFTER `frecuencia_cuotas`",
        'monto_cuota_usd'     => "ALTER TABLE `gm_cuentas_por_cobrar` ADD COLUMN `monto_cuota_usd` decimal(10,2) NOT NULL DEFAULT 0.00 AFTER `monto_cuota`",
        'monto_cuota_bs'      => "ALTER TABLE `gm_cuentas_por_cobrar` ADD COLUMN `monto_cuota_bs` decimal(12,2) NOT NULL DEFAULT 0.00 AFTER `monto_cuota_usd`",
        'monto_total_usd'     => "ALTER TABLE `gm_cuentas_por_cobrar` ADD COLUMN `monto_total_usd` decimal(10,2) NOT NULL DEFAULT 0.00 AFTER `monto_cuota_bs`",
        'monto_total_bs'      => "ALTER TABLE `gm_cuentas_por_cobrar` ADD COLUMN `monto_total_bs` decimal(12,2) NOT NULL DEFAULT 0.00 AFTER `monto_total_usd`",
        'saldo_pendiente_usd' => "ALTER TABLE `gm_cuentas_por_cobrar` ADD COLUMN `saldo_pendiente_usd` decimal(10,2) NOT NULL DEFAULT 0.00 AFTER `monto_total_bs`",
        'saldo_pendiente_bs'  => "ALTER TABLE `gm_cuentas_por_cobrar` ADD COLUMN `saldo_pendiente_bs` decimal(12,2) NOT NULL DEFAULT 0.00 AFTER `saldo_pendiente_usd`",
        'debe_usd'            => "ALTER TABLE `gm_cuentas_por_cobrar` ADD COLUMN `debe_usd` decimal(10,2) NOT NULL DEFAULT 0.00 AFTER `saldo`",
        'haber_usd'           => "ALTER TABLE `gm_cuentas_por_cobrar` ADD COLUMN `haber_usd` decimal(10,2) NOT NULL DEFAULT 0.00 AFTER `debe_usd`",
        'saldo_usd'           => "ALTER TABLE `gm_cuentas_por_cobrar` ADD COLUMN `saldo_usd` decimal(10,2) NOT NULL DEFAULT 0.00 AFTER `haber_usd`",
        'estatus'             => "ALTER TABLE `gm_cuentas_por_cobrar` ADD COLUMN `estatus` varchar(20) NOT NULL DEFAULT 'pendiente' AFTER `estado`",
        'url_pdf'             => "ALTER TABLE `gm_cuentas_por_cobrar` ADD COLUMN `url_pdf` varchar(500) DEFAULT NULL AFTER `estatus`",
        'updated_at'          => "ALTER TABLE `gm_cuentas_por_cobrar` ADD COLUMN `updated_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP AFTER `fecha_creacion`",
    ];
    foreach ($colsCxc as $col => $alterSql) {
        $chk = $pdo->query("SHOW COLUMNS FROM `gm_cuentas_por_cobrar` LIKE '$col'");
        if ($chk && !$chk->fetch()) {
            $pdo->exec($alterSql);
        }
    }

    // Asegurar columnas en gm_citas si la tabla ya existía previamente
    $colsCitas = [
        'total_usd'            => "ALTER TABLE `gm_citas` ADD COLUMN `total_usd` decimal(10,2) NOT NULL DEFAULT 0.00 AFTER `procedimiento`",
        'es_financiado'        => "ALTER TABLE `gm_citas` ADD COLUMN `es_financiado` tinyint(1) NOT NULL DEFAULT 0 AFTER `total_usd`",
        'monto_financiado_usd' => "ALTER TABLE `gm_citas` ADD COLUMN `monto_financiado_usd` decimal(10,2) NOT NULL DEFAULT 0.00 AFTER `es_financiado`",
    ];
    foreach ($colsCitas as $col => $alterSql) {
        $stmtCol = $pdo->query("SHOW COLUMNS FROM `gm_citas` LIKE '$col'");
        if ($stmtCol && !$stmtCol->fetch()) {
            $pdo->exec($alterSql);
        }
    }

    // Asegurar columnas de relación y facturación en gm_finanzas si la tabla ya existía previamente
    $colsFinanzas = [
        'cita_id'         => "ALTER TABLE `gm_finanzas` ADD COLUMN `cita_id` mediumint(9) DEFAULT NULL AFTER `historia_id`",
        'servicio_id'     => "ALTER TABLE `gm_finanzas` ADD COLUMN `servicio_id` mediumint(9) DEFAULT NULL AFTER `cita_id`",
        'facturado'       => "ALTER TABLE `gm_finanzas` ADD COLUMN `facturado` tinyint(1) NOT NULL DEFAULT 0",
        'numero_factura'  => "ALTER TABLE `gm_finanzas` ADD COLUMN `numero_factura` varchar(50) DEFAULT NULL",
        'url_factura_pdf' => "ALTER TABLE `gm_finanzas` ADD COLUMN `url_factura_pdf` varchar(500) DEFAULT NULL",
    ];
    foreach ($colsFinanzas as $col => $alterSql) {
        $chk = $pdo->query("SHOW COLUMNS FROM `gm_finanzas` LIKE '$col'");
        if ($chk && !$chk->fetch()) {
            $pdo->exec($alterSql);
        }
    }

    // Asegurar columnas de control de conversión en gm_presupuestos
    $colsPresupuestos = [
        'estatus'    => "ALTER TABLE `gm_presupuestos` ADD COLUMN `estatus` varchar(20) NOT NULL DEFAULT 'pendiente'",
        'finanza_id' => "ALTER TABLE `gm_presupuestos` ADD COLUMN `finanza_id` int(11) DEFAULT NULL",
    ];
    foreach ($colsPresupuestos as $col => $alterSql) {
        $chk = $pdo->query("SHOW COLUMNS FROM `gm_presupuestos` LIKE '$col'");
        if ($chk && !$chk->fetch()) {
            $pdo->exec($alterSql);
        }
    }

    // Asegurar soporte de exámenes independientes, múltiples adjuntos, responsable_carga y observaciones_dr en gm_historias_examenes
    $pdo->exec("ALTER TABLE `gm_historias_examenes` MODIFY COLUMN `historia_id` mediumint(9) DEFAULT NULL");
    $pdo->exec("ALTER TABLE `gm_historias_examenes` MODIFY COLUMN `archivo_adjunto_url` text DEFAULT NULL");
    $colsExamenes = [
        'paciente_id'       => "ALTER TABLE `gm_historias_examenes` ADD COLUMN `paciente_id` bigint(20) DEFAULT NULL AFTER `historia_id`",
        'medico_id'         => "ALTER TABLE `gm_historias_examenes` ADD COLUMN `medico_id` bigint(20) DEFAULT NULL AFTER `paciente_id`",
        'responsable_carga' => "ALTER TABLE `gm_historias_examenes` ADD COLUMN `responsable_carga` varchar(30) NOT NULL DEFAULT 'medico' AFTER `tipo_examen`",
        'observaciones'     => "ALTER TABLE `gm_historias_examenes` ADD COLUMN `observaciones` text DEFAULT NULL AFTER `descripcion_solicitud`",
        'observaciones_dr'  => "ALTER TABLE `gm_historias_examenes` ADD COLUMN `observaciones_dr` text DEFAULT NULL AFTER `observaciones`",
        'fecha_examen'      => "ALTER TABLE `gm_historias_examenes` ADD COLUMN `fecha_examen` date DEFAULT NULL AFTER `archivo_adjunto_url`",
    ];
    foreach ($colsExamenes as $col => $alterSql) {
        $chk = $pdo->query("SHOW COLUMNS FROM `gm_historias_examenes` LIKE '$col'");
        if ($chk && !$chk->fetch()) {
            $pdo->exec($alterSql);
        }
    }

    // Backfill de paciente_id y medico_id para exámenes ya asociados a historias previas
    $pdo->exec("
        UPDATE gm_historias_examenes e
        INNER JOIN gm_historias_medicas h ON e.historia_id = h.id
        SET e.paciente_id = COALESCE(e.paciente_id, h.paciente_id),
            e.medico_id   = COALESCE(e.medico_id, h.medico_id)
        WHERE e.historia_id IS NOT NULL AND e.historia_id > 0
          AND (e.paciente_id IS NULL OR e.medico_id IS NULL)
    ");

    // Poblar configuración inicial (Evolution API + Tokens de Diseño GinevApp)
    $stmtConfig = $pdo->prepare("INSERT INTO `gm_configuracion` (`clave`, `valor`, `descripcion`)
        VALUES (:clave, :valor, :descripcion)
        ON DUPLICATE KEY UPDATE `descripcion` = VALUES(`descripcion`)");

    $configuracionesIniciales = [
        ['evolution_url', 'https://auto-evolution-api.tidwyf.easypanel.host', 'Server URL (Evolution API)'],
        ['evolution_instance', 'la naranja', 'Instancia de Evolution API'],
        ['evolution_apikey', '429683C4C977415CAAFCCE10F7D57E11', 'API Key de Evolution API'],
        ['whatsapp_plantilla_otp', 'Su código de acceso es: {codigo}', 'Plantilla del Mensaje OTP'],
        ['theme_primary', '#C2186B', 'Color Primario (Botones y Acentos)'],
        ['theme_primary_dark', '#4A0E2E', 'Color Oscuro (Barra Superior Web)'],
        ['theme_secondary', '#F45D5D', 'Color Secundario / Degradado'],
        ['theme_background', '#FDF6F8', 'Color de Fondo General'],
        ['theme_surface', '#FFFFFF', 'Color de Tarjetas / Cards'],
        ['theme_text_primary', '#2D1422', 'Color de Texto Principal'],
        ['theme_text_secondary', '#7A5C6B', 'Color de Texto Secundario']
    ];

    foreach ($configuracionesIniciales as $cfg) {
        $stmtConfig->execute([
            ':clave'       => $cfg[0],
            ':valor'       => $cfg[1],
            ':descripcion' => $cfg[2]
        ]);
    }

    echo json_encode([
        "status" => "success",
        "mensaje" => "¡Todas las tablas del sistema médico, financiamiento en servicios, cuentas por cobrar, presupuestos, facturación y configuración dinámica fueron creados y actualizados exitosamente!"
    ], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);

} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode([
        "status" => "error",
        "mensaje" => "Error en la migración: " . $e->getMessage()
    ], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
}