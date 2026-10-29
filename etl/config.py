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
# Id del pedido de la app de ventas en OINV: campo de usuario que solo existe
# en Segurex. El .sql lleva la expresion de Segurex tal cual; para Derivados
# leer_query reemplaza la que precede al marcador `-- @id_pedido_app`.
ID_PEDIDO_APP = {
    "SGX": 'CAST(T0."U_SGX_IdPedidoApp" AS NVARCHAR(254))',
    "SDC": "CAST(NULL AS NVARCHAR(254))",
}
# Consultas que dependen de la empresa y los marcadores que deben llevar: sin
# ellos fallan en vez de correr con datos de otra empresa.
CONSULTAS_POR_EMPRESA = {
    "cartera.sql": ("@cuentas_por_cobrar",),
    "ventas.sql": ("@id_pedido_app",),
}
_MARCADOR_CUENTAS = re.compile(
    r"\([^()]*\)(?=[ \t]*--[ \t]*@cuentas_por_cobrar)"
)
_MARCADOR_ID_PEDIDO_APP = re.compile(
    r'CAST\([^\n]*?\)(?=[ \t]+AS[ \t]+"IdPedidoApp"[ \t]*--[ \t]*@id_pedido_app)'
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
class EmpresaConfig:
    """Una empresa de la corrida: su esquema HANA y el usuario con que se entra a el."""

    codigo: str
    esquema: str
    usuario: str = ""
    password: str = ""


@dataclass(frozen=True)
class Config:
    vpn: VpnConfig
    hana: HanaConfig
    supabase_url: str
    alertas: AlertaConfig
    ingesta: IngestaConfig
    lotes: tuple[str, ...]
    # ETL_EMPRESAS: vacio = una sola empresa (INGESTA_EMPRESA con HANA_SCHEMA).
    empresas: tuple[EmpresaConfig, ...] = ()

    @property
    def empresas_efectivas(self) -> tuple[EmpresaConfig, ...]:
        """Las empresas que corre el robot, en orden."""
        return self.empresas or (
            EmpresaConfig(
                self.ingesta.empresa, self.hana.esquema,
                self.hana.usuario, self.hana.password,
            ),
        )

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
        faltantes.extend(self._faltantes_empresas(requiere_esquema))
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

    def _faltantes_empresas(self, requiere_esquema: bool) -> list[str]:
        """Usuario, contrasena y esquema de HANA de cada empresa de la corrida."""
        faltantes: list[str] = []
        if self.empresas:
            codigos = [e.codigo for e in self.empresas]
            desconocidas = [c for c in codigos if c not in EMPRESAS]
            if desconocidas:
                faltantes.append(
                    f"ETL_EMPRESAS tiene empresas desconocidas ({', '.join(desconocidas)}); "
                    f"las validas son {', '.join(EMPRESAS)}"
                )
            if len(set(codigos)) != len(codigos):
                faltantes.append("ETL_EMPRESAS repite una empresa")
        # Una sola empresa tambien acepta las variables sueltas de siempre.
        suelta = "" if self.empresas else " o HANA_SCHEMA"
        for e in self.empresas_efectivas:
            c = e.codigo
            if not e.usuario:
                faltantes.append(
                    f"HANA_USER_{c} (usuario de HANA de la empresa {c}; "
                    "si no existe se usa HANA_USER)"
                )
            if not e.password:
                faltantes.append(
                    f"HANA_PASSWORD_{c} (contrasena de HANA de la empresa {c}; "
                    "si no existe se usa HANA_PASSWORD)"
                )
            if requiere_esquema and not e.esquema:
                faltantes.append(
                    f"HANA_SCHEMA_{c}{suelta} "
                    f"(esquema de SAP B1 de la empresa {c}, ej: SBO_SEGUREX)"
                )
        return faltantes

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


def _credencial(nombre: str, empresa: str) -> str:
    """HANA_<nombre>_<EMPRESA> y, si no esta, HANA_<nombre> (sin quitar espacios)."""
    propia = os.getenv(f"HANA_{nombre}_{empresa}", "") if empresa else ""
    return propia or os.getenv(f"HANA_{nombre}", "")


def leer_hana(empresa: str = "") -> HanaConfig:
    """Variables HANA_* del entorno (cargar_config ya cargo etl/.env).

    Con empresa (la de una corrida de una sola empresa) usuario, contrasena y
    esquema son HANA_USER_<EMPRESA>, HANA_PASSWORD_<EMPRESA> y HANA_SCHEMA_<EMPRESA>,
    y cada una cae a la variable suelta si no existe.
    """
    return HanaConfig(
        host=os.getenv("HANA_HOST", "").strip(),
        port=_int(os.getenv("HANA_PORT"), 30015),
        usuario=_credencial("USER", empresa).strip(),
        password=_credencial("PASSWORD", empresa),
        esquema=_credencial("SCHEMA", empresa).strip(),
        timeout=_int(os.getenv("HANA_TIMEOUT"), 600),
        encrypt=_bool_seguro(os.getenv("HANA_ENCRYPT"), True),
        validar_certificado=_bool_seguro(os.getenv("HANA_SSL_VALIDATE"), True),
        truststore=os.getenv("HANA_SSL_TRUSTSTORE", "").strip(),
    )


def leer_empresas() -> tuple[EmpresaConfig, ...]:
    """ETL_EMPRESAS ("SGX,SDC") con el esquema y el usuario de HANA de cada una.

    El esquema es HANA_SCHEMA_<EMPRESA>; usuario y contrasena son
    HANA_USER_<EMPRESA> y HANA_PASSWORD_<EMPRESA>, o HANA_USER / HANA_PASSWORD
    si la empresa no tiene los suyos. Vacio si ETL_EMPRESAS no esta: el robot
    sigue con una sola empresa.
    """
    codigos: list[str] = []
    for parte in os.getenv("ETL_EMPRESAS", "").split(","):
        codigo = parte.strip().upper()
        if codigo and codigo not in codigos:
            codigos.append(codigo)
    return tuple(
        EmpresaConfig(
            c,
            os.getenv(f"HANA_SCHEMA_{c}", "").strip(),
            _credencial("USER", c).strip(),
            _credencial("PASSWORD", c),
        )
        for c in codigos
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

    empresa = os.getenv("INGESTA_EMPRESA", "").strip().upper() or "SGX"
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
        hana=leer_hana(empresa),
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
            empresa=empresa,
            timeout=_int(os.getenv("INGESTA_TIMEOUT"), 120),
            gzip=_bool(os.getenv("INGESTA_GZIP"), True),
        ),
        lotes=parsear_lotes(
            os.getenv("ETL_LOTES", "").strip() or LOTES_POR_DEFECTO
        ),
        empresas=leer_empresas(),
    )


def leer_query(nombre: str, empresa: str = "SGX") -> str:
    """Lee la consulta y pone los valores propios de la empresa.

    Solo cambia lo que lleva un marcador (`-- @cuentas_por_cobrar`,
    `-- @id_pedido_app`); para SGX el resultado es el archivo sin tocar.
    """
    if empresa not in CUENTAS_POR_COBRAR:
        raise ConfigError(
            f"Empresa desconocida: {empresa}. Las validas son {' o '.join(EMPRESAS)}."
        )
    ruta = QUERIES_DIR / nombre
    if not ruta.exists():
        raise ConfigError(f"No existe la consulta {ruta}")
    lista = ", ".join(f"'{c}'" for c in CUENTAS_POR_COBRAR[empresa])
    sql = ruta.read_text(encoding="utf-8")
    sql, cuentas = _MARCADOR_CUENTAS.subn(lambda _: f"({lista})", sql)
    sql, pedido_app = _MARCADOR_ID_PEDIDO_APP.subn(
        lambda _: ID_PEDIDO_APP[empresa], sql
    )
    encontrados = {"@cuentas_por_cobrar": cuentas, "@id_pedido_app": pedido_app}
    for marcador in CONSULTAS_POR_EMPRESA.get(nombre, ()):
        if encontrados[marcador] != 1:
            raise ConfigError(
                f"{ruta} debe tener exactamente un valor marcado con "
                f"'-- {marcador}' en la misma linea (encontrados: "
                f"{encontrados[marcador]})."
            )
    return sql
