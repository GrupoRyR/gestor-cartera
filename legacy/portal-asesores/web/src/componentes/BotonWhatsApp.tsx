"use client";

import { fecha, pesos } from "@/lib/formato";
import type { DocumentoCartera } from "@/lib/tipos";

/** Cuantas facturas caben en el mensaje sin volverlo ilegible en el chat. */
const MAXIMO_LINEAS = 12;

/**
 * Comparte el estado de cuenta del cliente por WhatsApp.
 *
 * NO envia nada: abre WhatsApp con el texto ya escrito para que el asesor
 * elija el contacto, lo revise y pulse enviar. Es como trabaja el equipo
 * comercial en la calle, desde el celular.
 */
export function BotonWhatsApp({
  documentos,
  nombreCliente,
}: {
  documentos: DocumentoCartera[];
  nombreCliente: string;
}) {
  if (!documentos.length) return null;

  const pendientes = documentos
    .filter((d) => Number(d.saldo ?? 0) > 0)
    .sort((a, b) => Number(b.dias ?? 0) - Number(a.dias ?? 0));

  const total = pendientes.reduce((s, d) => s + Number(d.saldo ?? 0), 0);
  const vencido = pendientes
    .filter((d) => Number(d.dias ?? -1) >= 0)
    .reduce((s, d) => s + Number(d.saldo ?? 0), 0);

  const lineas = pendientes
    .slice(0, MAXIMO_LINEAS)
    .map(
      (d) =>
        `• Factura ${d.documento ?? "—"} · vence ${fecha(d.fecha_vencimiento)} · ${pesos(d.saldo)}` +
        (Number(d.dias ?? -1) > 0 ? ` (${d.dias} días)` : ""),
    );

  if (pendientes.length > MAXIMO_LINEAS) {
    lineas.push(`• y ${pendientes.length - MAXIMO_LINEAS} facturas más`);
  }

  const texto = [
    `Buen día, ${nombreCliente}.`,
    "",
    `Le comparto el estado de su cuenta con SEGUREX al ${fecha(new Date().toISOString().slice(0, 10))}:`,
    "",
    `Saldo total: ${pesos(total)}`,
    vencido > 0 ? `Vencido: ${pesos(vencido)}` : "Sin saldo vencido.",
    "",
    ...lineas,
    "",
    "Quedo atento(a) para coordinar el pago. ¡Gracias!",
  ].join("\n");

  return (
    <a
      href={`https://wa.me/?text=${encodeURIComponent(texto)}`}
      target="_blank"
      rel="noopener noreferrer"
      className="no-imprimir rounded-lg px-4 py-2 text-sm font-semibold"
      style={{ border: "1px solid var(--borde)", color: "var(--tinta-2)" }}
    >
      Enviar por WhatsApp
    </a>
  );
}
