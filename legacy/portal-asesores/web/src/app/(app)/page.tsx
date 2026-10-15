import Link from "next/link";
import { AvisoDatos } from "@/componentes/AvisoDatos";
import { BotonExcel } from "@/componentes/BotonExcel";
import { BotonReportePdf } from "@/componentes/BotonReportePdf";
import { GraficoAntiguedad, TablaAntiguedad } from "@/componentes/GraficoAntiguedad";
import { GraficoTendencia, TablaTendencia } from "@/componentes/GraficoTendencia";
import { TarjetaConteo, TarjetaIndicador } from "@/componentes/TarjetaIndicador";
import {
  agendaDeCobro,
  agruparPorCliente,
  calcularIndicadores,
  clientesSobreCupo,
  datoDesactualizado,
  obtenerCartera,
  obtenerEstadoCarga,
  obtenerHistorico,
  obtenerUsuario,
} from "@/lib/cartera";
import { nombreAsesor, numero, pesosCorto, porcentaje } from "@/lib/formato";

export const metadata = { title: "Resumen · Cartera SEGUREX" };
export const dynamic = "force-dynamic";

export default async function PaginaResumen({
  searchParams,
}: {
  searchParams: Promise<{ asesor?: string }>;
}) {
  const { asesor } = await searchParams;
  // "Ver la cartera de" un asesor concreto (gerencia). Puede traer dos
  // codigos, porque hay quien tiene dos en SAP. Si alguien manipula el
  // parametro para espiar otra cartera, RLS simplemente no devuelve filas.
  const slpCodes = (asesor ?? "")
    .split(",")
    .map((t) => t.trim())
    .filter((t) => /^\d+$/.test(t))
    .map(Number);
  const viendoAsesor = slpCodes.length > 0;

  const [usuario, docs, estado, historico] = await Promise.all([
    obtenerUsuario(),
    obtenerCartera(viendoAsesor ? { slpCodes } : undefined),
    obtenerEstadoCarga(),
    obtenerHistorico(60),
  ]);

  const k = calcularIndicadores(docs);
  const clientes = agruparPorCliente(docs);
  const viejo = datoDesactualizado(estado);
  const criticos = clientes.filter((c) => c.vencidoCritico > 0).slice(0, 5);
  const sobreCupo = clientesSobreCupo(clientes);
  const agenda = agendaDeCobro(docs);
  const porGestionar = agenda.venceHoy.length + agenda.proximos.length;

  const titulo = viendoAsesor
    ? `Cartera de ${nombreAsesor(docs[0]?.asesor) || `código ${slpCodes.join(", ")}`}`
    : usuario?.rol === "admin"
      ? "Cartera general"
      : usuario?.rol === "gerente"
        ? "Cartera de mi equipo"
        : "Mi cartera";

  return (
    <div className="flex flex-col gap-5">
      {viendoAsesor && (
        <div
          className="flex flex-wrap items-center justify-between gap-2 rounded-lg px-3 py-2 text-sm"
          style={{ background: "var(--color-marca-suave)", color: "var(--color-marca-texto)" }}
        >
          <span>Estás viendo la cartera de un asesor de tu equipo.</span>
          <Link href="/equipo" className="font-semibold underline">
            ← Volver a mi equipo
          </Link>
        </div>
      )}

      <div className="flex flex-wrap items-start justify-between gap-3">
        <div>
          <h1 className="text-xl font-bold sm:text-2xl">{titulo}</h1>
          <p className="mt-0.5 text-sm" style={{ color: "var(--tinta-2)" }}>
            {usuario?.nombre}
          </p>
        </div>
        <div className="flex flex-wrap gap-2">
          <BotonExcel documentos={docs} nombre="cartera" />
          <BotonReportePdf
            documentos={docs}
            titulo={titulo}
            responsable={usuario?.nombre ?? ""}
          />
        </div>
      </div>

      <AvisoDatos estado={estado} desactualizado={viejo} />

      {docs.length === 0 ? (
        <p
          className="rounded-xl p-6 text-center text-sm"
          style={{ background: "var(--superficie)", border: "1px solid var(--borde)", color: "var(--tinta-2)" }}
        >
          No tienes documentos de cartera asignados.
        </p>
      ) : (
        <>
          <section className="grid grid-cols-2 gap-3 lg:grid-cols-4">
            <TarjetaIndicador
              etiqueta="Saldo total"
              valor={k.saldoTotal}
              compacto
              detalle={
                <>
                  Con impuestos · {numero(k.documentos)} documentos
                  <span className="mt-0.5 block">
                    Antes de impuestos:{" "}
                    <span className="tabular font-semibold">{pesosCorto(k.saldoBase)}</span>
                  </span>
                </>
              }
            />
            <TarjetaIndicador
              etiqueta="Sin vencer"
              valor={k.sinVencer}
              compacto
              tono="bien"
              detalle={`${porcentaje(100 - k.porcentajeVencido)} del total`}
            />
            <TarjetaIndicador
              etiqueta="Vencido"
              valor={k.vencido}
              compacto
              tono={k.vencido > 0 ? "atencion" : "neutro"}
              detalle={`${porcentaje(k.porcentajeVencido)} del total`}
            />
            <TarjetaIndicador
              etiqueta="Más de 90 días"
              valor={k.vencidoCritico}
              compacto
              tono={k.vencidoCritico > 0 ? "critico" : "neutro"}
              detalle="Cartera de difícil cobro"
            />
          </section>

          <section className="grid grid-cols-2 gap-3 lg:grid-cols-4">
            <TarjetaConteo etiqueta="Clientes" valor={numero(k.clientes)} />
            <TarjetaConteo
              etiqueta="Mora promedio"
              valor={`${Math.round(k.diasPromedioPonderado)} días`}
              detalle="Ponderada por saldo"
            />
            <TarjetaConteo
              etiqueta="Clientes con vencido"
              valor={numero(clientes.filter((c) => c.vencido > 0).length)}
            />
            <TarjetaConteo
              etiqueta="Clientes +90 días"
              valor={numero(clientes.filter((c) => c.vencidoCritico > 0).length)}
            />
          </section>

          {!viendoAsesor && porGestionar > 0 && (
            <Link
              href="/agenda"
              className="flex flex-wrap items-center justify-between gap-2 rounded-xl p-4"
              style={{
                background: "var(--superficie)",
                border: "1px solid var(--color-marca)",
                boxShadow: "var(--sombra)",
              }}
            >
              <span>
                <span className="block text-sm font-semibold">
                  {numero(porGestionar)}{" "}
                  {porGestionar === 1 ? "factura vence" : "facturas vencen"} esta semana
                </span>
                <span className="block text-xs" style={{ color: "var(--tinta-2)" }}>
                  Llamar antes del vencimiento es lo que evita la mora del mes entrante.
                </span>
              </span>
              <span className="text-sm font-semibold" style={{ color: "var(--color-marca-texto)" }}>
                Ver agenda →
              </span>
            </Link>
          )}

          <section
            className="rounded-xl p-4"
            style={{
              background: "var(--superficie)",
              border: "1px solid var(--borde)",
              boxShadow: "var(--sombra)",
            }}
          >
            <h2 className="mb-4 text-sm font-semibold uppercase tracking-wide" style={{ color: "var(--tinta-2)" }}>
              Antigüedad de la cartera
            </h2>
            <GraficoAntiguedad datos={k.porRango} />
            <details className="mt-4">
              <summary className="cursor-pointer text-xs font-medium" style={{ color: "var(--color-marca-texto)" }}>
                Ver como tabla
              </summary>
              <div className="mt-2">
                <TablaAntiguedad datos={k.porRango} />
              </div>
            </details>
          </section>

          {!viendoAsesor && (
            <section
              className="rounded-xl p-4"
              style={{
                background: "var(--superficie)",
                border: "1px solid var(--borde)",
                boxShadow: "var(--sombra)",
              }}
            >
              <h2 className="mb-3 text-sm font-semibold uppercase tracking-wide" style={{ color: "var(--tinta-2)" }}>
                Cómo evoluciona la mora
              </h2>
              <GraficoTendencia puntos={historico} />
              {historico.length >= 2 && (
                <details className="mt-3">
                  <summary className="cursor-pointer text-xs font-medium" style={{ color: "var(--color-marca-texto)" }}>
                    Ver como tabla
                  </summary>
                  <div className="mt-2">
                    <TablaTendencia puntos={historico} />
                  </div>
                </details>
              )}
            </section>
          )}

          {sobreCupo.length > 0 && (
            <section
              className="rounded-xl p-4"
              style={{
                background: "var(--superficie)",
                border: "1px solid var(--color-atencion)",
                boxShadow: "var(--sombra)",
              }}
            >
              <h2 className="text-sm font-semibold uppercase tracking-wide" style={{ color: "var(--tinta-2)" }}>
                <span aria-hidden="true">!</span> {numero(sobreCupo.length)}{" "}
                {sobreCupo.length === 1 ? "cliente supera" : "clientes superan"} su cupo
              </h2>
              <p className="mt-1 text-xs" style={{ color: "var(--tinta-3)" }}>
                Deben más de lo que tienen aprobado. Conviene revisarlo antes de
                autorizar un nuevo despacho.
              </p>
              <ul className="mt-3 flex list-none flex-col gap-2 p-0">
                {sobreCupo.slice(0, 5).map((c) => (
                  <li key={c.codigo_sn}>
                    <Link
                      href={`/cliente/${encodeURIComponent(c.codigo_sn)}`}
                      className="flex items-center justify-between gap-3 rounded-lg px-3 py-2"
                      style={{ background: "var(--plano)" }}
                    >
                      <span className="min-w-0">
                        <span className="block truncate text-sm font-medium">{c.nombre_sn}</span>
                        <span className="block text-xs" style={{ color: "var(--tinta-2)" }}>
                          Cupo {pesosCorto(Number(c.limite_credito))} · debe {pesosCorto(c.saldo)}
                        </span>
                      </span>
                      <span
                        className="tabular shrink-0 text-sm font-semibold"
                        style={{ color: "var(--color-atencion)" }}
                      >
                        +{pesosCorto(c.saldo - Number(c.limite_credito))}
                      </span>
                    </Link>
                  </li>
                ))}
              </ul>
            </section>
          )}

          {criticos.length > 0 && (
            <section
              className="rounded-xl p-4"
              style={{
                background: "var(--superficie)",
                border: "1px solid var(--borde)",
                boxShadow: "var(--sombra)",
              }}
            >
              <h2 className="mb-3 text-sm font-semibold uppercase tracking-wide" style={{ color: "var(--tinta-2)" }}>
                Atención prioritaria · más de 90 días
              </h2>
              <ul className="flex list-none flex-col gap-2 p-0">
                {criticos.map((c) => (
                  <li key={c.codigo_sn}>
                    <Link
                      href={`/cliente/${encodeURIComponent(c.codigo_sn)}`}
                      className="flex items-center justify-between gap-3 rounded-lg px-3 py-2"
                      style={{ background: "var(--plano)" }}
                    >
                      <span className="min-w-0">
                        <span className="block truncate text-sm font-medium">{c.nombre_sn}</span>
                        <span className="block text-xs" style={{ color: "var(--tinta-2)" }}>
                          {c.diasMaximo} días de mora
                        </span>
                      </span>
                      <span
                        className="tabular shrink-0 text-sm font-semibold"
                        style={{ color: "var(--color-critico)" }}
                      >
                        {pesosCorto(c.vencidoCritico)}
                      </span>
                    </Link>
                  </li>
                ))}
              </ul>
              <Link
                href="/clientes"
                className="mt-3 inline-block text-xs font-medium"
                style={{ color: "var(--color-marca-texto)" }}
              >
                Ver todos los clientes →
              </Link>
            </section>
          )}
        </>
      )}
    </div>
  );
}
