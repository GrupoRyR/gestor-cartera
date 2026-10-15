"use client";

import { useActionState, useState } from "react";
import { guardarUsuario, type Resultado } from "@/app/(app)/admin/acciones";
import { nombreAsesor, numero, pesosCorto } from "@/lib/formato";
import { ETIQUETA_ROL, type Rol, type Usuario } from "@/lib/tipos";

export interface AsesorSap {
  slp_code: number;
  nombre: string;
  documentos: number;
  saldo: number;
  asignado_a: string | null;
  /** Bolsa contable o canal, no una persona: la ve solo gerencia. */
  sin_titular: boolean;
}

const VACIO: Partial<Usuario> = {
  id: undefined,
  email: "",
  nombre: "",
  rol: "asesor",
  slp_codes: [],
  activo: true,
};

export function PanelUsuarios({
  usuarios,
  asesores,
}: {
  usuarios: Usuario[];
  asesores: AsesorSap[];
}) {
  const [edicion, setEdicion] = useState<Partial<Usuario> | null>(null);
  const [estado, accion, pendiente] = useActionState<Resultado | null, FormData>(
    async (previo, datos) => {
      const r = await guardarUsuario(previo, datos);
      if (r.ok) setEdicion(null);
      return r;
    },
    null,
  );

  // Las bolsas sin titular no son un pendiente: por decision del negocio las
  // ve solo gerencia, asi que sacarlas de la alerta evita un aviso eterno.
  const sinAsignar = asesores.filter((a) => !a.asignado_a && !a.sin_titular);
  const soloGerencia = asesores.filter((a) => !a.asignado_a && a.sin_titular);
  const saldoSinAsignar = sinAsignar.reduce((s, a) => s + a.saldo, 0);
  const saldoGerencia = soloGerencia.reduce((s, a) => s + a.saldo, 0);

  return (
    <div className="flex flex-col gap-5">
      {estado && (
        <p
          role="status"
          className="rounded-lg p-3 text-sm"
          style={{
            border: `1px solid ${estado.ok ? "var(--color-bien)" : "var(--color-critico)"}`,
            color: estado.ok ? "var(--color-bien)" : "var(--color-critico)",
          }}
        >
          {estado.ok ? estado.mensaje : estado.error}
        </p>
      )}

      {/* ------------------------------------------- asesores sin usuario */}
      {sinAsignar.length > 0 && (
        <section
          className="rounded-xl p-4"
          style={{ background: "var(--superficie)", border: "1px solid var(--color-atencion)" }}
        >
          <h2 className="text-sm font-semibold">
            <span aria-hidden="true">!</span> {sinAsignar.length} carteras sin usuario
            asignado · {pesosCorto(saldoSinAsignar)}
          </h2>
          <p className="mt-1 text-xs" style={{ color: "var(--tinta-2)" }}>
            Nadie puede consultarlas todavía. Pulsa una para crear su usuario con el
            código ya rellenado.
          </p>
          <ul className="mt-3 flex list-none flex-wrap gap-2 p-0">
            {sinAsignar.map((a) => (
              <li key={a.slp_code}>
                <button
                  type="button"
                  onClick={() =>
                    setEdicion({
                      ...VACIO,
                      nombre: nombreAsesor(a.nombre),
                      slp_codes: [a.slp_code],
                    })
                  }
                  className="rounded-lg px-3 py-2 text-left text-xs"
                  style={{ background: "var(--plano)", border: "1px solid var(--borde)" }}
                >
                  <span className="block font-medium">{nombreAsesor(a.nombre)}</span>
                  <span style={{ color: "var(--tinta-2)" }}>
                    código {a.slp_code} · {pesosCorto(a.saldo)}
                  </span>
                </button>
              </li>
            ))}
          </ul>
        </section>
      )}

      {soloGerencia.length > 0 && (
        <section
          className="rounded-xl p-4"
          style={{ background: "var(--superficie)", border: "1px solid var(--borde)" }}
        >
          <h2 className="text-sm font-semibold">
            {soloGerencia.length} carteras sin titular · {pesosCorto(saldoGerencia)}
          </h2>
          <p className="mt-1 text-xs" style={{ color: "var(--tinta-2)" }}>
            Bolsas contables y canales, no personas. Las ve únicamente gerencia
            (rol administrador); no requieren usuario propio.
          </p>
          <p className="mt-2 text-xs" style={{ color: "var(--tinta-3)" }}>
            {soloGerencia
              .map((a) => `${nombreAsesor(a.nombre)} (${a.slp_code})`)
              .join(" · ")}
          </p>
        </section>
      )}

      {/* ------------------------------------------------------ formulario */}
      <section
        className="rounded-xl p-4"
        style={{ background: "var(--superficie)", border: "1px solid var(--borde)" }}
      >
        <div className="mb-3 flex items-center justify-between gap-3">
          <h2 className="text-sm font-semibold uppercase tracking-wide" style={{ color: "var(--tinta-2)" }}>
            {edicion?.id ? "Editar usuario" : "Nuevo usuario"}
          </h2>
          {edicion && (
            <button
              type="button"
              onClick={() => setEdicion(null)}
              className="text-xs"
              style={{ color: "var(--tinta-2)" }}
            >
              Cancelar
            </button>
          )}
        </div>

        {edicion ? (
          <form action={accion} className="grid gap-3 sm:grid-cols-2">
            <input type="hidden" name="id" value={edicion.id ?? ""} />

            <Campo
              etiqueta="Correo electrónico"
              ayuda={
                edicion.id
                  ? "Es la llave del ingreso. Si lo cambias, hay que volver a crear su cuenta de acceso."
                  : "Con este correo ingresará a la web."
              }
            >
              <input
                name="email"
                type="email"
                required
                defaultValue={edicion.email ?? ""}
                placeholder="nombre.apellido@segurex.com"
                className="w-full rounded-lg px-3 py-2 text-sm"
                style={{ background: "var(--plano)", border: "1px solid var(--borde)", color: "var(--tinta)" }}
              />
            </Campo>

            <Campo etiqueta="Nombre">
              <input
                name="nombre"
                required
                defaultValue={edicion.nombre ?? ""}
                className="w-full rounded-lg px-3 py-2 text-sm"
                style={{ background: "var(--plano)", border: "1px solid var(--borde)", color: "var(--tinta)" }}
              />
            </Campo>

            <Campo etiqueta="Rol">
              <select
                name="rol"
                defaultValue={edicion.rol ?? "asesor"}
                className="w-full rounded-lg px-3 py-2 text-sm"
                style={{ background: "var(--plano)", border: "1px solid var(--borde)", color: "var(--tinta)" }}
              >
                <option value="asesor">
                  Asesor — solo la cartera de sus códigos
                </option>
                <option value="gerente">
                  Gerente de ventas — la cartera de su equipo
                </option>
                <option value="admin">
                  Administrador — toda la cartera de la empresa
                </option>
              </select>
            </Campo>

            <Campo
              etiqueta="Códigos de asesor"
              ayuda={
                "Separados por coma. Un gerente lleva los códigos de todos los " +
                "asesores de su equipo (puede repetir los de ellos). " +
                "Un administrador puede dejarlo vacío: ve todo."
              }
            >
              <input
                name="slp_codes"
                defaultValue={(edicion.slp_codes ?? []).join(", ")}
                placeholder="34, 48"
                inputMode="numeric"
                className="w-full rounded-lg px-3 py-2 text-sm"
                style={{ background: "var(--plano)", border: "1px solid var(--borde)", color: "var(--tinta)" }}
              />
            </Campo>

            <label className="flex items-center gap-2 text-sm sm:col-span-2">
              <input type="checkbox" name="activo" defaultChecked={edicion.activo ?? true} />
              Usuario activo
            </label>

            <div className="sm:col-span-2">
              <button
                type="submit"
                disabled={pendiente}
                className="rounded-lg px-4 py-2 text-sm font-semibold text-white disabled:opacity-60"
                style={{ background: "var(--color-marca)" }}
              >
                {pendiente ? "Guardando…" : "Guardar"}
              </button>
            </div>
          </form>
        ) : (
          <button
            type="button"
            onClick={() => setEdicion({ ...VACIO })}
            className="rounded-lg px-4 py-2 text-sm font-semibold text-white"
            style={{ background: "var(--color-marca)" }}
          >
            + Nuevo usuario
          </button>
        )}
      </section>

      {/* -------------------------------------------------- lista actual */}
      <section className="rounded-xl" style={{ background: "var(--superficie)", border: "1px solid var(--borde)" }}>
        <h2 className="px-4 pt-4 text-sm font-semibold uppercase tracking-wide" style={{ color: "var(--tinta-2)" }}>
          Usuarios ({numero(usuarios.length)})
        </h2>
        <div className="scroll-x mt-3">
          <table className="w-full text-sm">
            <thead>
              <tr style={{ color: "var(--tinta-2)" }}>
                <th className="px-4 py-2 text-left font-medium">Usuario</th>
                <th className="px-4 py-2 text-left font-medium">Rol</th>
                <th className="px-4 py-2 text-left font-medium">Códigos</th>
                <th className="px-4 py-2 text-left font-medium">Estado</th>
                <th className="px-4 py-2" />
              </tr>
            </thead>
            <tbody>
              {usuarios.map((u) => (
                <tr key={u.id} style={{ borderTop: "1px solid var(--borde-suave)" }}>
                  <td className="px-4 py-2.5">
                    <span className="block font-medium">{u.nombre}</span>
                    <span className="block text-xs" style={{ color: "var(--tinta-3)" }}>{u.email}</span>
                  </td>
                  <td className="px-4 py-2.5">
                    <span
                      className="rounded px-2 py-0.5 text-xs font-medium"
                      style={{
                        background:
                          u.rol === "asesor" ? "var(--plano)" : "var(--color-marca-suave)",
                        color:
                          u.rol === "asesor" ? "var(--tinta-2)" : "var(--color-marca-texto)",
                      }}
                    >
                      {ETIQUETA_ROL[u.rol as Rol] ?? u.rol}
                    </span>
                  </td>
                  <td className="tabular px-4 py-2.5" style={{ color: "var(--tinta-2)" }}>
                    {u.slp_codes.length
                      ? u.slp_codes.join(", ")
                      : u.rol === "admin"
                        ? "todos"
                        : "— sin códigos —"}
                  </td>
                  <td className="px-4 py-2.5" style={{ color: u.activo ? "var(--color-bien)" : "var(--tinta-3)" }}>
                    {u.activo ? "Activo" : "Inactivo"}
                  </td>
                  <td className="px-4 py-2.5 text-right">
                    <button
                      type="button"
                      onClick={() => setEdicion(u)}
                      className="text-xs font-medium"
                      style={{ color: "var(--color-marca-texto)" }}
                    >
                      Editar
                    </button>
                  </td>
                </tr>
              ))}
            </tbody>
          </table>
        </div>
      </section>
    </div>
  );
}

function Campo({
  etiqueta,
  ayuda,
  children,
}: {
  etiqueta: string;
  ayuda?: string;
  children: React.ReactNode;
}) {
  return (
    <label className="block">
      <span className="mb-1 block text-xs font-medium" style={{ color: "var(--tinta-2)" }}>
        {etiqueta}
      </span>
      {children}
      {ayuda && (
        <span className="mt-1 block text-xs" style={{ color: "var(--tinta-3)" }}>
          {ayuda}
        </span>
      )}
    </label>
  );
}
