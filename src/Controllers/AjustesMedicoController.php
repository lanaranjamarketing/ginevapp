<?php
namespace App\Controllers;

use App\Config\Database;
use PDO;
use Exception;

class AjustesMedicoController {

    private static function asegurarTablasAuxiliares(PDO $pdo) {$pdo->exec("CREATE TABLE IF NOT EXISTS gm_medico_meta (
            id INT AUTO_INCREMENT PRIMARY KEY,
            medico_id INT NOT NULL,
            meta_key VARCHAR(100) NOT NULL,
            meta_value LONGTEXT NULL,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            UNIQUE KEY idx_medico_key (medico_id, meta_key)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;");
    }

    private static function resolverMedicoId(PDO $pdo, int$idRecibido): int {
        if ($idRecibido <= 0) return 0;
        $stmt =$pdo->prepare("SELECT id, user_id FROM gm_usuarios_extendidos WHERE user_id = ? OR id = ? LIMIT 1");
        $stmt->execute([$idRecibido,$idRecibido]);
        $row =$stmt->fetch(PDO::FETCH_ASSOC);
        if ($row && !empty($row['user_id'])) {
            return intval($row['user_id']);
        }
        return $idRecibido;
    }

    private static function obtenerMeta(PDO $pdo, int$medicoId): array {
        $stmt =$pdo->prepare("SELECT meta_key, meta_value FROM gm_medico_meta WHERE medico_id = ?");
        $stmt->execute([$medicoId]);$metas = [];
        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {$metas[$row['meta_key']] =$row['meta_value'];
        }
        return $metas;
    }

    private static function guardarMeta(PDO $pdo, int$medicoId, string $key,$value) {
        $stmt =$pdo->prepare("INSERT INTO gm_medico_meta (medico_id, meta_key, meta_value)
                               VALUES (?, ?, ?)
                               ON DUPLICATE KEY UPDATE meta_value = VALUES(meta_value)");
        $stmt->execute([$medicoId, $key,$value]);
    }

    private static function primerEnteroPositivo(array $fuente, array $claves, int$default = 1): int {
        foreach ($claves as$k) {
            if (isset($fuente[$k]) &&$fuente[$k] !== '' &&$fuente[$k] !== null) {$val = intval($fuente[$k]);
                if ($val >= 1) {
                    return $val;
                }
            }
        }
        return $default;
    }

    /**
     * GET /api/medico/ajustes?medico_id=X
     */
    public static function obtenerTodo() {
        try {
            $idParam = isset($_GET['medico_id']) ? intval($_GET['medico_id']) : 0;
            if ($idParam <= 0) {
                http_response_code(400);
                echo json_encode(["status" => "error", "mensaje" => "ID de médico inválido"], JSON_UNESCAPED_UNICODE);
                return;
            }

            $pdo = Database::conectar();
            self::asegurarTablasAuxiliares($pdo);

            $stmtPerf =$pdo->prepare("SELECT * FROM gm_usuarios_extendidos WHERE user_id = ? OR id = ? LIMIT 1");
            $stmtPerf->execute([$idParam, $idParam]);$perfilBase = $stmtPerf->fetch(PDO::FETCH_ASSOC) ?: [];$realMedicoId = self::resolverMedicoId($pdo,$idParam);

            $stmtEsp =$pdo->query("SELECT id, nombre, slug FROM gm_especialidades ORDER BY nombre ASC");
            $especialidades = $stmtEsp ? $stmtEsp->fetchAll(PDO::FETCH_ASSOC) : [];

            $metas = self::obtenerMeta($pdo,$realMedicoId);

            $redesRaw = json_decode($perfilBase['redes_sociales'] ?? ($metas['redes_sociales_lista'] ?? '[]'), true);$redesLista = [];
            if (is_array($redesRaw)) {
                foreach ($redesRaw as$r) {
                    if (is_array($r) && !empty($r['red'])) {$redesLista[] = [
                            'red'     => $r['red'],
                            'usuario' => $r['usuario'] ?? ''
                        ];
                    }
                }
            }

            $sedesRaw = json_decode($metas['sedes_multiples'] ?? '[]', true);$sedes = [];
            if (is_array($sedesRaw)) {
                foreach ($sedesRaw as$s) {
                    if (is_string($s)) {
                        $sedes[] = ['pais' => 'Venezuela', 'estado' => '', 'direccion' =>$s, 'lat' => '', 'lng' => ''];
                    } elseif (is_array($s)) {$sedes[] = [
                            'pais'      => $s['pais'] ?? 'Venezuela',
                            'estado'    => $s['estado'] ?? '',
                            'direccion' => $s['direccion'] ?? '',
                            'lat'       => $s['lat'] ?? '',
                            'lng'       => $s['lng'] ?? '',
                        ];
                    }
                }
            }

            $metodosRaw = json_decode($metas['metodos_cobro'] ?? '[]', true);$metodosCobro = [];
            if (is_array($metodosRaw)) {
                foreach ($metodosRaw as$m) {
                    if (is_array($m) && !empty($m['banco'])) {$metodosCobro[] = [
                            'tipo'        => $m['tipo'] ?? 'pago_movil',
                            'banco'       => $m['banco'] ?? '',
                            'cedula_tipo' => $m['cedula_tipo'] ?? 'V',
                            'cedula'      => $m['cedula'] ?? '',
                            'telefono'    => $m['telefono'] ?? '',
                        ];
                    }
                }
            }

            $avatarUrl =$metas['avatar_url'] ?? '';
            if (!empty($avatarUrl) && strpos($avatarUrl, '/uploads/avatars/') !== false) {
                $archivoAvatar = basename(parse_url($avatarUrl, PHP_URL_PATH));
                $host =$_SERVER['HTTP_HOST'] ?? 'api.ginevapp.com';
                if (!empty($archivoAvatar)) {$avatarUrl = "https://$host/api/medico/avatar/ver?file=$archivoAvatar";
                }
            }

            $inicioFactura = self::primerEnteroPositivo($metas, ['inicio_factura', 'numero_control', 'numero_inicio_factura'], 1);
            $inicioPresupuesto = self::primerEnteroPositivo($metas, ['inicio_presupuesto', 'numero_inicio_presupuesto'], 1);

            $perfilCompleto = array_merge($perfilBase, [
                'user_id'                   => $realMedicoId,
                'avatar_url'                => $avatarUrl,
                'tagline'                   => !empty($perfilBase['tagline']) ? $perfilBase['tagline'] : ($metas['tagline'] ?? ''),
                'bio'                       => $metas['bio'] ?? '',
                'redes_sociales'            => $redesLista,
                'segundo_nombre'            => !empty($perfilBase['segundo_nombre']) ? $perfilBase['segundo_nombre'] : ($metas['segundo_nombre'] ?? ''),
                'segundo_apellido'          => !empty($perfilBase['segundo_apellido']) ? $perfilBase['segundo_apellido'] : ($metas['segundo_apellido'] ?? ''),
                'ocupacion'                 => !empty($perfilBase['ocupacion']) ? $perfilBase['ocupacion'] : ($metas['ocupacion'] ?? 'Médico Cirujano'),
                'universidad'               => !empty($perfilBase['universidad']) ? $perfilBase['universidad'] : ($metas['universidad'] ?? ''),
                'especialidad'              => !empty($perfilBase['especialidad']) ? $perfilBase['especialidad'] : ($metas['especialidad'] ?? ''),
                'mpps'                      => !empty($perfilBase['mpps']) ? $perfilBase['mpps'] : ($metas['mpps'] ?? ''),
                'cmm'                       => !empty($perfilBase['cmm']) ? $perfilBase['cmm'] : ($metas['cmm'] ?? ''),
                'rif_tipo'                  => $metas['rif_tipo'] ?? 'V',
                'rif_num'                   => $metas['rif_num'] ?? '',
                'pais_fiscal'               => !empty($perfilBase['pais']) ? $perfilBase['pais'] : ($metas['pais_fiscal'] ?? 'Venezuela'),
                'estado_fiscal'             => !empty($perfilBase['estado']) ? $perfilBase['estado'] : ($metas['estado_fiscal'] ?? ''),
                'direccion_fiscal'          => !empty($perfilBase['direccion_detalle']) ? $perfilBase['direccion_detalle'] : ($metas['direccion_fiscal'] ?? ''),
                'inicio_factura'            => $inicioFactura,
                'numero_control'            => $inicioFactura,
                'numero_inicio_factura'     => $inicioFactura,
                'inicio_presupuesto'        => $inicioPresupuesto,
                'numero_inicio_presupuesto' => $inicioPresupuesto,
                'sedes'                     => $sedes,
                'metodos_cobro'             => $metodosCobro
            ]);

            $stmtCal =$pdo->prepare("SELECT * FROM gm_calendarios WHERE medico_id = ? ORDER BY id DESC");
            $stmtCal->execute([$realMedicoId]);$calendariosRaw = $stmtCal->fetchAll(PDO::FETCH_ASSOC);$calendarios = [];
            foreach ($calendariosRaw as $cal) {$cal['dias_config'] = !empty($cal['dias_config']) ? json_decode($cal['dias_config'], true) : [];
                $calendarios[] =$cal;
            }

            $stmtAsis =$pdo->prepare("
                SELECT u.* FROM gm_usuarios_extendidos u
                INNER JOIN gm_medico_meta m ON m.meta_key = CONCAT('asistente_', u.user_id) AND m.medico_id = ?
                WHERE u.rol_medico = 'gm_asistente'
                ORDER BY u.id DESC
            ");
            $stmtAsis->execute([$realMedicoId]);
            $asistentes =$stmtAsis->fetchAll(PDO::FETCH_ASSOC);

            echo json_encode([
                "status" => "success",
                "data"   => [
                    "perfil"         => $perfilCompleto,
                    "especialidades" => $especialidades,
                    "calendarios"    => $calendarios,
                    "asistentes"     => $asistentes,
                    "metodos_cobro"  => $metodosCobro
                ]
            ], JSON_UNESCAPED_UNICODE);

        } catch (Exception $e) {
            http_response_code(500);
            echo json_encode(["status" => "error", "mensaje" => $e->getMessage()], JSON_UNESCAPED_UNICODE);
        }
    }

    /**
     * GET /api/medico/avatar/ver?file=medico_X.jpg
     */
    public static function verAvatar() {
        @ini_set('display_errors', '0');
        if (ob_get_length()) ob_end_clean();

        $archivo = basename($_GET['file'] ?? '');
        $ruta = __DIR__ . '/../../public/uploads/avatars/' .$archivo;

        if ($archivo !== '' && file_exists($ruta)) {
            $info = @getimagesize($ruta);
            header('Content-Type: ' . ($info['mime'] ?? 'image/jpeg'));
            header('Content-Length: ' . filesize($ruta));
            header('Cache-Control: public, max-age=86400');
            readfile($ruta);
            exit;
        }

        http_response_code(404);
        echo json_encode(["status" => "error", "mensaje" => "Imagen no encontrada"], JSON_UNESCAPED_UNICODE);
        exit;
    }

    /**
     * POST /api/medico/avatar
     */
    public static function subirAvatar() {
        @ini_set('display_errors', '0');
        ob_start();

        try {
            $idParam = intval($_POST['medico_id'] ?? 0);
            if ($idParam <= 0) {
                ob_end_clean();
                http_response_code(400);
                echo json_encode(["status" => "error", "mensaje" => "ID de médico requerido"], JSON_UNESCAPED_UNICODE);
                return;
            }

            if (!isset($_FILES['avatar']) \vert{}\vert{}$_FILES['avatar']['error'] !== UPLOAD_ERR_OK) {
                $errCode =$_FILES['avatar']['error'] ?? -1;
                ob_end_clean();
                http_response_code(400);
                echo json_encode(["status" => "error", "mensaje" => "Error al recibir archivo (código $errCode). Verifica el tamaño máximo."], JSON_UNESCAPED_UNICODE);
                return;
            }

            $archivo =$_FILES['avatar'];

            $infoImagen = @getimagesize($archivo['tmp_name']);
            if ($infoImagen === false) {
                ob_end_clean();
                http_response_code(415);
                echo json_encode(["status" => "error", "mensaje" => "El archivo seleccionado no es una imagen válida."], JSON_UNESCAPED_UNICODE);
                return;
            }

            $mime = $infoImagen['mime'] ?? '';$permitidos = [
                'image/jpeg' => 'jpg',
                'image/png'  => 'png',
                'image/webp' => 'webp',
            ];

            if (!isset($permitidos[$mime])) {
                ob_end_clean();
                http_response_code(415);
                echo json_encode(["status" => "error", "mensaje" => "Formato no permitido ($mime). Use JPG, PNG o WEBP."], JSON_UNESCAPED_UNICODE);
                return;
            }

            if ($archivo['size'] > 5 * 1024 * 1024) {
                ob_end_clean();
                http_response_code(413);
                echo json_encode(["status" => "error", "mensaje" => "La imagen supera el límite de 5 MB."], JSON_UNESCAPED_UNICODE);
                return;
            }

            $pdo = Database::conectar();
            self::asegurarTablasAuxiliares($pdo);$medicoId = self::resolverMedicoId($pdo,$idParam);

            $dirDestino = __DIR__ . '/../../public/uploads/avatars';
            if (!is_dir($dirDestino)) {
                @mkdir($dirDestino, 0777, true);
            }

            if (!is_writable($dirDestino)) {
                @chmod($dirDestino, 0777);
            }

            $metas = self::obtenerMeta($pdo,$medicoId);
            if (!empty($metas['avatar_url'])) {$archivoViejo = '';
                $queryStr = parse_url($metas['avatar_url'], PHP_URL_QUERY);
                if ($queryStr) {
                    parse_str($queryStr,$qParams);
                    $archivoViejo = basename($qParams['file'] ?? '');
                }
                if (empty($archivoViejo)) {$pathPrevio = parse_url($metas['avatar_url'], PHP_URL_PATH);$archivoViejo = $pathPrevio ? basename($pathPrevio) : '';
                }
                $rutaVieja = $dirDestino . '/' .$archivoViejo;
                if ($archivoViejo && file_exists($rutaVieja)) {
                    @unlink($rutaVieja);
                }
            }

            $ext =$permitidos[$mime];$nombreArchivo = 'medico_' . $medicoId . '_' . time() . '.' . $ext;
            $rutaFinal = $dirDestino . '/' .$nombreArchivo;

            if (!@move_uploaded_file($archivo['tmp_name'],$rutaFinal)) {
                ob_end_clean();
                http_response_code(500);
                echo json_encode([
                    "status"  => "error",
                    "mensaje" => "No se pudo escribir el archivo en $dirDestino. Revisa permisos o volumen en Easypanel."
                ], JSON_UNESCAPED_UNICODE);
                return;
            }

            $host = $_SERVER['HTTP_HOST'] ?? 'api.ginevapp.com';$urlPublica = "https://$host/api/medico/avatar/ver?file=$nombreArchivo";

            self::guardarMeta($pdo, $medicoId, 'avatar_url',$urlPublica);

            ob_end_clean();
            echo json_encode([
                "status"     => "success",
                "mensaje"    => "Foto de perfil actualizada correctamente.",
                "avatar_url" => $urlPublica
            ], JSON_UNESCAPED_UNICODE);

        } catch (Exception $e) {
            if (ob_get_length()) ob_end_clean();
            http_response_code(500);
            echo json_encode(["status" => "error", "mensaje" => $e->getMessage()], JSON_UNESCAPED_UNICODE);
        }
    }

    /**
     * POST /api/medico/perfil (Actualiza gm_usuarios_extendidos + gm_medico_meta)
     */
    public static function guardarPerfil() {
        try {
            $rawBody = file_get_contents('php://input');
            $input = json_decode($rawBody, true);
            if (!is_array($input) || empty($input)) {
                $input = $_POST;
            }

            $idParam = intval($input['medico_id'] ?? $input['user_id'] ?? 0);
            $seccion = trim($input['seccion'] ?? 'todo');

            if ($idParam <= 0) {
                http_response_code(400);
                echo json_encode(["status" => "error", "mensaje" => "ID de médico requerido"], JSON_UNESCAPED_UNICODE);
                return;
            }

            $pdo = Database::conectar();
            self::asegurarTablasAuxiliares($pdo);
            $medicoId = self::resolverMedicoId($pdo, $idParam);

            switch ($seccion) {
                case 'publico':
                    $avatar  = trim($input['avatar_url'] ?? '');
                    $tagline = trim($input['tagline'] ?? '');
                    $bio     = trim($input['bio'] ?? '');

                    $redesLimpias = [];
                    if (isset($input['redes_sociales']) && is_array($input['redes_sociales'])) {
                        foreach ($input['redes_sociales'] as $r) {
                            if (is_array($r) && trim($r['usuario'] ?? '') !== '') {
                                $redesLimpias[] = [
                                    'red'     => trim($r['red'] ?? 'Instagram'),
                                    'usuario' => trim($r['usuario'] ?? ''),
                                ];
                            }
                        }
                    }
                    $redesJson = json_encode($redesLimpias, JSON_UNESCAPED_UNICODE);

                    $stmt = $pdo->prepare("
                        UPDATE gm_usuarios_extendidos
                        SET tagline = ?, redes_sociales = ?
                        WHERE user_id = ? OR id = ?
                    ");
                    $stmt->execute([$tagline, $redesJson, $medicoId, $idParam]);

                    self::guardarMeta($pdo, $medicoId, 'avatar_url', $avatar);
                    self::guardarMeta($pdo, $medicoId, 'tagline', $tagline);
                    self::guardarMeta($pdo, $medicoId, 'bio', $bio);
                    self::guardarMeta($pdo, $medicoId, 'redes_sociales_lista', $redesJson);
                    $msg = "Perfil público y presencia digital actualizados.";
                    break;

                case 'personales':
                    $nombre    = trim($input['nombre'] ?? '');
                    $segNombre = trim($input['segundo_nombre'] ?? '');
                    $apellido  = trim($input['apellido'] ?? '');
                    $segApell  = trim($input['segundo_apellido'] ?? '');
                    $sexo      = trim($input['sexo'] ?? 'Femenino');
                    $fnac      = trim($input['fecha_nacimiento'] ?? '');

                    $stmt = $pdo->prepare("
                        UPDATE gm_usuarios_extendidos
                        SET nombre = ?, segundo_nombre = ?, apellido = ?, segundo_apellido = ?, sexo = ?, fecha_nacimiento = ?
                        WHERE user_id = ? OR id = ?
                    ");
                    $stmt->execute([$nombre, $segNombre, $apellido, $segApell, $sexo, !empty($fnac) ? $fnac : null, $medicoId, $idParam]);

                    self::guardarMeta($pdo, $medicoId, 'segundo_nombre', $segNombre);
                    self::guardarMeta($pdo, $medicoId, 'segundo_apellido', $segApell);
                    $msg = "Datos personales actualizados.";
                    break;

                case 'profesionales':
                    $ocupacion    = trim($input['ocupacion'] ?? '');
                    $universidad  = trim($input['universidad'] ?? '');
                    $especialidad = trim($input['especialidad'] ?? '');
                    $mpps         = trim($input['mpps'] ?? '');
                    $cmm          = trim($input['cmm'] ?? '');

                    $stmt = $pdo->prepare("
                        UPDATE gm_usuarios_extendidos
                        SET ocupacion = ?, universidad = ?, especialidad = ?, mpps = ?, cmm = ?
                        WHERE user_id = ? OR id = ?
                    ");
                    $stmt->execute([$ocupacion, $universidad, $especialidad, $mpps, $cmm, $medicoId, $idParam]);

                    self::guardarMeta($pdo, $medicoId, 'ocupacion', $ocupacion);
                    self::guardarMeta($pdo, $medicoId, 'universidad', $universidad);
                    self::guardarMeta($pdo, $medicoId, 'especialidad', $especialidad);
                    self::guardarMeta($pdo, $medicoId, 'mpps', $mpps);
                    self::guardarMeta($pdo, $medicoId, 'cmm', $cmm);
                    $msg = "Datos profesionales actualizados.";
                    break;

                case 'fiscales':
                    $rifTipo = trim($input['rif_tipo'] ?? 'V');
                    $rifNum  = trim($input['rif_num'] ?? '');
                    $pais    = trim($input['pais_fiscal'] ?? 'Venezuela');
                    $estado  = trim($input['estado_fiscal'] ?? '');
                    $dir     = trim($input['direccion_fiscal'] ?? '');

                    $inicioFactura = self::primerEnteroPositivo($input, [
                        'inicio_factura',
                        'numero_control',
                        'numero_inicio_factura',
                        'inicioFactura',
                        'numeroControl',
                        'correlativo_factura'
                    ], 1);

                    $inicioPresupuesto = self::primerEnteroPositivo($input, [
                        'inicio_presupuesto',
                        'numero_inicio_presupuesto',
                        'inicioPresupuesto',
                        'correlativo_presupuesto'
                    ], 1);

                    $stmt = $pdo->prepare("
                        UPDATE gm_usuarios_extendidos
                        SET pais = ?, estado = ?, direccion_detalle = ?
                        WHERE user_id = ? OR id = ?
                    ");
                    $stmt->execute([$pais, $estado, $dir, $medicoId, $idParam]);

                    self::guardarMeta($pdo, $medicoId, 'rif_tipo', $rifTipo);
                    self::guardarMeta($pdo, $medicoId, 'rif_num', $rifNum);
                    self::guardarMeta($pdo, $medicoId, 'pais_fiscal', $pais);
                    self::guardarMeta($pdo, $medicoId, 'estado_fiscal', $estado);
                    self::guardarMeta($pdo, $medicoId, 'direccion_fiscal', $dir);
                    self::guardarMeta($pdo, $medicoId, 'inicio_factura', (string)$inicioFactura);
                    self::guardarMeta($pdo, $medicoId, 'numero_control', (string)$inicioFactura);
                    self::guardarMeta($pdo, $medicoId, 'inicio_presupuesto', (string)$inicioPresupuesto);
                    $msg = "Datos fiscales y correlativos actualizados.";
                    break;

                case 'bancarios':
                    $metodosLimpios = [];
                    if (isset($input['metodos_cobro']) && is_array($input['metodos_cobro'])) {
                        foreach ($input['metodos_cobro'] as $m) {
                            if (!is_array($m)) continue;
                            $tipo = trim($m['tipo'] ?? 'pago_movil');
                            $banco = trim($m['banco'] ?? '');
                            $cedTipo = trim($m['cedula_tipo'] ?? 'V');
                            $cedula = trim($m['cedula'] ?? '');
                            $telefono = trim($m['telefono'] ?? '');

                            if ($banco !== '' && $cedula !== '') {
                                $metodosLimpios[] = [
                                    'tipo'        => ($tipo === 'transferencia') ? 'transferencia' : 'pago_movil',
                                    'banco'       => $banco,
                                    'cedula_tipo' => in_array($cedTipo, ['V', 'E', 'J']) ? $cedTipo : 'V',
                                    'cedula'      => $cedula,
                                    'telefono'    => ($tipo === 'pago_movil') ? $telefono : '',
                                ];
                            }
                        }
                    }
                    self::guardarMeta($pdo, $medicoId, 'metodos_cobro', json_encode($metodosLimpios, JSON_UNESCAPED_UNICODE));
                    $msg = "Métodos de cobro y datos bancarios actualizados.";
                    break;

                case 'sedes':
                    $sedesLimpias = [];
                    if (isset($input['sedes']) && is_array($input['sedes'])) {
                        foreach ($input['sedes'] as $s) {
                            if (is_array($s) && trim($s['direccion'] ?? '') !== '') {
                                $sedesLimpias[] = [
                                    'pais'      => trim($s['pais'] ?? 'Venezuela'),
                                    'estado'    => trim($s['estado'] ?? ''),
                                    'direccion' => trim($s['direccion'] ?? ''),
                                    'lat'       => trim($s['lat'] ?? ''),
                                    'lng'       => trim($s['lng'] ?? ''),
                                ];
                            }
                        }
                    }
                    self::guardarMeta($pdo, $medicoId, 'sedes_multiples', json_encode($sedesLimpias, JSON_UNESCAPED_UNICODE));
                    $msg = "Sedes de atención actualizadas.";
                    break;

                default:
                    $msg = "Cambios guardados.";
                    break;
            }

            echo json_encode([
                "status"  => "success",
                "mensaje" => $msg,
                "data"    => [
                    "medico_id"          => $medicoId,
                    "inicio_factura"     => $inicioFactura ?? null,
                    "numero_control"     => $inicioFactura ?? null,
                    "inicio_presupuesto" => $inicioPresupuesto ?? null,
                ]
            ], JSON_UNESCAPED_UNICODE);

        } catch (Exception $e) {
            http_response_code(500);
            echo json_encode(["status" => "error", "mensaje" => $e->getMessage()], JSON_UNESCAPED_UNICODE);
        }
    }

    public static function guardarCalendario() {
        try {
            $input = json_decode(file_get_contents('php://input'), true) ?: [];
            $idParam = intval($input['medico_id'] ?? 0);
            $calId   = intval($input['id'] ?? 0);

            if ($idParam <= 0) {
                http_response_code(400);
                echo json_encode(["status" => "error", "mensaje" => "ID de médico requerido"], JSON_UNESCAPED_UNICODE);
                return;
            }

            $pdo = Database::conectar();
            self::asegurarTablasAuxiliares($pdo);
            $medicoId = self::resolverMedicoId($pdo, $idParam);

            $metas = self::obtenerMeta($pdo, $medicoId);
            $rifNum = trim($metas['rif_num'] ?? '');
            $sedes  = json_decode($metas['sedes_multiples'] ?? '[]', true);

            if ($rifNum === '' || empty($sedes)) {
                http_response_code(422);
                echo json_encode([
                    "status"  => "error",
                    "mensaje" => "Debe registrar su RIF en Datos Fiscales y al menos una Sede de Atención antes de crear calendarios."
                ], JSON_UNESCAPED_UNICODE);
                return;
            }

            $nombreCal = trim($input['nombre_calendario'] ?? '');
            if ($nombreCal === '') {
                http_response_code(400);
                echo json_encode(["status" => "error", "mensaje" => "El nombre del calendario es obligatorio."], JSON_UNESCAPED_UNICODE);
                return;
            }

            $diasConfigJson     = json_encode($input['dias_config'] ?? [], JSON_UNESCAPED_UNICODE);
            $duracionBloque     = intval($input['duracion_bloque'] ?? 30);
            $cuposPorBloque     = intval($input['cupos_por_bloque'] ?? 1);
            $appointmentBuffer  = trim($input['appointment_buffer'] ?? '24 hours');
            $cancellationBuffer = trim($input['cancellation_buffer'] ?? '24 hours');
            $preventAfterDate   = !empty($input['prevent_after_date']) ? trim($input['prevent_after_date']) : null;
            $defaultStatus      = trim($input['default_status'] ?? 'reservada');

            if ($calId > 0) {
                $stmt = $pdo->prepare("
                    UPDATE gm_calendarios
                    SET nombre_calendario = ?, dias_config = ?, duracion_bloque = ?, cupos_por_bloque = ?,
                        appointment_buffer = ?, cancellation_buffer = ?, prevent_after_date = ?, default_status = ?
                    WHERE id = ? AND medico_id = ?
                ");
                $stmt->execute([
                    $nombreCal, $diasConfigJson, $duracionBloque, $cuposPorBloque,
                    $appointmentBuffer, $cancellationBuffer, $preventAfterDate, $defaultStatus,
                    $calId, $medicoId
                ]);
                $msg = "Calendario actualizado correctamente.";
            } else {
                $stmt = $pdo->prepare("
                    INSERT INTO gm_calendarios
                    (medico_id, nombre_calendario, dias_config, duracion_bloque, cupos_por_bloque, appointment_buffer, cancellation_buffer, prevent_after_date, default_status, estado)
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, 'activo')
                ");
                $stmt->execute([
                    $medicoId, $nombreCal, $diasConfigJson, $duracionBloque, $cuposPorBloque,
                    $appointmentBuffer, $cancellationBuffer, $preventAfterDate, $defaultStatus
                ]);
                $msg = "Calendario creado con éxito.";
            }

            echo json_encode(["status" => "success", "mensaje" => $msg], JSON_UNESCAPED_UNICODE);

        } catch (Exception $e) {
            http_response_code(500);
            echo json_encode(["status" => "error", "mensaje" => $e->getMessage()], JSON_UNESCAPED_UNICODE);
        }
    }

    public static function eliminarCalendario() {
        try {
            $id      = intval($_GET['id'] ?? 0);
            $idParam = intval($_GET['medico_id'] ?? 0);

            if ($id <= 0 || $idParam <= 0) {
                http_response_code(400);
                echo json_encode(["status" => "error", "mensaje" => "Parámetros inválidos"], JSON_UNESCAPED_UNICODE);
                return;
            }

            $pdo = Database::conectar();
            $medicoId = self::resolverMedicoId($pdo, $idParam);
            $stmt = $pdo->prepare("DELETE FROM gm_calendarios WHERE id = ? AND medico_id = ?");
            $stmt->execute([$id, $medicoId]);

            echo json_encode(["status" => "success", "mensaje" => "Calendario eliminado."], JSON_UNESCAPED_UNICODE);
        } catch (Exception $e) {
            http_response_code(500);
            echo json_encode(["status" => "error", "mensaje" => $e->getMessage()], JSON_UNESCAPED_UNICODE);
        }
    }

    public static function crearAsistente() {
        try {
            $input = json_decode(file_get_contents('php://input'), true) ?: [];
            $idParam  = intval($input['medico_id'] ?? 0);
            $nombre   = trim($input['nombre'] ?? '');
            $apellido = trim($input['apellido'] ?? '');
            $cedula   = trim($input['cedula'] ?? '');
            $whatsapp = trim($input['whatsapp'] ?? '');
            $email    = trim($input['email'] ?? '');

            if ($idParam <= 0 || $nombre === '' || $cedula === '' || $whatsapp === '') {
                http_response_code(400);
                echo json_encode(["status" => "error", "mensaje" => "Complete todos los campos obligatorios del asistente."], JSON_UNESCAPED_UNICODE);
                return;
            }

            $pdo = Database::conectar();
            self::asegurarTablasAuxiliares($pdo);
            $medicoId = self::resolverMedicoId($pdo, $idParam);

            $stmtCheck = $pdo->prepare("SELECT id, user_id FROM gm_usuarios_extendidos WHERE cedula = ? OR whatsapp = ? LIMIT 1");
            $stmtCheck->execute([$cedula, $whatsapp]);
            if ($stmtCheck->fetch(PDO::FETCH_ASSOC)) {
                http_response_code(409);
                echo json_encode(["status" => "error", "mensaje" => "Ya existe un usuario registrado con esa cédula o WhatsApp."], JSON_UNESCAPED_UNICODE);
                return;
            }

            $stmtMax = $pdo->query("SELECT COALESCE(MAX(user_id), 1000) + 1 AS siguiente FROM gm_usuarios_extendidos");
            $nuevoUserId = intval($stmtMax->fetch(PDO::FETCH_ASSOC)['siguiente']);

            $stmtIns = $pdo->prepare("
                INSERT INTO gm_usuarios_extendidos
                (user_id, tipo_identificacion, cedula, whatsapp, nombre, apellido, edad, sexo, email, rol_medico)
                VALUES (?, 'V', ?, ?, ?, ?, 0, 'Femenino', ?, 'gm_asistente')
            ");
            $stmtIns->execute([$nuevoUserId, $cedula, $whatsapp, $nombre, $apellido, $email]);

            self::guardarMeta($pdo, $medicoId, 'asistente_' . $nuevoUserId, (string)$nuevoUserId);

            echo json_encode([
                "status"  => "success",
                "mensaje" => "Asistente registrado y vinculado exitosamente."
            ], JSON_UNESCAPED_UNICODE);

        } catch (Exception $e) {
            http_response_code(500);
            echo json_encode(["status" => "error", "mensaje" => $e->getMessage()], JSON_UNESCAPED_UNICODE);
        }
    }

    public static function eliminarAsistente() {
        try {
            $asisUserId = intval($_GET['user_id'] ?? 0);
            $idParam    = intval($_GET['medico_id'] ?? 0);

            if ($asisUserId <= 0 || $idParam <= 0) {
                http_response_code(400);
                echo json_encode(["status" => "error", "mensaje" => "Parámetros inválidos"], JSON_UNESCAPED_UNICODE);
                return;
            }

            $pdo = Database::conectar();
            $medicoId = self::resolverMedicoId($pdo, $idParam);
            $stmtMeta = $pdo->prepare("DELETE FROM gm_medico_meta WHERE medico_id = ? AND meta_key = ?");
            $stmtMeta->execute([$medicoId, 'asistente_' . $asisUserId]);

            $stmtUser = $pdo->prepare("DELETE FROM gm_usuarios_extendidos WHERE user_id = ? AND rol_medico = 'gm_asistente'");
            $stmtUser->execute([$asisUserId]);

            echo json_encode(["status" => "success", "mensaje" => "Asistente eliminado correctamente."], JSON_UNESCAPED_UNICODE);
        } catch (Exception $e) {
            http_response_code(500);
            echo json_encode(["status" => "error", "mensaje" => $e->getMessage()], JSON_UNESCAPED_UNICODE);
        }
    }
}