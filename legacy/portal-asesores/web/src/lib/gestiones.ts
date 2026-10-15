import { crearClienteServidor } from "@/lib/supabase/servidor";
import type { Gestion } from "@/lib/tipos";

/**
 * Bitacora de cobro. RLS ya limita lo que llega: un asesor solo recibe las
 * gestiones de sus codigos, la gerente las de su equipo.
 */
export async function obtenerGestiones(opciones?: {
  cliente?: string;
  limite?: number;
}): Promise<Gestion[]> {
  const supabase = await crearClienteServidor();
  let consulta = supabase
    .from("gestiones")
    .select("*")
    .order("creado_en", { ascending: false })
    .limit(opciones?.limite ?? 200);

  if (opciones?.cliente) consulta = consulta.eq("codigo_sn", opciones.cliente);

  const { data, error } = await consulta;
  if (error) return [];
  return (data ?? []) as Gestion[];
}

/**
 * Compromisos de pago vivos, del mas atrasado al mas lejano: lo primero que
 * el asesor debe mirar cada manana.
 */
export async function obtenerCompromisos(): Promise<Gestion[]> {
  const supabase = await crearClienteServidor();
  const { data, error } = await supabase
    .from("gestiones")
    .select("*")
    .eq("tipo", "compromiso")
    .eq("estado", "abierto")
    .order("fecha_compromiso")
    .limit(200);

  if (error) return [];
  return (data ?? []) as Gestion[];
}
