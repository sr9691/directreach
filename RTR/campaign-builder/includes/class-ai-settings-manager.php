<?php
/**
 * AI Settings Manager
 *
 * Manages AI configuration including API keys, model settings, and encryption.
 *
 * @package DirectReach
 * @subpackage RTR
 * @since 2.5.0
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class CPD_AI_Settings_Manager {

    /**
     * Option prefix
     */
    const OPTION_PREFIX = 'dr_ai_';

    /**
     * Encryption method
     */
    const ENCRYPTION_METHOD = 'AES-256-CBC';

    /**
     * Check if AI email generation is enabled
     *
     * @return bool
     */
    public function is_enabled() {
        return (bool) get_option( self::OPTION_PREFIX . 'enabled', false );
    }

    /**
     * Enable/disable AI generation
     *
     * @param bool $enabled Enabled state
     * @return bool Success
     */
    public function set_enabled( $enabled ) {
        return update_option( self::OPTION_PREFIX . 'enabled', (bool) $enabled );
    }

    /**
     * Get Gemini API key (decrypted)
     *
     * @return string|null API key or null if not set
     */
    public function get_api_key() {
        $encrypted = get_option( self::OPTION_PREFIX . 'api_key_encrypted' );

        if ( empty( $encrypted ) ) {
            return null;
        }

        return $this->decrypt( $encrypted );
    }

    /**
     * Set Gemini API key (encrypted)
     *
     * @param string $api_key API key
     * @return bool Success
     */
    public function set_api_key( $api_key ) {
        if ( empty( $api_key ) ) {
            return delete_option( self::OPTION_PREFIX . 'api_key_encrypted' );
        }

        $encrypted = $this->encrypt( $api_key );
        return update_option( self::OPTION_PREFIX . 'api_key_encrypted', $encrypted );
    }

    /**
     * Get model name
     *
     * @return string Model name
     */
    public function get_model() {
        $saved_model = get_option( self::OPTION_PREFIX . 'model' );
        
        // If we have a saved model, use it
        if ( ! empty( $saved_model ) ) {
            return $saved_model;
        }
        
        // Otherwise, use the most current known stable model
        // Update this as Gemini releases new models
        return 'gemini-2.5-flash';
    }

    /**
     * Set model name
     *
     * @param string $model Model name
     * @return bool Success
     */
    public function set_model( $model ) {
        // Validate model name format
        if ( empty( $model ) || ! is_string( $model ) ) {
            return false;
        }
        
        // Must start with "gemini-" (allow models/ prefix)
        if ( ! preg_match( '/^(models\/)?gemini-[\w\.\-]+$/', $model ) ) {
            error_log( '[DirectReach] Invalid model name format: ' . $model );
            return false;
        }
        
        // Strip models/ prefix if present
        $model = str_replace( 'models/', '', $model );

        return update_option( self::OPTION_PREFIX . 'model', $model );
    }

    /**
     * Get temperature setting
     *
     * @return float Temperature (0.0 - 1.0)
     */
    public function get_temperature() {
        return (float) get_option( self::OPTION_PREFIX . 'temperature', 0.7 );
    }

    /**
     * Set temperature
     *
     * @param float $temperature Temperature (0.0 - 1.0)
     * @return bool Success
     */
    public function set_temperature( $temperature ) {
        $temperature = (float) $temperature;
        
        if ( $temperature < 0 || $temperature > 1 ) {
            return false;
        }

        return update_option( self::OPTION_PREFIX . 'temperature', $temperature );
    }

    /**
     * Get max tokens
     *
     * @return int Max tokens
     */
    public function get_max_tokens() {
        return (int) get_option( self::OPTION_PREFIX . 'max_tokens', 1000 );
    }

    /**
     * Set max tokens
     *
     * @param int $max_tokens Max tokens
     * @return bool Success
     */
    public function set_max_tokens( $max_tokens ) {
        $max_tokens = (int) $max_tokens;

        if ( $max_tokens < 100 || $max_tokens > 8000 ) {
            return false;
        }

        return update_option( self::OPTION_PREFIX . 'max_tokens', $max_tokens );
    }

    /**
     * Get rate limit (requests per hour)
     *
     * @return int Rate limit
     */
    public function get_rate_limit() {
        return (int) get_option( self::OPTION_PREFIX . 'rate_limit', 100 );
    }

    /**
     * Set rate limit
     *
     * @param int $limit Rate limit
     * @return bool Success
     */
    public function set_rate_limit( $limit ) {
        $limit = (int) $limit;

        if ( $limit < 1 || $limit > 1000 ) {
            return false;
        }

        return update_option( self::OPTION_PREFIX . 'rate_limit', $limit );
    }

    /**
     * Get all settings
     *
     * @return array All settings
     */
    public function get_all_settings() {
        return array(
            'enabled' => $this->is_enabled(),
            'api_key_set' => ! empty( $this->get_api_key() ),
            'model' => $this->get_model(),
            'temperature' => $this->get_temperature(),
            'max_tokens' => $this->get_max_tokens(),
            'rate_limit' => $this->get_rate_limit(),
        );
    }

    /**
     * Update multiple settings
     *
     * @param array $settings Settings to update
     * @return array Results
     */
    public function update_settings( $settings ) {
        $results = array();

        if ( isset( $settings['enabled'] ) ) {
            $results['enabled'] = $this->set_enabled( $settings['enabled'] );
        }

        if ( isset( $settings['api_key'] ) ) {
            $results['api_key'] = $this->set_api_key( $settings['api_key'] );
        }

        if ( isset( $settings['model'] ) ) {
            $results['model'] = $this->set_model( $settings['model'] );
        }

        if ( isset( $settings['temperature'] ) ) {
            $results['temperature'] = $this->set_temperature( $settings['temperature'] );
        }

        if ( isset( $settings['max_tokens'] ) ) {
            $results['max_tokens'] = $this->set_max_tokens( $settings['max_tokens'] );
        }

        if ( isset( $settings['rate_limit'] ) ) {
            $results['rate_limit'] = $this->set_rate_limit( $settings['rate_limit'] );
        }

        return $results;
    }

    /**
     * Test API connection
     *
     * @param string $api_key Optional API key to test (if not provided, uses stored key)
     * @return array|WP_Error Test result
     */
    public function test_connection( $api_key = null ) {
        if ( empty( $api_key ) ) {
            $api_key = $this->get_api_key();
        }

        if ( empty( $api_key ) ) {
            return new WP_Error( 'no_api_key', 'No API key provided' );
        }

        $endpoint = sprintf(
            'https://generativelanguage.googleapis.com/v1beta/models/gemini-2.5-flash:generateContent?key=%s',
            $api_key
        );

        $body = array(
            'contents' => array(
                array(
                    'parts' => array(
                        array( 'text' => 'Say "Connection successful" if you can read this.' )
                    )
                )
            ),
            'generationConfig' => array(
                'maxOutputTokens' => 50,
            ),
        );

        $response = wp_remote_post( $endpoint, array(
            'timeout' => 10,
            'headers' => array(
                'Content-Type' => 'application/json',
            ),
            'body' => wp_json_encode( $body ),
        ));

        if ( is_wp_error( $response ) ) {
            return new WP_Error(
                'connection_failed',
                'Failed to connect: ' . $response->get_error_message()
            );
        }

        $status_code = wp_remote_retrieve_response_code( $response );

        if ( $status_code !== 200 ) {
            $body = wp_remote_retrieve_body( $response );
            return new WP_Error(
                'api_error',
                sprintf( 'API returned status %d: %s', $status_code, $body )
            );
        }

        return array(
            'success' => true,
            'message' => 'Connection successful',
            'model' => 'gemini-2.5-flash',
        );
    }

    /**
     * Encrypt data
     *
     * @param string $data Data to encrypt
     * @return string Encrypted data
     */
    private function encrypt( $data ) {
        $key = $this->get_encryption_key();
        $iv = $this->get_encryption_iv();

        $encrypted = openssl_encrypt(
            $data,
            self::ENCRYPTION_METHOD,
            $key,
            0,
            $iv
        );

        return base64_encode( $encrypted );
    }

    /**
     * Decrypt data
     *
     * @param string $encrypted Encrypted data
     * @return string Decrypted data
     */
    private function decrypt( $encrypted ) {
        $key = $this->get_encryption_key();
        $iv = $this->get_encryption_iv();

        $decoded = base64_decode( $encrypted );

        return openssl_decrypt(
            $decoded,
            self::ENCRYPTION_METHOD,
            $key,
            0,
            $iv
        );
    }

    /**
     * Get encryption key
     *
     * @return string Encryption key
     */
    private function get_encryption_key() {
        // Use WordPress auth salt as encryption key
        return substr( hash( 'sha256', SECURE_AUTH_KEY ), 0, 32 );
    }

    /**
     * Get encryption IV
     *
     * @return string Encryption IV
     */
    private function get_encryption_iv() {
        // Use WordPress secure auth salt as IV
        return substr( hash( 'sha256', SECURE_AUTH_SALT ), 0, 16 );
    }

    /**
     * Clear all settings
     *
     * @return bool Success
     */
    public function clear_all_settings() {
        $options = array(
            'enabled',
            'api_key_encrypted',
            'model',
            'temperature',
            'max_tokens',
            'rate_limit',
        );

        foreach ( $options as $option ) {
            delete_option( self::OPTION_PREFIX . $option );
        }

        return true;
    }
}