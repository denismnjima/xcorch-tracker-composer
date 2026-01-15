<?php

require __DIR__ . '/vendor/autoload.php';

use xcorch\Tracker\Tracker;

echo "=== XCorch Tracker Test ===\n\n";

try {
    $tracker = new Tracker();
    
    echo "✓ Tracker initialized successfully\n";
    echo "API Key: " . substr($tracker->getApiKey(), 0, 10) . "...\n";
    echo "Website Code: " . $tracker->getWebsiteCode() . "\n\n";
    
    // Test credential validation
    echo "Testing credential validation...\n";
    $result = $tracker->validateCredentials();
    
    if ($result['valid']) {
        echo "✓ Credentials are valid\n";
        echo "Site: " . ($result['data']['site']['site_name'] ?? 'N/A') . "\n";
        echo "Business: " . ($result['data']['business']['business_name'] ?? 'N/A') . "\n\n";
    } else {
        echo "✗ Credential validation failed\n";
        echo "Error: " . ($result['error'] ?? 'Unknown error') . "\n";
        echo "HTTP Code: " . ($result['http_code'] ?? 'N/A') . "\n\n";
    }
    
    // Test tracking
    echo "Testing page view tracking...\n";
    $jsCode = $tracker->track();
    
    if (empty($jsCode)) {
        echo "✗ Tracking failed - no JavaScript code returned\n";
        echo "This could mean:\n";
        echo "  - Page is excluded\n";
        echo "  - Session creation failed\n";
        echo "  - View recording failed\n";
        echo "  - View ID not returned from API\n";
    } else {
        echo "✓ Tracking JavaScript generated\n";
        echo "JavaScript length: " . strlen($jsCode) . " bytes\n";
        
        // Check if view ID is in the JavaScript
        if (strpos($jsCode, 'var viewId =') !== false) {
            echo "✓ View ID found in JavaScript\n";
            // Extract view ID
            if (preg_match('/var viewId = (\d+);/', $jsCode, $matches)) {
                echo "View ID: " . $matches[1] . "\n";
            }
        } else {
            echo "✗ View ID not found in JavaScript\n";
        }
        
        // Check if endpoint is correct
        if (strpos($jsCode, '/api/v1/tracking/view/') !== false) {
            echo "✓ Update endpoint found in JavaScript\n";
        } else {
            echo "✗ Update endpoint not found in JavaScript\n";
        }
    }
    
    echo "\n=== Test Complete ===\n";
    
} catch (\Exception $e) {
    echo "✗ Error: " . $e->getMessage() . "\n";
    echo "File: " . $e->getFile() . ":" . $e->getLine() . "\n";
}
