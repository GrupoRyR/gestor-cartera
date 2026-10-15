import { crearClienteServidor } from "@/lib/supabase/servidor";
import { nombreAsesor } from "@/lib/formato";
import {
  esCarteraSinTitular,
  RANGOS,
  RANGOS_CRITICOS,
  type DocumentoCartera,
  type EstadoCarga,
  type Indicadores,
  type PuntoHistorico,
  type Rango,
  type ResumenAsesor,
  type ResumenCliente,
  type Usuario,
} from "@/lib/tipos";

/** Supabase devuelve como maximo 1000 filas por peticion. */
const PAGINA = 1000;

const num = (v: number | null | undefined) => Number(v ?? 0);

/**
 * Ficha del usuario autenticado. Devuelve null si tiene cuenta en Supabase
 * pero nadie lo ha dado de alta todavia en el panel de administracion.
 */
export async function obtenerUsuario(): Promise<Usuario | null> {
  const supabase = await crearClienteServidor();
  const {
    data: { user },
  } = await supabase.auth.getUser();
  if (!user?.email) return null;

  const { data } = await supabase
    .from("usuarios")
    .select("*")
    .ilike("email", user.email)
    .maybeSingle();

  if (!data || !data.activo) return null;
  return data as Usuario;
}

/**
 * Documentos que el usuario puede ver. El filtrado real lo hace Postgres con
 * RLS; aqui solo se pide lo que ademas se quiera acotar en pantalla.
 */
export async function obtenerCartera(opciones?: {
  cliente?: string;
  slpCode?: number;
  /** Varios codigos: un asesor puede tener dos en SAP. */
  slpCodes?: number[];
}): Promise<DocumentoCartera[]> {
  const supabase = await crearClienteServidor();
  const filas: DocumentoCartera[] = [];

  for (let desde = 0; ; desde += PAGINA) {
    let consulta = supabase
      .from("cartera")
      .select("*")
      .order("nombre_sn")
      .order("fecha_vencimiento")
      .range(desde, desde + PAGINA - 1);

    if (opciones?.cliente) consulta = consulta.eq("codigo_sn", opciones.cliente);
    if (opciones?.slpCode !== undefined)
      consulta = consulta.eq("slp_code", opciones.slpCode);
    if (opciones?.slpCodes?.length)
      consulta = consulta.in("slp_code", opciones.slpCodes);

    const { data, error } = await consulta;
    if (error) throw new Error(`No se pudo leer la cartera: ${error.message}`);
    if (!data?.length) break;

    filas.push(...(data as DocumentoCartera[]));
    if (data.length < PAGINA) break;
  }

  return filas;
}

export function calcularIndicadores(docs: DocumentoCartera[]): Indicadores {
  const saldoTotal = docs.reduce((s, d) => s + num(d.saldo), 0);
  // Los recibos de caja y asientos manuales no tienen documento fiscal: el
  // ETL deja ahi base = saldo, asi que sumar la columna nunca pierde plata.
  const saldoBase = docs.reduce((s, d) => s + num(d.saldo_base), 0);

  const porRangoMapa = new Map<Rango, { saldo: number; documentos: number }>();
  for (const r of RANGOS) porRangoMapa.set(r, { saldo: 0, documentos: 0 });

  let sinVencer = 0;
  let vencido = 0;
  let vencidoCritico = 0;
  let diasPonderados = 0;
  let baseVencida = 0;

  for (const d of docs) {
    const saldo = num(d.saldo);
    const rango = (d.rango ?? "Sin vencer") as Rango;
    const acc = porRangoMapa.get(rango);
    if (acc) {
      acc.saldo += saldo;
      acc.documentos += 1;
    }

    if (rango === "Sin vencer") {
      sinVencer += saldo;
    } else {
      vencido += saldo;
      if (RANGOS_CRITICOS.includes(rango)) vencidoCritico += saldo;
      // Solo los saldos positivos aportan al promedio: una nota credito no
      // "lleva" dias de mora, y con signo negativo distorsionaria la media.
      if (saldo > 0) {
        diasPonderados += num(d.dias) * saldo;
        baseVencida += saldo;
      }
    }
  }

  const porRango = RANGOS.map((rango) => {
    const v = porRangoMapa.get(rango)!;
    return {
      rango,
      saldo: v.saldo,
      documentos: v.documentos,
      porcentaje: saldoTotal !== 0 ? (v.saldo / saldoTotal) * 100 : 0,
    };
  });

  return {
    saldoTotal,
    saldoBase,
    sinVencer,
    vencido,
    vencidoCritico,
    documentos: docs.length,
    clientes: new Set(docs.map((d) => d.codigo_sn)).size,
    porcentajeVencido: saldoTotal !== 0 ? (vencido / saldoTotal) * 100 : 0,
    porRango,
    diasPromedioPonderado: baseVencida > 0 ? diasPonderados / baseVencida : 0,
  };
}

export function agruparPorCliente(docs: DocumentoCartera[]): ResumenCliente[] {
  const mapa = new Map<string, ResumenCliente>();

  for (const d of docs) {
    let c = mapa.get(d.codigo_sn);
    if (!c) {
      c = {
        codigo_sn: d.codigo_sn,
        nombre_sn: d.nombre_sn,
        nit: d.nit,
        municipio: d.municipio,
        grupo: d.grupo,
        asesor: d.asesor,
        documentos: 0,
        saldo: 0,
        sinVencer: 0,
        vencido: 0,
        vencidoCritico: 0,
        diasMaximo: Number.NEGATIVE_INFINITY,
        limite_credito: d.limite_credito,
      };
      mapa.set(d.codigo_sn, c);
    }

    const saldo = num(d.saldo);
    const rango = (d.rango ?? "Sin vencer") as Rango;
    c.documentos += 1;
    c.saldo += saldo;
    if (rango === "Sin vencer") {
      c.sinVencer += saldo;
    } else {
      c.vencido += saldo;
      if (RANGOS_CRITICOS.includes(rango)) c.vencidoCritico += saldo;
    }
    c.diasMaximo = Math.max(c.diasMaximo, num(d.dias));
  }

  return [...mapa.values()]
    .map((c) => ({
      ...c,
      diasMaximo: Number.isFinite(c.diasMaximo) ? c.diasMaximo : 0,
    }))
    // Primero quien mas debe vencido: es la lista de trabajo del asesor.
    .sort((a, b) => b.vencido - a.vencido || b.saldo - a.saldo);
}

/**
 * Totales por asesor. Es lo que la gerente necesita y hoy no tiene: ver el
 * equipo desglosado en vez de una sola cifra sumada.
 */
export function resumirPorAsesor(docs: DocumentoCartera[]): ResumenAsesor[] {
  const mapa = new Map<string, ResumenAsesor & { _clientes: Set<string> }>();

  for (const d of docs) {
    const codigo = d.slp_code ?? -1;
    const nombre = nombreAsesor(d.asesor);

    // Cuatro asesores tienen dos codigos en SAP y deben verse como UNA
    // persona. Las bolsas sin titular (VACANTE, E-COMMERCE...) NO se agrupan
    // por nombre: son zonas distintas, repartidas entre personas distintas.
    const llave = esCarteraSinTitular(nombre) ? `c${codigo}` : `n${nombre}`;

    let a = mapa.get(llave);
    if (!a) {
      a = {
        codigos: [],
        nombre,
        documentos: 0,
        clientes: 0,
        saldo: 0,
        sinVencer: 0,
        vencido: 0,
        vencidoCritico: 0,
        porcentajeVencido: 0,
        _clientes: new Set<string>(),
      };
      mapa.set(llave, a);
    }

    if (!a.codigos.includes(codigo)) a.codigos.push(codigo);

    const saldo = num(d.saldo);
    const rango = (d.rango ?? "Sin vencer") as Rango;
    a.documentos += 1;
    a.saldo += saldo;
    a._clientes.add(d.codigo_sn);
    if (rango === "Sin vencer") {
      a.sinVencer += saldo;
    } else {
      a.vencido += saldo;
      if (RANGOS_CRITICOS.includes(rango)) a.vencidoCritico += saldo;
    }
  }

  return [...mapa.values()]
    .map(({ _clientes, ...a }) => ({
      ...a,
      codigos: [...a.codigos].sort((x, y) => x - y),
      clientes: _clientes.size,
      porcentajeVencido: a.saldo !== 0 ? (a.vencido / a.saldo) * 100 : 0,
    }))
    .sort((x, y) => y.vencido - x.vencido || y.saldo - x.saldo);
}

/**
 * Historico diario, ya sumado sobre los codigos que el usuario puede ver
 * (RLS se encarga de que no lleguen filas ajenas).
 */
export async function obtenerHistorico(dias = 60): Promise<PuntoHistorico[]> {
  const supabase = await crearClienteServidor();
  const desde = new Date();
  desde.setDate(desde.getDate() - dias);

  const { data, error } = await supabase
    .from("historico_cartera")
    .select("fecha, saldo, vencido, vencido_critico")
    .gte("fecha", desde.toISOString().slice(0, 10))
    .order("fecha");

  // El historico es un extra: si falla, la pagina se dibuja igual sin grafico.
  if (error || !data) return [];

  const porFecha = new Map<string, PuntoHistorico>();
  for (const f of data as Array<Record<string, unknown>>) {
    const fecha = String(f.fecha);
    const p = porFecha.get(fecha) ?? {
      fecha,
      saldo: 0,
      vencido: 0,
      vencidoCritico: 0,
    };
    p.saldo += num(f.saldo as number);
    p.vencido += num(f.vencido as number);
    p.vencidoCritico += num(f.vencido_critico as number);
    porFecha.set(fecha, p);
  }

  return [...porFecha.values()].sort((a, b) => a.fecha.localeCompare(b.fecha));
}

/**
 * Agenda de cobro. El dato "dias" ya viene calculado por SAP: negativo es
 * que aun no vence, 0 vence hoy, positivo son dias de mora.
 */
export function agendaDeCobro(docs: DocumentoCartera[]) {
  const conSaldo = docs.filter((d) => num(d.saldo) > 0);
  const dia = (d: DocumentoCartera) => num(d.dias);
  const porFecha = (a: DocumentoCartera, b: DocumentoCartera) =>
    dia(b) - dia(a);

  return {
    venceHoy: conSaldo.filter((d) => dia(d) === 0).sort(porFecha),
    proximos: conSaldo
      .filter((d) => dia(d) < 0 && dia(d) >= -7)
      .sort(porFecha),
    recienVencidos: conSaldo
      .filter((d) => dia(d) > 0 && dia(d) <= 30)
      .sort(porFecha),
  };
}

/**
 * Clientes que superaron su cupo. El limite ya venia en los datos y no se
 * estaba usando; despachar por encima del cupo es justo lo que hay que evitar.
 */
export function clientesSobreCupo(clientes: ResumenCliente[]): ResumenCliente[] {
  return clientes
    .filter((c) => Number(c.limite_credito ?? 0) > 0 && c.saldo > Number(c.limite_credito))
    .sort(
      (a, b) =>
        b.saldo - Number(b.limite_credito) - (a.saldo - Number(a.limite_credito)),
    );
}

/** Ultima ejecucion del robot, para avisar si el dato no es de hoy. */
export async function obtenerEstadoCarga(): Promise<EstadoCarga | null> {
  const supabase = await crearClienteServidor();
  const { data } = await supabase
    .from("v_ultima_carga")
    .select("*")
    .maybeSingle();
  return (data as EstadoCarga) ?? null;
}

/**
 * El dato se considera viejo pasadas 14 horas: el robot corre cada 12, asi
 * que un margen de 2 horas evita falsas alarmas por demoras normales.
 */
export function datoDesactualizado(estado: EstadoCarga | null): boolean {
  if (!estado?.terminado_en) return true;
  if (estado.estado !== "OK") return true;
  const horas = (Date.now() - new Date(estado.terminado_en).getTime()) / 3_600_000;
  return horas > 14;
}
