"use client";

import { useState } from "react";
import { crearClienteNavegador } from "@/lib/supabase/cliente";

export function BotonSalir() {
  const [saliendo, setSaliendo] = useState(false);

  return (
    <button
      type="button"
      disabled={saliendo}
      onClick={async () => {
        setSaliendo(true);
        try {
          await crearClienteNavegador().auth.signOut();
        } catch {
          // Si Supabase no responde (sin red, token ya vencido), la sesion
          // local igual se descarta: nadie debe quedar atrapado sin poder salir.
          await crearClienteNavegador()
            .auth.signOut({ scope: "local" })
            .catch(() => {});
        } finally {
          // Navegacion completa, no router.push: garantiza que el servidor
          // vuelva a leer las cookies y no quede pintada la cartera anterior.
          window.location.assign("/login");
        }
      }}
      className="rounded-lg px-3 py-1.5 text-xs font-medium"
      style={{ border: "1px solid var(--borde)", color: "var(--tinta-2)" }}
    >
      {saliendo ? "Saliendo…" : "Salir"}
    </button>
  );
}
