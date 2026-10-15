import { notFound } from "next/navigation";
import { PanelUsuarios, type AsesorSap } from "@/componentes/PanelUsuarios";
import { obtenerCartera, obtenerUsuario } from "@/lib/cartera";
import { crearClienteServidor } from "@/lib/supabase/servidor";
import { esCarteraSinTitular, type Usuario } from "@/lib/tipos";
import { nombreAsesor } from "@/lib/formato";

export const metadata = { title: "Administrar · Cartera SEGUREX" };
export const dynamic = "force-dynamic";

export default async function PaginaAdmin() {
  const usuario = await obtenerUsuario();
  if (usuario?.rol !== "admin") notFound();

  const supabase = await crearClienteServidor();
  const [{ data: usuarios }, docs] = await Promise.all([
    supabase.from("usuarios").select("*").order("rol").order("nombre"),
    obtenerCartera(),
  ]);

  const lista = (usuarios ?? []) as Usuario[];

  // Que codigo tiene ya usuario, y cuanta cartera representa cada uno.
  const duenoDe = new Map<number, string>();
  for (const u of lista) {
    if (!u.activo) continue;
    for (const c of u.slp_codes) duenoDe.set(c, u.email);
  }

  const porCodigo = new Map<number, AsesorSap>();
  for (const d of docs) {
    const codigo = d.slp_code ?? -1;
    let a = porCodigo.get(codigo);
    if (!a) {
      a = {
        slp_code: codigo,
        nombre: d.asesor ?? `Código ${codigo}`,
        documentos: 0,
        saldo: 0,
        asignado_a: duenoDe.get(codigo) ?? null,
        sin_titular: esCarteraSinTitular(nombreAsesor(d.asesor)),
      };
      porCodigo.set(codigo, a);
    }
    a.documentos += 1;
    a.saldo += Number(d.saldo ?? 0);
  }

  const asesores = [...porCodigo.values()].sort((a, b) => b.saldo - a.saldo);

  return (
    <div className="flex flex-col gap-4">
      <div>
        <h1 className="text-xl font-bold sm:text-2xl">Administrar accesos</h1>
        <p className="mt-1 text-sm" style={{ color: "var(--tinta-2)" }}>
          Cada usuario ve únicamente la cartera de los códigos de asesor que le
          asignes. Una persona puede tener varios códigos.
        </p>
      </div>
      <PanelUsuarios usuarios={lista} asesores={asesores} />
    </div>
  );
}
