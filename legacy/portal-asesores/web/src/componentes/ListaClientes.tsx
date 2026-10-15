"use client";

import Link from "next/link";
import { useMemo, useState } from "react";
import { numero, pesos, pesosCorto } from "@/lib/formato";
import type { ResumenCliente } from "@/lib/tipos";

type Filtro = "todos" | "vencidos" | "criticos" | "sobrecupo";

const FILTROS: { id: Filtro; etiqueta: string }[] = [
  { id: "todos", etiqueta: "Todos" },
  { id: "vencidos", etiqueta: "Con saldo vencido" },
  { id: "criticos", etiqueta: "Más de 90 días" },
  { id: "sobrecupo", etiqueta: "Sobre el cupo" },
];

/** Quita tildes para que "Peña" se encuentre escribiendo "pena". */
const normalizar = (t: string) =>
  t
    .toLowerCase()
    .normalize("NFD")
    .replace(/[̀-ͯ]/g, "");

export function ListaClientes({
  clientes,
  facturas,
}: {
  clientes: ResumenCliente[];
  /** codigo de cliente -> sus numeros de factura, para poder buscarlas. */
  facturas?: Record<string, string>;
}) {
  const [busqueda, setBusqueda] = useState("");
  const [filtro, setFiltro] = useState<Filtro>("todos");

  const visibles = useMemo(() => {
    const q = normalizar(busqueda.trim());
    return clientes.filter((c) => {
      if (filtro === "vencidos" && c.vencido <= 0) return false;
      if (filtro === "criticos" && c.vencidoCritico <= 0) return false;
      if (filtro === "sobrecupo") {
        const cupo = Number(c.limite_credito ?? 0);
        if (!(cupo > 0 && c.saldo > cupo)) return false;
      }
      if (!q) return true;
      return (
        normalizar(c.nombre_sn).includes(q) ||
        normalizar(c.codigo_sn).includes(q) ||
        normalizar(c.nit ?? "").includes(q) ||
        normalizar(c.municipio ?? "").includes(q) ||
        // El caso real: llama el cliente preguntando por "la 9290".
        (facturas?.[c.codigo_sn] ?? "").includes(q)
      );
    });
  }, [clientes, busqueda, filtro, facturas]);

  const totalVisible = visibles.reduce((s, c) => s + c.saldo, 0);

  return (
    <section>
      {/* Controles: una fila sobre el contenido, como manda el patron. */}
      <div className="mb-3 flex flex-col gap-2 sm:flex-row sm:items-center">
        <input
          type="search"
          inputMode="search"
          value={busqueda}
          onChange={(e) => setBusqueda(e.target.value)}
          placeholder="Buscar cliente, NIT, municipio o n.º de factura…"
          aria-label="Buscar cliente o factura"
          className="w-full rounded-lg px-3 py-2 text-sm sm:max-w-xs"
          style={{
            background: "var(--superficie)",
            border: "1px solid var(--borde)",
            color: "var(--tinta)",
          }}
        />
        <div className="scroll-x flex gap-2 pb-1" role="group" aria-label="Filtrar clientes">
          {FILTROS.map((f) => {
            const activo = filtro === f.id;
            return (
              <button
                key={f.id}
                type="button"
                onClick={() => setFiltro(f.id)}
                aria-pressed={activo}
                className="shrink-0 rounded-full px-3 py-1.5 text-xs font-medium"
                style={{
                  background: activo ? "var(--color-marca)" : "var(--superficie)",
                  color: activo ? "#ffffff" : "var(--tinta-2)",
                  border: `1px solid ${activo ? "var(--color-marca)" : "var(--borde)"}`,
                }}
              >
                {f.etiqueta}
              </button>
            );
          })}
        </div>
      </div>

      <p className="mb-3 text-xs" style={{ color: "var(--tinta-2)" }}>
        {numero(visibles.length)} de {numero(clientes.length)} clientes ·{" "}
        <span className="tabular font-medium">{pesos(totalVisible)}</span>
      </p>

      {visibles.length === 0 ? (
        <p
          className="rounded-lg p-6 text-center text-sm"
          style={{ background: "var(--superficie)", border: "1px solid var(--borde)", color: "var(--tinta-2)" }}
        >
          Ningún cliente coincide con la búsqueda.
        </p>
      ) : (
        <>
          {/* Celular: tarjetas tocables */}
          <ul className="flex list-none flex-col gap-2 p-0 md:hidden">
            {visibles.map((c) => (
              <li key={c.codigo_sn}>
                <Link
                  href={`/cliente/${encodeURIComponent(c.codigo_sn)}`}
                  className="block rounded-xl p-3"
                  style={{
                    background: "var(--superficie)",
                    border: "1px solid var(--borde)",
                    boxShadow: "var(--sombra)",
                  }}
                >
                  <div className="flex items-start justify-between gap-3">
                    <div className="min-w-0">
                      <p className="truncate text-sm font-semibold">{c.nombre_sn}</p>
                      <p className="mt-0.5 truncate text-xs" style={{ color: "var(--tinta-2)" }}>
                        {c.codigo_sn}
                        {c.municipio ? ` · ${c.municipio}` : ""}
                      </p>
                    </div>
                    <p className="tabular shrink-0 text-right text-sm font-semibold">
                      {pesosCorto(c.saldo)}
                    </p>
                  </div>
                  <div className="mt-2 flex flex-wrap items-center gap-x-3 gap-y-1 text-xs">
                    <span style={{ color: "var(--tinta-2)" }}>
                      {c.documentos} doc{c.documentos === 1 ? "" : "s"}.
                    </span>
                    {c.vencido > 0 && (
                      <span style={{ color: "var(--color-critico)" }}>
                        <span aria-hidden="true">▲</span> Vencido{" "}
                        <span className="tabular font-medium">{pesosCorto(c.vencido)}</span>
                      </span>
                    )}
                    {c.diasMaximo > 0 && (
                      <span style={{ color: "var(--tinta-2)" }}>
                        máx. {c.diasMaximo} días
                      </span>
                    )}
                  </div>
                </Link>
              </li>
            ))}
          </ul>

          {/* Escritorio: tabla */}
          <div
            className="scroll-x hidden rounded-xl md:block"
            style={{ background: "var(--superficie)", border: "1px solid var(--borde)" }}
          >
            <table className="w-full text-sm">
              <thead>
                <tr style={{ color: "var(--tinta-2)" }}>
                  <th className="px-4 py-3 text-left font-medium">Cliente</th>
                  <th className="px-4 py-3 text-left font-medium">Municipio</th>
                  <th className="px-4 py-3 text-right font-medium">Docs.</th>
                  <th className="px-4 py-3 text-right font-medium">Sin vencer</th>
                  <th className="px-4 py-3 text-right font-medium">Vencido</th>
                  <th className="px-4 py-3 text-right font-medium">Días</th>
                  <th className="px-4 py-3 text-right font-medium">Saldo</th>
                </tr>
              </thead>
              <tbody>
                {visibles.map((c) => (
                  <tr key={c.codigo_sn} style={{ borderTop: "1px solid var(--borde-suave)" }}>
                    <td className="px-4 py-3">
                      <Link
                        href={`/cliente/${encodeURIComponent(c.codigo_sn)}`}
                        className="font-medium hover:underline"
                        style={{ color: "var(--color-marca-texto)" }}
                      >
                        {c.nombre_sn}
                      </Link>
                      <span className="block text-xs" style={{ color: "var(--tinta-3)" }}>
                        {c.codigo_sn}
                        {c.nit ? ` · NIT ${c.nit}` : ""}
                      </span>
                    </td>
                    <td className="px-4 py-3" style={{ color: "var(--tinta-2)" }}>
                      {c.municipio || "—"}
                    </td>
                    <td className="tabular px-4 py-3 text-right">{c.documentos}</td>
                    <td className="tabular px-4 py-3 text-right" style={{ color: "var(--tinta-2)" }}>
                      {pesos(c.sinVencer)}
                    </td>
                    <td
                      className="tabular px-4 py-3 text-right font-medium"
                      style={{ color: c.vencido > 0 ? "var(--color-critico)" : "var(--tinta-3)" }}
                    >
                      {c.vencido > 0 ? pesos(c.vencido) : "—"}
                    </td>
                    <td className="tabular px-4 py-3 text-right" style={{ color: "var(--tinta-2)" }}>
                      {c.diasMaximo > 0 ? c.diasMaximo : "—"}
                    </td>
                    <td className="tabular px-4 py-3 text-right font-semibold">
                      {pesos(c.saldo)}
                    </td>
                  </tr>
                ))}
              </tbody>
            </table>
          </div>
        </>
      )}
    </section>
  );
}
