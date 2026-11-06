# Installe la synchronisation quotidienne sur un poste Windows (XAMPP), dans le
# Planificateur de tâches. Le serveur Linux utilise install_customers_refresh_cron.sh.
#
#   powershell -NoProfile -ExecutionPolicy Bypass -File install_customers_refresh_task.ps1 [options]
#     -At 05:00            heure quotidienne (le rechargement Oracle se termine vers 04:45)
#     -RetryHours 17       nouvelles tentatives horaires après -At (0 = aucune)
#     -AppDir <chemin>     défaut : C:\xampp\htdocs\extractor
#     -Php <chemin>        défaut : C:\xampp\php\php.exe
#     -DryRun              affiche la tâche sans l'enregistrer
#     -Uninstall           retire la tâche et le fichier de programmation
#
# Une seule tâche : à -At puis toutes les heures, `customers:refresh --skip-if-fresh`.
# Les passages suivants ne font rien tant que la version active contient le
# chargement Oracle du jour ; ils relancent après un échec, ou si le poste était
# éteint à l'heure prévue. StartWhenAvailable rattrape un passage manqué.
#
# Compte : l'utilisateur courant, session ouverte (LogonType Interactive), sans
# droit administrateur. Session fermée = aucune exécution : un poste de travail
# n'est pas un serveur de production.
param(
    [string]$At = '05:00',
    [int]$RetryHours = 17,
    [string]$AppDir = 'C:\xampp\htdocs\extractor',
    [string]$Php = 'C:\xampp\php\php.exe',
    [switch]$DryRun,
    [switch]$Uninstall
)
$ErrorActionPreference = 'Stop'
$TaskName     = 'Extractor - Customers refresh'
$ScheduleFile = Join-Path $AppDir 'writable\data\customers_sync_schedule.json'

if ($Uninstall) {
    Unregister-ScheduledTask -TaskName $TaskName -Confirm:$false
    Remove-Item -LiteralPath $ScheduleFile -ErrorAction SilentlyContinue
    Write-Output "Tâche '$TaskName' retirée."
    return
}

if ($At -notmatch '^([01]\d|2[0-3]):[0-5]\d$') { throw "-At attend HH:MM (reçu : $At)" }
if (-not (Test-Path -LiteralPath (Join-Path $AppDir 'spark'))) { throw "$AppDir\spark introuvable (-AppDir)" }
if (-not (Test-Path -LiteralPath $Php)) { throw "$Php introuvable (-Php)" }

# Rien n'est supposé : PHP, oci8 et DuckDB sont vérifiés avant d'écrire la tâche.
& $Php -r "exit(PHP_VERSION_ID >= 80200 && extension_loaded('oci8') ? 0 : 1);"
if ($LASTEXITCODE -ne 0) { throw "$Php : PHP >= 8.2 avec oci8 requis" }
$DuckDb = Get-Command duckdb -ErrorAction SilentlyContinue
if (-not $DuckDb) { throw 'duckdb introuvable dans le PATH' }

$Log      = Join-Path $AppDir 'writable\logs\customers_refresh.log'
$Refresh  = "`"$Php`" spark customers:refresh --scheduled --skip-if-fresh >> `"$Log`" 2>&1"
$Warm     = "`"$Php`" spark dashboard:warm >> `"$Log`" 2>&1"
$Command  = "$Refresh && $Warm"

# conhost --headless : aucune fenêtre ne s'ouvre pendant l'exécution.
$Action  = New-ScheduledTaskAction -Execute 'C:\Windows\System32\conhost.exe' `
    -Argument "--headless C:\Windows\System32\cmd.exe /d /c `"$Command`"" -WorkingDirectory $AppDir
$Trigger = New-ScheduledTaskTrigger -Daily -At $At
if ($RetryHours -gt 0) {
    $Repeat = New-ScheduledTaskTrigger -Once -At $At -RepetitionInterval (New-TimeSpan -Hours 1) -RepetitionDuration (New-TimeSpan -Hours $RetryHours)
    $Trigger.Repetition = $Repeat.Repetition
}
# Limite de 4 h : 74 min mesurées le 2026-10-03, 61 min avant une coupure Oracle le 2026-09-29.
$Settings = New-ScheduledTaskSettingsSet -WakeToRun -StartWhenAvailable -AllowStartIfOnBatteries -DontStopIfGoingOnBatteries `
    -MultipleInstances IgnoreNew -ExecutionTimeLimit (New-TimeSpan -Hours 4)
$Principal = New-ScheduledTaskPrincipal -UserId "$env:USERDOMAIN\$env:USERNAME" -LogonType Interactive -RunLevel Limited

Write-Output "PHP     : $Php"
Write-Output "DuckDB  : $($DuckDb.Source)"
Write-Output "Projet  : $AppDir"
Write-Output "Compte  : $env:USERDOMAIN\$env:USERNAME (session ouverte)"
Write-Output "Heure   : $At, puis toutes les heures pendant $RetryHours h"
Write-Output "Commande: $Command"

if ($DryRun) {
    Write-Output '(-DryRun : tâche non enregistrée)'
    return
}

Register-ScheduledTask -TaskName $TaskName -Action $Action -Trigger $Trigger -Settings $Settings -Principal $Principal `
    -Description 'Extractor : Oracle CMS_RFC.TB_CUSTOMERS_LIST vers snapshot actif (docs/snapshot/README.md).' -Force | Out-Null

# Déclare l'heure au tableau de bord (UTF-8 sans BOM).
$Json = @{ daily_at = $At; scheduler = 'windows-task'; installed_at = (Get-Date).ToString('yyyy-MM-ddTHH:mm:sszzz') } | ConvertTo-Json -Compress
[System.IO.File]::WriteAllText($ScheduleFile, $Json, (New-Object System.Text.UTF8Encoding($false)))

Write-Output "Installée : '$TaskName'"
Write-Output "Test immédiat : Start-ScheduledTask -TaskName '$TaskName' ; suivi : php spark customers:sync-status"
