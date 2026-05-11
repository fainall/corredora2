<?php
/**
 * GPRB - Backend API (MySQL/PHP)
 * Single-file API replacing the Supabase backend.
 *
 * PHP 8.1 compatible. mysqli WITHOUT mysqlnd.
 * Do NOT use $stmt->get_result() — use bind_result helpers instead.
 */

// ── Error/exception handling ─────────────────────────────────
mysqli_report(MYSQLI_REPORT_OFF);
ini_set('display_errors', '0');
error_reporting(E_ALL);

set_exception_handler(function ($e) {
    if (!headers_sent()) {
        http_response_code(500);
        header('Content-Type: application/json; charset=utf-8');
    }
    echo json_encode([
        'ok'    => false,
        'error' => 'Server error: ' . $e->getMessage(),
    ]);
    exit;
});

set_error_handler(function ($severity, $message, $file, $line) {
    if (!(error_reporting() & $severity)) return false;
    throw new ErrorException($message, 0, $severity, $file, $line);
});

register_shutdown_function(function () {
    $err = error_get_last();
    if ($err && in_array($err['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR], true)) {
        if (!headers_sent()) {
            http_response_code(500);
            header('Content-Type: application/json; charset=utf-8');
        }
        echo json_encode([
            'ok'    => false,
            'error' => 'Fatal: ' . $err['message'],
        ]);
    }
});

// ── DB Config ────────────────────────────────────────────────
const DB_HOST = 'localhost';
const DB_USER = 'gprbcl_usr';
const DB_PASS = 'Gprb2026!Db#Strong';
const DB_NAME = 'gprbcl_db';

const UPLOAD_DIR     = __DIR__ . '/uploads/properties/';
const UPLOAD_URL     = '/uploads/properties/';
const MAX_UPLOAD     = 10 * 1024 * 1024; // 10 MB
const RATE_WINDOW    = 15;               // seconds
const CONTACT_EMAIL  = 'contacto@gprb.cl';

// ── CORS / headers ───────────────────────────────────────────
$allowedOrigins = ['https://gprb.cl', 'https://www.gprb.cl'];
$origin = $_SERVER['HTTP_ORIGIN'] ?? '';
if (in_array($origin, $allowedOrigins, true)) {
    header('Access-Control-Allow-Origin: ' . $origin);
    header('Vary: Origin');
    header('Access-Control-Allow-Credentials: true');
}
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization, X-Requested-With');
header('Content-Type: application/json; charset=utf-8');

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'OPTIONS') {
    http_response_code(204);
    exit;
}

// ── Session ──────────────────────────────────────────────────
session_set_cookie_params([
    'lifetime' => 0,
    'path'     => '/',
    'secure'   => true,
    'httponly' => true,
    'samesite' => 'Lax',
]);
session_name('GPRBSESSID');
session_start();

// ── Response helpers ─────────────────────────────────────────
function ok($data = null) {
    echo json_encode(['ok' => true, 'data' => $data]);
    exit;
}

function fail(string $msg, int $code = 400) {
    http_response_code($code);
    echo json_encode(['ok' => false, 'error' => $msg]);
    exit;
}

function require_auth(): array {
    if (empty($_SESSION['user'])) fail('No autenticado', 401);
    return $_SESSION['user'];
}

function read_json_body(): array {
    $raw = file_get_contents('php://input');
    if (!$raw) return [];
    $j = json_decode($raw, true);
    return is_array($j) ? $j : [];
}

// ── DB helpers ───────────────────────────────────────────────
function db(): mysqli {
    static $db = null;
    if ($db === null) {
        $db = @new mysqli(DB_HOST, DB_USER, DB_PASS, DB_NAME);
        if ($db->connect_errno) fail('DB connect: ' . $db->connect_error, 500);
        $db->set_charset('utf8mb4');
    }
    return $db;
}

/**
 * Run a SELECT and return all rows as assoc arrays.
 * Works WITHOUT mysqlnd by using bind_result.
 */
function db_query_all(mysqli $db, string $sql, array $params = [], string $types = ''): array {
    if (empty($params)) {
        $r = $db->query($sql);
        if ($r === false) throw new RuntimeException('Query failed: ' . $db->error);
        $rows = [];
        while ($row = $r->fetch_assoc()) $rows[] = $row;
        $r->free();
        return $rows;
    }
    $stmt = $db->prepare($sql);
    if (!$stmt) throw new RuntimeException('Prepare failed: ' . $db->error);
    $stmt->bind_param($types, ...$params);
    $stmt->execute();

    $meta = $stmt->result_metadata();
    if (!$meta) { $stmt->close(); return []; }

    $row = [];
    $bindRefs = [];
    while ($f = $meta->fetch_field()) {
        $row[$f->name] = null;
        $bindRefs[]    = &$row[$f->name];
    }
    call_user_func_array([$stmt, 'bind_result'], $bindRefs);

    $rows = [];
    while ($stmt->fetch()) {
        $copy = [];
        foreach ($row as $k => $v) $copy[$k] = $v;
        $rows[] = $copy;
    }
    $stmt->close();
    return $rows;
}

function db_query_one(mysqli $db, string $sql, array $params = [], string $types = ''): ?array {
    $rows = db_query_all($db, $sql, $params, $types);
    return $rows[0] ?? null;
}

function db_execute(mysqli $db, string $sql, array $params = [], string $types = ''): int {
    $stmt = $db->prepare($sql);
    if (!$stmt) throw new RuntimeException('Prepare failed: ' . $db->error);
    if (!empty($params)) $stmt->bind_param($types, ...$params);
    $stmt->execute();
    $insertId = $stmt->insert_id;
    $affected = $stmt->affected_rows;
    $stmt->close();
    return $insertId > 0 ? $insertId : $affected;
}

// ── Type coercion helpers ────────────────────────────────────
function as_float_or_null($v): ?float {
    if ($v === null || $v === '' || $v === false) return null;
    if (!is_numeric($v)) return null;
    return (float) $v;
}

function as_int_or_null($v): ?int {
    if ($v === null || $v === '' || $v === false) return null;
    if (!is_numeric($v)) return null;
    return (int) $v;
}

function as_bool_int($v): int {
    return $v ? 1 : 0;
}

function as_string_or_null($v): ?string {
    if ($v === null) return null;
    $s = trim((string)$v);
    return $s === '' ? null : $s;
}

function as_array($v): array {
    if (is_array($v)) return $v;
    if (is_string($v) && $v !== '') {
        $j = json_decode($v, true);
        if (is_array($j)) return $j;
    }
    return [];
}

// ── Property mappers ─────────────────────────────────────────
function prop_from_db(array $r): array {
    return [
        'id'                   => (int)$r['id'],
        'title'                => $r['title'] ?? '',
        'subtitle'             => $r['subtitle'] ?? null,
        'type'                 => $r['type'] ?? '',
        'status'               => $r['status'] ?? '',
        'price'                => as_float_or_null($r['price'] ?? null),
        'priceUnit'            => $r['price_unit'] ?? 'UF',
        'priceWithIva'         => (int)($r['price_with_iva'] ?? 1) === 1,
        'gastosComunes'        => as_float_or_null($r['gastos_comunes'] ?? null),
        'gastosComunesUnit'    => $r['gastos_comunes_unit'] ?? 'UF',
        'gastosComunesWithIva' => (int)($r['gastos_comunes_with_iva'] ?? 0) === 1,
        'location'             => $r['location'] ?? '',
        'address'              => $r['address'] ?? '',
        'area'                 => as_float_or_null($r['area'] ?? null),
        'areaBodega'           => as_float_or_null($r['area_bodega'] ?? null),
        'areaOficina'          => as_float_or_null($r['area_oficina'] ?? null),
        'areaAltillo'          => as_float_or_null($r['area_altillo'] ?? null),
        'usableArea'           => as_float_or_null($r['usable_area'] ?? null),
        'bathrooms'            => (int)($r['bathrooms'] ?? 0),
        'parking'              => (int)($r['parking'] ?? 0),
        'portones'             => as_int_or_null($r['portones'] ?? null),
        'andenes'              => as_int_or_null($r['andenes'] ?? null),
        'cumbrera'             => as_float_or_null($r['cumbrera'] ?? null),
        'privateRooms'         => (int)($r['private_rooms'] ?? 0),
        'age'                  => $r['age'] ?? null,
        'height'               => as_float_or_null($r['height'] ?? null),
        'width'                => as_float_or_null($r['width'] ?? null),
        'floorSupport'         => as_float_or_null($r['floor_support'] ?? null),
        'platforms'            => $r['platforms'] ?? null,
        'pricePerM2'           => as_float_or_null($r['price_per_m2'] ?? null),
        'propertyCode'         => $r['property_code'] ?? '',
        'portalCode'           => $r['portal_code'] ?? null,
        'image'                => $r['image'] ?? '',
        'gallery'              => as_array($r['gallery'] ?? []),
        'description'          => $r['description'] ?? '',
        'videoUrl'             => $r['video_url'] ?? null,
        'services'             => as_array($r['services'] ?? []),
        'amenities'            => as_array($r['amenities'] ?? []),
        'security'             => as_array($r['security'] ?? []),
        'featured'             => (int)($r['featured'] ?? 0) === 1,
        'published'            => (int)($r['published'] ?? 1) === 1,
    ];
}

/**
 * Build the column → value map for INSERT/UPDATE from a camelCase payload.
 * Returns: ['cols' => [...], 'vals' => [...], 'types' => 'sssd...']
 */
function prop_payload_to_db(array $p): array {
    $map = [
        ['title',                  as_string_or_null($p['title']         ?? null), 's'],
        ['subtitle',               as_string_or_null($p['subtitle']      ?? null), 's'],
        ['type',                   as_string_or_null($p['type']          ?? null), 's'],
        ['status',                 as_string_or_null($p['status']        ?? null), 's'],
        ['price',                  as_float_or_null($p['price']          ?? null), 'd'],
        ['price_unit',             as_string_or_null($p['priceUnit']     ?? null), 's'],
        ['price_with_iva',         as_bool_int($p['priceWithIva']        ?? true),  'i'],
        ['gastos_comunes',         as_float_or_null($p['gastosComunes']  ?? null), 'd'],
        ['gastos_comunes_unit',    as_string_or_null($p['gastosComunesUnit']  ?? null), 's'],
        ['gastos_comunes_with_iva',as_bool_int($p['gastosComunesWithIva'] ?? false), 'i'],
        ['location',               as_string_or_null($p['location']      ?? null), 's'],
        ['address',                as_string_or_null($p['address']       ?? null), 's'],
        ['area',                   as_float_or_null($p['area']           ?? null), 'd'],
        ['area_bodega',            as_float_or_null($p['areaBodega']     ?? null), 'd'],
        ['area_oficina',           as_float_or_null($p['areaOficina']    ?? null), 'd'],
        ['area_altillo',           as_float_or_null($p['areaAltillo']    ?? null), 'd'],
        ['usable_area',            as_float_or_null($p['usableArea']     ?? null), 'd'],
        ['bathrooms',              as_int_or_null($p['bathrooms']        ?? null), 'i'],
        ['parking',                as_int_or_null($p['parking']          ?? null), 'i'],
        ['portones',               as_int_or_null($p['portones']         ?? null), 'i'],
        ['andenes',                as_int_or_null($p['andenes']          ?? null), 'i'],
        ['cumbrera',               as_float_or_null($p['cumbrera']       ?? null), 'd'],
        ['private_rooms',          as_int_or_null($p['privateRooms']     ?? null), 'i'],
        ['age',                    as_string_or_null($p['age']           ?? null), 's'],
        ['height',                 as_float_or_null($p['height']         ?? null), 'd'],
        ['width',                  as_float_or_null($p['width']          ?? null), 'd'],
        ['floor_support',          as_float_or_null($p['floorSupport']   ?? null), 'd'],
        ['platforms',              as_string_or_null($p['platforms']     ?? null), 's'],
        ['price_per_m2',           as_float_or_null($p['pricePerM2']     ?? null), 'd'],
        ['property_code',          as_string_or_null($p['propertyCode']  ?? null), 's'],
        ['portal_code',            as_string_or_null($p['portalCode']    ?? null), 's'],
        ['image',                  as_string_or_null($p['image']         ?? null), 's'],
        ['gallery',                json_encode(as_array($p['gallery']    ?? []), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), 's'],
        ['description',            as_string_or_null($p['description']   ?? null), 's'],
        ['video_url',              as_string_or_null($p['videoUrl']      ?? null), 's'],
        ['services',               json_encode(as_array($p['services']   ?? []), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), 's'],
        ['amenities',              json_encode(as_array($p['amenities']  ?? []), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), 's'],
        ['security',               json_encode(as_array($p['security']   ?? []), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), 's'],
        ['featured',               as_bool_int($p['featured']            ?? false), 'i'],
        ['published',              as_bool_int($p['published']           ?? true),  'i'],
    ];
    $cols = []; $vals = []; $types = '';
    foreach ($map as [$col, $val, $t]) {
        $cols[]  = $col;
        $vals[]  = $val;
        $types  .= $t;
    }
    return ['cols' => $cols, 'vals' => $vals, 'types' => $types];
}

// ── Slide mappers ────────────────────────────────────────────
function slide_from_db(array $r): array {
    return [
        'id'        => (int)$r['id'],
        'bgUrl'     => $r['bg_url'] ?? '',
        'tag'       => $r['tag'] ?? '',
        'title'     => $r['title'] ?? '',
        'subtitle'  => $r['subtitle'] ?? '',
        'sortOrder' => (int)($r['sort_order'] ?? 0),
        'active'    => (int)($r['active'] ?? 1) === 1,
    ];
}

// ── Routing ──────────────────────────────────────────────────
$action = $_GET['action'] ?? '';

try {
    switch ($action) {
        case 'login':              handle_login();              break;
        case 'logout':             handle_logout();             break;
        case 'session':            handle_session();            break;
        case 'properties':         handle_properties();         break;
        case 'save_property':      handle_save_property();      break;
        case 'delete_property':    handle_delete_property();    break;
        case 'duplicate_property': handle_duplicate_property(); break;
        case 'upload_image':       handle_upload_image();       break;
        case 'delete_image':       handle_delete_image();       break;
        case 'save_contact':       handle_save_contact();       break;
        case 'contacts':           handle_contacts();           break;
        case 'slides':             handle_slides();             break;
        case 'save_slides':        handle_save_slides();        break;
        default: fail('Acción desconocida: ' . $action, 404);
    }
} catch (Throwable $e) {
    fail('Error: ' . $e->getMessage(), 500);
}

// ════════════════════════════════════════════════════════════
//   AUTH
// ════════════════════════════════════════════════════════════
function handle_login() {
    $b = read_json_body();
    $email = trim((string)($b['email'] ?? ''));
    $pass  = (string)($b['password'] ?? '');
    if ($email === '' || $pass === '') fail('Credenciales requeridas', 400);

    // Allow login with bare username (append @gprb.cl)
    if (strpos($email, '@') === false) $email .= '@gprb.cl';

    $db = db();
    $row = db_query_one(
        $db,
        'SELECT id, email, password_hash, name FROM admin_users WHERE email = ? LIMIT 1',
        [$email],
        's'
    );
    if (!$row) fail('Credenciales inválidas', 401);
    if (!password_verify($pass, $row['password_hash'])) fail('Credenciales inválidas', 401);

    $user = [
        'id'    => (int)$row['id'],
        'email' => $row['email'],
        'name'  => $row['name'],
    ];
    session_regenerate_id(true);
    $_SESSION['user'] = $user;
    ok(['user' => $user]);
}

function handle_logout() {
    $_SESSION = [];
    if (ini_get('session.use_cookies')) {
        $params = session_get_cookie_params();
        setcookie(
            session_name(), '', time() - 42000,
            $params['path'], $params['domain'] ?? '',
            $params['secure'], $params['httponly']
        );
    }
    session_destroy();
    ok(true);
}

function handle_session() {
    if (!empty($_SESSION['user'])) {
        ok(['user' => $_SESSION['user']]);
    }
    ok(null);
}

// ════════════════════════════════════════════════════════════
//   PROPERTIES
// ════════════════════════════════════════════════════════════
function handle_properties() {
    $db = db();
    $rows = db_query_all($db, 'SELECT * FROM properties ORDER BY featured DESC, id DESC');
    ok(array_map('prop_from_db', $rows));
}

function handle_save_property() {
    require_auth();
    $b = read_json_body();
    if (empty($b)) fail('Body vacío', 400);

    $db = db();
    $payload = prop_payload_to_db($b);
    $cols    = $payload['cols'];
    $vals    = $payload['vals'];
    $types   = $payload['types'];

    $id = isset($b['id']) ? (int)$b['id'] : 0;

    if ($id > 0) {
        // UPDATE
        $assigns = implode(', ', array_map(fn($c) => "`$c` = ?", $cols));
        $sql     = "UPDATE properties SET $assigns, updated_at = NOW() WHERE id = ?";
        $vals[]  = $id;
        $types  .= 'i';
        db_execute($db, $sql, $vals, $types);
    } else {
        // INSERT
        $colList      = implode(', ', array_map(fn($c) => "`$c`", $cols));
        $placeholders = implode(', ', array_fill(0, count($cols), '?'));
        $sql          = "INSERT INTO properties ($colList, created_at, updated_at)
                         VALUES ($placeholders, NOW(), NOW())";
        $id = db_execute($db, $sql, $vals, $types);
    }

    $row = db_query_one($db, 'SELECT * FROM properties WHERE id = ? LIMIT 1', [$id], 'i');
    if (!$row) fail('Propiedad no encontrada tras guardar', 500);
    ok(prop_from_db($row));
}

function handle_delete_property() {
    require_auth();
    $b  = read_json_body();
    $id = (int)($b['id'] ?? 0);
    if ($id <= 0) fail('id requerido', 400);

    $db = db();
    db_execute($db, 'DELETE FROM properties WHERE id = ?', [$id], 'i');
    ok(['id' => $id]);
}

function handle_duplicate_property() {
    require_auth();
    $b  = read_json_body();
    $id = (int)($b['id'] ?? 0);
    if ($id <= 0) fail('id requerido', 400);

    $db  = db();
    $row = db_query_one($db, 'SELECT * FROM properties WHERE id = ? LIMIT 1', [$id], 'i');
    if (!$row) fail('Propiedad origen no existe', 404);

    $orig = prop_from_db($row);
    $orig['title'] = ($orig['title'] ?? '') . ' (copia)';
    unset($orig['id']);

    $payload      = prop_payload_to_db($orig);
    $colList      = implode(', ', array_map(fn($c) => "`$c`", $payload['cols']));
    $placeholders = implode(', ', array_fill(0, count($payload['cols']), '?'));
    $sql = "INSERT INTO properties ($colList, created_at, updated_at)
            VALUES ($placeholders, NOW(), NOW())";
    $newId = db_execute($db, $sql, $payload['vals'], $payload['types']);

    $new = db_query_one($db, 'SELECT * FROM properties WHERE id = ? LIMIT 1', [$newId], 'i');
    ok(prop_from_db($new));
}

// ════════════════════════════════════════════════════════════
//   IMAGES
// ════════════════════════════════════════════════════════════
function handle_upload_image() {
    require_auth();

    if (empty($_FILES['file']) || ($_FILES['file']['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
        fail('Archivo no recibido', 400);
    }
    $f = $_FILES['file'];
    if ($f['size'] > MAX_UPLOAD) fail('Archivo demasiado grande (máx 10MB)', 413);

    $allowed = [
        'image/jpeg' => 'jpg',
        'image/png'  => 'png',
        'image/webp' => 'webp',
        'image/gif'  => 'gif',
    ];

    // Detect real MIME (don't trust client)
    $mime = '';
    if (function_exists('finfo_open')) {
        $finfo = finfo_open(FILEINFO_MIME_TYPE);
        $mime  = (string) finfo_file($finfo, $f['tmp_name']);
        finfo_close($finfo);
    } elseif (function_exists('mime_content_type')) {
        $mime = (string) mime_content_type($f['tmp_name']);
    }
    if (!isset($allowed[$mime])) fail('Tipo de imagen no permitido (' . $mime . ')', 415);

    if (!is_dir(UPLOAD_DIR)) {
        if (!@mkdir(UPLOAD_DIR, 0755, true) && !is_dir(UPLOAD_DIR)) {
            fail('No se pudo crear directorio de uploads', 500);
        }
    }

    $ext  = $allowed[$mime];
    $name = bin2hex(random_bytes(12)) . '_' . time() . '.' . $ext;
    $dest = UPLOAD_DIR . $name;

    if (!move_uploaded_file($f['tmp_name'], $dest)) fail('Error al mover archivo', 500);
    @chmod($dest, 0644);

    $url = UPLOAD_URL . $name;
    ok(['url' => $url]);
}

function handle_delete_image() {
    require_auth();
    $b   = read_json_body();
    $url = trim((string)($b['url'] ?? ''));
    if ($url === '') fail('url requerido', 400);

    // Only allow deleting URLs inside our upload dir
    $path = parse_url($url, PHP_URL_PATH);
    if ($path === null || $path === false) $path = $url;
    if (strpos($path, UPLOAD_URL) !== 0) fail('URL no permitida', 403);

    $name = basename($path);
    if ($name === '' || $name === '.' || $name === '..') fail('Nombre inválido', 400);

    $real = realpath(UPLOAD_DIR);
    if ($real === false) fail('Directorio de uploads no existe', 500);

    $candidate = UPLOAD_DIR . $name;
    $canonical = realpath($candidate);
    if ($canonical === false || strpos($canonical, $real) !== 0) {
        fail('Ruta no permitida', 403);
    }

    if (is_file($canonical)) @unlink($canonical);
    ok(['deleted' => $name]);
}

// ════════════════════════════════════════════════════════════
//   CONTACT MESSAGES
// ════════════════════════════════════════════════════════════
function handle_save_contact() {
    $b = read_json_body();

    // Honeypot — pretend success
    if (!empty($b['website'])) {
        ok(['ok' => true]);
    }

    $name      = trim((string)($b['name']      ?? ''));
    $email     = trim((string)($b['email']     ?? ''));
    $phone     = trim((string)($b['phone']     ?? ''));
    $city      = trim((string)($b['city']      ?? ''));
    $category  = trim((string)($b['category']  ?? ''));
    $oper      = trim((string)($b['operation'] ?? ''));
    $propTitle = trim((string)($b['property']  ?? ''));
    $propUrl   = trim((string)($b['url']       ?? ''));
    $message   = trim((string)($b['message']   ?? ''));

    if ($name === '' || $email === '' || $message === '') {
        fail('Nombre, email y mensaje son requeridos', 400);
    }
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) fail('Email inválido', 400);

    $ip = $_SERVER['HTTP_X_FORWARDED_FOR'] ?? $_SERVER['REMOTE_ADDR'] ?? '';
    if (strpos($ip, ',') !== false) $ip = trim(explode(',', $ip)[0]);

    $db = db();

    // Rate limit: same IP can only post once every RATE_WINDOW seconds
    if ($ip !== '') {
        $recent = db_query_one(
            $db,
            'SELECT id FROM contact_messages
             WHERE ip = ? AND created_at > (NOW() - INTERVAL ? SECOND)
             ORDER BY id DESC LIMIT 1',
            [$ip, RATE_WINDOW],
            'si'
        );
        if ($recent) fail('Espera unos segundos antes de enviar otro mensaje', 429);
    }

    db_execute(
        $db,
        'INSERT INTO contact_messages
         (name, email, phone, city, category, operation, property_title, property_url, message, ip, created_at)
         VALUES (?,?,?,?,?,?,?,?,?,?, NOW())',
        [$name, $email, $phone, $city, $category, $oper, $propTitle, $propUrl, $message, $ip],
        'ssssssssss'
    );

    // Send email notification (best-effort, don't fail if mail() fails)
    @send_contact_email([
        'name'      => $name,
        'email'     => $email,
        'phone'     => $phone,
        'city'      => $city,
        'category'  => $category,
        'operation' => $oper,
        'property'  => $propTitle,
        'url'       => $propUrl,
        'message'   => $message,
        'ip'        => $ip,
    ]);

    ok(['ok' => true]);
}

function send_contact_email(array $d): bool {
    $to      = CONTACT_EMAIL;
    $subject = '[GPRB] Nuevo mensaje de contacto - ' . ($d['name'] ?: 'sin nombre');

    $bodyLines = [
        'Nuevo mensaje desde gprb.cl',
        '----------------------------',
        'Nombre:    ' . $d['name'],
        'Email:     ' . $d['email'],
        'Teléfono:  ' . $d['phone'],
        'Ciudad:    ' . $d['city'],
        'Categoría: ' . $d['category'],
        'Operación: ' . $d['operation'],
        'Propiedad: ' . $d['property'],
        'URL:       ' . $d['url'],
        '',
        'Mensaje:',
        $d['message'],
        '',
        '----------------------------',
        'IP: ' . $d['ip'],
        'Fecha: ' . date('Y-m-d H:i:s'),
    ];
    $body = implode("\r\n", $bodyLines);

    $fromEmail = 'no-reply@gprb.cl';
    $replyTo   = filter_var($d['email'], FILTER_VALIDATE_EMAIL) ? $d['email'] : $fromEmail;

    $headers = [
        'From: GPRB Web <' . $fromEmail . '>',
        'Reply-To: ' . $replyTo,
        'X-Mailer: PHP/' . phpversion(),
        'MIME-Version: 1.0',
        'Content-Type: text/plain; charset=UTF-8',
    ];

    $encodedSubject = '=?UTF-8?B?' . base64_encode($subject) . '?=';
    return @mail($to, $encodedSubject, $body, implode("\r\n", $headers));
}

function handle_contacts() {
    require_auth();
    $db = db();
    $rows = db_query_all(
        $db,
        'SELECT id, name, email, phone, city, category, operation,
                property_title, property_url, message, ip, created_at
         FROM contact_messages ORDER BY id DESC LIMIT 500'
    );
    $out = array_map(function ($r) {
        return [
            'id'        => (int)$r['id'],
            'name'      => $r['name'],
            'email'     => $r['email'],
            'phone'     => $r['phone'],
            'city'      => $r['city'],
            'category'  => $r['category'],
            'operation' => $r['operation'],
            'property'  => $r['property_title'],
            'url'       => $r['property_url'],
            'message'   => $r['message'],
            'ip'        => $r['ip'],
            'createdAt' => $r['created_at'],
        ];
    }, $rows);
    ok($out);
}

// ════════════════════════════════════════════════════════════
//   SLIDES
// ════════════════════════════════════════════════════════════
function handle_slides() {
    $db = db();
    $rows = db_query_all(
        $db,
        'SELECT id, bg_url, tag, title, subtitle, sort_order, active, created_at
         FROM slider_slides WHERE active = 1
         ORDER BY sort_order ASC, id ASC'
    );
    ok(array_map('slide_from_db', $rows));
}

function handle_save_slides() {
    require_auth();
    $b = read_json_body();
    $slides = is_array($b['slides'] ?? null) ? $b['slides'] : [];

    $db = db();
    $db->begin_transaction();
    try {
        $db->query('DELETE FROM slider_slides');
        $i = 1;
        foreach ($slides as $s) {
            $bgUrl    = as_string_or_null($s['bgUrl']    ?? null);
            $tag      = as_string_or_null($s['tag']      ?? null);
            $title    = as_string_or_null($s['title']    ?? null);
            $subtitle = as_string_or_null($s['subtitle'] ?? null);
            $sort     = isset($s['sortOrder']) ? (int)$s['sortOrder'] : $i;
            db_execute(
                $db,
                'INSERT INTO slider_slides (bg_url, tag, title, subtitle, sort_order, active, created_at)
                 VALUES (?,?,?,?,?,1, NOW())',
                [$bgUrl, $tag, $title, $subtitle, $sort],
                'ssssi'
            );
            $i++;
        }
        $db->commit();
    } catch (Throwable $e) {
        $db->rollback();
        throw $e;
    }

    $rows = db_query_all(
        $db,
        'SELECT id, bg_url, tag, title, subtitle, sort_order, active, created_at
         FROM slider_slides WHERE active = 1
         ORDER BY sort_order ASC, id ASC'
    );
    ok(array_map('slide_from_db', $rows));
}
