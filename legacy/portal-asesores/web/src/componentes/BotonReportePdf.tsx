"use client";

import { useState } from "react";
import type { DocumentoCartera } from "@/lib/tipos";

/** Naranja de marca en RGB, para jsPDF. */
const MARCA: [number, number, number] = [255, 103, 31];
const GRIS: [number, number, number] = [102, 102, 102];

const pesosPdf = (v: number | null | undefined) =>
  new Intl.NumberFormat("es-CO", { maximumFractionDigits: 0 }).format(Number(v ?? 0));

const fechaPdf = (iso: string | null | undefined) => {
  if (!iso) return "—";
  const [a, m, d] = iso.split("-");
  return d && m && a ? `${d}/${m}/${a}` : "—";
};

/**
 * Reporte PDF de la cartera visible.
 *
 * Se genera en el navegador: los datos ya estan en pantalla, asi que no hace
 * falta un viaje al servidor ni exponer un endpoint que pudiera filtrar
 * documentos de otro asesor.
 */
export function BotonReportePdf({
  documentos,
  titulo,
  responsable,
  cliente,
}: {
  documentos: DocumentoCartera[];
  titulo: string;
  responsable: string;
  cliente?: string;
}) {
  const [generando, setGenerando] = useState(false);
  const [error, setError] = useState<string | null>(null);

  async function generar() {
    setError(null);
    setGenerando(true);
    try {
      const [{ jsPDF }, { default: autoTable }] = await Promise.all([
        import("jspdf"),
        import("jspdf-autotable"),
      ]);

      const doc = new jsPDF({ orientation: "landscape", unit: "pt", format: "a4" });
      const ancho = doc.internal.pageSize.getWidth();
      const hoy = new Date();

      // --- Encabezado -----------------------------------------------------
      doc.setFillColor(...MARCA);
      doc.rect(0, 0, ancho, 6, "F");

      doc.setFont("helvetica", "bold");
      doc.setFontSize(16);
      doc.setTextColor(17, 17, 17);
      doc.text("SEGUREX", 40, 40);

      doc.setFont("helvetica", "normal");
      doc.setFontSize(11);
      doc.setTextColor(...GRIS);
      doc.text(titulo, 40, 58);

      doc.setFontSize(9);
      const meta = [
        `Responsable: ${responsable}`,
        `Generado: ${hoy.toLocaleString("es-CO")}`,
      ];
      meta.forEach((t, i) => doc.text(t, ancho - 40, 40 + i * 13, { align: "right" }));

      // --- Resumen --------------------------------------------------------
      const total = documentos.reduce((s, d) => s + Number(d.saldo ?? 0), 0);
      const sinVencer = documentos
        .filter((d) => d.rango === "Sin vencer")
        .reduce((s, d) => s + Number(d.saldo ?? 0), 0);
      const vencido = total - sinVencer;
      const criticos = ["91-120", "121-150", "151-180", "> 180"];
      const critico = documentos
        .filter((d) => criticos.includes(d.rango ?? ""))
        .reduce((s, d) => s + Number(d.saldo ?? 0), 0);

      autoTable(doc, {
        startY: 78,
        head: [[
          "Saldo total", "Saldo antes de impuestos", "Sin vencer", "Vencido",
          "Más de 90 días", "Documentos", "Clientes",
        ]],
        body: [[
          pesosPdf(total),
          pesosPdf(documentos.reduce((s, d) => s + Number(d.saldo_base ?? 0), 0)),
          pesosPdf(sinVencer),
          pesosPdf(vencido),
          pesosPdf(critico),
          String(documentos.length),
          String(new Set(documentos.map((d) => d.codigo_sn)).size),
        ]],
        theme: "grid",
        headStyles: { fillColor: MARCA, textColor: 255, fontSize: 9, halign: "center" },
        bodyStyles: { fontSize: 10, halign: "center", fontStyle: "bold" },
        margin: { left: 40, right: 40 },
      });

      // --- Detalle --------------------------------------------------------
      const ordenados = [...documentos].sort(
        (a, b) =>
          a.nombre_sn.localeCompare(b.nombre_sn, "es") ||
          (a.fecha_vencimiento ?? "").localeCompare(b.fecha_vencimiento ?? ""),
      );

      autoTable(doc, {
        startY: (doc as unknown as { lastAutoTable: { finalY: number } }).lastAutoTable.finalY + 18,
        head: [[
          "Cliente", "NIT", "Documento", "Emisión", "Vence",
          "Días", "Antes de imp.", "Total con IVA", "Saldo", "Rango",
        ]],
        body: ordenados.map((d) => [
          cliente ? (d.documento ?? "—") : d.nombre_sn,
          d.nit ?? "—",
          d.documento ?? "—",
          fechaPdf(d.fecha_documento),
          fechaPdf(d.fecha_vencimiento),
          String(d.dias ?? "—"),
          pesosPdf(d.base_gravable),
          pesosPdf(d.importe_original),
          pesosPdf(d.saldo),
          d.rango ?? "—",
        ]),
        theme: "striped",
        headStyles: { fillColor: MARCA, textColor: 255, fontSize: 8 },
        bodyStyles: { fontSize: 7.5, textColor: 30 },
        alternateRowStyles: { fillColor: [250, 250, 249] },
        columnStyles: {
          0: { cellWidth: cliente ? 90 : 170 },
          5: { halign: "right" },
          6: { halign: "right" },
          7: { halign: "right" },
          8: { halign: "right", fontStyle: "bold" },
        },
        margin: { left: 40, right: 40 },
        didDrawPage: () => {
          const pagina = doc.getNumberOfPages();
          doc.setFontSize(8);
          doc.setTextColor(...GRIS);
          doc.text(
            `Página ${pagina}`,
            ancho - 40,
            doc.internal.pageSize.getHeight() - 20,
            { align: "right" },
          );
          doc.text(
            "Documento generado desde el portal de cartera SEGUREX.",
            40,
            doc.internal.pageSize.getHeight() - 20,
          );
        },
      });

      const marca = hoy.toISOString().slice(0, 10);
      doc.save(`cartera-${cliente ? `${cliente}-` : ""}${marca}.pdf`);
    } catch (e) {
      console.error(e);
      setError("No se pudo generar el PDF. Intenta de nuevo.");
    } finally {
      setGenerando(false);
    }
  }

  if (!documentos.length) return null;

  return (
    <div className="no-imprimir">
      <button
        type="button"
        onClick={generar}
        disabled={generando}
        className="rounded-lg px-4 py-2 text-sm font-semibold text-white disabled:opacity-60"
        style={{ background: "var(--color-marca)" }}
      >
        {generando ? "Generando…" : "Descargar PDF"}
      </button>
      {error && (
        <p role="alert" className="mt-2 text-xs" style={{ color: "var(--color-critico)" }}>
          {error}
        </p>
      )}
    </div>
  );
}
