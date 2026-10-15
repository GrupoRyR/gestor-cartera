import { fecha, pesos, pesosCorto } from "@/lib/formato";
import type { PuntoHistorico } from "@/lib/tipos";

const ANCHO = 640;
const ALTO = 170;
const MARGEN = { arriba: 16, derecha: 74, abajo: 26, izquierda: 8 };

/**
 * Evolucion del saldo vencido, un punto por dia.
 *
 * UNA sola serie a proposito: el vencido es la cifra por la que se mide al
 * asesor, y dos lineas de escala parecida (saldo y vencido) se estorban sin
 * aportar. El saldo total y el +90 estan en la tabla de abajo.
 *
 * Como el naranja de marca mide 2,91:1 sobre blanco, la linea no puede ser el
 * unico portador del dato: el ultimo valor va escrito junto al punto y existe
 * la vista de tabla.
 */
export function GraficoTendencia({ puntos }: { puntos: PuntoHistorico[] }) {
  if (puntos.length < 2) {
    return (
      <p className="text-sm" style={{ color: "var(--tinta-2)" }}>
        La historia se empieza a guardar hoy: el robot registra una foto de la
        cartera en cada actualización. Mañana ya podrás comparar.
      </p>
    );
  }

  const plotAncho = ANCHO - MARGEN.izquierda - MARGEN.derecha;
  const plotAlto = ALTO - MARGEN.arriba - MARGEN.abajo;
  const valores = puntos.map((p) => p.vencido);
  const maximo = Math.max(...valores, 1);

  const x = (i: number) =>
    MARGEN.izquierda + (puntos.length === 1 ? 0 : (i / (puntos.length - 1)) * plotAncho);
  const y = (v: number) => MARGEN.arriba + plotAlto - (Math.max(v, 0) / maximo) * plotAlto;

  const linea = puntos.map((p, i) => `${i === 0 ? "M" : "L"}${x(i)},${y(p.vencido)}`).join(" ");
  const area =
    `${linea} L${x(puntos.length - 1)},${MARGEN.arriba + plotAlto} ` +
    `L${x(0)},${MARGEN.arriba + plotAlto} Z`;

  const ultimo = puntos[puntos.length - 1];
  const primero = puntos[0];
  const variacion = ultimo.vencido - primero.vencido;

  return (
    <figure className="m-0">
      <svg
        viewBox={`0 0 ${ANCHO} ${ALTO}`}
        className="h-auto w-full"
        role="img"
        aria-label={`Evolución del saldo vencido entre el ${fecha(primero.fecha)} y el ${fecha(ultimo.fecha)}`}
      >
        {/* Rejilla discreta: solo el maximo y la base. */}
        <line
          x1={MARGEN.izquierda} y1={MARGEN.arriba}
          x2={MARGEN.izquierda + plotAncho} y2={MARGEN.arriba}
          stroke="var(--rejilla)" strokeWidth="1"
        />
        <line
          x1={MARGEN.izquierda} y1={MARGEN.arriba + plotAlto}
          x2={MARGEN.izquierda + plotAncho} y2={MARGEN.arriba + plotAlto}
          stroke="var(--rejilla)" strokeWidth="1"
        />

        <path d={area} fill="var(--serie)" opacity="0.10" />
        <path
          d={linea}
          fill="none"
          stroke="var(--serie)"
          strokeWidth="2"
          strokeLinejoin="round"
          strokeLinecap="round"
        />

        {puntos.map((p, i) => (
          <circle
            key={p.fecha}
            cx={x(i)}
            cy={y(p.vencido)}
            r={i === puntos.length - 1 ? 4.5 : 2.5}
            fill="var(--serie)"
            stroke="var(--superficie)"
            strokeWidth="2"
          >
            <title>{`${fecha(p.fecha)}: ${pesos(p.vencido)} vencido`}</title>
          </circle>
        ))}

        {/* Valor del ultimo dia, escrito: el color no carga el dato solo. */}
        <text
          x={MARGEN.izquierda + plotAncho + 8}
          y={y(ultimo.vencido) + 4}
          fontSize="13"
          fontWeight="600"
          fill="var(--tinta)"
        >
          {pesosCorto(ultimo.vencido)}
        </text>

        <text x={MARGEN.izquierda} y={ALTO - 6} fontSize="11" fill="var(--tinta-3)">
          {fecha(primero.fecha)}
        </text>
        <text
          x={MARGEN.izquierda + plotAncho}
          y={ALTO - 6}
          fontSize="11"
          fill="var(--tinta-3)"
          textAnchor="end"
        >
          {fecha(ultimo.fecha)}
        </text>
      </svg>

      <figcaption className="mt-2 text-xs" style={{ color: "var(--tinta-3)" }}>
        Saldo vencido día a día ({puntos.length} {puntos.length === 1 ? "día" : "días"} de
        historia).{" "}
        {variacion === 0
          ? "Sin cambio en el período."
          : variacion > 0
            ? `Subió ${pesosCorto(variacion)} desde el primer día.`
            : `Bajó ${pesosCorto(Math.abs(variacion))} desde el primer día.`}
      </figcaption>
    </figure>
  );
}

/** Mismo dato en tabla: exigido por la regla de relieve. */
export function TablaTendencia({ puntos }: { puntos: PuntoHistorico[] }) {
  return (
    <div className="scroll-x">
      <table className="w-full text-sm">
        <thead>
          <tr style={{ color: "var(--tinta-2)" }}>
            <th className="py-2 text-left font-medium">Día</th>
            <th className="py-2 text-right font-medium">Saldo</th>
            <th className="py-2 text-right font-medium">Vencido</th>
            <th className="py-2 text-right font-medium">+90 días</th>
          </tr>
        </thead>
        <tbody>
          {[...puntos].reverse().map((p) => (
            <tr key={p.fecha} style={{ borderTop: "1px solid var(--borde-suave)" }}>
              <td className="py-2">{fecha(p.fecha)}</td>
              <td className="tabular py-2 text-right">{pesos(p.saldo)}</td>
              <td className="tabular py-2 text-right font-medium">{pesos(p.vencido)}</td>
              <td className="tabular py-2 text-right" style={{ color: "var(--tinta-2)" }}>
                {pesos(p.vencidoCritico)}
              </td>
            </tr>
          ))}
        </tbody>
      </table>
    </div>
  );
}
