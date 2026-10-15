<?php
// ===== Cartera R&R — API (PHP 8 + MySQL PDO) =====
require __DIR__ . '/config.php';
require __DIR__ . '/import-lib.php';
date_default_timezone_set('America/Bogota');
header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');

function out($data, $code = 200) { http_response_code($code); echo json_encode($data, JSON_UNESCAPED_UNICODE); exit; }
function fail($msg, $code = 400) { out(['ok' => false, 'error' => $msg], $code); }

try {
  $pdo = new PDO('mysql:host=' . DB_HOST . ';dbname=' . DB_NAME . ';charset=utf8mb4', DB_USER, DB_PASS,
    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
} catch (Exception $e) { fail('No hay conexión a la base de datos. Revisa config.php', 500); }

$action = $_GET['action'] ?? '';
$body = json_decode(file_get_contents('php://input'), true) ?: [];

// ---------- Tokens ----------
function make_token($uid) {
  $exp = time() + 90 * 86400;
  $p = $uid . '.' . $exp;
  return base64_encode($p . '.' . hash_hmac('sha256', $p, SECRET_KEY));
}
function check_token($t) {
  $parts = explode('.', base64_decode($t ?: ''), 3);
  if (count($parts) !== 3) return null;
  [$uid, $exp, $sig] = $parts;
  if (!hash_equals(hash_hmac('sha256', "$uid.$exp", SECRET_KEY), $sig)) return null;
  if ((int)$exp < time()) return null;
  return (int)$uid;
}
function auth($pdo) {
  $t = $_SERVER['HTTP_X_AUTH'] ?? ($_GET['token'] ?? '');
  $uid = check_token($t);
  if (!$uid) fail('Sesión inválida o vencida', 401);
  $u = $pdo->prepare('SELECT id,nombre,email,rol,empresa,activo FROM users WHERE id=?');
  $u->execute([$uid]);
  $user = $u->fetch();
  if (!$user || !$user['activo']) fail('Usuario inactivo', 401);
  return $user;
}
function can_emp($user, $emp) { return $user['rol'] === 'admin' || $user['empresa'] === 'ALL' || $user['empresa'] === $emp; }
function emp_filter($user) { // lista de empresas visibles
  return ($user['rol'] === 'admin' || $user['empresa'] === 'ALL') ? ['SGX', 'SDC'] : [$user['empresa']];
}

// =====================================================================
switch ($action) {

case 'login': {
  $email = strtolower(trim($body['email'] ?? ''));
  $pass = $body['pass'] ?? '';
  if (!$email || !$pass) fail('Escribe correo y contraseña');
  // primer arranque: crea el admin de config.php si no hay usuarios
  if (!(int)$pdo->query('SELECT COUNT(*) c FROM users')->fetch()['c']) {
    $pdo->prepare("INSERT INTO users (nombre,email,pass_hash,rol,empresa) VALUES (?,?,?,'admin','ALL')")
        ->execute([ADMIN_NOMBRE, strtolower(ADMIN_EMAIL), password_hash(ADMIN_PASS, PASSWORD_DEFAULT)]);
  }
  $q = $pdo->prepare('SELECT * FROM users WHERE email=? AND activo=1');
  $q->execute([$email]);
  $u = $q->fetch();
  if (!$u || !password_verify($pass, $u['pass_hash'])) fail('Correo o contraseña incorrectos', 401);
  out(['ok' => true, 'token' => make_token($u['id']),
       'user' => ['id' => $u['id'], 'nombre' => $u['nombre'], 'email' => $u['email'], 'rol' => $u['rol'], 'empresa' => $u['empresa']]]);
}

case 'state': {
  $user = auth($pdo);
  $emps = emp_filter($user);
  $in = implode(',', array_fill(0, count($emps), '?'));
  $cl = $pdo->prepare(
    "SELECT c.*, u.nombre gestor_nombre,
            COALESCE(SUM(i.saldo),0) saldo_total,
            COALESCE(SUM(CASE WHEN i.dias>0 AND i.saldo>0 THEN i.saldo ELSE 0 END),0) vencido,
            COALESCE(SUM(CASE WHEN i.dias<=0 AND i.saldo>0 THEN i.saldo ELSE 0 END),0) sin_vencer,
            COALESCE(MAX(CASE WHEN i.saldo>0 THEN i.dias ELSE NULL END), 0) max_dias,
            COUNT(CASE WHEN i.saldo>0 THEN i.id ELSE NULL END) num_facturas,
            COALESCE(SUM(CASE WHEN i.dias BETWEEN 1 AND 30 AND i.saldo>0 THEN i.saldo ELSE 0 END),0) b30,
            COALESCE(SUM(CASE WHEN i.dias BETWEEN 31 AND 60 AND i.saldo>0 THEN i.saldo ELSE 0 END),0) b60,
            COALESCE(SUM(CASE WHEN i.dias BETWEEN 61 AND 90 AND i.saldo>0 THEN i.saldo ELSE 0 END),0) b90,
            COALESCE(SUM(CASE WHEN i.dias>90 AND i.saldo>0 THEN i.saldo ELSE 0 END),0) b90m,
            (SELECT MAX(a.created_at) FROM actions a WHERE a.client_id=c.id AND a.tipo!='sistema') ultima_accion,
            (SELECT a2.compromiso_fecha FROM actions a2 WHERE a2.client_id=c.id AND a2.compromiso_fecha IS NOT NULL ORDER BY a2.id DESC LIMIT 1) prox_compromiso,
            (SELECT a3.compromiso_valor FROM actions a3 WHERE a3.client_id=c.id AND a3.compromiso_fecha IS NOT NULL ORDER BY a3.id DESC LIMIT 1) compromiso_valor
     FROM clients c
     LEFT JOIN users u ON u.id=c.gestor_id
     LEFT JOIN invoices i ON i.client_id=c.id AND i.activa=1
     WHERE c.empresa IN ($in)
     GROUP BY c.id ORDER BY vencido DESC, saldo_total DESC");
  $cl->execute($emps);
  $ups = $pdo->prepare("SELECT * FROM uploads WHERE empresa IN ($in) ORDER BY id DESC LIMIT 12");
  $ups->execute($emps);
  $usr = [];
  if ($user['rol'] === 'admin') $usr = $pdo->query('SELECT id,nombre,email,rol,empresa,activo FROM users ORDER BY nombre')->fetchAll();
  else { $g = $pdo->query("SELECT id,nombre,email,rol,empresa,activo FROM users WHERE activo=1 ORDER BY nombre")->fetchAll(); $usr = $g; }
  $acts = $pdo->prepare("SELECT a.*, c.nombre cliente, u.nombre usuario FROM actions a JOIN clients c ON c.id=a.client_id LEFT JOIN users u ON u.id=a.user_id WHERE a.empresa IN ($in) ORDER BY a.id DESC LIMIT 60");
  $acts->execute($emps);
  $sync = ['configurado' => is_file(__DIR__ . '/onedrive-config.php')];
  foreach ($emps as $e) $sync[$e] = json_decode(kv_get($pdo, 'od_last_' . $e) ?? 'null', true);
  $pays = $pdo->prepare("SELECT p.*, c.nombre cliente, c.nit FROM payments p JOIN clients c ON c.id=p.client_id WHERE p.empresa IN ($in) ORDER BY p.exportado ASC, p.id DESC LIMIT 100");
  $pays->execute($emps);
  out(['ok' => true, 'user' => $user, 'clients' => $cl->fetchAll(), 'uploads' => $ups->fetchAll(),
       'users' => $usr, 'recent' => $acts->fetchAll(), 'hoy' => date('Y-m-d'), 'sync' => $sync,
       'payments' => $pays->fetchAll()]);
}

case 'sync': {
  $user = auth($pdo);
  if (!is_file(__DIR__ . '/onedrive-config.php')) fail('La sincronización con OneDrive no está configurada (falta onedrive-config.php)');
  require __DIR__ . '/onedrive-config.php';
  require __DIR__ . '/onedrive-lib.php';
  $emp = $body['empresa'] ?? '';
  if (!in_array($emp, ['SGX', 'SDC'])) fail('Empresa inválida');
  if (!can_emp($user, $emp)) fail('No tienes acceso a esta empresa', 403);
  set_time_limit(180);
  out(['ok' => true, 'resultado' => sync_empresa($pdo, $emp, !empty($body['force']))]);
}

case 'client': {
  $user = auth($pdo);
  $id = (int)($_GET['id'] ?? 0);
  $c = $pdo->prepare('SELECT * FROM clients WHERE id=?'); $c->execute([$id]);
  $cli = $c->fetch();
  if (!$cli || !can_emp($user, $cli['empresa'])) fail('Cliente no encontrado', 404);
  $inv = $pdo->prepare('SELECT * FROM invoices WHERE client_id=? AND activa=1 ORDER BY fecha_venc'); $inv->execute([$id]);
  $act = $pdo->prepare('SELECT a.*, u.nombre usuario FROM actions a LEFT JOIN users u ON u.id=a.user_id WHERE a.client_id=? ORDER BY a.id DESC LIMIT 200'); $act->execute([$id]);
  out(['ok' => true, 'client' => $cli, 'invoices' => $inv->fetchAll(), 'actions' => $act->fetchAll()]);
}

case 'upload': {
  $user = auth($pdo);
  $emp = $body['empresa'] ?? '';
  if (!in_array($emp, ['SGX', 'SDC'])) fail('Empresa inválida');
  if (!can_emp($user, $emp)) fail('No tienes acceso a esta empresa', 403);
  $corte = norm_date($body['fecha_corte'] ?? '') ?: date('Y-m-d');
  $rows = $body['rows'] ?? [];
  if (!is_array($rows)) fail('El archivo no trae filas válidas');
  try {
    $res = import_rows($pdo, $emp, $corte, (string)($body['filename'] ?? ''), $rows, (int)$user['id']);
    out(['ok' => true] + $res);
  } catch (Exception $e) { fail('Error procesando el archivo: ' . $e->getMessage(), 500); }
}

case 'move': {
  $user = auth($pdo);
  $id = (int)($body['id'] ?? 0);
  $estado = $body['estado'] ?? '';
  if (!in_array($estado, array_merge(ESTADOS_AUTO, ESTADOS_MANUAL))) fail('Estado inválido');
  $c = $pdo->prepare('SELECT empresa,nombre,estado FROM clients WHERE id=?'); $c->execute([$id]);
  $cli = $c->fetch();
  if (!$cli || !can_emp($user, $cli['empresa'])) fail('Cliente no encontrado', 404);
  $manual = in_array($estado, ESTADOS_MANUAL) ? 1 : 0;
  $pdo->prepare('UPDATE clients SET estado=?, estado_manual=? WHERE id=?')->execute([$estado, $manual, $id]);
  $pdo->prepare("INSERT INTO actions (client_id,empresa,user_id,tipo,resultado,detalle) VALUES (?,?,?,'sistema','Cambio de estado',?)")
      ->execute([$id, $cli['empresa'], $user['id'], 'De «' . $cli['estado'] . '» a «' . $estado . '»']);
  out(['ok' => true]);
}

case 'save_client': {
  $user = auth($pdo);
  $id = (int)($body['id'] ?? 0);
  $c = $pdo->prepare('SELECT empresa FROM clients WHERE id=?'); $c->execute([$id]);
  $cli = $c->fetch();
  if (!$cli || !can_emp($user, $cli['empresa'])) fail('Cliente no encontrado', 404);
  $modo = in_array($body['modo_correo'] ?? '', ['auto', 'manual']) ? $body['modo_correo'] : 'manual';
  $gestor = (int)($body['gestor_id'] ?? 0) ?: null;
  $pdo->prepare('UPDATE clients SET email=?, telefono=?, contacto=?, gestor_id=?, modo_correo=?, notas=? WHERE id=?')
      ->execute([mb_substr(trim((string)($body['email'] ?? '')), 0, 200), mb_substr(trim((string)($body['telefono'] ?? '')), 0, 60),
                 mb_substr(trim((string)($body['contacto'] ?? '')), 0, 150), $gestor, $modo, (string)($body['notas'] ?? ''), $id]);
  out(['ok' => true]);
}

case 'add_action': {
  $user = auth($pdo);
  $id = (int)($body['client_id'] ?? 0);
  $tipo = $body['tipo'] ?? '';
  if (!in_array($tipo, ['llamada', 'correo', 'whatsapp', 'nota', 'compromiso'])) fail('Tipo inválido');
  $c = $pdo->prepare('SELECT empresa FROM clients WHERE id=?'); $c->execute([$id]);
  $cli = $c->fetch();
  if (!$cli || !can_emp($user, $cli['empresa'])) fail('Cliente no encontrado', 404);
  $cf = norm_date($body['compromiso_fecha'] ?? '');
  $cv = isset($body['compromiso_valor']) && $body['compromiso_valor'] !== '' ? (float)$body['compromiso_valor'] : null;
  $desc = isset($body['descuento']) && $body['descuento'] !== '' ? (float)$body['descuento'] : null;
  $pdo->prepare('INSERT INTO actions (client_id,empresa,user_id,tipo,resultado,detalle,compromiso_fecha,compromiso_valor,descuento) VALUES (?,?,?,?,?,?,?,?,?)')
      ->execute([$id, $cli['empresa'], $user['id'], $tipo, mb_substr(trim((string)($body['resultado'] ?? '')), 0, 150),
                 (string)($body['detalle'] ?? ''), $cf, $cv, $desc]);
  if ($tipo === 'compromiso' && $cf) {
    $pdo->prepare("UPDATE clients SET estado='compromiso', estado_manual=1 WHERE id=?")->execute([$id]);
  }
  out(['ok' => true]);
}

case 'open_invoices': {
  $user = auth($pdo);
  $emp = $_GET['empresa'] ?? '';
  if (!in_array($emp, ['SGX', 'SDC'])) fail('Empresa inválida');
  if (!can_emp($user, $emp)) fail('Sin acceso', 403);
  $q = $pdo->prepare("SELECT i.id, i.client_id, i.documento, i.saldo, i.dias, i.fecha_venc, i.codigo_sn
                      FROM invoices i WHERE i.empresa=? AND i.activa=1 AND i.saldo>0.01 ORDER BY i.client_id, i.fecha_venc");
  $q->execute([$emp]);
  out(['ok' => true, 'invoices' => $q->fetchAll()]);
}

case 'movs': {
  $user = auth($pdo);
  $emp = $_GET['empresa'] ?? '';
  if (!in_array($emp, ['SGX', 'SDC'])) fail('Empresa inválida');
  if (!can_emp($user, $emp)) fail('Sin acceso', 403);
  $q = $pdo->prepare("SELECT * FROM bank_movs WHERE empresa=? ORDER BY (estado='pendiente') DESC, fecha DESC, id DESC LIMIT 300");
  $q->execute([$emp]);
  out(['ok' => true, 'movs' => $q->fetchAll()]);
}

case 'movs_upload': {
  $user = auth($pdo);
  $emp = $body['empresa'] ?? '';
  if (!in_array($emp, ['SGX', 'SDC'])) fail('Empresa inválida');
  if (!can_emp($user, $emp)) fail('Sin acceso', 403);
  $rows = $body['rows'] ?? [];
  if (!is_array($rows) || !count($rows)) fail('El extracto no trae movimientos de ingreso');
  $banco = mb_substr((string)($body['banco'] ?? ''), 0, 80);
  $ins = $pdo->prepare('INSERT IGNORE INTO bank_movs (empresa,banco,fecha,descripcion,referencia,valor,hash,user_id) VALUES (?,?,?,?,?,?,?,?)');
  $n = 0; $dup = 0;
  foreach ($rows as $r) {
    $v = (float)($r['valor'] ?? 0);
    if ($v <= 0) continue;
    $fecha = norm_date($r['fecha'] ?? '') ?: date('Y-m-d');
    $desc = mb_substr(trim((string)($r['descripcion'] ?? '')), 0, 300);
    $ref = mb_substr(trim((string)($r['referencia'] ?? '')), 0, 120);
    $hash = md5($emp . '|' . $fecha . '|' . number_format($v, 2, '.', '') . '|' . $desc . '|' . $ref);
    $ins->execute([$emp, $banco, $fecha, $desc, $ref, $v, $hash, $user['id']]);
    $ins->rowCount() ? $n++ : $dup++;
  }
  out(['ok' => true, 'nuevos' => $n, 'duplicados' => $dup]);
}

case 'mov_update': {
  $user = auth($pdo);
  $id = (int)($body['id'] ?? 0);
  $estado = $body['estado'] ?? '';
  if (!in_array($estado, ['pendiente', 'ignorado'])) fail('Estado inválido');
  $q = $pdo->prepare('SELECT empresa, estado FROM bank_movs WHERE id=?'); $q->execute([$id]);
  $m = $q->fetch();
  if (!$m || !can_emp($user, $m['empresa'])) fail('Movimiento no encontrado', 404);
  if ($m['estado'] === 'conciliado') fail('Ya está conciliado');
  $pdo->prepare('UPDATE bank_movs SET estado=? WHERE id=?')->execute([$estado, $id]);
  out(['ok' => true]);
}

case 'contacts_upload': {
  $user = auth($pdo);
  $emp = $body['empresa'] ?? '';
  if (!in_array($emp, ['SGX', 'SDC'])) fail('Empresa inválida');
  if (!can_emp($user, $emp)) fail('Sin acceso', 403);
  $rows = $body['rows'] ?? [];
  if (!is_array($rows) || !count($rows)) fail('El archivo no trae filas con NIT');
  $over = !empty($body['overwrite']);
  // índice de clientes por NIT normalizado (solo dígitos, con y sin dígito de verificación)
  $idx = [];
  $q = $pdo->prepare('SELECT id, nit FROM clients WHERE empresa=?');
  $q->execute([$emp]);
  foreach ($q->fetchAll() as $c) {
    $d = preg_replace('/\D/', '', (string)$c['nit']);
    if ($d === '') continue;
    $idx[$d] = $c['id'];
    if (strlen($d) > 6) $idx[substr($d, 0, -1)] = $idx[substr($d, 0, -1)] ?? $c['id'];
  }
  $upd = $pdo->prepare('SELECT email, telefono, contacto FROM clients WHERE id=?');
  $set = $pdo->prepare('UPDATE clients SET email=?, telefono=?, contacto=? WHERE id=?');
  $n = 0; $sin = 0;
  foreach ($rows as $r) {
    $d = preg_replace('/\D/', '', (string)($r['nit'] ?? ''));
    if ($d === '') continue;
    $cid = $idx[$d] ?? ($idx[substr($d, 0, -1)] ?? null);
    if (!$cid) { $sin++; continue; }
    $upd->execute([$cid]);
    $cur = $upd->fetch();
    $email = mb_substr(trim((string)($r['email'] ?? '')), 0, 200);
    $tel = mb_substr(trim((string)($r['telefono'] ?? '')), 0, 60);
    $con = mb_substr(trim((string)($r['contacto'] ?? '')), 0, 150);
    $nv = [
      ($over && $email !== '') || $cur['email'] === '' ? ($email ?: $cur['email']) : $cur['email'],
      ($over && $tel !== '') || $cur['telefono'] === '' ? ($tel ?: $cur['telefono']) : $cur['telefono'],
      ($over && $con !== '') || $cur['contacto'] === '' ? ($con ?: $cur['contacto']) : $cur['contacto'],
    ];
    if ($nv[0] !== $cur['email'] || $nv[1] !== $cur['telefono'] || $nv[2] !== $cur['contacto']) {
      $set->execute([$nv[0], $nv[1], $nv[2], $cid]);
      $n++;
    }
  }
  out(['ok' => true, 'actualizados' => $n, 'sin_match' => $sin]);
}

case 'add_payment': {
  $user = auth($pdo);
  $id = (int)($body['client_id'] ?? 0);
  $c = $pdo->prepare('SELECT empresa,nombre FROM clients WHERE id=?'); $c->execute([$id]);
  $cli = $c->fetch();
  if (!$cli || !can_emp($user, $cli['empresa'])) fail('Cliente no encontrado', 404);
  $fact = $body['facturas'] ?? [];
  if (!is_array($fact) || !count($fact)) fail('Marca al menos una factura');
  $fecha = norm_date($body['fecha'] ?? '') ?: date('Y-m-d');
  $pdo->beginTransaction();
  try {
    $sel = $pdo->prepare('SELECT id,saldo,documento FROM invoices WHERE id=? AND client_id=? AND activa=1');
    $upd = $pdo->prepare('UPDATE invoices SET saldo=? WHERE id=?');
    $total = 0.0; $lineas = []; $detFact = [];
    foreach ($fact as $f) {
      $sel->execute([(int)($f['invoice_id'] ?? 0), $id]);
      $inv = $sel->fetch();
      if (!$inv) continue;
      $v = min((float)($f['valor'] ?? 0), (float)$inv['saldo']);
      if ($v <= 0) continue;
      $upd->execute([round((float)$inv['saldo'] - $v, 2), $inv['id']]);
      $total += $v;
      $detFact[] = ['documento' => $inv['documento'], 'codigo_sn' => (string)($f['codigo_sn'] ?? ''), 'valor' => $v];
      $lineas[] = $inv['documento'] . ' (' . number_format($v, 0, ',', '.') . ')';
    }
    if (!$total) { $pdo->rollBack(); fail('Ninguna factura válida con valor aplicado'); }
    $rf = (float)($body['retefuente'] ?? 0); $riva = (float)($body['reteiva'] ?? 0);
    $rica = (float)($body['reteica'] ?? 0); $desc = (float)($body['descuento'] ?? 0);
    $obs = mb_substr(trim((string)($body['obs'] ?? '')), 0, 500);
    $pdo->prepare('INSERT INTO payments (client_id,empresa,user_id,fecha,banco,referencia,total,retefuente,reteiva,reteica,descuento,facturas,obs) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?)')
        ->execute([$id, $cli['empresa'], $user['id'], $fecha, mb_substr((string)($body['banco'] ?? ''), 0, 80),
                   mb_substr((string)($body['referencia'] ?? ''), 0, 80), $total, $rf, $riva, $rica, $desc,
                   json_encode($detFact, JSON_UNESCAPED_UNICODE), $obs]);
    $det = 'Facturas: ' . implode(', ', $lineas) . ' · Banco: ' . ($body['banco'] ?? '') .
           ($body['referencia'] ? ' · Ref: ' . $body['referencia'] : '') .
           ' · Retenciones: ' . number_format($rf + $riva + $rica, 0, ',', '.') .
           ($desc > 0 ? ' · Descuento: ' . number_format($desc, 0, ',', '.') : '') .
           ($obs !== '' ? ' · ' . $obs : '');
    $payId = (int)$pdo->lastInsertId();
    $pdo->prepare("INSERT INTO actions (client_id,empresa,user_id,tipo,resultado,detalle,descuento) VALUES (?,?,?,'pago',?,?,?)")
        ->execute([$id, $cli['empresa'], $user['id'], 'Pago recibido por ' . number_format($total, 0, ',', '.'), $det, $desc ?: null]);
    $movId = (int)($body['mov_id'] ?? 0);
    if ($movId) $pdo->prepare("UPDATE bank_movs SET estado='conciliado', payment_id=? WHERE id=? AND empresa=? AND estado!='conciliado'")
                    ->execute([$payId, $movId, $cli['empresa']]);
    recompute_states($pdo, $cli['empresa']);
    $pdo->commit();
    out(['ok' => true, 'total' => $total]);
  } catch (Exception $e) { if ($pdo->inTransaction()) $pdo->rollBack(); fail('Error registrando el pago: ' . $e->getMessage(), 500); }
}

case 'export_paid': {
  $user = auth($pdo);
  $ids = array_filter(array_map('intval', (array)($body['ids'] ?? [])));
  if (!$ids) fail('Sin pagos');
  $in = implode(',', array_fill(0, count($ids), '?'));
  $q = $pdo->prepare("SELECT id, empresa FROM payments WHERE id IN ($in)");
  $q->execute($ids);
  $okIds = [];
  foreach ($q->fetchAll() as $p) if (can_emp($user, $p['empresa'])) $okIds[] = (int)$p['id'];
  if ($okIds) {
    $in2 = implode(',', array_fill(0, count($okIds), '?'));
    $pdo->prepare("UPDATE payments SET exportado=1 WHERE id IN ($in2)")->execute($okIds);
  }
  out(['ok' => true, 'marcados' => count($okIds)]);
}

case 'upload_doc': {
  $user = auth($pdo);
  $id = (int)($_POST['client_id'] ?? 0);
  $c = $pdo->prepare('SELECT empresa FROM clients WHERE id=?'); $c->execute([$id]);
  $cli = $c->fetch();
  if (!$cli || !can_emp($user, $cli['empresa'])) fail('Cliente no encontrado', 404);
  if (empty($_FILES['file']) || $_FILES['file']['error'] !== UPLOAD_ERR_OK) fail('No llegó el archivo');
  if ($_FILES['file']['size'] > MAX_DOC_MB * 1048576) fail('Archivo mayor a ' . MAX_DOC_MB . ' MB');
  if (!is_dir(DOCS_DIR)) { mkdir(DOCS_DIR, 0755, true); file_put_contents(DOCS_DIR . '/.htaccess', "Deny from all\n"); }
  $orig = $_FILES['file']['name'];
  $ext = strtolower(pathinfo($orig, PATHINFO_EXTENSION));
  if (in_array($ext, ['php', 'phtml', 'php3', 'php4', 'php5', 'cgi', 'pl', 'sh', 'exe', 'htaccess'])) fail('Tipo de archivo no permitido');
  $fname = bin2hex(random_bytes(16)) . ($ext ? '.' . $ext : '');
  if (!move_uploaded_file($_FILES['file']['tmp_name'], DOCS_DIR . '/' . $fname)) fail('No se pudo guardar', 500);
  $pdo->prepare("INSERT INTO actions (client_id,empresa,user_id,tipo,resultado,detalle,adjunto,adjunto_nombre) VALUES (?,?,?,'documento',?,?,?,?)")
      ->execute([$id, $cli['empresa'], $user['id'], mb_substr((string)($_POST['resultado'] ?? 'Documento de cobro'), 0, 150),
                 (string)($_POST['detalle'] ?? ''), $fname, mb_substr($orig, 0, 250)]);
  out(['ok' => true]);
}

case 'doc': {
  $user = auth($pdo);
  $f = basename($_GET['f'] ?? '');
  $a = $pdo->prepare('SELECT empresa, adjunto_nombre FROM actions WHERE adjunto=?'); $a->execute([$f]);
  $act = $a->fetch();
  if (!$act || !can_emp($user, $act['empresa'])) fail('No encontrado', 404);
  $path = DOCS_DIR . '/' . $f;
  if (!is_file($path)) fail('No encontrado', 404);
  header('Content-Type: application/octet-stream');
  header('Content-Disposition: attachment; filename="' . rawurlencode($act['adjunto_nombre'] ?: $f) . '"');
  header('Content-Length: ' . filesize($path));
  readfile($path);
  exit;
}

case 'user_save': {
  $user = auth($pdo);
  if ($user['rol'] !== 'admin') fail('Solo el administrador', 403);
  $id = (int)($body['id'] ?? 0);
  $nombre = trim((string)($body['nombre'] ?? ''));
  $email = strtolower(trim((string)($body['email'] ?? '')));
  $rol = in_array($body['rol'] ?? '', ['admin', 'gestor']) ? $body['rol'] : 'gestor';
  $emp = in_array($body['empresa'] ?? '', ['SGX', 'SDC', 'ALL']) ? $body['empresa'] : 'ALL';
  $activo = (int)($body['activo'] ?? 1) ? 1 : 0;
  $pass = (string)($body['pass'] ?? '');
  if (!$nombre || !filter_var($email, FILTER_VALIDATE_EMAIL)) fail('Nombre o correo inválido');
  if ($id) {
    $pdo->prepare('UPDATE users SET nombre=?, email=?, rol=?, empresa=?, activo=? WHERE id=?')->execute([$nombre, $email, $rol, $emp, $activo, $id]);
    if ($pass !== '') $pdo->prepare('UPDATE users SET pass_hash=? WHERE id=?')->execute([password_hash($pass, PASSWORD_DEFAULT), $id]);
  } else {
    if ($pass === '' || strlen($pass) < 8) fail('La contraseña debe tener mínimo 8 caracteres');
    $pdo->prepare('INSERT INTO users (nombre,email,pass_hash,rol,empresa,activo) VALUES (?,?,?,?,?,?)')
        ->execute([$nombre, $email, password_hash($pass, PASSWORD_DEFAULT), $rol, $emp, $activo]);
  }
  out(['ok' => true]);
}

case 'passwd': {
  $user = auth($pdo);
  $old = (string)($body['old'] ?? ''); $new = (string)($body['new'] ?? '');
  if (strlen($new) < 8) fail('La nueva contraseña debe tener mínimo 8 caracteres');
  $q = $pdo->prepare('SELECT pass_hash FROM users WHERE id=?'); $q->execute([$user['id']]);
  if (!password_verify($old, $q->fetch()['pass_hash'])) fail('La contraseña actual no coincide', 401);
  $pdo->prepare('UPDATE users SET pass_hash=? WHERE id=?')->execute([password_hash($new, PASSWORD_DEFAULT), $user['id']]);
  out(['ok' => true]);
}

default: fail('Acción desconocida', 404);
}
