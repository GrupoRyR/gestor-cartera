import Link from "next/link";
import { notFound } from "next/navigation";
import { Bitacora } from "@/componentes/Bitacora";
import { BotonExcel } from "@/componentes/BotonExcel";
import { BotonReportePdf } from "@/componentes/BotonReportePdf";
import { BotonWhatsApp } from "@/componentes/BotonWhatsApp";
import { GraficoAntiguedad } from "@/componentes/GraficoAntiguedad";
import { TarjetaIndicador } from "@/componentes/TarjetaIndicador";
import { calcularIndicadores, obtenerCartera, obtenerUsuario } from "@/lib/cartera";
import { obtenerGestiones } from "@/lib/gestiones";
import { fecha, nombreAsesor, numero, pesos, pesosCorto } from "@/lib/formato";

export const dynamic = "force-dynamic";

export default async function PaginaCliente({
  params,
}: {
  params: Promise<{ codigo: string }>;
}) {
  const { codigo } = await params;
  const cliente = decodeURIComponent(codigo);
  const [usuario, docs, gestiones] = await Promise.all([
    obtenerUsuario(),
    obtenerCartera({ cliente }),
    obtenerGestiones({ cliente }),
  ]);

  // RLS ya filtro: si no hay filas, o el cliente no existe o no es suyo.
  if (!docs.length) notFound();

  const k = calcularIndicadores(docs);
  const c = docs[0];
  const cupo = Number(c.limite_credito ?? 0);
  const sobreCupo = cupo > 0 && k.saldoTotal > cupo;

  return (
    <div className="flex flex-col gap-5">
      <div>
        <Link href="/clientes" className="text-xs font-medium" style={{ color: "var(--color-marca-texto)" }}>
          ← Volver a clientes
        </Link>
        <div className="mt-2 flex flex-wrap items-start justify-between gap-3">
          <div className="min-w-0">
            <h1 className="text-xl font-bold sm:text-2xl">{c.nombre_sn}</h1>
            <p className="mt-1 text-sm" style={{ color: "var(--tinta-2)" }}>
              {c.codigo_sn}
              {c.nit ? ` · NIT ${c.nit}` : ""}
              {c.municipio ? ` · ${c.municipio}` : ""}
            </p>
            <p className="mt-0.5 text-xs" style={{ color: "var(--tinta-3)" }}>
              Asesor: {nombreAsesor(c.asesor)}
              {c.condicion_pago ? ` · ${c.condicion_pago}` : ""}
            </p>
          </div>
          <div className="flex flex-wrap gap-2">
            <BotonWhatsApp documentos={docs} nombreCliente={c.nombre_sn} />
            <BotonExcel documentos={docs} nombre={`cartera-${c.codigo_sn}`} />
            <BotonReportePdf
              documentos={docs}
              titulo={`Cartera · ${c.nombre_sn}`}
              responsable={usuario?.nombre ?? ""}
              cliente={c.codigo_sn}
            />
          </div>
        </div>
      </div>

      {sobreCupo && (
        <p
          className="rounded-lg px-3 py-2 text-sm"
          style={{
            border: "1px solid var(--color-atencion)",
            color: "var(--color-atencion)",
          }}
        >
          <span aria-hidden="true">!</span> Este cliente superó su cupo de crédito
          en {pesos(k.saldoTotal - cupo)}. Conviene revisarlo antes de autorizar un
          nuevo despacho.
        </p>
      )}

      <section className="grid grid-cols-2 gap-3 lg:grid-cols-4">
        <TarjetaIndicador etiqueta="Saldo total" valor={k.saldoTotal} compacto
          detalle={
            <>
              Con impuestos · {numero(k.documentos)} documentos
              <span className="mt-0.5 block">
                Antes de impuestos:{" "}
                <span className="tabular font-semibold">{pesosCorto(k.saldoBase)}</span>
              </span>
            </>
          } />
        <TarjetaIndicador etiqueta="Sin vencer" valor={k.sinVencer} compacto tono="bien" />
        <TarjetaIndicador etiqueta="Vencido" valor={k.vencido} compacto
          tono={k.vencido > 0 ? "atencion" : "neutro"} />
        <TarjetaIndicador etiqueta="Cupo de crédito" valor={cupo} compacto
          tono={sobreCupo ? "atencion" : "neutro"}
          detalle={cupo > 0
            ? sobreCupo
              ? `Excedido en ${pesosCorto(k.saldoTotal - cupo)}`
              : `Disponible: ${pesosCorto(cupo - k.saldoTotal)}`
            : "Sin cupo asignado"} />
      </section>

      {k.vencido !== 0 && (
        <section className="rounded-xl p-4"
          style={{ background: "var(--superficie)", border: "1px solid var(--borde)", boxShadow: "var(--sombra)" }}>
          <h2 className="mb-4 text-sm font-semibold uppercase tracking-wide" style={{ color: "var(--tinta-2)" }}>
            Antigüedad
          </h2>
          <GraficoAntiguedad datos={k.porRango} />
        </section>
      )}

      <section className="rounded-xl" style={{ background: "var(--superficie)", border: "1px solid var(--borde)" }}>
        <h2 className="px-4 pt-4 text-sm font-semibold uppercase tracking-wide" style={{ color: "var(--tinta-2)" }}>
          Documentos pendientes
        </h2>
        <div className="scroll-x mt-3">
          <table className="w-full text-sm">
            <thead>
              <tr style={{ color: "var(--tinta-2)" }}>
                <th className="px-4 py-2 text-left font-medium">Documento</th>
                <th className="px-4 py-2 text-left font-medium">Emisión</th>
                <th className="px-4 py-2 text-left font-medium">Vence</th>
                <th className="px-4 py-2 text-right font-medium">Días</th>
                <th className="px-4 py-2 text-right font-medium">Antes de impuestos</th>
                <th className="px-4 py-2 text-right font-medium">Total con IVA</th>
                <th className="px-4 py-2 text-right font-medium">Saldo</th>
                <th className="px-4 py-2 text-left font-medium">Rango</th>
              </tr>
            </thead>
            <tbody>
              {docs.map((d, i) => {
                const vencido = (d.dias ?? -1) >= 0;
                return (
                  <tr key={`${d.documento}-${i}`} style={{ borderTop: "1px solid var(--borde-suave)" }}>
                    <td className="px-4 py-2.5 font-medium">{d.documento || "—"}</td>
                    <td className="px-4 py-2.5" style={{ color: "var(--tinta-2)" }}>{fecha(d.fecha_documento)}</td>
                    <td className="px-4 py-2.5" style={{ color: "var(--tinta-2)" }}>{fecha(d.fecha_vencimiento)}</td>
                    <td className="tabular px-4 py-2.5 text-right"
                      style={{ color: vencido ? "var(--color-critico)" : "var(--tinta-2)" }}>
                      {d.dias ?? "—"}
                    </td>
                    <td className="tabular px-4 py-2.5 text-right" style={{ color: "var(--tinta-3)" }}>
                      {pesos(d.base_gravable)}
                    </td>
                    <td className="tabular px-4 py-2.5 text-right" style={{ color: "var(--tinta-2)" }}>
                      {pesos(d.importe_original)}
                    </td>
                    <td className="tabular px-4 py-2.5 text-right font-semibold">
                      {pesos(d.saldo)}
                    </td>
                    <td className="px-4 py-2.5">
                      <span className="rounded px-2 py-0.5 text-xs font-medium"
                        style={{
                          background: vencido ? "var(--color-marca-suave)" : "var(--plano)",
                          color: vencido ? "var(--color-marca-texto)" : "var(--tinta-2)",
                        }}>
                        {d.rango}
                      </span>
                    </td>
                  </tr>
                );
              })}
            </tbody>
          </table>
        </div>
      </section>

      <Bitacora
        codigoSn={c.codigo_sn}
        nombreSn={c.nombre_sn}
        slpCode={Number(c.slp_code ?? -1)}
        documentos={[...new Set(docs.map((d) => d.documento).filter(Boolean))] as string[]}
        gestiones={gestiones}
      />
    </div>
  );
}
