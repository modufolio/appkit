<?php

/**
 * Security Headers Configuration
 *
 * Configure HTTP security headers to protect against common web vulnerabilities.
 *
 * Testing:
 * - Mozilla Observatory: https://observatory.mozilla.org/
 * - SecurityHeaders.com: https://securityheaders.com/
 *
 * Target: Grade A+ on both platforms
 */

return [
    /**
     * X-Frame-Options: Prevents clickjacking attacks
     *
     * Options:
     * - DENY: Page cannot be displayed in a frame (recommended)
     * - SAMEORIGIN: Page can only be displayed in frame on same origin
     */
    'x_frame_options' => [
        'enabled' => true,
        'value' => $_ENV['X_FRAME_OPTIONS'] ?? 'DENY',
    ],

    /**
     * X-Content-Type-Options: Prevents MIME sniffing
     *
     * Forces browsers to respect declared Content-Type
     */
    'x_content_type_options' => [
        'enabled' => true,
    ],

    /**
     * X-XSS-Protection: Legacy XSS protection for older browsers
     *
     * Note: Modern browsers use CSP instead, but this provides defense-in-depth
     */
    'x_xss_protection' => [
        'enabled' => true,
        'mode_block' => true, // Block page instead of sanitizing
    ],

    /**
     * Referrer-Policy: Controls referrer information leakage
     *
     * Options (in order of privacy):
     * - no-referrer: Never send referrer
     * - same-origin: Send for same-origin requests only
     * - strict-origin: Send origin only, HTTPS→HTTP drops referrer
     * - no-referrer-when-downgrade: Default, don't send on HTTPS→HTTP (recommended)
     * - origin: Always send origin only
     * - origin-when-cross-origin: Full URL for same-origin, origin for cross-origin
     * - strict-origin-when-cross-origin: Like above but drop on HTTPS→HTTP
     * - unsafe-url: Always send full URL (not recommended)
     */
    'referrer_policy' => [
        'enabled' => true,
        'policy' => $_ENV['REFERRER_POLICY'] ?? 'no-referrer-when-downgrade',
    ],

    /**
     * Strict-Transport-Security (HSTS): Enforce HTTPS
     *
     * WARNING: Set preload=true only after:
     * 1. Testing with short max-age (300 seconds)
     * 2. Increasing to longer periods (1 week, 1 month)
     * 3. Submitting to https://hstspreload.org/
     * 4. Being certain HTTPS will work forever
     *
     * Removing preload is difficult and takes months!
     */
    'hsts' => [
        'enabled' => (bool)($_ENV['HSTS_ENABLED'] ?? true),
        'max_age' => (int)($_ENV['HSTS_MAX_AGE'] ?? 31536000), // 1 year
        'include_sub_domains' => (bool)($_ENV['HSTS_INCLUDE_SUBDOMAINS'] ?? true),
        'preload' => (bool)($_ENV['HSTS_PRELOAD'] ?? false), // Only enable after testing!
    ],

    /**
     * Content-Security-Policy (CSP): Prevents XSS and injection attacks
     *
     * Start in report-only mode to avoid breaking functionality!
     * Monitor reports and adjust directives before enforcing.
     *
     * Common directives:
     * - default-src: Fallback for other directives
     * - script-src: JavaScript sources
     * - style-src: CSS sources
     * - img-src: Image sources
     * - font-src: Font sources
     * - connect-src: XMLHttpRequest, fetch, WebSocket sources
     * - frame-src: iframe sources
     * - frame-ancestors: Who can embed this page (replaces X-Frame-Options)
     */
    'csp' => [
        'enabled' => (bool)($_ENV['CSP_ENABLED'] ?? true),
        'report_only' => (bool)($_ENV['CSP_REPORT_ONLY'] ?? false), // Set true initially!
        'report_uri' => $_ENV['CSP_REPORT_URI'] ?? null, // e.g., '/__csp-report'

        'directives' => [
            // Default policy for all resource types
            'default-src' => ['self'],

            // JavaScript sources
            // Note: 'unsafe-inline' needed for inline <script> tags
            // Note: 'unsafe-eval' needed for eval() (avoid if possible)
            'script-src' => [
                'self',
                'unsafe-inline', // Remove after moving all inline scripts to files
                // Add CDN domains if needed:
                // 'cdn.example.com',
                // 'https://unpkg.com',
            ],

            // CSS sources
            'style-src' => [
                'self',
                'unsafe-inline', // Needed for inline styles and style attributes
                // Add CDN domains if needed:
                // 'fonts.googleapis.com',
            ],

            // Image sources
            'img-src' => [
                'self',
                'data:', // For data URIs
                'https:', // Allow all HTTPS images (tighten this in production)
            ],

            // Font sources
            'font-src' => [
                'self',
                'data:', // For data URI fonts
                // Add font CDNs if needed:
                // 'fonts.gstatic.com',
            ],

            // AJAX, WebSocket, fetch sources
            'connect-src' => [
                'self',
                // Add API domains if needed:
                // 'api.example.com',
            ],

            // iframe sources (for embedding other sites)
            'frame-src' => [
                'self',
                // Add trusted iframe sources:
                // 'youtube.com',
                // 'player.vimeo.com',
            ],

            // Who can embed this page in iframe
            'frame-ancestors' => [
                'none', // Equivalent to X-Frame-Options: DENY
                // Or use 'self' for SAMEORIGIN behavior
            ],

            // Form submission targets
            'form-action' => [
                'self',
            ],

            // <base> tag restriction
            'base-uri' => [
                'self',
            ],

            // Object/embed sources (Flash, Java, etc.)
            'object-src' => [
                'none', // Disable plugins
            ],
        ],
    ],

    /**
     * Permissions-Policy (formerly Feature-Policy)
     *
     * Controls which browser features can be used.
     * Empty array = feature disabled for all origins
     */
    'permissions_policy' => [
        'enabled' => (bool)($_ENV['PERMISSIONS_POLICY_ENABLED'] ?? true),
        'directives' => [
            // Location services
            'geolocation' => [],

            // Microphone access
            'microphone' => [],

            // Camera access
            'camera' => [],

            // Payment Request API
            'payment' => [],

            // USB access
            'usb' => [],

            // Sensors
            'magnetometer' => [],
            'gyroscope' => [],
            'accelerometer' => [],

            // Uncomment to allow features for self:
            // 'geolocation' => ['self'],
            // 'microphone' => ['self'],
        ],
    ],
];
