"use client";

import { useState } from "react";
import { crearClienteNavegador } from "@/lib/supabase/cliente";

const MINIMO = 8;

/**
 * Cambio de contrasena del propio usuario.
 *
 * Hasta ahora las claves las repartia sistemas y nadie podia cambiarlas, asi
 * que la primera contrasena vivia para siempre en un WhatsApp. Esto lo
 * resuelve sin depender de que haya correo saliente configurado: la persona
 * ya esta autenticada, solo cambia su clave.
 */
export function FormularioClave() {
  const [clave, setClave] = useState("");
  const [repetida, setRepetida] = useState("");
  const [ver, setVer] = useState(false);
  const [estado, setEstado] = useState<{ ok: boolean; texto: string } | null>(null);
  const [guardando, setGuardando] = useState(false);

  async function guardar(e: React.FormEvent) {
    e.preventDefault();
    setEstado(null);

    if (clave.length < MINIMO) {
      setEstado({ ok: false, texto: `La contraseña debe tener al menos ${MINIMO} caracteres.` });
      return;
    }
    if (clave !== repetida) {
      setEstado({ ok: false, texto: "Las dos contraseñas no coinciden." });
      return;
    }

    setGuardando(true);
    try {
      const supabase = crearClienteNavegador();
      const { error } = await supabase.auth.updateUser({ password: clave });
      if (error) {
        const t = error.message.toLowerCase();
        setEstado({
          ok: false,
          texto: t.includes("should be different")
            ? "Esa es la contraseña que ya tienes. Elige una distinta."
            : t.includes("weak") || t.includes("password")
              ? "Esa contraseña es demasiado sencilla. Combina letras y números."
              : "No se pudo cambiar la contraseña. Intenta de nuevo.",
        });
        return;
      }
      setClave("");
      setRepetida("");
      setEstado({ ok: true, texto: "Listo, tu contraseña quedó cambiada." });
    } catch {
      setEstado({ ok: false, texto: "Sin conexión con el servidor. Intenta de nuevo." });
    } finally {
      setGuardando(false);
    }
  }

  const campo = {
    background: "var(--plano)",
    border: "1px solid var(--borde)",
    color: "var(--tinta)",
  };

  return (
    <form
      onSubmit={guardar}
      className="rounded-xl p-4"
      style={{ background: "var(--superficie)", border: "1px solid var(--borde)" }}
    >
      <h2 className="text-sm font-semibold uppercase tracking-wide" style={{ color: "var(--tinta-2)" }}>
        Cambiar mi contraseña
      </h2>

      <label htmlFor="clave" className="mt-3 block text-sm font-medium">
        Nueva contraseña
      </label>
      <div className="relative mt-1">
        <input
          id="clave"
          type={ver ? "text" : "password"}
          required
          autoComplete="new-password"
          value={clave}
          onChange={(e) => setClave(e.target.value)}
          className="w-full rounded-lg py-2.5 pl-3 pr-16 text-base"
          style={campo}
        />
        <button
          type="button"
          onClick={() => setVer((v) => !v)}
          className="absolute right-2 top-1/2 -translate-y-1/2 px-2 py-1 text-xs font-medium"
          style={{ color: "var(--color-marca-texto)" }}
        >
          {ver ? "Ocultar" : "Ver"}
        </button>
      </div>
      <p className="mt-1 text-xs" style={{ color: "var(--tinta-3)" }}>
        Mínimo {MINIMO} caracteres. No la compartas con nadie: con ella se ve la
        cartera de tus clientes.
      </p>

      <label htmlFor="repetida" className="mt-4 block text-sm font-medium">
        Repite la nueva contraseña
      </label>
      <input
        id="repetida"
        type={ver ? "text" : "password"}
        required
        autoComplete="new-password"
        value={repetida}
        onChange={(e) => setRepetida(e.target.value)}
        className="mt-1 w-full rounded-lg px-3 py-2.5 text-base"
        style={campo}
      />

      {estado && (
        <p
          role="status"
          className="mt-4 rounded-lg p-3 text-sm"
          style={{
            color: estado.ok ? "var(--color-bien)" : "var(--color-critico)",
            border: `1px solid ${estado.ok ? "var(--color-bien)" : "var(--color-critico)"}`,
          }}
        >
          <span aria-hidden="true">{estado.ok ? "✓" : "▲"}</span> {estado.texto}
        </p>
      )}

      <button
        type="submit"
        disabled={guardando}
        className="mt-5 rounded-lg px-4 py-2.5 text-sm font-semibold text-white disabled:opacity-60"
        style={{ background: "var(--color-marca)" }}
      >
        {guardando ? "Guardando…" : "Cambiar contraseña"}
      </button>
    </form>
  );
}
