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

### Basic Tracking

Simply call the `track()` method on each page to automatically track page views:

```php
<?php

require 'vendor/autoload.php';

use xcorch\Tracker\Tracker;

$tracker = new Tracker();
$tracker->track();
```

The `track()` method will:
- Check for an existing session in cookies
- Create a new session if one doesn't exist
- Record the page view with source URL (domain only, e.g., `google.com`)
- Automatically detect device type (mobile/desktop)

### Validate Credentials

You can validate your API key and website code:

```php
$tracker = new Tracker();
$result = $tracker->validateCredentials();

if ($result['valid']) {
    echo "Credentials are valid!";
    // Access site and business data from $result['data']
} else {
    echo "Validation failed: " . $result['error'];
}
```
