<?php
// ===== Lector mínimo de .xlsx (ZipArchive + XML) — sin dependencias =====
// Devuelve la primera hoja como matriz de filas; strings resueltos, números como float.

function xlsx_rows(string $path): array {
  $zip = new ZipArchive();
  if ($zip->open($path) !== true) throw new Exception('No se pudo abrir el xlsx');

  // shared strings
  $shared = [];
  $ss = $zip->getFromName('xl/sharedStrings.xml');
  if ($ss !== false) {
    $x = simplexml_load_string($ss);
    if ($x) foreach ($x->si as $si) {
      if (isset($si->t)) $shared[] = (string)$si->t;
      else { // rich text: concatenar runs
        $t = '';
        foreach ($si->r as $r) $t .= (string)$r->t;
        $shared[] = $t;
      }
    }
  }

  // primera hoja (los exports del ERP traen una sola: "Datos")
  $sheetXml = $zip->getFromName('xl/worksheets/sheet1.xml');
  if ($sheetXml === false) {
    for ($i = 0; $i < $zip->numFiles; $i++) {
      $n = $zip->getNameIndex($i);
      if (preg_match('#^xl/worksheets/sheet\d+\.xml$#', $n)) { $sheetXml = $zip->getFromName($n); break; }
    }
  }
  $zip->close();
  if ($sheetXml === false) throw new Exception('El xlsx no trae hojas');

  $x = simplexml_load_string($sheetXml);
  if (!$x) throw new Exception('XML de hoja inválido');

  $rows = [];
  foreach ($x->sheetData->row as $row) {
    $r = [];
    foreach ($row->c as $c) {
      $ref = (string)$c['r'];                       // ej. "C12"
      preg_match('/^([A-Z]+)/', $ref, $m);
      $ci = 0;
      foreach (str_split($m[1] ?? 'A') as $ch) $ci = $ci * 26 + (ord($ch) - 64);
      $ci--;
      $t = (string)$c['t'];
      $v = null;
      if ($t === 's') $v = $shared[(int)$c->v] ?? '';
      elseif ($t === 'inlineStr') $v = (string)($c->is->t ?? '');
      elseif (isset($c->v)) { $raw = (string)$c->v; $v = is_numeric($raw) ? (float)$raw : $raw; }
      $r[$ci] = $v;
    }
    if ($r) { ksort($r); $rows[] = $r; }
  }
  return $rows;
}

// Serial de Excel (sistema 1900) → 'Y-m-d'
function xlsx_serial_date($serial): ?string {
  if (!is_numeric($serial) || $serial < 60) return null;
  return gmdate('Y-m-d', (int)round(((float)$serial - 25569) * 86400));
}
