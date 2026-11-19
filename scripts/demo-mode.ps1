<#
.SYNOPSIS
    Switches tea-alley between local development and demo mode behind a Cloudflare quick tunnel.

.DESCRIPTION
    T3-6a (#57). See docs/demo-runbook.md for the whole procedure; this script does the
    mechanical part.

      check           Preflight only. Changes nothing.
      on <url>        Preflight, then demo mode for the quick tunnel at <url>
                      (https://<name>.trycloudflare.com).
      off             Back to local development, exactly as before `on`.
      selftest        Tests the .env editing on a synthetic file in %TEMP%. Never touches .env.

    Rules it keeps:
      - .env is backed up to .env.demo-backup (git-ignored) before anything changes, and only
        these keys are changed: APP_ENV, APP_DEBUG, APP_URL, PAYMENT_GATEWAY, VITE_REVERB_PORT.
      - No .env value is ever printed, only key names.
      - When a key appears more than once, the last occurrence is the one Laravel uses (later
        lines override earlier ones), so that is the one edited.
      - `off` restores exactly those keys from the backup (a key `on` added is removed again);
        anything else edited in .env since `on` is kept.

.EXAMPLE
    .\scripts\demo-mode.ps1 check
    .\scripts\demo-mode.ps1 on https://example-words-here.trycloudflare.com
    .\scripts\demo-mode.ps1 off
#>
[CmdletBinding()]
param(
    [Parameter(Mandatory = $true, Position = 0)]
    [ValidateSet('check', 'on', 'off', 'selftest')]
    [string] $Mode,

    [Parameter(Position = 1)]
    [string] $TunnelUrl
)

$ErrorActionPreference = 'Stop'

$Root = Split-Path -Parent $PSScriptRoot
$EnvFile = Join-Path $Root '.env'
$BackupFile = Join-Path $Root '.env.demo-backup'
$HotFile = Join-Path $Root 'public\hot'
$HerdConfig = Join-Path $env:USERPROFILE '.config\herd\config'
$HerdCa = Join-Path $HerdConfig 'valet\CA\LaravelValetCASelfSigned.crt'
$SnippetTemplate = Join-Path $Root 'deploy\herd\reverb-proxy.conf'
$SnippetFile = Join-Path $HerdConfig 'server_snippets\tea-alley-reverb.conf'
$Site = 'tea-alley.test'
$HerdLoopback = 'http://127.0.0.1:60'   # Herd's plain-HTTP listener for the site (loopback only)
$ManagedKeys = @('APP_ENV', 'APP_DEBUG', 'APP_URL', 'PAYMENT_GATEWAY', 'VITE_REVERB_PORT')
$Utf8NoBom = New-Object System.Text.UTF8Encoding $false

function Write-Step([string] $Text) { Write-Host "==> $Text" -ForegroundColor Cyan }
function Write-Ok([string] $Text) { Write-Host "    ok    $Text" -ForegroundColor Green }
function Write-Note([string] $Text) { Write-Host "    note  $Text" -ForegroundColor Yellow }
function Stop-Demo([string] $Text) {
    Write-Host ''
    Write-Host "STOP: $Text" -ForegroundColor Red
    exit 1
}

# ---------------------------------------------------------------------------------------------
# .env as a list of lines. Values are never written to the console.
# ---------------------------------------------------------------------------------------------

function Read-EnvDocument([string] $Path) {
    $text = [System.IO.File]::ReadAllText($Path)
    $newline = if ($text.Contains("`r`n")) { "`r`n" } else { "`n" }
    $lines = New-Object System.Collections.Generic.List[string]
    foreach ($line in ($text -split "`r?`n")) { $lines.Add($line) }

    return @{ Lines = $lines; NewLine = $newline }
}

function Save-EnvDocument($Document, [string] $Path) {
    [System.IO.File]::WriteAllText($Path, ($Document.Lines -join $Document.NewLine), $Utf8NoBom)
}

# Index of the line that defines $Key (the last one: it wins), or -1.
function Find-EnvKey($Document, [string] $Key) {
    $pattern = '^\s*' + [regex]::Escape($Key) + '\s*='
    for ($i = $Document.Lines.Count - 1; $i -ge 0; $i--) {
        if ($Document.Lines[$i] -match $pattern) { return $i }
    }

    return -1
}

function Set-EnvLine($Document, [string] $Key, [string] $Line) {
    $index = Find-EnvKey $Document $Key
    if ($index -ge 0) {
        $Document.Lines[$index] = $Line
        return
    }

    # A new key goes before the file's trailing newline (the last, empty element).
    $at = $Document.Lines.Count
    if ($at -gt 0 -and $Document.Lines[$at - 1] -eq '') { $at-- }
    $Document.Lines.Insert($at, $Line)
}

function Remove-EnvKey($Document, [string] $Key) {
    $index = Find-EnvKey $Document $Key
    if ($index -ge 0) { $Document.Lines.RemoveAt($index) }
}

function Get-EnvValue($Document, [string] $Key) {
    $index = Find-EnvKey $Document $Key
    if ($index -lt 0) { return $null }

    return ($Document.Lines[$index] -replace '^[^=]*=', '').Trim().Trim('"')
}

# Turns demo mode on in $Document: sets the managed keys.
function Set-DemoKeys($Document, [string] $Url) {
    $values = [ordered]@{
        APP_ENV          = 'production'
        APP_DEBUG        = 'false'
        APP_URL          = $Url
        PAYMENT_GATEWAY  = 'stripe'
        VITE_REVERB_PORT = '443'   # Echo connects to the page's own host and port 443 (the tunnel)
    }
    foreach ($key in $values.Keys) { Set-EnvLine $Document $key "$key=$($values[$key])" }
}

# Undoes Set-DemoKeys in $Document using $Backup: each managed key gets the backup's line back,
# or is removed if the backup didn't have it.
function Restore-DemoKeys($Document, $Backup) {
    foreach ($key in $ManagedKeys) {
        $index = Find-EnvKey $Backup $key
        if ($index -ge 0) { Set-EnvLine $Document $key $Backup.Lines[$index] } else { Remove-EnvKey $Document $key }
    }
}

# ---------------------------------------------------------------------------------------------
# Checks
# ---------------------------------------------------------------------------------------------

# HTTP status of a request to Herd's loopback listener for the site ("000" if no answer).
function Get-HerdStatus([string] $Path, [switch] $WebSocket) {
    $arguments = @('-s', '-o', 'NUL', '-w', '%{http_code}', '--max-time', '8', '-H', "Host: $Site")
    if ($WebSocket) {
        $arguments += @('-H', 'Connection: Upgrade', '-H', 'Upgrade: websocket', '-H', 'Sec-WebSocket-Version: 13', '-H', 'Sec-WebSocket-Key: dGhlIHNhbXBsZSBub25jZQ==')
    }

    return (& curl.exe @arguments "$HerdLoopback$Path")
}

function Get-UrlStatus([string] $Url, [switch] $WebSocket) {
    $arguments = @('-s', '-o', 'NUL', '-w', '%{http_code}', '--max-time', '15')
    if ($WebSocket) {
        $arguments += @('-H', 'Connection: Upgrade', '-H', 'Upgrade: websocket', '-H', 'Sec-WebSocket-Version: 13', '-H', 'Sec-WebSocket-Key: dGhlIHNhbXBsZSBub25jZQ==')
    }

    return (& curl.exe @arguments $Url)
}

function Test-Listening([int] $Port) {
    return [bool](Get-NetTCPConnection -LocalPort $Port -State Listen -ErrorAction SilentlyContinue)
}

function Get-ProcessCommandLines([string] $Name) {
    return @(Get-CimInstance Win32_Process -Filter "Name='$Name'" -ErrorAction SilentlyContinue | ForEach-Object { $_.CommandLine })
}

# Returns a list of problems (empty when everything is ready). $ForOn adds the checks that only
# matter right before switching on.
function Get-PreflightProblems([switch] $ForOn, [string] $Url = '') {
    $problems = New-Object System.Collections.Generic.List[string]

    if (-not (Test-Path $EnvFile)) { $problems.Add('.env not found.'); return $problems }
    $document = Read-EnvDocument $EnvFile

    # Herd Studio must be off: through the tunnel it would be reachable from the internet.
    $loader = Get-HerdStatus '/herd-studio/loader.js'
    $aiSocket = Get-HerdStatus '/__herd/ai/ws' -WebSocket   # a plain GET returns 404 even when it's live
    if ($loader -eq '000') {
        $problems.Add("Herd isn't answering on 127.0.0.1:60. Start Herd.")
    } elseif ($loader -ne '404' -or $aiSocket -ne '404') {
        $problems.Add("Herd Studio is on (/herd-studio/loader.js: $loader, /__herd/ai/ws: $aiSocket; both must be 404). Turn Herd Studio off in Herd's settings, then run this again.")
    } else {
        Write-Ok 'Herd Studio is off (/herd-studio/loader.js and /__herd/ai/ws are 404)'
    }

    $mailer = Get-EnvValue $document 'MAIL_MAILER'
    if ($mailer -ne 'smtp') { $problems.Add("MAIL_MAILER (its last occurrence in .env) isn't smtp. Demo emails go through Gmail SMTP.") } else { Write-Ok 'MAIL_MAILER is smtp' }

    if (-not (Test-Listening 8080)) { $problems.Add('Reverb isn''t listening on 8080. Start it: php artisan reverb:start') } else { Write-Ok 'Reverb is listening on 8080' }

    # In demo mode tea-alley.test is refused (one host), so Stripe's webhooks must come in
    # through the tunnel: stripe listen --forward-to <tunnel URL>/stripe/webhook.
    $stripe = Get-ProcessCommandLines 'stripe.exe' | Where-Object { $_ -match '\blisten\b' }
    if (-not $stripe) {
        $problems.Add('stripe listen isn''t running. Start it in its own terminal (docs/demo-runbook.md, step 6).')
    } elseif ($Url -ne '' -and -not ($stripe | Where-Object { $_.Contains("$Url/stripe/webhook") })) {
        $problems.Add("stripe listen doesn't forward to the tunnel. Restart it with --forward-to $Url/stripe/webhook (tea-alley.test is refused in demo mode).")
    } else {
        Write-Ok 'stripe listen is running'
    }

    if (Test-Listening 5173) { $problems.Add('The Vite dev server is running (port 5173). Stop `npm run dev` / `composer run dev`: phones can''t reach it, and demo mode serves built assets.') } else { Write-Ok 'no Vite dev server' }

    if (-not (Test-Path $HerdCa)) { $problems.Add("Herd's CA certificate wasn't found at $HerdCa.") }
    if (-not (Test-Path $SnippetTemplate)) { $problems.Add("The snippet template wasn't found at $SnippetTemplate.") }

    if ($ForOn) {
        if (Test-Path $BackupFile) { $problems.Add('.env.demo-backup already exists: demo mode looks on already. Run `off` first (or compare and delete the backup yourself).') }

        $tunnel = Get-ProcessCommandLines 'cloudflared.exe' | Where-Object { $_ -match '127\.0\.0\.1:60' -and $_ -match '--http-host-header\s+tea-alley\.test' }
        if (-not $tunnel) { $problems.Add('The quick tunnel isn''t running. Start it: cloudflared tunnel --url http://127.0.0.1:60 --http-host-header tea-alley.test') } else { Write-Ok 'cloudflared quick tunnel to Herd is running' }
    }

    return $problems
}

function Invoke-Checked([string] $What, [scriptblock] $Command) {
    Write-Step $What
    & $Command
    if ($LASTEXITCODE -ne 0) { Stop-Demo "$What failed (exit $LASTEXITCODE). See the output above." }
}

# ---------------------------------------------------------------------------------------------
# Modes
# ---------------------------------------------------------------------------------------------

function Invoke-Check {
    Write-Step 'Preflight'
    $problems = Get-PreflightProblems -ForOn
    if ($problems.Count -gt 0) {
        Write-Host ''
        $problems | ForEach-Object { Write-Host "  - $_" -ForegroundColor Red }
        Stop-Demo 'Not ready for demo mode.'
    }
    Write-Host ''
    Write-Host 'Ready for demo mode.' -ForegroundColor Green
}

function Invoke-On {
    if ($TunnelUrl -notmatch '^https://[a-z0-9-]+\.trycloudflare\.com/?$') {
        Stop-Demo 'Give the quick tunnel URL cloudflared printed, e.g. .\scripts\demo-mode.ps1 on https://example-words-here.trycloudflare.com'
    }
    $url = $TunnelUrl.TrimEnd('/')

    Write-Step 'Preflight'
    $problems = Get-PreflightProblems -ForOn -Url $url
    if ($problems.Count -gt 0) {
        Write-Host ''
        $problems | ForEach-Object { Write-Host "  - $_" -ForegroundColor Red }
        Stop-Demo 'Not switching to demo mode. Nothing was changed.'
    }

    Write-Step 'Backing up .env to .env.demo-backup (git-ignored)'
    Copy-Item $EnvFile $BackupFile
    Write-Ok 'backup written'

    Write-Step 'Setting the demo keys in .env (values not shown)'
    $document = Read-EnvDocument $EnvFile
    Set-DemoKeys $document $url
    Save-EnvDocument $document $EnvFile
    $ManagedKeys | ForEach-Object { Write-Ok "set $_" }

    Write-Step 'Installing the Reverb proxy into Herd (only /app/, TLS verified with Herd''s CA)'
    $snippet = [System.IO.File]::ReadAllText($SnippetTemplate).Replace('{{HERD_CA}}', ($HerdCa -replace '\\', '/'))
    [System.IO.File]::WriteAllText($SnippetFile, $snippet, $Utf8NoBom)
    Invoke-Checked 'Restarting Herd''s nginx' { herd restart nginx | Out-Host }

    if (Test-Path $HotFile) {
        Remove-Item $HotFile
        Write-Ok 'removed a stale public/hot'
    }

    Push-Location $Root
    try {
        Invoke-Checked 'Building assets (VITE_REVERB_PORT=443)' { npm run build | Out-Host }
        Invoke-Checked 'Caching config, routes, and views (php artisan optimize)' { php artisan optimize | Out-Null }
    } finally {
        Pop-Location
    }

    Write-Step 'Checking through the tunnel'
    $reverbKey = Get-EnvValue $document 'REVERB_APP_KEY'
    $results = [ordered]@{
        "$url/up (200)"                    = @((Get-UrlStatus "$url/up"), '200')
        "$url/no-such-page (404)"          = @((Get-UrlStatus "$url/no-such-page-demo-check"), '404')
        'WebSocket /app/<key> (101)'       = @((Get-UrlStatus "$url/app/$reverbKey`?protocol=7" -WebSocket), '101')
        'Reverb HTTP API /apps/ (404)'     = @((Get-UrlStatus "$url/apps/check/events"), '404')
        'Herd Studio loader (404)'         = @((Get-UrlStatus "$url/herd-studio/loader.js"), '404')
        # Without a forwarded host the request is for tea-alley.test itself, refused in demo mode.
        # (Over Herd's loopback listener: Windows curl can't check revocation for Herd's local CA.)
        'tea-alley.test refused (400)'     = @((Get-HerdStatus '/up'), '400')
    }
    $failed = 0
    foreach ($name in $results.Keys) {
        $got, $want = $results[$name]
        if ($got -eq $want) { Write-Ok "$name" } else { Write-Note "$name -> got $got"; $failed++ }
    }

    Write-Host ''
    if ($failed -gt 0) {
        Write-Host "Demo mode is ON, but $failed check(s) above didn't match. Fix them, or run `off`." -ForegroundColor Yellow
    } else {
        Write-Host 'Demo mode is ON.' -ForegroundColor Green
    }
    Write-Host "  Open $url on every device, including this PC (https://tea-alley.test is refused in demo mode)."
    Write-Host '  Back to local development: .\scripts\demo-mode.ps1 off'
}

function Invoke-Off {
    if (-not (Test-Path $BackupFile)) {
        Stop-Demo 'Demo mode isn''t on: there is no .env.demo-backup to restore from.'
    }

    Write-Step 'Restoring the demo keys in .env from .env.demo-backup (values not shown)'
    $document = Read-EnvDocument $EnvFile
    $backup = Read-EnvDocument $BackupFile
    Restore-DemoKeys $document $backup
    Save-EnvDocument $document $EnvFile
    $ManagedKeys | ForEach-Object { Write-Ok "restored $_" }

    Write-Step 'Removing the Reverb proxy from Herd'
    if (Test-Path $SnippetFile) { Remove-Item $SnippetFile }
    Invoke-Checked 'Restarting Herd''s nginx' { herd restart nginx | Out-Host }

    Push-Location $Root
    try {
        Invoke-Checked 'Clearing cached config, routes, and views' { php artisan optimize:clear | Out-Null }
        Invoke-Checked 'Building assets (local Reverb port)' { npm run build | Out-Host }
    } finally {
        Pop-Location
    }

    # The backup goes only if .env is byte-for-byte what it was before `on`.
    $same = [System.IO.File]::ReadAllText($EnvFile) -ceq [System.IO.File]::ReadAllText($BackupFile)
    if ($same) {
        Remove-Item $BackupFile
        Write-Ok '.env is exactly as before demo mode; backup removed'
    } else {
        $now = (Read-EnvDocument $EnvFile).Lines
        $before = $backup.Lines
        $lines = for ($i = 0; $i -lt [Math]::Max($now.Count, $before.Count); $i++) { if ($now[$i] -cne $before[$i]) { $i + 1 } }
        Write-Note ".env differs from .env.demo-backup at line(s) $($lines -join ', ') (edits made while demo mode was on). Backup kept: compare, then delete it."
    }

    Write-Host ''
    Write-Host 'Demo mode is OFF.' -ForegroundColor Green
    Push-Location $Root
    try {
        php artisan about --only=environment | Select-String 'Environment|Debug Mode|URL' | ForEach-Object { '  ' + $_.Line.Trim() }
    } finally {
        Pop-Location
    }
}

function Invoke-SelfTest {
    $path = Join-Path $env:TEMP ("demo-mode-selftest-" + [guid]::NewGuid().ToString('N') + '.env')
    $original = @(
        'APP_NAME="Tea Alley"', 'APP_ENV=local', 'APP_DEBUG=true', 'APP_URL=https://tea-alley.test',
        'MAIL_MAILER=smtp', 'MAIL_HOST=127.0.0.1', 'MAIL_HOST=smtp.gmail.com',
        'PAYMENT_GATEWAY=fake', 'PAYMENT_GATEWAY=fake', 'OTHER=1', ''
    ) -join "`r`n"
    [System.IO.File]::WriteAllText($path, $original, $Utf8NoBom)
    $failures = 0
    $assert = {
        param([bool] $Condition, [string] $Name)
        if ($Condition) { Write-Ok $Name } else { Write-Host "    FAIL  $Name" -ForegroundColor Red; $script:failures++ }
    }

    try {
        $backup = Read-EnvDocument $path
        $document = Read-EnvDocument $path
        Set-DemoKeys $document 'https://a-b-c.trycloudflare.com'
        Save-EnvDocument $document $path
        $on = Read-EnvDocument $path

        & $assert ((Get-EnvValue $on 'APP_ENV') -eq 'production') 'on: APP_ENV=production'
        & $assert ((Get-EnvValue $on 'APP_URL') -eq 'https://a-b-c.trycloudflare.com') 'on: APP_URL is the tunnel'
        & $assert ((Get-EnvValue $on 'VITE_REVERB_PORT') -eq '443') 'on: VITE_REVERB_PORT added'
        & $assert ($on.Lines[7] -eq 'PAYMENT_GATEWAY=fake' -and $on.Lines[8] -eq 'PAYMENT_GATEWAY=stripe') 'on: a duplicated key changes its last (effective) occurrence only'
        & $assert ($on.Lines[5] -eq 'MAIL_HOST=127.0.0.1' -and $on.Lines[6] -eq 'MAIL_HOST=smtp.gmail.com') 'on: unmanaged keys untouched'
        & $assert ([System.IO.File]::ReadAllText($path).Contains("`r`n") -and [System.IO.File]::ReadAllText($path).EndsWith("`r`n")) 'on: CRLF and the final newline kept'

        $on.Lines[9] = 'OTHER=edited during the demo'
        Restore-DemoKeys $on $backup
        Save-EnvDocument $on $path
        $off = Read-EnvDocument $path

        & $assert ((Find-EnvKey $off 'VITE_REVERB_PORT') -lt 0) 'off: a key on added is removed'
        & $assert ((Get-EnvValue $off 'APP_URL') -eq 'https://tea-alley.test' -and (Get-EnvValue $off 'PAYMENT_GATEWAY') -eq 'fake') 'off: managed keys restored'
        & $assert ((Get-EnvValue $off 'OTHER') -eq 'edited during the demo') 'off: edits made during the demo are kept'

        $off.Lines[9] = 'OTHER=1'
        & $assert (($off.Lines -join "`r`n") -ceq $original) 'off: byte-for-byte the original'
    } finally {
        Remove-Item $path -ErrorAction SilentlyContinue
    }

    if ($failures -gt 0) { Stop-Demo "$failures self-test(s) failed." }
    Write-Host ''
    Write-Host 'Self-test passed.' -ForegroundColor Green
}

switch ($Mode) {
    'check' { Invoke-Check }
    'on' { Invoke-On }
    'off' { Invoke-Off }
    'selftest' { Invoke-SelfTest }
}
