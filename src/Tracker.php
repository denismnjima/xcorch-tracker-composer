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

    /**
     * Main tracking function - call this to track page views and other events
     */
    public function track(): void
    {
        $this->recordPageView();
        // Add other tracking functions here in the future
    }

    /**
     * Records a page view - checks for session in cookies, creates one if needed, then records the view
     */
    private function recordPageView(): void
    {
        // Get or create session
        $sessionId = $this->getOrCreateSession();

        if ($sessionId === null) {
            return; // Failed to create session, skip tracking
        }

        // Get current page URL
        $currentPage = $this->getCurrentPageUrl();
        
        // Record the view
        $this->recordView($sessionId, $currentPage);
    }

    /**
     * Gets session ID from cookie or creates a new session
     */
    private function getOrCreateSession(): ?int
    {
        $cookieName = 'xcorch_session_id';
        
        // Check if session exists in cookie
        if (isset($_COOKIE[$cookieName])) {
            $sessionId = (int) $_COOKIE[$cookieName];
            // Verify session still exists by trying to use it
            return $sessionId;
        }

        // Create new session
        $sourceUrl = $this->getSourceUrl();
        $deviceType = $this->detectDeviceType();

        $result = $this->createSession($sourceUrl, $deviceType);

        if ($result['success'] && isset($result['data']['session_id'])) {
            $sessionId = $result['data']['session_id'];
            // Set cookie (expires in 30 days)
            setcookie($cookieName, (string) $sessionId, time() + (30 * 24 * 60 * 60), '/');
            return $sessionId;
        }

        return null;
    }

    /**
     * Creates a new session via API
     */
    private function createSession(?string $sourceUrl = null, ?string $deviceType = null): array
    {
        $baseUrl = Variables::BASE_URL;
        $endpoint = $baseUrl . '/api/v1/tracking/session';

        $payload = [
            'api_key' => $this->apiKey,
            'site_code' => $this->websiteCode
        ];

        if ($sourceUrl !== null) {
            $payload['source_url'] = $sourceUrl;
        }

        if ($deviceType !== null) {
            $payload['device_type'] = $deviceType;
        }

        return $this->makeApiRequest($endpoint, $payload);
    }

    /**
     * Records a page view via API
     */
    private function recordView(int $sessionId, string $currentPage, ?string $entry = null, ?string $exit = null, ?int $scrollDepth = null, ?string $endedAt = null): array
    {
        $baseUrl = Variables::BASE_URL;
        $endpoint = $baseUrl . '/api/v1/tracking/view';

        $now = date('c'); // ISO 8601 format

        $payload = [
            'session_id' => $sessionId,
            'entry' => $entry ?? $now,
            'current_page' => $currentPage
        ];

        if ($exit !== null) {
            $payload['exit'] = $exit;
        }

        if ($scrollDepth !== null) {
            $payload['scroll_depth'] = $scrollDepth;
        }

        if ($endedAt !== null) {
            $payload['ended_at'] = $endedAt;
        }

        return $this->makeApiRequest($endpoint, $payload);
    }

    /**
     * Makes an API request and returns the response
     */
    private function makeApiRequest(string $endpoint, array $payload): array
    {
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
            return [
                'success' => false,
                'error' => 'Failed to connect to API: ' . $curlError
            ];
        }

        $responseData = json_decode($response, true);

        if ($httpCode === 200 && isset($responseData['success']) && $responseData['success'] === true) {
            return [
                'success' => true,
                'data' => $responseData['data'] ?? []
            ];
        }

        $errorMessage = 'API request failed';
        if (isset($responseData['message'])) {
            $errorMessage = $responseData['message'];
        } elseif (isset($responseData['error'])) {
            $errorMessage = $responseData['error'];
        }

        return [
            'success' => false,
            'http_code' => $httpCode,
            'error' => $errorMessage,
            'data' => $responseData ?? []
        ];
    }

    /**
     * Extracts domain from a URL (e.g., google.com from google.com/search/...)
     */
    private function extractDomain(string $url): string
    {
        $parsed = parse_url($url);
        if ($parsed === false || !isset($parsed['host'])) {
            return '';
        }

        $host = $parsed['host'];
        // Remove www. prefix if present
        if (strpos($host, 'www.') === 0) {
            $host = substr($host, 4);
        }

        return $host;
    }

    /**
     * Gets the source/referrer URL and extracts just the domain
     */
    private function getSourceUrl(): ?string
    {
        $referrer = $_SERVER['HTTP_REFERER'] ?? null;
        
        if ($referrer === null) {
            return null;
        }

        $domain = $this->extractDomain($referrer);
        if (empty($domain)) {
            return null;
        }

        // Return just the domain (e.g., google.com)
        return $domain;
    }

    /**
     * Gets the current page URL
     */
    private function getCurrentPageUrl(): string
    {
        $protocol = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https://' : 'http://';
        $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
        $uri = $_SERVER['REQUEST_URI'] ?? '/';
        
        return $protocol . $host . $uri;
    }

    /**
     * Detects if the device is mobile or desktop
     */
    private function detectDeviceType(): string
    {
        $userAgent = $_SERVER['HTTP_USER_AGENT'] ?? '';
        
        $mobilePatterns = [
            '/Mobile|Android|iPhone|iPad|iPod|BlackBerry|IEMobile|Opera Mini/i'
        ];

        foreach ($mobilePatterns as $pattern) {
            if (preg_match($pattern, $userAgent)) {
                return 'mobile';
            }
        }

        return 'desktop';
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
