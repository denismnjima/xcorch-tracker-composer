<?php

namespace xcorch\Tracker;

use Dotenv\Dotenv;

class Tracker
{
    private string $apiKey;
    private string $websiteCode;

    public function __construct()
    {
        // Try to load .env file from the project root (where composer.json is)
        $envPath = $this->findEnvFile();
        if ($envPath !== null) {
            $dotenv = Dotenv::createImmutable(dirname($envPath));
            $dotenv->load();
        }

        $apiKey = $_ENV['XCORCH_API'] ?? getenv('XCORCH_API') ?: null;
        $websiteCode = $_ENV['XCORCH_WEBSITE_CODE'] ?? getenv('XCORCH_WEBSITE_CODE') ?: null;

        // Validate required environment variables
        if (empty($apiKey)) {
            throw new \InvalidArgumentException('XCORCH_API environment variable is required but not found.');
        }

        if (empty($websiteCode)) {
            throw new \InvalidArgumentException('XCORCH_WEBSITE_CODE environment variable is required but not found.');
        }

        $this->apiKey = (string) $apiKey;
        $this->websiteCode = (string) $websiteCode;
    }

    private function findEnvFile(): ?string
    {
        // Start from current directory and go up to find .env
        $dir = getcwd();
        $maxDepth = 10;
        $depth = 0;

        while ($depth < $maxDepth) {
            $envFile = $dir . DIRECTORY_SEPARATOR . '.env';
            if (file_exists($envFile)) {
                return $envFile;
            }
            $parent = dirname($dir);
            if ($parent === $dir) {
                break; // Reached root
            }
            $dir = $parent;
            $depth++;
        }

        return null;
    }

    public function log(): void
    {
        echo "hello";
    }

    public function getApiKey(): string
    {
        return $this->apiKey;
    }

    public function getWebsiteCode(): string
    {
        return $this->websiteCode;
    }

    /**
     * Validates the API key and website code by calling the authentication endpoint
     * 
     * @return array Returns an array with 'valid' (bool) and 'data' (array) keys
     * @throws \RuntimeException If the API request fails
     */
    public function validateCredentials(): array
    {
        $baseUrl = Variables::BASE_URL;
        $endpoint = $baseUrl . '/api/v1/auth/validate';

        $payload = [
            'api_key' => $this->apiKey,
            'site_code' => $this->websiteCode
        ];

        $ch = curl_init($endpoint);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload));
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            'Content-Type: application/json',
            'Accept: application/json'
        ]);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlError = curl_error($ch);
        curl_close($ch);

        if ($curlError) {
            throw new \RuntimeException('Failed to connect to validation endpoint: ' . $curlError);
        }

        $responseData = json_decode($response, true);

        if ($httpCode === 200 && isset($responseData['success']) && $responseData['success'] === true) {
            return [
                'valid' => true,
                'data' => $responseData['data'] ?? []
            ];
        }

        // Handle error responses
        $errorMessage = 'Validation failed';
        if (isset($responseData['message'])) {
            $errorMessage = $responseData['message'];
        } elseif (isset($responseData['error'])) {
            $errorMessage = $responseData['error'];
        }

        return [
            'valid' => false,
            'http_code' => $httpCode,
            'error' => $errorMessage,
            'data' => $responseData ?? []
        ];
    }
}
