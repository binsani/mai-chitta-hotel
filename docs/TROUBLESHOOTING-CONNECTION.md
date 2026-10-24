# Connection Troubleshooting Guide: `ERR_CONNECTION_CLOSED` / `curl (56)`

This guide provides steps to isolate intermittent connection closures (`ERR_CONNECTION_CLOSED` in Chromium-based browsers or `curl: (56) schannel: server closed abruptly` in Windows/curl) on **maichittahotel.com**, hosted on LiteSpeed Web Server via cPanel (Asura Hosting), with document root at `/home/istrate1/mai-chitta-hotel/backend/public`.

---

## 1. Symptom Profile

- **Symptom:** A specific client connection terminates abruptly after approximately 11 seconds without receiving any HTTP status code or HTTP response headers.
- **Contrast:** The same URLs (`https://maichittahotel.com` and `https://www.maichittahotel.com`) return `200 OK` when tested from the host's test locations and locally on the server itself via loopback.
- **Implication (Hypothesis):** The drop is consistent with a transport, TLS, or edge filtering cause rather than an unhandled PHP runtime error (which in Laravel produces a structured HTTP 500 HTML or JSON response), but this is unconfirmed until the `/health.txt` vs `/up` vs `/api/v1/health` comparison is run from the failing connection.

---

## 2. Diagnostics & Health Endpoints

### Primary Automated Diagnostic Test (`scripts/test-connection.ps1`)
The primary test is the automated test loop script located at [scripts/test-connection.ps1](../scripts/test-connection.ps1). It executes `curl.exe` across `/health.txt`, `/up`, `/api/v1/health`, and `/` on both `https://maichittahotel.com` and `https://www.maichittahotel.com` using both `--http1.1` and `--http2`. Results are written to `scripts/results.txt` and contain the detected public IP, `http_code`, `time_connect`, `time_appconnect`, `time_total`, and curl exit code:

```powershell
powershell -ExecutionPolicy Bypass -File scripts/test-connection.ps1
```

Individual endpoint checks can also be run manually across the four tiers below:

### Tier 1: Static Web Server Check (Bypasses PHP entirely)
Tests pure web server TCP and static file delivery without invoking PHP, `.htaccess` rewrite rules, or database connections:
```bash
curl -i https://maichittahotel.com/health.txt
```
**Expected Response:** `200 OK`, body: `ok` (served directly from `backend/public/health.txt`).

### Tier 2: Laravel Framework Heartbeat (`/up`)
Tests that LiteSpeed correctly invokes PHP (LSPHP) and boots the Laravel application kernel. Unthrottled:
```bash
curl -i https://maichittahotel.com/up
```
**Expected Response:** `200 OK`, body renders the Laravel "Application up" status card.

### Tier 3: Minimal JSON Health Check (Unthrottled)
Tests Laravel routing and request handling without touching the database, cache, or external dependencies. Positioned outside `throttle:api`:
```bash
curl -i https://maichittahotel.com/api/v1/health
```
**Expected Response (`200 OK`):**
```json
{
  "status": "ok",
  "time": "2026-10-23T20:42:13+01:00",
  "app_env": "production",
  "client_ip": "203.0.113.195" // (placeholder: client's detected IP)
}
```

### Tier 4: Authenticated Deep Health Check (`/api/v1/health/deep`)
Verifies database PDO connectivity, measures DB latency, and executes a cache read/write/delete cycle. Rate limited to 10 requests per minute (`throttle:10,1`).
- **Authorization:** Accepts only the `X-Health-Key` HTTP header, verified strictly against `config('observability.health_secret')`.
- **Fail-Closed Security:** If `HEALTH_CHECK_SECRET` is unset or empty on the server, the endpoint unconditionally returns `403 Forbidden` (`{"message": "Unauthorized."}`).
- **Information Protection:** Raw exception strings and the PHP version are never exposed in the response; failures are logged to Laravel log files instead.

```bash
curl -i -H "X-Health-Key: <HEALTH_CHECK_SECRET>" https://maichittahotel.com/api/v1/health/deep
```
**Expected Response (`200 OK`):**
```json
{
  "status": "healthy",
  "time": "2026-10-23T20:42:22+01:00",
  "app_env": "production",
  "checks": {
    "database": {
      "status": "ok",
      "latency_ms": 1.45
    },
    "cache": {
      "status": "ok"
    }
  },
  "duration_ms": 3.12
}
```
*Note: Any request with an absent, invalid, or unconfigured secret returns `403 Forbidden`.*

### Capturing a Failure
To capture verbose connection and handshake details during an abrupt drop:
```powershell
curl.exe -v --max-time 20 https://maichittahotel.com/health.txt 2>&1 | Tee-Object fail-verbose.txt
```
How to read the timings (note: this is a metric reading guide, not a root-cause diagnosis): if `time_appconnect` is above 0 and `http_code` is 000, the TLS handshake finished and the connection closed afterward; if `time_appconnect` is 0, it closed during the handshake. Note that the `time_appconnect` reading applies to the script's output in `results.txt`, and that `fail-verbose.txt` shows the TLS handshake in curl's verbose lines instead.

---

## 3. Hypotheses for the ~11-Second Connection Drop

The root cause of the intermittent connection closure remains **unconfirmed**. The following are potential hypotheses consistent with an ~11-second drop without HTTP headers:

### Hypothesis A: LiteSpeed Per-Client Throttling / Anti-DDoS
- **Mechanism:** LiteSpeed Web Server features built-in connection and bandwidth throttling (`Per Client Throttling`). If an IP initiates multiple concurrent TCP connections or rapid requests, LiteSpeed may place subsequent connections into a hold queue or drop the TCP socket when a grace timeout expires (~10–15s).
- **Evidence needed:** Entries in web server error logs noting throttling or client connection aborts.
- **Investigation steps:**
  1. In cPanel, navigate to **Metrics** -> **Errors**.
  2. Ask the host to check the LiteSpeed/Apache error log for this client IP (e.g. searching for throttled or closed connection events).

### Hypothesis B: ModSecurity (WAF) Rule Trigger
- **Mechanism:** A ModSecurity rule may trigger on specific client request headers, missing headers, or TLS characteristics. Some WAF configurations drop connections or pause execution instead of returning a 403 Forbidden status.
- **Evidence needed:** ModSecurity Audit Log entries for the client IP with Rule IDs and action taken.
- **Investigation steps:**
  1. In cPanel, open **ModSecurity**.
  2. Temporarily toggle ModSecurity to **OFF** for `maichittahotel.com` and reproduce the request from the affected client.
  3. If the drop ceases, re-enable ModSecurity and inspect the log to whitelist the specific false-positive Rule ID.

### Hypothesis C: Server-Level Bot Shield / Challenge (e.g., Imunify360, LiteSpeed Anti-Bot)
- **Mechanism:** Shared hosting platforms commonly employ anti-bot systems (e.g., Imunify360 or LiteSpeed Web Application Firewall). When an IP or TLS fingerprint is flagged as untrusted, the server may hold the connection awaiting a JavaScript challenge or reCAPTCHA validation. Headless tools, curl, or clients unable to execute JS timeout after ~10–12 seconds and the server terminates the TCP socket.
- **Evidence needed:** Records in Imunify360 "Incidents" or "CAPTCHA" event log matching the client IP.
- **Investigation steps:**
  1. In cPanel, look for **Imunify360** (if enabled by host).
  2. Review the incident log for the client IP.
  3. If accessible, add the client IP to the whitelist temporarily to test if connection drops disappear.

### Hypothesis D: Network / Middlebox / ISP Deep Packet Inspection (DPI)
- **Mechanism:** An intermediate router, firewall, or ISP filter between the client and the hosting provider's data center may terminate stateful TCP connections after an initial payload or SNI inspection (~10 seconds).
- **Evidence needed:** Packet capture (`tcpdump` / Wireshark) showing whether a TCP `RST` is injected by an intermediate hop (indicated by TTL mismatch) or originates directly from the host.
- **Investigation steps:**
  1. Run a traceroute / `mtr` to `maichittahotel.com` from the affected client.
  2. Packet capture: run `tcpdump` / Wireshark during the connection attempt to inspect TCP flags, sequence numbers, and TTL.
  3. Curl trace:
     ```bash
     curl -iv https://maichittahotel.com --trace-time --trace-ascii curl_trace.log
     ```
  4. Inspect `curl_trace.log` to determine the exact phase (TLS Client Hello, Server Hello, or post-handshake) where the socket closed.

### Hypothesis E: Windows SChannel / TLS Cipher Negotiation Stall
- **Mechanism:** Default Windows `curl.exe` uses Microsoft SChannel (`schannel: server closed abruptly`). Discrepancies between SChannel's supported TLS 1.3 / 1.2 cipher suites and LiteSpeed's OpenSSL / BoringSSL cipher negotiation can cause connection stalls or abrupt resets.
- **Evidence needed:** Successful connection using OpenSSL-compiled curl or different TLS backend from the same client network.
- **Investigation steps:**
  1. Test with OpenSSL-based curl (e.g., inside Git Bash, WSL, or curl with `--tlsv1.2` / `--tlsv1.3` explicitly specified):
     ```bash
     curl.exe --tlsv1.2 -iv https://maichittahotel.com
     curl.exe --tlsv1.3 -iv https://maichittahotel.com
     ```
  2. Compare behavior between HTTP/1.1 and HTTP/2:
     ```bash
     curl.exe --http1.1 -iv https://maichittahotel.com
     ```

---

## 4. Reverse Proxy & Client IP Logging (`TRUSTED_PROXIES`)

In `backend/bootstrap/app.php`:
```php
$middleware->trustProxies(at: env('TRUSTED_PROXIES', '127.0.0.1'));
```

### Analysis:
1. **Direct LiteSpeed / LSPHP (Standard cPanel environment):**
   LiteSpeed receives incoming TCP connections directly and assigns the client's public IP to `$_SERVER['REMOTE_ADDR']`. Because `REMOTE_ADDR` is not `127.0.0.1` (e.g. `203.0.113.195` [placeholder client IP]), Laravel treats the connection as direct and logs the client's actual IP correctly. Untrusted `X-Forwarded-For` headers sent by clients are discarded.
2. **Upstream Reverse Proxy or CDN (e.g., Cloudflare, edge proxy):**
   If traffic passes through an external proxy whose IP is not `127.0.0.1` (e.g. `198.51.100.1` [placeholder proxy IP]), `REMOTE_ADDR` reflects the proxy's IP. Because Laravel does not trust that proxy IP, it ignores `X-Forwarded-For` and logs the proxy's IP for every visitor. This would bundle all users into a single rate-limiting bucket.
3. **Security Caution on `TRUSTED_PROXIES="*"`:**
   Never set `TRUSTED_PROXIES="*"` unless an upstream reverse proxy (like Cloudflare) is actively stripping and rewriting `X-Forwarded-For` before reaching LiteSpeed. On a directly exposed server, `TRUSTED_PROXIES="*"` allows any client to spoof their IP by supplying an arbitrary `X-Forwarded-For` header.

---

## 5. Slow Request Logging

Slow request logging is integrated into the Laravel middleware stack via `App\Http\Middleware\LogSlowRequests`. It defaults to 2000 ms and automatically strips query strings to prevent logging sensitive URL parameters:

- **Config file:** `backend/config/observability.php`
- **Default threshold:** 2000 ms
- **Activation flag in `.env`:**
  ```env
  LOG_SLOW_REQUESTS=true
  SLOW_REQUEST_THRESHOLD_MS=2000
  ```

When triggered, slow requests log a warning to `storage/logs/laravel.log`:
```
[2026-10-23 20:40:00] local.WARNING: Slow request detected {"request_id":"...","method":"GET","url":"https://maichittahotel.com/api/v1/rooms","duration_ms":2150.4,"threshold_ms":2000,"ip":"203.0.113.195"}
```
*(Where `203.0.113.195` is an example client IP placeholder).*

---

## 6. PowerShell Connection Diagnostic Script & Results Table

> [!NOTE]
> `scripts/test-connection.ps1` is the primary test; this PowerShell script acts as a secondary client implementation for comparison from the same host.

This PowerShell script tests each endpoint from a Windows client, records duration and network status, and formats the output into a structured results table:

```powershell
# Run from Windows PowerShell (5.1+ compatible) on the client experiencing dropped connections
$targetHost = "https://maichittahotel.com"
$netProfile = Get-NetConnectionProfile -ErrorAction SilentlyContinue | Select-Object -ExpandProperty Name -First 1
$networkDesc = if ($netProfile) { $netProfile } else { "Default Network" }
$publicIp = try { (Invoke-RestMethod -Uri "https://api.ipify.org" -TimeoutSec 3).Trim() } catch { "Unavailable" }

$testPaths = @(
    "/health.txt",
    "/up",
    "/api/v1/health",
    "/"
)

$results = @()

foreach ($path in $testPaths) {
    $url = "$targetHost$path"
    $cmd = "GET $path"
    $timestamp = (Get-Date).ToUniversalTime().ToString("yyyy-MM-dd HH:mm:ss 'UTC'")
    $sw = [System.Diagnostics.Stopwatch]::StartNew()
    $resultDesc = ""

    try {
        $response = Invoke-WebRequest -Uri $url -Method Get -TimeoutSec 15 -UseBasicParsing
        $sw.Stop()
        $resultDesc = "$($response.StatusCode) OK ($($sw.ElapsedMilliseconds)ms)"
    } catch [System.Net.WebException] {
        $sw.Stop()
        if ($_.Exception.Response) {
            $code = [int]$_.Exception.Response.StatusCode
            $resultDesc = "HTTP $code ($($sw.ElapsedMilliseconds)ms)"
        } else {
            $resultDesc = "Aborted at $($sw.ElapsedMilliseconds)ms: $($_.Exception.Message)"
        }
    } catch {
        $sw.Stop()
        $resultDesc = "Failed at $($sw.ElapsedMilliseconds)ms: $($_.Exception.Message)"
    }

    $results += [PSCustomObject]@{
        Command   = $cmd
        Network   = $networkDesc
        Result    = $resultDesc
        Timestamp = $timestamp
        PublicIP  = $publicIp
    }
}

$results | Format-Table -AutoSize
```

### Diagnostic Results Table

*Fill in from real test runs only.*

| Command | Network | Result | Timestamp | Public IP |
|---|---|---|---|---|
| | | | | |

### How to Interpret the Table (Hypotheses):
1. **Hypothesis: Transport / TLS / Firewall issue (outside application layer):**
   If all commands (including the static `/health.txt`) fail or drop at ~11s, the drop is consistent with an issue occurring before PHP/Laravel request execution (e.g. TCP/TLS handshake stall, LiteSpeed per-client throttling, WAF bot challenge, or network DPI).
2. **Hypothesis: PHP / LSPHP process pool contention:**
   If `/health.txt` consistently succeeds in < 300 ms, but `/up` and application routes stall or abort, this points to a potential bottleneck or delay at the PHP process manager (LSPHP) layer.
3. **Hypothesis: Payload / Response-specific trigger:**
   If `/health.txt`, `/up`, and `/api/v1/health` return `200 OK`, but `/` alone fails, this would not conclude an application bug; rather, it would warrant a packet capture and a comparative inspection of response payload size, response headers, and compression (gzip/brotli) to determine why the larger HTML stream was interrupted.

---

## 7. Deployment Checklist

When deploying these observability and health check features to the cPanel production server:

1. **Set `HEALTH_CHECK_SECRET` in server `.env`:**
   Generate a cryptographically secure random token:
   ```bash
   php -r "echo bin2hex(random_bytes(32)) . PHP_EOL;"
   ```
   Add to `/home/istrate1/mai-chitta-hotel/backend/.env`:
   ```env
   HEALTH_CHECK_SECRET=<generated_token>
   ```
2. **Configure slow request logging:**
   Leave `LOG_SLOW_REQUESTS=false` in `.env` by default to ensure zero logging overhead during normal operations. Only set `LOG_SLOW_REQUESTS=true` when actively investigating slow transaction times.
3. **Clear and rebuild caches:**
   In `/home/istrate1/mai-chitta-hotel/backend`:
   ```bash
   php artisan config:clear
   php artisan route:clear
   # If route or config caching is used in production:
   php artisan config:cache
   php artisan route:cache
   ```
4. **Test after deploy:**
   Verify connectivity to the unthrottled endpoints:
   ```bash
   curl -i https://maichittahotel.com/health.txt
   curl -i https://maichittahotel.com/api/v1/health
   curl -i -H "X-Health-Key: <HEALTH_CHECK_SECRET>" https://maichittahotel.com/api/v1/health/deep
   ```
5. **Check `TRUSTED_PROXIES` in server `.env`:**
   After deploy, compare `client_ip` from `/api/v1/health` with your actual public IP to confirm LiteSpeed and Laravel are reporting the remote client IP accurately.
