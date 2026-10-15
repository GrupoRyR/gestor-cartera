"use server";

import { revalidatePath } from "next/cache";
import { crearClienteServidor } from "@/lib/supabase/servidor";

export type Resultado = { ok: true; mensaje: string } | { ok: false; error: string };

/** Solo un admin activo puede administrar usuarios. Se comprueba en servidor. */
async function exigirAdmin() {
  const supabase = await crearClienteServidor();
  const {
    data: { user },
  } = await supabase.auth.getUser();
  if (!user?.email) throw new Error("Sesión no válida.");

  const { data } = await supabase
    .from("usuarios")
    .select("rol, activo")
    .ilike("email", user.email)
    .maybeSingle();

  if (!data || data.rol !== "admin" || !data.activo) {
    throw new Error("No tienes permisos de administración.");
  }
  return { supabase, email: user.email };
}

function leerCodigos(bruto: string): number[] {
  const codigos = bruto
    .split(/[\s,;]+/)
    .map((t) => t.trim())
    .filter(Boolean)
    .map(Number);

  if (codigos.some((n) => !Number.isInteger(n))) {
    throw new Error("Los códigos de asesor deben ser números enteros.");
  }
  return [...new Set(codigos)];
}

export async function guardarUsuario(_previo: unknown, datos: FormData): Promise<Resultado> {
  try {
    const { supabase, email: actor } = await exigirAdmin();

    const id = String(datos.get("id") ?? "").trim();
    const email = String(datos.get("email") ?? "").trim().toLowerCase();
    const nombre = String(datos.get("nombre") ?? "").trim();
    const rol = String(datos.get("rol") ?? "asesor");
    const activo = datos.get("activo") === "on";
    const slp_codes = leerCodigos(String(datos.get("slp_codes") ?? ""));

    if (!email.includes("@")) return { ok: false, error: "El correo no es válido." };
    if (!nombre) return { ok: false, error: "El nombre es obligatorio." };
    if (rol !== "admin" && rol !== "asesor" && rol !== "gerente")
      return { ok: false, error: "Rol no reconocido." };
    if (rol !== "admin" && slp_codes.length === 0)
      return {
        ok: false,
        error:
          rol === "gerente"
            ? "Un gerente necesita los códigos de los asesores de su equipo."
            : "Un asesor necesita al menos un código; si no, no verá ninguna cartera.",
      };

    // Exclusividad SOLO entre asesores: dos asesores con el mismo código casi
    // siempre es un error de digitación y haría que uno vea la cartera del
    // otro. En cambio un gerente comparte a propósito los códigos de su
    // equipo, así que ahí no se comprueba nada.
    if (rol === "asesor" && slp_codes.length) {
      const { data: choques } = await supabase
        .from("usuarios")
        .select("email, slp_codes")
        .eq("rol", "asesor")
        .overlaps("slp_codes", slp_codes)
        .neq("id", id || "00000000-0000-0000-0000-000000000000");

      if (choques?.length) {
        const otros = choques.map((c) => c.email).join(", ");
        return {
          ok: false,
          error: `Alguno de esos códigos ya está asignado al asesor: ${otros}. Quítaselo antes de asignarlo aquí.`,
        };
      }
    }

    // El correo es la llave del login. Si cambia, la cuenta de acceso creada
    // en Supabase Auth sigue con el correo anterior y la persona ya no podra
    // entrar hasta que se le cree la cuenta nueva.
    let correoAnterior: string | null = null;
    if (id) {
      const { data: previo } = await supabase
        .from("usuarios")
        .select("email")
        .eq("id", id)
        .maybeSingle();
      correoAnterior = previo?.email?.toLowerCase() ?? null;
    }

    const fila = { email, nombre, rol, slp_codes, activo };
    const { error } = id
      ? await supabase.from("usuarios").update(fila).eq("id", id)
      : await supabase.from("usuarios").insert({ ...fila, creado_por: actor });

    if (error) {
      if (error.code === "23505")
        return { ok: false, error: "Ya existe un usuario con ese correo." };
      return { ok: false, error: `No se pudo guardar: ${error.message}` };
    }

    revalidatePath("/admin");

    if (!id) {
      return {
        ok: true,
        mensaje:
          `Usuario ${email} creado. Todavía NO puede ingresar: falta crear su ` +
          `cuenta de acceso ejecutando scripts/crear_cuentas.py.`,
      };
    }

    if (correoAnterior && correoAnterior !== email) {
      return {
        ok: true,
        mensaje:
          `Permisos actualizados, pero el correo cambió de ${correoAnterior} ` +
          `a ${email}. Ejecuta scripts/crear_cuentas.py para crear la cuenta ` +
          `de acceso nueva; hasta entonces esta persona no podrá ingresar. ` +
          `(La cuenta anterior queda sin acceso a ninguna cartera.)`,
      };
    }

    return { ok: true, mensaje: `Usuario ${email} actualizado.` };
  } catch (e) {
    return { ok: false, error: e instanceof Error ? e.message : "Error inesperado." };
  }
}

export async function alternarActivo(id: string, activo: boolean): Promise<Resultado> {
  try {
    const { supabase } = await exigirAdmin();
    const { error } = await supabase.from("usuarios").update({ activo }).eq("id", id);
    if (error) return { ok: false, error: error.message };
    revalidatePath("/admin");
    return { ok: true, mensaje: activo ? "Usuario activado." : "Usuario desactivado." };
  } catch (e) {
    return { ok: false, error: e instanceof Error ? e.message : "Error inesperado." };
  }
}
