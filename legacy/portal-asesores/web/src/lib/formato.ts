/** Formateo en pesos colombianos y fechas locales. */

const MONEDA = new Intl.NumberFormat("es-CO", {
  style: "currency",
  currency: "COP",
  maximumFractionDigits: 0,
});

const MILLONES = new Intl.NumberFormat("es-CO", {
  minimumFractionDigits: 1,
  maximumFractionDigits: 1,
});

const MILES = new Intl.NumberFormat("es-CO", { maximumFractionDigits: 0 });

const NUMERO = new Intl.NumberFormat("es-CO");

export function pesos(valor: number | null | undefined): string {
  // es-CO intercala un espacio ("$ 1.000"); se quita para que la cifra completa
  // y la abreviada se vean igual cuando conviven en la misma pantalla.
  return MONEDA.format(Number(valor ?? 0)).replace(/\$\s+/, "$");
}

/**
 * Cifra abreviada para espacios estrechos (celular, celdas de tabla).
 *
 * El signo va siempre delante del s\u00EDmbolo ("-$5,0 M", nunca "$-5 M"), y los
 * millones llevan separador de miles: con saldos de miles de millones,
 * "$3.289,2 M" se lee de un golpe y "$3289,2 M" no.
 */
export function pesosCorto(valor: number | null | undefined): string {
  const n = Number(valor ?? 0);
  const signo = n < 0 ? "-" : "";
  const abs = Math.abs(n);

  if (abs < 1000) return `${signo}$${MILES.format(abs)}`;
  if (abs < 1_000_000) return `${signo}$${MILLONES.format(abs / 1000)}\u00A0k`;
  return `${signo}$${MILLONES.format(abs / 1_000_000)}\u00A0M`;
}

export function numero(valor: number | null | undefined): string {
  return NUMERO.format(Number(valor ?? 0));
}

export function porcentaje(valor: number, decimales = 1): string {
  return `${valor.toFixed(decimales).replace(".", ",")}%`;
}

export function fecha(iso: string | null | undefined): string {
  if (!iso) return "—";
  const d = new Date(`${iso}T00:00:00`);
  if (Number.isNaN(d.getTime())) return "—";
  return d.toLocaleDateString("es-CO", {
    day: "2-digit",
    month: "short",
    year: "numeric",
  });
}

export function fechaHora(iso: string | null | undefined): string {
  if (!iso) return "—";
  const d = new Date(iso);
  if (Number.isNaN(d.getTime())) return "—";
  return d.toLocaleString("es-CO", {
    day: "2-digit",
    month: "short",
    year: "numeric",
    hour: "2-digit",
    minute: "2-digit",
  });
}

/** "hace 3 horas" — para el aviso de frescura del dato. */
export function haceCuanto(iso: string | null | undefined): string {
  if (!iso) return "nunca";
  const ms = Date.now() - new Date(iso).getTime();
  if (Number.isNaN(ms)) return "nunca";
  const min = Math.floor(ms / 60000);
  if (min < 1) return "hace un momento";
  if (min < 60) return `hace ${min} min`;
  const h = Math.floor(min / 60);
  if (h < 24) return `hace ${h} ${h === 1 ? "hora" : "horas"}`;
  const d = Math.floor(h / 24);
  return `hace ${d} ${d === 1 ? "día" : "días"}`;
}

/** SAP antepone un codigo interno al nombre: "84 JESSIKA ..." -> "JESSIKA ...". */
export function nombreAsesor(nombre: string | null | undefined): string {
  if (!nombre) return "Sin asesor";
  return nombre.replace(/^\s*\d+\s+/, "").trim() || "Sin asesor";
}
