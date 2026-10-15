import Link from "next/link";

export const metadata = { title: "Página no encontrada · Cartera SEGUREX" };

export default function NoEncontrado() {
  return (
    <main className="flex min-h-dvh items-center justify-center p-4">
      <div
        className="max-w-md rounded-xl p-6 text-center"
        style={{ background: "var(--superficie)", border: "1px solid var(--borde)" }}
      >
        <p className="text-2xl font-bold tracking-tight">
          SEGU<span style={{ color: "var(--color-marca)" }}>REX</span>
        </p>
        <p className="mt-4 text-lg font-semibold">No encontramos esa página</p>
        <p className="mt-2 text-sm" style={{ color: "var(--tinta-2)" }}>
          Puede que el enlace esté equivocado, que el cliente ya no tenga saldo
          pendiente, o que no forme parte de tu cartera.
        </p>
        <Link
          href="/"
          className="mt-5 inline-block rounded-lg px-4 py-2 text-sm font-semibold text-white"
          style={{ background: "var(--color-marca)" }}
        >
          Volver al resumen
        </Link>
      </div>
    </main>
  );
}
