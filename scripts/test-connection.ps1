# scripts/test-connection.ps1
# Primary automated curl.exe test loop across endpoints, domains, and HTTP protocols.
# Appends diagnostics to results.txt and outputs to stdout.

$hosts = @(
    "https://maichittahotel.com",
    "https://www.maichittahotel.com"
)

$endpoints = @(
    "/health.txt",
    "/up",
    "/api/v1/health",
    "/"
)

$protocols = @(
    "--http1.1",
    "--http2"
)

$resultsFile = if ($PSScriptRoot) { Join-Path $PSScriptRoot "results.txt" } elseif (Test-Path "scripts") { "scripts/results.txt" } else { "results.txt" }

$publicIp = try {
    (Invoke-RestMethod -Uri "https://api.ipify.org" -TimeoutSec 5).Trim()
} catch {
    "Unavailable"
}

Write-Host "=================================================================="
Write-Host "Starting Primary Connection Test Loop"
Write-Host "Public IP:    $publicIp"
Write-Host "Results File: $resultsFile"
Write-Host "=================================================================="

$totalRequests = $hosts.Length * $endpoints.Length * $protocols.Length
$currentRequest = 0

foreach ($hostUrl in $hosts) {
    foreach ($path in $endpoints) {
        foreach ($proto in $protocols) {
            $currentRequest++
            $url = "$hostUrl$path"
            $timestamp = (Get-Date).ToUniversalTime().ToString("yyyy-MM-dd HH:mm:ss 'UTC'")
            $writeFormat = "%{http_code}|%{time_connect}|%{time_appconnect}|%{time_total}"

            # Execute curl.exe with silent flags, 20s timeout, and discarded body
            $rawMetrics = & curl.exe -s -o NUL --max-time 20 $proto -w $writeFormat $url
            $exitCode = $LASTEXITCODE

            $parts = $rawMetrics -split '\|'
            $httpCode = if ($parts.Length -ge 1) { $parts[0] } else { "000" }
            $timeConnect = if ($parts.Length -ge 2) { $parts[1] } else { "0" }
            $timeAppConnect = if ($parts.Length -ge 3) { $parts[2] } else { "0" }
            $timeTotal = if ($parts.Length -ge 4) { $parts[3] } else { "0" }

            $logLine = "[$timestamp] IP: $publicIp | $proto | $url | http_code: $httpCode | time_connect: ${timeConnect}s | time_appconnect: ${timeAppConnect}s | time_total: ${timeTotal}s | curl_exit: $exitCode"

            Write-Host $logLine
            Add-Content -Path $resultsFile -Value $logLine

            # Pause 2 seconds between requests to avoid triggering LiteSpeed per-client connection/rate throttling (skip after final request)
            if ($currentRequest -lt $totalRequests) {
                Start-Sleep -Seconds 2
            }
        }
    }
}

Write-Host "=================================================================="
Write-Host "Test loop completed. Results saved to $resultsFile"
Write-Host "=================================================================="
