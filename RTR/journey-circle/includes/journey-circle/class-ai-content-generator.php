<?php
/**
 * AI Content Generator
 *
 * Integrates with Google Gemini API to generate problem titles,
 * solution titles, content outlines, and full content for Journey Circles.
 *
 * Part of Iteration 8: AI Title Recommendations
 *
 * @package DirectReach_Campaign_Builder
 * @subpackage Journey_Circle
 * @since 2.0.0
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Class DR_AI_Content_Generator
 *
 * Handles all AI-powered content generation for the Journey Circle workflow.
 * Uses Google Gemini API with structured prompt templates and response caching.
 *
 * Features:
 * - Problem title generation (8-10 titles)
 * - Solution title generation (3 per problem)
 * - Transient-based caching (15 minutes)
 * - Graceful error handling with fallback
 * - Structured JSON output parsing
 */
class DR_AI_Content_Generator {

    /**
     * Gemini API base URL.
     *
     * @var string
     */
    const API_BASE_URL = 'https://generativelanguage.googleapis.com/v1beta/models/';

    /**
     * Default Gemini model to use.
     *
     * @var string
     */
    const DEFAULT_MODEL = 'gemini-2.5-flash';

    /**
     * Cache duration in seconds (15 minutes).
     *
     * @var int
     */
    const CACHE_DURATION = 900;

    /**
     * API request timeout in seconds.
     *
     * @var int
     */
    const API_TIMEOUT = 30;

    /**
     * Maximum number of problem titles to request.
     *
     * @var int
     */
    const MAX_PROBLEM_TITLES = 10;

    /**
     * Minimum number of problem titles to request.
     *
     * @var int
     */
    const MIN_PROBLEM_TITLES = 8;

    /**
     * Number of solution titles per problem.
     *
     * @var int
     */
    const SOLUTION_TITLES_COUNT = 3;

    /**
     * Gemini API key.
     *
     * @var string|null
     */
    private $api_key = null;

    /**
     * Gemini model identifier.
     *
     * @var string
     */
    private $model;

    /**
     * Last API error message.
     *
     * @var string
     */
    private $last_error = '';

    /**
     * Constructor.
     *
     * Loads API key from WordPress options and sets the model.
     *
     * @param string|null $model Optional model override.
     */
    public function __construct( $model = null ) {
        $this->load_api_key();
        $this->model = $model ?? self::DEFAULT_MODEL;
    }

    // =========================================================================
    // PUBLIC API
    // =========================================================================

    /**
     * Generate problem title recommendations.
     *
     * Given brain content, industries, and service area context,
     * generates 8-10 problem titles suitable for content marketing.
     *
     * @since 2.0.0
     *
     * @param array $args {
     *     Arguments for problem title generation.
     *
     *     @type int    $service_area_id  Service area ID.
     *     @type string $service_area_name Service area name.
     *     @type array  $industries       Array of industry names/IDs.
     *     @type array  $brain_content    Array of brain content items.
     *     @type bool   $force_refresh    Skip cache if true.
     * }
     * @return array|WP_Error Array of title strings on success, WP_Error on failure.
     */
    public function generate_problem_titles( $args ) {
        $defaults = array(
            'service_area_id'   => 0,
            'service_area_name' => '',
            'industries'        => array(),
            'brain_content'     => array(),
            'existing_assets'   => array(),
            'force_refresh'     => false,
        );
        $args = wp_parse_args( $args, $defaults );

        $service_area = $args['service_area_name'] ?: '(id:' . $args['service_area_id'] . ')';
        $force        = $args['force_refresh'] ? 'yes' : 'no';

        // Validate inputs.
        if ( empty( $args['service_area_name'] ) && empty( $args['service_area_id'] ) ) {
            error_log( "[JC AI] generate_problem_titles FAIL — missing service area" );
            return new WP_Error(
                'missing_service_area',
                __( 'Service area is required to generate problem titles.', 'directreach' )
            );
        }

        // Resolve service area name from ID if needed.
        if ( empty( $args['service_area_name'] ) && ! empty( $args['service_area_id'] ) ) {
            $args['service_area_name'] = $this->get_service_area_name( $args['service_area_id'] );
            $service_area = $args['service_area_name'];
        }

        $prev_count = isset( $args['previous_titles'] ) ? count( $args['previous_titles'] ) : 0;
        error_log( "[JC AI] generate_problem_titles START — service_area={$service_area}, force_refresh={$force}, previous_titles={$prev_count}" );

        // Build cache key once — needed for both force-refresh deletion and normal lookup.
        $cache_key = $this->build_cache_key( 'problem_titles', $args );

        // On force refresh, delete cached transient first.
        if ( $args['force_refresh'] ) {
            delete_transient( $cache_key );
        }

        // Check cache unless force refresh.
        if ( ! $args['force_refresh'] ) {
            $cached = get_transient( $cache_key );
            if ( false !== $cached ) {
                error_log( "[JC AI] generate_problem_titles CACHE HIT — returning " . count( $cached ) . " cached titles" );
                return $cached;
            }
        }

        // Build prompt.
        $prompt = $this->build_problem_titles_prompt( $args );

        // Log the full prompt for debugging title generation issues.
        // error_log( "[JC AI] generate_problem_titles PROMPT START >>>>" );
        // error_log( $prompt );
        // error_log( "[JC AI] generate_problem_titles PROMPT END <<<<" );
        
        // Call Gemini API with higher token limit for structured multi-object response.
        error_log( "[JC AI] generate_problem_titles API CALL — sending to Gemini ({$this->model})" );
        $response = $this->call_gemini_api( $prompt );

        if ( is_wp_error( $response ) ) {
            error_log( "[JC AI] generate_problem_titles API ERROR — " . $response->get_error_code() . ': ' . $response->get_error_message() );
            return $response;
        }

        // Log raw response for debugging truncation/parse issues.
        // error_log( "[JC AI] generate_problem_titles RAW RESPONSE (" . strlen( $response ) . " bytes): " . substr( $response, 0, 500 ) );

        // Parse response into title array.
        $titles = $this->parse_titles_response( $response, 'problems' );

        if ( is_wp_error( $titles ) ) {
            error_log( "[JC AI] generate_problem_titles PARSE ERROR — " . $titles->get_error_message() );
            return $titles;
        }

        // Trim to max — no padding, return only what the LLM produced.
        $titles = array_slice( $titles, 0, self::MAX_PROBLEM_TITLES );

        error_log( "[JC AI] generate_problem_titles OK — {$service_area}, returned " . count( $titles ) . " titles" );

        // Cache the result.
        set_transient( $cache_key, $titles, self::CACHE_DURATION );

        return $titles;
    }

    /**
     * Generate solution title recommendations for a specific problem.
     *
     * Given a problem title and brain content, generates 3 solution titles.
     *
     * @since 2.0.0
     *
     * @param array $args {
     *     Arguments for solution title generation.
     *
     *     @type int    $problem_id       Problem ID.
     *     @type string $problem_title    Problem title text.
     *     @type string $service_area_name Service area name for context.
     *     @type array  $brain_content    Array of brain content items.
     *     @type array  $industries       Industry names for context.
     *     @type bool   $force_refresh    Skip cache if true.
     * }
     * @return array|WP_Error Array of title strings on success, WP_Error on failure.
     */
    public function generate_solution_titles( $args ) {
        $defaults = array(
            'problem_id'        => 0,
            'problem_title'     => '',
            'service_area_name' => '',
            'brain_content'     => array(),
            'existing_assets'   => array(),
            'industries'        => array(),
            'force_refresh'     => false,
        );

        $args = wp_parse_args( $args, $defaults );

        $problem_short = substr( $args['problem_title'], 0, 60 );
        $force         = $args['force_refresh'] ? 'yes' : 'no';

        // Validate inputs.
        if ( empty( $args['problem_title'] ) ) {
            error_log( "[JC AI] generate_solution_titles FAIL — missing problem_title" );
            return new WP_Error(
                'missing_problem_title',
                __( 'Problem title is required to generate solution titles.', 'directreach' )
            );
        }

        error_log( "[JC AI] generate_solution_titles START — problem=\"{$problem_short}\", force_refresh={$force}" );

        // Build cache key once — needed for both force-refresh deletion and normal lookup.
        $cache_key = $this->build_cache_key( 'solution_titles', $args );

        if ( $args['force_refresh'] ) {
            delete_transient( $cache_key );
        }

        // Check cache unless force refresh.
        if ( ! $args['force_refresh'] ) {
            $cached = get_transient( $cache_key );
            if ( false !== $cached ) {
                error_log( "[JC AI] generate_solution_titles CACHE HIT — returning " . count( $cached ) . " cached titles" );
                return $cached;
            }
        }

        // Build prompt.
        $prompt = $this->build_solution_titles_prompt( $args );

        // Call Gemini API with higher token limit for structured response.
        error_log( "[JC AI] generate_solution_titles API CALL — sending to Gemini ({$this->model})" );
        $response = $this->call_gemini_api( $prompt );

        if ( is_wp_error( $response ) ) {
            error_log( "[JC AI] generate_solution_titles API ERROR — " . $response->get_error_code() . ': ' . $response->get_error_message() );
            return $response;
        }

        // Parse response.
        $titles = $this->parse_titles_response( $response, 'solutions' );

        if ( is_wp_error( $titles ) ) {
            error_log( "[JC AI] generate_solution_titles PARSE ERROR — " . $titles->get_error_message() );
            return $titles;
        }

        // Trim to max — no padding, return only what the LLM produced.
        $titles = array_slice( $titles, 0, self::SOLUTION_TITLES_COUNT );

        error_log( "[JC AI] generate_solution_titles OK — problem=\"{$problem_short}\", returned " . count( $titles ) . " titles" );

        // Cache the result.
        set_transient( $cache_key, $titles, self::CACHE_DURATION );

        return $titles;
    }

    /**
     * Check if the Gemini API key is configured.
     *
     * @since 2.0.0
     *
     * @return bool True if API key exists.
     */
    public function is_configured() {
        return ! empty( $this->api_key );
    }

    /**
     * Get the last error message.
     *
     * @since 2.0.0
     *
     * @return string Last error message.
     */
    public function get_last_error() {
        return $this->last_error;
    }

    /**
     * Clear cached titles for a specific context.
     *
     * @since 2.0.0
     *
     * @param string $type 'problem_titles' or 'solution_titles'.
     * @param array  $args Arguments used for generation (for cache key).
     * @return bool True if cache was deleted.
     */
    public function clear_cache( $type, $args ) {
        $cache_key = $this->build_cache_key( $type, $args );
        return delete_transient( $cache_key );
    }

    // =========================================================================
    // PROMPT BUILDING
    // =========================================================================

    /**
     * Build the prompt for problem title generation.
     *
     * @param array $args Generation arguments.
     * @return string The constructed prompt.
     */
    private function build_problem_titles_prompt( $args ) {
        $brain_summary  = $this->summarize_brain_content( $args['brain_content'] );
        $assets_summary = $this->summarize_existing_assets( $args['existing_assets'] ?? array() );
        $industries     = $this->format_industries( $args['industries'] );
        $service_area   = sanitize_text_field( $args['service_area_name'] );

        // Build negative constraints block if previous titles were provided.
        $constraints_block = '';
        if ( ! empty( $args['previous_titles'] ) && is_array( $args['previous_titles'] ) ) {
            $previous_list = array();
            foreach ( $args['previous_titles'] as $prev_title ) {
                $clean = is_array( $prev_title ) ? ( $prev_title['title'] ?? '' ) : $prev_title;
                $clean = sanitize_text_field( $clean );
                if ( ! empty( $clean ) ) {
                    $previous_list[] = '  * ' . $clean;
                }
            }
            if ( ! empty( $previous_list ) ) {
                $constraints_block = "- **Negative Constraints (Do NOT use or closely paraphrase):**\n" . implode( "\n", $previous_list );
            }
        }

    $prompt = <<<PROMPT
### ROLE
You are a Senior Content Strategist specializing in B2B Demand Generation for Professional & Business Services. Your expertise is identifying "unspoken" business pains and framing them as compelling content hooks that make executives uncomfortable enough to take action.

### TASK
Analyze the provided Source Material and generate exactly 10 unique "Problem-Centric" titles. These titles will serve as the foundation for a Content Marketing Journey (Top-of-Funnel).

### CONTEXT & KNOWLEDGE BASE
- **Primary Service Area:** {$service_area}
- **Target Industries:** {$industries}
- **Core Insights (Brain Content):**
{$brain_summary}
- **Existing Content Assets:**
{$assets_summary}
{$constraints_block}

### TITLE CONSTRUCTION RULES (The "Framework")
Every title must follow one of these four psychological "angles":
1. **The Cost of Inaction:** The hidden financial or operational drain of the status quo.
2. **The Opportunity Gap:** What the firm is losing to competitors who leverage {$service_area}.
3. **The Executive Friction:** How this problem creates personal stress or risk for decision-makers.
4. **The Scalability Wall:** Why current manual processes will break during the next growth phase.

### TONE & PROVOCATION DEVICES
Every title must pass the "Coffee Spiller" Test — would a Partner scrolling LinkedIn stop because this headline feels like an indictment of their current strategy? To achieve this, each title MUST use one of these provocation devices:

1. **The Rhetorical Question:** Forces the reader to confront an uncomfortable truth.
   → "Why are your best clients leaving before you even know they're unhappy?"
2. **The Direct Accusation ("You" Statement):** Makes it personal and impossible to ignore.
   → "Your pipeline is a fiction — and your board is about to find out"
3. **The Shocking Frame:** Anchors the problem to a tangible, visceral cost or "final straw" moment.
   → "That 30% proposal win rate is silently draining \$1.5M from your bottom line"
4. **The Contrarian / Myth-Buster:** Challenges a commonly held belief or sacred cow.
   → "More leads won't save you — your conversion problem starts after the first call"

### REQUIREMENTS FOR HIGH-PUNCH TITLES
1. **Title Format Mix:** At least 5 of the 10 titles MUST be phrased as questions. The remaining may be declarative but must still use a provocation device above.
2. **Zero "Corporate Speak":** Ban generic phrasing like "inefficient processes," "struggling to," "challenges with," or "difficulty in." Use visceral language: "operational paralysis," "revenue hemorrhaging," "talent exodus," "pipeline fiction."
3. **Outcome-Centric Fear:** Don't just mention the problem — name the "final straw" consequence: losing a Tier-1 client, a partner exodus, a failed merger, a missed acquisition, a board revolt.
4. **Perspectives:** Frame titles as revelations or indictments — "The terrifying cost of...," "The realization that...," "What nobody tells you about..." — never as flat observations.
5. **Industry-Specific Language:** Reference real Professional Services pain points: The Bench (underutilized staff), non-billable burn, equity dilution, pitch fatigue, RFP treadmill, realization rate collapse, client churn, utilization death spiral, partner-led growth stalls.
6. **Voice:** Write as if a frustrated CEO is venting to a trusted advisor — raw, specific, unfiltered.
7. **Length:** 10–20 words. Avoid "The Importance of..." or "How to..." (those are solution titles, not problem titles).
8. **No Repeats:** Each title must attack a different internal silo (Finance, Ops, Sales, Talent, Technology, Compliance, Growth, Client Retention, etc.).
9. **Distribute Angles:** Use each of the 4 framework angles at least twice across the 10 titles.
10. **Distribute Devices:** Use each of the 4 provocation devices at least twice across the 10 titles.

### WEAK vs. STRONG — Do NOT generate titles like the "Weak" column
| Weak (vague, passive, corporate) | Strong (specific, visceral, provocative) |
|---|---|
| Difficulty scaling pipeline processes across teams | Why does every growth spurt break your pipeline — and who pays the price? |
| Struggling to attract high-value clients | Your competitors are closing your dream clients while you're still "building relationships" |
| Poor alignment between strategy and objectives | The strategy deck looks great — so why is revenue still flat? |
| Lack of visibility into performance metrics | You can't manage what you can't see — and right now your pipeline is a black box |
| High turnover in service delivery teams | The terrifying cost of losing three senior consultants in one quarter |

### RESPONSE FORMAT
Return ONLY a valid JSON object with this exact structure — no markdown, no code fences, no explanation:
{
  "titles": [
    {
      "title": "Problem title string here",
      "angle": "Cost of Inaction | Opportunity Gap | Executive Friction | Scalability Wall",
      "device": "Rhetorical Question | Direct Accusation | Shocking Frame | Contrarian",
      "rationale": "Why this specific pain point keeps a target industry leader awake at night."
    }
  ]
}

CRITICAL: You MUST return exactly 10 objects in the "titles" array, no fewer. Each title must be unique, each rationale must be specific to that problem, and the "angle" and "device" fields must match the framework options listed above.
PROMPT;

        return $prompt;
    }

    /**
     * Build the prompt for solution title generation.
     *
     * @param array $args Generation arguments.
     * @return string The constructed prompt.
     */
    private function build_solution_titles_prompt( $args ) {
        $brain_summary  = $this->summarize_brain_content( $args['brain_content'] );
        $assets_summary = $this->summarize_existing_assets( $args['existing_assets'] ?? array() );
        $problem_title  = sanitize_text_field( $args['problem_title'] );
        $service_area   = sanitize_text_field( $args['service_area_name'] );
        $industries     = $this->format_industries( $args['industries'] );

        // Build exclusion instruction if exclude_titles were provided.
        $exclusion_block = '';
        if ( ! empty( $args['exclude_titles'] ) && is_array( $args['exclude_titles'] ) ) {
            $exclude_list = array();
            foreach ( $args['exclude_titles'] as $ex_title ) {
                $clean = is_array( $ex_title ) ? ( $ex_title['title'] ?? '' ) : $ex_title;
                $clean = sanitize_text_field( $clean );
                if ( ! empty( $clean ) ) {
                    $exclude_list[] = '- ' . $clean;
                }
            }
            if ( ! empty( $exclude_list ) ) {
                $exclusion_block = "\n\nIMPORTANT — DO NOT REPEAT OR CLOSELY PARAPHRASE any of these previously generated solution titles:\n" . implode( "\n", $exclude_list ) . "\n\nGenerate completely NEW solution titles with different strategic angles and phrasing.\n";
            }
        }

        $prompt = <<<PROMPT
You are an expert content marketing strategist specializing in B2B and service-based industries.

TASK: Generate exactly 3 solution titles that address a specific problem.

PROBLEM BEING SOLVED:
"{$problem_title}"

CONTEXT:
- Service Area: {$service_area}
- Target Industries: {$industries}
- Source Material (Brain Content):
{$brain_summary}
- Existing Content Assets:
{$assets_summary}
{$exclusion_block}

REQUIREMENTS FOR SOLUTION TITLES:
1. Each title should present a clear, actionable solution approach to the stated problem
2. Solutions should be distinct from each other — offer genuinely different strategic angles
3. Write from the perspective of a trusted advisor proposing solutions
4. Make titles content-marketing friendly — each should work as the basis for solution-focused content
5. Be specific enough to be compelling but broad enough to generate multiple content pieces
6. Use confident, authoritative language that inspires trust
7. Keep titles concise but descriptive (8-15 words each)
8. Do NOT include numbering or bullet points in the titles themselves

RESPONSE FORMAT:
Return ONLY a valid JSON object with this exact structure:
{
  "titles": [
    {"title": "First solution title here", "rationale": "Brief 1-2 sentence explanation of why this solution approach addresses the problem effectively."},
    {"title": "Second solution title here", "rationale": "Brief 1-2 sentence explanation."},
    {"title": "Third solution title here", "rationale": "Brief 1-2 sentence explanation."}
  ]
}

Each "rationale" should be a brief 1-2 sentence explanation of why this solution was recommended — what makes it effective for the stated problem and target audience.

Return ONLY the JSON object. No markdown, no code fences, no explanation.
PROMPT;

        return $prompt;
    }

    // =========================================================================
    // API COMMUNICATION
    // =========================================================================

    /**
     * Call the Gemini API with a prompt.
     *
     * @param string $prompt The prompt text.
     * @return string|WP_Error Response text on success, WP_Error on failure.
     */
    private function call_gemini_api( $prompt, $options = array() ) {
        if ( ! $this->is_configured() ) {
            $this->last_error = __( 'Gemini API key is not configured. Please set it in DirectReach AI Settings.', 'directreach' );
            return new WP_Error( 'api_not_configured', $this->last_error );
        }

        $url = self::API_BASE_URL . $this->model . ':generateContent?key=' . $this->api_key;

        // Merge caller overrides into generation config.
        $gen_config = array(
            'temperature'     => 0.8,
            'topP'            => 0.9,
            'topK'            => 40,
            'maxOutputTokens' => isset( $options['maxOutputTokens'] ) ? (int) $options['maxOutputTokens'] : 16384,
        );

        // Only set responseMimeType when we genuinely need structured JSON.
        // For HTML or free-text content, omitting it avoids Gemini wrapping/escaping output.
        if ( ! isset( $options['responseMimeType'] ) || $options['responseMimeType'] !== 'none' ) {
            $gen_config['responseMimeType'] = isset( $options['responseMimeType'] ) ? $options['responseMimeType'] : 'application/json';
        }

        $body = array(
            'contents' => array(
                array(
                    'parts' => array(
                        array(
                            'text' => $prompt,
                        ),
                    ),
                ),
            ),
            'generationConfig' => $gen_config,
            'safetySettings' => array(
                array(
                    'category'  => 'HARM_CATEGORY_HARASSMENT',
                    'threshold' => 'BLOCK_NONE',
                ),
                array(
                    'category'  => 'HARM_CATEGORY_HATE_SPEECH',
                    'threshold' => 'BLOCK_NONE',
                ),
                array(
                    'category'  => 'HARM_CATEGORY_SEXUALLY_EXPLICIT',
                    'threshold' => 'BLOCK_NONE',
                ),
                array(
                    'category'  => 'HARM_CATEGORY_DANGEROUS_CONTENT',
                    'threshold' => 'BLOCK_NONE',
                ),
            ),
        );

        $response = wp_remote_post( $url, array(
            'headers' => array(
                'Content-Type' => 'application/json',
            ),
            'body'    => wp_json_encode( $body ),
            'timeout' => isset( $options['timeout'] ) ? (int) $options['timeout'] : self::API_TIMEOUT,
        ) );

        // Handle connection errors.
        if ( is_wp_error( $response ) ) {
            $this->last_error = sprintf(
                /* translators: %s: error message */
                __( 'Failed to connect to Gemini API: %s', 'directreach' ),
                $response->get_error_message()
            );

            // Check for timeout specifically.
            if ( strpos( $response->get_error_message(), 'timed out' ) !== false
                || strpos( $response->get_error_message(), 'timeout' ) !== false ) {
                return new WP_Error(
                    'api_timeout',
                    __( 'The AI request timed out. Please try again.', 'directreach' )
                );
            }

            return new WP_Error( 'api_connection_error', $this->last_error );
        }

        // Check HTTP status code.
        $status_code = wp_remote_retrieve_response_code( $response );

        if ( $status_code !== 200 ) {
            $body_raw = wp_remote_retrieve_body( $response );
            $error_data = json_decode( $body_raw, true );

            $error_message = isset( $error_data['error']['message'] )
                ? $error_data['error']['message']
                : sprintf(
                    /* translators: %d: HTTP status code */
                    __( 'Gemini API returned status %d', 'directreach' ),
                    $status_code
                );

            $this->last_error = $error_message;

            // Map common HTTP errors.
            $error_code_map = array(
                400 => 'api_bad_request',
                401 => 'api_unauthorized',
                403 => 'api_forbidden',
                429 => 'api_rate_limited',
                500 => 'api_server_error',
                503 => 'api_unavailable',
            );

            $wp_error_code = isset( $error_code_map[ $status_code ] )
                ? $error_code_map[ $status_code ]
                : 'api_error';

            // Provide user-friendly messages for common errors.
            $user_messages = array(
                'api_unauthorized' => __( 'The Gemini API key is invalid. Please check your AI settings.', 'directreach' ),
                'api_forbidden'    => __( 'Access denied by Gemini API. Please verify your API key permissions.', 'directreach' ),
                'api_rate_limited' => __( 'Too many AI requests. Please wait a moment and try again.', 'directreach' ),
                'api_unavailable'  => __( 'The AI service is temporarily unavailable. Please try again later.', 'directreach' ),
            );

            $user_message = isset( $user_messages[ $wp_error_code ] )
                ? $user_messages[ $wp_error_code ]
                : $error_message;

            return new WP_Error( $wp_error_code, $user_message, array(
                'status'    => $status_code,
                'raw_error' => $error_message,
            ) );
        }

        // Parse successful response.
        $body_raw  = wp_remote_retrieve_body( $response );
        $body_data = json_decode( $body_raw, true );

        if ( json_last_error() !== JSON_ERROR_NONE ) {
            $this->last_error = __( 'Failed to parse Gemini API response.', 'directreach' );
            return new WP_Error( 'api_parse_error', $this->last_error );
        }

        // Extract text from Gemini response structure.
        $text = $this->extract_gemini_text( $body_data );

        if ( is_wp_error( $text ) ) {
            return $text;
        }

        return $text;
    }

    /**
     * Extract text content from Gemini API response structure.
     *
     * @param array $body_data Decoded response body.
     * @return string|WP_Error Extracted text or error.
     */
    private function extract_gemini_text( $body_data ) {
        // Standard Gemini response structure:
        // candidates[0].content.parts[0].text
        if ( isset( $body_data['candidates'][0]['content']['parts'][0]['text'] ) ) {
            return $body_data['candidates'][0]['content']['parts'][0]['text'];
        }

        // Check for blocked content.
        if ( isset( $body_data['candidates'][0]['finishReason'] )
            && $body_data['candidates'][0]['finishReason'] === 'SAFETY' ) {
            $this->last_error = __( 'The AI flagged the request as potentially unsafe. Please try different input.', 'directreach' );
            return new WP_Error( 'ai_safety_blocked', $this->last_error );
        }

        // Check for prompt feedback blocking.
        if ( isset( $body_data['promptFeedback']['blockReason'] ) ) {
            $this->last_error = sprintf(
                /* translators: %s: block reason */
                __( 'The AI blocked this request: %s', 'directreach' ),
                $body_data['promptFeedback']['blockReason']
            );
            return new WP_Error( 'ai_prompt_blocked', $this->last_error );
        }

        // Unexpected response structure.
        $this->last_error = __( 'Unexpected response format from Gemini API.', 'directreach' );
        return new WP_Error( 'api_unexpected_response', $this->last_error );
    }

    // =========================================================================
    // RESPONSE PARSING
    // =========================================================================

    /**
     * Parse the AI response text into an array of titles.
     *
     * Handles JSON parsing with multiple fallback strategies.
     *
     * @param string $response_text Raw response text from AI.
     * @param string $type          'problems' or 'solutions'.
     * @return array|WP_Error Array of title strings or error.
     */
    private function parse_titles_response( $response_text, $type ) {
        $raw_len = strlen( $response_text );
        error_log( "[JC AI] parse_titles_response — type={$type}, raw_length={$raw_len}" );

        // Strategy 1: Direct JSON parse.
        $data = json_decode( $response_text, true );

        if ( json_last_error() === JSON_ERROR_NONE && isset( $data['titles'] ) && is_array( $data['titles'] ) ) {
            $raw_count = count( $data['titles'] );
            $result    = $this->sanitize_titles( $data['titles'] );
            $clean_count = count( $result );
            error_log( "[JC AI] parse_titles_response OK (strategy=json) — raw_titles={$raw_count}, after_sanitize={$clean_count}" );
            return $result;
        }

        // Strategy 2: Extract JSON from markdown code fences.
        $cleaned = $this->extract_json_from_text( $response_text );
        if ( $cleaned ) {
            $data = json_decode( $cleaned, true );
            if ( json_last_error() === JSON_ERROR_NONE && isset( $data['titles'] ) && is_array( $data['titles'] ) ) {
                $raw_count = count( $data['titles'] );
                $result    = $this->sanitize_titles( $data['titles'] );
                $clean_count = count( $result );
                error_log( "[JC AI] parse_titles_response OK (strategy=extract) — raw_titles={$raw_count}, after_sanitize={$clean_count}" );
                return $result;
            }
        }

        // Strategy 3: Try to find a JSON array in the response.
        if ( preg_match( '/\[([^\]]+)\]/', $response_text, $matches ) ) {
            $array_str = '[' . $matches[1] . ']';
            $titles    = json_decode( $array_str, true );
            if ( json_last_error() === JSON_ERROR_NONE && is_array( $titles ) ) {
                $raw_count = count( $titles );
                $result    = $this->sanitize_titles( $titles );
                $clean_count = count( $result );
                error_log( "[JC AI] parse_titles_response OK (strategy=array) — raw_titles={$raw_count}, after_sanitize={$clean_count}" );
                return $result;
            }
        }

        // Strategy 4: Line-by-line parsing as last resort.
        $lines  = explode( "\n", $response_text );
        $titles = array();
        foreach ( $lines as $line ) {
            $line = trim( $line );
            // Remove numbering like "1.", "1)", "- ", "* ".
            $line = preg_replace( '/^[\d]+[\.\)]\s*/', '', $line );
            $line = preg_replace( '/^[-\*]\s*/', '', $line );
            // Remove surrounding quotes.
            $line = trim( $line, '"\'`' );
            $line = trim( $line );

            if ( strlen( $line ) > 10 && strlen( $line ) < 200 ) {
                $titles[] = $line;
            }
        }

        if ( ! empty( $titles ) ) {
            $raw_count = count( $titles );
            $result    = $this->sanitize_titles( $titles );
            $clean_count = count( $result );
            error_log( "[JC AI] parse_titles_response OK (strategy=lines) — raw_lines={$raw_count}, after_sanitize={$clean_count}" );
            return $result;
        }

        // All parsing strategies failed.
        error_log( "[JC AI] parse_titles_response FAIL — all strategies exhausted, raw preview: " . substr( $response_text, 0, 200 ) );
        $this->last_error = __( 'Could not parse AI response into titles. Please try regenerating.', 'directreach' );
        return new WP_Error( 'parse_error', $this->last_error, array(
            'raw_response' => substr( $response_text, 0, 500 ),
        ) );
    }

    /**
     * Extract JSON string from text that may contain markdown fences.
     *
     * @param string $text Raw text.
     * @return string|false Extracted JSON string or false.
     */
    private function extract_json_from_text( $text ) {
        // Try ```json ... ``` pattern.
        if ( preg_match( '/```(?:json)?\s*(\{[\s\S]*?\})\s*```/', $text, $matches ) ) {
            return $matches[1];
        }

        // Try to find first { ... } block.
        $start = strpos( $text, '{' );
        $end   = strrpos( $text, '}' );

        if ( $start !== false && $end !== false && $end > $start ) {
            return substr( $text, $start, $end - $start + 1 );
        }

        return false;
    }

    /**
     * Sanitize an array of title strings.
     *
     * @param array $titles Raw title strings.
     * @return array Sanitized titles.
     */
    private function sanitize_titles( $titles ) {
        $sanitized = array();
        foreach ( $titles as $title ) {
            // Handle {title, rationale} objects from updated prompts.
            if ( is_array( $title ) && isset( $title['title'] ) ) {
                $t = $title['title'];
                // Guard against Gemini returning nested JSON fragments as title values.
                if ( is_string( $t ) && preg_match( '/^title"\s*:\s*"/', $t ) ) {
                    // Strip the JSON key prefix: title": "actual title",
                    $t = preg_replace( '/^title"\s*:\s*"/', '', $t );
                    $t = rtrim( $t, '",' );
                }
                $t = sanitize_text_field( $t );
                $t = trim( $t, '"\'`' );
                $t = trim( $t );

                // Reject JSON artifacts: anything that looks like a key-value fragment.
                if ( $this->is_json_fragment( $t ) ) {
                    continue;
                }

                if ( strlen( $t ) < 10 || strlen( $t ) > 200 ) {
                    continue;
                }

                $item = array( 'title' => $t );

                if ( ! empty( $title['angle'] ) ) {
                    $a = sanitize_text_field( $title['angle'] );
                    if ( ! $this->is_json_fragment( $a ) ) {
                        $item['angle'] = $a;
                    }
                }

                if ( ! empty( $title['device'] ) ) {
                    $d = sanitize_text_field( $title['device'] );
                    if ( ! $this->is_json_fragment( $d ) ) {
                        $item['device'] = $d;
                    }
                }

                if ( ! empty( $title['rationale'] ) ) {
                    $r = sanitize_text_field( $title['rationale'] );
                    // Don't store rationale if it's also a JSON artifact.
                    if ( ! $this->is_json_fragment( $r ) ) {
                        $item['rationale'] = $r;
                    }
                }

                $sanitized[] = $item;
                continue;
            }

            // Handle plain strings (backward compat / fallback).
            if ( ! is_string( $title ) ) {
                continue;
            }

            // Guard: Gemini sometimes returns stringified JSON fragments as title values.
            if ( preg_match( '/^(title|rationale)"\s*:\s*"(.+)$/s', $title, $frag ) ) {
                $key = $frag[1];
                $val = rtrim( $frag[2], '",. ' );
                if ( $key === 'rationale' ) {
                    continue;
                }
                $title = $val;
            }

            $title = sanitize_text_field( $title );
            $title = trim( $title, '"\'\`' );
            $title = trim( $title );

            // Reject JSON artifacts.
            if ( $this->is_json_fragment( $title ) ) {
                continue;
            }

            // Skip empty or too-short titles.
            if ( strlen( $title ) < 10 ) {
                continue;
            }

            // Skip duplicates.
            $existing_titles = array_map( function( $s ) {
                return is_array( $s ) ? $s['title'] : $s;
            }, $sanitized );
            if ( ! in_array( $title, $existing_titles, true ) ) {
                $sanitized[] = $title;
            }
        }
        return $sanitized;
    }

    /**
     * Check if a string looks like a JSON key/value fragment rather than real content.
     *
     * Catches things like: 'rationale":', '"title": "..."', '{ "title"', etc.
     *
     * @param string $text Text to check.
     * @return bool True if it appears to be a JSON artifact.
     */
    private function is_json_fragment( $text ) {
        $t = trim( $text );
        // Matches: word":\s  or  "word":\s  (JSON key patterns)
        if ( preg_match( '/^"?\w+"\s*:/', $t ) ) {
            return true;
        }
        // Matches: starts with { or [ (partial JSON object/array)
        if ( preg_match( '/^\s*[\{\[]/', $t ) ) {
            return true;
        }
        // Matches: ends with a dangling JSON key like  ..."rationale":
        if ( preg_match( '/"\s*:\s*$/', $t ) ) {
            return true;
        }
        return false;
    }

    // =========================================================================
    // BRAIN CONTENT PROCESSING
    // =========================================================================

    /**
     * Summarize brain content into a text block for prompts.
     *
     * Uses extracted/summarized text when available, falling back to
     * metadata for items that haven't been processed yet.
     *
     * @param array $brain_content Array of brain content items.
     * @return string Summarized text.
     */
    private function summarize_brain_content( $brain_content ) {
        if ( empty( $brain_content ) || ! is_array( $brain_content ) ) {
            return '(No source material provided)';
        }

        $parts      = array();
        $url_count  = 0;
        $text_count = 0;
        $file_count = 0;

        foreach ( $brain_content as $item ) {
            if ( ! is_array( $item ) ) {
                continue;
            }

            $type           = isset( $item['type'] ) ? $item['type'] : '';
            $value          = isset( $item['value'] ) ? $item['value'] : '';
            $extracted_text = isset( $item['extracted_text'] ) ? trim( $item['extracted_text'] ) : '';

            switch ( $type ) {
                case 'url':
                    $url_count++;
                    if ( ! empty( $extracted_text ) ) {
                        $parts[] = '- Content from URL (' . esc_url( $value ) . "):\n  " . $extracted_text;
                    } else {
                        $parts[] = '- Reference URL (content not yet extracted): ' . esc_url( $value );
                    }
                    break;

                case 'text':
                    $text_count++;
                    // Use extracted summary if available, otherwise use raw value.
                    $content = ! empty( $extracted_text ) ? $extracted_text : wp_strip_all_tags( $value );
                    if ( strlen( $content ) > 2000 ) {
                        $content = substr( $content, 0, 2000 ) . '... (truncated)';
                    }
                    $parts[] = '- Pasted content: ' . $content;
                    break;

                case 'file':
                    $file_count++;
                    $filename = isset( $item['filename'] ) ? $item['filename'] : $value;
                    if ( ! empty( $extracted_text ) ) {
                        $parts[] = '- Content from file (' . sanitize_file_name( $filename ) . "):\n  " . $extracted_text;
                    } else {
                        $parts[] = '- Uploaded file (content not yet extracted): ' . sanitize_file_name( $filename );
                    }
                    break;
            }
        }

        if ( empty( $parts ) ) {
            return '(No usable source material)';
        }

        $summary = implode( "\n", $parts );

        // Add count summary.
        $counts = array();
        if ( $url_count > 0 ) {
            $counts[] = sprintf( _n( '%d URL', '%d URLs', $url_count, 'directreach' ), $url_count );
        }
        if ( $text_count > 0 ) {
            $counts[] = sprintf( _n( '%d text block', '%d text blocks', $text_count, 'directreach' ), $text_count );
        }
        if ( $file_count > 0 ) {
            $counts[] = sprintf( _n( '%d file', '%d files', $file_count, 'directreach' ), $file_count );
        }

        $header = sprintf(
            __( 'Source material includes: %s', 'directreach' ),
            implode( ', ', $counts )
        );

        return $header . "\n" . $summary;
    }
    
    /**
     * Summarize existing content assets into a readable string for prompts.
     *
     * Uses extracted text when available so the AI understands what
     * content already exists and can avoid duplication.
     *
     * @param array $existing_assets Array of existing asset items.
     * @return string Summarized text.
     */
    private function summarize_existing_assets( $existing_assets ) {
        if ( empty( $existing_assets ) || ! is_array( $existing_assets ) ) {
            return '(No existing content assets provided)';
        }

        $parts      = array();
        $url_count  = 0;
        $file_count = 0;

        foreach ( $existing_assets as $item ) {
            if ( ! is_array( $item ) ) {
                continue;
            }

            $type           = isset( $item['type'] ) ? $item['type'] : '';
            $name           = isset( $item['name'] ) ? sanitize_text_field( $item['name'] ) : '';
            $value          = isset( $item['value'] ) ? $item['value'] : '';
            $extracted_text = isset( $item['extracted_text'] ) ? trim( $item['extracted_text'] ) : '';

            switch ( $type ) {
                case 'url':
                    $url_count++;
                    $display = $name ? $name : esc_url( $value );
                    if ( ! empty( $extracted_text ) ) {
                        $parts[] = '- Existing content: ' . $display . "\n  Summary: " . $extracted_text;
                    } else {
                        $parts[] = '- Existing content URL: ' . $display . ( $name && $value ? ' (' . esc_url( $value ) . ')' : '' );
                    }
                    break;

                case 'file':
                    $file_count++;
                    $filename = $name ? $name : ( isset( $item['filename'] ) ? sanitize_file_name( $item['filename'] ) : $value );
                    if ( ! empty( $extracted_text ) ) {
                        $parts[] = '- Existing file: ' . $filename . "\n  Summary: " . $extracted_text;
                    } else {
                        $mime    = isset( $item['mimeType'] ) ? sanitize_text_field( $item['mimeType'] ) : '';
                        $parts[] = '- Existing file: ' . $filename . ( $mime ? ' (' . $mime . ')' : '' );
                    }
                    break;

                default:
                    if ( ! empty( $value ) ) {
                        $text = ! empty( $extracted_text ) ? $extracted_text : wp_strip_all_tags( $value );
                        if ( strlen( $text ) > 1000 ) {
                            $text = substr( $text, 0, 1000 ) . '... (truncated)';
                        }
                        $parts[] = '- Existing content: ' . $text;
                    }
                    break;
            }
        }

        if ( empty( $parts ) ) {
            return '(No usable existing content assets)';
        }

        $summary = implode( "\n", $parts );

        $counts = array();
        if ( $url_count > 0 ) {
            $counts[] = sprintf( '%d existing URL%s', $url_count, $url_count > 1 ? 's' : '' );
        }
        if ( $file_count > 0 ) {
            $counts[] = sprintf( '%d existing file%s', $file_count, $file_count > 1 ? 's' : '' );
        }

        $header = 'Existing content assets: ' . ( ! empty( $counts ) ? implode( ', ', $counts ) : 'see below' );

        return $header . "\n" . $summary;
    }   

    /**
     * Format industries array into a readable string for prompts.
     *
     * @param array $industries Industry names or IDs.
     * @return string Formatted industries string.
     */
    private function format_industries( $industries ) {
        if ( empty( $industries ) || ! is_array( $industries ) ) {
            return 'General / All industries';
        }

        $names = array();
        foreach ( $industries as $industry ) {
            if ( is_numeric( $industry ) ) {
                // Try to get term name from taxonomy.
                $term = get_term( intval( $industry ), 'jc_industry' );
                if ( $term && ! is_wp_error( $term ) ) {
                    $names[] = $term->name;
                } else {
                    $names[] = 'Industry #' . intval( $industry );
                }
            } else {
                $names[] = sanitize_text_field( $industry );
            }
        }

        return implode( ', ', $names );
    }

    // =========================================================================
    // CACHE MANAGEMENT
    // =========================================================================

    /**
     * Build a cache key from generation arguments.
     *
     * @param string $type 'problem_titles' or 'solution_titles'.
     * @param array  $args Generation arguments.
     * @return string Cache key.
     */
    private function build_cache_key( $type, $args ) {
        $key_data = array(
            'type'  => $type,
            'model' => $this->model,
        );

        switch ( $type ) {
            case 'problem_titles':
                $key_data['sa']         = $args['service_area_id'] ?? $args['service_area_name'] ?? '';
                $key_data['industries'] = is_array( $args['industries'] ) ? implode( ',', $args['industries'] ) : '';
                $key_data['brain']      = $this->hash_brain_content( $args['brain_content'] ?? array() );
                $key_data['assets']     = $this->hash_brain_content( $args['existing_assets'] ?? array() );

                break;

            case 'solution_titles':
                $key_data['problem'] = $args['problem_title'] ?? '';
                $key_data['brain']   = $this->hash_brain_content( $args['brain_content'] ?? array() );
                $key_data['assets']  = $this->hash_brain_content( $args['existing_assets'] ?? array() );

                break;
        }

        $hash = md5( wp_json_encode( $key_data ) );
        return 'dr_ai_' . $type . '_' . $hash;
    }

    /**
     * Generate a hash of brain content for cache key purposes.
     *
     * @param array $brain_content Brain content array.
     * @return string MD5 hash.
     */
    private function hash_brain_content( $brain_content ) {
        if ( empty( $brain_content ) ) {
            return 'empty';
        }
        return md5( wp_json_encode( $brain_content ) );
    }

    // =========================================================================
    // UTILITY METHODS
    // =========================================================================

    /**
     * Load the Gemini API key from WordPress options.
     */
    private function load_api_key() {
        // Ensure the Campaign Builder's AI Settings Manager class is available.
        if ( ! class_exists( 'CPD_AI_Settings_Manager' ) ) {
            // Try using the Campaign Builder constant first.
            if ( defined( 'DR_CB_PLUGIN_DIR' ) ) {
                $cb_settings_file = DR_CB_PLUGIN_DIR . 'includes/class-ai-settings-manager.php';
            } else {
                // Fallback: traverse from this file's location.
                $cb_settings_file = dirname( __FILE__, 4 ) . '/campaign-builder/includes/class-ai-settings-manager.php';
            }
            if ( file_exists( $cb_settings_file ) ) {
                require_once $cb_settings_file;
            }
        }

        // Primary: Use the Campaign Builder's AI Settings Manager (handles encrypted keys).
        if ( class_exists( 'CPD_AI_Settings_Manager' ) ) {
            $settings_manager = new CPD_AI_Settings_Manager();
            $key = $settings_manager->get_api_key();
            if ( ! empty( $key ) ) {
                $this->api_key = $key;
                return;
            }
        }

        // Fallback: check for standalone unencrypted option.
        $standalone_key = get_option( 'dr_gemini_api_key', '' );
        if ( ! empty( $standalone_key ) ) {
            $this->api_key = $standalone_key;
            return;
        }

        // Check Journey Circle specific settings.
        $jc_settings = get_option( 'jc_settings', array() );
        if ( isset( $jc_settings['gemini_api_key'] ) && ! empty( $jc_settings['gemini_api_key'] ) ) {
            $this->api_key = $jc_settings['gemini_api_key'];
        }
    }

    /**
     * Get service area name from ID.
     *
     * @param int $service_area_id Service area post ID.
     * @return string Service area name.
     */
    private function get_service_area_name( $service_area_id ) {
        // Try custom post type.
        $post = get_post( absint( $service_area_id ) );
        if ( $post && ! is_wp_error( $post ) ) {
            return $post->post_title;
        }

        // Fallback: try custom table.
        global $wpdb;
        $table = $wpdb->prefix . 'dr_service_areas';
        $name  = $wpdb->get_var(
            $wpdb->prepare( "SELECT name FROM {$table} WHERE id = %d", $service_area_id )
        );

        return $name ? $name : __( 'Unknown Service Area', 'directreach' );
    }

    // =========================================================================
    // OUTLINE & CONTENT GENERATION (Step 9)
    // =========================================================================

    /**
     * Generate a content outline for a problem/solution pair.
     *
     * @param array $args {
     *     @type string $problem_title   The problem title.
     *     @type string $solution_title  The solution title.
     *     @type string $format          Content format (article_long, article_short, infographic).
     *     @type array  $brain_content   Brain content resources.
     *     @type array  $industries      Target industries.
     *     @type string $existing_outline Existing outline for revision.
     *     @type string $feedback        User feedback for revision.
     * }
     * @return array|WP_Error Array with 'outline' key or WP_Error.
     */
    public function generate_outline( $args ) {
        if ( ! $this->is_configured() ) {
            return new \WP_Error( 'not_configured', 'Gemini API key is not configured.' );
        }

        $problem_title  = sanitize_text_field( $args['problem_title'] ?? '' );
        $solution_title = sanitize_text_field( $args['solution_title'] ?? '' );
        $format         = sanitize_text_field( $args['format'] ?? 'article_long' );
        $brain_summary  = $this->summarize_brain_content( $args['brain_content'] ?? array() );
        $industries_str = $this->format_industries( $args['industries'] ?? array() );
        $existing       = $args['existing_outline'] ?? '';
        $feedback       = sanitize_text_field( $args['feedback'] ?? '' );
        $focus          = sanitize_text_field( $args['focus'] ?? '' );
        $focus_instr    = sanitize_text_field( $args['focus_instruction'] ?? '' );

        $format_desc = array(
            'article_long'   => 'a detailed long-form article (1500-2500 words)',
            'article_short'  => 'a concise short article (500-800 words)',
            'blog_post'      => 'an engaging blog post (500-800 words) with a conversational, accessible tone optimized for web reading',
            'linkedin_post'  => 'a professional LinkedIn post (200-300 words) designed for engagement, with a strong hook, insight, and call-to-action',
            'infographic'    => 'an infographic with data points, statistics, and visual sections',
            'presentation'   => 'a slide deck presentation (10-15 slides) with slide titles, bullet points, speaker notes, and a clear narrative arc',
        );

        $format_label = $format_desc[ $format ] ?? 'a content piece';

        // Build the focus angle instruction.
        $focus_angle = '';
        if ( ! empty( $focus_instr ) ) {
            $focus_angle = "\nContent angle: {$focus_instr}\n";
        } elseif ( $focus === 'problem' ) {
            $focus_angle = "\nContent angle: Focus on PROBLEM — pain points, challenges, consequences, and urgency.\n";
        } elseif ( $focus === 'solution' ) {
            $focus_angle = "\nContent angle: Focus on SOLUTION — approach, benefits, implementation, and ROI.\n";
        }

        // =====================================================================
        // Format-specific JSON schemas for the outline.
        // These must match what the JS _renderOutlineObj() method expects.
        // =====================================================================
        $json_schemas = array(
            'linkedin_post' => '{"hook": "attention-grabbing opening line", "body": ["paragraph 1 summary", "paragraph 2 summary", "paragraph 3 summary"], "call_to_action": "what reader should do next", "hashtags": ["#tag1", "#tag2", "#tag3"]}',
            'presentation'  => '[{"slide_number": 1, "slide_title": "Title Slide", "section": "Title Slide", "key_points": ["point 1", "point 2"], "speaker_notes": "notes for presenter"}, {"slide_number": 2, "slide_title": "...", "section": "Problem Definition", "key_points": ["..."], "speaker_notes": "..."}]',
            'infographic'   => '{"title": "main title", "subtitle": "subtitle", "sections": [{"heading": "section heading", "description": "what this section covers", "data_points": [{"label": "stat label", "value": "stat value"}]}], "call_to_action": "next step for reader"}',
        );

        // Default schema for article/blog formats.
        $default_schema = '{"title": "compelling headline", "meta_description": "SEO meta description (150-160 chars)", "sections": [{"heading": "section heading", "paragraphs": ["key point or topic to cover in this section", "another key point"], "key_takeaway": "main insight from this section"}], "call_to_action": "what reader should do next"}';

        $schema = $json_schemas[ $format ] ?? $default_schema;

        if ( ! empty( $existing ) && ! empty( $feedback ) ) {
            // Serialize existing outline to string if it's an array/object.
            $existing_str = is_string( $existing ) ? $existing : wp_json_encode( $existing, JSON_PRETTY_PRINT );

            // Revision prompt — must return same JSON format.
            $prompt  = "You previously generated this content outline:\n\n{$existing_str}\n\n";
            $prompt .= "The user provided this feedback: \"{$feedback}\"\n\n";
            $prompt .= "Please revise the outline based on the feedback. Keep the same JSON structure but incorporate the requested changes.\n";
            $prompt .= $focus_angle;
            $prompt .= "\nReturn ONLY valid JSON matching the original structure. No markdown, no explanations, no code fences.";
        } else {
            $prompt  = "Create a detailed content outline for {$format_label}.\n\n";
            $prompt .= "Topic/Problem: {$problem_title}\n";
            $prompt .= "Solution Approach: {$solution_title}\n";
            if ( ! empty( $industries_str ) ) {
                $prompt .= "Target Industries: {$industries_str}\n";
            }
            $prompt .= $focus_angle;
            if ( ! empty( $brain_summary ) ) {
                $prompt .= "\nContext from client resources:\n{$brain_summary}\n";
            }
            $prompt .= "\nCreate a comprehensive, detailed outline. Each section should have specific, descriptive key points — not generic placeholders.\n";
            $prompt .= "Include at least 4-6 sections with 2-4 key points each.\n\n";
            $prompt .= "Return ONLY valid JSON in this exact format (no markdown, no code fences, no explanations):\n";
            $prompt .= $schema;
        }

        $result = $this->call_gemini_api( $prompt, array(
            'responseMimeType' => 'application/json',
        ) );

        if ( is_wp_error( $result ) ) {
            return $result;
        }

        // Normalize the result — ensure it's a valid JSON string for the client.
        $outline = trim( $result );

        // If $result is already decoded (array/object), re-encode it.
        if ( is_array( $outline ) || is_object( $outline ) ) {
            $outline = wp_json_encode( $outline );
        }

        // Clean common Gemini JSON quirks: trailing commas before ] or }.
        $outline = preg_replace( '/,\s*([\]\}])/', '$1', $outline );

        // Strip markdown code fences if Gemini wrapped the JSON in them.
        $outline = preg_replace( '/^```(?:json)?\s*/i', '', $outline );
        $outline = preg_replace( '/\s*```\s*$/', '', $outline );
        $outline = trim( $outline );

        // Validate the JSON actually parses and has content.
        $decoded = json_decode( $outline, true );
        if ( json_last_error() !== JSON_ERROR_NONE ) {
            // If Gemini returned non-JSON despite the mime type, wrap it as a text outline.
            // The JS _fmtOutline() text fallback will handle it.
            error_log( 'Journey Circle: Outline response was not valid JSON after cleanup. JSON error: ' . json_last_error_msg() );
            return array( 'outline' => $outline );
        }

        // Re-encode the cleaned/validated JSON to ensure consistent output.
        $outline = wp_json_encode( $decoded );

        // Ensure sections-based outlines actually have sections.
        if ( is_array( $decoded ) && ! isset( $decoded[0] ) && isset( $decoded['title'] ) && ( ! isset( $decoded['sections'] ) || empty( $decoded['sections'] ) ) ) {
            // Re-encode as plain text so the text parser can try, rather than showing only a title.
            error_log( 'Journey Circle: Outline JSON had title but empty sections, returning raw for text parsing.' );
        }

        return array( 'outline' => $outline );
    }

    /**
     * Generate full content from an approved outline.
     *
     * ALL formats now return structured JSON. The client-side renderer
     * parses JSON and builds format-specific previews and downloads.
     *
     * JSON Schemas by format:
     *
     * article_long / blog_post:
     *   { "title": "...", "meta_description": "...", "sections": [
     *       { "heading": "...", "paragraphs": ["...", "..."], "key_takeaway": "..." }
     *   ], "call_to_action": "..." }
     *
     * linkedin_post:
     *   { "hook": "...", "body": ["paragraph1", "paragraph2", ...],
     *     "call_to_action": "...", "hashtags": ["#tag1", "#tag2"] }
     *
     * infographic:
     *   { "title": "...", "subtitle": "...", "sections": [
     *       { "heading": "...", "description": "...",
     *         "data_points": [{"label":"...","value":"..."}],
     *         "visual_element": { "type": "bar_chart|donut_chart|stat_cards|comparison|timeline|progress_bars", "data": {...} } }
     *   ], "footer": "...", "call_to_action": "..." }
     *
     * presentation: (unchanged - already JSON)
     *   [ { "slide_number":1, "slide_title":"...", "section":"...", "key_points":[], "speaker_notes":"...", "data_points":[], "visual_element": null|{...} } ]
     *
     * @param array $args Generation arguments.
     * @return array|WP_Error Array with 'content' key (JSON string) or WP_Error.
     */
    public function generate_content( $args ) {
        if ( ! $this->is_configured() ) {
            return new \WP_Error( 'not_configured', 'Gemini API key is not configured.' );
        }

        $problem_title  = sanitize_text_field( $args['problem_title'] ?? '' );
        $solution_title = sanitize_text_field( $args['solution_title'] ?? '' );
        $format         = sanitize_text_field( $args['format'] ?? 'article_long' );
        $outline        = $args['outline'] ?? '';
        $brain_summary  = $this->summarize_brain_content( $args['brain_content'] ?? array() );
        $industries_str = $this->format_industries( $args['industries'] ?? array() );
        $existing       = $args['existing_content'] ?? '';
        $feedback       = sanitize_text_field( $args['feedback'] ?? '' );
        $focus          = sanitize_text_field( $args['focus'] ?? '' );
        $focus_instr    = sanitize_text_field( $args['focus_instruction'] ?? '' );

        // =====================================================================
        // REVISION PATH (existing content + feedback)
        // =====================================================================
        if ( ! empty( $existing ) && ! empty( $feedback ) ) {
            $prompt = $this->build_revision_prompt( $format, $existing, $feedback, $focus_instr );
        }
        // =====================================================================
        // PRESENTATION — slide deck JSON array
        // =====================================================================
        elseif ( $format === 'presentation' ) {
            $prompt = $this->build_presentation_prompt( $problem_title, $solution_title, $industries_str, $brain_summary, $focus_instr, $outline );
        }
        // =====================================================================
        // LINKEDIN POST — structured post JSON
        // =====================================================================
        elseif ( $format === 'linkedin_post' ) {
            $prompt = $this->build_linkedin_prompt( $problem_title, $solution_title, $industries_str, $brain_summary, $focus_instr, $outline );
        }
        // =====================================================================
        // INFOGRAPHIC — structured sections with visual elements
        // =====================================================================
        elseif ( $format === 'infographic' ) {
            $prompt = $this->build_infographic_prompt( $problem_title, $solution_title, $industries_str, $brain_summary, $focus_instr, $outline );
        }
        // =====================================================================
        // ARTICLE / BLOG POST — structured sections JSON
        // =====================================================================
        else {
            $prompt = $this->build_article_prompt( $format, $problem_title, $solution_title, $industries_str, $brain_summary, $focus_instr, $outline );
        }

        // All formats now use JSON response mode.
        $max_tokens = 16384;
        $api_options = array(
            'maxOutputTokens'  => $max_tokens,
            'responseMimeType' => 'application/json',
            'timeout'          => ( $format === 'presentation' ) ? 60 : 45,
        );

        $result = $this->call_gemini_api( $prompt, $api_options );

        if ( is_wp_error( $result ) ) {
            return $result;
        }

        // Clean up any markdown code fences (```json, etc.)
        $content = trim( $result );
        $content = preg_replace( '/^```\w*\s*/i', '', $content );
        $content = preg_replace( '/\s*```$/', '', $content );

        return array( 'content' => $content );
    }

    // =========================================================================
    // FORMAT-SPECIFIC PROMPT BUILDERS (all enforce JSON output)
    // =========================================================================

    /**
     * Build revision prompt — used when user provides feedback on existing content.
     */
    private function build_revision_prompt( $format, $existing, $feedback, $focus_instr ) {
        $prompt  = "You previously generated this content as JSON:\n\n{$existing}\n\n";
        $prompt .= "The user provided this feedback: \"{$feedback}\"\n\n";
        if ( ! empty( $focus_instr ) ) {
            $prompt .= "Content angle: {$focus_instr}\n\n";
        }
        $prompt .= "Please revise the content based on the feedback. Return the SAME JSON structure with the requested changes applied.\n";
        $prompt .= "CRITICAL: Return ONLY valid JSON. No markdown fences, no explanatory text.\n";
        return $prompt;
    }

    /**
     * Build article/blog post prompt — returns structured JSON.
     */
    private function build_article_prompt( $format, $problem_title, $solution_title, $industries_str, $brain_summary, $focus_instr, $outline ) {
        $word_counts = array(
            'article_long'  => '1500-2500',
            'article_short' => '500-800',
            'blog_post'     => '500-800',
        );
        $word_range = $word_counts[ $format ] ?? '800-1200';
        $format_name = ( $format === 'blog_post' ) ? 'blog post' : 'article';

        $prompt  = "Write a complete {$word_range} word {$format_name} and return it as structured JSON.\n\n";
        $prompt .= "Topic/Problem: {$problem_title}\nSolution: {$solution_title}\n";
        if ( ! empty( $industries_str ) ) {
            $prompt .= "Target audience industries: {$industries_str}\n";
        }
        if ( ! empty( $brain_summary ) ) {
            $prompt .= "\nContext from client resources:\n{$brain_summary}\n";
        }
        if ( ! empty( $focus_instr ) ) {
            $prompt .= "\nContent angle: {$focus_instr}\n";
        }
        if ( ! empty( $outline ) ) {
            $prompt .= "\nFollow this approved outline:\n{$outline}\n";
        }

        $prompt .= "\nRequirements:\n";
        $prompt .= "- Professional, authoritative tone\n";
        $prompt .= "- Include specific, actionable advice\n";
        $prompt .= "- Use data points and examples where relevant\n";
        $prompt .= "- Strong introduction and conclusion\n";
        $prompt .= "- Clear call-to-action at the end\n";

        if ( $format === 'blog_post' ) {
            $prompt .= "- Conversational, accessible tone optimized for web reading\n";
            $prompt .= "- Short paragraphs and clear subheadings for scanability\n";
        }

        $prompt .= "\nReturn a JSON object with EXACTLY this structure:\n";
        $prompt .= "{\n";
        $prompt .= "  \"title\": \"The article headline\",\n";
        $prompt .= "  \"meta_description\": \"150-character SEO meta description\",\n";
        $prompt .= "  \"sections\": [\n";
        $prompt .= "    {\n";
        $prompt .= "      \"heading\": \"Section heading\",\n";
        $prompt .= "      \"paragraphs\": [\"First paragraph text...\", \"Second paragraph text...\"],\n";
        $prompt .= "      \"key_takeaway\": \"Optional one-line takeaway for this section\"\n";
        $prompt .= "    }\n";
        $prompt .= "  ],\n";
        $prompt .= "  \"call_to_action\": \"Final call-to-action paragraph\"\n";
        $prompt .= "}\n\n";
        $prompt .= "Include 4-8 sections. Each section should have 2-4 paragraphs. Each paragraph should be 3-5 sentences.\n";
        $prompt .= "CRITICAL: Return ONLY valid JSON. No markdown fences, no code blocks, no explanatory text.\n";

        return $prompt;
    }

    /**
     * Build LinkedIn post prompt — returns structured JSON.
     */
    private function build_linkedin_prompt( $problem_title, $solution_title, $industries_str, $brain_summary, $focus_instr, $outline ) {
        $prompt  = "Write a professional LinkedIn post (200-300 words) and return it as structured JSON.\n\n";
        $prompt .= "Topic/Problem: {$problem_title}\nSolution: {$solution_title}\n";
        if ( ! empty( $industries_str ) ) {
            $prompt .= "Target audience industries: {$industries_str}\n";
        }
        if ( ! empty( $brain_summary ) ) {
            $prompt .= "\nContext from client resources:\n{$brain_summary}\n";
        }
        if ( ! empty( $focus_instr ) ) {
            $prompt .= "\nContent angle: {$focus_instr}\n";
        }
        if ( ! empty( $outline ) ) {
            $prompt .= "\nFollow this outline:\n{$outline}\n";
        }

        $prompt .= "\nRequirements:\n";
        $prompt .= "- Start with an attention-grabbing hook line\n";
        $prompt .= "- Use short paragraphs (1-2 sentences each)\n";
        $prompt .= "- Include relevant emoji sparingly\n";
        $prompt .= "- End with a question or call-to-action to drive engagement\n";
        $prompt .= "- Add 3-5 relevant hashtags\n\n";

        $prompt .= "Return a JSON object with EXACTLY this structure:\n";
        $prompt .= "{\n";
        $prompt .= "  \"hook\": \"The attention-grabbing opening line\",\n";
        $prompt .= "  \"body\": [\"Short paragraph 1\", \"Short paragraph 2\", \"Short paragraph 3\"],\n";
        $prompt .= "  \"call_to_action\": \"Closing question or CTA\",\n";
        $prompt .= "  \"hashtags\": [\"#hashtag1\", \"#hashtag2\", \"#hashtag3\"]\n";
        $prompt .= "}\n\n";
        $prompt .= "The body array should have 4-8 short paragraphs. Each paragraph 1-2 sentences.\n";
        $prompt .= "CRITICAL: Return ONLY valid JSON. No markdown fences, no code blocks, no explanatory text.\n";

        return $prompt;
    }

    /**
     * Build infographic prompt — returns structured sections with visual elements.
     */
    private function build_infographic_prompt( $problem_title, $solution_title, $industries_str, $brain_summary, $focus_instr, $outline ) {
        $prompt  = "Create content for a professional infographic and return it as structured JSON.\n\n";
        $prompt .= "Topic/Problem: {$problem_title}\nSolution: {$solution_title}\n";
        if ( ! empty( $industries_str ) ) {
            $prompt .= "Target audience industries: {$industries_str}\n";
        }
        if ( ! empty( $brain_summary ) ) {
            $prompt .= "\nContext from client resources:\n{$brain_summary}\n";
        }
        if ( ! empty( $focus_instr ) ) {
            $prompt .= "\nContent angle: {$focus_instr}\n";
        }
        if ( ! empty( $outline ) ) {
            $prompt .= "\nFollow this outline:\n{$outline}\n";
        }

        $prompt .= "\nReturn a JSON object with EXACTLY this structure:\n";
        $prompt .= "{\n";
        $prompt .= "  \"title\": \"Infographic title\",\n";
        $prompt .= "  \"subtitle\": \"Brief tagline or subtitle\",\n";
        $prompt .= "  \"sections\": [\n";
        $prompt .= "    {\n";
        $prompt .= "      \"heading\": \"Section heading\",\n";
        $prompt .= "      \"description\": \"Brief description (1-2 sentences)\",\n";
        $prompt .= "      \"data_points\": [{\"label\": \"Metric name\", \"value\": \"67%\"}],\n";
        $prompt .= "      \"visual_element\": {\n";
        $prompt .= "        \"type\": \"stat_cards\",\n";
        $prompt .= "        \"data\": { \"stats\": [{\"value\": \"67%\", \"label\": \"Reduction\"}] }\n";
        $prompt .= "      }\n";
        $prompt .= "    }\n";
        $prompt .= "  ],\n";
        $prompt .= "  \"footer\": \"Source attribution or footnote\",\n";
        $prompt .= "  \"call_to_action\": \"Final CTA text\"\n";
        $prompt .= "}\n\n";

        $prompt .= "Include 4-6 sections. Each section MUST have a visual_element.\n";
        $prompt .= "Supported visual_element types:\n";
        $prompt .= "1. \"bar_chart\" — data: { \"labels\": [...], \"values\": [...], \"title\": \"...\", \"value_suffix\": \"%\" }\n";
        $prompt .= "2. \"donut_chart\" — data: { \"segments\": [{\"label\":\"...\",\"value\":30},...], \"center_label\": \"...\", \"center_value\": \"...\" }\n";
        $prompt .= "3. \"stat_cards\" — data: { \"stats\": [{\"value\":\"67%\",\"label\":\"Reduction\"},...] }\n";
        $prompt .= "4. \"comparison\" — data: { \"before\": {\"title\":\"Before\",\"points\":[...]}, \"after\": {\"title\":\"After\",\"points\":[...]} }\n";
        $prompt .= "5. \"timeline\" — data: { \"steps\": [{\"phase\":\"Phase 1\",\"title\":\"...\",\"description\":\"...\"},...] }\n";
        $prompt .= "6. \"progress_bars\" — data: { \"bars\": [{\"label\":\"...\",\"value\":85},...], \"value_suffix\": \"%\" }\n\n";

        $prompt .= "Use realistic, plausible numbers. Vary visual types across sections.\n";
        $prompt .= "CRITICAL: Return ONLY valid JSON. No markdown fences, no code blocks, no explanatory text.\n";

        return $prompt;
    }

    /**
     * Build presentation prompt — returns slide deck JSON array.
     * (Extracted from previous inline code, unchanged logic.)
     */
    private function build_presentation_prompt( $problem_title, $solution_title, $industries_str, $brain_summary, $focus_instr, $outline ) {
        $prompt  = "Create a professional slide deck as a JSON array.\n\n";
        $prompt .= "Topic/Problem: {$problem_title}\n";
        $prompt .= "Solution: {$solution_title}\n";
        if ( ! empty( $industries_str ) ) {
            $prompt .= "Target Industries: {$industries_str}\n";
        }
        if ( ! empty( $brain_summary ) ) {
            $prompt .= "\nContext from client resources:\n{$brain_summary}\n";
        }
        if ( ! empty( $focus_instr ) ) {
            $prompt .= "\nContent angle: {$focus_instr}\n";
        }
        if ( ! empty( $outline ) ) {
            $prompt .= "\nUse this approved outline as guidance:\n{$outline}\n";
        }
        $prompt .= "\nReturn a JSON array of 10-12 slide objects. Each object MUST have:\n";
        $prompt .= "{\n";
        $prompt .= "  \"slide_number\": 1,\n";
        $prompt .= "  \"slide_title\": \"Compelling Title\",\n";
        $prompt .= "  \"section\": \"Title Slide\",\n";
        $prompt .= "  \"key_points\": [\"Point one\", \"Point two\", \"Point three\"],\n";
        $prompt .= "  \"speaker_notes\": \"What the presenter should say.\",\n";
        $prompt .= "  \"data_points\": [\"67% of companies...\", \"$2.3M average savings\"],\n";
        $prompt .= "  \"visual_element\": null\n";
        $prompt .= "}\n\n";

        $prompt .= "VISUAL ELEMENTS: For 3-5 data-heavy slides (NOT the title slide or CTA), include a \"visual_element\" object instead of null.\n";
        $prompt .= "When a slide has a visual_element, move text bullets to key_points and let the visual tell the data story.\n";
        $prompt .= "Each visual_element MUST have a \"type\" plus a \"data\" object. Supported types:\n\n";

        $prompt .= "1. \"bar_chart\" — data: { \"labels\": [\"Label1\",\"Label2\",...], \"values\": [40,65,...], \"title\": \"Chart Title\", \"value_suffix\": \"%\" }\n";
        $prompt .= "2. \"donut_chart\" — data: { \"segments\": [{\"label\":\"Seg1\",\"value\":30},{\"label\":\"Seg2\",\"value\":70}], \"center_label\": \"Total\", \"center_value\": \"100%\" }\n";
        $prompt .= "3. \"stat_cards\" — data: { \"stats\": [{\"value\":\"67%\",\"label\":\"Reduction\"},{\"value\":\"$2.3M\",\"label\":\"Savings\"},{\"value\":\"3x\",\"label\":\"Faster\"}] }\n";
        $prompt .= "4. \"comparison\" — data: { \"before\": {\"title\":\"Before\",\"points\":[\"Manual processes\",\"Slow\"]}, \"after\": {\"title\":\"After\",\"points\":[\"Automated\",\"Fast\"]} }\n";
        $prompt .= "5. \"timeline\" — data: { \"steps\": [{\"phase\":\"Phase 1\",\"title\":\"Discovery\",\"description\":\"Assess current state\"},{\"phase\":\"Phase 2\",\"title\":\"Implementation\",\"description\":\"Deploy solution\"}] }\n";
        $prompt .= "6. \"progress_bars\" — data: { \"bars\": [{\"label\":\"Efficiency\",\"value\":85},{\"label\":\"Cost\",\"value\":60}], \"value_suffix\": \"%\" }\n\n";

        $prompt .= "Use realistic, plausible numbers. Vary visual types across slides — don't repeat the same type.\n\n";

        $prompt .= "Valid section values: \"Title Slide\", \"Problem Definition\", \"Problem Amplification\", \"Solution Overview\", \"Solution Details\", \"Benefits Summary\", \"Credibility\", \"Call to Action\"\n\n";
        $prompt .= "Structure: Title slide -> 2-3 problem slides -> 3-4 solution slides -> benefits -> credibility -> CTA\n";
        $prompt .= "Keep key_points to 3-5 items, max 12 words each. Speaker notes should be 2-3 helpful sentences.\n\n";
        $prompt .= "CRITICAL: Return ONLY the raw JSON array. No markdown fences, no ```json blocks, no explanatory text. Start with [ and end with ].\n";

        return $prompt;
    }

}