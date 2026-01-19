<?php
/**
 * Facebook Video/Photo Downloader API - All-in-One
 * Menggabungkan streaming dan download dalam satu response
 * v3.0 - Unified Single File
 */

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST');
header('Access-Control-Allow-Headers: Content-Type');

class FacebookDownloader {
    private $userAgent = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36';
    private $externalApiUrl = 'https://api.deline.web.id/downloader/aio';
    private $originalUrl = '';
    
    public function parse($url) {
        $this->originalUrl = $url;
        
        if (!$this->isValidFacebookUrl($url)) {
            return $this->error('URL Facebook tidak valid');
        }
        
        // Coba external API dulu untuk share links
        if ($this->isShareUrl($url)) {
            $externalResult = $this->fetchFromExternalApi($url);
            
            if ($externalResult && $externalResult['status'] === 'success' && 
                isset($externalResult['data']['download']) && 
                $externalResult['data']['download'] !== '-' && 
                !empty($externalResult['data']['download'])) {
                return $externalResult;
            }
        }
        
        // Handle share links - get final URL after redirect
        $resolvedUrl = $url;
        if ($this->isShareUrl($url)) {
            $finalUrl = $this->resolveShareUrl($url);
            
            if ($finalUrl) {
                $resolvedUrl = $finalUrl;
            }
        }
        
        // Fetch content
        $html = $this->fetchContent($resolvedUrl);
        
        if (!$html) {
            $externalResult = $this->fetchFromExternalApi($this->originalUrl);
            
            if ($externalResult && $externalResult['status'] === 'success') {
                return $externalResult;
            }
            
            return $this->error('Gagal mengambil konten');
        }
        
        $type = $this->detectContentTypeFromHtml($html, $resolvedUrl);
        
        if ($type === 'video') {
            $result = $this->parseVideoFromHtml($html);
        } elseif ($type === 'photo') {
            $result = $this->parsePhotoFromHtml($html);
        } else {
            $result = $this->parseGeneralFromHtml($html);
        }
        
        // Jika parsing internal gagal atau tidak menemukan download URL, coba external API
        if ($result['status'] === 'error' || 
            (isset($result['data']['download']) && ($result['data']['download'] === '-' || empty($result['data']['download'])))) {
            
            $externalResult = $this->fetchFromExternalApi($this->originalUrl);
            
            if ($externalResult && $externalResult['status'] === 'success' && 
                isset($externalResult['data']['download']) && 
                $externalResult['data']['download'] !== '-' && 
                !empty($externalResult['data']['download'])) {
                $result = $externalResult;
            }
        }
        
        return $result;
    }
    
    private function fetchFromExternalApi($url) {
        $apiUrl = $this->externalApiUrl . '?url=' . urlencode($url);
        
        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL => $apiUrl,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_USERAGENT => $this->userAgent,
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_TIMEOUT => 30,
            CURLOPT_HTTPHEADER => [
                'Accept: application/json',
                'Content-Type: application/json'
            ]
        ]);
        
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        
        if ($httpCode !== 200 || !$response) {
            return null;
        }
        
        $data = json_decode($response, true);
        
        if (!$data || !isset($data['status']) || $data['status'] !== true) {
            return null;
        }
        
        return $this->transformExternalApiResponse($data);
    }
    
    private function transformExternalApiResponse($apiData) {
        $result = $apiData['result'] ?? [];
        
        $downloadUrl = '-';
        $qualities = [];
        
        if (isset($result['medias']) && is_array($result['medias'])) {
            foreach ($result['medias'] as $media) {
                if (isset($media['quality']) && isset($media['url']) && isset($media['type'])) {
                    if ($media['type'] === 'video') {
                        $qualities[$media['quality']] = $media['url'];
                    }
                }
            }
            
            if (isset($qualities['HD'])) {
                $downloadUrl = $qualities['HD'];
            } elseif (isset($qualities['SD'])) {
                $downloadUrl = $qualities['SD'];
            } elseif (!empty($qualities)) {
                $downloadUrl = reset($qualities);
            }
        }
        
        $duration = '-';
        if (isset($result['duration']) && is_numeric($result['duration'])) {
            $durationInSeconds = (int)($result['duration'] / 1000);
            $duration = $this->formatDuration($durationInSeconds);
        }
        
        $transformedData = [
            'judul' => $result['title'] ?? 'Facebook Content',
            'thumbnail' => $result['thumbnail'] ?? '',
            'download' => $downloadUrl,
            'durasi' => $duration
        ];
        
        if (count($qualities) > 1) {
            $transformedData['qualities'] = $qualities;
        }
        
        return $this->success($transformedData);
    }
    
    private function isValidFacebookUrl($url) {
        return preg_match('/(?:https?:\/\/)?(?:www\.|m\.|web\.)?facebook\.com/i', $url);
    }
    
    private function isShareUrl($url) {
        return preg_match('/facebook\.com\/share\/[rv]\//i', $url);
    }
    
    private function resolveShareUrl($url) {
        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL => $url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS => 10,
            CURLOPT_USERAGENT => $this->userAgent,
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_TIMEOUT => 30,
            CURLOPT_HEADER => true,
            CURLOPT_NOBODY => false,
            CURLOPT_ENCODING => 'gzip, deflate',
            CURLOPT_HTTPHEADER => [
                'Accept: text/html,application/xhtml+xml,application/xml',
                'Accept-Language: en-US,en;q=0.9',
                'Cache-Control: no-cache'
            ]
        ]);
        
        $response = curl_exec($ch);
        $finalUrl = curl_getinfo($ch, CURLINFO_EFFECTIVE_URL);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $headerSize = curl_getinfo($ch, CURLINFO_HEADER_SIZE);
        
        curl_close($ch);
        
        if ($httpCode !== 200) {
            return false;
        }
        
        if ($finalUrl && $finalUrl !== $url) {
            return $finalUrl;
        }
        
        if ($response) {
            $body = substr($response, $headerSize);
            
            if (preg_match('/<link[^>]+rel=["\']canonical["\'][^>]+href=["\'](https:\/\/[^"\']+)["\']/i', $body, $match)) {
                return $match[1];
            }
            
            if (preg_match('/<meta[^>]+property=["\']og:url["\'][^>]+content=["\'](https:\/\/[^"\']+)["\']/i', $body, $match)) {
                return $match[1];
            }
        }
        
        return false;
    }
    
    private function detectContentTypeFromHtml($html, $url) {
        if (preg_match('/\/videos?\//i', $url) || preg_match('/\/watch\?/i', $url) || preg_match('/\/reel\//i', $url)) {
            return 'video';
        } elseif (preg_match('/\/photos?\//i', $url)) {
            return 'photo';
        }
        
        if (preg_match('/hd_src:"([^"]+)"/i', $html) || 
            preg_match('/sd_src:"([^"]+)"/i', $html) ||
            preg_match('/"playable_url":"([^"]+)"/i', $html) ||
            preg_match('/"browser_native_hd_url":"([^"]+)"/i', $html)) {
            return 'video';
        }
        
        if (preg_match('/<meta property="og:type" content="video/i', $html)) {
            return 'video';
        }
        
        if (preg_match('/<meta property="og:type" content="article/i', $html) &&
            preg_match('/"image":{"uri":"([^"]+)"/i', $html)) {
            return 'photo';
        }
        
        return 'general';
    }
    
    private function parseVideoFromHtml($html) {
        $qualities = [];
        
        if (preg_match('/hd_src:"([^"]+)"/i', $html, $match)) {
            $qualities['hd'] = $this->cleanUrl($match[1]);
        }
        
        if (preg_match('/sd_src:"([^"]+)"/i', $html, $match)) {
            $qualities['sd'] = $this->cleanUrl($match[1]);
        }
        
        if (empty($qualities)) {
            if (preg_match('/"playable_url":"([^"]+)"/i', $html, $match)) {
                $qualities['default'] = $this->cleanUrl($match[1]);
            }
            
            if (preg_match('/"browser_native_hd_url":"([^"]+)"/i', $html, $match)) {
                $qualities['hd'] = $this->cleanUrl($match[1]);
            }
            
            if (preg_match('/"browser_native_sd_url":"([^"]+)"/i', $html, $match)) {
                $qualities['sd'] = $this->cleanUrl($match[1]);
            }
            
            if (preg_match('/"playable_url_quality_hd":"([^"]+)"/i', $html, $match)) {
                $qualities['hd'] = $this->cleanUrl($match[1]);
            }
            
            if (preg_match_all('/"url":"(https:[^"]+\.mp4[^"]*)"/i', $html, $matches)) {
                foreach ($matches[1] as $videoUrl) {
                    $cleaned = $this->cleanUrl($videoUrl);
                    if (!isset($qualities['default'])) {
                        $qualities['default'] = $cleaned;
                    }
                }
            }
        }
        
        $title = $this->extractTitle($html);
        $thumbnail = $this->extractThumbnail($html);
        $duration = $this->extractDuration($html);
        
        if (empty($qualities)) {
            return $this->error('Video tidak ditemukan atau private');
        }
        
        $downloadUrl = $qualities['sd'] ?? $qualities['hd'] ?? $qualities['default'] ?? '';
        
        return $this->success([
            'judul' => $title,
            'thumbnail' => $thumbnail,
            'download' => $downloadUrl,
            'durasi' => $duration
        ]);
    }
    
    private function parsePhotoFromHtml($html) {
        $photoUrls = [];
        
        if (preg_match('/"image":{"uri":"([^"]+)"/i', $html, $match)) {
            $photoUrls[] = $this->cleanUrl($match[1]);
        }
        
        if (preg_match_all('/"url":"(https:[^"]+\.(?:jpg|jpeg|png))"/i', $html, $matches)) {
            foreach ($matches[1] as $photoUrl) {
                $cleaned = $this->cleanUrl($photoUrl);
                if (!in_array($cleaned, $photoUrls)) {
                    $photoUrls[] = $cleaned;
                }
            }
        }
        
        if (empty($photoUrls)) {
            $thumbnail = $this->extractThumbnail($html);
            if ($thumbnail) {
                $photoUrls[] = $thumbnail;
            }
        }
        
        if (empty($photoUrls)) {
            return $this->error('Foto tidak ditemukan atau private');
        }
        
        $title = $this->extractTitle($html);
        
        return $this->success([
            'judul' => $title,
            'thumbnail' => $photoUrls[0] ?? '',
            'download' => $photoUrls[0] ?? '',
            'durasi' => '-'
        ]);
    }
    
    private function parseGeneralFromHtml($html) {
        $title = $this->extractTitle($html);
        $image = $this->extractThumbnail($html);
        $downloadUrl = $image;
        
        return $this->success([
            'judul' => $title,
            'thumbnail' => $image,
            'download' => $downloadUrl ?: '-',
            'durasi' => '-'
        ]);
    }
    
    private function fetchContent($url) {
        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL => $url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_USERAGENT => $this->userAgent,
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_TIMEOUT => 30,
            CURLOPT_ENCODING => 'gzip, deflate',
            CURLOPT_HTTPHEADER => [
                'Accept: text/html,application/xhtml+xml,application/xml',
                'Accept-Language: en-US,en;q=0.9',
                'Cache-Control: no-cache'
            ]
        ]);
        
        $result = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        
        if ($httpCode !== 200) {
            return false;
        }
        
        return $result;
    }
    
    private function extractTitle($html) {
        if (preg_match('/<title>([^<]+)<\/title>/i', $html, $match)) {
            return html_entity_decode(trim($match[1]), ENT_QUOTES, 'UTF-8');
        }
        return 'Facebook Content';
    }
    
    private function extractThumbnail($html) {
        if (preg_match('/<meta property="og:image" content="([^"]+)"/i', $html, $match)) {
            return html_entity_decode($match[1], ENT_QUOTES | ENT_HTML5, 'UTF-8');
        }
        return '';
    }
    
    private function extractDuration($html) {
        if (preg_match('/<meta property="video:duration" content="([^"]+)"/i', $html, $match)) {
            return $this->formatDuration((int)$match[1]);
        }
        
        if (preg_match('/"playable_duration_in_ms":(\d+)/i', $html, $match)) {
            return $this->formatDuration((int)$match[1] / 1000);
        }
        
        if (preg_match('/"duration_in_sec":(\d+)/i', $html, $match)) {
            return $this->formatDuration((int)$match[1]);
        }
        
        return '-';
    }
    
    private function formatDuration($seconds) {
        $seconds = (int)$seconds;
        
        if ($seconds <= 0) {
            return '-';
        }
        
        $hours = floor($seconds / 3600);
        $minutes = floor(($seconds % 3600) / 60);
        $secs = $seconds % 60;
        
        if ($hours > 0) {
            return sprintf('%02d:%02d:%02d', $hours, $minutes, $secs);
        } else {
            return sprintf('%02d:%02d', $minutes, $secs);
        }
    }
    
    private function cleanUrl($url) {
        $url = str_replace('\/', '/', $url);
        $url = str_replace('\\u0025', '%', $url);
        $url = str_replace('\u0025', '%', $url);
        return html_entity_decode($url, ENT_QUOTES, 'UTF-8');
    }
    
    private function success($data) {
        return [
            'status' => 'success',
            'data' => $data
        ];
    }
    
    private function error($message) {
        return [
            'status' => 'error',
            'message' => $message
        ];
    }
}

// FDownloader Integration
function fbdl($url) {
    try {
        $postData = http_build_query([
            'q' => $url,
            'lang' => 'en',
            'web' => 'fdownloader.net',
            'v' => 'v2',
            'w' => ''
        ]);
        
        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL => 'https://v3.fdownloader.net/api/ajaxSearch',
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $postData,
            CURLOPT_HTTPHEADER => [
                'Content-Type: application/x-www-form-urlencoded; charset=UTF-8',
                'Origin: https://fdownloader.net',
                'Referer: https://fdownloader.net/',
                'User-Agent: Mozilla/5.0 (Linux; Android 10)'
            ],
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_TIMEOUT => 30
        ]);
        
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        
        if ($httpCode !== 200 || !$response) {
            return null;
        }
        
        $data = json_decode($response, true);
        
        if (!isset($data['data'])) {
            return null;
        }
        
        $html = $data['data'];
        $dom = new DOMDocument();
        @$dom->loadHTML($html);
        $xpath = new DOMXPath($dom);
        
        // Extract duration
        $durationNode = $xpath->query("//div[@class='content']//p")->item(0);
        $duration = $durationNode ? trim($durationNode->textContent) : null;
        
        // Extract thumbnail
        $thumbnailNode = $xpath->query("//div[@class='thumbnail']//img/@src")->item(0);
        $thumbnail = $thumbnailNode ? $thumbnailNode->nodeValue : null;
        
        // Extract video links
        $videos = [];
        $videoLinks = $xpath->query("//a[contains(@class, 'download-link-fb')]");
        
        foreach ($videoLinks as $link) {
            $title = $link->getAttribute('title');
            $quality = str_replace('Download ', '', $title);
            $videoUrl = $link->getAttribute('href');
            
            if ($videoUrl) {
                $videos[] = [
                    'quality' => $quality,
                    'url' => $videoUrl
                ];
            }
        }
        
        if (empty($videos)) {
            return null;
        }
        
        return [
            'status' => 'success',
            'duration' => $duration,
            'thumbnail' => $thumbnail,
            'videos' => $videos
        ];
        
    } catch (Exception $e) {
        return null;
    }
}

// Main Handler
if ($_SERVER['REQUEST_METHOD'] === 'GET' || $_SERVER['REQUEST_METHOD'] === 'POST') {
    $url = $_GET['url'] ?? $_POST['url'] ?? '';
    
    if (empty($url)) {
        echo json_encode([
            'status' => 'error',
            'message' => 'Parameter URL diperlukan',
            'usage' => [
                'endpoint' => 'GET/POST ?url=FACEBOOK_URL',
                'example' => '?url=https://www.facebook.com/reel/1855893615023668',
                'features' => [
                    'Streaming URLs (facebookapi)',
                    'Download Links (fdownloader)',
                    'Multiple quality options',
                    'Auto fallback mechanism'
                ]
            ]
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
        exit;
    }
    
    // Validate Facebook URL
    if (!preg_match('/(?:https?:\/\/)?(?:www\.|m\.|web\.)?facebook\.com/i', $url)) {
        echo json_encode([
            'status' => 'error',
            'message' => 'URL Facebook tidak valid'
        ]);
        exit;
    }
    
    // Try fdownloader first for download links
    $fdownloaderResult = fbdl($url);
    
    // Try facebookapi for streaming
    $downloader = new FacebookDownloader();
    $facebookapiResult = $downloader->parse($url);
    
    // Merge results into one comprehensive response
    $finalResult = [
        'status' => 'success',
        'judul' => 'Facebook Content',
        'thumbnail' => '',
        'download' => '-',
        'durasi' => '-',
        'videos' => []
    ];
    
    // Use facebookapi data as base (for streaming)
    if ($facebookapiResult['status'] === 'success') {
        $finalResult['judul'] = $facebookapiResult['data']['judul'];
        $finalResult['thumbnail'] = $facebookapiResult['data']['thumbnail'];
        $finalResult['download'] = $facebookapiResult['data']['download'];
        $finalResult['durasi'] = $facebookapiResult['data']['durasi'];
    }
    
    // Add fdownloader videos if available
    if ($fdownloaderResult && $fdownloaderResult['status'] === 'success') {
        if (!empty($fdownloaderResult['thumbnail'])) {
            $finalResult['thumbnail'] = $fdownloaderResult['thumbnail'];
        }
        if (!empty($fdownloaderResult['duration'])) {
            $finalResult['durasi'] = $fdownloaderResult['duration'];
        }
        if (!empty($fdownloaderResult['videos'])) {
            $finalResult['videos'] = $fdownloaderResult['videos'];
        }
    }
    
    // If both failed, return error
    if ($facebookapiResult['status'] === 'error' && (!$fdownloaderResult || $fdownloaderResult['status'] === 'error')) {
        echo json_encode([
            'status' => 'error',
            'message' => 'Gagal mengambil data dari kedua sumber'
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
        exit;
    }
    
    echo json_encode($finalResult, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
} else {
    http_response_code(405);
    echo json_encode([
        'status' => 'error',
        'message' => 'Method not allowed'
    ]);
}
?>
