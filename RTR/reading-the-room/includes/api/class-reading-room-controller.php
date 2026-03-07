    /**
     * Permission check.
     */
    public function check_permission(WP_REST_Request $request = null): bool|WP_Error {
        // Allow cookie-based auth (WordPress admin)
        if (current_user_can('edit_posts')) {
            return true;
        }

        // Allow X-API-Key auth (external systems like JourneyOS)
        if ($request) {
            $api_key = $request->get_header('X-API-Key');

            if (!empty($api_key)) {
                // Check against primary RTR key
                $stored_key = get_option('cpd_api_key', '');
                if (!empty($stored_key) && hash_equals($stored_key, $api_key)) {
                    return true;
                }

                // Also accept the JourneyOS shared key (used by Campaign Builder namespace)
                $journeyos_key = get_option('directreach_journeyos_api_key', '');
                if (!empty($journeyos_key) && hash_equals($journeyos_key, $api_key)) {
                    return true;
                }
            }
        }

        return new WP_Error(
            'rest_forbidden',
            'You do not have permission to access this endpoint.',
            ['status' => 403]
        );
    }
