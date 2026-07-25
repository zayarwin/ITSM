$ErrorActionPreference = 'Continue'

function Assert-Admin {
    $identity = [Security.Principal.WindowsIdentity]::GetCurrent()
    $principal = New-Object Security.Principal.WindowsPrincipal($identity)
    if (-not $principal.IsInRole([Security.Principal.WindowsBuiltInRole]::Administrator)) {
        Write-Host 'Not running as Administrator. Requesting elevation (UAC prompt)...' -ForegroundColor Yellow
        $scriptPath = $PSCommandPath
        if (-not $scriptPath) {
            $scriptPath = $MyInvocation.MyCommand.Path
        }

        if (-not $scriptPath) {
            Write-Host 'Unable to determine script path for elevation. Please run this script from a .ps1 file as Administrator.' -ForegroundColor Red
            exit 1
        }

        $elevationParams = @(
            '-NoProfile',
            '-ExecutionPolicy', 'Bypass',
            '-File', ('"{0}"' -f $scriptPath)
        )

        try {
            Start-Process -FilePath 'powershell.exe' -ArgumentList $elevationParams -Verb RunAs | Out-Null
            Write-Host 'Elevation request sent. Continue in the new Administrator PowerShell window.' -ForegroundColor Green
            exit 0
        }
        catch {
            Write-Host 'Elevation was cancelled or failed. Please run this script as Administrator.' -ForegroundColor Red
            exit 1
        }
    }
}

Assert-Admin

Write-Host 'Disabling Hyper-V, VBS, and Credential Guard for VMware nested virtualization...' -ForegroundColor Cyan

$dismCommands = @(
    '/online /disable-feature /featurename:Microsoft-Hyper-V-All /norestart',
    '/online /disable-feature /featurename:Microsoft-Hyper-V-Hypervisor /norestart',
    '/online /disable-feature /featurename:VirtualMachinePlatform /norestart',
    '/online /disable-feature /featurename:HypervisorPlatform /norestart',
    '/online /disable-feature /featurename:Microsoft-Windows-Subsystem-Linux /norestart',
    '/online /disable-feature /featurename:Containers-DisposableClientVM /norestart'
)

$failedDismCommands = @()
$rebootNeededFromDism = $false

foreach ($dismFeatureCommand in $dismCommands) {
    Write-Host "Running: dism $dismFeatureCommand" -ForegroundColor Yellow
    $dismProcess = Start-Process -FilePath dism.exe -ArgumentList $dismFeatureCommand -Wait -NoNewWindow -PassThru

    if ($dismProcess.ExitCode -eq 3010) {
        $rebootNeededFromDism = $true
    }
    elseif ($dismProcess.ExitCode -ne 0) {
        $failedDismCommands += "dism $dismFeatureCommand (exit code $($dismProcess.ExitCode))"
    }
}

if ($failedDismCommands.Count -gt 0) {
    Write-Host "`nSome DISM commands failed:" -ForegroundColor Yellow
    $failedDismCommands | ForEach-Object { Write-Host " - $_" -ForegroundColor Yellow }
    Write-Host 'This can happen if a feature is not present on your Windows edition. The script will continue with remaining steps.' -ForegroundColor Yellow
}

if ($rebootNeededFromDism) {
    Write-Host 'DISM reported a pending reboot (3010). Reboot is required for all changes to take full effect.' -ForegroundColor Cyan
}

Write-Host 'Setting BCD boot options...' -ForegroundColor Yellow
bcdedit /set hypervisorlaunchtype off
bcdedit /set vsmlaunchtype off

Write-Host 'Disabling VBS and Credential Guard registry policies...' -ForegroundColor Yellow
reg add "HKLM\SYSTEM\CurrentControlSet\Control\DeviceGuard" /v EnableVirtualizationBasedSecurity /t REG_DWORD /d 0 /f
reg add "HKLM\SYSTEM\CurrentControlSet\Control\Lsa" /v LsaCfgFlags /t REG_DWORD /d 0 /f
reg add "HKLM\SOFTWARE\Policies\Microsoft\Windows\DeviceGuard" /v EnableVirtualizationBasedSecurity /t REG_DWORD /d 0 /f
reg add "HKLM\SOFTWARE\Policies\Microsoft\Windows\DeviceGuard" /v RequirePlatformSecurityFeatures /t REG_DWORD /d 0 /f
reg add "HKLM\SOFTWARE\Policies\Microsoft\Windows\DeviceGuard" /v HypervisorEnforcedCodeIntegrity /t REG_DWORD /d 0 /f
reg add "HKLM\SYSTEM\CurrentControlSet\Control\DeviceGuard\Scenarios\HypervisorEnforcedCodeIntegrity" /v Enabled /t REG_DWORD /d 0 /f
reg add "HKLM\SYSTEM\CurrentControlSet\Control\DeviceGuard\Scenarios\CredentialGuard" /v Enabled /t REG_DWORD /d 0 /f

Write-Host "`nCurrent boot entry (hypervisorlaunchtype/vsmlaunchtype):" -ForegroundColor Cyan
$bootOutput = bcdedit /enum {current}
$bootFlags = $bootOutput | Select-String -Pattern 'hypervisorlaunchtype|vsmlaunchtype'
$bootFlags

Write-Host "`nCurrent VBS / Credential Guard status:" -ForegroundColor Cyan
$deviceGuard = Get-CimInstance -ClassName Win32_DeviceGuard -Namespace root\Microsoft\Windows\DeviceGuard |
    Select-Object SecurityServicesConfigured, SecurityServicesRunning, VirtualizationBasedSecurityStatus

$deviceGuard | Format-List

$bootText = $bootOutput | Out-String
$hasHypervisorOff = $bootText -match '(?im)^\s*hypervisorlaunchtype\s+off\s*$'
$hasVsmOff = $bootText -match '(?im)^\s*vsmlaunchtype\s+off\s*$'
$vbsStatus = [int]$deviceGuard.VirtualizationBasedSecurityStatus
$runningServices = @($deviceGuard.SecurityServicesRunning)
$noSecurityServicesRunning = ($runningServices.Count -eq 0) -or ($runningServices.Count -eq 1 -and [int]$runningServices[0] -eq 0)

if ($hasHypervisorOff -and $hasVsmOff -and $noSecurityServicesRunning -and $vbsStatus -eq 2) {
    Write-Host "`nWarning: VBS appears enabled with boot flags already Off." -ForegroundColor Yellow
    Write-Host "This usually indicates UEFI-locked Device Guard/Credential Guard policy." -ForegroundColor Yellow
    Write-Host "Next step: run Microsoft's DG_Readiness_Tool with -Disable, reboot, and confirm any firmware prompt." -ForegroundColor Yellow
}

Write-Host "`nDone. Reboot Windows now, then verify VMware starts." -ForegroundColor Green
