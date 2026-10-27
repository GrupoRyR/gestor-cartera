"""Carga y validacion de la configuracion del ETL (archivo .env)."""
from __future__ import annotations

import os
import re
from dataclasses import dataclass, field
from pathlib import Path
from urllib.parse import urlsplit

from dotenv import load_dotenv

from esquema import LOTES

BASE_DIR = Path(__file__).resolve().parent
ENV_PATH = BASE_DIR / ".env"
QUERIES_DIR = BASE_DIR / "queries"
LOGS_DIR = BASE_DIR / "logs"
OUT_DIR = BASE_DIR / "out"
STATE_DIR = BASE_DIR / "state"

LOTES_POR_DEFECTO = "cartera,asesores"
# Cuentas por cobrar de clientes de cada empresa (JDT1.Account). El archivo
# .sql lleva las de Segurex tal cual, para que corra sin cambios en el Query
# Manager; leer_query reemplaza la lista que precede al marcador de abajo.
CUENTAS_POR_COBRAR = {
    "SGX": ("13050505", "13051005"),
    "SDC": ("13050501", "13051005"),
}
EMPRESAS = tuple(CUENTAS_POR_COBRAR)
# Consultas que filtran por cuenta: sin el marcador fallan en vez de correr con
# las cuentas de otra empresa.
CONSULTAS_POR_EMPRESA = ("cartera.sql",)
_MARCADOR_CUENTAS = re.compile(
    r"\([^()]*\)(?=[ \t]*--[ \t]*@cuentas_por_cobrar)"
)
# http:// solo se acepta contra un Laravel local de desarrollo.
HOSTS_LOCALES = ("localhost", "127.0.0.1", "::1")


class ConfigError(RuntimeError):
    """Falta configuracion obligatoria o tiene un valor invalido."""


def _bool(valor: str | None, por_defecto: bool = False) -> bool:
    if valor is None or valor.strip() == "":
        return por_defecto
    return valor.strip().lower() in {"1", "true", "si", "sí", "yes", "y"}


def _bool_seguro(valor: str | None, por_defecto: bool) -> bool:
    """Como _bool, pero un valor que no se reconoce deja el valor por
    defecto: para banderas de seguridad, donde un error de tipeo
    (HANA_ENCRYPT=ture) no debe apagar la proteccion sin querer."""
    texto = (valor or "").strip().lower()
    if texto in {"1", "true", "si", "sí", "yes", "y"}:
        return True
    if texto in {"0", "false", "no", "n"}:
        return False
    return por_defecto


def _int(valor: str | None, por_defecto: int) -> int:
    try:
        return int(str(valor).strip())
    except (TypeError, ValueError):
        return por_defecto


@dataclass(frozen=True)
class VpnConfig:
    cli: Path
    conexion: str
    usuario: str
    password: str
    timeout: int
    desconectar_al_final: bool


@dataclass(frozen=True)
class HanaConfig:
    host: str
    port: int
    usuario: str
    password: str
    esquema: str
    timeout: int
    # TLS hacia HANA. Se apaga desde etl/.env solo si el servidor no lo soporta.
    encrypt: bool = True
    validar_certificado: bool = True
    truststore: str = ""  # CA/PEM o almacen de confianza del servidor (opcional)


@dataclass(frozen=True)
class AlertaConfig:
    smtp_host: str
    smtp_port: int
    smtp_user: str
    smtp_password: str
    destinatarios: list[str] = field(default_factory=list)

    @property
    def habilitada(self) -> bool:
        return bool(self.smtp_host and self.destinatarios)


@dataclass(frozen=True)
class IngestaConfig:
    """Destino principal: el modulo Ingesta de Laravel (gestor de cartera)."""

    url: str          # raiz del sitio, sin /api (ej: https://cartera.segurex.com)
    key_id: str       # id de la llave HMAC (ej: k2026a)
    secreto: str      # secreto del par id:secreto de INGESTA_HMAC_KEYS en Laravel
    empresa: str = "SGX"
    timeout: int = 120
    gzip: bool = True

    @property
    def habilitada(self) -> bool:
        return bool(self.url and self.key_id and self.secreto)


@dataclass(frozen=True)
class Config:
    vpn: VpnConfig
    hana: HanaConfig
    supabase_url: str
    alertas: AlertaConfig
    ingesta: IngestaConfig
    lotes: tuple[str, ...]

    def validar(
        self, *, requiere_destino: bool = True, requiere_esquema: bool = True
    ) -> None:
        """Lanza ConfigError con un mensaje accionable si falta algo.

        Fuera del modo diagnostico (requiere_destino) exige al menos un destino:
        Laravel (principal) o Supabase (respaldo).
        """
        faltantes: list[str] = []
        if not self.vpn.usuario:
            faltantes.append("SOPHOS_USER (usuario de la VPN Sophos)")
        if not self.vpn.password:
            faltantes.append("SOPHOS_PASSWORD (contrasena de la VPN Sophos)")
        if not self.hana.host:
            faltantes.append("HANA_HOST (IP o nombre del servidor SAP HANA)")
        if not self.hana.usuario:
            faltantes.append("HANA_USER")
        if not self.hana.password:
            faltantes.append("HANA_PASSWORD")
        if requiere_esquema and not self.hana.esquema:
            faltantes.append("HANA_SCHEMA (esquema de SAP B1, ej: SBO_SEGUREX)")
        if self.ingesta.empresa not in EMPRESAS:
            faltantes.append(
                f"INGESTA_EMPRESA debe ser {' o '.join(EMPRESAS)} "
                f"(valor actual: {self.ingesta.empresa})"
            )
        if requiere_destino:
            faltantes.extend(self._faltantes_destino())

        if not self.vpn.cli.exists():
            faltantes.append(
                f"SOPHOS_CLI apunta a un archivo inexistente: {self.vpn.cli}"
            )

        if faltantes:
            detalle = "\n  - ".join(faltantes)
            raise ConfigError(
                "Faltan datos obligatorios o hay valores invalidos en "
                f"{ENV_PATH}:\n  - {detalle}\n\n"
                "Edita ese archivo y vuelve a ejecutar."
            )

    def _faltantes_destino(self) -> list[str]:
        ingesta = self.ingesta
        partes = {
            "INGESTA_URL": ingesta.url,
            "INGESTA_KEY_ID": ingesta.key_id,
            "INGESTA_SECRET": ingesta.secreto,
        }
        definidas = [nombre for nombre, valor in partes.items() if valor]
        faltantes: list[str] = []
        if definidas and len(definidas) < len(partes):
            # A medio configurar: mejor fallar que publicar solo en el
            # respaldo creyendo que Laravel esta recibiendo los datos.
            faltantes.extend(
                f"{nombre} (el destino Laravel esta a medio configurar)"
                for nombre, valor in partes.items()
                if not valor
            )
        elif not definidas and not self.supabase_url:
            faltantes.append(
                "Un destino: INGESTA_URL + INGESTA_KEY_ID + INGESTA_SECRET "
                "(Laravel, principal) o SUPABASE_DB_URL (Supabase, respaldo)"
            )
        if ingesta.url and not _url_segura(ingesta.url):
            faltantes.append(
                "INGESTA_URL debe empezar por https:// (http:// solo se acepta "
                f"para un Laravel local). Valor actual: {ingesta.url}"
            )
        return faltantes


def _url_segura(url: str) -> bool:
    """https siempre; http solo contra el propio equipo o un dominio .test."""
    partes = urlsplit(url)
    if not partes.hostname:
        return False
    if partes.scheme == "https":
        return True
    host = partes.hostname.lower()
    return partes.scheme == "http" and (
        host in HOSTS_LOCALES
        or host.endswith(".localhost")
        or host.endswith(".test")
    )


def parsear_lotes(texto: str | None) -> tuple[str, ...]:
    """Convierte "cartera, ventas" en ("cartera", "ventas") y valida los nombres."""
    nombres: list[str] = []
    for parte in (texto or "").split(","):
        nombre = parte.strip().lower()
        if nombre and nombre not in nombres:
            nombres.append(nombre)
    validos = ", ".join(LOTES)
    if not nombres:
        raise ConfigError(f"No se indico ningun lote. Los validos son: {validos}.")
    desconocidos = [n for n in nombres if n not in LOTES]
    if desconocidos:
        raise ConfigError(
            "Lote(s) desconocido(s) en ETL_LOTES o --lotes: "
            f"{', '.join(desconocidos)}. Los validos son: {validos}."
        )
    return tuple(nombres)


def leer_hana() -> HanaConfig:
    """Variables HANA_* del entorno (cargar_config ya cargo etl/.env)."""
    return HanaConfig(
        host=os.getenv("HANA_HOST", "").strip(),
        port=_int(os.getenv("HANA_PORT"), 30015),
        usuario=os.getenv("HANA_USER", "").strip(),
        password=os.getenv("HANA_PASSWORD", ""),
        esquema=os.getenv("HANA_SCHEMA", "").strip(),
        timeout=_int(os.getenv("HANA_TIMEOUT"), 600),
        encrypt=_bool_seguro(os.getenv("HANA_ENCRYPT"), True),
        validar_certificado=_bool_seguro(os.getenv("HANA_SSL_VALIDATE"), True),
        truststore=os.getenv("HANA_SSL_TRUSTSTORE", "").strip(),
    )


def cargar_config() -> Config:
    """Lee etl/.env. Lanza ConfigError si ETL_LOTES trae un lote desconocido."""
    load_dotenv(ENV_PATH, override=True)
    for carpeta in (LOGS_DIR, OUT_DIR, STATE_DIR):
        carpeta.mkdir(parents=True, exist_ok=True)

    destinatarios = [
        c.strip()
        for c in os.getenv("ALERTA_DESTINATARIOS", "").split(",")
        if c.strip()
    ]

    secreto_ingesta = os.getenv("INGESTA_SECRET", "").strip()
    # Import diferido: registro importa este modulo (LOGS_DIR) y un import
    # arriba seria circular. Desde aqui el secreto nunca sale en los logs.
    from registro import registrar_secreto
    registrar_secreto(secreto_ingesta)

    return Config(
        vpn=VpnConfig(
            cli=Path(
                os.getenv(
                    "SOPHOS_CLI",
                    r"C:\Program Files (x86)\Sophos\Connect\sccli.exe",
                )
            ),
            conexion=os.getenv("SOPHOS_CONNECTION", "").strip(),
            usuario=os.getenv("SOPHOS_USER", "").strip(),
            password=os.getenv("SOPHOS_PASSWORD", ""),
            timeout=_int(os.getenv("SOPHOS_TIMEOUT"), 90),
            desconectar_al_final=_bool(
                os.getenv("SOPHOS_DISCONNECT_AL_FINAL"), True
            ),
        ),
        hana=leer_hana(),
        supabase_url=os.getenv("SUPABASE_DB_URL", "").strip(),
        alertas=AlertaConfig(
            smtp_host=os.getenv("ALERTA_SMTP_HOST", "").strip(),
            smtp_port=_int(os.getenv("ALERTA_SMTP_PORT"), 587),
            smtp_user=os.getenv("ALERTA_SMTP_USER", "").strip(),
            smtp_password=os.getenv("ALERTA_SMTP_PASSWORD", ""),
            destinatarios=destinatarios,
        ),
        ingesta=IngestaConfig(
            url=os.getenv("INGESTA_URL", "").strip(),
            key_id=os.getenv("INGESTA_KEY_ID", "").strip(),
            secreto=secreto_ingesta,
            empresa=os.getenv("INGESTA_EMPRESA", "").strip().upper() or "SGX",
            timeout=_int(os.getenv("INGESTA_TIMEOUT"), 120),
            gzip=_bool(os.getenv("INGESTA_GZIP"), True),
        ),
        lotes=parsear_lotes(
            os.getenv("ETL_LOTES", "").strip() or LOTES_POR_DEFECTO
        ),
    )


def leer_query(nombre: str, empresa: str = "SGX") -> str:
    """Lee la consulta y pone las cuentas por cobrar de la empresa.

    Solo cambia la lista que lleva el marcador `-- @cuentas_por_cobrar`; para
    SGX el resultado es el archivo sin tocar.
    """
    if empresa not in CUENTAS_POR_COBRAR:
        raise ConfigError(
            f"Empresa desconocida: {empresa}. Las validas son {' o '.join(EMPRESAS)}."
        )
    ruta = QUERIES_DIR / nombre
    if not ruta.exists():
        raise ConfigError(f"No existe la consulta {ruta}")
    lista = ", ".join(f"'{c}'" for c in CUENTAS_POR_COBRAR[empresa])
    sql, reemplazos = _MARCADOR_CUENTAS.subn(
        lambda _: f"({lista})", ruta.read_text(encoding="utf-8")
    )
    if nombre in CONSULTAS_POR_EMPRESA and reemplazos != 1:
        raise ConfigError(
            f"{ruta} debe tener exactamente un filtro de cuentas marcado con "
            f"'-- @cuentas_por_cobrar' en la misma linea (encontrados: {reemplazos})."
        )
    return sql
