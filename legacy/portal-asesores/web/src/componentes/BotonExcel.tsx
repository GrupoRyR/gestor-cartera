"use client";

import { useState } from "react";
import { nombreAsesor } from "@/lib/formato";
import type { DocumentoCartera } from "@/lib/tipos";

const COLUMNAS: Array<[string, (d: DocumentoCartera) => string | number]> = [
  ["Código cliente", (d) => d.codigo_sn],
  ["Cliente", (d) => d.nombre_sn],
  ["NIT", (d) => d.nit ?? ""],
  ["Municipio", (d) => d.municipio ?? ""],
  ["Grupo", (d) => d.grupo ?? ""],
  ["Asesor", (d) => nombreAsesor(d.asesor)],
  ["Factura", (d) => d.documento ?? ""],
  ["Condición de pago", (d) => d.condicion_pago ?? ""],
  ["Fecha documento", (d) => d.fecha_documento ?? ""],
  ["Fecha vencimiento", (d) => d.fecha_vencimiento ?? ""],
  ["Días", (d) => d.dias ?? 0],
  ["Antes de impuestos", (d) => Number(d.base_gravable ?? 0)],
  ["Total con IVA", (d) => Number(d.importe_original ?? 0)],
  ["Saldo", (d) => Number(d.saldo ?? 0)],
  ["Saldo antes de impuestos", (d) => Number(d.saldo_base ?? 0)],
  ["Rango", (d) => d.rango ?? ""],
  ["Cupo de crédito", (d) => Number(d.limite_credito ?? 0)],
];

/**
 * Descarga la cartera visible como CSV para Excel.
 *
 * Se arma en el navegador con lo que ya esta en pantalla: ni viaje al
 * servidor ni endpoint nuevo que pudiera filtrar documentos de otro asesor.
 *
 * Detalles que hacen que Excel en espanol lo abra bien de una vez:
 *   * separador ";" (la coma es el separador decimal aqui)
 *   * BOM al inicio, o las tildes salen como simbolos raros
 *   * numeros con coma decimal y SIN separador de miles
 */
export function BotonExcel({
  documentos,
  nombre,
}: {
  documentos: DocumentoCartera[];
  nombre: string;
}) {
  const [generando, setGenerando] = useState(false);

  function descargar() {
    setGenerando(true);
    try {
      const celda = (v: string | number) => {
        if (typeof v === "number") return v.toFixed(2).replace(".", ",");
        // Las comillas se duplican; el resto se encierra para que ni ";" ni
        // los saltos de linea rompan la fila.
        return `"${String(v).replace(/"/g, '""')}"`;
      };

      const filas = [
        COLUMNAS.map(([t]) => celda(t)).join(";"),
        ...documentos.map((d) => COLUMNAS.map(([, f]) => celda(f(d))).join(";")),
      ];

      const url = URL.createObjectURL(
        new Blob(["﻿" + filas.join("\r\n")], {
          type: "text/csv;charset=utf-8;",
        }),
      );
      const a = document.createElement("a");
      a.href = url;
      a.download = `${nombre}-${new Date().toISOString().slice(0, 10)}.csv`;
      a.click();
      URL.revokeObjectURL(url);
    } finally {
      setGenerando(false);
    }
  }

  if (!documentos.length) return null;

  return (
    <button
      type="button"
      onClick={descargar}
      disabled={generando}
      className="no-imprimir rounded-lg px-4 py-2 text-sm font-semibold disabled:opacity-60"
      style={{ border: "1px solid var(--color-marca)", color: "var(--color-marca-texto)" }}
    >
      Descargar Excel
    </button>
  );
}
