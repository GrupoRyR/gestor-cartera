<?php
// ===== Cliente Microsoft Graph para leer los Excel de cartera en OneDrive =====
require_once __DIR__ . '/xlsx-lib.php';
require_once __DIR__ . '/import-lib.php';

function od_configured(): bool {
  return is_file(__DIR__ . '/onedrive-config.php');
}

function od_http(string $method, string $url, array $headers = [], ?string $body = null): array {
  $ch = curl_init($url);
  curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true, CURLOPT_CUSTOMREQUEST => $method,
    CURLOPT_HTTPHEADER => $headers, CURLOPT_TIMEOUT => 60, CURLOPT_FOLLOWLOCATION => true,
  ]);
  if ($body !== null) curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
  $resp = curl_exec($ch);
  $code = curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
  $err = curl_error($ch);
  curl_close($ch);
  if ($resp === false) throw new Exception('Graph sin respuesta: ' . $err);
  return [$code, $resp];
}

function od_token(): string {
  static $tok = null;
  if ($tok) return $tok;
  [$code, $resp] = od_http('POST', 'https://login.microsoftonline.com/' . OD_TENANT_ID . '/oauth2/v2.0/token',
    ['Content-Type: application/x-www-form-urlencoded'],
    http_build_query(['client_id' => OD_CLIENT_ID, 'client_secret' => OD_CLIENT_SECRET,
                      'scope' => 'https://graph.microsoft.com/.default', 'grant_type' => 'client_credentials']));
  $d = json_decode($resp, true);
  if ($code !== 200 || empty($d['access_token']))
    throw new Exception('Token Graph falló (' . $code . '): ' . ($d['error_description'] ?? $resp));
  return $tok = $d['access_token'];
}

function od_item_url(string $path): string {
  $enc = implode('/', array_map('rawurlencode', explode('/', $path)));
  return 'https://graph.microsoft.com/v1.0/users/' . rawurlencode(OD_UPN) . '/drive/root:/' . $enc;
}

function od_file_meta(string $path): array {
  [$code, $resp] = od_http('GET', od_item_url($path) . '?$select=id,name,eTag,lastModifiedDateTime,size',
    ['Authorization: Bearer ' . od_token()]);
  $d = json_decode($resp, true);
  if ($code !== 200) throw new Exception('No se pudo leer el archivo en OneDrive (' . $code . '): ' . ($d['error']['message'] ?? ''));
  return $d;
}

function od_download(string $path, string $dest): void {
  [$code, $resp] = od_http('GET', od_item_url($path) . ':/content', ['Authorization: Bearer ' . od_token()]);
  if ($code !== 200) throw new Exception('Descarga desde OneDrive falló (' . $code . ')');
  if (file_put_contents($dest, $resp) === false) throw new Exception('No se pudo guardar el archivo temporal');
}

// Sincroniza una empresa. Devuelve ['status'=>'sin_cambios'|'importado'|'error', ...]
function sync_empresa(PDO $pdo, string $emp, bool $force = false): array {
  $path = OD_FILES[$emp] ?? null;
  if (!$path) return ['empresa' => $emp, 'status' => 'error', 'error' => 'Sin ruta configurada'];
  try {
    $meta = od_file_meta($path);
    $etag = (string)($meta['eTag'] ?? '');
    $lastKey = 'od_etag_' . $emp;
    if (!$force && $etag !== '' && kv_get($pdo, $lastKey) === $etag) {
      $st = ['empresa' => $emp, 'status' => 'sin_cambios', 'modificado' => $meta['lastModifiedDateTime'] ?? ''];
      kv_set($pdo, 'od_last_' . $emp, json_encode(['t' => date('Y-m-d H:i'), 'r' => $st], JSON_UNESCAPED_UNICODE));
      return $st;
    }
    $tmp = tempnam(sys_get_temp_dir(), 'crr');
    od_download($path, $tmp);
    $grid = xlsx_rows($tmp);
    @unlink($tmp);
    $rows = map_headers($grid);
    // fecha de corte = fecha de modificación del archivo, en hora Bogotá
    $mod = $meta['lastModifiedDateTime'] ?? '';
    $corte = $mod ? (new DateTime($mod))->setTimezone(new DateTimeZone('America/Bogota'))->format('Y-m-d') : date('Y-m-d');
    $res = import_rows($pdo, $emp, $corte, 'OneDrive: ' . ($meta['name'] ?? basename($path)), $rows, null);
    kv_set($pdo, $lastKey, $etag);
    $st = ['empresa' => $emp, 'status' => 'importado', 'corte' => $corte,
           'facturas' => $res['facturas'], 'total' => $res['total'], 'vencido' => $res['vencido']];
    kv_set($pdo, 'od_last_' . $emp, json_encode(['t' => date('Y-m-d H:i'), 'r' => $st], JSON_UNESCAPED_UNICODE));
    return $st;
  } catch (Exception $e) {
    $st = ['empresa' => $emp, 'status' => 'error', 'error' => $e->getMessage()];
    try { kv_set($pdo, 'od_last_' . $emp, json_encode(['t' => date('Y-m-d H:i'), 'r' => $st], JSON_UNESCAPED_UNICODE)); } catch (Exception $e2) {}
    return $st;
  }
}
