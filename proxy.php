<?php
declare(strict_types=1);
/**
 * proxy.php — GTR CORS-прокси v10.6
 *
 * Вердикты:
 *   ok       — 2xx/3xx/429
 *   blocked  — 4xx (кроме 404/410) — антибот/geo/битый запрос
 *   timeout  — 5xx, сетевые, HTTP/2, SSL
 *   dead     — ТОЛЬКО DNS-fail, 404, 410 (реально мёртв)
 *
 * Режимы:
 *   ?url=X              → HTML + X-Upstream-Status
 *   ?url=X&mode=json    → JSON с verdict
 *   ?url=X&mode=title   → JSON {ok, status, title, verdict}
 *   ?url=X&mode=probe   → JSON только заголовки
 *   ?url=X&mode=debug   → JSON всё (для отладки)
 */

if (!function_exists('curl_init')) {
    http_response_code(500);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['ok' => false, 'error' => 'cURL not installed']);
    exit;
}

$mode = $_GET['mode'] ?? 'html';

// ═══════════════ CORS ═══════════════
$origin = $_SERVER['HTTP_ORIGIN'] ?? '';
if ($origin !== '') {
    $originHost = parse_url($origin, PHP_URL_HOST);
    $host = $_SERVER['HTTP_HOST'] ?? '';
    $hostWithoutPort = preg_replace('/:\d+$/', '', $host);
    if ($originHost !== $hostWithoutPort) {
        http_response_code(403);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['ok' => false, 'error' => 'cross_origin']);
        exit;
    }
    header('Access-Control-Allow-Origin: ' . $origin);
    header('Access-Control-Allow-Methods: GET, OPTIONS');
    header('Access-Control-Allow-Headers: Content-Type');
    header('Access-Control-Expose-Headers: X-Upstream-Status, X-Content-Type, X-Error, X-Verdict');
    header('Vary: Origin');
}

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { http_response_code(204); exit; }
if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    http_response_code(405);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['ok' => false, 'error' => 'method_not_allowed']);
    exit;
}

// ═══════════════ SSRF ═══════════════
function is_safe_url(string $url): array {
    $p = parse_url($url);
    if (!$p || empty($p['host'])) return [false, 'bad_url'];
    if (!in_array($p['scheme'] ?? '', ['http', 'https'], true)) return [false, 'bad_scheme'];
    $host = strtolower($p['host']);

    if (in_array($host, ['localhost', 'localhost.localdomain', 'ip6-localhost'], true)) return [false, 'localhost'];
    if (str_ends_with($host, '.localhost') || str_ends_with($host, '.internal') || str_ends_with($host, '.local')) {
        return [false, 'local_tld'];
    }

    if (filter_var($host, FILTER_VALIDATE_IP)) {
        $ok = filter_var($host, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) !== false;
        return [$ok, $ok ? 'ok' : 'private_ip'];
    }

    $ips = @gethostbynamel($host);
    if ($ips === false) return [false, 'dns_fail'];
    foreach ($ips as $ip) {
        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) === false) {
            return [false, 'resolves_to_private'];
        }
    }
    return [true, 'ok'];
}

// ═══════════════ Rate limit ═══════════════
function check_rate_limit(string $ip, int $max = 500, int $window = 60): bool {
    $dir = sys_get_temp_dir() . '/gtr_proxy_rl';
    if (!is_dir($dir) && !@mkdir($dir, 0700, true)) return true;
    $file = $dir . '/' . md5($ip);
    $now = time();
    $fh = @fopen($file, 'c+');
    if (!$fh) return true;
    if (!flock($fh, LOCK_EX)) { fclose($fh); return true; }
    $data = [];
    $raw = stream_get_contents($fh);
    if ($raw) $data = json_decode($raw, true) ?: [];
    if (!is_array($data)) $data = [];
    $data = array_values(array_filter($data, fn($t) => is_numeric($t) && $t > $now - $window));
    $ok = count($data) < $max;
    if ($ok) $data[] = $now;
    ftruncate($fh, 0);
    rewind($fh);
    fwrite($fh, json_encode($data));
    fflush($fh);
    flock($fh, LOCK_UN);
    fclose($fh);
    return $ok;
}

function json_out(array $data, int $httpCode = 200): void {
    http_response_code($httpCode);
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    header('X-Content-Type-Options: nosniff');
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

/**
 * 🆕 Классификация v10.6 — консервативная.
 * dead ТОЛЬКО для: DNS fail (errno 6), 404, 410.
 * Всё остальное "не ok" = timeout или blocked.
 */
function classify(int $code, int $errno, bool $timeout): string {
    // ─── cURL ошибки ───
    if ($errno !== 0 && $errno !== 23) {
        // DNS не резолвится → ТОЧНО мёртв
        if ($errno === 6) return 'dead';

        // SSL handshake fail → часто антибот Cloudflare
        if (in_array($errno, [35, 60], true)) return 'blocked';

        // Таймауты, recv, HTTP/2 stream, partial → не факт что мёртв
        if (in_array($errno, [7, 16, 18, 28, 52, 55, 56, 92], true)) return 'timeout';

        // Неизвестная ошибка — не рискуем ставить dead
        return 'timeout';
    }

    // ─── HTTP-коды ───
    if ($code === 0) return 'dead';                     // пустой ответ = проблема
    if ($code >= 200 && $code < 400) return 'ok';       // 2xx, 3xx
    if ($code === 429) return 'ok';                     // rate limit, сайт жив

    // 🆕 ТОЛЬКО 404 и 410 = точно мёртв
    if (in_array($code, [404, 410], true)) return 'dead';

    // 🆕 5xx — сервер сломан ИЛИ блокирует ботов, может восстановиться
    if ($code >= 500) return 'timeout';                 // БЫЛО 'dead'

    // 🆕 Остальные 4xx (400, 401, 402, 403, 405, 406, 408, 451...) —
    //     сайт жив, но не пускает cURL. Браузер увидит его иначе.
    if ($code >= 400) return 'blocked';                 // БЫЛО 'dead'

    return 'ok';
}

// ═══════════════ Валидация ═══════════════
$url = (string)($_GET['url'] ?? '');
if ($url === '' || strlen($url) > 4000) {
    json_out(['ok' => false, 'error' => 'bad_url', 'reason' => 'empty_or_too_long'], 400);
}
if (!filter_var($url, FILTER_VALIDATE_URL)) {
    json_out(['ok' => false, 'error' => 'bad_url', 'reason' => 'not_a_url'], 400);
}

[$safe, $reason] = is_safe_url($url);
if (!$safe) {
    json_out(['ok' => false, 'error' => 'unsafe_url', 'reason' => $reason], 403);
}

$ip = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
if (!check_rate_limit($ip, 500, 60)) {
    json_out(['ok' => false, 'error' => 'rate_limit'], 429);
}

// ═══════════════ Fetch ═══════════════
function do_fetch(string $url, bool $verifySSL, bool $fetchBody, int $timeoutSec, int $maxBytes): array {
    $body = '';
    $ch = curl_init($url);
    $opts = [
        CURLOPT_RETURNTRANSFER => false,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_MAXREDIRS => 5,
        CURLOPT_TIMEOUT => $timeoutSec,
        CURLOPT_CONNECTTIMEOUT => 10,
        CURLOPT_USERAGENT => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/122.0.0.0 Safari/537.36',
        CURLOPT_SSL_VERIFYPEER => $verifySSL,
        CURLOPT_SSL_VERIFYHOST => $verifySSL ? 2 : 0,
        CURLOPT_ENCODING => '',
        CURLOPT_HTTPHEADER => [
            'Accept: text/html,application/xhtml+xml,application/xml;q=0.9,image/avif,image/webp,image/apng,*/*;q=0.8',
            'Accept-Language: ru-RU,ru;q=0.9,en-US;q=0.8,en;q=0.7,uk;q=0.6',
            'Accept-Encoding: gzip, deflate, br',
            'Cache-Control: no-cache',
            'Pragma: no-cache',
            'DNT: 1',
            'Connection: keep-alive',
            'Upgrade-Insecure-Requests: 1',
            'Sec-Fetch-Dest: document',
            'Sec-Fetch-Mode: navigate',
            'Sec-Fetch-Site: none',
            'Sec-Fetch-User: ?1',
            'Sec-CH-UA: "Chromium";v="122", "Google Chrome";v="122", "Not(A:Brand";v="24"',
            'Sec-CH-UA-Mobile: ?0',
            'Sec-CH-UA-Platform: "Windows"',
        ],
        CURLOPT_NOBODY => !$fetchBody,
    ];
    if ($fetchBody) {
        $opts[CURLOPT_WRITEFUNCTION] = function ($ch, $chunk) use (&$body, $maxBytes) {
            if (strlen($body) + strlen($chunk) > $maxBytes) return 0;
            $body .= $chunk;
            return strlen($chunk);
        };
    }
    curl_setopt_array($ch, $opts);
    curl_exec($ch);
    $result = [
        'body' => $body,
        'code' => (int)curl_getinfo($ch, CURLINFO_HTTP_CODE),
        'ctype' => (string)curl_getinfo($ch, CURLINFO_CONTENT_TYPE),
        'effective_url' => (string)curl_getinfo($ch, CURLINFO_EFFECTIVE_URL),
        'errno' => curl_errno($ch),
        'errmsg' => curl_error($ch),
        'time' => (float)curl_getinfo($ch, CURLINFO_TOTAL_TIME),
    ];
    curl_close($ch);
    return $result;
}

$maxBytes = 2 * 1024 * 1024;
$fetchBody = ($mode !== 'probe');

// Попытка 1: со строгой SSL, таймаут 20 сек
$r = do_fetch($url, true, $fetchBody, 20, $maxBytes);

// Ретрай #1: SSL handshake fail (35, 60) → пробуем без verify
if (in_array($r['errno'], [35, 60], true)) {
    $r2 = do_fetch($url, false, $fetchBody, 20, $maxBytes);
    if ($r2['code'] > 0 || $r2['errno'] === 0) {
        $r = $r2;
    }
}

// Ретрай #2: recv/HTTP2 fail быстро → пробуем ещё раз
if (in_array($r['errno'], [28, 56, 92], true) && $r['time'] < 4) {
    $r2 = do_fetch($url, true, $fetchBody, 20, $maxBytes);
    if ($r2['code'] > 0 || $r2['errno'] === 0) {
        $r = $r2;
    }
}

$body = $r['body'];
$code = $r['code'];
$ctype = $r['ctype'];
$effectiveUrl = $r['effective_url'] ?: $url;
$errno = $r['errno'];
$errmsg = $r['errmsg'];
$totalTime = $r['time'];
$curlFailed = ($errno !== 0 && $errno !== 23);
$curlTimeout = in_array($errno, [7, 28], true);
$verdict = classify($code, $errno, $curlTimeout);

// ═══════════════ DEBUG mode 🆕 ═══════════════
if ($mode === 'debug') {
    $title = null;
    if ($body !== '' && preg_match('/<title[^>]*>([\s\S]*?)<\/title>/i', $body, $m)) {
        $title = html_entity_decode(trim(preg_replace('/\s+/', ' ', $m[1])), ENT_QUOTES | ENT_HTML5, 'UTF-8');
    }
    json_out([
        'ok' => true,
        'url' => $url,
        'effective_url' => $effectiveUrl,
        'verdict' => $verdict,
        'status' => $code,
        'content_type' => $ctype,
        'curl_errno' => $errno,
        'curl_error' => $errmsg,
        'curl_timeout' => $curlTimeout,
        'time' => $totalTime,
        'body_length' => strlen($body),
        'body_start' => substr($body, 0, 500),
        'title' => $title,
    ]);
}

// ═══════════════ Ответ ═══════════════
if ($mode === 'json' || $mode === 'probe') {
    json_out([
        'ok' => !$curlFailed,
        'status' => $code,
        'verdict' => $verdict,
        'content_type' => $ctype,
        'body_length' => strlen($body),
        'time' => $totalTime,
        'curl_errno' => $errno,
        'curl_error' => $curlFailed ? $errmsg : null,
        'timeout' => $curlTimeout,
        'url' => $url,
        'final_url' => $effectiveUrl,
    ]);
}

if ($mode === 'title') {
    $title = null;
    if ($body !== '') {
        if (preg_match('/<meta[^>]+property\s*=\s*["\']og:title["\'][^>]*content\s*=\s*["\']([^"\']+)["\']/i', $body, $m)) {
            $title = html_entity_decode(trim($m[1]), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        } elseif (preg_match('/<meta[^>]+name\s*=\s*["\']twitter:title["\'][^>]*content\s*=\s*["\']([^"\']+)["\']/i', $body, $m)) {
            $title = html_entity_decode(trim($m[1]), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        } elseif (preg_match('/<title[^>]*>([\s\S]*?)<\/title>/i', $body, $m)) {
            $title = html_entity_decode(trim(preg_replace('/\s+/', ' ', $m[1])), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        } elseif (preg_match('/<meta[^>]+property\s*=\s*["\']og:site_name["\'][^>]*content\s*=\s*["\']([^"\']+)["\']/i', $body, $m)) {
            $title = html_entity_decode(trim($m[1]), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        }
        if ($title !== null) {
            $title = preg_replace('/\s+/u', ' ', $title);
            $title = trim($title);
            if ($title === '' || mb_strlen($title) > 300) $title = null;
        }
    }

    json_out([
        'ok' => !$curlFailed && $code >= 200 && $code < 400,
        'status' => $code,
        'verdict' => $verdict,
        'content_type' => $ctype,
        'title' => $title,
        'timeout' => $curlTimeout,
        'curl_errno' => $errno,
    ]);
}

// mode=html
if ($curlFailed && !$curlTimeout) {
    http_response_code(502);
    header('Content-Type: text/plain; charset=utf-8');
    header('X-Error: ' . $errno);
    echo 'Fetch failed: ' . $errmsg;
    exit;
}
if ($curlTimeout) {
    http_response_code(504);
    header('Content-Type: text/plain; charset=utf-8');
    header('X-Error: timeout');
    echo 'Timeout: ' . $errmsg;
    exit;
}

http_response_code($code ?: 200);
header('X-Upstream-Status: ' . $code);
header('X-Content-Type: ' . $ctype);
header('X-Content-Type-Options: nosniff');
header('Cache-Control: no-store');
header('X-Verdict: ' . $verdict);

if ($ctype) header('Content-Type: ' . $ctype);
echo $body;