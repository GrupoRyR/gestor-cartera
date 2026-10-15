"use client";

import { useRouter } from "next/navigation";
import { useState } from "react";
import { crearClienteNavegador } from "@/lib/supabase/cliente";

/** Traduce los errores de Supabase a algo accionable en espanol. */
function mensajeDeError(bruto: string): string {
  const t = bruto.toLowerCase();
  if (t.includes("invalid login credentials"))
    return "Correo o contraseña incorrectos. Verifica e intenta de nuevo.";
  if (t.includes("email not confirmed"))
    return "Tu cuenta aún no ha sido confirmada. Contacta al área de sistemas.";
  if (t.includes("too many requests") || t.includes("rate limit"))
    return "Demasiados intentos. Espera un minuto y vuelve a intentarlo.";
  if (t.includes("failed to fetch") || t.includes("network"))
    return "Sin conexión con el servidor. Revisa tu conexión a internet.";
  return "No fue posible ingresar. Intenta de nuevo en unos momentos.";
}

export function FormularioLogin({ volver }: { volver?: string }) {
  const router = useRouter();
  const [email, setEmail] = useState("");
  const [password, setPassword] = useState("");
  const [verClave, setVerClave] = useState(false);
  const [error, setError] = useState<string | null>(null);
  const [cargando, setCargando] = useState(false);

  async function ingresar(e: React.FormEvent) {
    e.preventDefault();
    setError(null);
    setCargando(true);
    try {
      const supabase = crearClienteNavegador();
      const { error: fallo } = await supabase.auth.signInWithPassword({
        email: email.trim(),
        password,
      });
      if (fallo) {
        setError(mensajeDeError(fallo.message));
        return;
      }
      router.push(volver && volver.startsWith("/") ? volver : "/");
      router.refresh();
    } catch (err) {
      setError(mensajeDeError(String(err)));
    } finally {
      setCargando(false);
    }
  }

  return (
    <form
      onSubmit={ingresar}
      className="rounded-xl p-5"
      style={{
        background: "var(--superficie)",
        border: "1px solid var(--borde)",
        boxShadow: "var(--sombra)",
      }}
    >
      <label htmlFor="email" className="block text-sm font-medium">
        Correo electrónico
      </label>
      <input
        id="email"
        type="email"
        required
        autoComplete="username"
        inputMode="email"
        autoCapitalize="none"
        value={email}
        onChange={(e) => setEmail(e.target.value)}
        placeholder="nombre.apellido@segurex.com"
        className="mt-1 w-full rounded-lg px-3 py-2.5 text-base"
        style={{
          background: "var(--plano)",
          border: "1px solid var(--borde)",
          color: "var(--tinta)",
        }}
      />

      <label htmlFor="password" className="mt-4 block text-sm font-medium">
        Contraseña
      </label>
      <div className="relative mt-1">
        <input
          id="password"
          type={verClave ? "text" : "password"}
          required
          autoComplete="current-password"
          value={password}
          onChange={(e) => setPassword(e.target.value)}
          className="w-full rounded-lg py-2.5 pl-3 pr-16 text-base"
          style={{
            background: "var(--plano)",
            border: "1px solid var(--borde)",
            color: "var(--tinta)",
          }}
        />
        <button
          type="button"
          onClick={() => setVerClave((v) => !v)}
          className="absolute right-2 top-1/2 -translate-y-1/2 px-2 py-1 text-xs font-medium"
          style={{ color: "var(--color-marca-texto)" }}
        >
          {verClave ? "Ocultar" : "Ver"}
        </button>
      </div>

      {error && (
        <p
          role="alert"
          className="mt-4 rounded-lg p-3 text-sm"
          style={{
            color: "var(--color-critico)",
            border: "1px solid var(--color-critico)",
          }}
        >
          <span aria-hidden="true">▲</span> {error}
        </p>
      )}

      <button
        type="submit"
        disabled={cargando}
        className="mt-5 w-full rounded-lg px-4 py-3 text-base font-semibold text-white disabled:opacity-60"
        style={{ background: "var(--color-marca)" }}
      >
        {cargando ? "Ingresando…" : "Ingresar"}
      </button>
    </form>
  );
}
