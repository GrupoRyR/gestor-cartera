import type { NextRequest } from "next/server";
import { actualizarSesion } from "@/lib/supabase/sesion";

/**
 * Se ejecuta antes que cada pagina: refresca la sesion de Supabase y manda al
 * login a quien no la tenga. En Next.js 16 este archivo se llama `proxy`
 * (antes `middleware`).
 */
export async function proxy(peticion: NextRequest) {
  return await actualizarSesion(peticion);
}

export const config = {
  matcher: [
    // Todo salvo estaticos e imagenes.
    "/((?!_next/static|_next/image|favicon.ico|.*\.(?:svg|png|jpg|jpeg|gif|webp|ico)$).*)",
  ],
};
