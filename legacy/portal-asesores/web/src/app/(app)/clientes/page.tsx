import { BotonExcel } from "@/componentes/BotonExcel";
import { BotonReportePdf } from "@/componentes/BotonReportePdf";
import { ListaClientes } from "@/componentes/ListaClientes";
import { agruparPorCliente, obtenerCartera, obtenerUsuario } from "@/lib/cartera";

export const metadata = { title: "Clientes · Cartera SEGUREX" };
export const dynamic = "force-dynamic";

export default async function PaginaClientes() {
  const [usuario, docs] = await Promise.all([obtenerUsuario(), obtenerCartera()]);
  const clientes = agruparPorCliente(docs);

  // Indice de facturas por cliente: permite buscar "9290" y llegar al cliente
  // que la tiene, que es como pregunta el telefono.
  const facturas: Record<string, string> = {};
  for (const d of docs) {
    if (!d.documento) continue;
    facturas[d.codigo_sn] = `${facturas[d.codigo_sn] ?? ""} ${d.documento}`;
  }

  return (
    <div className="flex flex-col gap-4">
      <div className="flex flex-wrap items-start justify-between gap-3">
        <h1 className="text-xl font-bold sm:text-2xl">Clientes</h1>
        <div className="flex flex-wrap gap-2">
          <BotonExcel documentos={docs} nombre="cartera-por-cliente" />
          <BotonReportePdf
            documentos={docs}
            titulo="Cartera por cliente"
            responsable={usuario?.nombre ?? ""}
          />
        </div>
      </div>
      <ListaClientes clientes={clientes} facturas={facturas} />
    </div>
  );
}
