<?php
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST');
header('Access-Control-Allow-Headers: Content-Type');

function downloadFacebook($url) {
    $postData = http_build_query([
        'id' => $url,
        'locale' => 'id'
    ]);
    
    $ch = curl_init();
    curl_setopt_array($ch, [
        CURLOPT_URL => 'https://getmyfb.com/process',
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => $postData,
        CURLOPT_HTTPHEADER => [
            'HX-Request: true',
            'HX-Trigger: form',
            'HX-Target: target',
            'HX-Current-URL: https://getmyfb.com/id',
            'Content-Type: application/x-www-form-urlencoded; charset=UTF-8',
            'User-Agent: Mozilla/5.0 (Linux; Android 10; K) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/128.0.0.0 Mobile Safari/537.36',
            'Referer: https://getmyfb.com/id',
            'Accept: */*',
            'Accept-Language: id-ID,id;q=0.9,en-US;q=0.8,en;q=0.7',
            'Accept-Encoding: gzip, deflate, br',
            'Origin: https://getmyfb.com'
        ],
        CURLOPT_ENCODING => '',
        CURLOPT_SSL_VERIFYPEER => false,
        CURLOPT_FOLLOWLOCATION => true
    ]);
    
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    
    if (curl_errno($ch)) {
        curl_close($ch);
        return [
            'status' => 'error',
            'message' => 'Failed to connect: ' . curl_error($ch)
        ];
    }
    
    curl_close($ch);
    
    if ($httpCode !== 200) {
        return [
            'status' => 'error',
            'message' => 'Server returned error code: ' . $httpCode
        ];
    }
    
    return parseResponse($response);
}

function parseResponse($html) {
    $dom = new DOMDocument();
    @$dom->loadHTML($html);
    $xpath = new DOMXPath($dom);
    
    // Get caption/title
    $captionNodes = $xpath->query("//div[contains(@class, 'results-item-text')]");
    $caption = '';
    if ($captionNodes->length > 0) {
        $caption = trim($captionNodes->item(0)->textContent);
    }
    
    // Get image/thumbnail
    $imageNodes = $xpath->query("//img[contains(@class, 'results-item-image')]");
    $imageUrl = '';
    if ($imageNodes->length > 0) {
        $imageUrl = $imageNodes->item(0)->getAttribute('src');
    }
    
    // Get download links
    $media = [];
    $listItems = $xpath->query("//ul[contains(@class, 'results-list')]//li");
    
    foreach ($listItems as $item) {
        $linkNodes = $xpath->query(".//a", $item);
        $textNodes = $xpath->query(".//div[contains(@class, 'results-item-text')]", $item);
        
        if ($linkNodes->length > 0) {
            $downloadLink = $linkNodes->item(0)->getAttribute('href');
            $quality = '';
            
            if ($textNodes->length > 0) {
                $text = trim($textNodes->item(0)->textContent);
                $quality = trim(explode('(', $text)[0]);
            }
            
            if (!empty($downloadLink) && filter_var($downloadLink, FILTER_VALIDATE_URL)) {
                $media[] = [
                    'quality' => $quality,
                    'url' => $downloadLink
                ];
            }
        }
    }
    
    if (empty($media) && empty($caption)) {
        return [
            'status' => 'error',
            'message' => 'No download links found. The URL may be invalid or the video is private.'
        ];
    }
    
    return [
        'status' => 'success',
        'metadata' => [
            'title' => $caption,
            'image' => $imageUrl
        ],
        'media' => $media
    ];
}

// Handle API requests
$method = $_SERVER['REQUEST_METHOD'];

if ($method === 'GET' || $method === 'POST') {
    $url = $_GET['url'] ?? $_POST['url'] ?? '';
    
    if (empty($url)) {
        echo json_encode([
            'status' => 'error',
            'message' => 'URL parameter is required',
            'usage' => 'facebook.php?url=FACEBOOK_VIDEO_URL',
            'example' => 'facebook.php?url=https://www.facebook.com/watch?v=123456789'
        ], JSON_PRETTY_PRINT);
        exit;
    }
    
    // Validate Facebook URL
    if (!preg_match('/(facebook\.com|fb\.watch)/i', $url)) {
        echo json_encode([
            'status' => 'error',
            'message' => 'Invalid Facebook URL. Please provide a valid Facebook video URL.'
        ], JSON_PRETTY_PRINT);
        exit;
    }
    
    $result = downloadFacebook($url);
    echo json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
    
} else {
    echo json_encode([
        'status' => 'error',
        'message' => 'Method not allowed. Use GET or POST.'
    ], JSON_PRETTY_PRINT);
}
?>
