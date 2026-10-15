import { pesos, pesosCorto, porcentaje } from "@/lib/formato";
import type { ResumenRango } from "@/lib/tipos";

/**
 * Antiguedad de la cartera por rango de vencimiento.
 *
 * Una sola serie, asi que un solo color: la identidad de cada barra la da su
 * etiqueta, no el color. Cada barra lleva su valor escrito, que es ademas lo
 * que exige la "regla de relieve" (el naranja de marca mide 2,91:1 sobre
 * blanco, por debajo de 3:1, y no puede cargar el significado solo).
 *
 * Barras horizontales a proposito: las etiquetas ("121-150", "Sin vencer")
 * caben sin rotarse en la pantalla de un celular.
 */
export function GraficoAntiguedad({ datos }: { datos: ResumenRango[] }) {
  const conValor = datos.filter((d) => d.documentos > 0);
  if (!conValor.length) {
    return (
      <p className="text-sm" style={{ color: "var(--tinta-2)" }}>
        No hay documentos para mostrar.
      </p>
    );
  }

  // La escala usa el valor absoluto: hay notas credito con saldo negativo.
  const maximo = Math.max(...conValor.map((d) => Math.abs(d.saldo)), 1);

  return (
    <figure className="m-0">
      <ul className="flex list-none flex-col gap-3 p-0">
        {conValor.map((d) => {
          const ancho = Math.max((Math.abs(d.saldo) / maximo) * 100, 1.5);
          const negativo = d.saldo < 0;
          return (
            <li key={d.rango} className="grid grid-cols-[5.5rem_1fr] items-center gap-3">
              <span
                className="text-right text-xs font-medium sm:text-sm"
                style={{ color: "var(--tinta-2)" }}
              >
                {d.rango}
              </span>

              <div className="flex items-center gap-2">
                <div
                  className="relative h-6 flex-1 overflow-hidden rounded"
                  style={{ background: "var(--rejilla)" }}
                >
                  <div
                    className="h-full rounded transition-[width] duration-500"
                    style={{
                      width: `${ancho}%`,
                      background: negativo ? "var(--tinta-3)" : "var(--serie)",
                    }}
                  />
                </div>
                <span
                  className="tabular w-[5.5rem] shrink-0 text-right text-xs font-semibold sm:text-sm"
                  style={{ color: "var(--tinta)" }}
                  title={`${d.documentos} documento${d.documentos === 1 ? "" : "s"} · ${porcentaje(d.porcentaje)}`}
                >
                  {pesosCorto(d.saldo)}
                </span>
              </div>
            </li>
          );
        })}
      </ul>

      <figcaption className="mt-4 text-xs" style={{ color: "var(--tinta-3)" }}>
        Saldo por rango de vencimiento. Las barras grises corresponden a saldos
        negativos (notas crédito o anticipos).
      </figcaption>
    </figure>
  );
}

/** Vista de tabla del mismo dato: exigida por la regla de relieve. */
export function TablaAntiguedad({ datos }: { datos: ResumenRango[] }) {
  return (
    <div className="scroll-x">
      <table className="w-full text-sm">
        <thead>
          <tr style={{ color: "var(--tinta-2)" }}>
            <th className="py-2 text-left font-medium">Rango</th>
            <th className="py-2 text-right font-medium">Docs.</th>
            <th className="py-2 text-right font-medium">Saldo</th>
            <th className="py-2 text-right font-medium">%</th>
          </tr>
        </thead>
        <tbody>
          {datos
            .filter((d) => d.documentos > 0)
            .map((d) => (
              <tr key={d.rango} style={{ borderTop: "1px solid var(--borde-suave)" }}>
                <td className="py-2">{d.rango}</td>
                <td className="tabular py-2 text-right">{d.documentos}</td>
                <td className="tabular py-2 text-right font-medium">
                  {pesos(d.saldo)}
                </td>
                <td className="tabular py-2 text-right" style={{ color: "var(--tinta-2)" }}>
                  {porcentaje(d.porcentaje)}
                </td>
              </tr>
            ))}
        </tbody>
      </table>
    </div>
  );
}
