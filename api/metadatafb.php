<?php
/**
 * Facebook Video/Reels Info API
 * Free scraping method - No API Key Required
 */

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST');
header('Access-Control-Allow-Headers: Content-Type');

// Fungsi untuk extract video ID
function extractVideoId($url) {
    $patterns = [
        '/facebook\.com\/.*\/videos\/(\d+)/',
        '/facebook\.com\/video\.php\?v=(\d+)/',
        '/fb\.watch\/([a-zA-Z0-9_-]+)/',
        '/facebook\.com\/watch\/\?v=(\d+)/',
        '/facebook\.com\/reel\/(\d+)/',
        '/facebook\.com\/reels\/(\d+)/',
        '/facebook\.com\/share\/r\/([a-zA-Z0-9]+)/',
        '/facebook\.com\/share\/v\/([a-zA-Z0-9]+)/',
    ];
    
    foreach ($patterns as $pattern) {
        if (preg_match($pattern, $url, $matches)) {
            return $matches[1];
        }
    }
    return null;
}

// Fungsi untuk fetch HTML dengan berbagai metode
function fetchPage($url) {
    // Method 1: Gunakan mobile version (lebih ringan)
    $mobileUrl = str_replace(
        ['www.facebook.com', 'facebook.com'],
        'm.facebook.com',
        $url
    );
    
    $headers = [
        'User-Agent: Mozilla/5.0 (Linux; Android 10; SM-G973F) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Mobile Safari/537.36',
        'Accept: text/html,application/xhtml+xml,application/xml;q=0.9,*/*;q=0.8',
        'Accept-Language: en-US,en;q=0.9',
        'Accept-Encoding: gzip, deflate',
        'Connection: keep-alive',
        'Upgrade-Insecure-Requests: 1',
        'Sec-Fetch-Dest: document',
        'Sec-Fetch-Mode: navigate',
        'Sec-Fetch-Site: none',
    ];
    
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $mobileUrl);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
    curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);
    curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
    curl_setopt($ch, CURLOPT_ENCODING, '');
    curl_setopt($ch, CURLOPT_TIMEOUT, 30);
    curl_setopt($ch, CURLOPT_MAXREDIRS, 5);
    
    $html = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    
    // Method 2: Jika mobile gagal, coba desktop
    if ($httpCode !== 200 || !$html) {
        $headers[0] = 'User-Agent: Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36';
        
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
        curl_setopt($ch, CURLOPT_ENCODING, '');
        curl_setopt($ch, CURLOPT_TIMEOUT, 30);
        
        $html = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
    }
    
    return [
        'html' => $html,
        'http_code' => $httpCode
    ];
}

// Fungsi untuk parse title yang kompleks
function parseTitle($rawTitle) {
    if (!$rawTitle) return null;
    
    // Normalize newlines and extra spaces
    $rawTitle = preg_replace('/\s+/', ' ', $rawTitle);
    $rawTitle = trim($rawTitle);
    
    $result = [
        'title' => null,
        'views' => null,
        'reactions' => null,
        'author' => null,
        'views_count' => 0,
        'reactions_count' => 0,
    ];
    
    // Pattern: "63K views · 3.3K reactions | Title | Author"
    // atau: "2M views · 39K reactions | Title | Author"
    if (preg_match('/^(.+?)\s*\|\s*(.+?)(?:\s*\|\s*([^|]+))?$/s', $rawTitle, $matches)) {
        $statsSection = trim($matches[1]);
        $result['title'] = trim($matches[2]);
        $result['author'] = isset($matches[3]) ? trim($matches[3]) : null;
        
        // Extract views (support K, M, B format)
        if (preg_match('/([\d.]+[KMB]?)\s*views?/i', $statsSection, $viewMatches)) {
            $result['views'] = $viewMatches[1] . ' views';
            $result['views_count'] = parseCount($viewMatches[1]);
        }
        
        // Extract reactions
        if (preg_match('/([\d.]+[KMB]?)\s*reactions?/i', $statsSection, $reactionMatches)) {
            $result['reactions'] = $reactionMatches[1] . ' reactions';
            $result['reactions_count'] = parseCount($reactionMatches[1]);
        }
    } else {
        // Jika format tidak sesuai, gunakan raw title
        $result['title'] = $rawTitle;
    }
    
    return $result;
}

// Fungsi untuk convert K, M, B ke angka
function parseCount($str) {
    if (!$str) return 0;
    
    $str = strtoupper(trim($str));
    $multiplier = 1;
    
    if (strpos($str, 'K') !== false) {
        $multiplier = 1000;
        $str = str_replace('K', '', $str);
    } elseif (strpos($str, 'M') !== false) {
        $multiplier = 1000000;
        $str = str_replace('M', '', $str);
    } elseif (strpos($str, 'B') !== false) {
        $multiplier = 1000000000;
        $str = str_replace('B', '', $str);
    }
    
    return (int)(floatval($str) * $multiplier);
}

// Fungsi untuk extract metadata dari HTML
function extractMetadata($html) {
    $metadata = [
        'title' => null,
        'title_parsed' => null,
        'description' => null,
        'thumbnail' => null,
        'duration' => null,
        'author' => null,
        'upload_date' => null,
    ];
    
    // Extract title
    if (preg_match('/<meta property="og:title" content="([^"]+)"/', $html, $matches)) {
        $rawTitle = html_entity_decode($matches[1], ENT_QUOTES, 'UTF-8');
        $metadata['title'] = $rawTitle;
        $metadata['title_parsed'] = parseTitle($rawTitle);
    }
    
    // Extract description
    if (preg_match('/<meta property="og:description" content="([^"]+)"/', $html, $matches)) {
        $metadata['description'] = html_entity_decode($matches[1], ENT_QUOTES, 'UTF-8');
    } else if (preg_match('/<meta name="description" content="([^"]+)"/', $html, $matches)) {
        $metadata['description'] = html_entity_decode($matches[1], ENT_QUOTES, 'UTF-8');
    }
    
    // Extract thumbnail
    if (preg_match('/<meta property="og:image" content="([^"]+)"/', $html, $matches)) {
        $metadata['thumbnail'] = $matches[1];
    }
    
    // Extract duration
    if (preg_match('/<meta property="video:duration" content="(\d+)"/', $html, $matches)) {
        $metadata['duration'] = (int)$matches[1];
    } else if (preg_match('/"playable_duration_in_ms":(\d+)/', $html, $matches)) {
        $metadata['duration'] = (int)($matches[1] / 1000);
    } else if (preg_match('/"duration":(\d+)/', $html, $matches)) {
        $metadata['duration'] = (int)$matches[1];
    } else if (preg_match('/"lengthInSecond":(\d+)/', $html, $matches)) {
        $metadata['duration'] = (int)$matches[1];
    }
    
    // Extract author/uploader - multiple attempts
    if (preg_match('/<meta property="article:author" content="([^"]+)"/', $html, $matches)) {
        $metadata['author'] = html_entity_decode($matches[1], ENT_QUOTES, 'UTF-8');
    } else if (preg_match('/"ownerName":"([^"]+)"/', $html, $matches)) {
        $metadata['author'] = html_entity_decode($matches[1], ENT_QUOTES, 'UTF-8');
    } else if (preg_match('/"owner":\{"name":"([^"]+)"/', $html, $matches)) {
        $metadata['author'] = html_entity_decode($matches[1], ENT_QUOTES, 'UTF-8');
    } else if (preg_match('/<meta property="og:site_name" content="([^"]+)"/', $html, $matches)) {
        $metadata['author'] = html_entity_decode($matches[1], ENT_QUOTES, 'UTF-8');
    } else if (preg_match('/"page_name":"([^"]+)"/', $html, $matches)) {
        $metadata['author'] = html_entity_decode($matches[1], ENT_QUOTES, 'UTF-8');
    }
    
    // Extract upload date
    if (preg_match('/<meta property="article:published_time" content="([^"]+)"/', $html, $matches)) {
        $metadata['upload_date'] = $matches[1];
    } else if (preg_match('/"publish_time":(\d+)/', $html, $matches)) {
        $metadata['upload_date'] = date('Y-m-d\TH:i:s\Z', $matches[1]);
    } else if (preg_match('/"created_time":"([^"]+)"/', $html, $matches)) {
        $metadata['upload_date'] = $matches[1];
    } else if (preg_match('/"creation_story":\{[^}]*"created_time":(\d+)/', $html, $matches)) {
        $metadata['upload_date'] = date('Y-m-d\TH:i:s\Z', $matches[1]);
    }
    
    // Extract author dari OG title jika belum dapat
    if (!$metadata['author'] && preg_match('/<meta property="og:title" content="[^"]*\|\s*([^"]+)"/', $html, $matches)) {
        $parts = explode('|', $matches[0]);
        if (count($parts) > 1) {
            $lastPart = trim(str_replace(['<meta property="og:title" content="', '"'], '', end($parts)));
            if ($lastPart && strlen($lastPart) < 100) {
                $metadata['author'] = html_entity_decode($lastPart, ENT_QUOTES, 'UTF-8');
            }
        }
    }
    
    // Extract dari JSON-LD jika ada
    if (preg_match('/<script type="application\/ld\+json">(.+?)<\/script>/s', $html, $matches)) {
        $jsonData = json_decode($matches[1], true);
        if ($jsonData) {
            $metadata['title'] = $metadata['title'] ?? ($jsonData['name'] ?? null);
            $metadata['description'] = $metadata['description'] ?? ($jsonData['description'] ?? null);
            $metadata['thumbnail'] = $metadata['thumbnail'] ?? ($jsonData['thumbnailUrl'] ?? null);
            $metadata['upload_date'] = $jsonData['uploadDate'] ?? null;
            $metadata['author'] = $metadata['author'] ?? ($jsonData['author']['name'] ?? null);
        }
    }
    
    return $metadata;
}

// Fungsi untuk extract video URLs
function extractVideoUrls($html) {
    $videoUrls = [];
    
    // Pattern untuk SD dan HD video
    $patterns = [
        '/sd_src:"([^"]+)"/',
        '/hd_src:"([^"]+)"/',
        '/sd_src_no_ratelimit:"([^"]+)"/',
        '/hd_src_no_ratelimit:"([^"]+)"/',
        '/"playable_url":"([^"]+)"/',
        '/"playable_url_quality_hd":"([^"]+)"/',
        '/"browser_native_sd_url":"([^"]+)"/',
        '/"browser_native_hd_url":"([^"]+)"/',
        '/src:"(https:\/\/[^"]*video[^"]+)"/',
        '/"playableUrl":"([^"]+)"/',
        '/"downloadUrl":"([^"]+)"/',
    ];
    
    foreach ($patterns as $idx => $pattern) {
        if (preg_match_all($pattern, $html, $matches)) {
            foreach ($matches[1] as $url) {
                // Decode URL
                $url = html_entity_decode($url, ENT_QUOTES, 'UTF-8');
                $url = stripslashes($url);
                $url = str_replace('\/', '/', $url);
                
                // Decode unicode sequences
                $url = preg_replace_callback('/\\\\u([0-9a-fA-F]{4})/', function($m) {
                    return mb_convert_encoding(pack('H*', $m[1]), 'UTF-8', 'UCS-2BE');
                }, $url);
                
                // Validasi URL
                if (filter_var($url, FILTER_VALIDATE_URL) && strpos($url, 'video') !== false) {
                    // Tentukan kualitas
                    $quality = 'sd';
                    if (strpos($pattern, 'hd') !== false || strpos($url, '_hd_') !== false) {
                        $quality = 'hd';
                    }
                    
                    if (!isset($videoUrls[$quality])) {
                        $videoUrls[$quality] = $url;
                    }
                }
            }
        }
    }
    
    return $videoUrls;
}

// Main function
function getFacebookVideoInfo($url) {
    $videoId = extractVideoId($url);
    
    if (!$videoId) {
        return [
            'success' => false,
            'error' => 'Invalid Facebook video URL or unable to extract video ID'
        ];
    }
    
    // Fetch halaman
    $result = fetchPage($url);
    
    if ($result['http_code'] !== 200 || !$result['html']) {
        return [
            'success' => false,
            'error' => 'Failed to fetch video page',
            'http_code' => $result['http_code']
        ];
    }
    
    $html = $result['html'];
    
    // Extract metadata
    $metadata = extractMetadata($html);
    
    // Extract video URLs
    $videoUrls = extractVideoUrls($html);
    
    // Compile hasil
    $titleData = $metadata['title_parsed'];
    
    return [
        'success' => true,
        'video_id' => $videoId,
        'url' => $url,
        'title' => $titleData['title'] ?? $metadata['title'],
        'views' => $titleData['views'],
        'views_count' => $titleData['views_count'],
        'reactions' => $titleData['reactions'],
        'reactions_count' => $titleData['reactions_count'],
        'author' => $titleData['author'] ?? $metadata['author'],
        'description' => $metadata['description'],
        'thumbnail' => $metadata['thumbnail'],
        'duration' => $metadata['duration'],
        'duration_formatted' => $metadata['duration'] ? formatDuration($metadata['duration']) : null,
        'upload_date' => $metadata['upload_date'],
        'video_urls' => $videoUrls,
        'download_url' => $videoUrls['hd'] ?? $videoUrls['sd'] ?? null,
    ];
}

// Fungsi untuk format durasi
function formatDuration($seconds) {
    if (!$seconds) return null;
    
    $hours = floor($seconds / 3600);
    $minutes = floor(($seconds % 3600) / 60);
    $secs = $seconds % 60;
    
    if ($hours > 0) {
        return sprintf('%d:%02d:%02d', $hours, $minutes, $secs);
    } else {
        return sprintf('%d:%02d', $minutes, $secs);
    }
}

// API Handler
try {
    $method = $_SERVER['REQUEST_METHOD'];
    
    if ($method === 'GET') {
        if (!isset($_GET['url'])) {
            echo json_encode([
                'success' => false,
                'error' => 'Missing required parameter: url',
                'usage' => [
                    'example' => '?url=https://www.facebook.com/reel/123456789',
                    'supported' => [
                        'Facebook Videos',
                        'Facebook Reels',
                        'Facebook Watch',
                        'fb.watch links'
                    ]
                ]
            ], JSON_PRETTY_PRINT);
            exit;
        }
        
        $videoUrl = $_GET['url'];
        
    } elseif ($method === 'POST') {
        $input = json_decode(file_get_contents('php://input'), true);
        
        if (!isset($input['url'])) {
            echo json_encode([
                'success' => false,
                'error' => 'Missing required parameter: url'
            ]);
            exit;
        }
        
        $videoUrl = $input['url'];
        
    } else {
        echo json_encode([
            'success' => false,
            'error' => 'Invalid request method. Use GET or POST'
        ]);
        exit;
    }
    
    // Validasi URL
    if (!filter_var($videoUrl, FILTER_VALIDATE_URL)) {
        echo json_encode([
            'success' => false,
            'error' => 'Invalid URL format'
        ]);
        exit;
    }
    
    // Cek apakah URL Facebook
    if (!preg_match('/facebook\.com|fb\.watch/i', $videoUrl)) {
        echo json_encode([
            'success' => false,
            'error' => 'URL must be from Facebook'
        ]);
        exit;
    }
    
    // Process
    $result = getFacebookVideoInfo($videoUrl);
    echo json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    
} catch (Exception $e) {
    echo json_encode([
        'success' => false,
        'error' => 'Server error: ' . $e->getMessage()
    ]);
}
