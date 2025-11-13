<?php

/**
 * PHP Example: Using Mdukuzi AI API
 * 
 * This example demonstrates how to use the Mdukuzi AI API with PHP.
 * Make sure to set your HF_TOKEN in the .env file.
 */

// Load environment variables (using vlucas/phpdotenv)
require __DIR__ . '/vendor/autoload.php';

use Dotenv\Dotenv;

// Load .env file
$dotenv = Dotenv::createImmutable(__DIR__);
$dotenv->load();

// Get API token from environment
$apiToken = $_ENV['HF_TOKEN'] ?? getenv('HF_TOKEN');

if (!$apiToken) {
    die('Error: HF_TOKEN not found in .env file');
}

// API endpoint
$apiUrl = 'https://router.huggingface.co/v1/chat/completions';

// Request data
$data = [
    'model' => 'DeepHat/DeepHat-V1-7B:featherless-ai',
    'messages' => [
        [
            'role' => 'user',
            'content' => 'What is the capital of France?',
        ],
    ],
    'stream' => false,
];

// Initialize cURL
$ch = curl_init($apiUrl);

// Set cURL options
curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_POST => true,
    CURLOPT_POSTFIELDS => json_encode($data),
    CURLOPT_HTTPHEADER => [
        'Authorization: Bearer ' . $apiToken,
        'Content-Type: application/json',
    ],
]);

// Execute request
$response = curl_exec($ch);

// Check for errors
if (curl_errno($ch)) {
    echo 'Error: ' . curl_error($ch) . PHP_EOL;
    curl_close($ch);
    exit(1);
}

// Get HTTP status code
$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);

// Close cURL
curl_close($ch);

// Handle response
if ($httpCode === 200) {
    $responseData = json_decode($response, true);
    
    if (isset($responseData['choices'][0]['message']['content'])) {
        echo 'Response: ' . $responseData['choices'][0]['message']['content'] . PHP_EOL;
    } else {
        echo 'Error: Unexpected response format' . PHP_EOL;
        print_r($responseData);
    }
} else {
    echo 'Error: HTTP ' . $httpCode . PHP_EOL;
    echo 'Response: ' . $response . PHP_EOL;
}

/**
 * Alternative using Guzzle HTTP Client (if installed)
 * 
 * Uncomment the following code if you have guzzlehttp/guzzle installed:
 */

/*
use GuzzleHttp\Client;

$client = new Client([
    'base_uri' => 'https://router.huggingface.co/v1',
    'headers' => [
        'Authorization' => 'Bearer ' . $apiToken,
        'Content-Type' => 'application/json',
    ],
]);

try {
    $response = $client->post('/chat/completions', [
        'json' => [
            'model' => 'DeepHat/DeepHat-V1-7B:featherless-ai',
            'messages' => [
                [
                    'role' => 'user',
                    'content' => 'What is the capital of France?',
                ],
            ],
            'stream' => false,
        ],
    ]);

    $data = json_decode($response->getBody(), true);
    
    if (isset($data['choices'][0]['message']['content'])) {
        echo 'Response: ' . $data['choices'][0]['message']['content'] . PHP_EOL;
    }
} catch (\Exception $e) {
    echo 'Error: ' . $e->getMessage() . PHP_EOL;
}
*/

