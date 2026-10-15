import { pesos, pesosCorto, porcentaje } from "@/lib/formato";

type Tono = "neutro" | "marca" | "bien" | "atencion" | "critico";

const TONOS: Record<Tono, { color: string; icono: string }> = {
  neutro: { color: "var(--tinta)", icono: "" },
  marca: { color: "var(--color-marca-texto)", icono: "" },
  bien: { color: "var(--color-bien)", icono: "✓" },
  atencion: { color: "var(--color-atencion)", icono: "!" },
  critico: { color: "var(--color-critico)", icono: "▲" },
};

/**
 * Cifra destacada. El estado (bien / atencion / critico) siempre viaja con
 * icono y etiqueta: el color nunca es el unico portador del significado.
 */
export function TarjetaIndicador({
  etiqueta,
  valor,
  detalle,
  tono = "neutro",
  compacto = false,
}: {
  etiqueta: string;
  valor: number;
  detalle?: React.ReactNode;
  tono?: Tono;
  compacto?: boolean;
}) {
  const t = TONOS[tono];
  return (
    <div
      className="rounded-xl p-4"
      style={{
        background: "var(--superficie)",
        border: "1px solid var(--borde)",
        boxShadow: "var(--sombra)",
      }}
    >
      <p
        className="text-xs font-medium uppercase tracking-wide"
        style={{ color: "var(--tinta-2)" }}
      >
        {etiqueta}
      </p>
      <p
        className="tabular mt-1 text-xl font-semibold sm:text-2xl"
        style={{ color: t.color }}
        title={pesos(valor)}
      >
        {t.icono && (
          <span aria-hidden="true" className="mr-1 text-base">
            {t.icono}
          </span>
        )}
        {compacto ? pesosCorto(valor) : pesos(valor)}
      </p>
      {detalle && (
        <p className="mt-1 text-xs" style={{ color: "var(--tinta-2)" }}>
          {detalle}
        </p>
      )}
    </div>
  );
}

export function TarjetaConteo({
  etiqueta,
  valor,
  detalle,
}: {
  etiqueta: string;
  valor: string | number;
  detalle?: React.ReactNode;
}) {
  return (
    <div
      className="rounded-xl p-4"
      style={{
        background: "var(--superficie)",
        border: "1px solid var(--borde)",
        boxShadow: "var(--sombra)",
      }}
    >
      <p
        className="text-xs font-medium uppercase tracking-wide"
        style={{ color: "var(--tinta-2)" }}
      >
        {etiqueta}
      </p>
      <p className="tabular mt-1 text-xl font-semibold sm:text-2xl">{valor}</p>
      {detalle && (
        <p className="mt-1 text-xs" style={{ color: "var(--tinta-2)" }}>
          {detalle}
        </p>
      )}
    </div>
  );
}

export { porcentaje };
