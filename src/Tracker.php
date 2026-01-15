<?php

namespace xcorch\Tracker;

use Dotenv\Dotenv;

class Tracker
{
    private string $apiKey;
    private string $websiteCode;
    private array $excludedPatterns = [];

    /**
     * Gets the base URL for API requests
     */
    private function getBaseUrl(): string
    {
        try {
            return Variables::BASE_URL;
        } catch (\Error $e) {
            // Fallback if Variables class is not found (autoloader needs regeneration)
            return 'http://localhost:8000';
        }
    }

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
     * Returns JavaScript code to track scroll depth and end time
     */
    public function track(): string
    {
        $viewData = $this->recordPageView();
        
        if ($viewData === null) {
            return ''; // Failed to record, return empty string
        }
        
        // Return JavaScript to track scroll depth and end time
        return $this->getTrackingScript($viewData['view_id'], $viewData['session_id']);
    }

    /**
     * Sets patterns for URLs that should be excluded from tracking
     * 
     * @param array $patterns Array of patterns. Can be:
     *   - String patterns (matched with strpos)
     *   - Regex patterns (must start and end with /)
     * 
     * @example
     *   $tracker->setExcludedPatterns(['/products/', '/blogs/', '/admin/']);
     */
    public function setExcludedPatterns(array $patterns): void
    {
        $this->excludedPatterns = $patterns;
    }

    /**
     * Checks if the current page should be excluded from tracking
     */
    private function isPageExcluded(string $url): bool
    {
        if (empty($this->excludedPatterns)) {
            return false;
        }

        foreach ($this->excludedPatterns as $pattern) {
            // Check if it's a regex pattern (starts and ends with /)
            if (preg_match('/^\/.*\/$/', $pattern)) {
                if (preg_match($pattern, $url)) {
                    return true;
                }
            } else {
                // Simple string matching
                if (strpos($url, $pattern) !== false) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * Records a page view - checks for session in cookies, creates one if needed, then records the view
     * Returns view data if successful, null otherwise
     */
    private function recordPageView(): ?array
    {
        // Get current page URL
        $currentPage = $this->getCurrentPageUrl();
        
        // Check if page should be excluded
        if ($this->isPageExcluded($currentPage)) {
            error_log('XCorch Tracker: Page excluded from tracking - ' . $currentPage);
            return null;
        }

        // Get or create session
        $sessionId = $this->getOrCreateSession();

        if ($sessionId === null) {
            error_log('XCorch Tracker: Failed to get or create session');
            return null; // Failed to create session, skip tracking
        }
        
        // Record the view
        $result = $this->recordView($sessionId, $currentPage);
        
        // If session is invalid, create a new session and retry
        if (!$result['success']) {
            $errorMsg = strtolower($result['error'] ?? '');
            $isSessionError = (
                strpos($errorMsg, 'session') !== false || 
                strpos($errorMsg, 'invalid') !== false ||
                ($result['http_code'] ?? 0) === 404 ||
                (isset($result['data']['errors']['session_id']) && 
                 strpos(strtolower($result['data']['errors']['session_id'][0] ?? ''), 'invalid') !== false)
            );
            
            if ($isSessionError) {
                error_log('XCorch Tracker: Session invalid, creating new session and retrying');
                
                // Clear the invalid session cookie
                $cookieName = 'xcorch_session_id';
                setcookie($cookieName, '', time() - 3600, '/');
                unset($_COOKIE[$cookieName]);
                
                // Create a new session
                $sourceUrl = $this->getSourceUrl();
                $deviceType = $this->detectDeviceType();
                $newSessionResult = $this->createSession($sourceUrl, $deviceType);
                
                if ($newSessionResult['success'] && isset($newSessionResult['data']['session_id'])) {
                    $newSessionId = $newSessionResult['data']['session_id'];
                    // Set new cookie
                    setcookie($cookieName, (string) $newSessionId, time() + (30 * 24 * 60 * 60), '/');
                    error_log('XCorch Tracker: New session created after invalid session - ID: ' . $newSessionId);
                    
                    // Retry recording the view with the new session
                    $result = $this->recordView($newSessionId, $currentPage);
                    $sessionId = $newSessionId;
                }
            }
        }
        
        if (!$result['success']) {
            error_log('XCorch Tracker: Failed to record view - ' . ($result['error'] ?? 'Unknown error') . ' (HTTP: ' . ($result['http_code'] ?? 'N/A') . ')');
            return null;
        }
        
        // Return view data for JavaScript tracking
        // Support both 'id' (new) and 'view_id' (backward compatibility)
        $viewId = $result['data']['id'] ?? $result['data']['view_id'] ?? null;
        
        if ($viewId === null) {
            error_log('XCorch Tracker: View ID not found in response. Response data: ' . json_encode($result['data'] ?? []));
        } else {
            error_log('XCorch Tracker: View ID extracted successfully: ' . $viewId);
        }
        
        return [
            'view_id' => $viewId,
            'session_id' => $sessionId
        ];
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
            error_log('XCorch Tracker: Using existing session from cookie - ID: ' . $sessionId);
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
            error_log('XCorch Tracker: Session created successfully - ID: ' . $sessionId);
            return $sessionId;
        }

        error_log('XCorch Tracker: Failed to create session - ' . json_encode($result));
        return null;
    }

    /**
     * Creates a new session via API
     */
    private function createSession(?string $sourceUrl = null, ?string $deviceType = null): array
    {
        $baseUrl = $this->getBaseUrl();
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
        $baseUrl = $this->getBaseUrl();
        $endpoint = $baseUrl . '/api/v1/tracking/view';

        $now = date('c'); // ISO 8601 format

        $payload = [
            'api_key' => $this->apiKey,
            'site_code' => $this->websiteCode,
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

        $result = $this->makeApiRequest($endpoint, $payload);
        
        // Log for debugging
        if (!$result['success']) {
            error_log('XCorch Tracker: View recording failed. Payload: ' . json_encode($payload) . ' Response: ' . json_encode($result));
        } else {
            error_log('XCorch Tracker: View recorded successfully for session ' . $sessionId);
        }
        
        return $result;
    }

    /**
     * Updates a view with scroll depth and/or end time using the view ID
     * 
     * @param int $viewId The view ID to update
     * @param int|null $scrollDepth Scroll depth (0-100), null to skip
     * @param string|null $endedAt End time in ISO 8601 format, null to skip
     */
    public function updateView(int $viewId, ?int $scrollDepth = null, ?string $endedAt = null): array
    {
        $baseUrl = $this->getBaseUrl();
        $endpoint = $baseUrl . '/api/v1/tracking/view/' . $viewId;

        $payload = [];

        if ($scrollDepth !== null) {
            $payload['scroll_depth'] = $scrollDepth;
        }

        if ($endedAt !== null) {
            $payload['ended_at'] = $endedAt;
        }

        // Use PUT method for updates
        return $this->makeApiRequest($endpoint, $payload, 'PUT');
    }

    /**
     * Gets the JavaScript tracking script for scroll depth and end time
     */
    private function getTrackingScript(?int $viewId, int $sessionId): string
    {
        if ($viewId === null) {
            return ''; // No view ID, can't track
        }

        $baseUrl = $this->getBaseUrl();
        $updateEndpoint = htmlspecialchars($baseUrl . '/api/v1/tracking/view/' . $viewId, ENT_QUOTES, 'UTF-8');
        
        return <<<SCRIPT
<script>
(function() {
    var viewId = {$viewId};
    var maxScroll = 0;
    var lastUpdateScroll = 0;
    var startTime = Date.now();
    var updateSent = false;
    var updateEndpoint = '{$updateEndpoint}';
    
    // Track scroll depth and send incremental updates
    function trackScroll() {
        var scrollTop = window.pageYOffset || document.documentElement.scrollTop;
        var documentHeight = document.documentElement.scrollHeight;
        var windowHeight = window.innerHeight;
        var scrollPercent = Math.round(((scrollTop + windowHeight) / documentHeight) * 100);
        
        if (scrollPercent > maxScroll) {
            maxScroll = scrollPercent;
            
            // Send incremental update if scroll depth increased significantly (every 25%)
            if (maxScroll - lastUpdateScroll >= 25) {
                sendScrollUpdate(maxScroll);
                lastUpdateScroll = maxScroll;
            }
        }
    }
    
    // Send incremental scroll depth update
    function sendScrollUpdate(scrollDepth) {
        var payload = {
            scroll_depth: scrollDepth
        };
        
        fetch(updateEndpoint, {
            method: 'PUT',
            headers: {'Content-Type': 'application/json'},
            body: JSON.stringify(payload),
            keepalive: true
        }).catch(function(err) {
            console.error('XCorch Tracker: Failed to send scroll update', err);
        });
    }
    
    // Send final update with scroll depth and end time
    function sendFinalUpdate() {
        if (updateSent) return;
        updateSent = true;
        
        var endTime = new Date().toISOString();
        var payload = {
            scroll_depth: maxScroll,
            ended_at: endTime
        };
        
        // Use fetch with keepalive for reliable delivery on page unload
        // Note: sendBeacon doesn't support PUT method, so we use fetch
        fetch(updateEndpoint, {
            method: 'PUT',
            headers: {'Content-Type': 'application/json'},
            body: JSON.stringify(payload),
            keepalive: true
        }).catch(function(err) {
            console.error('XCorch Tracker: Failed to send final update', err);
        });
    }
    
    // Track scroll events
    window.addEventListener('scroll', trackScroll, {passive: true});
    
    // Track when user leaves page
    window.addEventListener('beforeunload', sendFinalUpdate);
    window.addEventListener('pagehide', sendFinalUpdate);
    
    // Also send update when page becomes hidden
    document.addEventListener('visibilitychange', function() {
        if (document.hidden) {
            sendFinalUpdate();
        }
    });
    
    // Track initial scroll position
    trackScroll();
})();
</script>
SCRIPT;
    }

    /**
     * Makes an API request and returns the response
     */
    private function makeApiRequest(string $endpoint, array $payload, string $method = 'POST'): array
    {
        $ch = curl_init($endpoint);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        
        if ($method === 'PUT' || $method === 'PATCH') {
            curl_setopt($ch, CURLOPT_CUSTOMREQUEST, $method);
        } else {
            curl_setopt($ch, CURLOPT_POST, true);
        }
        
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
        $baseUrl = $this->getBaseUrl();
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
