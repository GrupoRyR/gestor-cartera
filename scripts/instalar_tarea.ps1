<#
.SYNOPSIS
    Registra el robot de cartera en el Programador de tareas de Windows.

.DESCRIPTION
    Crea la tarea "CarteraWeb-ETL" con dos disparadores diarios: 00:00 y 12:00.
    Si el equipo estaba apagado a esa hora, la tarea se ejecuta en cuanto
    arranca (StartWhenAvailable).

.PARAMETER Desinstalar
    Elimina la tarea en lugar de crearla.

.PARAMETER Horas
    Horas de ejecucion en formato HH:mm. Por defecto 00:00 y 12:00.

.EXAMPLE
    powershell -ExecutionPolicy Bypass -File scripts\instalar_tarea.ps1
    powershell -ExecutionPolicy Bypass -File scripts\instalar_tarea.ps1 -Desinstalar
#>
[CmdletBinding()]
param(
    [switch]$Desinstalar,
    [string[]]$Horas = @("00:00", "12:00")
)

$ErrorActionPreference = "Stop"
$NombreTarea = "CarteraWeb-ETL"

$Raiz    = Split-Path -Parent $PSScriptRoot
$EtlDir  = Join-Path $Raiz "etl"
$PythonW = Join-Path $Raiz ".venv\Scripts\pythonw.exe"
$Script  = Join-Path $EtlDir "run_etl.py"

if ($Desinstalar) {
    if (Get-ScheduledTask -TaskName $NombreTarea -ErrorAction SilentlyContinue) {
        Unregister-ScheduledTask -TaskName $NombreTarea -Confirm:$false
        Write-Host "Tarea '$NombreTarea' eliminada." -ForegroundColor Yellow
    } else {
        Write-Host "La tarea '$NombreTarea' no existia." -ForegroundColor Yellow
    }
    return
}

# --- Comprobaciones previas -------------------------------------------------
if (-not (Test-Path $PythonW)) {
    throw "No se encontro el entorno virtual en $PythonW.`nCrealo con:`n  python -m venv .venv`n  .venv\Scripts\python.exe -m pip install -r etl\requirements.txt"
}
if (-not (Test-Path $Script)) { throw "No se encontro $Script" }

$EnvFile = Join-Path $EtlDir ".env"
if (-not (Test-Path $EnvFile)) {
    Write-Warning "No existe $EnvFile. La tarea quedara registrada pero fallara hasta que lo completes."
}

# --- Definicion de la tarea -------------------------------------------------
$Accion = New-ScheduledTaskAction -Execute $PythonW -Argument "`"$Script`"" -WorkingDirectory $EtlDir

$Disparadores = foreach ($h in $Horas) {
    New-ScheduledTaskTrigger -Daily -At ([datetime]::ParseExact($h, "HH:mm", $null))
}

$Ajustes = New-ScheduledTaskSettingsSet `
    -StartWhenAvailable `
    -DontStopOnIdleEnd `
    -AllowStartIfOnBatteries `
    -DontStopIfGoingOnBatteries `
    -WakeToRun `
    -MultipleInstances IgnoreNew `
    -ExecutionTimeLimit (New-TimeSpan -Hours 1) `
    -RestartCount 2 `
    -RestartInterval (New-TimeSpan -Minutes 10)

# Se ejecuta como el usuario actual, solo con sesion iniciada: asi no hay que
# guardar la contrasena de Windows. Para que corra con la sesion cerrada,
# usa el Programador de tareas -> "Ejecutar tanto si el usuario inicio sesion
# como si no" (pedira la contrasena del usuario).
$Principal = New-ScheduledTaskPrincipal -UserId "$env:USERDOMAIN\$env:USERNAME" -LogonType Interactive -RunLevel Limited

if (Get-ScheduledTask -TaskName $NombreTarea -ErrorAction SilentlyContinue) {
    Unregister-ScheduledTask -TaskName $NombreTarea -Confirm:$false
    Write-Host "Tarea anterior eliminada para reemplazarla." -ForegroundColor DarkGray
}

Register-ScheduledTask `
    -TaskName    $NombreTarea `
    -Action      $Accion `
    -Trigger     $Disparadores `
    -Settings    $Ajustes `
    -Principal   $Principal `
    -Description "Extrae la cartera de SAP HANA a traves de la VPN Sophos y la publica en Supabase para la web de asesores." | Out-Null

Write-Host ""
Write-Host "Tarea '$NombreTarea' registrada." -ForegroundColor Green
Write-Host "  Ejecuta      : $PythonW"
Write-Host "  Script       : $Script"
Write-Host "  Horarios     : $($Horas -join ' y ')"
Write-Host ""
Write-Host "Para lanzarla ahora mismo y ver el resultado:" -ForegroundColor Cyan
Write-Host "  Start-ScheduledTask -TaskName $NombreTarea"
Write-Host "  Get-Content etl\logs\etl.log -Tail 30"
