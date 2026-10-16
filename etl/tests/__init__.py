"""Pruebas del robot, solo con la biblioteca estandar (unittest).

Ejecutalas desde la raiz del repositorio:

    python -m unittest discover -s etl/tests -t etl -v

No usan la VPN, SAP, Laravel ni Supabase: todo con datos de muestra. Si
python-dotenv no esta instalado (las pruebas no lo necesitan), se instala un
modulo falso para que config.py se pueda importar.
"""
import logging
import sys
import types

try:
    import dotenv  # noqa: F401
except ImportError:
    _falso = types.ModuleType("dotenv")
    _falso.load_dotenv = lambda *args, **kwargs: False
    sys.modules["dotenv"] = _falso

# Sin handlers, los avisos del robot saldrian por stderr y ensuciarian la salida.
logging.getLogger("cartera").addHandler(logging.NullHandler())
