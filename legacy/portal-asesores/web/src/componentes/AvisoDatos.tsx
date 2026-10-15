import { fechaHora, haceCuanto } from "@/lib/formato";
import type { EstadoCarga } from "@/lib/tipos";

/**
 * Aviso de frescura del dato. Cuando el robot falla, los asesores siguen
 * viendo la ultima carga correcta: esto les dice de cuando es, para que
 * nadie llame a un cliente con cifras de anteayer creyendolas de hoy.
 */
export function AvisoDatos({
  estado,
  desactualizado,
}: {
  estado: EstadoCarga | null;
  desactualizado: boolean;
}) {
  if (!estado) {
    return (
      <Banda tono="critico" icono="▲">
        <strong>Sin datos.</strong> El robot de actualización aún no ha
        completado ninguna carga.
      </Banda>
    );
  }

  if (!desactualizado) {
    return (
      <p className="text-xs" style={{ color: "var(--tinta-3)" }}>
        Datos actualizados {haceCuanto(estado.terminado_en)} ·{" "}
        {fechaHora(estado.terminado_en)}
      </p>
    );
  }

  const fallo = estado.estado !== "OK";
  return (
    <Banda tono={fallo ? "critico" : "atencion"} icono={fallo ? "▲" : "!"}>
      <strong>
        {fallo ? "La última actualización falló." : "Datos posiblemente desactualizados."}
      </strong>{" "}
      Estás viendo la información del {fechaHora(estado.terminado_en)} (
      {haceCuanto(estado.terminado_en)}).
      {fallo && estado.etapa_fallida && (
        <> Etapa con problema: {estado.etapa_fallida}.</>
      )}
    </Banda>
  );
}

function Banda({
  tono,
  icono,
  children,
}: {
  tono: "atencion" | "critico";
  icono: string;
  children: React.ReactNode;
}) {
  const color = tono === "critico" ? "var(--color-critico)" : "var(--color-atencion)";
  return (
    <div
      role="status"
      className="flex items-start gap-2 rounded-lg p-3 text-sm"
      style={{ border: `1px solid ${color}`, background: "var(--superficie)" }}
    >
      <span aria-hidden="true" style={{ color }}>
        {icono}
      </span>
      <p className="m-0">{children}</p>
    </div>
  );
}
