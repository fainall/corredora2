<?php
// ============================================================
//  GPRB - Mail handler para formularios de contacto
//  Recibe POST JSON y envia correo a contacto@gprb.cl
//  Compatible con PHP 5.6+
// ============================================================

// Headers CORS y seguridad
header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: strict-origin-when-cross-origin');

// Permitir solo desde gprb.cl (mismo origen)
$allowed_origins = array(
  'https://gprb.cl',
  'https://www.gprb.cl'
);
$origin = isset($_SERVER['HTTP_ORIGIN']) ? $_SERVER['HTTP_ORIGIN'] : '';
if (in_array($origin, $allowed_origins, true)) {
  header("Access-Control-Allow-Origin: $origin");
  header('Access-Control-Allow-Methods: POST, OPTIONS');
  header('Access-Control-Allow-Headers: Content-Type');
  header('Access-Control-Max-Age: 600');
}

// Preflight
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
  http_response_code(204);
  exit;
}

// Solo POST
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
  http_response_code(405);
  echo json_encode(array('ok' => false, 'error' => 'Metodo no permitido'));
  exit;
}

// Validar Content-Type JSON
$content_type = isset($_SERVER['CONTENT_TYPE']) ? $_SERVER['CONTENT_TYPE'] : '';
if (strpos($content_type, 'application/json') === false) {
  http_response_code(415);
  echo json_encode(array('ok' => false, 'error' => 'Content-Type debe ser application/json'));
  exit;
}

// Validar Origin (anti CSRF)
if (!empty($origin) && !in_array($origin, $allowed_origins, true)) {
  http_response_code(403);
  echo json_encode(array('ok' => false, 'error' => 'Origen no permitido'));
  exit;
}

// Leer body
$raw = file_get_contents('php://input');
if (strlen($raw) > 50000) {
  http_response_code(413);
  echo json_encode(array('ok' => false, 'error' => 'Payload demasiado grande'));
  exit;
}
$data = json_decode($raw, true);
if (!is_array($data)) {
  http_response_code(400);
  echo json_encode(array('ok' => false, 'error' => 'JSON invalido'));
  exit;
}

// Sanitizar y extraer campos
function clean($v, $maxLen = 500) {
  if (!is_string($v)) return '';
  $v = trim($v);
  if (mb_strlen($v) > $maxLen) $v = mb_substr($v, 0, $maxLen);
  $v = preg_replace('/[\r\n\0]+/', ' ', $v);
  return $v;
}

$name           = clean(isset($data['name'])     ? $data['name']     : '', 100);
$email          = clean(isset($data['email'])    ? $data['email']    : '', 200);
$phone          = clean(isset($data['phone'])    ? $data['phone']    : '', 50);
$message        = clean(isset($data['message'])  ? $data['message']  : '', 5000);
$subject_input  = clean(isset($data['subject'])  ? $data['subject']  : '', 200);
$property_title = clean(isset($data['property']) ? $data['property'] : '', 200);
$property_url   = clean(isset($data['url'])      ? $data['url']      : '', 500);

// Honeypot anti-bot
$honeypot = clean(isset($data['website']) ? $data['website'] : '', 200);
if ($honeypot !== '') {
  echo json_encode(array('ok' => true));
  exit;
}

// Validaciones minimas
if ($name === '' || $email === '' || $message === '') {
  http_response_code(400);
  echo json_encode(array('ok' => false, 'error' => 'Faltan campos obligatorios (nombre, email, mensaje)'));
  exit;
}

if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
  http_response_code(400);
  echo json_encode(array('ok' => false, 'error' => 'Email invalido'));
  exit;
}

// Rate limiting basico por IP (1 envio cada 15 segundos)
$ip = isset($_SERVER['REMOTE_ADDR']) ? $_SERVER['REMOTE_ADDR'] : 'unknown';
$rate_file = sys_get_temp_dir() . '/gprb_mail_' . md5($ip);
if (file_exists($rate_file)) {
  $last = (int)file_get_contents($rate_file);
  if (time() - $last < 15) {
    http_response_code(429);
    echo json_encode(array('ok' => false, 'error' => 'Espera unos segundos antes de enviar otro mensaje'));
    exit;
  }
}
@file_put_contents($rate_file, time());

// Construir email
$to = 'contacto@gprb.cl';
if ($subject_input !== '') {
  $subject = '[Web GPRB] ' . $subject_input;
} elseif ($property_title !== '') {
  $subject = '[Web GPRB] Consulta sobre: ' . $property_title;
} else {
  $subject = '[Web GPRB] Nueva consulta';
}

$body  = "Nuevo mensaje recibido desde gprb.cl\n";
$body .= "==========================================\n\n";
$body .= "Nombre:    " . $name . "\n";
$body .= "Email:     " . $email . "\n";
if ($phone !== '')          $body .= "Telefono:  " . $phone . "\n";
if ($property_title !== '') $body .= "Propiedad: " . $property_title . "\n";
if ($property_url !== '')   $body .= "URL:       " . $property_url . "\n";
$body .= "\n--- Mensaje ---\n";
$body .= $message . "\n\n";
$body .= "==========================================\n";
$body .= "IP: " . $ip . "\n";
$body .= "Fecha: " . date('Y-m-d H:i:s') . "\n";

// Headers del email
$from_addr = 'no-reply@gprb.cl';
$reply_to = $email;
$display_name = '=?UTF-8?B?' . base64_encode($name) . '?=';

$headers  = "From: GPRB Web <" . $from_addr . ">\r\n";
$headers .= "Reply-To: " . $display_name . " <" . $reply_to . ">\r\n";
$headers .= "MIME-Version: 1.0\r\n";
$headers .= "Content-Type: text/plain; charset=UTF-8\r\n";
$headers .= "Content-Transfer-Encoding: 8bit\r\n";
$headers .= "X-Mailer: GPRB-Web-PHP\r\n";

// Codificar subject UTF-8
$subject_encoded = '=?UTF-8?B?' . base64_encode($subject) . '?=';

$ok = @mail($to, $subject_encoded, $body, $headers, "-f" . $from_addr);

if ($ok) {
  echo json_encode(array('ok' => true));
} else {
  http_response_code(500);
  echo json_encode(array('ok' => false, 'error' => 'No se pudo enviar el mensaje. Intenta mas tarde.'));
}
