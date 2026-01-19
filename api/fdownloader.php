<?php
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST');
header('Access-Control-Allow-Headers: Content-Type');

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
            CURLOPT_FOLLOWLOCATION => true
        ]);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($httpCode !== 200 || !$response) {
            throw new Exception('Failed to fetch data from fdownloader');
        }

        $data = json_decode($response, true);
        
        if (!isset($data['data'])) {
            throw new Exception('Invalid response format');
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

        return [
            'status' => 'success',
            'duration' => $duration,
            'thumbnail' => $thumbnail,
            'videos' => $videos
        ];

    } catch (Exception $e) {
        return [
            'status' => 'error',
            'message' => $e->getMessage()
        ];
    }
}

// Handle request
if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $url = isset($_GET['url']) ? $_GET['url'] : null;
} elseif ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $input = json_decode(file_get_contents('php://input'), true);
    $url = isset($input['url']) ? $input['url'] : null;
} else {
    echo json_encode([
        'status' => 'error',
        'message' => 'Method not allowed'
    ]);
    exit;
}

if (!$url) {
    echo json_encode([
        'status' => 'error',
        'message' => 'URL parameter is required'
    ]);
    exit;
}

if (!filter_var($url, FILTER_VALIDATE_URL) || strpos($url, 'facebook.com') === false) {
    echo json_encode([
        'status' => 'error',
        'message' => 'Invalid Facebook URL'
    ]);
    exit;
}

$result = fbdl($url);
echo json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
?>
