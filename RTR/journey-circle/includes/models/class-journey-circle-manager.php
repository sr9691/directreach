<?php
/**
 * Journey Circle Manager
 *
 * Handles all CRUD operations for journey circles, problems, solutions, and offers.
 *
 * @package Journey_Circle
 */

class DR_Journey_Circle_Manager {

    /**
     * Maximum number of problems allowed per journey circle.
     */
    const MAX_PROBLEMS = 5;
    
    /**
     * Maximum number of solutions allowed per journey circle.
     */
    const MAX_SOLUTIONS = 5;

    /**
     * Create a new journey circle.
     *
     * @since 1.0.0
     * @param array $args Journey circle arguments.
     * @return int|WP_Error Post ID on success, WP_Error on failure.
     */
    public function create( $args ) {
        // Validate required fields
        if ( empty( $args['service_area_id'] ) ) {
            return new WP_Error( 'missing_service_area', 'Service area ID is required' );
        }
        
        // Check if service area already has a journey circle
        if ( $this->service_area_has_circle( $args['service_area_id'] ) ) {
            return new WP_Error( 'circle_exists', 'Service area already has a journey circle' );
        }
        
        // Default values
        $defaults = array(
            'service_area_id' => 0,
            'industries'      => array(),
            'brain_content'   => array(),
            'status'          => 'incomplete',
        );
        
        $args = wp_parse_args( $args, $defaults );
        
        // Create the post
        $post_data = array(
            'post_title'   => 'Journey Circle for Service Area ' . $args['service_area_id'],
            'post_type'    => 'jc_journey_circle',
            'post_status'  => 'publish',
        );
        
        $post_id = wp_insert_post( $post_data );
        
        if ( is_wp_error( $post_id ) ) {
            return $post_id;
        }
        
        // Set meta data
        update_post_meta( $post_id, '_jc_service_area_id', absint( $args['service_area_id'] ) );
        update_post_meta( $post_id, '_jc_industries', $args['industries'] );
        update_post_meta( $post_id, '_jc_brain_content', $args['brain_content'] );
        
        // Set status
        wp_set_object_terms( $post_id, $args['status'], 'jc_status' );
        
        // Note: We no longer sync industries to taxonomy terms.
        // Industries are stored as string arrays (e.g. "Agriculture|Crop Farming")
        // in _jc_industries post meta. Using absint() on these strings corrupted
        // the taxonomy, so that code path has been removed.
        
        return $post_id;
    }
    
    /**
     * Get a journey circle by ID.
     *
     * @since 1.0.0
     * @param int $id Journey circle ID.
     * @return array|WP_Error Journey circle data or WP_Error on failure.
     */
    public function get( $id ) {
        $post = get_post( $id );
        
        if ( ! $post || $post->post_type !== 'jc_journey_circle' ) {
            return new WP_Error( 'not_found', 'Journey circle not found' );
        }
        
        return $this->format_journey_circle( $post );
    }
    
    /**
     * Get journey circle by service area ID.
     *
     * @since 1.0.0
     * @param int $service_area_id Service area ID.
     * @return array|WP_Error Journey circle data or WP_Error if not found.
     */
    public function get_by_service_area( $service_area_id ) {
        $args = array(
            'post_type'      => 'jc_journey_circle',
            'posts_per_page' => 1,
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
        
        if ( ! $query->have_posts() ) {
            return new WP_Error( 'not_found', 'Journey circle not found for this service area' );
        }
        
        $post = $query->posts[0];
        
        return $this->format_journey_circle( $post );
    }
    
    /**
     * Add a problem to a journey circle.
     *
     * @since 1.0.0
     * @param int   $journey_circle_id Journey circle ID.
     * @param array $args              Problem arguments.
     * @return int|WP_Error Problem post ID on success, WP_Error on failure.
     */
    public function add_problem( $journey_circle_id, $args ) {
        // Validate journey circle exists
        $circle = $this->get( $journey_circle_id );
        if ( is_wp_error( $circle ) ) {
            return $circle;
        }
        
        // Check problem limit
        $problem_count = $this->get_problem_count( $journey_circle_id );
        if ( $problem_count >= self::MAX_PROBLEMS ) {
            return new WP_Error( 'max_problems', sprintf( 'Maximum of %d problems allowed', self::MAX_PROBLEMS ) );
        }
        
        // Validate required fields
        if ( empty( $args['title'] ) ) {
            return new WP_Error( 'missing_title', 'Problem title is required' );
        }
        
        // Default values
        $defaults = array(
            'title'       => '',
            'description' => '',
            'position'    => $problem_count,
            'is_primary'  => false,
        );
        
        $args = wp_parse_args( $args, $defaults );
        
        // Create problem post
        $post_data = array(
            'post_title'   => sanitize_text_field( $args['title'] ),
            'post_content' => wp_kses_post( $args['description'] ),
            'post_type'    => 'jc_problem',
            'post_status'  => 'publish',
        );
        
        $problem_id = wp_insert_post( $post_data );
        
        if ( is_wp_error( $problem_id ) ) {
            return $problem_id;
        }
        
        // Set meta data
        update_post_meta( $problem_id, '_jc_journey_circle_id', $journey_circle_id );
        update_post_meta( $problem_id, '_jc_position', absint( $args['position'] ) );
        update_post_meta( $problem_id, '_jc_is_primary', (bool) $args['is_primary'] );
        
        // If this is primary, update journey circle
        if ( $args['is_primary'] ) {
            $this->set_primary_problem( $journey_circle_id, $problem_id );
        }
        
        return $problem_id;
    }
    
    /**
     * Add a solution to a problem.
     *
     * @since 1.0.0
     * @param int   $journey_circle_id Journey circle ID.
     * @param int   $problem_id        Problem ID.
     * @param array $args              Solution arguments.
     * @return int|WP_Error Solution post ID on success, WP_Error on failure.
     */
    public function add_solution( $journey_circle_id, $problem_id, $args ) {
        // Validate journey circle and problem
        $circle = $this->get( $journey_circle_id );
        if ( is_wp_error( $circle ) ) {
            return $circle;
        }
        
        // Check if problem already has a solution (1:1 relationship)
        if ( $this->problem_has_solution( $problem_id ) ) {
            return new WP_Error( 'solution_exists', 'Problem already has a solution' );
        }
        
        // Validate required fields
        if ( empty( $args['title'] ) ) {
            return new WP_Error( 'missing_title', 'Solution title is required' );
        }
        
        // Default values
        $defaults = array(
            'title'       => '',
            'description' => '',
        );
        
        $args = wp_parse_args( $args, $defaults );
        
        // Create solution post
        $post_data = array(
            'post_title'   => sanitize_text_field( $args['title'] ),
            'post_content' => wp_kses_post( $args['description'] ),
            'post_type'    => 'jc_solution',
            'post_status'  => 'publish',
        );
        
        $solution_id = wp_insert_post( $post_data );
        
        if ( is_wp_error( $solution_id ) ) {
            return $solution_id;
        }
        
        // Set meta data
        update_post_meta( $solution_id, '_jc_journey_circle_id', $journey_circle_id );
        update_post_meta( $solution_id, '_jc_problem_id', $problem_id );
        
        return $solution_id;
    }
    
    /**
     * Add an offer to a solution.
     *
     * @since 1.0.0
     * @param int   $journey_circle_id Journey circle ID.
     * @param int   $solution_id       Solution ID.
     * @param array $args              Offer arguments.
     * @return int|WP_Error Offer post ID on success, WP_Error on failure.
     */
    public function add_offer( $journey_circle_id, $solution_id, $args ) {
        // Validate required fields
        if ( empty( $args['title'] ) ) {
            return new WP_Error( 'missing_title', 'Offer title is required' );
        }
        
        if ( empty( $args['url'] ) ) {
            return new WP_Error( 'missing_url', 'Offer URL is required' );
        }
        
        // Validate URL
        if ( ! filter_var( $args['url'], FILTER_VALIDATE_URL ) ) {
            return new WP_Error( 'invalid_url', 'Invalid URL format' );
        }
        
        // Default values
        $defaults = array(
            'title'       => '',
            'url'         => '',
            'description' => '',
        );
        
        $args = wp_parse_args( $args, $defaults );
        
        // Create offer post
        $post_data = array(
            'post_title'   => sanitize_text_field( $args['title'] ),
            'post_content' => wp_kses_post( $args['description'] ),
            'post_type'    => 'jc_offer',
            'post_status'  => 'publish',
        );
        
        $offer_id = wp_insert_post( $post_data );
        
        if ( is_wp_error( $offer_id ) ) {
            return $offer_id;
        }
        
        // Set meta data
        update_post_meta( $offer_id, '_jc_journey_circle_id', $journey_circle_id );
        update_post_meta( $offer_id, '_jc_solution_id', $solution_id );
        update_post_meta( $offer_id, '_jc_url', esc_url_raw( $args['url'] ) );
        
        return $offer_id;
    }
    
    /**
     * Get all problems for a journey circle.
     *
     * @since 1.0.0
     * @param int $journey_circle_id Journey circle ID.
     * @return array Array of problems.
     */
    public function get_problems( $journey_circle_id ) {
        $args = array(
            'post_type'      => 'jc_problem',
            'posts_per_page' => -1,
            'post_status'    => 'publish',
            'meta_query'     => array(
                array(
                    'key'     => '_jc_journey_circle_id',
                    'value'   => absint( $journey_circle_id ),
                    'compare' => '='
                )
            ),
            'orderby'        => 'meta_value_num',
            'meta_key'       => '_jc_position',
            'order'          => 'ASC'
        );
        
        $query = new WP_Query( $args );
        $problems = array();
        
        if ( $query->have_posts() ) {
            while ( $query->have_posts() ) {
                $query->the_post();
                $post = get_post();
                $problems[] = array(
                    'id'          => $post->ID,
                    'title'       => $post->post_title,
                    'description' => $post->post_content,
                    'position'    => get_post_meta( $post->ID, '_jc_position', true ),
                    'is_primary'  => (bool) get_post_meta( $post->ID, '_jc_is_primary', true ),
                );
            }
            wp_reset_postdata();
        }
        
        return $problems;
    }
    
    /**
     * Get all solutions for a journey circle.
     *
     * @since 1.0.0
     * @param int $journey_circle_id Journey circle ID.
     * @return array Array of solutions.
     */
    public function get_solutions( $journey_circle_id ) {
        $args = array(
            'post_type'      => 'jc_solution',
            'posts_per_page' => -1,
            'post_status'    => 'publish',
            'meta_query'     => array(
                array(
                    'key'     => '_jc_journey_circle_id',
                    'value'   => absint( $journey_circle_id ),
                    'compare' => '='
                )
            )
        );
        
        $query = new WP_Query( $args );
        $solutions = array();
        
        if ( $query->have_posts() ) {
            while ( $query->have_posts() ) {
                $query->the_post();
                $post = get_post();
                $solutions[] = array(
                    'id'          => $post->ID,
                    'title'       => $post->post_title,
                    'description' => $post->post_content,
                    'problem_id'  => get_post_meta( $post->ID, '_jc_problem_id', true ),
                );
            }
            wp_reset_postdata();
        }
        
        return $solutions;
    }
    
    /**
     * Get all offers for a journey circle.
     *
     * @since 1.0.0
     * @param int $journey_circle_id Journey circle ID.
     * @return array Array of offers.
     */
    public function get_offers( $journey_circle_id ) {
        $args = array(
            'post_type'      => 'jc_offer',
            'posts_per_page' => -1,
            'post_status'    => 'publish',
            'meta_query'     => array(
                array(
                    'key'     => '_jc_journey_circle_id',
                    'value'   => absint( $journey_circle_id ),
                    'compare' => '='
                )
            )
        );
        
        $query = new WP_Query( $args );
        $offers = array();
        
        if ( $query->have_posts() ) {
            while ( $query->have_posts() ) {
                $query->the_post();
                $post = get_post();
                $offers[] = array(
                    'id'          => $post->ID,
                    'title'       => $post->post_title,
                    'description' => $post->post_content,
                    'url'         => get_post_meta( $post->ID, '_jc_url', true ),
                    'solution_id' => get_post_meta( $post->ID, '_jc_solution_id', true ),
                );
            }
            wp_reset_postdata();
        }
        
        return $offers;
    }
    
    /**
     * Check if service area already has a journey circle.
     *
     * @since 1.0.0
     * @param int $service_area_id Service area ID.
     * @return bool True if has circle, false otherwise.
     */
    private function service_area_has_circle( $service_area_id ) {
        $args = array(
            'post_type'      => 'jc_journey_circle',
            'posts_per_page' => 1,
            'post_status'    => 'any',
            'meta_query'     => array(
                array(
                    'key'     => '_jc_service_area_id',
                    'value'   => absint( $service_area_id ),
                    'compare' => '='
                )
            )
        );
        
        $query = new WP_Query( $args );
        
        return $query->have_posts();
    }
    
    /**
     * Check if problem already has a solution.
     *
     * @since 1.0.0
     * @param int $problem_id Problem ID.
     * @return bool True if has solution, false otherwise.
     */
    private function problem_has_solution( $problem_id ) {
        $args = array(
            'post_type'      => 'jc_solution',
            'posts_per_page' => 1,
            'post_status'    => 'any',
            'meta_query'     => array(
                array(
                    'key'     => '_jc_problem_id',
                    'value'   => absint( $problem_id ),
                    'compare' => '='
                )
            )
        );
        
        $query = new WP_Query( $args );
        
        return $query->have_posts();
    }
    
    /**
     * Get problem count for a journey circle.
     *
     * @since 1.0.0
     * @param int $journey_circle_id Journey circle ID.
     * @return int Problem count.
     */
    private function get_problem_count( $journey_circle_id ) {
        $args = array(
            'post_type'      => 'jc_problem',
            'posts_per_page' => -1,
            'post_status'    => 'publish',
            'meta_query'     => array(
                array(
                    'key'     => '_jc_journey_circle_id',
                    'value'   => absint( $journey_circle_id ),
                    'compare' => '='
                )
            ),
            'fields'         => 'ids'
        );
        
        $query = new WP_Query( $args );
        
        return $query->found_posts;
    }
    
    /**
     * Set primary problem for a journey circle.
     *
     * @since 1.0.0
     * @param int $journey_circle_id Journey circle ID.
     * @param int $problem_id        Problem ID.
     * @return void
     */
    private function set_primary_problem( $journey_circle_id, $problem_id ) {
        // First, remove primary flag from all other problems
        $problems = $this->get_problems( $journey_circle_id );
        foreach ( $problems as $problem ) {
            if ( $problem['id'] !== $problem_id ) {
                update_post_meta( $problem['id'], '_jc_is_primary', false );
            }
        }
        
        // Set the new primary problem
        update_post_meta( $problem_id, '_jc_is_primary', true );
        update_post_meta( $journey_circle_id, '_jc_primary_problem_id', $problem_id );
    }
    
    /**
     * Format journey circle data for API response.
     *
     * FIX: Read industries from _jc_industries post meta instead of jc_industry
     * taxonomy terms. The taxonomy gets corrupted because absint() on string
     * values like "Agriculture|Crop Farming" returns 0.
     *
     * @since 1.0.0
     * @param WP_Post $post Post object.
     * @return array Formatted journey circle data.
     */
    private function format_journey_circle( $post ) {
        $status_terms = wp_get_object_terms( $post->ID, 'jc_status' );
        $status = ! empty( $status_terms ) && ! is_wp_error( $status_terms ) ? $status_terms[0]->slug : 'incomplete';
        
        // FIX: Read from post meta (stores actual string values correctly)
        // instead of taxonomy terms (which were corrupted by absint())
        $industries_meta = get_post_meta( $post->ID, '_jc_industries', true );
        
        return array(
            'id'                 => $post->ID,
            'service_area_id'    => get_post_meta( $post->ID, '_jc_service_area_id', true ),
            'primary_problem_id' => get_post_meta( $post->ID, '_jc_primary_problem_id', true ),
            'industries'         => is_array( $industries_meta ) ? $industries_meta : array(),
            'brain_content'      => get_post_meta( $post->ID, '_jc_brain_content', true ),
            'status'             => $status,
            'problems'           => $this->get_problems( $post->ID ),
            'solutions'          => $this->get_solutions( $post->ID ),
            'offers'             => $this->get_offers( $post->ID ),
            'created_at'         => $post->post_date,
            'updated_at'         => $post->post_modified,
        );
    }
}
