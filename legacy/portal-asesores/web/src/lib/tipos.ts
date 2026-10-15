/**
 * asesor  -> ve solo sus codigos (exclusivos entre asesores)
 * gerente -> ve los codigos de su equipo; PUEDE compartirlos con sus asesores
 * admin   -> ve toda la cartera de la empresa
 */
export type Rol = "asesor" | "gerente" | "admin";

export const ETIQUETA_ROL: Record<Rol, string> = {
  asesor: "Asesor",
  gerente: "Gerente de ventas",
  admin: "Administrador",
};

export interface Usuario {
  id: string;
  email: string;
  nombre: string;
  rol: Rol;
  slp_codes: number[];
  activo: boolean;
  creado_en: string;
}

/** Una fila = un documento pendiente de cobro. Refleja la tabla `cartera`. */
export interface DocumentoCartera {
  slp_code: number | null;
  codigo_sn: string;
  nombre_sn: string;
  nit: string | null;
  grupo: string | null;
  municipio: string | null;
  tipo_doc: string | null;
  documento: string | null;
  documento_base: string | null;
  no_referencia: string | null;
  asesor: string | null;
  condicion_pago: string | null;
  fecha_contabilizacion: string | null;
  fecha_vencimiento: string | null;
  fecha_documento: string | null;
  dias: number | null;
  limite_credito: number | null;
  /** Total del documento CON impuestos (el DocTotal de la factura). */
  importe_original: number | null;
  /** Mismo total ANTES de impuestos, con el IVA real del documento fiscal. */
  base_gravable: number | null;
  saldo: number | null;
  /** Saldo pendiente antes de impuestos (prorrateado con la tarifa del documento). */
  saldo_base: number | null;
  rango: Rango | null;
}

export const RANGOS = [
  "Sin vencer",
  "0-30",
  "31-60",
  "61-90",
  "91-120",
  "121-150",
  "151-180",
  "> 180",
] as const;

export type Rango = (typeof RANGOS)[number];

/** Un rango a partir de 91 dias ya es cartera de dificil cobro. */
export const RANGOS_CRITICOS: Rango[] = ["91-120", "121-150", "151-180", "> 180"];

export interface ResumenRango {
  rango: Rango;
  saldo: number;
  documentos: number;
  porcentaje: number;
}

export interface Indicadores {
  saldoTotal: number;
  /** El mismo saldo total, antes de impuestos. */
  saldoBase: number;
  sinVencer: number;
  vencido: number;
  vencidoCritico: number;
  documentos: number;
  clientes: number;
  porcentajeVencido: number;
  porRango: ResumenRango[];
  /** Dias promedio de vencimiento, ponderado por saldo. */
  diasPromedioPonderado: number;
}

export interface ResumenCliente {
  codigo_sn: string;
  nombre_sn: string;
  nit: string | null;
  municipio: string | null;
  grupo: string | null;
  asesor: string | null;
  documentos: number;
  saldo: number;
  sinVencer: number;
  vencido: number;
  vencidoCritico: number;
  /** Dias del documento mas atrasado. */
  diasMaximo: number;
  limite_credito: number | null;
}

/** Fila de la bitacora de cobro: una nota o un compromiso de pago. */
export interface Gestion {
  id: number;
  codigo_sn: string;
  nombre_sn: string | null;
  documento: string | null;
  slp_code: number;
  tipo: "nota" | "compromiso";
  nota: string;
  fecha_compromiso: string | null;
  valor_compromiso: number | null;
  estado: "abierto" | "cumplido" | "cancelado";
  creado_por: string;
  creado_en: string;
}

/**
 * Un compromiso abierto cuya fecha ya paso esta incumplido. No se guarda en
 * la base: se deduce, para que no dependa de que alguien corra un proceso.
 */
export function compromisoIncumplido(g: Gestion, hoy = new Date()): boolean {
  if (g.tipo !== "compromiso" || g.estado !== "abierto") return false;
  if (!g.fecha_compromiso) return false;
  return g.fecha_compromiso < hoy.toISOString().slice(0, 10);
}

/** Totales de un asesor: alimenta la vista de equipo de las gerentes. */
export interface ResumenAsesor {
  /** Todos los codigos SAP de esa persona: hay quien tiene dos. */
  codigos: number[];
  nombre: string;
  documentos: number;
  clientes: number;
  saldo: number;
  sinVencer: number;
  vencido: number;
  vencidoCritico: number;
  porcentajeVencido: number;
}

/** Un dia del historico, ya sumado sobre los codigos que el usuario ve. */
export interface PuntoHistorico {
  fecha: string;
  saldo: number;
  vencido: number;
  vencidoCritico: number;
}

export interface EstadoCarga {
  terminado_en: string | null;
  estado: string;
  filas_cartera: number | null;
  duracion_seg: number | null;
  etapa_fallida: string | null;
  mensaje_error: string | null;
}

/**
 * Codigos de SAP que no corresponden a una persona: son bolsas contables o
 * canales. Por decision del negocio los ve solo gerencia (rol admin), asi que
 * NO deben figurar como "pendientes de asignar" en el panel.
 */
export const CARTERAS_SIN_TITULAR = [
  "OTROS INGRESOS",
  "OFICINA - VARIOS",
  "E-COMMERCE",
  "VACANTE",
];

export function esCarteraSinTitular(nombreLimpio: string): boolean {
  return CARTERAS_SIN_TITULAR.includes(nombreLimpio.trim().toUpperCase());
}
