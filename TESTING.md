# Testing XCorch Tracker

## Quick Test

1. **Make sure you have a `.env` file** in the project root with:
   ```env
   XCORCH_API=your_api_key_here
   XCORCH_WEBSITE_CODE=your_site_code_here
   ```

2. **Start a test server:**
   ```bash
   cd /var/www/xcorch_tracker
   php -S localhost:8080 test_page.php
   ```

3. **Open in browser:**
   - Go to `http://localhost:8080`
   - The page will show:
     - Server-side test results (credential validation, view recording)
     - Client-side tracking status
     - Scroll test area

4. **Check browser console (F12):**
   - Look for "XCorch Tracker" messages
   - Check for any errors

5. **Check Network tab:**
   - Look for requests to `/api/v1/tracking/view/`
   - Should see PUT requests when scrolling

## Command Line Test

Run the test script:
```bash
php test_tracker.php
```

This will test:
- Tracker initialization
- Credential validation
- View recording
- JavaScript generation

## What to Look For

### Success Indicators:
- ✓ "View ID found in JavaScript"
- ✓ "Update endpoint found in JavaScript"
- Network requests to `/api/v1/tracking/view/{id}` with PUT method
- No errors in browser console

### Common Issues:
- **No JavaScript generated**: Check if view was recorded successfully
- **View ID is null**: API might not be returning the ID correctly
- **Update requests failing**: Check if API requires authentication (should be fixed now)
- **No scroll updates**: Check browser console for JavaScript errors

## Debugging

Check PHP error logs:
```bash
tail -f /var/log/php*-fpm.log
# or
tail -f /var/www/your-app/storage/logs/laravel.log
```

Look for messages starting with "XCorch Tracker:"

## Testing Scroll Depth

1. Scroll down the test page
2. Check Network tab - you should see PUT requests every 25% scroll
3. Leave the page - should see a final update with `ended_at`
