<#
.SYNOPSIS
    One-command production deploy of Gestor de cartera from a Windows PC.

.DESCRIPTION
    Runs on the cPanel server, over SSH:

        cd ~/<APP_DIR> && bash deploy/deploy.sh

    and shows the remote output as it comes. Values come from the parameters
    or from .cpanel-deploy.conf at the repository root (KEY=value, no secrets,
    gitignored; template: deploy/cpanel-deploy.conf.example). SSH key
    authentication only (BatchMode=yes): it never asks for or stores a
    password. Run it only when you really mean to publish to production; see
    docs/despliegue.md.

.EXAMPLE
    powershell -ExecutionPolicy Bypass -File deploy\deploy-remoto.ps1 -DryRun
    Prints the remote command without running it.

.EXAMPLE
    powershell -ExecutionPolicy Bypass -File deploy\deploy-remoto.ps1
    Deploys with the values in .cpanel-deploy.conf.
#>
[CmdletBinding()]
param(
    [string]$ConfigFile,
    [string]$SshHost,
    [string]$AppDir,
    [string]$Php,
    [string]$ComposerBin,
    [switch]$DryRun
)

$ErrorActionPreference = 'Stop'

function Stop-Deploy([string]$message) {
    Write-Host $message -ForegroundColor Red
    exit 2
}

# Windows PowerShell 5.1 leaves $PSScriptRoot empty in parameter defaults,
# so the default path is resolved here.
if (-not $ConfigFile) {
    $ConfigFile = Join-Path (Split-Path -Parent $PSScriptRoot) '.cpanel-deploy.conf'
}

# Read the file as KEY=value lines; it is never executed.
$config = @{}
if (Test-Path -LiteralPath $ConfigFile) {
    foreach ($line in Get-Content -LiteralPath $ConfigFile) {
        if ($line -match '^\s*([A-Z_]+)\s*=(.*)$') {
            $config[$Matches[1]] = $Matches[2].Trim()
        }
    }
}

# Parameters win over the file.
if (-not $SshHost)     { $SshHost     = $config['SSH_HOST'] }
if (-not $AppDir)      { $AppDir      = $config['APP_DIR'] }
if (-not $Php)         { $Php         = $config['PHP'] }
if (-not $ComposerBin) { $ComposerBin = $config['COMPOSER_BIN'] }

if (-not $SshHost -or -not $AppDir) {
    Stop-Deploy "SshHost or AppDir is missing (parameters or $ConfigFile)."
}

# Strict validation, so nothing unexpected reaches the remote shell.
$safe = '^[A-Za-z0-9._/~-]+$'
# A leading '-' would reach ssh as an option, not as a host.
if ($SshHost -notmatch '^[A-Za-z0-9][A-Za-z0-9._@-]*$') { Stop-Deploy "Invalid SshHost: $SshHost" }
if ($AppDir -notmatch $safe -or $AppDir.StartsWith('/') -or $AppDir.Contains('..')) {
    Stop-Deploy "AppDir must be relative to the home folder, without '..': $AppDir"
}
if ($Php -and $Php -notmatch $safe) { Stop-Deploy "Invalid Php: $Php" }
if ($ComposerBin -and $ComposerBin -notmatch '^[A-Za-z0-9._/~ -]+$') {
    Stop-Deploy "Invalid ComposerBin: $ComposerBin"
}

# deploy.sh expands PHP and COMPOSER_BIN without tilde expansion, so a "~/"
# path is sent as "$HOME/" inside double quotes and the remote shell expands
# it. The validation above already rules out quotes, '$' and backticks.
function ConvertTo-RemoteValue([string]$value) {
    return '"' + ($value -replace '(^|\s)~/', '$1$$HOME/') + '"'
}

$prefix = ''
if ($Php)         { $prefix += 'PHP=' + (ConvertTo-RemoteValue $Php) + ' ' }
if ($ComposerBin) { $prefix += 'COMPOSER_BIN=' + (ConvertTo-RemoteValue $ComposerBin) + ' ' }
$remote = "cd ~/$AppDir && ${prefix}bash deploy/deploy.sh"

Write-Host "Server: $SshHost"
Write-Host "Remote command: $remote"

if ($DryRun) {
    Write-Host '(dry run: nothing was executed)'
    exit 0
}

if (-not (Get-Command ssh -ErrorAction SilentlyContinue)) {
    Stop-Deploy 'ssh was not found. Install the Windows OpenSSH client.'
}

& ssh -o BatchMode=yes $SshHost $remote
$code = $LASTEXITCODE

if ($code -eq 0) {
    Write-Host 'Deploy finished successfully.' -ForegroundColor Green
} else {
    Write-Host "The deploy failed (exit code $code). Read the error above before trying again." -ForegroundColor Red
}
exit $code
