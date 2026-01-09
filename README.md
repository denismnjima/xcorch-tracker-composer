# XCorch Tracker

A PHP Composer package for tracking website views to your XCorch dashboard.

## Installation

```bash
composer require xcorch/tracker
```

## Configuration

Create a `.env` file in your project root with the following variables:

```env
XCORCH_API=xcorch_your_api_key_here
XCORCH_WEBSITE_CODE=ABC12345
```

Your API key and website code can be found in your XCorch app or website dashboard.

## Usage

```php
<?php

require 'vendor/autoload.php';

use xcorch\Tracker\Tracker;

$tracker = new Tracker();

// Validate your API key and website code
$result = $tracker->validateCredentials();

if ($result['valid']) {
    echo "Credentials are valid!";
    // Access site and business data from $result['data']
} else {
    echo "Validation failed: " . $result['error'];
}
```
