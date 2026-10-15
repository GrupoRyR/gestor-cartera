import { createServerClient } from "@supabase/ssr";
import { cookies } from "next/headers";

/**
 * Cliente de Supabase para componentes de servidor.
 * Las consultas viajan con la sesion del usuario, asi que las politicas RLS
 * de Postgres deciden que filas devuelve: la seguridad no depende de la interfaz.
 */
export async function crearClienteServidor() {
  const almacen = await cookies();

  return createServerClient(
    process.env.NEXT_PUBLIC_SUPABASE_URL!,
    process.env.NEXT_PUBLIC_SUPABASE_ANON_KEY!,
    {
      cookies: {
        getAll() {
          return almacen.getAll();
        },
        setAll(cookiesNuevas) {
          try {
            cookiesNuevas.forEach(({ name, value, options }) =>
              almacen.set(name, value, options),
            );
          } catch {
            // Se llamo desde un Server Component: el middleware ya refresca
            // la sesion, asi que se puede ignorar sin consecuencias.
          }
        },
      },
    },
  );
}
