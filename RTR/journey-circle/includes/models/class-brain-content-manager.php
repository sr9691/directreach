<?php
/**
 * Brain Content Manager
 *
 * Handles all operations for brain content (URLs, text, files).
 *
 * @package Journey_Circle
 */

class Brain_Content_Manager {

    /**
     * Maximum characters of extracted text to store.
     * Keeps DB reasonable while providing enough context for AI.
     */
    const MAX_EXTRACTED_LENGTH = 5000;

    /**
     * Maximum characters of raw content before summarization is triggered.
     * Content shorter than this is stored directly.
     */
    const SUMMARIZE_THRESHOLD = 6000;

    /**
     * Maximum characters of raw content to send to the summarizer.
     */
    const MAX_RAW_FOR_SUMMARY = 15000;

    /**
     * Add brain content to a service area.
     *
     * @since 1.0.0
     * @param int   $service_area_id Service area ID.
     * @param array $args            Brain content arguments.
     * @return int|WP_Error Content ID on success, WP_Error on failure.
     */
    public function add_content( $service_area_id, $args ) {
        // Validate required fields
        if ( empty( $args['type'] ) ) {
            return new WP_Error( 'missing_type', 'Content type is required' );
        }
        
        if ( empty( $args['value'] ) ) {
            return new WP_Error( 'missing_value', 'Content value is required' );
        }
        
        // Validate content type
        $allowed_types = array( 'url', 'text', 'file' );
        if ( ! in_array( $args['type'], $allowed_types ) ) {
            return new WP_Error( 'invalid_type', 'Invalid content type' );
        }
        
        // Validate URL if type is url
        if ( $args['type'] === 'url' && ! filter_var( $args['value'], FILTER_VALIDATE_URL ) ) {
            return new WP_Error( 'invalid_url', 'Invalid URL format' );
        }
        
        // Default values
        $defaults = array(
            'type'  => '',
            'value' => '',
            'title' => '',
        );
        
        $args = wp_parse_args( $args, $defaults );
        
        // Generate title if not provided
        if ( empty( $args['title'] ) ) {
            $args['title'] = $this->generate_title( $args['type'], $args['value'] );
        }
        
        // Create brain content post
        $post_data = array(
            'post_title'   => sanitize_text_field( $args['title'] ),
            'post_content' => $args['type'] === 'text' ? wp_kses_post( $args['value'] ) : '',
            'post_type'    => 'jc_brain_content',
            'post_status'  => 'publish',
        );
        
        $content_id = wp_insert_post( $post_data );
        
        if ( is_wp_error( $content_id ) ) {
            return $content_id;
        }
        
        // Set meta data
        update_post_meta( $content_id, '_jc_service_area_id', absint( $service_area_id ) );
        update_post_meta( $content_id, '_jc_content_type', sanitize_text_field( $args['type'] ) );
        
        if ( $args['type'] === 'url' ) {
            update_post_meta( $content_id, '_jc_url', esc_url_raw( $args['value'] ) );
        } elseif ( $args['type'] === 'file' ) {
            update_post_meta( $content_id, '_jc_file_path', sanitize_text_field( $args['value'] ) );
        }
        
        // Also store in custom table for better querying
        $this->store_in_table( $service_area_id, $args['type'], $args['value'] );
        
        // Trigger content extraction asynchronously.
        // For text type, content is already available — extract immediately.
        if ( $args['type'] === 'text' ) {
            $this->extract_and_store( $content_id, 'text', $args['value'] );
        } elseif ( $args['type'] === 'url' ) {
            $this->extract_and_store_url( $content_id, $args['value'] );
        }
        // File extraction happens in upload_file() after the file is saved.

        return $content_id;
    }
    
    /**
     * Get all brain content for a service area.
     *
     * @since 1.0.0
     * @param int $service_area_id Service area ID.
     * @return array Array of brain content items.
     */
    public function get_by_service_area( $service_area_id ) {
        $args = array(
            'post_type'      => 'jc_brain_content',
            'posts_per_page' => -1,
            'post_status'    => 'publish',
            'meta_query'     => array(
                array(
                    'key'     => '_jc_service_area_id',
                    'value'   => absint( $service_area_id ),
                    'compare' => '='
                )
            )
        );
        
        $query = new WP_Query( $args );
        $content_items = array();
        
        if ( $query->have_posts() ) {
            while ( $query->have_posts() ) {
                $query->the_post();
                $post = get_post();
                $type = get_post_meta( $post->ID, '_jc_content_type', true );
                
                $value = '';
                if ( $type === 'url' ) {
                    $value = get_post_meta( $post->ID, '_jc_url', true );
                } elseif ( $type === 'text' ) {
                    $value = $post->post_content;
                } elseif ( $type === 'file' ) {
                    $value = get_post_meta( $post->ID, '_jc_file_path', true );
                }
                
                $content_items[] = array(
                    'id'             => $post->ID,
                    'title'          => $post->post_title,
                    'type'           => $type,
                    'value'          => $value,
                    'extracted_text' => get_post_meta( $post->ID, '_jc_extracted_text', true ) ?: '',
                    'extraction_status' => get_post_meta( $post->ID, '_jc_extraction_status', true ) ?: 'pending',
                );
            }
            wp_reset_postdata();
        }
        
        return $content_items;
    }
    
    /**
     * Delete brain content.
     *
     * @since 1.0.0
     * @param int $content_id Content ID.
     * @return bool True on success, false on failure.
     */
    public function delete( $content_id ) {
        $post = get_post( $content_id );
        
        if ( ! $post || $post->post_type !== 'jc_brain_content' ) {
            return false;
        }
        
        // If it's a file, delete the file
        $type = get_post_meta( $content_id, '_jc_content_type', true );
        if ( $type === 'file' ) {
            $file_path = get_post_meta( $content_id, '_jc_file_path', true );
            if ( file_exists( $file_path ) ) {
                @unlink( $file_path );
            }
        }
        
        $result = wp_delete_post( $content_id, true );
        
        return $result !== false;
    }
    
    /**
     * Upload a file and add it as brain content.
     *
     * @since 1.0.0
     * @param int   $service_area_id Service area ID.
     * @param array $file            $_FILES array element.
     * @return int|WP_Error Content ID on success, WP_Error on failure.
     */
    public function upload_file( $service_area_id, $file ) {
        // Validate file
        if ( ! isset( $file['tmp_name'] ) || ! is_uploaded_file( $file['tmp_name'] ) ) {
            return new WP_Error( 'invalid_file', 'Invalid file upload' );
        }
        
        // Check file size (10MB max)
        $max_size = 10 * 1024 * 1024; // 10MB
        if ( $file['size'] > $max_size ) {
            return new WP_Error( 'file_too_large', 'File size exceeds 10MB limit' );
        }
        
        // Allowed file types
        $allowed_types = array(
            'application/pdf',
            'application/msword',
            'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
            'text/plain',
            'text/html',
        );
        
        $finfo = finfo_open( FILEINFO_MIME_TYPE );
        $mime_type = finfo_file( $finfo, $file['tmp_name'] );
        finfo_close( $finfo );
        
        if ( ! in_array( $mime_type, $allowed_types ) ) {
            return new WP_Error( 'invalid_file_type', 'File type not allowed' );
        }
        
        // Use WordPress upload handler
        require_once( ABSPATH . 'wp-admin/includes/file.php' );
        
        $upload_overrides = array(
            'test_form' => false,
            'test_type' => true,
        );
        
        $uploaded_file = wp_handle_upload( $file, $upload_overrides );
        
        if ( isset( $uploaded_file['error'] ) ) {
            return new WP_Error( 'upload_error', $uploaded_file['error'] );
        }
        
        // Add file as brain content.
        $content_id = $this->add_content( $service_area_id, array(
            'type'  => 'file',
            'value' => $uploaded_file['file'],
            'title' => $file['name'],
        ) );

        if ( ! is_wp_error( $content_id ) ) {
            $this->extract_and_store_file( $content_id, $uploaded_file['file'] );
        }

        return $content_id;
    }
    
/**
     * Extract text content from a file.
     *
     * Supports plain text, HTML, PDF (via pdftotext or php-pdf-parser),
     * and DOCX (via ZIP + XML parsing).
     *
     * @since 2.1.0
     * @param string $file_path File path.
     * @return string|WP_Error Extracted text or WP_Error on failure.
     */
    public function extract_file_content( $file_path ) {
        if ( ! file_exists( $file_path ) ) {
            return new WP_Error( 'file_not_found', 'File not found' );
        }

        $mime_type = mime_content_type( $file_path );

        switch ( $mime_type ) {
            case 'text/plain':
                $content = file_get_contents( $file_path );
                return ( false !== $content ) ? $content : new WP_Error( 'read_error', 'Could not read text file.' );

            case 'text/html':
                $content = file_get_contents( $file_path );
                if ( false === $content ) {
                    return new WP_Error( 'read_error', 'Could not read HTML file.' );
                }
                return wp_strip_all_tags( $content );

            case 'application/pdf':
                return $this->extract_pdf_content( $file_path );

            case 'application/msword':
            case 'application/vnd.openxmlformats-officedocument.wordprocessingml.document':
                return $this->extract_docx_content( $file_path );

            default:
                return new WP_Error( 'unsupported_type', 'Unsupported file type for content extraction: ' . $mime_type );
        }
    }

    /**
     * Extract text from a PDF file.
     *
     * Tries pdftotext CLI first (best quality), then falls back to
     * basic stream extraction.
     *
     * @since 2.1.0
     * @param string $file_path Path to PDF file.
     * @return string|WP_Error Extracted text or error.
     */
    private function extract_pdf_content( $file_path ) {
        // Strategy 1: pdftotext (poppler-utils) — best quality.
        $pdftotext = $this->find_executable( 'pdftotext' );
        if ( $pdftotext ) {
            $escaped_path = escapeshellarg( $file_path );
            $output = shell_exec( "{$pdftotext} -layout {$escaped_path} -" );
            if ( ! empty( $output ) ) {
                return trim( $output );
            }
        }

        // Strategy 2: Basic PHP stream extraction (no external deps).
        $content = file_get_contents( $file_path );
        if ( false === $content ) {
            return new WP_Error( 'read_error', 'Could not read PDF file.' );
        }

        $text = '';

        // Extract text between stream/endstream markers.
        if ( preg_match_all( '/stream\s*\n(.*?)\nendstream/s', $content, $matches ) ) {
            foreach ( $matches[1] as $stream ) {
                // Try to decompress if zlib compressed.
                $decompressed = @gzuncompress( $stream );
                if ( false === $decompressed ) {
                    $decompressed = @gzinflate( $stream );
                }
                $data = ( false !== $decompressed ) ? $decompressed : $stream;

                // Extract text from Tj and TJ operators.
                if ( preg_match_all( '/\(([^)]+)\)\s*Tj/s', $data, $tj ) ) {
                    $text .= implode( ' ', $tj[1] ) . "\n";
                }
                if ( preg_match_all( '/\[([^\]]+)\]\s*TJ/s', $data, $tjs ) ) {
                    foreach ( $tjs[1] as $tj_array ) {
                        if ( preg_match_all( '/\(([^)]*)\)/', $tj_array, $parts ) ) {
                            $text .= implode( '', $parts[1] );
                        }
                    }
                    $text .= "\n";
                }
            }
        }

        $text = trim( $text );

        if ( empty( $text ) ) {
            return new WP_Error( 'extraction_failed', 'Could not extract text from PDF. The file may be image-based or encrypted.' );
        }

        return $text;
    }

    /**
     * Extract text from a DOCX file.
     *
     * DOCX is a ZIP archive containing XML. We read word/document.xml
     * and extract text from <w:t> elements.
     *
     * @since 2.1.0
     * @param string $file_path Path to DOCX file.
     * @return string|WP_Error Extracted text or error.
     */
    private function extract_docx_content( $file_path ) {
        if ( ! class_exists( 'ZipArchive' ) ) {
            return new WP_Error( 'missing_dep', 'ZipArchive PHP extension is required for DOCX parsing.' );
        }

        $zip = new ZipArchive();
        if ( true !== $zip->open( $file_path ) ) {
            return new WP_Error( 'zip_error', 'Could not open DOCX file.' );
        }

        $xml = $zip->getFromName( 'word/document.xml' );
        $zip->close();

        if ( false === $xml ) {
            return new WP_Error( 'parse_error', 'Could not read document.xml from DOCX.' );
        }

        // Strip namespaces for simpler parsing.
        $xml = preg_replace( '/(<\/?)w:/', '$1', $xml );

        $dom = new DOMDocument();
        @$dom->loadXML( $xml );

        $text    = '';
        $paras   = $dom->getElementsByTagName( 'p' );

        foreach ( $paras as $para ) {
            $line = '';
            $runs = $para->getElementsByTagName( 't' );
            foreach ( $runs as $run ) {
                $line .= $run->textContent;
            }
            $line = trim( $line );
            if ( ! empty( $line ) ) {
                $text .= $line . "\n";
            }
        }

        $text = trim( $text );

        if ( empty( $text ) ) {
            return new WP_Error( 'extraction_failed', 'No text content found in DOCX file.' );
        }

        return $text;
    }

    /**
     * Find a system executable by name.
     *
     * @param string $name Executable name (e.g., 'pdftotext').
     * @return string|false Full path or false if not found.
     */
    private function find_executable( $name ) {
        $output = shell_exec( 'which ' . escapeshellarg( $name ) . ' 2>/dev/null' );
        $path   = trim( $output ?? '' );
        return ( ! empty( $path ) && is_executable( $path ) ) ? $path : false;
    }
    
    /**
     * Fetch and extract meaningful content from a URL.
     *
     * Fetches the page, strips navigation/scripts/styles, and extracts
     * the main text content.
     *
     * @since 1.0.0
     * @param string $url URL to fetch.
     * @return string|WP_Error Fetched content or WP_Error on failure.
     */
    public function fetch_url_content( $url ) {
        $response = wp_remote_get( $url, array(
            'timeout'    => 30,
            'user-agent' => 'Mozilla/5.0 (DirectReach Brain Content Extractor)',
        ) );

        if ( is_wp_error( $response ) ) {
            return $response;
        }

        $status = wp_remote_retrieve_response_code( $response );
        if ( $status !== 200 ) {
            return new WP_Error( 'http_error', "URL returned HTTP {$status}" );
        }

        $body = wp_remote_retrieve_body( $response );
        if ( empty( $body ) ) {
            return new WP_Error( 'empty_body', 'URL returned empty content.' );
        }

        // Remove scripts, styles, nav, header, footer, aside elements.
        $body = preg_replace( '/<script[^>]*>.*?<\/script>/si', '', $body );
        $body = preg_replace( '/<style[^>]*>.*?<\/style>/si', '', $body );
        $body = preg_replace( '/<nav[^>]*>.*?<\/nav>/si', '', $body );
        $body = preg_replace( '/<header[^>]*>.*?<\/header>/si', '', $body );
        $body = preg_replace( '/<footer[^>]*>.*?<\/footer>/si', '', $body );
        $body = preg_replace( '/<aside[^>]*>.*?<\/aside>/si', '', $body );

        // Try to extract <article> or <main> content first.
        $main_content = '';
        if ( preg_match( '/<article[^>]*>(.*?)<\/article>/si', $body, $match ) ) {
            $main_content = $match[1];
        } elseif ( preg_match( '/<main[^>]*>(.*?)<\/main>/si', $body, $match ) ) {
            $main_content = $match[1];
        }

        $text_source = ! empty( $main_content ) ? $main_content : $body;

        // Strip remaining HTML tags and decode entities.
        $text = wp_strip_all_tags( $text_source );
        $text = html_entity_decode( $text, ENT_QUOTES, 'UTF-8' );

        // Normalize whitespace.
        $text = preg_replace( '/\s+/', ' ', $text );
        $text = trim( $text );

        return $text;
    }

// =========================================================================
    // CONTENT EXTRACTION & STORAGE
    // =========================================================================

    /**
     * Extract content from a URL and store the summary.
     *
     * @since 2.1.0
     * @param int    $content_id Brain content post ID.
     * @param string $url        URL to fetch.
     */
    public function extract_and_store_url( $content_id, $url ) {
        update_post_meta( $content_id, '_jc_extraction_status', 'processing' );

        $raw_text = $this->fetch_url_content( $url );

        if ( is_wp_error( $raw_text ) || empty( $raw_text ) ) {
            update_post_meta( $content_id, '_jc_extraction_status', 'failed' );
            if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
                $msg = is_wp_error( $raw_text ) ? $raw_text->get_error_message() : 'Empty content';
                error_log( "Journey Circle: URL extraction failed for {$url}: {$msg}" );
            }
            return;
        }

        $this->process_and_store_extracted( $content_id, $raw_text );
    }

    /**
     * Extract content from an uploaded file and store the summary.
     *
     * @since 2.1.0
     * @param int    $content_id Brain content post ID.
     * @param string $file_path  Path to uploaded file.
     */
    public function extract_and_store_file( $content_id, $file_path ) {
        update_post_meta( $content_id, '_jc_extraction_status', 'processing' );

        $raw_text = $this->extract_file_content( $file_path );

        if ( is_wp_error( $raw_text ) || empty( $raw_text ) ) {
            update_post_meta( $content_id, '_jc_extraction_status', 'failed' );
            if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
                $msg = is_wp_error( $raw_text ) ? $raw_text->get_error_message() : 'Empty content';
                error_log( "Journey Circle: File extraction failed for {$file_path}: {$msg}" );
            }
            return;
        }

        $this->process_and_store_extracted( $content_id, $raw_text );
    }

    /**
     * Extract and store for pasted text content.
     *
     * @since 2.1.0
     * @param int    $content_id Brain content post ID.
     * @param string $type       Content type ('text').
     * @param string $value      Raw text content.
     */
    public function extract_and_store( $content_id, $type, $value ) {
        if ( $type !== 'text' || empty( $value ) ) {
            update_post_meta( $content_id, '_jc_extraction_status', 'skipped' );
            return;
        }

        update_post_meta( $content_id, '_jc_extraction_status', 'processing' );
        $this->process_and_store_extracted( $content_id, wp_strip_all_tags( $value ) );
    }

    /**
     * Process raw extracted text: summarize if needed, then store.
     *
     * If text is short enough, store directly. If too long, use Gemini
     * to create a focused summary suitable for downstream AI prompts.
     *
     * @since 2.1.0
     * @param int    $content_id Brain content post ID.
     * @param string $raw_text   Raw extracted text.
     */
    private function process_and_store_extracted( $content_id, $raw_text ) {
        // Clean up whitespace.
        $raw_text = preg_replace( '/\s+/', ' ', $raw_text );
        $raw_text = trim( $raw_text );

        if ( empty( $raw_text ) ) {
            update_post_meta( $content_id, '_jc_extraction_status', 'failed' );
            return;
        }

        // If short enough, store directly.
        if ( strlen( $raw_text ) <= self::SUMMARIZE_THRESHOLD ) {
            $extracted = substr( $raw_text, 0, self::MAX_EXTRACTED_LENGTH );
            update_post_meta( $content_id, '_jc_extracted_text', $extracted );
            update_post_meta( $content_id, '_jc_extraction_status', 'completed' );
            $this->update_brain_content_table( $content_id, $extracted, 'completed' );
            return;
        }

        // Too long — summarize via Gemini.
        $summary = $this->summarize_with_ai( $raw_text );

        if ( is_wp_error( $summary ) || empty( $summary ) ) {
            // Fallback: truncate intelligently.
            $extracted = $this->smart_truncate( $raw_text, self::MAX_EXTRACTED_LENGTH );
            update_post_meta( $content_id, '_jc_extracted_text', $extracted );
            update_post_meta( $content_id, '_jc_extraction_status', 'completed' );
            $this->update_brain_content_table( $content_id, $extracted, 'completed' );
            return;
        }

        $extracted = substr( $summary, 0, self::MAX_EXTRACTED_LENGTH );
        update_post_meta( $content_id, '_jc_extracted_text', $extracted );
        update_post_meta( $content_id, '_jc_extraction_status', 'completed' );
        $this->update_brain_content_table( $content_id, $extracted, 'completed' );
    }

    /**
     * Summarize long content using Gemini AI.
     *
     * Produces a focused summary highlighting key themes, problems,
     * solutions, and industry context — optimized for downstream
     * title generation prompts.
     *
     * @since 2.1.0
     * @param string $raw_text Raw text to summarize.
     * @return string|WP_Error Summary text or error.
     */
    private function summarize_with_ai( $raw_text ) {
        // Lazy-load the AI generator.
        if ( ! class_exists( 'DR_AI_Content_Generator' ) ) {
            $generator_path = plugin_dir_path( dirname( __FILE__ ) ) . 'journey-circle/class-ai-content-generator.php';
            if ( ! file_exists( $generator_path ) ) {
                $generator_path = dirname( __FILE__, 2 ) . '/includes/journey-circle/class-ai-content-generator.php';
            }
            if ( file_exists( $generator_path ) ) {
                require_once $generator_path;
            }
        }

        if ( ! class_exists( 'DR_AI_Content_Generator' ) ) {
            return new WP_Error( 'generator_missing', 'AI content generator class not found.' );
        }

        $generator = new DR_AI_Content_Generator();
        if ( ! $generator->is_configured() ) {
            return new WP_Error( 'not_configured', 'Gemini API not configured.' );
        }

        // Truncate input to avoid blowing the Gemini context window.
        $input = substr( $raw_text, 0, self::MAX_RAW_FOR_SUMMARY );

        $prompt = <<<PROMPT
Summarize the following content in 400-600 words. Focus on:
1. The main topics, themes, and subject matter
2. Specific problems or challenges discussed
3. Solutions, approaches, or methodologies mentioned
4. Industry context, target audiences, or market segments
5. Key data points, statistics, or claims
6. Services, products, or offerings described

Write the summary as a dense, factual paragraph — not a list. Preserve specific terminology, proper nouns, and technical details that would help an AI generate relevant content marketing titles later.

CONTENT TO SUMMARIZE:
{$input}

SUMMARY:
PROMPT;

        // Use reflection or a public wrapper to call the Gemini API.
        // Since call_gemini_api is private, we'll use a lightweight direct call.
        return $this->call_gemini_for_summary( $generator, $prompt );
    }

    /**
     * Call Gemini API for summarization using the generator's config.
     *
     * @since 2.1.0
     * @param DR_AI_Content_Generator $generator Generator instance.
     * @param string                  $prompt    Summary prompt.
     * @return string|WP_Error Summary text or error.
     */
    private function call_gemini_for_summary( $generator, $prompt ) {
        // Load the API key the same way the generator does.
        $api_key = $this->get_gemini_api_key();
        if ( empty( $api_key ) ) {
            return new WP_Error( 'no_api_key', 'Gemini API key not available.' );
        }

        $model = DR_AI_Content_Generator::DEFAULT_MODEL;
        $url   = DR_AI_Content_Generator::API_BASE_URL . $model . ':generateContent?key=' . $api_key;

        $body = array(
            'contents' => array(
                array(
                    'parts' => array(
                        array( 'text' => $prompt ),
                    ),
                ),
            ),
            'generationConfig' => array(
                'temperature'     => 0.3,
                'topP'            => 0.8,
                'maxOutputTokens' => 1024,
            ),
        );

        $response = wp_remote_post( $url, array(
            'headers' => array( 'Content-Type' => 'application/json' ),
            'body'    => wp_json_encode( $body ),
            'timeout' => 30,
        ) );

        if ( is_wp_error( $response ) ) {
            return $response;
        }

        if ( wp_remote_retrieve_response_code( $response ) !== 200 ) {
            return new WP_Error( 'api_error', 'Gemini API returned status ' . wp_remote_retrieve_response_code( $response ) );
        }

        $data = json_decode( wp_remote_retrieve_body( $response ), true );
        if ( isset( $data['candidates'][0]['content']['parts'][0]['text'] ) ) {
            return trim( $data['candidates'][0]['content']['parts'][0]['text'] );
        }

        return new WP_Error( 'parse_error', 'Could not parse Gemini summary response.' );
    }

    /**
     * Get the Gemini API key using the same lookup chain as the generator.
     *
     * @since 2.1.0
     * @return string API key or empty string.
     */
    private function get_gemini_api_key() {
        // Try Campaign Builder's AI Settings Manager.
        if ( ! class_exists( 'CPD_AI_Settings_Manager' ) ) {
            $paths = array(
                ( defined( 'DR_CB_PLUGIN_DIR' ) ? DR_CB_PLUGIN_DIR : '' ) . 'includes/class-ai-settings-manager.php',
                dirname( __FILE__, 4 ) . '/campaign-builder/includes/class-ai-settings-manager.php',
            );
            foreach ( $paths as $path ) {
                if ( ! empty( $path ) && file_exists( $path ) ) {
                    require_once $path;
                    break;
                }
            }
        }

        if ( class_exists( 'CPD_AI_Settings_Manager' ) ) {
            $manager = new CPD_AI_Settings_Manager();
            $key     = $manager->get_api_key();
            if ( ! empty( $key ) ) {
                return $key;
            }
        }

        // Fallback: standalone option.
        $key = get_option( 'dr_gemini_api_key', '' );
        if ( ! empty( $key ) ) {
            return $key;
        }

        // Fallback: Journey Circle settings.
        $jc_settings = get_option( 'jc_settings', array() );
        return $jc_settings['gemini_api_key'] ?? '';
    }

    /**
     * Smart-truncate text to a character limit at a sentence boundary.
     *
     * @since 2.1.0
     * @param string $text      Raw text.
     * @param int    $max_chars Maximum characters.
     * @return string Truncated text.
     */
    private function smart_truncate( $text, $max_chars ) {
        if ( strlen( $text ) <= $max_chars ) {
            return $text;
        }

        $truncated = substr( $text, 0, $max_chars );

        // Find the last sentence boundary.
        $last_period = strrpos( $truncated, '. ' );
        if ( false !== $last_period && $last_period > ( $max_chars * 0.5 ) ) {
            return substr( $truncated, 0, $last_period + 1 );
        }

        // Fall back to last space.
        $last_space = strrpos( $truncated, ' ' );
        if ( false !== $last_space ) {
            return substr( $truncated, 0, $last_space ) . '...';
        }

        return $truncated . '...';
    }

    /**
     * Update the custom brain content table with extracted text.
     *
     * @since 2.1.0
     * @param int    $content_id       Brain content post ID.
     * @param string $extracted_text    Extracted/summarized text.
     * @param string $extraction_status Status string.
     */
    private function update_brain_content_table( $content_id, $extracted_text, $extraction_status ) {
        global $wpdb;
        $table = $wpdb->prefix . 'jc_brain_content';

        // Find the row by matching content_value or use a separate lookup.
        // Since store_in_table doesn't store the post ID, we use post meta as primary.
        // But also update the custom table if it has the columns.
        $has_column = $wpdb->get_var(
            "SHOW COLUMNS FROM {$table} LIKE 'extracted_text'"
        );

        if ( $has_column ) {
            // Get the content_value from post meta to find the row.
            $content_type  = get_post_meta( $content_id, '_jc_content_type', true );
            $service_area  = get_post_meta( $content_id, '_jc_service_area_id', true );

            $wpdb->query(
                $wpdb->prepare(
                    "UPDATE {$table}
                     SET extracted_text = %s, extraction_status = %s
                     WHERE service_area_id = %d AND content_type = %s
                     ORDER BY id DESC LIMIT 1",
                    $extracted_text,
                    $extraction_status,
                    absint( $service_area ),
                    $content_type
                )
            );
        }
    }    
    
    /**
     * Store content in custom database table.
     *
     * @since 1.0.0
     * @param int    $service_area_id Service area ID.
     * @param string $type            Content type.
     * @param string $value           Content value.
     * @return int|false Insert ID on success, false on failure.
     */
    private function store_in_table( $service_area_id, $type, $value ) {
        global $wpdb;
        
        $table_name = $wpdb->prefix . 'jc_brain_content_data';
        
        $result = $wpdb->insert(
            $table_name,
            array(
                'service_area_id' => absint( $service_area_id ),
                'content_type'    => sanitize_text_field( $type ),
                'content_value'   => $type === 'text' ? wp_kses_post( $value ) : sanitize_text_field( $value ),
            ),
            array( '%d', '%s', '%s' )
        );
        
        if ( $result === false ) {
            return false;
        }
        
        return $wpdb->insert_id;
    }
    
    /**
     * Generate a title for brain content based on type and value.
     *
     * @since 1.0.0
     * @param string $type  Content type.
     * @param string $value Content value.
     * @return string Generated title.
     */
    private function generate_title( $type, $value ) {
        switch ( $type ) {
            case 'url':
                $parsed = parse_url( $value );
                return isset( $parsed['host'] ) ? $parsed['host'] : 'Untitled URL';
                
            case 'text':
                $excerpt = wp_trim_words( $value, 10 );
                return ! empty( $excerpt ) ? $excerpt : 'Untitled Text';
                
            case 'file':
                return basename( $value );
                
            default:
                return 'Untitled Content';
        }
    }
}
