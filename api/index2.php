<?php
/**
 * Facebook Video Downloader — PHP API Endpoint
 * v2 — Full Debug + Auto Discovery
 *
 * Usage:
 *   GET  ?url=https://www.facebook.com/reel/123456
 *   GET  ?url=...&debug=1        → full debug log in response
 *   POST JSON: { "url": "...", "debug": true }
 */

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(204);
    exit;
}

// ─── Global Debug Log ─────────────────────────────────────────────────────────
$DEBUG_LOG = [];

function dbg(string $msg, mixed $data = null): void {
    global $DEBUG_LOG;
    $entry = ['msg' => $msg];
    if ($data !== null) $entry['data'] = $data;
    $DEBUG_LOG[] = $entry;
}

// ─── Helpers ──────────────────────────────────────────────────────────────────

function isValidFacebookUrl(string $url): bool {
    return (bool) preg_match('/facebook\.com|fb\.watch/i', $url);
}

function extractFacebookUrls(string $text): array {
    preg_match_all('/(https?:\/\/)?(www\.)?(m\.)?(facebook\.com|fb\.watch)\/\S+/i', $text, $m);
    return array_values(array_unique($m[0] ?? []));
}

function normalizeUrl(string $url): string {
    return str_starts_with($url, 'http') ? $url : 'https://' . $url;
}

function formatDuration(int $s): string {
    $h = intdiv($s, 3600); $m = intdiv($s % 3600, 60); $sec = $s % 60;
    return $h > 0 ? sprintf('%d:%02d:%02d', $h, $m, $sec) : sprintf('%d:%02d', $m, $sec);
}

function decodeHtmlEntities(?string $s): ?string {
    if ($s === null) return null;
    return html_entity_decode($s, ENT_QUOTES | ENT_HTML5, 'UTF-8');
}

function cleanVideoUrl(?string $s): ?string {
    if (!$s) return null;
    $s = decodeHtmlEntities($s);
    $s = str_replace('\\/', '/', $s);
    $s = preg_replace_callback('/\\\\u([0-9a-fA-F]{4})/', fn($m) => mb_chr(hexdec($m[1]), 'UTF-8'), $s);
    $s = preg_replace_callback('/\\\\x([0-9a-fA-F]{2})/', fn($m) => chr(hexdec($m[1])), $s);
    // Remove query string escaping artifacts
    $s = str_replace('&amp;', '&', $s);
    if (str_contains($s, 'lookaside.fbsbx.com/lookaside/crawler')) return null;
    if (!str_contains($s, 'fbcdn.net') && !str_contains($s, 'facebook.com/video')) return null;
    // Basic URL sanity
    if (!filter_var($s, FILTER_VALIDATE_URL)) return null;
    return $s;
}

function extractFirst(string $pattern, string $html): ?string {
    return preg_match($pattern, $html, $m) ? ($m[1] ?? null) : null;
}

function extractAll(string $pattern, string $html): array {
    preg_match_all($pattern, $html, $m);
    return array_values(array_filter(array_map('cleanVideoUrl', $m[1] ?? [])));
}

// ─── HTTP Fetch (cURL) ────────────────────────────────────────────────────────

function getSafeEncoding(): string {
    static $enc = null;
    if ($enc !== null) return $enc;
    $v = curl_version();
    $supportsBrotli = (defined('CURL_VERSION_BROTLI') && ($v['features'] & CURL_VERSION_BROTLI))
                   || str_contains(strtolower($v['version'] ?? ''), 'brotli');
    $enc = $supportsBrotli ? '' : 'gzip, deflate';
    return $enc;
}

function httpGet(string $url, array $headers = [], int $timeout = 25): ?array {
    $encoding = getSafeEncoding();

    // Remove any caller-supplied Accept-Encoding and inject safe value
    $headers = array_values(array_filter($headers,
        fn($h) => stripos($h, 'accept-encoding') !== 0
    ));
    $headers[] = 'Accept-Encoding: ' . $encoding;

    $ch = curl_init();
    curl_setopt_array($ch, [
        CURLOPT_URL            => $url,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_MAXREDIRS      => 10,
        CURLOPT_TIMEOUT        => $timeout,
        CURLOPT_ENCODING       => $encoding,
        CURLOPT_HTTPHEADER     => $headers,
        CURLOPT_SSL_VERIFYPEER => false,
        CURLOPT_SSL_VERIFYHOST => false,
        CURLOPT_COOKIEFILE     => '',
        CURLOPT_COOKIEJAR      => '',
    ]);

    $body     = curl_exec($ch);
    $httpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $finalUrl = curl_getinfo($ch, CURLINFO_EFFECTIVE_URL);
    $error    = curl_error($ch);
    curl_close($ch);

    if ($error || $body === false) {
        dbg("cURL error for $url", $error);
        return null;
    }

    return ['body' => $body, 'http_code' => $httpCode, 'final_url' => $finalUrl];
}

function httpPost(string $url, string $body, array $headers = [], int $timeout = 25): ?array {
    $encoding = getSafeEncoding();
    $headers  = array_values(array_filter($headers,
        fn($h) => stripos($h, 'accept-encoding') !== 0
    ));
    $headers[] = 'Accept-Encoding: ' . $encoding;

    $ch = curl_init();
    curl_setopt_array($ch, [
        CURLOPT_URL            => $url,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => $body,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_MAXREDIRS      => 5,
        CURLOPT_TIMEOUT        => $timeout,
        CURLOPT_ENCODING       => $encoding,
        CURLOPT_HTTPHEADER     => $headers,
        CURLOPT_SSL_VERIFYPEER => false,
        CURLOPT_SSL_VERIFYHOST => false,
    ]);

    $resp     = curl_exec($ch);
    $httpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $error    = curl_error($ch);
    curl_close($ch);

    if ($error || $resp === false) {
        dbg("cURL POST error for $url", $error);
        return null;
    }

    return ['body' => $resp, 'http_code' => $httpCode];
}

// ─── Build URL Variants ───────────────────────────────────────────────────────

function buildUrlVariants(string $url): array {
    $variants = [];

    $www      = preg_replace('/^https?:\/\/(m\.|www\.)?facebook\.com/', 'https://www.facebook.com', $url);
    $mbasic   = preg_replace('/^https?:\/\/(m\.|www\.)?facebook\.com/', 'https://mbasic.facebook.com', $url);
    $mobile   = preg_replace('/^https?:\/\/(m\.|www\.)?facebook\.com/', 'https://m.facebook.com', $url);

    $variants[] = $www;
    $variants[] = $mbasic;
    $variants[] = $mobile;
    $variants[] = $url;

    // Extract video/reel ID from various formats
    $id = null;
    if (preg_match('/\/reel\/(\d+)/i', $url, $m))             $id = $m[1];
    elseif (preg_match('/\/videos\/(?:[^\/]+\/)?(\d+)/i', $url, $m)) $id = $m[1];
    elseif (preg_match('/[?&]v=(\d+)/i', $url, $m))           $id = $m[1];
    elseif (preg_match('/\/watch\?v=(\d+)/i', $url, $m))      $id = $m[1];
    elseif (preg_match('/\/video\/(\d+)/i', $url, $m))        $id = $m[1];
    elseif (preg_match('/story_fbid=(\d+)/i', $url, $m))      $id = $m[1];
    elseif (preg_match('/permalink\/(\d+)/i', $url, $m))      $id = $m[1];

    if ($id) {
        dbg("Extracted video ID: $id");
        $variants[] = "https://www.facebook.com/video/$id/";
        $variants[] = "https://www.facebook.com/watch?v=$id";
        $variants[] = "https://mbasic.facebook.com/video/$id/";
        $variants[] = "https://mbasic.facebook.com/watch?v=$id";
        $variants[] = "https://www.facebook.com/reel/$id/";
        $variants[] = "https://mbasic.facebook.com/reel/$id/";
        $variants[] = "https://www.facebook.com/videos/$id/";
    }

    // share/v variants
    if (preg_match('/\/share\/v\//i', $url)) {
        $base = rtrim($www, '/');
        $variants[] = $base . '/?_rdr=p';
        $variants[] = $base . '/?ref=sharing';
    }

    // fb.watch
    if (preg_match('/fb\.watch/i', $url)) {
        $variants[] = preg_replace('/^https?:\/\/fb\.watch/', 'https://www.facebook.com/watch', $url);
    }

    return array_values(array_unique(array_filter($variants)));
}

// ─── Parse Video URLs From HTML ───────────────────────────────────────────────

function parseVideoUrlsFromHtml(string $html): array {
    $results = ['sd' => null, 'hd' => null];

    // All known patterns — ordered by specificity / reliability
    $sdPatterns = [
        '/"playable_url"\s*:\s*"([^"]+)"/',
        '/sd_src(?:_no_ratelimit)?\s*:\s*"([^"]+)"/',
        '/"browser_native_sd_url"\s*:\s*"([^"]+)"/',
        '/"sd_src"\s*:\s*"([^"]+)"/',
        '/videoURL\s*:\s*"([^"]+)"/',
        '/"src"\s*:\s*"(https?:\\\\?\/\\\\?\/[^"]*fbcdn\.net[^"]+\.mp4[^"]*)"/',
        '/content="(https?:\/\/video[^"]+\.mp4[^"]*)"/',
        // JSON-embedded blobs
        '/"uri"\s*:\s*"(https?:[^"]*fbcdn\.net[^"]*(?:sd|SD)[^"]*\.mp4[^"]*)"/',
        '/"url"\s*:\s*"(https?:[^"]*fbcdn\.net[^"]*(?:sd|SD)[^"]*\.mp4[^"]*)"/',
    ];

    $hdPatterns = [
        '/"playable_url_quality_hd"\s*:\s*"([^"]+)"/',
        '/hd_src(?:_no_ratelimit)?\s*:\s*"([^"]+)"/',
        '/"browser_native_hd_url"\s*:\s*"([^"]+)"/',
        '/"hd_src"\s*:\s*"([^"]+)"/',
        '/"uri"\s*:\s*"(https?:[^"]*fbcdn\.net[^"]*(?:hd|HD)[^"]*\.mp4[^"]*)"/',
        '/"url"\s*:\s*"(https?:[^"]*fbcdn\.net[^"]*(?:hd|HD)[^"]*\.mp4[^"]*)"/',
    ];

    // Generic MP4 catch-all (last resort)
    $genericPatterns = [
        '/"playable_url[^"]*"\s*:\s*"([^"]+)"/',
        '/(https?:\\\\?\/\\\\?\/[^"\'<>\s]*fbcdn\.net[^"\'<>\s]*\.mp4[^"\'<>\s]*)/',
        '/(https?:\/\/[^"\'<>\s]*fbcdn\.net[^"\'<>\s]*\.mp4[^"\'<>\s]*)/',
    ];

    foreach ($sdPatterns as $pat) {
        $found = extractAll($pat, $html);
        if ($found) { $results['sd'] = $found[0]; break; }
    }

    foreach ($hdPatterns as $pat) {
        $found = extractAll($pat, $html);
        if ($found) { $results['hd'] = $found[0]; break; }
    }

    // If still nothing, try generic
    if (!$results['sd'] && !$results['hd']) {
        foreach ($genericPatterns as $pat) {
            $found = extractAll($pat, $html);
            if ($found) {
                dbg("Generic fallback pattern matched", $pat);
                $results['sd'] = $found[0];
                break;
            }
        }
    }

    return $results;
}

// ─── Strategy: GraphQL API ────────────────────────────────────────────────────

function tryGraphQL(string $videoId): ?array {
    dbg("Trying GraphQL API for video ID: $videoId");

    $query = json_encode([
        'av'              => '0',
        '__user'          => '0',
        '__a'             => '1',
        '__req'           => 'a',
        '__hs'            => '',
        'dpr'             => '1',
        '__ccg'           => 'EXCELLENT',
        '__rev'           => '',
        '__s'             => '',
        '__hsi'           => '',
        '__dyn'           => '',
        '__csr'           => '',
        'lsd'             => 'AVqbxe3J_no',
        'jazoest'         => '2957',
        '__spin_r'        => '',
        '__spin_b'        => '',
        '__spin_t'        => '',
        'fb_api_caller_class' => 'RelayModern',
        'fb_api_req_friendly_name' => 'PolarisFeedVideoPlaybackQuery',
        'variables'       => json_encode(['videoID' => $videoId]),
        'server_timestamps' => 'true',
        'doc_id'          => '7044246465655191',
    ]);

    // Alternative: direct video endpoint
    $url = 'https://www.facebook.com/video/playback/cursor/?video_id=' . urlencode($videoId);
    $res = httpGet($url, [
        'Accept: application/json, text/javascript, */*; q=0.01',
        'X-Requested-With: XMLHttpRequest',
        'Referer: https://www.facebook.com/',
        'User-Agent: Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 Chrome/120.0.0.0 Safari/537.36',
    ]);

    if ($res && $res['http_code'] === 200) {
        $json = json_decode($res['body'], true);
        dbg("GraphQL cursor response", substr($res['body'], 0, 300));
        if (!empty($json)) return $json;
    }

    return null;
}

// ─── Strategy: oEmbed ────────────────────────────────────────────────────────

function tryOEmbed(string $url): array {
    dbg("Trying oEmbed for: $url");
    $oUrl = 'https://www.facebook.com/plugins/video/oembed.json/?url=' . urlencode($url);
    $res  = httpGet($oUrl, [
        'Accept: application/json',
        'User-Agent: Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 Chrome/120.0.0.0 Safari/537.36',
    ], 15);

    if ($res && $res['http_code'] === 200) {
        $json = json_decode($res['body'], true);
        if ($json) {
            dbg("oEmbed success", ['title' => $json['title'] ?? null]);
            return [
                'title'     => $json['title']         ?? null,
                'author'    => $json['author_name']   ?? null,
                'thumbnail' => $json['thumbnail_url'] ?? null,
            ];
        }
    }
    dbg("oEmbed failed", $res['http_code'] ?? 'no response');
    return [];
}

// ─── Strategy: Scrape HTML ────────────────────────────────────────────────────

function tryScrapeHtml(string $url, string $ua): ?array {
    $commonHeaders = [
        'Accept: text/html,application/xhtml+xml,application/xml;q=0.9,image/avif,image/webp,*/*;q=0.8',
        'Accept-Language: en-US,en;q=0.9',
        'Connection: keep-alive',
        'Upgrade-Insecure-Requests: 1',
        'Sec-Fetch-Dest: document',
        'Sec-Fetch-Mode: navigate',
        'Sec-Fetch-Site: none',
        'Sec-Fetch-User: ?1',
        'Cache-Control: max-age=0',
        'Referer: https://www.facebook.com/',
        "User-Agent: $ua",
    ];

    $res = httpGet($url, $commonHeaders, 25);
    if (!$res) return null;

    dbg("HTTP {$res['http_code']} for " . substr($url, 0, 80), [
        'final_url'    => substr($res['final_url'], 0, 100),
        'body_length'  => strlen($res['body']),
        'ua_snippet'   => substr($ua, 0, 40),
    ]);

    if ($res['http_code'] !== 200) return null;

    $html = $res['body'];

    if (preg_match('/<title>\s*(Error|Something went wrong|Page Not Found|Log in)/i', $html, $tm)) {
        dbg("Page error title detected: " . $tm[0]);
        return null;
    }

    // Check if it's a login gate
    if (str_contains($html, '"loginRequired":true') || str_contains($html, 'login_required')) {
        dbg("Login gate detected for $url");
        return null;
    }

    $urls = parseVideoUrlsFromHtml($html);

    dbg("Video URLs found", [
        'sd' => $urls['sd'] ? substr($urls['sd'], 0, 80) : null,
        'hd' => $urls['hd'] ? substr($urls['hd'], 0, 80) : null,
    ]);

    // Extract meta
    $metaTitle    = decodeHtmlEntities(extractFirst('/<meta property="og:title"\s+content="([^"]+)"/i', $html));
    $metaThumb    = extractFirst('/<meta property="og:image"\s+content="([^"]+)"/i', $html);
    $metaDesc     = decodeHtmlEntities(extractFirst('/<meta property="og:description"\s+content="([^"]+)"/i', $html));
    $metaDuration = null;
    $metaViews    = null;
    $metaReact    = null;
    $metaAuthor   = null;

    $durRaw = extractFirst('/<meta property="video:duration"\s+content="(\d+)"/i', $html)
           ?? extractFirst('/"playable_duration_in_ms"\s*:\s*(\d+)/', $html)
           ?? extractFirst('/"duration_in_sec"\s*:\s*(\d+)/', $html)
           ?? extractFirst('/"duration"\s*:\s*(\d+)/', $html);
    if ($durRaw) {
        $metaDuration = strlen($durRaw) > 5 ? (int) floor((int)$durRaw / 1000) : (int)$durRaw;
    }

    if ($metaTitle && preg_match('/^(.+?)\s*\|\s*(.+?)(?:\s*\|\s*([^|]+))?$/s', $metaTitle, $parts)) {
        $stats     = $parts[1];
        $metaTitle = trim($parts[2]);
        $metaAuthor = isset($parts[3]) ? trim($parts[3]) : null;
        if (preg_match('/([\d.,]+[KMB]?)\s*views?/i', $stats, $v)) $metaViews = $v[1] . ' views';
        if (preg_match('/([\d.,]+[KMB]?)\s*reactions?/i', $stats, $r)) $metaReact = $r[1] . ' reactions';
    }

    // Dump a snippet of HTML for debug if no video found
    if (!$urls['sd'] && !$urls['hd']) {
        // Check for known patterns to understand why
        $hasFbcdn = str_contains($html, 'fbcdn.net');
        $hasMp4   = str_contains($html, '.mp4');
        $hasPlayable = str_contains($html, 'playable_url');
        dbg("No video URL — page analysis", [
            'has_fbcdn'    => $hasFbcdn,
            'has_mp4'      => $hasMp4,
            'has_playable' => $hasPlayable,
            'html_snippet' => substr(strip_tags($html), 0, 500),
        ]);
        return null;
    }

    return [
        'sd_url'  => $urls['sd'],
        'hd_url'  => $urls['hd'],
        'meta'    => compact('metaTitle', 'metaThumb', 'metaDesc', 'metaDuration', 'metaViews', 'metaReact', 'metaAuthor'),
    ];
}

// ─── Core Orchestrator ────────────────────────────────────────────────────────

function getFacebookVideo(string $url): array {
    dbg("=== getFacebookVideo START ===", $url);

    // ── oEmbed (metadata only) ────────────────────────────────────────────────
    $oembedData = tryOEmbed($url);

    // ── Build all URL variants ────────────────────────────────────────────────
    $urlVariants = buildUrlVariants($url);
    dbg("URL variants to try", $urlVariants);

    $uaList = [
        // Desktop Chrome (most permissive)
        'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/122.0.0.0 Safari/537.36',
        // Older Chrome (bypasses some newer anti-bot)
        'Mozilla/5.0 (Windows NT 6.1; WOW64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/97.0.4692.71 Safari/537.36',
        // Firefox
        'Mozilla/5.0 (Windows NT 10.0; Win64; x64; rv:122.0) Gecko/20100101 Firefox/122.0',
        // Mobile Android
        'Mozilla/5.0 (Linux; Android 13; Pixel 7) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/122.0.6261.105 Mobile Safari/537.36',
        // Safari macOS
        'Mozilla/5.0 (Macintosh; Intel Mac OS X 14_3) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/17.2 Safari/605.1.15',
        // curl-like (mbasic friendly)
        'facebookexternalhit/1.1 (+http://www.facebook.com/externalhit_uatext.php)',
    ];

    $sdUrl = null; $hdUrl = null; $meta = [];
    $found = false;

    foreach ($uaList as $ua) {
        if ($found) break;
        foreach ($urlVariants as $variant) {
            dbg("Trying UA=" . substr($ua, 0, 35) . " URL=" . substr($variant, 0, 70));
            $result = tryScrapeHtml($variant, $ua);
            if ($result) {
                $sdUrl = $result['sd_url'];
                $hdUrl = $result['hd_url'];
                $meta  = $result['meta'];
                $found = true;
                dbg("SUCCESS: video URLs found", [
                    'via_url' => $variant,
                    'via_ua'  => substr($ua, 0, 40),
                    'sd'      => $sdUrl ? substr($sdUrl, 0, 80) : null,
                    'hd'      => $hdUrl ? substr($hdUrl, 0, 80) : null,
                ]);
                break;
            }
        }
    }

    // ── Last resort: try GraphQL if we have a numeric ID ──────────────────────
    if (!$sdUrl && !$hdUrl) {
        $id = null;
        foreach ($urlVariants as $v) {
            if (preg_match('/\/(?:reel|video|videos)\/(\d+)/i', $v, $m)) { $id = $m[1]; break; }
            if (preg_match('/[?&]v=(\d+)/i', $v, $m)) { $id = $m[1]; break; }
        }
        if ($id) {
            dbg("All scrape attempts failed — trying GraphQL with ID: $id");
            $gql = tryGraphQL($id);
            if ($gql) {
                dbg("GraphQL raw response", json_encode($gql, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
                // Attempt to extract URLs from GraphQL response
                $gqlJson = json_encode($gql);
                $urls = parseVideoUrlsFromHtml($gqlJson);
                $sdUrl = $urls['sd'];
                $hdUrl = $urls['hd'];
            }
        }
    }

    if (!$sdUrl && !$hdUrl) {
        dbg("=== FAILED: no video URL found after all strategies ===");
        throw new RuntimeException('URL video tidak ditemukan. Video mungkin private, butuh login, atau Facebook memblokir scraping dari server ini.');
    }

    dbg("=== getFacebookVideo END: SUCCESS ===");

    // Fix duration: playable_duration_in_ms returns ms (e.g. 11300 = 11s).
    // video:duration meta returns seconds. Heuristic: >3600 almost certainly ms.
    $durRaw = $meta['metaDuration'] ?? null;
    $durSec = null;
    if ($durRaw !== null) {
        $durSec = ($durRaw > 3600) ? (int) round($durRaw / 1000) : (int) $durRaw;
    }

    // Decode HTML entities in thumbnail URL (oEmbed may return &amp; in URLs)
    $thumb = $oembedData['thumbnail'] ?? $meta['metaThumb'] ?? null;
    if ($thumb) $thumb = html_entity_decode($thumb, ENT_QUOTES | ENT_HTML5, 'UTF-8');

    return [
        'hd_url' => $hdUrl ?? $sdUrl,
        'extra'  => [
            'title'        => $oembedData['title']  ?? $meta['metaTitle']  ?? 'Facebook Video',
            'author'       => $oembedData['author'] ?? $meta['metaAuthor'] ?? null,
            'thumbnail'    => $thumb,
            'duration_sec' => $durSec,
            'duration_fmt' => $durSec ? formatDuration($durSec) : null,
            'views'        => $meta['metaViews'] ?? null,
            'reactions'    => $meta['metaReact'] ?? null,
            'description'  => $meta['metaDesc']  ?? null,
        ],
    ];
}

// ─── Request Parsing ──────────────────────────────────────────────────────────

function getInputData(): array {
    $raw   = '';
    $debug = false;
    $urls  = [];

    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $ct = $_SERVER['CONTENT_TYPE'] ?? '';
        if (str_contains($ct, 'application/json')) {
            $body  = json_decode(file_get_contents('php://input'), true) ?? [];
            $debug = !empty($body['debug']);
            if (!empty($body['urls']) && is_array($body['urls'])) {
                $urls = array_values(array_filter(array_map('trim', $body['urls'])));
            } else {
                $raw = $body['url'] ?? '';
            }
        } else {
            $raw   = $_POST['url'] ?? '';
            $debug = !empty($_POST['debug']);
        }
    } else {
        $raw   = $_GET['url'] ?? '';
        $debug = !empty($_GET['debug']);
    }

    if (empty($urls) && $raw) {
        $urls = extractFacebookUrls($raw);
    }

    return ['urls' => $urls, 'debug' => $debug];
}

// ─── JSON Response ────────────────────────────────────────────────────────────

function jsonResponse(array $data, int $code = 200): void {
    http_response_code($code);
    echo json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
    exit;
}

// ─── Main ─────────────────────────────────────────────────────────────────────

try {
    $input = getInputData();
    $debug = $input['debug'];
    $urls  = $input['urls'];

    if (empty($urls)) {
        jsonResponse([
            'success' => false,
            'error'   => 'Parameter url wajib diisi.',
            'example' => '?url=https://www.facebook.com/reel/123456&debug=1',
        ], 400);
    }

    $validUrls = array_values(array_filter(array_map('normalizeUrl', $urls), 'isValidFacebookUrl'));

    if (empty($validUrls)) {
        jsonResponse(['success' => false, 'error' => 'URL Facebook tidak valid.'], 400);
    }

    $isBulk = count($validUrls) > 1;

    if (!$isBulk) {
        // ── Single ──────────────────────────────────────────────────────────
        try {
            $data = getFacebookVideo($validUrls[0]);
            $resp = ['success' => true, 'url' => $validUrls[0], 'data' => $data];
            if ($debug) $resp['debug'] = $GLOBALS['DEBUG_LOG'];
            jsonResponse($resp);
        } catch (Throwable $e) {
            $resp = ['success' => false, 'error' => $e->getMessage(), 'url' => $validUrls[0]];
            if ($debug) $resp['debug'] = $GLOBALS['DEBUG_LOG'];
            jsonResponse($resp, 500);
        }
    } else {
        // ── Bulk ─────────────────────────────────────────────────────────────
        $results = [];
        foreach ($validUrls as $i => $url) {
            $GLOBALS['DEBUG_LOG'] = []; // reset per URL
            try {
                $data = getFacebookVideo($url);
                $r = ['url' => $url, 'success' => true, 'data' => $data];
            } catch (Throwable $e) {
                $r = ['url' => $url, 'success' => false, 'error' => $e->getMessage()];
            }
            if ($debug) $r['debug'] = $GLOBALS['DEBUG_LOG'];
            $results[] = $r;
            if ($i < count($validUrls) - 1) usleep(random_int(1500000, 3000000));
        }

        $ok   = count(array_filter($results, fn($r) => $r['success']));
        $fail = count($results) - $ok;
        jsonResponse(['success' => true, 'total' => count($results), 'ok' => $ok, 'failed' => $fail, 'results' => $results]);
    }

} catch (Throwable $e) {
    $resp = ['success' => false, 'error' => $e->getMessage()];
    if (!empty($GLOBALS['DEBUG_LOG'])) $resp['debug'] = $GLOBALS['DEBUG_LOG'];
    jsonResponse($resp, 500);
}
