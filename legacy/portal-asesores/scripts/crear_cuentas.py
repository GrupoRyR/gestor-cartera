"""Crea las cuentas de acceso (Supabase Auth) de los usuarios dados de alta.

La tabla `usuarios` define QUE ve cada persona; esto crea la credencial CON LA
QUE entra. Son dos cosas distintas, y esta es la segunda.

El script es idempotente: solo crea las cuentas que faltan, nunca pisa una
contrasena existente. Ejecutalo cada vez que des de alta asesores nuevos en el
panel de administracion.

Uso:
    ..\\.venv\\Scripts\\python.exe scripts\\crear_cuentas.py
    ..\\.venv\\Scripts\\python.exe scripts\\crear_cuentas.py --reiniciar correo@x.com
"""
from __future__ import annotations

import argparse
import json
import secrets
import sys
import urllib.error
import urllib.request
from datetime import datetime
from pathlib import Path

RAIZ = Path(__file__).resolve().parent.parent
sys.path.insert(0, str(RAIZ / "etl"))

import psycopg  # noqa: E402
from config import cargar_config  # noqa: E402
from dotenv import dotenv_values  # noqa: E402

SECRETOS = dotenv_values(RAIZ / "etl" / ".env")
URL = (SECRETOS.get("SUPABASE_URL") or "").strip().rstrip("/")
CLAVE = (SECRETOS.get("SUPABASE_SERVICE_KEY") or "").strip()
SALIDA = RAIZ / "etl" / "state" / "credenciales_iniciales.txt"


def generar_password() -> str:
    """Suficientemente fuerte y todavia dictable por telefono."""
    return "Sgx-" + secrets.token_urlsafe(6)


def api(ruta: str, metodo: str = "GET", cuerpo: dict | None = None):
    peticion = urllib.request.Request(
        f"{URL}/auth/v1{ruta}",
        method=metodo,
        data=json.dumps(cuerpo).encode() if cuerpo else None,
        headers={
            "apikey": CLAVE,
            "Authorization": f"Bearer {CLAVE}",
            "Content-Type": "application/json",
        },
    )
    try:
        with urllib.request.urlopen(peticion, timeout=30) as r:
            return json.loads(r.read().decode() or "{}")
    except urllib.error.HTTPError as e:
        detalle = e.read().decode()
        raise RuntimeError(f"Supabase respondio {e.code}: {detalle[:300]}") from e


def cuentas_existentes() -> dict[str, str]:
    """Correo -> id de todas las cuentas ya creadas."""
    encontradas: dict[str, str] = {}
    pagina = 1
    while True:
        datos = api(f"/admin/users?page={pagina}&per_page=200")
        usuarios = datos.get("users", [])
        if not usuarios:
            break
        for u in usuarios:
            if u.get("email"):
                encontradas[u["email"].lower()] = u["id"]
        if len(usuarios) < 200:
            break
        pagina += 1
    return encontradas


def main() -> int:
    parser = argparse.ArgumentParser()
    parser.add_argument("--reiniciar", metavar="CORREO",
                        help="Asigna una contrasena nueva a una cuenta existente.")
    args = parser.parse_args()

    if not URL or not CLAVE:
        print("Faltan SUPABASE_URL o SUPABASE_SERVICE_KEY en etl/.env")
        return 2

    existentes = cuentas_existentes()

    if args.reiniciar:
        correo = args.reiniciar.lower()
        uid = existentes.get(correo)
        if not uid:
            print(f"No existe una cuenta para {correo}.")
            return 1
        nueva = generar_password()
        api(f"/admin/users/{uid}", "PUT", {"password": nueva})
        print(f"Contrasena de {correo} reiniciada: {nueva}")
        return 0

    with psycopg.connect(cargar_config().supabase_url, connect_timeout=25) as c:
        with c.cursor() as cur:
            cur.execute(
                'SELECT lower("email"), "nombre", "rol" FROM "usuarios" '
                'WHERE "activo" ORDER BY "rol", "nombre"'
            )
            registrados = cur.fetchall()

    nuevas: list[tuple[str, str, str]] = []
    for correo, nombre, rol in registrados:
        if correo in existentes:
            print(f"  ya existe   {correo}")
            continue
        clave = generar_password()
        api("/admin/users", "POST", {
            "email": correo,
            "password": clave,
            "email_confirm": True,          # sin servidor de correo configurado
            "user_metadata": {"nombre": nombre, "rol": rol},
        })
        nuevas.append((correo, nombre, clave))
        print(f"  CREADA      {correo}")

    if not nuevas:
        print("\nNo habia cuentas pendientes por crear.")
        return 0

    lineas = [
        "CREDENCIALES INICIALES - Cartera SEGUREX",
        f"Generadas el {datetime.now():%Y-%m-%d %H:%M}",
        "",
        "Entregalas por un canal seguro y pide que se cambien al primer ingreso.",
        "Este archivo esta excluido de Git; borralo cuando termines de repartirlas.",
        "",
    ]
    lineas += [f"{c:<40} {p}" for c, _, p in nuevas]
    SALIDA.parent.mkdir(parents=True, exist_ok=True)
    SALIDA.write_text("\n".join(lineas) + "\n", encoding="utf-8")

    print(f"\n{len(nuevas)} cuenta(s) creada(s). Credenciales guardadas en:")
    print(f"  {SALIDA}")
    print()
    for correo, nombre, clave in nuevas:
        print(f"  {correo:<40} {clave}   ({nombre})")
    return 0


if __name__ == "__main__":
    sys.exit(main())
