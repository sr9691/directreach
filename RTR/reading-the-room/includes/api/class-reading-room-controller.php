    public function check_permission(WP_REST_Request $request = null): bool|WP_Error
    {
        error_log('[RTR_AUTH] check_permission called');
        error_log('[RTR_AUTH] request is null: ' . ($request === null ? 'YES' : 'NO'));
        
        // Allow cookie-based auth (WordPress admin)
        if (current_user_can('edit_posts')) {
            error_log('[RTR_AUTH] Cookie auth passed');
            return true;
        }
        error_log('[RTR_AUTH] Cookie auth failed, checking X-API-Key');

        // Allow X-API-Key auth (external systems like CIS)
        if ($request) {
            $api_key = $request->get_header('X-API-Key');
            error_log('[RTR_AUTH] X-API-Key header value: ' . ($api_key ? substr($api_key, 0, 8) . '...' : 'EMPTY/NULL'));
            
            if (!empty($api_key)) {
                $stored_key = get_option('cpd_api_key');
                error_log('[RTR_AUTH] Stored key exists: ' . (!empty($stored_key) ? 'YES (starts with ' . substr($stored_key, 0, 8) . ')' : 'NO'));
                error_log('[RTR_AUTH] Keys match: ' . ($api_key === $stored_key ? 'YES' : 'NO'));
                
                if (!empty($stored_key) && $api_key === $stored_key) {
                    error_log('[RTR_AUTH] X-API-Key auth passed');
                    return true;
                }
            }
            
            // Also try checking all headers for debugging
            error_log('[RTR_AUTH] All request headers: ' . print_r($request->get_headers(), true));
        }

        error_log('[RTR_AUTH] All auth methods failed - returning 403');
        return new WP_Error(
            'rest_forbidden',
            'You do not have permission to access this endpoint.',
            ['status' => 403]
        );
    }
}