# Builds Hostinger upload zips into this folder. Each zip's contents go straight into the site's public_html.
#   scl-app.zip       -> scl.seothriv.com        (static export of the Next.js app)
#   scl-api.zip       -> api.seothriv.com        (Laravel API)
#   scl-dashboard.zip -> dashboard.seothriv.com  (Laravel admin + migrations)
param(
  [string]$ApiUrl = 'https://api.seothriv.com/api',
  [switch]$SkipAppBuild
)
$ErrorActionPreference = 'Stop'
Add-Type -AssemblyName System.IO.Compression
Add-Type -AssemblyName System.IO.Compression.FileSystem

$root    = Split-Path $PSScriptRoot -Parent
$out     = $PSScriptRoot
$staging = Join-Path $env:TEMP 'scl-deploy-staging'

function New-Zip($srcDir, $zipName, $exclude) {
  $zipPath = Join-Path $out $zipName
  if (Test-Path $zipPath) { Remove-Item $zipPath -Force }
  $zip = [System.IO.Compression.ZipFile]::Open($zipPath, 'Create')
  $count = 0
  try {
    Get-ChildItem $srcDir -Recurse -File -Force | ForEach-Object {
      $rel = $_.FullName.Substring($srcDir.Length + 1).Replace('\', '/')
      foreach ($p in $exclude) { if ($rel -match $p) { return } }
      [void][System.IO.Compression.ZipFileExtensions]::CreateEntryFromFile($zip, $_.FullName, $rel, 'Optimal')
      $count++
    }
  } finally { $zip.Dispose() }
  '{0,-18} {1,6} files  {2,7:N1} MB' -f $zipName, $count, ((Get-Item $zipPath).Length / 1MB)
}

# ── Next.js app: static export ────────────────────────────────────────────────
if (-not $SkipAppBuild) {
  Push-Location (Join-Path $root 'scl-app')
  try {
    Remove-Item -Recurse -Force .next, out -ErrorAction SilentlyContinue
    $env:NEXT_PUBLIC_API_URL = $ApiUrl
    npm run build
    if ($LASTEXITCODE -ne 0) { throw 'scl-app build failed' }
  } finally {
    Remove-Item Env:NEXT_PUBLIC_API_URL -ErrorAction SilentlyContinue
    Pop-Location
  }
}
New-Zip (Join-Path $root 'scl-app\out') 'scl-app.zip' @()

# ── Laravel apps: production vendor in a staging copy ─────────────────────────
$laravelSkip = @(
  '^node_modules/', '^vendor/', '^tests/', '^\.env$', '^\.phpunit\.result\.cache$', '^phpunit\.xml$', '^[^/]+\.zip$',
  '^storage/logs/(?!\.gitignore$)', '^storage/framework/(cache/data|sessions|views|testing)/(?!\.gitignore$)',
  '^storage/app/(private|public)/(?!\.gitignore$)', '^bootstrap/cache/(?!\.gitignore$)', '^public/storage$'
)
$rootHtaccess = @'
<IfModule mod_rewrite.c>
  RewriteEngine On
  RewriteCond %{HTTPS} off
  RewriteRule ^ https://%{HTTP_HOST}%{REQUEST_URI} [L,R=301]
  RewriteRule ^(.*)$ public/$1 [L]
</IfModule>
'@

foreach ($name in 'scl-api', 'scl-dashboard') {
  $src  = Join-Path $root $name
  $dest = Join-Path $staging $name
  if (Test-Path $dest) { Remove-Item -Recurse -Force $dest }
  New-Item -ItemType Directory -Force -Path $dest | Out-Null

  Get-ChildItem $src -Recurse -File -Force | ForEach-Object {
    $rel = $_.FullName.Substring($src.Length + 1).Replace('\', '/')
    foreach ($p in $laravelSkip) { if ($rel -match $p) { return } }
    $target = Join-Path $dest $rel
    New-Item -ItemType Directory -Force -Path (Split-Path $target -Parent) | Out-Null
    Copy-Item -LiteralPath $_.FullName -Destination $target
  }
  Set-Content -Path (Join-Path $dest '.htaccess') -Value $rootHtaccess -Encoding ascii
  foreach ($dir in 'bootstrap/cache', 'storage/app/public', 'storage/app/private', 'storage/framework/cache/data',
                   'storage/framework/sessions', 'storage/framework/views', 'storage/logs') {
    $full = Join-Path $dest $dir
    New-Item -ItemType Directory -Force -Path $full | Out-Null
    $keep = Join-Path $full '.gitignore'
    if (-not (Test-Path $keep)) { Set-Content -Path $keep -Value "*`n!.gitignore" -Encoding ascii }
  }

  Push-Location $dest
  try {
    composer install --no-dev --optimize-autoloader --no-interaction --no-progress --quiet
    if ($LASTEXITCODE -ne 0) { throw "composer install failed for $name" }
  } finally { Pop-Location }

  New-Zip $dest "$name.zip" @()
}

Remove-Item -Recurse -Force $staging
