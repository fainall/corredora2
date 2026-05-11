<?php
// ============================================================
// GPRB - Setup script (one-shot)
// Visit once: https://gprb.cl/setup.php
// Creates clean schema. Delete this file after.
// ============================================================

header('Content-Type: text/plain; charset=utf-8');

mysqli_report(MYSQLI_REPORT_OFF);
$db = @new mysqli('localhost', 'gprbcl_usr', 'Gprb2026!Db#Strong', 'gprbcl_db');
if ($db->connect_error) {
    die("Connection error: " . $db->connect_error);
}
$db->set_charset('utf8mb4');

$queries = [
"SET FOREIGN_KEY_CHECKS=0",

"DROP TABLE IF EXISTS admin_users",
"DROP TABLE IF EXISTS properties",
"DROP TABLE IF EXISTS slider_slides",
"DROP TABLE IF EXISTS contact_messages",

"CREATE TABLE admin_users (
  id INT(11) AUTO_INCREMENT PRIMARY KEY,
  email VARCHAR(200) NOT NULL UNIQUE,
  password_hash VARCHAR(255) NOT NULL,
  name VARCHAR(200) DEFAULT NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",

"CREATE TABLE properties (
  id INT(11) AUTO_INCREMENT PRIMARY KEY,
  title VARCHAR(255) NOT NULL,
  subtitle VARCHAR(255) DEFAULT NULL,
  type VARCHAR(50) NOT NULL,
  status VARCHAR(50) NOT NULL,
  price DECIMAL(15,2) DEFAULT 0,
  price_unit VARCHAR(10) DEFAULT 'CLP',
  price_with_iva TINYINT(1) DEFAULT 1,
  gastos_comunes DECIMAL(15,2) DEFAULT NULL,
  gastos_comunes_unit VARCHAR(10) DEFAULT 'UF',
  gastos_comunes_with_iva TINYINT(1) DEFAULT 0,
  location VARCHAR(255) DEFAULT NULL,
  address VARCHAR(500) DEFAULT NULL,
  area DECIMAL(10,2) DEFAULT NULL,
  area_bodega DECIMAL(10,2) DEFAULT NULL,
  area_oficina DECIMAL(10,2) DEFAULT NULL,
  area_altillo DECIMAL(10,2) DEFAULT NULL,
  usable_area DECIMAL(10,2) DEFAULT NULL,
  bathrooms INT(11) DEFAULT 0,
  parking INT(11) DEFAULT 0,
  portones INT(11) DEFAULT NULL,
  andenes INT(11) DEFAULT NULL,
  cumbrera DECIMAL(10,2) DEFAULT NULL,
  private_rooms INT(11) DEFAULT 0,
  age INT(11) DEFAULT NULL,
  height DECIMAL(10,2) DEFAULT NULL,
  width DECIMAL(10,2) DEFAULT NULL,
  floor_support DECIMAL(10,2) DEFAULT NULL,
  platforms INT(11) DEFAULT NULL,
  price_per_m2 DECIMAL(10,4) DEFAULT NULL,
  property_code VARCHAR(50) DEFAULT NULL,
  portal_code VARCHAR(50) DEFAULT NULL,
  image VARCHAR(1000) DEFAULT NULL,
  gallery TEXT DEFAULT NULL,
  description TEXT DEFAULT NULL,
  video_url VARCHAR(500) DEFAULT NULL,
  services TEXT DEFAULT NULL,
  amenities TEXT DEFAULT NULL,
  security TEXT DEFAULT NULL,
  featured TINYINT(1) DEFAULT 0,
  published TINYINT(1) DEFAULT 1,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  INDEX idx_status (status),
  INDEX idx_published (published),
  INDEX idx_featured (featured)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",

"CREATE TABLE slider_slides (
  id INT(11) AUTO_INCREMENT PRIMARY KEY,
  bg_url VARCHAR(1000) NOT NULL,
  tag VARCHAR(100) DEFAULT NULL,
  title VARCHAR(255) DEFAULT NULL,
  subtitle VARCHAR(500) DEFAULT NULL,
  sort_order INT(11) DEFAULT 0,
  active TINYINT(1) DEFAULT 1,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",

"CREATE TABLE contact_messages (
  id INT(11) AUTO_INCREMENT PRIMARY KEY,
  name VARCHAR(200) NOT NULL,
  email VARCHAR(200) NOT NULL,
  phone VARCHAR(50) DEFAULT NULL,
  city VARCHAR(100) DEFAULT NULL,
  category VARCHAR(100) DEFAULT NULL,
  operation VARCHAR(50) DEFAULT NULL,
  property_title VARCHAR(255) DEFAULT NULL,
  property_url VARCHAR(500) DEFAULT NULL,
  message TEXT NOT NULL,
  ip VARCHAR(45) DEFAULT NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",

"SET FOREIGN_KEY_CHECKS=1",
];

echo "GPRB Setup\n==========\n\n";
foreach ($queries as $i => $q) {
    $short = substr(preg_replace('/\s+/', ' ', $q), 0, 80);
    if ($db->query($q)) {
        echo "[OK] $short\n";
    } else {
        echo "[ERR] $short\n     " . $db->error . "\n";
    }
}

// Seed admin user
$hash = password_hash('admin123', PASSWORD_BCRYPT);
$stmt = $db->prepare("INSERT INTO admin_users (email, password_hash, name) VALUES (?, ?, ?)");
$email = 'admin@gprb.cl';
$name = 'Admin GPRB';
$stmt->bind_param('sss', $email, $hash, $name);
if ($stmt->execute()) {
    echo "\n[OK] Admin user created: admin@gprb.cl / admin123\n";
} else {
    echo "\n[ERR] Admin seed: " . $stmt->error . "\n";
}

echo "\nDONE. Now delete this file (setup.php).\n";
