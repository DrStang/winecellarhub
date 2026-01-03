<?php
/**
 * /api/v2/analyze_label.php — AI wine label analysis with catalog matching
 *
 * POST /api/v2/analyze_label.php
 * Content-Type: application/json
 * Body: { "image_base64": "data:image/jpeg;base64,..." }
 */

declare(strict_types=1);

$root = dirname(__DIR__, 2);
require_once $root . '/db.php';
require_once dirname(__DIR__) . '/api_auth_middleware.php';

if (file_exists($root . '/search_lib.php')) {
    require_once $root . '/search_lib.php';
}

add_cors_headers();
header('Content-Type: application/json; charset=utf-8');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    api_error(405, 'method_not_allowed', 'Only POST is allowed');
}

$userId = get_authenticated_user_id();

/**
 * Save base64 image - handles all formats including HEIC
 */
function save_label_image_base64(string $dataUrl): array {
    // Log what we received (first 200 chars)
    error_log('[analyze_label] Received data URL prefix: ' . substr($dataUrl, 0, 200));

    // Very permissive regex - catch any data URL
    if (preg_match('#^data:([^;,]+)?(?:;base64)?,(.*)$#is', $dataUrl, $matches)) {
        $fullMime = trim($matches[1] ?? 'image/jpeg');
        $base64Data = $matches[2];

        error_log('[analyze_label] Parsed mime: ' . $fullMime);
    } else {
        // Maybe it's just raw base64 without data URL prefix?
        if (preg_match('#^[A-Za-z0-9+/=]+$#', substr($dataUrl, 0, 100))) {
            error_log('[analyze_label] Appears to be raw base64 without data URL prefix');
            $fullMime = 'image/jpeg';
            $base64Data = $dataUrl;
        } else {
            error_log('[analyze_label] Could not parse data URL. First 500 chars: ' . substr($dataUrl, 0, 500));
            throw new RuntimeException('Invalid image format. Could not parse data URL.');
        }
    }

    // Extract just the type from mime (e.g., "image/heic" -> "heic")
    $mimeType = $fullMime;
    if (strpos($fullMime, '/') !== false) {
        $parts = explode('/', $fullMime);
        $mimeType = strtolower(end($parts));
    }
    $mimeType = strtolower(trim($mimeType));

    // Normalize common variations
    $mimeType = str_replace(['x-heic', 'x-heif'], ['heic', 'heif'], $mimeType);

    error_log('[analyze_label] Normalized mime type: ' . $mimeType);

    // Decode base64
    $imageData = base64_decode($base64Data, true);
    if ($imageData === false || strlen($imageData) < 100) {
        error_log('[analyze_label] Base64 decode failed or data too small. Length: ' . strlen($base64Data));
        throw new RuntimeException('Failed to decode base64 image data');
    }

    error_log('[analyze_label] Decoded image size: ' . strlen($imageData) . ' bytes');

    // Try to detect actual format from magic bytes
    $detectedType = detect_image_type($imageData);
    error_log('[analyze_label] Detected type from magic bytes: ' . ($detectedType ?? 'unknown'));

    // Use detected type if we got one
    if ($detectedType) {
        $mimeType = $detectedType;
    }

    // Create covers directory
    $coversDir = dirname(__DIR__, 2) . '/covers';
    if (!is_dir($coversDir)) {
        @mkdir($coversDir, 0775, true);
    }

    // Handle HEIC/HEIF conversion
    $isHeic = in_array($mimeType, ['heic', 'heif']);
    $finalMimeType = $mimeType;
    $finalBase64 = $base64Data;
    $finalImageData = $imageData;

    if ($isHeic) {
        error_log('[analyze_label] Attempting HEIC conversion...');
        $converted = convert_heic_to_jpeg($imageData);
        if ($converted) {
            $finalImageData = $converted;
            $finalBase64 = base64_encode($converted);
            $finalMimeType = 'jpeg';
            error_log('[analyze_label] HEIC conversion successful, new size: ' . strlen($converted));
        } else {
            error_log('[analyze_label] HEIC conversion failed, will try raw');
        }
    }

    // Determine extension
    $extMap = ['jpeg' => 'jpg', 'jpg' => 'jpg', 'png' => 'png', 'gif' => 'gif', 'webp' => 'webp', 'heic' => 'heic', 'heif' => 'heif'];
    $ext = $extMap[$finalMimeType] ?? 'jpg';

    // Generate filename and save
    $basename = 'label_' . date('Ymd_His') . '_' . bin2hex(random_bytes(4)) . '.' . $ext;
    $fullPath = $coversDir . '/' . $basename;

    if (file_put_contents($fullPath, $finalImageData) === false) {
        throw new RuntimeException('Failed to save image file');
    }

    error_log('[analyze_label] Saved to: ' . $fullPath);

    return [
        'path' => '/covers/' . $basename,
        'full_path' => $fullPath,
        'mime_type' => 'image/' . $finalMimeType,
        'base64' => $finalBase64,
        'original_mime' => $mimeType,
    ];
}

/**
 * Detect image type from magic bytes
 */
function detect_image_type(string $data): ?string {
    $hex = bin2hex(substr($data, 0, 12));

    // JPEG: FF D8 FF
    if (strpos($hex, 'ffd8ff') === 0) {
        return 'jpeg';
    }

    // PNG: 89 50 4E 47
    if (strpos($hex, '89504e47') === 0) {
        return 'png';
    }

    // GIF: 47 49 46 38
    if (strpos($hex, '47494638') === 0) {
        return 'gif';
    }

    // WebP: 52 49 46 46 ... 57 45 42 50
    if (strpos($hex, '52494646') === 0 && strpos(bin2hex(substr($data, 8, 4)), '57454250') === 0) {
        return 'webp';
    }

    // HEIC/HEIF: Look for 'ftyp' box with heic/mif1/heif brand
    if (strpos($data, 'ftyp') !== false) {
        $ftypPos = strpos($data, 'ftyp');
        $brand = substr($data, $ftypPos + 4, 8);
        if (strpos($brand, 'heic') !== false || strpos($brand, 'mif1') !== false || strpos($brand, 'heif') !== false) {
            return 'heic';
        }
    }

    // Also check for HEIC at specific offset (usually bytes 4-8)
    $bytes4to8 = substr($data, 4, 8);
    if (strpos($bytes4to8, 'ftyp') !== false || strpos($bytes4to8, 'heic') !== false || strpos($bytes4to8, 'mif1') !== false) {
        return 'heic';
    }

    return null;
}

/**
 * Convert HEIC to JPEG using various methods
 */
function convert_heic_to_jpeg(string $heicData): ?string {
    $tempHeic = tempnam(sys_get_temp_dir(), 'heic_') . '.heic';
    $tempJpeg = tempnam(sys_get_temp_dir(), 'jpeg_') . '.jpg';

    file_put_contents($tempHeic, $heicData);

    // Try ImageMagick convert
    $cmd = "convert " . escapeshellarg($tempHeic) . " " . escapeshellarg($tempJpeg) . " 2>&1";
    exec($cmd, $output, $returnCode);
    error_log('[analyze_label] ImageMagick convert result: ' . $returnCode . ' - ' . implode("\n", $output));

    if ($returnCode === 0 && file_exists($tempJpeg) && filesize($tempJpeg) > 0) {
        $jpegData = file_get_contents($tempJpeg);
        @unlink($tempHeic);
        @unlink($tempJpeg);
        return $jpegData;
    }

    // Try heif-convert if available
    $tempJpeg2 = tempnam(sys_get_temp_dir(), 'jpeg2_') . '.jpg';
    $cmd2 = "heif-convert " . escapeshellarg($tempHeic) . " " . escapeshellarg($tempJpeg2) . " 2>&1";
    exec($cmd2, $output2, $returnCode2);
    error_log('[analyze_label] heif-convert result: ' . $returnCode2 . ' - ' . implode("\n", $output2));

    if ($returnCode2 === 0 && file_exists($tempJpeg2) && filesize($tempJpeg2) > 0) {
        $jpegData = file_get_contents($tempJpeg2);
        @unlink($tempHeic);
        @unlink($tempJpeg2);
        return $jpegData;
    }

    // Cleanup
    @unlink($tempHeic);
    @unlink($tempJpeg);
    @unlink($tempJpeg2);

    return null;
}

/**
 * Call OpenAI Vision API
 */
function analyze_with_openai(string $base64Data, string $mimeType = 'image/jpeg'): array {
    $apiKey = getenv('OPENAI_API_KEY') ?: ($_ENV['OPENAI_API_KEY'] ?? '');
    if (!$apiKey) {
        throw new RuntimeException('OpenAI API key not configured');
    }

    $prompt = <<<PROMPT
You are a wine label parser. Analyze this wine bottle label image and extract information.
Return a single JSON object with these keys (use empty string if unknown):
- name: The wine name/cuvée (not the winery)
- winery: The producer/winery name  
- vintage: 4-digit year or empty string for NV
- grapes: Grape varieties (comma separated)
- region: Wine region/appellation
- country: Country of origin
- type: One of: Red, White, Rosé, Sparkling, Orange, Dessert, Fortified (or empty if unsure)
- style: Wine style description if visible
- barcode: UPC/barcode if visible

Output JSON only, no markdown, no explanation.
PROMPT;

    $imagePayload = [
        'type' => 'image_url',
        'image_url' => [
            'url' => "data:{$mimeType};base64,{$base64Data}",
        ],
    ];

    $payload = [
        'model' => 'gpt-4o-mini',
        'messages' => [
            [
                'role' => 'user',
                'content' => [
                    ['type' => 'text', 'text' => $prompt],
                    $imagePayload,
                ],
            ],
        ],
        'max_tokens' => 600,
        'temperature' => 0.1,
    ];

    error_log('[analyze_label] Sending to OpenAI with mime: ' . $mimeType . ', base64 length: ' . strlen($base64Data));

    $ch = curl_init('https://api.openai.com/v1/chat/completions');
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER => [
            'Authorization: Bearer ' . $apiKey,
            'Content-Type: application/json',
        ],
        CURLOPT_POSTFIELDS => json_encode($payload),
        CURLOPT_TIMEOUT => 60,
    ]);

    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlError = curl_error($ch);
    curl_close($ch);

    error_log('[analyze_label] OpenAI response code: ' . $httpCode);

    if ($response === false) {
        throw new RuntimeException("cURL error: $curlError");
    }

    if ($httpCode >= 400) {
        error_log('[analyze_label] OpenAI error response: ' . substr($response, 0, 1000));
        $errorData = json_decode($response, true);
        $errorMsg = $errorData['error']['message'] ?? $response;
        throw new RuntimeException("OpenAI API error (HTTP $httpCode): $errorMsg");
    }

    $data = json_decode($response, true);
    if (!$data) {
        throw new RuntimeException('Failed to parse OpenAI response');
    }

    $content = $data['choices'][0]['message']['content'] ?? '';

    // Extract JSON
    $content = trim($content);
    if (strpos($content, '```') !== false) {
        $content = preg_replace('/```json\s*/i', '', $content);
        $content = preg_replace('/```\s*/', '', $content);
        $content = trim($content);
    }

    $jsonStart = strpos($content, '{');
    $jsonEnd = strrpos($content, '}');
    if ($jsonStart === false || $jsonEnd === false || $jsonEnd <= $jsonStart) {
        throw new RuntimeException('Could not find JSON in AI response');
    }

    $jsonStr = substr($content, $jsonStart, $jsonEnd - $jsonStart + 1);
    $parsed = json_decode($jsonStr, true);

    if (!is_array($parsed)) {
        throw new RuntimeException('Invalid JSON in AI response');
    }

    return $parsed;
}

/**
 * Find catalog matches
 */
function find_catalog_matches(PDO $pdo, array $aiData, int $limit = 5): array {
    $name = trim((string)($aiData['name'] ?? ''));
    $winery = trim((string)($aiData['winery'] ?? ''));
    $vintage = trim((string)($aiData['vintage'] ?? ''));

    if ($name === '' && $winery === '') {
        return [];
    }

    $where = [];
    $params = [];

    if ($name !== '') {
        $where[] = "name LIKE :name";
        $params[':name'] = '%' . $name . '%';
    }
    if ($winery !== '') {
        $where[] = "winery LIKE :winery";
        $params[':winery'] = '%' . $winery . '%';
    }

    if (empty($where)) {
        return [];
    }

    $sql = "SELECT id, wine_id, name, winery, region, country, grapes, vintage, type, style, image_url, rating, price FROM wines WHERE " . implode(' AND ', $where);

    if ($vintage !== '' && preg_match('/^\d{4}$/', $vintage)) {
        $sql .= " ORDER BY CASE WHEN vintage = :vintage THEN 0 ELSE 1 END, rating DESC";
        $params[':vintage'] = $vintage;
    } else {
        $sql .= " ORDER BY rating DESC";
    }

    $sql .= " LIMIT :lim";

    $results = [];
    try {
        $stmt = $pdo->prepare($sql);
        foreach ($params as $k => $v) {
            $stmt->bindValue($k, $v, PDO::PARAM_STR);
        }
        $stmt->bindValue(':lim', $limit, PDO::PARAM_INT);
        $stmt->execute();

        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $results[] = [
                'id' => (int)$row['id'],
                'wine_id' => $row['wine_id'] ? (int)$row['wine_id'] : null,
                'name' => $row['name'] ?? '',
                'winery' => $row['winery'] ?? '',
                'region' => $row['region'] ?? '',
                'country' => $row['country'] ?? '',
                'grapes' => $row['grapes'] ?? '',
                'vintage' => $row['vintage'] ? (int)$row['vintage'] : null,
                'type' => $row['type'] ?? '',
                'style' => $row['style'] ?? '',
                'image_url' => $row['image_url'] ?? '',
                'rating' => $row['rating'] !== null ? (float)$row['rating'] : null,
                'price' => $row['price'] !== null ? (float)$row['price'] : null,
            ];
        }
    } catch (PDOException $e) {
        error_log("Catalog search error: " . $e->getMessage());
    }

    return $results;
}

// Main handler
try {
    $rawInput = file_get_contents('php://input');
    error_log('[analyze_label] Raw input length: ' . strlen($rawInput));

    $input = json_decode($rawInput, true);

    if (!$input) {
        error_log('[analyze_label] JSON decode failed. Raw start: ' . substr($rawInput, 0, 500));
        api_error(400, 'invalid_json', 'Could not parse JSON body');
    }

    if (empty($input['image_base64'])) {
        api_error(400, 'no_image', 'Please provide image_base64 field');
    }

    $imageBase64 = $input['image_base64'];

    // Save and process image
    $imageInfo = save_label_image_base64($imageBase64);

    // Analyze with AI
    $aiParsed = analyze_with_openai($imageInfo['base64'], $imageInfo['mime_type']);

    // Normalize type
    $typeMap = [
        'red' => 'Red', 'white' => 'White', 'rose' => 'Rosé', 'rosé' => 'Rosé',
        'sparkling' => 'Sparkling', 'orange' => 'Orange', 'dessert' => 'Dessert', 'fortified' => 'Fortified',
    ];
    $rawType = strtolower(trim($aiParsed['type'] ?? ''));
    $aiParsed['type'] = $typeMap[$rawType] ?? ($rawType ? ucfirst($rawType) : '');

    // Find catalog matches
    $catalogMatches = [];
    if (isset($winelist_pdo) && $winelist_pdo instanceof PDO) {
        $catalogMatches = find_catalog_matches($winelist_pdo, $aiParsed);
    }

    api_success([
        'ai_parsed' => [
            'name' => $aiParsed['name'] ?? '',
            'winery' => $aiParsed['winery'] ?? '',
            'vintage' => $aiParsed['vintage'] ?? '',
            'grapes' => $aiParsed['grapes'] ?? '',
            'region' => $aiParsed['region'] ?? '',
            'country' => $aiParsed['country'] ?? '',
            'type' => $aiParsed['type'] ?? '',
            'style' => $aiParsed['style'] ?? '',
            'barcode' => $aiParsed['barcode'] ?? '',
        ],
        'image_path' => $imageInfo['path'],
        'catalog_matches' => $catalogMatches,
    ]);

} catch (Throwable $e) {
    error_log('[analyze_label] FATAL: ' . $e->getMessage() . "\n" . $e->getTraceAsString());
    api_error(500, 'analysis_failed', $e->getMessage());
}