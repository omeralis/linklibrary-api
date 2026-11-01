<#
  LinkLibrary — local setup & verification for Windows (PostgreSQL already installed, databases already created).

  Safe by design:
    * never drops/recreates databases, never runs migrate:fresh / db:wipe
    * verifies connectivity (read-only) before migrating
    * tests run only against a *_test database (enforced by phpunit.xml + tests/TestCase.php)

  Usage (from the project folder):   powershell -ExecutionPolicy Bypass -File scripts\local-setup.ps1
  Output is also written to storage\logs\local-setup.log
#>
$ErrorActionPreference = 'Continue'
Set-Location (Split-Path -Parent $PSScriptRoot)
$log = Join-Path (Get-Location) 'storage\logs\local-setup.log'
Start-Transcript -Path $log -Force | Out-Null

function Step($n) { Write-Host "`n==================== $n ====================" -ForegroundColor Cyan }
function Fail($m) { Write-Host "FAILED: $m" -ForegroundColor Red; Stop-Transcript | Out-Null; exit 1 }
function Run($exe, [string[]]$argsList) {
    Write-Host "> $exe $($argsList -join ' ')"
    & $exe @argsList | Out-Host
    return $LASTEXITCODE
}

Step 'Tooling'
if (-not (Get-Command php -ErrorAction SilentlyContinue)) { Fail 'php is not on PATH' }
if (-not (Get-Command composer -ErrorAction SilentlyContinue)) { Fail 'composer is not on PATH' }
php -v
composer --version
$mods = (php -m) -join ' '
foreach ($m in @('pdo_pgsql','pgsql','intl','mbstring','curl','fileinfo','openssl','gd')) {
    if ($mods -notmatch "(?i)\b$m\b") { Write-Host "Missing PHP extension: $m (enable it in php.ini)" -ForegroundColor Yellow; if ($m -in @('pdo_pgsql','intl','mbstring','curl')) { Fail "required extension $m" } }
    else { Write-Host "ext $m OK" }
}

Step '.env files'
if (-not (Test-Path .env)) { Fail '.env is missing (copy .env.example to .env and set DB_PASSWORD)' }
if (-not (Test-Path .env.testing)) { Fail '.env.testing is missing' }
$envDb = (Select-String -Path .env -Pattern '^DB_DATABASE=(.*)$').Matches[0].Groups[1].Value
$testDb = (Select-String -Path .env.testing -Pattern '^DB_DATABASE=(.*)$').Matches[0].Groups[1].Value
Write-Host "Development DB: $envDb   Test DB: $testDb"
if ($envDb -like '*_test') { Fail '.env must point at the development database, not a *_test database' }
if ($testDb -notlike '*_test') { Fail '.env.testing must point at a *_test database' }
if ($envDb -eq $testDb) { Fail 'development and test databases must differ' }

Step 'Composer dependencies'
if (-not (Test-Path composer.lock)) {
    Write-Host 'composer.lock missing -> composer update (creates the lock from packagist)'
    if ((Run composer @('update','--no-interaction','--no-progress')) -ne 0) { Fail 'composer update' }
} else {
    if ((Run composer @('install','--no-interaction','--no-progress')) -ne 0) { Fail 'composer install' }
}

Step 'APP_KEY'
if ((Select-String -Path .env -Pattern '^APP_KEY=base64:.+').Count -eq 0) {
    if ((Run php @('artisan','key:generate','--no-interaction')) -ne 0) { Fail 'key:generate' }
} else { Write-Host 'APP_KEY already set (kept)' }

Step 'config:clear'
if ((Run php @('artisan','config:clear')) -ne 0) { Fail 'config:clear' }

Step 'Connectivity (read-only)'
if ((Run php @('artisan','linklibrary:db-check')) -ne 0) { Fail "cannot connect to $envDb" }
if ((Run php @('artisan','linklibrary:db-check','--env=testing')) -ne 0) { Fail "cannot connect to $testDb" }

Step 'Migrate + seed (development database)'
if ((Run php @('artisan','migrate','--seed','--no-interaction')) -ne 0) { Fail 'migrate --seed' }
Run php @('artisan','migrate:status') | Out-Null

Step 'Schema verification'
if ((Run php @('artisan','linklibrary:db-check','--tables')) -ne 0) { Fail 'expected tables missing' }

Step 'Test suite (test database only)'
if (Test-Path storage\logs\deprecations.log) { Remove-Item storage\logs\deprecations.log }
$testExit = Run php @('artisan','test')
Write-Host "php artisan test exit code: $testExit"

Step 'Readiness report (read-only)'
Run php @('artisan','linklibrary:doctor') | Out-Null
if (Test-Path storage\logs\deprecations.log) {
    Write-Host 'Deprecations collected during tests (storage\logs\deprecations.log):' -ForegroundColor Yellow
    Get-Content storage\logs\deprecations.log -Tail 40
} else { Write-Host 'No PHP deprecations were logged during the test run.' }

Step 'Done'
Write-Host "Log written to $log"
Stop-Transcript | Out-Null
exit $testExit
