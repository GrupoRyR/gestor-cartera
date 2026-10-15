"use server";

import { revalidatePath } from "next/cache";
import { crearClienteServidor } from "@/lib/supabase/servidor";

export type Resultado = { ok: true; mensaje: string } | { ok: false; error: string };

const LIMITE_NOTA = 1000;

/**
 * Registra una nota o un compromiso de pago.
 *
 * El autor NO se lee del formulario: se toma de la sesion. Asi la bitacora
 * sirve de verdad como registro de quien gestiono, y ademas es lo que exige
 * la politica de la tabla (creado_por debe coincidir con el correo de la
 * sesion, o Postgres rechaza la fila).
 */
export async function crearGestion(_previo: unknown, datos: FormData): Promise<Resultado> {
  try {
    const supabase = await crearClienteServidor();
    const {
      data: { user },
    } = await supabase.auth.getUser();
    if (!user?.email) return { ok: false, error: "Tu sesión expiró. Vuelve a ingresar." };

    const codigo_sn = String(datos.get("codigo_sn") ?? "").trim();
    const nombre_sn = String(datos.get("nombre_sn") ?? "").trim() || null;
    const documento = String(datos.get("documento") ?? "").trim() || null;
    const slp_code = Number(datos.get("slp_code"));
    const tipo = String(datos.get("tipo") ?? "nota");
    const nota = String(datos.get("nota") ?? "").trim();
    const fecha = String(datos.get("fecha_compromiso") ?? "").trim();
    const valorBruto = String(datos.get("valor_compromiso") ?? "").replace(/[^\d-]/g, "");

    if (!codigo_sn || !Number.isInteger(slp_code))
      return { ok: false, error: "Falta el cliente. Recarga la página e intenta de nuevo." };
    if (!nota) return { ok: false, error: "Escribe qué pasó en la gestión." };
    if (nota.length > LIMITE_NOTA)
      return { ok: false, error: `La nota no puede pasar de ${LIMITE_NOTA} caracteres.` };
    if (tipo !== "nota" && tipo !== "compromiso")
      return { ok: false, error: "Tipo de gestión no reconocido." };
    if (tipo === "compromiso" && !fecha)
      return { ok: false, error: "Un compromiso necesita la fecha en que prometieron pagar." };

    const { error } = await supabase.from("gestiones").insert({
      codigo_sn,
      nombre_sn,
      documento,
      slp_code,
      tipo,
      nota,
      fecha_compromiso: tipo === "compromiso" ? fecha : null,
      valor_compromiso: tipo === "compromiso" && valorBruto ? Number(valorBruto) : null,
      creado_por: user.email,
    });

    if (error) {
      // 42501 = la politica RLS rechazo la fila: la cartera no es suya.
      if (error.code === "42501")
        return { ok: false, error: "Esa cartera no es tuya, no puedes registrar gestiones en ella." };
      return { ok: false, error: `No se pudo guardar: ${error.message}` };
    }

    revalidatePath(`/cliente/${codigo_sn}`);
    revalidatePath("/agenda");
    return {
      ok: true,
      mensaje: tipo === "compromiso" ? "Compromiso agendado." : "Nota registrada.",
    };
  } catch (e) {
    return { ok: false, error: e instanceof Error ? e.message : "Error inesperado." };
  }
}

/** Cierra un compromiso: cumplido (pagaron) o cancelado (se cayo el acuerdo). */
export async function cerrarCompromiso(
  id: number,
  estado: "cumplido" | "cancelado",
): Promise<Resultado> {
  try {
    const supabase = await crearClienteServidor();
    const { error } = await supabase.from("gestiones").update({ estado }).eq("id", id);
    if (error) return { ok: false, error: error.message };

    revalidatePath("/agenda");
    revalidatePath("/", "layout");
    return {
      ok: true,
      mensaje: estado === "cumplido" ? "Compromiso marcado como cumplido." : "Compromiso cancelado.",
    };
  } catch (e) {
    return { ok: false, error: e instanceof Error ? e.message : "Error inesperado." };
  }
}
