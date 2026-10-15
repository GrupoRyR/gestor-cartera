import { redirect } from "next/navigation";
import { FormularioClave } from "@/componentes/FormularioClave";
import { obtenerUsuario } from "@/lib/cartera";
import { ETIQUETA_ROL } from "@/lib/tipos";

export const metadata = { title: "Mi cuenta · Cartera SEGUREX" };
export const dynamic = "force-dynamic";

export default async function PaginaCuenta() {
  const usuario = await obtenerUsuario();
  if (!usuario) redirect("/login");

  return (
    <div className="flex max-w-xl flex-col gap-5">
      <h1 className="text-xl font-bold sm:text-2xl">Mi cuenta</h1>

      <section
        className="rounded-xl p-4"
        style={{ background: "var(--superficie)", border: "1px solid var(--borde)" }}
      >
        <dl className="grid gap-3 text-sm sm:grid-cols-2">
          <Dato etiqueta="Nombre" valor={usuario.nombre} />
          <Dato etiqueta="Correo" valor={usuario.email} />
          <Dato etiqueta="Perfil" valor={ETIQUETA_ROL[usuario.rol] ?? usuario.rol} />
          <Dato
            etiqueta="Carteras asignadas"
            valor={
              usuario.rol === "admin" && usuario.slp_codes.length === 0
                ? "Todas"
                : usuario.slp_codes.join(", ") || "Ninguna"
            }
          />
        </dl>
        <p className="mt-3 text-xs" style={{ color: "var(--tinta-3)" }}>
          Si algo de esto está mal, escríbele al área de sistemas: los permisos
          no se cambian desde aquí.
        </p>
      </section>

      <FormularioClave />
    </div>
  );
}

function Dato({ etiqueta, valor }: { etiqueta: string; valor: string }) {
  return (
    <div>
      <dt className="text-xs font-medium uppercase tracking-wide" style={{ color: "var(--tinta-2)" }}>
        {etiqueta}
      </dt>
      <dd className="mt-0.5 break-words">{valor}</dd>
    </div>
  );
}
