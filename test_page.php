<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>XCorch Tracker Test</title>
    <style>
        body {
            font-family: Arial, sans-serif;
            max-width: 800px;
            margin: 50px auto;
            padding: 20px;
            line-height: 1.6;
        }
        .test-section {
            margin: 30px 0;
            padding: 20px;
            border: 1px solid #ddd;
            border-radius: 5px;
        }
        .status {
            padding: 10px;
            margin: 10px 0;
            border-radius: 3px;
        }
        .success { background-color: #d4edda; color: #155724; }
        .error { background-color: #f8d7da; color: #721c24; }
        .info { background-color: #d1ecf1; color: #0c5460; }
        pre {
            background: #f4f4f4;
            padding: 10px;
            border-radius: 3px;
            overflow-x: auto;
        }
        button {
            padding: 10px 20px;
            background: #007bff;
            color: white;
            border: none;
            border-radius: 3px;
            cursor: pointer;
            margin: 5px;
        }
        button:hover {
            background: #0056b3;
        }
    </style>
</head>
<body>
    <h1>XCorch Tracker Test Page</h1>
    
    <div class="test-section">
        <h2>Server-Side Test</h2>
        <?php
        require __DIR__ . '/vendor/autoload.php';
        
        use xcorch\Tracker\Tracker;
        
        try {
            $tracker = new Tracker();
            
            echo '<div class="status success">✓ Tracker initialized successfully</div>';
            echo '<p><strong>API Key:</strong> ' . substr($tracker->getApiKey(), 0, 15) . '...</p>';
            echo '<p><strong>Website Code:</strong> ' . $tracker->getWebsiteCode() . '</p>';
            
            // Test credential validation
            echo '<h3>Credential Validation</h3>';
            $result = $tracker->validateCredentials();
            
            if ($result['valid']) {
                echo '<div class="status success">✓ Credentials are valid</div>';
                if (isset($result['data']['site'])) {
                    echo '<p><strong>Site:</strong> ' . htmlspecialchars($result['data']['site']['site_name'] ?? 'N/A') . '</p>';
                }
                if (isset($result['data']['business'])) {
                    echo '<p><strong>Business:</strong> ' . htmlspecialchars($result['data']['business']['business_name'] ?? 'N/A') . '</p>';
                }
            } else {
                echo '<div class="status error">✗ Credential validation failed</div>';
                echo '<p><strong>Error:</strong> ' . htmlspecialchars($result['error'] ?? 'Unknown error') . '</p>';
                echo '<p><strong>HTTP Code:</strong> ' . ($result['http_code'] ?? 'N/A') . '</p>';
            }
            
            // Test tracking
            echo '<h3>Page View Tracking</h3>';
            $jsCode = $tracker->track();
            
            if (empty($jsCode)) {
                echo '<div class="status error">✗ Tracking failed - no JavaScript code returned</div>';
                echo '<p>Possible reasons:</p><ul>';
                echo '<li>Page is excluded from tracking</li>';
                echo '<li>Session creation failed</li>';
                echo '<li>View recording failed</li>';
                echo '<li>View ID not returned from API</li>';
                echo '</ul>';
            } else {
                echo '<div class="status success">✓ Tracking JavaScript generated</div>';
                echo '<p><strong>JavaScript length:</strong> ' . strlen($jsCode) . ' bytes</p>';
                
                // Check if view ID is in the JavaScript
                if (strpos($jsCode, 'var viewId =') !== false) {
                    echo '<div class="status success">✓ View ID found in JavaScript</div>';
                    if (preg_match('/var viewId = (\d+);/', $jsCode, $matches)) {
                        echo '<p><strong>View ID:</strong> ' . $matches[1] . '</p>';
                    }
                } else {
                    echo '<div class="status error">✗ View ID not found in JavaScript</div>';
                }
                
                // Check if endpoint is correct
                if (strpos($jsCode, '/api/v1/tracking/view/') !== false) {
                    echo '<div class="status success">✓ Update endpoint found in JavaScript</div>';
                } else {
                    echo '<div class="status error">✗ Update endpoint not found in JavaScript</div>';
                }
            }
            
        } catch (\Exception $e) {
            echo '<div class="status error">✗ Error: ' . htmlspecialchars($e->getMessage()) . '</div>';
            echo '<p><strong>File:</strong> ' . htmlspecialchars($e->getFile()) . ':' . $e->getLine() . '</p>';
        }
        ?>
    </div>
    
    <div class="test-section">
        <h2>Client-Side Test</h2>
        <p>Open your browser's Developer Tools (F12) and check:</p>
        <ul>
            <li><strong>Console tab:</strong> Look for any XCorch Tracker errors</li>
            <li><strong>Network tab:</strong> Look for requests to <code>/api/v1/tracking/view/</code></li>
        </ul>
        
        <button onclick="testScroll()">Test Scroll Tracking</button>
        <button onclick="testUpdate()">Test Manual Update</button>
        
        <div id="test-results" style="margin-top: 20px;"></div>
    </div>
    
    <div class="test-section" style="min-height: 2000px;">
        <h2>Scroll Test Area</h2>
        <p>Scroll down this page to test scroll depth tracking.</p>
        <p>The tracker should send updates every 25% of scroll depth.</p>
        <p style="margin-top: 500px;">You've scrolled about 25%</p>
        <p style="margin-top: 500px;">You've scrolled about 50%</p>
        <p style="margin-top: 500px;">You've scrolled about 75%</p>
        <p style="margin-top: 500px;">You've scrolled 100%</p>
    </div>
    
    <?php
    // Output the tracking JavaScript
    if (isset($jsCode) && !empty($jsCode)) {
        echo $jsCode;
    }
    ?>
    
    <script>
        function testScroll() {
            window.scrollTo(0, document.body.scrollHeight);
            document.getElementById('test-results').innerHTML = 
                '<div class="status info">Scrolled to bottom. Check Network tab for PUT request.</div>';
        }
        
        function testUpdate() {
            if (typeof viewId !== 'undefined' && typeof updateEndpoint !== 'undefined') {
                fetch(updateEndpoint, {
                    method: 'PUT',
                    headers: {'Content-Type': 'application/json'},
                    body: JSON.stringify({
                        scroll_depth: 100,
                        ended_at: new Date().toISOString()
                    })
                })
                .then(response => response.json())
                .then(data => {
                    document.getElementById('test-results').innerHTML = 
                        '<div class="status success">Update sent! Response: ' + JSON.stringify(data, null, 2) + '</div>';
                })
                .catch(err => {
                    document.getElementById('test-results').innerHTML = 
                        '<div class="status error">Error: ' + err.message + '</div>';
                });
            } else {
                document.getElementById('test-results').innerHTML = 
                    '<div class="status error">View ID not found. Tracking script may not have loaded.</div>';
            }
        }
        
        // Log all fetch requests for debugging
        const originalFetch = window.fetch;
        window.fetch = function(...args) {
            if (args[0].includes('/api/v1/tracking/view/')) {
                console.log('XCorch Tracker: Fetch request', args);
            }
            return originalFetch.apply(this, args)
                .then(response => {
                    if (args[0].includes('/api/v1/tracking/view/')) {
                        console.log('XCorch Tracker: Response', response.status, response.statusText);
                        response.clone().json().then(data => {
                            console.log('XCorch Tracker: Response data', data);
                        }).catch(() => {});
                    }
                    return response;
                });
        };
    </script>
</body>
</html>
