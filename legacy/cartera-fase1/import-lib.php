<?php
// ===== Cartera R&R — lógica compartida de importación =====
// Usada por api.php (carga manual y sync) y cron-cartera.php (sync automático).
require_once __DIR__ . '/xlsx-lib.php';

const ESTADOS_AUTO = ['al_dia', 'por_vencer', 'vencida', 'pago_recibido'];
const ESTADOS_MANUAL = ['en_gestion', 'compromiso', 'incumplido', 'juridico'];

function norm_date($v) {
  if (!$v) return null;
  $v = trim((string)$v);
  if (preg_match('/^\d{4}-\d{2}-\d{2}/', $v)) return substr($v, 0, 10);
  if (preg_match('#^(\d{1,2})/(\d{1,2})/(\d{4})#', $v, $m)) return sprintf('%04d-%02d-%02d', $m[3], $m[2], $m[1]);
  return null;
}

function recompute_states(PDO $pdo, string $emp): void {
  $rows = $pdo->prepare(
    "SELECT c.id, c.estado, c.estado_manual,
            COALESCE(SUM(i.saldo),0) saldo,
            COALESCE(SUM(CASE WHEN i.dias>0 AND i.saldo>0 THEN i.saldo ELSE 0 END),0) vencido,
            COALESCE(MAX(CASE WHEN i.saldo>0 THEN i.dias ELSE NULL END), -9999) max_dias
     FROM clients c
     LEFT JOIN invoices i ON i.client_id=c.id AND i.activa=1
     WHERE c.empresa=? GROUP BY c.id");
  $rows->execute([$emp]);
  $upd = $pdo->prepare('UPDATE clients SET estado=?, estado_manual=? WHERE id=?');
  $log = $pdo->prepare("INSERT INTO actions (client_id, empresa, tipo, resultado, detalle) VALUES (?,?, 'sistema', ?, ?)");
  foreach ($rows->fetchAll() as $c) {
    $saldo = (float)$c['saldo']; $venc = (float)$c['vencido']; $md = (int)$c['max_dias'];
    if ($saldo <= 0.01) {
      if ($c['estado'] !== 'pago_recibido' && $c['estado'] !== 'al_dia') {
        $upd->execute(['pago_recibido', 0, $c['id']]);
        $log->execute([$c['id'], $emp, 'Cartera en cero', 'El cliente no presenta saldo en el último corte cargado.']);
      } elseif ($c['estado_manual']) { $upd->execute([$c['estado'], 0, $c['id']]); }
      continue;
    }
    if ($c['estado_manual'] && in_array($c['estado'], ESTADOS_MANUAL)) continue;
    $auto = $venc > 0.01 ? 'vencida' : ($md >= -5 ? 'por_vencer' : 'al_dia');
    if ($c['estado'] !== $auto || $c['estado_manual']) $upd->execute([$auto, 0, $c['id']]);
  }
}

// Importa la foto completa de cartera de una empresa. Lanza Exception si falla.
// $rows: filas asociativas {codigo,nombre,nit,grupo,municipio,documento,referencia,
//        vendedor,condicion,fecha_venc,fecha_doc,limite,importe,saldo}
function import_rows(PDO $pdo, string $emp, string $corte, string $filename, array $rows, ?int $userId = null): array {
  if (!in_array($emp, ['SGX', 'SDC'])) throw new Exception('Empresa inválida');
  if (!count($rows)) throw new Exception('El archivo no trae filas válidas');
  $pdo->beginTransaction();
  try {
    $pdo->prepare('UPDATE invoices SET activa=0 WHERE empresa=? AND activa=1')->execute([$emp]);
    $pdo->prepare('INSERT INTO uploads (empresa, fecha_corte, filename, user_id) VALUES (?,?,?,?)')
        ->execute([$emp, $corte, mb_substr($filename, 0, 250), $userId]);
    $upId = (int)$pdo->lastInsertId();
    $selC = $pdo->prepare('SELECT id FROM clients WHERE empresa=? AND nit=?');
    $insC = $pdo->prepare('INSERT INTO clients (empresa,nit,nombre,grupo,municipio,limite_credito) VALUES (?,?,?,?,?,?)');
    $updC = $pdo->prepare('UPDATE clients SET nombre=?, grupo=?, municipio=?, limite_credito=? WHERE id=?');
    $insI = $pdo->prepare('INSERT INTO invoices (empresa,client_id,upload_id,codigo_sn,documento,referencia,vendedor,condicion_pago,fecha_doc,fecha_venc,importe_original,saldo,dias,activa) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,1)');
    $n = 0; $tot = 0.0; $totV = 0.0; $cache = [];
    foreach ($rows as $r) {
      $nit = trim((string)($r['nit'] ?? ''));
      $doc = trim((string)($r['documento'] ?? ''));
      if ($nit === '' || $doc === '') continue;
      $nombre = mb_substr(trim((string)($r['nombre'] ?? '')), 0, 200);
      $venc = norm_date($r['fecha_venc'] ?? '');
      $saldo = (float)($r['saldo'] ?? 0);
      $dias = $venc ? (int)floor((strtotime($corte) - strtotime($venc)) / 86400) : 0;
      if (!isset($cache[$nit])) {
        $selC->execute([$emp, $nit]);
        $cid = $selC->fetch()['id'] ?? null;
        if ($cid) $updC->execute([$nombre, mb_substr((string)($r['grupo'] ?? ''), 0, 150), mb_substr((string)($r['municipio'] ?? ''), 0, 120), (float)($r['limite'] ?? 0), $cid]);
        else { $insC->execute([$emp, $nit, $nombre, mb_substr((string)($r['grupo'] ?? ''), 0, 150), mb_substr((string)($r['municipio'] ?? ''), 0, 120), (float)($r['limite'] ?? 0)]); $cid = (int)$pdo->lastInsertId(); }
        $cache[$nit] = $cid;
      }
      $insI->execute([$emp, $cache[$nit], $upId, mb_substr((string)($r['codigo'] ?? ''), 0, 30), mb_substr($doc, 0, 40),
        mb_substr((string)($r['referencia'] ?? ''), 0, 100), mb_substr((string)($r['vendedor'] ?? ''), 0, 150),
        mb_substr((string)($r['condicion'] ?? ''), 0, 60), norm_date($r['fecha_doc'] ?? ''), $venc,
        (float)($r['importe'] ?? 0), $saldo, $dias]);
      $n++; $tot += $saldo; if ($dias > 0 && $saldo > 0) $totV += $saldo;
    }
    if (!$n) { $pdo->rollBack(); throw new Exception('Ninguna fila tenía NIT y número de factura'); }
    $pdo->prepare('UPDATE uploads SET num_facturas=?, total_saldo=?, total_vencido=? WHERE id=?')->execute([$n, $tot, $totV, $upId]);
    recompute_states($pdo, $emp);
    $pdo->commit();
    return ['upload_id' => $upId, 'facturas' => $n, 'total' => $tot, 'vencido' => $totV];
  } catch (Exception $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    throw $e;
  }
}

// Convierte la matriz cruda del xlsx (formato "Cartera del día" del ERP, 34 col)
// en filas asociativas para import_rows. Detecta la fila de encabezados por nombre.
function map_headers(array $grid): array {
  $hi = -1; $H = [];
  foreach ($grid as $i => $row) {
    $vals = array_map(fn($c) => trim((string)$c), $row);
    if (in_array('Código SN', $vals) && in_array('Nit', $vals)) { $hi = $i; $H = $vals; break; }
  }
  if ($hi < 0) throw new Exception('No se encontró la fila de encabezados («Código SN», «Nit»…)');
  $col = fn($n) => array_search($n, $H);
  $M = ['codigo' => $col('Código SN'), 'nombre' => $col('Nombre SN'), 'nit' => $col('Nit'), 'grupo' => $col('Grupo'),
        'municipio' => $col('Municipio'), 'documento' => $col('Documento'), 'referencia' => $col('No. referencia'),
        'vendedor' => $col('Empleado ventas / compras'), 'condicion' => $col('Condicion pago documento'),
        'fecha_venc' => $col('Fecha vencimiento'), 'fecha_doc' => $col('Fecha documento'),
        'limite' => $col('Límite crédito'), 'importe' => $col('Importe original'), 'saldo' => $col('Saldo')];
  if ($M['nit'] === false || $M['documento'] === false || $M['saldo'] === false)
    throw new Exception('Faltan columnas obligatorias (Nit / Documento / Saldo)');
  $get = function ($row, $k) use ($M) { return $M[$k] === false ? '' : ($row[$M[$k]] ?? ''); };
  $dt = function ($v) { return is_numeric($v) ? (xlsx_serial_date($v) ?? '') : (string)$v; };
  $rows = [];
  for ($i = $hi + 1; $i < count($grid); $i++) {
    $r = $grid[$i];
    $nit = trim((string)$get($r, 'nit')); $doc = trim((string)$get($r, 'documento'));
    if ($nit === '' || $doc === '') continue;
    // Documento puede venir numérico (9280.0) → sin decimales
    if (is_numeric($doc)) $doc = rtrim(rtrim(sprintf('%.2f', (float)$doc), '0'), '.');
    $rows[] = [
      'codigo' => (string)$get($r, 'codigo'), 'nombre' => (string)$get($r, 'nombre'), 'nit' => $nit,
      'grupo' => (string)$get($r, 'grupo'), 'municipio' => (string)$get($r, 'municipio'), 'documento' => $doc,
      'referencia' => (string)$get($r, 'referencia'), 'vendedor' => (string)$get($r, 'vendedor'),
      'condicion' => (string)$get($r, 'condicion'), 'fecha_venc' => $dt($get($r, 'fecha_venc')),
      'fecha_doc' => $dt($get($r, 'fecha_doc')), 'limite' => (float)$get($r, 'limite'),
      'importe' => (float)$get($r, 'importe'), 'saldo' => (float)$get($r, 'saldo')];
  }
  return $rows;
}

// key-value simple (tabla kv) para estado de sincronización
function kv_get(PDO $pdo, string $k): ?string {
  try { $q = $pdo->prepare('SELECT v FROM kv WHERE k=?'); $q->execute([$k]); $r = $q->fetch(); return $r ? $r['v'] : null; }
  catch (Exception $e) { return null; }
}
function kv_set(PDO $pdo, string $k, string $v): void {
  $pdo->prepare('INSERT INTO kv (k,v) VALUES (?,?) ON DUPLICATE KEY UPDATE v=VALUES(v)')->execute([$k, $v]);
}
