<?php
// ===== Cron de sincronización OneDrive → Cartera R&R =====
// cPanel cron (cada hora):  php /home/USUARIO/RUTA_DEL_SITIO/cron-cartera.php
// o por web:                https://TU_SITIO/cron-cartera.php?k=CRON_KEY
// Solo importa cuando el archivo cambió en OneDrive (compara eTag). ?force=1 fuerza.

require __DIR__ . '/config.php';
date_default_timezone_set('America/Bogota');

if (!is_file(__DIR__ . '/onedrive-config.php')) { http_response_code(500); die("onedrive-config.php no existe\n"); }
require __DIR__ . '/onedrive-config.php';
require __DIR__ . '/onedrive-lib.php';

$cli = php_sapi_name() === 'cli';
if (!$cli && !hash_equals(CRON_KEY, $_GET['k'] ?? '')) { http_response_code(403); die("forbidden\n"); }

try {
  $pdo = new PDO('mysql:host=' . DB_HOST . ';dbname=' . DB_NAME . ';charset=utf8mb4', DB_USER, DB_PASS,
    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
} catch (Exception $e) { http_response_code(500); die("sin BD\n"); }

$force = !empty($_GET['force']);
$out = [];
foreach (array_keys(OD_FILES) as $emp) $out[] = sync_empresa($pdo, $emp, $force);

header('Content-Type: application/json; charset=utf-8');
echo json_encode(['ok' => true, 'ts' => date('Y-m-d H:i:s'), 'resultados' => $out], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT) . "\n";
