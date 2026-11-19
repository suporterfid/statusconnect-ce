#requires -Version 5.1
Set-StrictMode -Version Latest
$ErrorActionPreference = 'Stop'

$RootDir = Split-Path -Parent $PSScriptRoot
Set-Location $RootDir

$ComposeFiles = @('-f', 'compose.yaml')

if ($env:STC_CI -eq '1' -or $env:CI -eq 'true' -or $env:GITHUB_ACTIONS -eq 'true') {
    $ComposeFiles += @('-f', 'compose.ci.yaml')
}

function Invoke-ComposeCore {
    param(
        [Parameter(ValueFromRemainingArguments = $true)]
        [object[]]$CommandArgs
    )
    [string[]]$flatArgs = @($CommandArgs | ForEach-Object { $_ })
    docker compose @ComposeFiles $flatArgs
    return $LASTEXITCODE
}

function Invoke-Compose {
    param(
        [Parameter(ValueFromRemainingArguments = $true)]
        [string[]]$CommandArgs
    )
    Invoke-ComposeCore @CommandArgs
    if ($LASTEXITCODE -ne 0) {
        throw "docker compose failed with exit code $LASTEXITCODE"
    }
}

function Write-PackagistWarning {
    if ($env:COMPOSER_PACKAGIST_URL) {
        Write-Warning "COMPOSER_PACKAGIST_URL is set ($($env:COMPOSER_PACKAGIST_URL)). Custom Packagist mirrors can cause stale or incomplete installs."
    }
}

function Get-ComposerEnvArgs {
    $envList = @()
    if ($env:COMPOSER_PACKAGIST_URL) {
        $envList += @('-e', "COMPOSER_PACKAGIST_URL=$($env:COMPOSER_PACKAGIST_URL)")
    }
    return $envList
}

function Invoke-ComposerWithRetry {
    param(
        [Parameter(ValueFromRemainingArguments = $true)]
        [string[]]$ComposerArgs
    )

    Write-PackagistWarning
    $envArgs = Get-ComposerEnvArgs
    $maxAttempts = 3
    $attempt = 1
    $delaySeconds = 5

    while ($attempt -le $maxAttempts) {
        $exitCode = Invoke-ComposeCore (@('run', '--rm') + $envArgs + @('app', 'composer') + $ComposerArgs)
        if ($exitCode -eq 0) {
            return
        }

        if ($attempt -ge $maxAttempts) {
            throw "Composer failed after $maxAttempts attempts."
        }

        Write-Warning "Composer attempt $attempt failed; retrying in ${delaySeconds}s..."
        Start-Sleep -Seconds $delaySeconds
        $delaySeconds *= 2
        $attempt++
    }
}

function Show-Usage {
    @'
StatusConnect Docker toolchain

Usage:
  .\scripts\stc.ps1 <verb> [args...]

Verbs:
  up           Start core services (app, mysql, mailpit, target)
  down         Stop and remove containers
  bootstrap    Install dependencies, prepare env, migrate database
  composer     Run composer via app container
  artisan      Run artisan via app container
  npm          Run npm via node container (dev profile)
  test         Run PHPUnit test suite
  e2e          Run the Playwright browser tests on an isolated stack (e2e/)
  deploy       Build production release and publish to Hostinger over SSH (scripts/deploy-hostinger.sh, needs bash)
  shell        Open shell in app container
  help         Show this help
'@ | Write-Output
}

function Invoke-Up {
    Invoke-Compose 'up' '-d' '--build' 'mysql' 'mailpit' 'target' 'app'
}

function Invoke-Down {
    param([string[]]$CommandArgs)
    Invoke-Compose (@('down') + $CommandArgs)
}

function Invoke-Bootstrap {
    if (-not (Test-Path '.env')) {
        Copy-Item '.env.example' '.env'
        Write-Output 'Created .env from .env.example'
    }

    Invoke-Compose 'up' '-d' '--build' 'mysql' 'mailpit' 'target'
    Invoke-Compose 'up' '-d' '--wait' 'mysql'

    Invoke-ComposerWithRetry 'install'

    $hasArtisan = (Invoke-ComposeCore 'run' '--rm' 'app' 'test' '-f' 'artisan') -eq 0
    if ($hasArtisan) {
        Invoke-Compose 'run' '--rm' 'app' 'php' 'artisan' 'key:generate' '--force'
        Invoke-Compose 'run' '--rm' 'app' 'php' 'artisan' 'migrate' '--force'
    }
    else {
        Write-Output 'Laravel not scaffolded yet; skipping artisan bootstrap steps.'
    }

    if (Test-Path 'package.json') {
        $npmExit = Invoke-ComposeCore '--profile' 'dev' 'run' '--rm' 'node' 'npm' 'ci'
        if ($npmExit -ne 0) {
            Invoke-Compose '--profile' 'dev' 'run' '--rm' 'node' 'npm' 'install'
        }
    }

    Invoke-Compose 'up' '-d' '--build' 'app'
    Write-Output 'Bootstrap complete.'
}

function Invoke-Composer {
    param([string[]]$CommandArgs)

    if ($CommandArgs.Count -gt 0 -and $CommandArgs[0] -eq 'install') {
        $installArgs = if ($CommandArgs.Count -gt 1) { $CommandArgs[1..($CommandArgs.Count - 1)] } else { @() }
        $retryArgs = @('install') + $installArgs
        Invoke-ComposerWithRetry @retryArgs
        return
    }

    Write-PackagistWarning
    $envArgs = Get-ComposerEnvArgs
    $compArgs = @('run', '--rm') + $envArgs + @('app', 'composer') + $CommandArgs
    Invoke-Compose @compArgs
}

function Invoke-Artisan {
    param([string[]]$CommandArgs)
    $artArgs = @('run', '--rm', 'app', 'php', 'artisan') + $CommandArgs
    Invoke-Compose @artArgs
}

function Invoke-Npm {
    param([string[]]$CommandArgs)
    $npmArgs = @('--profile', 'dev', 'run', '--rm', '--service-ports', 'node', 'npm') + $CommandArgs
    Invoke-Compose @npmArgs
}

function Invoke-Test {
    param([string[]]$CommandArgs)

    $testEnvArgs = @('-e', 'APP_ENV=testing', '-e', 'DB_CONNECTION=sqlite', '-e', 'DB_DATABASE=:memory:')

    if ((Invoke-ComposeCore 'run' '--rm' 'app' 'test' '-f' 'artisan') -eq 0) {
        $testArgs = @('run', '--rm') + $testEnvArgs + @('app', 'php', 'artisan', 'test') + $CommandArgs
        Invoke-Compose @testArgs
        return
    }

    if ((Invoke-ComposeCore 'run' '--rm' 'app' 'test' '-f' 'vendor/bin/pest') -eq 0) {
        $testArgs = @('run', '--rm') + $testEnvArgs + @('app', 'vendor/bin/pest') + $CommandArgs
        Invoke-Compose @testArgs
        return
    }

    if ((Invoke-ComposeCore 'run' '--rm' 'app' 'test' '-f' 'vendor/bin/phpunit') -eq 0) {
        $testArgs = @('run', '--rm') + $testEnvArgs + @('app', 'vendor/bin/phpunit') + $CommandArgs
        Invoke-Compose @testArgs
        return
    }

    throw 'No test runner found. Scaffold Laravel or install dev dependencies first.'
}

# Browser tests (e2e/, #173): an isolated stack under its own compose project,
# a fresh database, a platform admin, then the Playwright suite. $env:E2E_KEEP=1
# leaves the stack running for debugging. Needs vendor/ (run bootstrap once).
$E2EProject = 'statusconnect-e2e'
$E2EAdminEmail = 'admin@e2e.test'

# A simple function on purpose: with a param() block PowerShell would read -d as -Debug.
function Invoke-E2ECompose {
    docker compose -p $E2EProject -f compose.yaml -f compose.e2e.yaml --profile e2e @args | Out-Host
    return $LASTEXITCODE
}

function Invoke-E2E {
    param([string[]]$CommandArgs)

    $rc = 0
    try {
        if ((Invoke-E2ECompose up -d --build --wait mysql mailpit target) -ne 0) { throw 'Could not start mysql, mailpit and target.' }
        if ((Invoke-E2ECompose up -d --build app) -ne 0) { throw 'Could not start app.' }
        docker compose -p $E2EProject -f compose.yaml -f compose.e2e.yaml exec -T app php artisan migrate:fresh --force | Out-Null
        if ($LASTEXITCODE -ne 0) { throw 'migrate:fresh failed.' }

        $output = docker compose -p $E2EProject -f compose.yaml -f compose.e2e.yaml exec -T app php artisan statusconnect:provision-account `
            "--email=$E2EAdminEmail" '--org=E2E Platform' '--slug=e2e-platform' '--platform-admin' | Out-String
        $match = [regex]::Match($output, 'Temporary password[^:]*:\s*(\S+)')
        if (-not $match.Success) { Write-Output $output; throw "Could not read the platform admin's temporary password." }

        $env:E2E_ADMIN_EMAIL = $E2EAdminEmail
        $env:E2E_ADMIN_PASSWORD = $match.Groups[1].Value
        $runArgs = @('run', '--rm', 'playwright', 'sh', '-c', 'npm ci --no-audit --no-fund --loglevel=error && npx playwright test "$@"', '--') + $CommandArgs
        $rc = Invoke-E2ECompose @runArgs
    }
    catch {
        Write-Error $_
        $rc = 1
    }
    finally {
        Remove-Item Env:E2E_ADMIN_PASSWORD -ErrorAction SilentlyContinue
        if ($env:E2E_KEEP -eq '1') {
            Write-Output "E2E_KEEP=1: stack left running. Remove it with: docker compose -p $E2EProject -f compose.yaml -f compose.e2e.yaml --profile e2e down -v"
        }
        else {
            Invoke-E2ECompose down -v --remove-orphans | Out-Null
        }
    }

    if ($rc -eq 0) { Write-Output 'E2E passed. Report: e2e/playwright-report/index.html' }
    else { Write-Output "E2E failed (exit $rc). Report and traces: e2e/playwright-report/, e2e/test-results/"; exit $rc }
}

function Invoke-Deploy {
    param([string[]]$CommandArgs)

    # Same path as stc.sh deploy. The script needs bash, rsync and ssh, so on
    # Windows run it from Git Bash or WSL. The Community Edition does not ship it (#251).
    if (-not (Test-Path "$PSScriptRoot/deploy-hostinger.sh")) {
        Write-Error 'scripts/deploy-hostinger.sh is not part of this edition.'
        exit 1
    }
    if (-not (Get-Command bash -ErrorAction SilentlyContinue)) {
        Write-Error 'stc deploy needs bash, rsync and ssh. Run it from WSL or Git Bash: ./scripts/stc.sh deploy'
        exit 1
    }

    bash "$PSScriptRoot/deploy-hostinger.sh" @CommandArgs
    if ($LASTEXITCODE -ne 0) { throw "Deployment failed with exit code $LASTEXITCODE" }
}

function Invoke-Shell {
    Invoke-Compose 'run' '--rm' 'app' 'bash'
}

$Verb = if ($args.Count -gt 0) { $args[0] } else { 'help' }
$VerbArgs = if ($args.Count -gt 1) { $args[1..($args.Count - 1)] } else { @() }

switch ($Verb) {
    'up' { Invoke-Up }
    'down' { Invoke-Down -CommandArgs $VerbArgs }
    'bootstrap' { Invoke-Bootstrap }
    'composer' { Invoke-Composer -CommandArgs $VerbArgs }
    'artisan' { Invoke-Artisan -CommandArgs $VerbArgs }
    'npm' { Invoke-Npm -CommandArgs $VerbArgs }
    'test' { Invoke-Test -CommandArgs $VerbArgs }
    'e2e' { Invoke-E2E -CommandArgs $VerbArgs }
    'deploy' { Invoke-Deploy -CommandArgs $VerbArgs }
    'shell' { Invoke-Shell }
    { $_ -in @('help', '-h', '--help') } { Show-Usage }
    default {
        Write-Error "Unknown verb: $Verb"
        Show-Usage
        exit 1
    }
}
