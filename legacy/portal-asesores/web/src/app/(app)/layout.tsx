import Link from "next/link";
import { Encabezado } from "@/componentes/Encabezado";
import { obtenerUsuario } from "@/lib/cartera";

export default async function LayoutApp({
  children,
}: {
  children: React.ReactNode;
}) {
  const usuario = await obtenerUsuario();

  // Tiene cuenta en Supabase, pero nadie lo ha dado de alta todavia.
  if (!usuario) {
    return (
      <main className="flex min-h-dvh items-center justify-center p-4">
        <div
          className="max-w-md rounded-xl p-6 text-center"
          style={{ background: "var(--superficie)", border: "1px solid var(--borde)" }}
        >
          <p className="text-lg font-semibold">Tu cuenta aún no tiene acceso</p>
          <p className="mt-2 text-sm" style={{ color: "var(--tinta-2)" }}>
            Ingresaste correctamente, pero tu usuario todavía no está asociado a
            ninguna cartera. Pide al área de sistemas que te dé de alta.
          </p>
          <Link
            href="/login"
            className="mt-5 inline-block rounded-lg px-4 py-2 text-sm font-semibold text-white"
            style={{ background: "var(--color-marca)" }}
          >
            Volver a ingresar
          </Link>
        </div>
      </main>
    );
  }

  return (
    <div className="min-h-dvh">
      <Encabezado usuario={usuario} />
      <main className="mx-auto max-w-6xl px-4 py-5">{children}</main>
    </div>
  );
}
