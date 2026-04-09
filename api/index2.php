<?php
/**
 * Facebook Video Downloader — PHP API Endpoint
 * Ported from Node.js (oEmbed + Meta scraping)
 *
 * Usage:
 *   GET/POST ?url=https://www.facebook.com/reel/123456
 *   POST JSON: { "url": "...", "urls": ["...", "..."] }
 *
 * Response: JSON
 */

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(204);
    exit;
}

// ─── Helpers ─────────────────────────────────────────────────────────────────

function isValidFacebookUrl(string $url): bool {
    return (bool) preg_match('/facebook\.com|fb\.watch/i', $url);
}

function extractFacebookUrls(string $text): array {
    preg_match_all(
        '/(https?:\/\/)?(www\.)?(m\.)?(facebook\.com|fb\.watch)\/\S+/i',
        $text,
        $matches
    );
    return array_values(array_unique($matches[0] ?? []));
}

function normalizeUrl(string $url): string {
    return str_starts_with($url, 'http') ? $url : 'https://' . $url;
}

function formatDuration(int $seconds): string {
    $h   = intdiv($seconds, 3600);
    $m   = intdiv($seconds % 3600, 60);
    $s   = $seconds % 60;
    if ($h > 0) {
        return sprintf('%d:%02d:%02d', $h, $m, $s);
    }
    return sprintf('%d:%02d', $m, $s);
}

function decodeHtmlEntities(?string $str): ?string {
    if ($str === null) return null;
    return html_entity_decode($str, ENT_QUOTES | ENT_HTML5, 'UTF-8');
}

// ─── Build URL Variants ───────────────────────────────────────────────────────

function buildUrlVariants(string $url): array {
    $variants = [];

    $variants[] = $url;
    $normalized = preg_replace('/^https?:\/\/(m\.|www\.)?facebook\.com/', 'https://www.facebook.com', $url);
    $variants[] = $normalized;

    if (preg_match('/\/share\/v\//i', $url)) {
        $base = rtrim($normalized, '/');
        $variants[] = $base . '/?_rdr=p';
        $variants[] = $base . '/?ref=sharing';
        $variants[] = preg_replace('/^https?:\/\/(m\.|www\.)?facebook\.com/', 'https://mbasic.facebook.com', $url);
    }

    if (preg_match('/fb\.watch/i', $url)) {
        $variants[] = preg_replace('/^https?:\/\/fb\.watch/', 'https://www.facebook.com/watch', $url);
    }

    return array_values(array_unique($variants));
}

// ─── HTTP Fetch (cURL) ────────────────────────────────────────────────────────

function httpGet(string $url, array $headers = [], int $timeout = 20): ?array {
    $ch = curl_init();
    curl_setopt_array($ch, [
        CURLOPT_URL            => $url,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_MAXREDIRS      => 10,
        CURLOPT_TIMEOUT        => $timeout,
        CURLOPT_ENCODING       => '', // Accept gzip/deflate/br automatically
        CURLOPT_HTTPHEADER     => $headers,
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_USERAGENT      => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 Chrome/120.0.0.0 Safari/537.36',
    ]);

    $body     = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $finalUrl = curl_getinfo($ch, CURLINFO_EFFECTIVE_URL);
    $error    = curl_error($ch);
    curl_close($ch);

    if ($error || $body === false) return null;

    return [
        'body'      => $body,
        'http_code' => $httpCode,
        'final_url' => $finalUrl,
    ];
}

// ─── Core: oEmbed + Meta Scraping ────────────────────────────────────────────

function getFacebookVideo(string $url): array {
    // ── Step 1: oEmbed ────────────────────────────────────────────────────────
    $oembedData = [];
    $oembedUrl  = 'https://www.facebook.com/plugins/video/oembed.json/?url=' . urlencode($url);

    $oembedRes = httpGet($oembedUrl, [
        'Accept: application/json',
        'User-Agent: Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 Chrome/120.0.0.0 Safari/537.36',
    ], 15);

    if ($oembedRes && $oembedRes['http_code'] === 200) {
        $json = json_decode($oembedRes['body'], true);
        if ($json) {
            $oembedData = [
                'title'     => $json['title']         ?? null,
                'author'    => $json['author_name']   ?? null,
                'thumbnail' => $json['thumbnail_url'] ?? null,
                'html'      => $json['html']          ?? null,
            ];
        }
    }

    // ── Step 2: Scrape video URL from HTML ────────────────────────────────────
    $videoUrl     = null;
    $sdUrl        = null;
    $hdUrl        = null;
    $metaTitle    = null;
    $metaThumb    = null;
    $metaDuration = null;
    $metaViews    = null;
    $metaReactions  = null;
    $metaAuthor     = null;
    $metaDescription = null;

    $uaList = [
        'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36',
        'Mozilla/5.0 (Windows NT 6.1; WOW64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/97.0.4692.71 Safari/537.36',
        'Mozilla/5.0 (Linux; Android 10; SM-G973F) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Mobile Safari/537.36',
        'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/17.0 Safari/605.1.15',
    ];

    $urlVariants = buildUrlVariants($url);

    $found = false;

    foreach ($uaList as $ua) {
        if ($found) break;

        foreach ($urlVariants as $variant) {
            if ($found) break;

            $headers = [
                'User-Agent: '          . $ua,
                'Accept: text/html,application/xhtml+xml,application/xml;q=0.9,image/webp,*/*;q=0.8',
                'Accept-Language: en-US,en;q=0.9',
                'Accept-Encoding: gzip, deflate, br',
                'Connection: keep-alive',
                'Upgrade-Insecure-Requests: 1',
                'Sec-Fetch-Dest: document',
                'Sec-Fetch-Mode: navigate',
                'Sec-Fetch-Site: none',
                'Sec-Fetch-User: ?1',
                'Referer: https://www.facebook.com/',
            ];

            $res = httpGet($variant, $headers, 20);
            if (!$res || $res['http_code'] !== 200) continue;

            $html = $res['body'];

            // Skip error pages
            if (preg_match('/<title>\s*(Error|Something went wrong)/i', $html)) continue;

            // ── Clean & validate a candidate URL ─────────────────────────────
            $clean = function(?string $s) use ($url): ?string {
                if (!$s) return null;
                $s = decodeHtmlEntities($s);
                $s = str_replace('\\/', '/', $s);
                // Decode \uXXXX
                $s = preg_replace_callback('/\\\\u([0-9a-fA-F]{4})/', fn($m) => mb_chr(hexdec($m[1]), 'UTF-8'), $s);
                if (str_contains($s, 'lookaside.fbsbx.com/lookaside/crawler')) return null;
                if (!str_contains($s, 'fbcdn.net') && !str_contains($s, 'facebook.com/video')) return null;
                return $s;
            };

            $extractAll = function(string $pattern) use ($html, $clean): array {
                preg_match_all($pattern, $html, $m);
                return array_values(array_filter(array_map($clean, $m[1] ?? [])));
            };

            $playable   = $extractAll('/"playable_url"\s*:\s*"([^"]+)"/');
            $playableHD = $extractAll('/"playable_url_quality_hd"\s*:\s*"([^"]+)"/');
            $sdSrc      = $extractAll('/sd_src(?:_no_ratelimit)?\s*:\s*"([^"]+)"/');
            $hdSrc      = $extractAll('/hd_src(?:_no_ratelimit)?\s*:\s*"([^"]+)"/');
            $nativeSd   = $extractAll('/"browser_native_sd_url"\s*:\s*"([^"]+)"/');
            $nativeHd   = $extractAll('/"browser_native_hd_url"\s*:\s*"([^"]+)"/');

            $sdUrl = $sdSrc[0] ?? $nativeSd[0] ?? $playable[0] ?? null;
            $hdUrl = $hdSrc[0] ?? $nativeHd[0] ?? $playableHD[0] ?? null;
            $videoUrl = $sdUrl ?? $hdUrl;

            // ── OG Meta ───────────────────────────────────────────────────────
            preg_match('/<meta property="og:title" content="([^"]+)"/i', $html, $tm);
            $metaTitle = decodeHtmlEntities($tm[1] ?? null);

            preg_match('/<meta property="og:image" content="([^"]+)"/i', $html, $im);
            $metaThumb = $im[1] ?? null;

            preg_match('/<meta property="og:description" content="([^"]+)"/i', $html, $dm);
            $metaDescription = decodeHtmlEntities($dm[1] ?? null);

            // Duration
            $durRaw = null;
            if (preg_match('/<meta property="video:duration" content="(\d+)"/i', $html, $dr)) $durRaw = $dr[1];
            elseif (preg_match('/"playable_duration_in_ms"\s*:\s*(\d+)/', $html, $dr)) $durRaw = $dr[1];
            elseif (preg_match('/"duration"\s*:\s*(\d+)/', $html, $dr)) $durRaw = $dr[1];

            if ($durRaw !== null) {
                $metaDuration = strlen($durRaw) > 5 ? (int) floor((int)$durRaw / 1000) : (int)$durRaw;
            }

            // Parse "views | title | author" from og:title
            if ($metaTitle && preg_match('/^(.+?)\s*\|\s*(.+?)(?:\s*\|\s*([^|]+))?$/s', $metaTitle, $parts)) {
                $stats     = $parts[1];
                $metaTitle = trim($parts[2]);
                $metaAuthor = isset($parts[3]) ? trim($parts[3]) : null;

                if (preg_match('/([\d.]+[KMB]?)\s*views?/i', $stats, $v)) {
                    $metaViews = $v[1] . ' views';
                }
                if (preg_match('/([\d.]+[KMB]?)\s*reactions?/i', $stats, $r)) {
                    $metaReactions = $r[1] . ' reactions';
                }
            }

            if ($videoUrl) {
                $found = true;
            }
        }
    }

    if (!$videoUrl) {
        throw new RuntimeException('URL video tidak ditemukan. Video mungkin private atau memerlukan login.');
    }

    return [
        'sd_url'    => $sdUrl,
        'hd_url'    => $hdUrl,
        'video_url' => $videoUrl,
        'source'    => 'oEmbed+Meta',
        'extra'     => [
            'title'       => $oembedData['title']     ?? $metaTitle       ?? 'Facebook Video',
            'author'      => $oembedData['author']    ?? $metaAuthor      ?? null,
            'thumbnail'   => $oembedData['thumbnail'] ?? $metaThumb       ?? null,
            'duration'    => $metaDuration,
            'duration_fmt'=> $metaDuration ? formatDuration($metaDuration) : null,
            'views'       => $metaViews,
            'reactions'   => $metaReactions,
            'description' => $metaDescription,
        ],
    ];
}

// ─── Request Parsing ──────────────────────────────────────────────────────────

function getInputUrls(): array {
    $raw = '';

    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $contentType = $_SERVER['CONTENT_TYPE'] ?? '';

        if (str_contains($contentType, 'application/json')) {
            $body = json_decode(file_get_contents('php://input'), true);
            // Support both single "url" and "urls" array
            if (!empty($body['urls']) && is_array($body['urls'])) {
                return array_values(array_filter(array_map('trim', $body['urls'])));
            }
            $raw = $body['url'] ?? '';
        } else {
            $raw = $_POST['url'] ?? '';
        }
    } else {
        $raw = $_GET['url'] ?? '';
    }

    return extractFacebookUrls($raw);
}

// ─── Main Handler ─────────────────────────────────────────────────────────────

function jsonResponse(array $data, int $code = 200): void {
    http_response_code($code);
    echo json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
    exit;
}

try {
    $urls = getInputUrls();

    if (empty($urls)) {
        jsonResponse([
            'success' => false,
            'error'   => 'URL tidak ditemukan. Kirim parameter ?url= atau body JSON { "url": "..." }',
            'example' => [
                'single' => '?url=https://www.facebook.com/reel/123456',
                'bulk'   => ['POST JSON', '{ "urls": ["https://...", "https://..."] }'],
            ],
        ], 400);
    }

    $validUrls = array_filter($urls, 'isValidFacebookUrl');
    if (empty($validUrls)) {
        jsonResponse(['success' => false, 'error' => 'URL Facebook tidak valid.'], 400);
    }

    $validUrls = array_values(array_map('normalizeUrl', $validUrls));
    $isBulk    = count($validUrls) > 1;

    if (!$isBulk) {
        // ── Single ──────────────────────────────────────────────────────────
        $data = getFacebookVideo($validUrls[0]);
        jsonResponse([
            'success' => true,
            'url'     => $validUrls[0],
            'data'    => $data,
        ]);
    } else {
        // ── Bulk ─────────────────────────────────────────────────────────────
        $results = [];
        foreach ($validUrls as $url) {
            try {
                $data = getFacebookVideo($url);
                $results[] = [
                    'url'     => $url,
                    'success' => true,
                    'data'    => $data,
                ];
            } catch (Throwable $e) {
                $results[] = [
                    'url'     => $url,
                    'success' => false,
                    'error'   => $e->getMessage(),
                ];
            }
            // Polite delay between requests
            if ($url !== end($validUrls)) usleep(random_int(1500000, 3000000));
        }

        $ok   = count(array_filter($results, fn($r) => $r['success']));
        $fail = count($results) - $ok;

        jsonResponse([
            'success' => true,
            'total'   => count($results),
            'ok'      => $ok,
            'failed'  => $fail,
            'results' => $results,
        ]);
    }

} catch (Throwable $e) {
    jsonResponse([
        'success' => false,
        'error'   => $e->getMessage(),
    ], 500);
}
