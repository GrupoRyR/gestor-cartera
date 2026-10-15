import { FormularioLogin } from "@/componentes/FormularioLogin";

export const metadata = { title: "Ingresar · Cartera SEGUREX" };

export default async function PaginaLogin({
  searchParams,
}: {
  searchParams: Promise<{ volver?: string }>;
}) {
  const { volver } = await searchParams;

  return (
    <main className="flex min-h-dvh items-center justify-center p-4">
      <div className="w-full max-w-sm">
        <div className="mb-8 text-center">
          <p className="text-2xl font-bold tracking-tight">
            SEGU<span style={{ color: "var(--color-marca)" }}>REX</span>
          </p>
          <p className="mt-1 text-sm" style={{ color: "var(--tinta-2)" }}>
            Cartera de clientes
          </p>
        </div>
        <FormularioLogin volver={volver} />
        <p className="mt-6 text-center text-xs" style={{ color: "var(--tinta-3)" }}>
          ¿Problemas para ingresar? Comunícate con el área de sistemas.
        </p>
      </div>
    </main>
  );
}
