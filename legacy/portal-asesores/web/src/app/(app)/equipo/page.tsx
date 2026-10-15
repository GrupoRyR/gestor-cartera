import Link from "next/link";
import { redirect } from "next/navigation";
import { obtenerCartera, obtenerUsuario, resumirPorAsesor } from "@/lib/cartera";
import { numero, pesos, pesosCorto, porcentaje } from "@/lib/formato";

export const metadata = { title: "Mi equipo · Cartera SEGUREX" };
export const dynamic = "force-dynamic";

/**
 * Desglose por asesor para gerencia.
 *
 * Hasta ahora la gerente veia su equipo como una sola cifra sumada, que no
 * sirve para dirigir: lo que necesita es saber de quien es el vencido.
 */
export default async function PaginaEquipo() {
  const usuario = await obtenerUsuario();
  if (!usuario) redirect("/login");
  // Un asesor no tiene equipo: su propia cartera ya es el resumen.
  if (usuario.rol === "asesor") redirect("/");

  const docs = await obtenerCartera();
  const asesores = resumirPorAsesor(docs);
  const totalVencido = asesores.reduce((s, a) => s + a.vencido, 0);
  const maximo = Math.max(...asesores.map((a) => Math.abs(a.vencido)), 1);

  return (
    <div className="flex flex-col gap-5">
      <div>
        <h1 className="text-xl font-bold sm:text-2xl">
          {usuario.rol === "admin" ? "Todos los asesores" : "Mi equipo"}
        </h1>
        <p className="mt-0.5 text-sm" style={{ color: "var(--tinta-2)" }}>
          {numero(asesores.length)} carteras · {pesosCorto(totalVencido)} vencido en total
        </p>
      </div>

      <section className="rounded-xl" style={{ background: "var(--superficie)", border: "1px solid var(--borde)" }}>
        <div className="scroll-x">
          <table className="w-full text-sm">
            <thead>
              <tr style={{ color: "var(--tinta-2)" }}>
                <th className="px-4 py-3 text-left font-medium">Asesor</th>
                <th className="px-4 py-3 text-right font-medium">Clientes</th>
                <th className="px-4 py-3 text-right font-medium">Saldo</th>
                <th className="px-4 py-3 text-left font-medium">Vencido</th>
                <th className="px-4 py-3 text-right font-medium">+90 días</th>
                <th className="px-4 py-3" />
              </tr>
            </thead>
            <tbody>
              {asesores.map((a) => (
                <tr key={a.codigos.join("-")} style={{ borderTop: "1px solid var(--borde-suave)" }}>
                  <td className="px-4 py-3">
                    <span className="block font-medium">{a.nombre}</span>
                    <span className="block text-xs" style={{ color: "var(--tinta-3)" }}>
                      {a.codigos.length === 1 ? "código" : "códigos"} {a.codigos.join(", ")} ·{" "}
                      {numero(a.documentos)} documentos
                    </span>
                  </td>
                  <td className="tabular px-4 py-3 text-right">{numero(a.clientes)}</td>
                  <td className="tabular px-4 py-3 text-right">{pesos(a.saldo)}</td>

                  {/* Barra + cifra: el color nunca carga solo el significado. */}
                  <td className="px-4 py-3">
                    <div className="flex min-w-[11rem] items-center gap-2">
                      <div
                        className="relative h-4 flex-1 overflow-hidden rounded"
                        style={{ background: "var(--rejilla)" }}
                      >
                        <div
                          className="h-full rounded"
                          style={{
                            width: `${Math.max((Math.abs(a.vencido) / maximo) * 100, 1.5)}%`,
                            background: a.vencido < 0 ? "var(--tinta-3)" : "var(--serie)",
                          }}
                        />
                      </div>
                      <span className="tabular w-[5rem] shrink-0 text-right text-xs font-semibold">
                        {pesosCorto(a.vencido)}
                      </span>
                      <span className="tabular w-[3.2rem] shrink-0 text-right text-xs" style={{ color: "var(--tinta-2)" }}>
                        {porcentaje(a.porcentajeVencido, 0)}
                      </span>
                    </div>
                  </td>

                  <td
                    className="tabular px-4 py-3 text-right font-semibold"
                    style={{ color: a.vencidoCritico > 0 ? "var(--color-critico)" : "var(--tinta-3)" }}
                  >
                    {a.vencidoCritico > 0 ? `▲ ${pesosCorto(a.vencidoCritico)}` : "—"}
                  </td>

                  <td className="px-4 py-3 text-right">
                    <Link
                      href={`/?asesor=${a.codigos.join(",")}`}
                      className="whitespace-nowrap text-xs font-medium"
                      style={{ color: "var(--color-marca-texto)" }}
                    >
                      Ver su cartera →
                    </Link>
                  </td>
                </tr>
              ))}
            </tbody>
          </table>
        </div>
      </section>

      <p className="text-xs" style={{ color: "var(--tinta-3)" }}>
        El porcentaje es la parte vencida sobre el saldo de cada asesor, para
        poder comparar carteras de tamaños distintos.
      </p>
    </div>
  );
}
