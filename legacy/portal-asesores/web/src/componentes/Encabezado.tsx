import Link from "next/link";
import { BotonSalir } from "@/componentes/BotonSalir";
import type { Usuario } from "@/lib/tipos";

const ENLACES = [
  { href: "/", etiqueta: "Resumen" },
  { href: "/agenda", etiqueta: "Agenda" },
  { href: "/clientes", etiqueta: "Clientes" },
];

export function Encabezado({ usuario }: { usuario: Usuario }) {
  const enlaces = [
    ...ENLACES,
    // Un asesor no tiene equipo: su cartera ya es todo lo que ve.
    ...(usuario.rol !== "asesor" ? [{ href: "/equipo", etiqueta: "Equipo" }] : []),
    ...(usuario.rol === "admin" ? [{ href: "/admin", etiqueta: "Administrar" }] : []),
    { href: "/cuenta", etiqueta: "Mi cuenta" },
  ];

  return (
    <header
      className="sticky top-0 z-10 no-imprimir"
      style={{
        background: "var(--superficie)",
        borderBottom: "1px solid var(--borde)",
      }}
    >
      <div className="mx-auto flex max-w-6xl items-center justify-between gap-3 px-4 py-3">
        <Link href="/" className="shrink-0 text-base font-bold tracking-tight">
          SEGU<span style={{ color: "var(--color-marca)" }}>REX</span>
          <span className="ml-2 text-xs font-normal" style={{ color: "var(--tinta-2)" }}>
            Cartera
          </span>
        </Link>

        <div className="flex items-center gap-3">
          <span
            className="hidden max-w-[16rem] truncate text-xs sm:block"
            style={{ color: "var(--tinta-2)" }}
            title={usuario.email}
          >
            {usuario.nombre}
            {usuario.rol !== "asesor" && (
              <span
                className="ml-2 rounded px-1.5 py-0.5 text-[10px] font-semibold uppercase"
                style={{ background: "var(--color-marca-suave)", color: "var(--color-marca-texto)" }}
              >
                {usuario.rol === "admin" ? "Admin" : "Gerente"}
              </span>
            )}
          </span>
          <BotonSalir />
        </div>
      </div>

      {/* En el celular la barra se desplaza en vez de partirse en dos lineas. */}
      <nav className="scroll-x mx-auto max-w-6xl px-4">
        <ul className="flex list-none gap-1 whitespace-nowrap p-0">
          {enlaces.map((e) => (
            <li key={e.href}>
              <Link
                href={e.href}
                className="inline-block px-3 py-2 text-sm font-medium"
                style={{ color: "var(--tinta-2)" }}
              >
                {e.etiqueta}
              </Link>
            </li>
          ))}
        </ul>
      </nav>
    </header>
  );
}
