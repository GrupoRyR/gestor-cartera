import Link from "next/link";
import { FilaGestion } from "@/componentes/Bitacora";
import { agendaDeCobro, obtenerCartera, obtenerUsuario } from "@/lib/cartera";
import { obtenerCompromisos } from "@/lib/gestiones";
import { fecha, numero, pesos, pesosCorto } from "@/lib/formato";
import { compromisoIncumplido, type DocumentoCartera } from "@/lib/tipos";

export const metadata = { title: "Agenda de cobro · Cartera SEGUREX" };
export const dynamic = "force-dynamic";

export default async function PaginaAgenda() {
  const [usuario, docs, compromisos] = await Promise.all([
    obtenerUsuario(),
    obtenerCartera(),
    obtenerCompromisos(),
  ]);

  const { venceHoy, proximos, recienVencidos } = agendaDeCobro(docs);

  // Lo incumplido primero: es lo que hay que destrabar hoy.
  const compromisosOrdenados = [...compromisos].sort((a, b) => {
    const ia = compromisoIncumplido(a) ? 0 : 1;
    const ib = compromisoIncumplido(b) ? 0 : 1;
    return ia - ib || (a.fecha_compromiso ?? "").localeCompare(b.fecha_compromiso ?? "");
  });
  const incumplidos = compromisos.filter((g) => compromisoIncumplido(g)).length;

  return (
    <div className="flex flex-col gap-5">
      <div>
        <h1 className="text-xl font-bold sm:text-2xl">Agenda de cobro</h1>
        <p className="mt-0.5 text-sm" style={{ color: "var(--tinta-2)" }}>
          {usuario?.nombre} · qué hay que gestionar hoy
        </p>
      </div>

      <section className="grid grid-cols-2 gap-3 lg:grid-cols-4">
        <Contador etiqueta="Compromisos incumplidos" valor={incumplidos} tono="critico" />
        <Contador etiqueta="Compromisos pendientes" valor={compromisos.length - incumplidos} />
        <Contador etiqueta="Vencen hoy" valor={venceHoy.length} tono="atencion" />
        <Contador etiqueta="Vencen esta semana" valor={proximos.length} />
      </section>

      {/* ------------------------------------------------- compromisos */}
      <section
        className="rounded-xl p-4"
        style={{ background: "var(--superficie)", border: "1px solid var(--borde)" }}
      >
        <h2 className="text-sm font-semibold uppercase tracking-wide" style={{ color: "var(--tinta-2)" }}>
          Compromisos de pago
        </h2>
        {compromisosOrdenados.length === 0 ? (
          <p className="mt-2 text-sm" style={{ color: "var(--tinta-3)" }}>
            No tienes compromisos abiertos. Cuando un cliente prometa una fecha de
            pago, regístralo desde su ficha y aparecerá aquí.
          </p>
        ) : (
          <ul className="mt-3 flex list-none flex-col gap-2 p-0">
            {compromisosOrdenados.map((g) => (
              <FilaGestion key={g.id} g={g} conAcciones conCliente />
            ))}
          </ul>
        )}
      </section>

      <BloqueDocumentos
        titulo="Vencen hoy"
        ayuda="Última oportunidad de cobrar sin que entren en mora."
        docs={venceHoy}
        tono="atencion"
      />
      <BloqueDocumentos
        titulo="Vencen en los próximos 7 días"
        ayuda="Llamar ahora es lo que evita la mora del mes entrante."
        docs={proximos}
        modo="faltan"
      />
      <BloqueDocumentos
        titulo="Vencidas hace menos de 30 días"
        ayuda="Mora reciente: es la más fácil de recuperar."
        docs={recienVencidos}
        tono="critico"
      />
    </div>
  );
}

function Contador({
  etiqueta,
  valor,
  tono = "neutro",
}: {
  etiqueta: string;
  valor: number;
  tono?: "neutro" | "atencion" | "critico";
}) {
  const color =
    valor === 0
      ? "var(--tinta)"
      : tono === "critico"
        ? "var(--color-critico)"
        : tono === "atencion"
          ? "var(--color-atencion)"
          : "var(--tinta)";

  return (
    <div
      className="rounded-xl p-4"
      style={{ background: "var(--superficie)", border: "1px solid var(--borde)", boxShadow: "var(--sombra)" }}
    >
      <p className="text-xs font-medium uppercase tracking-wide" style={{ color: "var(--tinta-2)" }}>
        {etiqueta}
      </p>
      <p className="tabular mt-1 text-xl font-semibold sm:text-2xl" style={{ color }}>
        {numero(valor)}
      </p>
    </div>
  );
}

function BloqueDocumentos({
  titulo,
  ayuda,
  docs,
  tono = "neutro",
  modo = "mora",
}: {
  titulo: string;
  ayuda: string;
  docs: DocumentoCartera[];
  tono?: "neutro" | "atencion" | "critico";
  /** En lo que aun no vence, "-3 días" se lee mal: se muestra "faltan 3". */
  modo?: "mora" | "faltan";
}) {
  const total = docs.reduce((s, d) => s + Number(d.saldo ?? 0), 0);
  const color =
    tono === "critico"
      ? "var(--color-critico)"
      : tono === "atencion"
        ? "var(--color-atencion)"
        : "var(--tinta)";

  return (
    <section
      className="rounded-xl"
      style={{ background: "var(--superficie)", border: "1px solid var(--borde)" }}
    >
      <div className="flex flex-wrap items-baseline justify-between gap-2 px-4 pt-4">
        <h2 className="text-sm font-semibold uppercase tracking-wide" style={{ color: "var(--tinta-2)" }}>
          {titulo} ({numero(docs.length)})
        </h2>
        <span className="tabular text-sm font-semibold" style={{ color }}>
          {pesosCorto(total)}
        </span>
      </div>
      <p className="px-4 pt-1 text-xs" style={{ color: "var(--tinta-3)" }}>
        {ayuda}
      </p>

      {docs.length === 0 ? (
        <p className="px-4 pb-4 pt-3 text-sm" style={{ color: "var(--tinta-3)" }}>
          Nada por aquí.
        </p>
      ) : (
        <div className="scroll-x mt-3">
          <table className="w-full text-sm">
            <thead>
              <tr style={{ color: "var(--tinta-2)" }}>
                <th className="px-4 py-2 text-left font-medium">Cliente</th>
                <th className="px-4 py-2 text-left font-medium">Factura</th>
                <th className="px-4 py-2 text-left font-medium">Vence</th>
                <th className="px-4 py-2 text-right font-medium">
                  {modo === "faltan" ? "Faltan" : "Días"}
                </th>
                <th className="px-4 py-2 text-right font-medium">Saldo</th>
              </tr>
            </thead>
            <tbody>
              {docs.map((d, i) => (
                <tr key={`${d.documento}-${i}`} style={{ borderTop: "1px solid var(--borde-suave)" }}>
                  <td className="px-4 py-2.5">
                    <Link
                      href={`/cliente/${encodeURIComponent(d.codigo_sn)}`}
                      className="font-medium"
                      style={{ color: "var(--color-marca-texto)" }}
                    >
                      {d.nombre_sn}
                    </Link>
                  </td>
                  <td className="px-4 py-2.5" style={{ color: "var(--tinta-2)" }}>
                    {d.documento || "—"}
                  </td>
                  <td className="px-4 py-2.5" style={{ color: "var(--tinta-2)" }}>
                    {fecha(d.fecha_vencimiento)}
                  </td>
                  <td className="tabular px-4 py-2.5 text-right" style={{ color }}>
                    {d.dias === null
                      ? "—"
                      : modo === "faltan"
                        ? `${Math.abs(d.dias)} d`
                        : d.dias}
                  </td>
                  <td className="tabular px-4 py-2.5 text-right font-semibold">
                    {pesos(d.saldo)}
                  </td>
                </tr>
              ))}
            </tbody>
          </table>
        </div>
      )}
    </section>
  );
}
