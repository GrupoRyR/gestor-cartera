"use client";

import { useActionState, useState, useTransition } from "react";
import { cerrarCompromiso, crearGestion, type Resultado } from "@/app/(app)/gestiones.acciones";
import { fecha as formatoFecha, fechaHora, pesos } from "@/lib/formato";
import { compromisoIncumplido, type Gestion } from "@/lib/tipos";

/** Etiqueta de estado de un compromiso: color SIEMPRE acompanado de texto. */
function EstadoCompromiso({ g }: { g: Gestion }) {
  const incumplido = compromisoIncumplido(g);
  const mapa = {
    cumplido: { texto: "✓ Cumplido", color: "var(--color-bien)" },
    cancelado: { texto: "Cancelado", color: "var(--tinta-3)" },
    incumplido: { texto: "▲ Incumplido", color: "var(--color-critico)" },
    abierto: { texto: "Pendiente", color: "var(--color-marca-texto)" },
  };
  const clave = g.estado === "abierto" && incumplido ? "incumplido" : g.estado;
  const e = mapa[clave as keyof typeof mapa];

  return (
    <span className="text-xs font-semibold" style={{ color: e.color }}>
      {e.texto}
    </span>
  );
}

export function FilaGestion({
  g,
  conAcciones = false,
  conCliente = false,
}: {
  g: Gestion;
  conAcciones?: boolean;
  conCliente?: boolean;
}) {
  const [pendiente, iniciar] = useTransition();
  const [error, setError] = useState<string | null>(null);

  function cerrar(estado: "cumplido" | "cancelado") {
    iniciar(async () => {
      const r = await cerrarCompromiso(g.id, estado);
      if (!r.ok) setError(r.error);
    });
  }

  const esCompromiso = g.tipo === "compromiso";

  return (
    <li
      className="rounded-lg p-3"
      style={{
        background: "var(--plano)",
        borderLeft: `3px solid ${
          esCompromiso
            ? compromisoIncumplido(g)
              ? "var(--color-critico)"
              : "var(--color-marca)"
            : "var(--borde)"
        }`,
      }}
    >
      <div className="flex flex-wrap items-baseline justify-between gap-2">
        <span className="text-xs font-semibold uppercase tracking-wide" style={{ color: "var(--tinta-2)" }}>
          {esCompromiso ? "Compromiso de pago" : "Nota"}
          {g.documento ? ` · factura ${g.documento}` : ""}
        </span>
        {esCompromiso && <EstadoCompromiso g={g} />}
      </div>

      {conCliente && (
        <p className="mt-1 text-sm font-medium">{g.nombre_sn ?? g.codigo_sn}</p>
      )}

      {esCompromiso && (
        <p className="tabular mt-1 text-sm">
          Prometen pagar el <strong>{formatoFecha(g.fecha_compromiso)}</strong>
          {g.valor_compromiso ? ` · ${pesos(g.valor_compromiso)}` : ""}
        </p>
      )}

      <p className="mt-1 whitespace-pre-wrap text-sm">{g.nota}</p>

      <p className="mt-1.5 text-xs" style={{ color: "var(--tinta-3)" }}>
        {g.creado_por} · {fechaHora(g.creado_en)}
      </p>

      {conAcciones && esCompromiso && g.estado === "abierto" && (
        <div className="mt-2 flex gap-2">
          <button
            type="button"
            onClick={() => cerrar("cumplido")}
            disabled={pendiente}
            className="rounded-lg px-3 py-1.5 text-xs font-semibold text-white disabled:opacity-60"
            style={{ background: "var(--color-bien)" }}
          >
            Ya pagó
          </button>
          <button
            type="button"
            onClick={() => cerrar("cancelado")}
            disabled={pendiente}
            className="rounded-lg px-3 py-1.5 text-xs font-medium"
            style={{ border: "1px solid var(--borde)", color: "var(--tinta-2)" }}
          >
            Cancelar compromiso
          </button>
        </div>
      )}

      {error && (
        <p role="alert" className="mt-2 text-xs" style={{ color: "var(--color-critico)" }}>
          {error}
        </p>
      )}
    </li>
  );
}

/**
 * Bitacora de un cliente: registrar la gestion y ver el historial.
 *
 * Es la unica pantalla donde el asesor ESCRIBE. Se deja el formulario
 * plegado hasta que hace falta para que la ficha siga siendo, de un vistazo,
 * la cartera del cliente.
 */
export function Bitacora({
  codigoSn,
  nombreSn,
  slpCode,
  documentos,
  gestiones,
}: {
  codigoSn: string;
  nombreSn: string;
  slpCode: number;
  documentos: string[];
  gestiones: Gestion[];
}) {
  const [abierto, setAbierto] = useState(false);
  const [tipo, setTipo] = useState<"nota" | "compromiso">("nota");
  const [estado, accion, pendiente] = useActionState<Resultado | null, FormData>(
    async (previo, datos) => {
      const r = await crearGestion(previo, datos);
      if (r.ok) setAbierto(false);
      return r;
    },
    null,
  );

  const hoy = new Date().toISOString().slice(0, 10);

  return (
    <section
      className="rounded-xl p-4"
      style={{ background: "var(--superficie)", border: "1px solid var(--borde)" }}
    >
      <div className="flex flex-wrap items-center justify-between gap-3">
        <h2 className="text-sm font-semibold uppercase tracking-wide" style={{ color: "var(--tinta-2)" }}>
          Bitácora de cobro
        </h2>
        <button
          type="button"
          onClick={() => setAbierto((v) => !v)}
          className="rounded-lg px-3 py-1.5 text-xs font-semibold text-white"
          style={{ background: "var(--color-marca)" }}
        >
          {abierto ? "Cerrar" : "+ Registrar gestión"}
        </button>
      </div>

      {estado && (
        <p
          role="status"
          className="mt-3 rounded-lg p-2.5 text-xs"
          style={{
            border: `1px solid ${estado.ok ? "var(--color-bien)" : "var(--color-critico)"}`,
            color: estado.ok ? "var(--color-bien)" : "var(--color-critico)",
          }}
        >
          {estado.ok ? estado.mensaje : estado.error}
        </p>
      )}

      {abierto && (
        <form action={accion} className="mt-3 grid gap-3 sm:grid-cols-2">
          <input type="hidden" name="codigo_sn" value={codigoSn} />
          <input type="hidden" name="nombre_sn" value={nombreSn} />
          <input type="hidden" name="slp_code" value={slpCode} />

          <fieldset className="sm:col-span-2">
            <legend className="mb-1 text-xs font-medium" style={{ color: "var(--tinta-2)" }}>
              Tipo de gestión
            </legend>
            <div className="flex flex-wrap gap-3 text-sm">
              <label className="flex items-center gap-1.5">
                <input
                  type="radio"
                  name="tipo"
                  value="nota"
                  checked={tipo === "nota"}
                  onChange={() => setTipo("nota")}
                />
                Nota de seguimiento
              </label>
              <label className="flex items-center gap-1.5">
                <input
                  type="radio"
                  name="tipo"
                  value="compromiso"
                  checked={tipo === "compromiso"}
                  onChange={() => setTipo("compromiso")}
                />
                Compromiso de pago
              </label>
            </div>
          </fieldset>

          {tipo === "compromiso" && (
            <>
              <label className="block">
                <span className="mb-1 block text-xs font-medium" style={{ color: "var(--tinta-2)" }}>
                  ¿Qué día prometieron pagar?
                </span>
                <input
                  type="date"
                  name="fecha_compromiso"
                  required
                  min={hoy}
                  className="w-full rounded-lg px-3 py-2 text-sm"
                  style={{ background: "var(--plano)", border: "1px solid var(--borde)", color: "var(--tinta)" }}
                />
              </label>
              <label className="block">
                <span className="mb-1 block text-xs font-medium" style={{ color: "var(--tinta-2)" }}>
                  Valor prometido (opcional)
                </span>
                <input
                  name="valor_compromiso"
                  inputMode="numeric"
                  placeholder="5000000"
                  className="w-full rounded-lg px-3 py-2 text-sm"
                  style={{ background: "var(--plano)", border: "1px solid var(--borde)", color: "var(--tinta)" }}
                />
              </label>
            </>
          )}

          <label className="block sm:col-span-2">
            <span className="mb-1 block text-xs font-medium" style={{ color: "var(--tinta-2)" }}>
              Factura (opcional)
            </span>
            <select
              name="documento"
              defaultValue=""
              className="w-full rounded-lg px-3 py-2 text-sm"
              style={{ background: "var(--plano)", border: "1px solid var(--borde)", color: "var(--tinta)" }}
            >
              <option value="">Toda la cartera del cliente</option>
              {documentos.map((d) => (
                <option key={d} value={d}>
                  {d}
                </option>
              ))}
            </select>
          </label>

          <label className="block sm:col-span-2">
            <span className="mb-1 block text-xs font-medium" style={{ color: "var(--tinta-2)" }}>
              ¿Qué pasó?
            </span>
            <textarea
              name="nota"
              required
              rows={3}
              maxLength={1000}
              placeholder="Hablé con tesorería, confirman pago el viernes."
              className="w-full rounded-lg px-3 py-2 text-sm"
              style={{ background: "var(--plano)", border: "1px solid var(--borde)", color: "var(--tinta)" }}
            />
          </label>

          <div className="sm:col-span-2">
            <button
              type="submit"
              disabled={pendiente}
              className="rounded-lg px-4 py-2 text-sm font-semibold text-white disabled:opacity-60"
              style={{ background: "var(--color-marca)" }}
            >
              {pendiente ? "Guardando…" : "Guardar gestión"}
            </button>
          </div>
        </form>
      )}

      {gestiones.length === 0 ? (
        <p className="mt-3 text-sm" style={{ color: "var(--tinta-3)" }}>
          Todavía no hay gestiones registradas para este cliente.
        </p>
      ) : (
        <ul className="mt-3 flex list-none flex-col gap-2 p-0">
          {gestiones.map((g) => (
            <FilaGestion key={g.id} g={g} conAcciones />
          ))}
        </ul>
      )}
    </section>
  );
}
