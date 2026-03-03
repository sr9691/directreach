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
    const MAX_PROBLEM_TITLES = 15;

    /**
     * Minimum number of problem titles to request.
     *
     * @var int
     */
    const MIN_PROBLEM_TITLES = 15;

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

    // =========================================================================
    // STEP 5: PRIMARY PROBLEM GENERATION
    // =========================================================================

    /**
     * Generate Primary Problem statement candidates.
     *
     * Extracts structured buyer context from existing assets, then generates
     * 8-10 solution-agnostic problem statements following the template:
     * "[Buyer] struggle to [desired outcome] because [root cause],
     *  resulting in [business impact] and increasing [risk/cost]."
     *
     * @since 2.2.0
     * @param array $args {
     *     @type int    $service_area_id
     *     @type string $service_area_name
     *     @type array  $industries
     *     @type array  $brain_content
     *     @type array  $existing_assets
     *     @type bool   $force_refresh
     * }
     * @return array|WP_Error Result with 'statements', 'best_pick', 'extracted_fields'.
     */
    public function generate_primary_problems( $args ) {
        $defaults = array(
            'service_area_id'   => 0,
            'service_area_name' => '',
            'industries'        => array(),
            'brain_content'     => array(),
            'existing_assets'   => array(),
            'force_refresh'     => false,
        );
        $args = wp_parse_args( $args, $defaults );

        // Resolve service area name.
        if ( empty( $args['service_area_name'] ) && ! empty( $args['service_area_id'] ) ) {
            $args['service_area_name'] = $this->get_service_area_name( $args['service_area_id'] );
        }

        error_log( '[JC AI] generate_primary_problems START — service_area=' . $args['service_area_name'] );

        // Cache check.
        $cache_key = $this->build_cache_key( 'primary_problems', $args );
        if ( $args['force_refresh'] ) {
            delete_transient( $cache_key );
        } elseif ( false !== ( $cached = get_transient( $cache_key ) ) ) {
            error_log( '[JC AI] generate_primary_problems CACHE HIT' );
            return $cached;
        }

        // Build prompt.
        $prompt = $this->build_primary_problems_prompt( $args );

        error_log( '[JC AI] generate_primary_problems — prompt built, length=' . strlen( $prompt ) );

        // Call Gemini with extended timeout — this prompt is large with structured output.
        error_log( '[JC AI] generate_primary_problems — calling Gemini API...' );
        $response = $this->call_gemini_api( $prompt, array( 'timeout' => 90 ) );
        if ( is_wp_error( $response ) ) {
            error_log( '[JC AI] generate_primary_problems API ERROR: ' . $response->get_error_message() );
            return $response;
        }

        // Parse JSON response.
        $result = $this->parse_json_response( $response );
        if ( is_wp_error( $result ) ) {
            error_log( '[JC AI] generate_primary_problems PARSE ERROR: ' . $result->get_error_message() );
            return $result;
        }

        // Validate expected structure.
        if ( ! isset( $result['statements'] ) || ! is_array( $result['statements'] ) ) {
            return new WP_Error( 'invalid_response', 'Response missing "statements" array.' );
        }

        // Cache for 6 hours.
        set_transient( $cache_key, $result, 6 * HOUR_IN_SECONDS );

        $count = count( $result['statements'] );
        error_log( "[JC AI] generate_primary_problems SUCCESS — {$count} statements generated" );

        return $result;
    }

    /**
     * Build the prompt for Primary Problem generation.
     *
     * @since 2.2.0
     * @param array $args Generation arguments.
     * @return string Full prompt text.
     */
    private function build_primary_problems_prompt( $args ) {
        $assets_summary = $this->summarize_existing_assets( $args['existing_assets'] ?? array() );
        $brain_summary  = $this->summarize_brain_content( $args['brain_content'] );
        $industries     = $this->format_industries( $args['industries'] );
        $service_area   = sanitize_text_field( $args['service_area_name'] );

        $prompt = <<<PROMPT
### ROLE
You are a B2B Content Strategy Architect specializing in buyer-problem research. Your job is to identify and articulate the **single core buyer problem** that anchors an entire content marketing journey.

### TASK
You have two jobs:
1. **Extract** structured buyer context from the source material
2. **Generate** 8-10 Primary Problem statement candidates

### SOURCE MATERIAL

**Service Area:** {$service_area}
**Target Industries:** {$industries}

**Primary Client Knowledge (MOST IMPORTANT — this is the client describing problems and solutions in their own words):**
{$assets_summary}

**Supporting Research & Insights:**
{$brain_summary}

### JOB 1: EXTRACT STRUCTURED FIELDS

From the source material above, identify and extract these fields. Use the client's own language wherever possible:

- **buyer_roles**: Who experiences this problem? (job titles, roles — e.g., "B2B tech company CEOs", "VPs of Sales")
- **industry_context**: What industry/market context makes this problem acute?
- **desired_outcome**: What does the buyer want to achieve? (e.g., "predictable pipeline of qualified opportunities")
- **core_pain**: What is actually failing or broken? (e.g., "no system to manufacture buyer interest into intent")
- **root_cause**: Why is it happening? The deeper structural reason. (e.g., "marketing generates interest but nothing bridges the gap to sales-ready intent")
- **business_impact**: What measurable damage results? (e.g., "sales team burnout, unpredictable revenue, repeated marketing restarts")
- **stakes**: What happens in 12-24 months if ignored? (e.g., "stunted growth, talent exodus, failed growth initiatives")

### JOB 2: GENERATE PRIMARY PROBLEM STATEMENTS

Using the extracted fields, generate exactly 8 Primary Problem statements following this template:

**Template:** "[Buyer role(s)] struggle to [desired outcome] because [root cause / barrier], resulting in [business impact] and increasing [risk / cost / constraint]."

**Quality Rules:**
1. **Solution-agnostic** — NO product names, no "platform," no "AI," no "we help," no vendor/solution language. If you removed the client's solution from the world, the statement must still be true.
2. **Specific, not vague** — Ban "inefficiency," "challenges," "difficulties," "issues." Use concrete, measurable language.
3. **Pain + consequence** — Every statement must describe BOTH what's broken AND what it costs.
4. **Multi-angle potential** — The statement must be "attackable" from many angles: risk, revenue, time, reputation, compliance, talent, customer experience, competitive position.
5. **Buyer language** — Use the words and phrases the buyer actually uses (from the source material), not consultant jargon.
6. **Each statement must emphasize a different facet** — vary which root cause, which impact, or which stakeholder perspective is foregrounded.

**Anti-patterns (DO NOT write statements like these):**
- "Companies struggle with growth challenges" (too vague)
- "Businesses need better marketing automation" (solution language)
- "Sales teams face difficulties in pipeline management" (corporate-speak, no consequence)

**Strong examples:**
- "B2B tech company leaders struggle to build a predictable pipeline of qualified opportunities because their marketing generates interest without bridging the gap to sales-ready intent, resulting in sales teams burning out on unqualified prospects and revenue targets becoming a quarterly gamble."
- "Mid-market professional services firms struggle to scale beyond partner-led origination because no systematic process exists to educate buyers through the evaluation journey, resulting in chronic talent underutilization and growth that plateaus at the founders' personal capacity."

### JOB 3: RECOMMEND THE BEST PICK

Select the single best statement and explain:
- Why it's the strongest (clarity, specificity, breadth of angles)
- What makes it stay solution-agnostic
- What "guardrail" words/phrases should be avoided when generating content from this statement

### RESPONSE FORMAT
Return ONLY a valid JSON object — no markdown fences, no explanation outside the JSON:

{
  "extracted_fields": {
    "buyer_roles": "...",
    "industry_context": "...",
    "desired_outcome": "...",
    "core_pain": "...",
    "root_cause": "...",
    "business_impact": "...",
    "stakes": "..."
  },
  "statements": [
    {
      "id": 1,
      "statement": "Full primary problem statement here",
      "emphasis": "Which facet this statement foregrounds (e.g., 'sales burnout', 'revenue unpredictability', 'marketing waste')"
    }
  ],
  "best_pick": {
    "id": 1,
    "rationale": "Why this statement is the strongest...",
    "guardrails": ["word or phrase to avoid", "another phrase to avoid"]
  }
}

CRITICAL: Return exactly 8 objects in the "statements" array. Each must follow the template. Each must foreground a different facet.
PROMPT;

        return $prompt;
    }

    // =========================================================================
    // STEP 6: PROBLEM TITLE GENERATION
    // =========================================================================

    /**
     * Generate problem titles using the awareness/angle matrix.
     *
     * Requires the selected Primary Problem statement from Step 5 as
     * the anchor input. Generates 15 titles across 4 awareness levels:
     *   4 Unaware, 4 Misnamed, 4 Aware, 3 Urgency.
     *
     * @since 2.2.0
     * @param array $args {
     *     @type int    $service_area_id            Service area CPT ID.
     *     @type string $service_area_name          Service area display name.
     *     @type string $primary_problem_statement  Selected primary problem from Step 5.
     *     @type array  $industries                 Target industries.
     *     @type array  $brain_content              Brain content items.
     *     @type array  $existing_assets            Existing assets.
     *     @type bool   $force_refresh              Skip cache if true.
     * }
     * @return array|WP_Error Array of title objects on success, WP_Error on failure.
     */
    public function generate_problem_titles( $args ) {
        $defaults = array(
            'service_area_id'            => 0,
            'service_area_name'          => '',
            'primary_problem_statement'  => '',
            'industries'                 => array(),
            'brain_content'              => array(),
            'existing_assets'            => array(),
            'force_refresh'              => false,
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
     * Uses the awareness/angle matrix approach anchored to the
     * Primary Problem statement selected in Step 5.
     *
     * @since 2.2.0
     * @param array $args Generation arguments.
     * @return string The constructed prompt.
     */
    private function build_problem_titles_prompt( $args ) {
        $brain_summary           = $this->summarize_brain_content( $args['brain_content'] );
        $tone_profile            = $this->get_tone_style_profile( $args['brain_content'], $args['existing_assets'] ?? array() );
        $assets_summary          = $this->summarize_existing_assets( $args['existing_assets'] ?? array() );
        $industries              = $this->format_industries( $args['industries'] );
        $service_area            = sanitize_text_field( $args['service_area_name'] );
        $primary_problem         = sanitize_text_field( $args['primary_problem_statement'] ?? '' );

        // Build tone/voice instruction block.
        $tone_block = '';
        if ( ! empty( $tone_profile ) ) {
            $tone_block = "- **Client Voice & Style (match this tone in all titles):**\n{$tone_profile}\n";
        }

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
You are a B2B Content Strategy Architect who specializes in buyer-awareness segmentation. You create diagnostic, problem-centric content titles that meet prospects at their exact stage of awareness — from completely unaware of the problem through urgently needing to act.

### TASK
Generate exactly 15 content titles organized across an **Awareness / Angle Matrix**. Every title must be anchored to the Primary Problem statement below. Titles are for TOP-OF-FUNNEL problem content — NO solutions, NO products, NO vendor language.

### PRIMARY PROBLEM STATEMENT (ANCHOR)
"{$primary_problem}"

This statement follows the structure: "[Buyer] struggle to [outcome] because [root cause], resulting in [impact] and increasing [risk]."
Each title you generate must connect to a specific component of this statement (cause, symptom, or impact).

### CONTEXT
- **Primary Service Area:** {$service_area}
- **Target Industries:** {$industries}
{$tone_block}- **Primary Client Knowledge:**
{$assets_summary}
- **Supporting Research & Insights:**
{$brain_summary}
{$constraints_block}

### AWARENESS / ANGLE MATRIX

Generate titles across these four awareness levels:

**LEVEL 1 — UNAWARE (4 titles)**
The reader does not know they have this problem. Titles surface symptoms, warning signs, or "you might have this and not know it" framings.
- Use diagnostic language: symptoms, early indicators, hidden patterns
- Make the reader self-diagnose: "Could this be happening to you?"
- Example patterns: "The warning sign hiding in your…", "What your [metric] is actually telling you", "Why [common symptom] is rarely what it seems"

**LEVEL 2 — MISNAMED (4 titles)**
The reader knows something is wrong but has the wrong diagnosis. Titles reframe the problem — what people think it is vs. what it actually is.
- Challenge the conventional label or assumed cause
- Reveal the real problem underneath the surface symptom
- Example patterns: "You think it's [common diagnosis] — it's actually [real cause]", "The [popular fix] fallacy", "Why fixing [symptom] keeps making [real problem] worse"

**LEVEL 3 — AWARE (4 titles)**
The reader knows the problem. Titles frame the stakes clearly — what's at risk, what it costs, how it compounds.
- Clear problem framing with specific stakes and consequences
- Make the cost of the status quo viscerally clear
- Example patterns: "The true cost of [problem]", "How [problem] silently erodes [important thing]", "[Problem]: What nobody tells the [role]"

**LEVEL 4 — URGENCY (3 titles)**
The reader knows the problem and may be procrastinating. Titles create time pressure — why it's getting worse, why delay is expensive, what's changed.
- Market timing, competitive pressure, compounding damage
- Make inaction feel like a decision with consequences
- Example patterns: "Why [problem] is getting worse in [year/context]", "The cost of waiting another quarter on [problem]", "What every [role] will wish they'd addressed sooner"

### ANGLE TAXONOMY
Each title must use one of these strategic angles. Distribute angles across all 15 titles — don't cluster the same angle:
- **Risk** — regulatory, reputational, operational, security
- **Revenue** — pipeline, conversion, retention, pricing power
- **Time** — compounding delays, wasted cycles, speed-to-value
- **Talent** — attrition, burnout, underutilization, hiring friction
- **Compliance** — regulatory, audit, legal exposure
- **Competitive** — market position, share erosion, differentiation loss
- **Customer** — churn, satisfaction, experience degradation

### QUALITY RULES
1. **NO SOLUTION LANGUAGE** — Titles must never mention the client name, product type, product category, technology type, or solution approach. Ban: "platform," "AI," "software," "system," "tool," "automation," "outsource," "partner." If you removed every solution from the market, the title must still make sense.
2. **Diagnostic over generic** — Use specific, observable language. Ban: "challenges," "issues," "difficulties," "inefficiencies," "problems with." Use instead: "symptom," "pattern," "signal," "blind spot," "misdiagnosis," "friction."
3. **Meaningfully distinct** — No two titles may be synonyms or rephrases of each other. Each must attack a genuinely different angle or facet.
4. **10-20 words** — Punchy, scannable. No filler, no "How to" (those are solution titles).
5. **Buyer language** — Use the words and phrases actual buyers use, not consultant jargon.

### SELF-CHECK (CRITICAL)
After generating all 15 titles, review every title and ask: "Does this title mention or imply any product, tool, vendor, technology, or solution category?" If YES, revise it before including it.

### RESPONSE FORMAT
Return ONLY a valid JSON object — no markdown fences, no code blocks, no explanation:
{
  "titles": [
    {
      "title": "The title text here",
      "awareness_level": "Unaware | Misnamed | Aware | Urgency",
      "angle": "Risk | Revenue | Time | Talent | Compliance | Competitive | Customer",
      "problem_connection": "cause | symptom | impact",
      "rationale": "One sentence: which part of the Primary Problem this connects to and why the framing works for this awareness level."
    }
  ],
  "solution_language_check": "PASS or list of titles that needed revision and what was changed"
}

CRITICAL: Return exactly 15 objects in the "titles" array: 4 Unaware, 4 Misnamed, 4 Aware, 3 Urgency. Each title must have all five fields populated. The "awareness_level", "angle", and "problem_connection" fields must use the exact values listed above.
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
        $tone_profile   = $this->get_tone_style_profile( $args['brain_content'], $args['existing_assets'] ?? array() );
        $assets_summary = $this->summarize_existing_assets( $args['existing_assets'] ?? array() );
        $problem_title  = sanitize_text_field( $args['problem_title'] );
        $service_area   = sanitize_text_field( $args['service_area_name'] );
        $industries     = $this->format_industries( $args['industries'] );

        // Build tone/voice instruction block.
        $tone_block = '';
        if ( ! empty( $tone_profile ) ) {
            $tone_block = "- Client Voice & Style (match this tone):\n{$tone_profile}\n";
        }

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
{$tone_block}- Primary Client Knowledge (MOST IMPORTANT — the client's own description of their problems and solutions):
{$assets_summary}
- Supporting Research & Insights:
{$brain_summary}
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
     * @return array|WP_Error Parsed data or error.
     */
    private function parse_json_response( $response_text ) {
        $raw_len = strlen( $response_text );
        error_log( "[JC AI] parse_json_response — raw_length={$raw_len}" );

        // Strip markdown code fences if present.
        $cleaned = $response_text;
        if ( preg_match( '/```(?:json)?\s*([\s\S]*?)\s*```/', $cleaned, $m ) ) {
            $cleaned = $m[1];
        }
        $cleaned = trim( $cleaned );

        $data = json_decode( $cleaned, true );
        if ( json_last_error() === JSON_ERROR_NONE && is_array( $data ) ) {
            error_log( '[JC AI] parse_json_response OK — keys: ' . implode( ', ', array_keys( $data ) ) );
            return $data;
        }

        // Try to extract JSON object from response text.
        if ( preg_match( '/\{[\s\S]*\}/', $cleaned, $m ) ) {
            $data = json_decode( $m[0], true );
            if ( json_last_error() === JSON_ERROR_NONE && is_array( $data ) ) {
                error_log( '[JC AI] parse_json_response OK (extracted) — keys: ' . implode( ', ', array_keys( $data ) ) );
                return $data;
            }
        }

        error_log( '[JC AI] parse_json_response FAILED — json_error: ' . json_last_error_msg() );
        error_log( '[JC AI] parse_json_response raw (first 500): ' . substr( $response_text, 0, 500 ) );

        return new WP_Error( 'parse_error', 'Could not parse JSON response from Gemini: ' . json_last_error_msg() );
    }

    /**
     * Parse the titles response from Gemini.
     *
     * @param string $response_text Raw response text.
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

                if ( ! empty( $title['awareness_level'] ) ) {
                    $al = sanitize_text_field( $title['awareness_level'] );
                    if ( ! $this->is_json_fragment( $al ) ) {
                        $item['awareness_level'] = $al;
                    }
                }

                if ( ! empty( $title['problem_connection'] ) ) {
                    $pc = sanitize_text_field( $title['problem_connection'] );
                    if ( ! $this->is_json_fragment( $pc ) ) {
                        $item['problem_connection'] = $pc;
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
     * Extract the aggregate tone & style profile from brain content items.
     *
     * Collects all tone_style_profile values from brain content items,
     * deduplicates, and returns a combined profile string. If multiple
     * brain items have profiles, they are merged into a single block.
     *
     * @since 2.1.0
     * @param array $brain_content Array of brain content items.
     * @return string Combined tone profile or empty string.
     */
    private function get_tone_style_profile( $brain_content, $existing_assets = array() ) {
        $profiles = array();

        // Check brain content items.
        if ( ! empty( $brain_content ) && is_array( $brain_content ) ) {
            foreach ( $brain_content as $item ) {
                if ( ! is_array( $item ) ) {
                    continue;
                }
                $profile = isset( $item['tone_style_profile'] ) ? trim( $item['tone_style_profile'] ) : '';
                if ( ! empty( $profile ) ) {
                    $profiles[] = $profile;
                }
            }
        }

        // Check existing assets items.
        if ( ! empty( $existing_assets ) && is_array( $existing_assets ) ) {
            foreach ( $existing_assets as $item ) {
                if ( ! is_array( $item ) ) {
                    continue;
                }
                $profile = isset( $item['tone_style_profile'] ) ? trim( $item['tone_style_profile'] ) : '';
                if ( ! empty( $profile ) ) {
                    $profiles[] = $profile;
                }
            }
        }

        if ( empty( $profiles ) ) {
            return '';
        }

        // If only one source, return it directly.
        if ( count( $profiles ) === 1 ) {
            return $profiles[0];
        }

        // Multiple sources — combine with labels.
        $combined = "Combined voice profile from " . count( $profiles ) . " sources:\n";
        foreach ( $profiles as $i => $profile ) {
            $combined .= "Source " . ( $i + 1 ) . ": " . $profile . "\n";
        }
        return $combined;
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
            return '(No client documents provided)';
        }

        $parts          = array();
        $url_count      = 0;
        $file_count     = 0;
        $extracted_count = 0;
        $pending_count   = 0;
        $total_chars     = 0;

        foreach ( $existing_assets as $item ) {
            if ( ! is_array( $item ) ) {
                continue;
            }

            $type           = isset( $item['type'] ) ? $item['type'] : '';
            $name           = isset( $item['name'] ) ? sanitize_text_field( $item['name'] ) : '';
            $value          = isset( $item['value'] ) ? $item['value'] : '';
            $extracted_text = isset( $item['extracted_text'] ) ? trim( $item['extracted_text'] ) : '';

            if ( ! empty( $extracted_text ) ) {
                $extracted_count++;
                $total_chars += strlen( $extracted_text );
            } else {
                $pending_count++;
            }

            switch ( $type ) {
                case 'url':
                    $url_count++;
                    $display = $name ? $name : esc_url( $value );
                    if ( ! empty( $extracted_text ) ) {
                        $parts[] = "--- Client source: {$display} ---\n{$extracted_text}";
                    } else {
                        $parts[] = '- Client URL (content pending extraction): ' . $display;
                    }
                    break;

                case 'file':
                    $file_count++;
                    $filename = $name ? $name : ( isset( $item['filename'] ) ? sanitize_file_name( $item['filename'] ) : $value );
                    if ( ! empty( $extracted_text ) ) {
                        $parts[] = "--- Client document: {$filename} ---\n{$extracted_text}";
                    } else {
                        $parts[] = '- Client document (content pending extraction): ' . $filename;
                    }
                    break;

                default:
                    if ( ! empty( $value ) ) {
                        $text = ! empty( $extracted_text ) ? $extracted_text : wp_strip_all_tags( $value );
                        if ( strlen( $text ) > 1000 ) {
                            $text = substr( $text, 0, 1000 ) . '... (truncated)';
                        }
                        $parts[] = "--- Client content ---\n{$text}";
                    }
                    break;
            }
        }

        // Log extraction stats for debugging.
        $total = $url_count + $file_count;
        $chars_fmt = number_format( $total_chars );
        error_log( "[JC AI] Assets: {$total} total, {$extracted_count} with extracted text ({$chars_fmt} chars), {$pending_count} pending" );

        if ( $pending_count > 0 ) {
            error_log( "[JC AI] WARNING: {$pending_count} asset(s) have no extracted text — titles will be generated without this content" );
        }

        if ( empty( $parts ) ) {
            return '(No usable client documents)';
        }

        $header = sprintf( 'Client-provided source documents (%d):', $total );

        return $header . "\n" . implode( "\n\n", $parts );
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
        $tone_profile   = $this->get_tone_style_profile( $args['brain_content'] ?? array(), $args['existing_assets'] ?? array() );
        $assets_summary = $this->summarize_existing_assets( $args['existing_assets'] ?? array() );
        $industries_str = $this->format_industries( $args['industries'] ?? array() );
        $existing       = $args['existing_outline'] ?? '';
        $feedback       = sanitize_text_field( $args['feedback'] ?? '' );
        $focus          = sanitize_text_field( $args['focus'] ?? '' );
        $focus_instr    = sanitize_text_field( $args['focus_instruction'] ?? '' );

        // Build tone instruction for outline prompts.
        $tone_instr = '';
        if ( ! empty( $tone_profile ) ) {
            $tone_instr = "\nClient Voice & Style (match this tone in all generated content):\n{$tone_profile}\n";
        }

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
            if ( ! empty( $tone_instr ) ) {
                $prompt .= $tone_instr;
            }
            if ( ! empty( $assets_summary ) ) {
                $prompt .= "\nPrimary Client Knowledge (use this as the most authoritative source):\n{$assets_summary}\n";
            }
            if ( ! empty( $brain_summary ) ) {
                $prompt .= "\nSupporting research & insights:\n{$brain_summary}\n";
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
        $tone_profile   = $this->get_tone_style_profile( $args['brain_content'] ?? array(), $args['existing_assets'] ?? array() );
        $assets_summary = $this->summarize_existing_assets( $args['existing_assets'] ?? array() );
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
            $prompt = $this->build_presentation_prompt( $problem_title, $solution_title, $industries_str, $brain_summary, $focus_instr, $outline, $tone_profile, $assets_summary );
        }
        // =====================================================================
        // LINKEDIN POST — structured post JSON
        // =====================================================================
        elseif ( $format === 'linkedin_post' ) {
            $prompt = $this->build_linkedin_prompt( $problem_title, $solution_title, $industries_str, $brain_summary, $focus_instr, $outline, $tone_profile, $assets_summary );
        }
        // =====================================================================
        // INFOGRAPHIC — structured sections with visual elements
        // =====================================================================
        elseif ( $format === 'infographic' ) {
            $prompt = $this->build_infographic_prompt( $problem_title, $solution_title, $industries_str, $brain_summary, $focus_instr, $outline, $tone_profile, $assets_summary );
        }
        // =====================================================================
        // ARTICLE / BLOG POST — structured sections JSON
        // =====================================================================
        else {
            $prompt = $this->build_article_prompt( $format, $problem_title, $solution_title, $industries_str, $brain_summary, $focus_instr, $outline, $tone_profile, $assets_summary );
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
    private function build_article_prompt( $format, $problem_title, $solution_title, $industries_str, $brain_summary, $focus_instr, $outline, $tone_profile = '', $assets_summary = '' ) {
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
        if ( ! empty( $tone_profile ) ) {
            $prompt .= "\nClient Voice & Style (CRITICAL — match this tone throughout):\n{$tone_profile}\n";
        }
        if ( ! empty( $assets_summary ) ) {
            $prompt .= "\nPrimary Client Knowledge (MOST IMPORTANT — use as authoritative source):\n{$assets_summary}\n";
        }
        if ( ! empty( $brain_summary ) ) {
            $prompt .= "\nSupporting research & insights:\n{$brain_summary}\n";
        }
        if ( ! empty( $focus_instr ) ) {
            $prompt .= "\nContent angle: {$focus_instr}\n";
        }
        if ( ! empty( $outline ) ) {
            $prompt .= "\nFollow this approved outline:\n{$outline}\n";
        }

        $prompt .= "\nRequirements:\n";
        if ( empty( $tone_profile ) ) {
            $prompt .= "- Professional, authoritative tone\n";
        } else {
            $prompt .= "- Follow the Client Voice & Style profile above — do NOT default to generic corporate tone\n";
        }
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
    private function build_linkedin_prompt( $problem_title, $solution_title, $industries_str, $brain_summary, $focus_instr, $outline, $tone_profile = '', $assets_summary = '' ) {
        $prompt  = "Write a professional LinkedIn post (200-300 words) and return it as structured JSON.\n\n";
        $prompt .= "Topic/Problem: {$problem_title}\nSolution: {$solution_title}\n";
        if ( ! empty( $industries_str ) ) {
            $prompt .= "Target audience industries: {$industries_str}\n";
        }
        if ( ! empty( $tone_profile ) ) {
            $prompt .= "\nClient Voice & Style (CRITICAL — match this tone throughout):\n{$tone_profile}\n";
        }
        if ( ! empty( $assets_summary ) ) {
            $prompt .= "\nPrimary Client Knowledge (use as authoritative source):\n{$assets_summary}\n";
        }
        if ( ! empty( $brain_summary ) ) {
            $prompt .= "\nSupporting research & insights:\n{$brain_summary}\n";
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
    private function build_infographic_prompt( $problem_title, $solution_title, $industries_str, $brain_summary, $focus_instr, $outline, $tone_profile = '', $assets_summary = '' ) {
        $prompt  = "Create content for a professional infographic and return it as structured JSON.\n\n";
        $prompt .= "Topic/Problem: {$problem_title}\nSolution: {$solution_title}\n";
        if ( ! empty( $industries_str ) ) {
            $prompt .= "Target audience industries: {$industries_str}\n";
        }
        if ( ! empty( $tone_profile ) ) {
            $prompt .= "\nClient Voice & Style (match this tone):\n{$tone_profile}\n";
        }
        if ( ! empty( $assets_summary ) ) {
            $prompt .= "\nPrimary Client Knowledge (use as authoritative source):\n{$assets_summary}\n";
        }
        if ( ! empty( $brain_summary ) ) {
            $prompt .= "\nSupporting research & insights:\n{$brain_summary}\n";
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
    private function build_presentation_prompt( $problem_title, $solution_title, $industries_str, $brain_summary, $focus_instr, $outline, $tone_profile = '', $assets_summary = '' ) {
        $prompt  = "Create a professional slide deck as a JSON array.\n\n";
        $prompt .= "Topic/Problem: {$problem_title}\n";
        $prompt .= "Solution: {$solution_title}\n";
        if ( ! empty( $industries_str ) ) {
            $prompt .= "Target Industries: {$industries_str}\n";
        }
        if ( ! empty( $tone_profile ) ) {
            $prompt .= "\nClient Voice & Style (match this tone):\n{$tone_profile}\n";
        }
        if ( ! empty( $assets_summary ) ) {
            $prompt .= "\nPrimary Client Knowledge (use as authoritative source):\n{$assets_summary}\n";
        }
        if ( ! empty( $brain_summary ) ) {
            $prompt .= "\nSupporting research & insights:\n{$brain_summary}\n";
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