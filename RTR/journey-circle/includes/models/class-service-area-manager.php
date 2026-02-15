<?php
/**
 * Service Area Manager
 *
 * Handles all CRUD operations for service areas.
 *
 * @package Journey_Circle
 */

class Service_Area_Manager {

    /**
     * Create a new service area.
     *
     * @since 1.0.0
     * @param array $args Service area arguments.
     * @return int|WP_Error Post ID on success, WP_Error on failure.
     */
    public function create( $args ) {
        // Validate required fields
        if ( empty( $args['title'] ) ) {
            return new WP_Error( 'missing_title', 'Service area title is required' );
        }
        
        // Default values
        $defaults = array(
            'title'       => '',
            'description' => '',
            'client_id'   => 0,
            'status'      => 'draft',
        );
        
        $args = wp_parse_args( $args, $defaults );
        
        // Create the post
        $post_data = array(
            'post_title'   => sanitize_text_field( $args['title'] ),
            'post_content' => wp_kses_post( $args['description'] ),
            'post_type'    => 'jc_service_area',
            'post_status'  => 'publish',
        );
        
        $post_id = wp_insert_post( $post_data );
        
        if ( is_wp_error( $post_id ) ) {
            return $post_id;
        }
        
        // Set meta data
        if ( ! empty( $args['client_id'] ) ) {
            update_post_meta( $post_id, '_jc_client_id', absint( $args['client_id'] ) );
        }
        
        // Set status term
        wp_set_object_terms( $post_id, $args['status'], 'jc_status' );
        
        return $post_id;
    }
    
    /**
     * Get a service area by ID.
     *
     * @since 1.0.0
     * @param int $id Service area ID.
     * @return array|WP_Error Service area data or WP_Error on failure.
     */
    public function get( $id ) {
        $post = get_post( $id );
        
        if ( ! $post || $post->post_type !== 'jc_service_area' ) {
            return new WP_Error( 'not_found', 'Service area not found' );
        }
        
        return $this->format_service_area( $post );
    }
    
    /**
     * Get all service areas for a client.
     *
     * @since 1.0.0
     * @param int $client_id Client ID.
     * @return array Array of service areas.
     */
    public function get_by_client( $client_id ) {
        $args = array(
            'post_type'      => 'jc_service_area',
            'posts_per_page' => -1,
            'post_status'    => 'publish',
            'meta_query'     => array(
                array(
                    'key'     => '_jc_client_id',
                    'value'   => absint( $client_id ),
                    'compare' => '='
                )
            )
        );
        
        $query = new WP_Query( $args );
        $service_areas = array();
        
        if ( $query->have_posts() ) {
            while ( $query->have_posts() ) {
                $query->the_post();
                $service_areas[] = $this->format_service_area( get_post() );
            }
            wp_reset_postdata();
        }
        
        return $service_areas;
    }
    
    /**
     * Update a service area.
     *
     * @since 1.0.0
     * @param int   $id   Service area ID.
     * @param array $args Updated service area arguments.
     * @return int|WP_Error Post ID on success, WP_Error on failure.
     */
    public function update( $id, $args ) {
        $post = get_post( $id );
        
        if ( ! $post || $post->post_type !== 'jc_service_area' ) {
            return new WP_Error( 'not_found', 'Service area not found' );
        }
        
        $post_data = array(
            'ID' => $id,
        );
        
        if ( isset( $args['title'] ) ) {
            $post_data['post_title'] = sanitize_text_field( $args['title'] );
        }
        
        if ( isset( $args['description'] ) ) {
            $post_data['post_content'] = wp_kses_post( $args['description'] );
        }
        
        $result = wp_update_post( $post_data );
        
        if ( is_wp_error( $result ) ) {
            return $result;
        }
        
        // Update meta data
        if ( isset( $args['client_id'] ) ) {
            update_post_meta( $id, '_jc_client_id', absint( $args['client_id'] ) );
        }
        
        // Update status
        if ( isset( $args['status'] ) ) {
            wp_set_object_terms( $id, $args['status'], 'jc_status' );
        }
        
        return $id;
    }
    
    /**
     * Delete a service area.
     *
     * @since 1.0.0
     * @param int  $id    Service area ID.
     * @param bool $force Whether to force delete (bypass trash). Default false.
     * @return bool True on success, false on failure.
     */
    public function delete( $id, $force = false ) {
        $post = get_post( $id );
        
        if ( ! $post || $post->post_type !== 'jc_service_area' ) {
            return false;
        }
        
        // Check if service area has journey circles
        $has_circles = $this->has_journey_circles( $id );
        
        if ( $has_circles && ! $force ) {
            return new WP_Error( 'has_circles', 'Cannot delete service area with existing journey circles. Use force=true to cascade delete.' );
        }

        // ── Cascade delete all related data ──
        if ( $has_circles ) {
            $this->cascade_delete_service_area_data( $id );
        }
        
        $result = wp_delete_post( $id, $force );
        
        return $result !== false;
    }

    /**
     * Cascade delete all data related to a service area.
     *
     * Deletes in order (respecting FK dependencies):
     *   1. Custom table rows:  assets → offers → solutions → problems
     *                          → brain_content → journey_state → journey_circles
     *   2. Custom post types:  jc_offer → jc_solution → jc_problem → jc_journey_circle
     *
     * @since 2.0.0
     * @param int $service_area_id Service area post ID.
     * @return void
     */
    private function cascade_delete_service_area_data( $service_area_id ) {
        global $wpdb;

        $prefix = $wpdb->prefix . 'jc_';

        // ── Step 1: Find all journey circle IDs for this service area ──

        // From custom post types (meta _jc_service_area_id)
        $cpt_circle_ids = get_posts( array(
            'post_type'      => 'jc_journey_circle',
            'posts_per_page' => -1,
            'post_status'    => 'any',
            'fields'         => 'ids',
            'meta_query'     => array(
                array(
                    'key'     => '_jc_service_area_id',
                    'value'   => absint( $service_area_id ),
                    'compare' => '=',
                ),
            ),
        ) );

        // From custom table
        $table_circle_ids = $wpdb->get_col( $wpdb->prepare(
            "SELECT id FROM {$prefix}journey_circles WHERE service_area_id = %d",
            $service_area_id
        ) );

        // Merge and deduplicate
        $all_circle_ids = array_unique( array_merge(
            array_map( 'intval', $cpt_circle_ids ),
            array_map( 'intval', $table_circle_ids )
        ) );

        if ( empty( $all_circle_ids ) ) {
            return;
        }

        $circle_placeholders = implode( ',', array_fill( 0, count( $all_circle_ids ), '%d' ) );

        // ── Step 2: Delete custom table rows (leaf → root) ──

        // Assets
        $wpdb->query( $wpdb->prepare(
            "DELETE FROM {$prefix}journey_assets WHERE journey_circle_id IN ($circle_placeholders)",
            ...$all_circle_ids
        ) );

        // Offers
        $wpdb->query( $wpdb->prepare(
            "DELETE FROM {$prefix}journey_offers WHERE journey_circle_id IN ($circle_placeholders)",
            ...$all_circle_ids
        ) );

        // Solutions
        $wpdb->query( $wpdb->prepare(
            "DELETE FROM {$prefix}journey_solutions WHERE journey_circle_id IN ($circle_placeholders)",
            ...$all_circle_ids
        ) );

        // Problems
        $wpdb->query( $wpdb->prepare(
            "DELETE FROM {$prefix}journey_problems WHERE journey_circle_id IN ($circle_placeholders)",
            ...$all_circle_ids
        ) );

        // Brain content
        $wpdb->query( $wpdb->prepare(
            "DELETE FROM {$prefix}brain_content WHERE journey_circle_id IN ($circle_placeholders)",
            ...$all_circle_ids
        ) );

        // Journey state snapshots
        $wpdb->query( $wpdb->prepare(
            "DELETE FROM {$prefix}journey_state WHERE journey_circle_id IN ($circle_placeholders)",
            ...$all_circle_ids
        ) );

        // Journey circles (the parent row)
        $wpdb->query( $wpdb->prepare(
            "DELETE FROM {$prefix}journey_circles WHERE service_area_id = %d",
            $service_area_id
        ) );

        // ── Step 3: Delete custom post types (leaf → root) ──

        foreach ( $all_circle_ids as $circle_id ) {
            // Delete offers (CPT)
            $offer_ids = get_posts( array(
                'post_type'      => 'jc_offer',
                'posts_per_page' => -1,
                'post_status'    => 'any',
                'fields'         => 'ids',
                'meta_query'     => array( array(
                    'key'     => '_jc_journey_circle_id',
                    'value'   => $circle_id,
                    'compare' => '=',
                ) ),
            ) );
            foreach ( $offer_ids as $oid ) {
                wp_delete_post( $oid, true );
            }

            // Delete solutions (CPT)
            $solution_ids = get_posts( array(
                'post_type'      => 'jc_solution',
                'posts_per_page' => -1,
                'post_status'    => 'any',
                'fields'         => 'ids',
                'meta_query'     => array( array(
                    'key'     => '_jc_journey_circle_id',
                    'value'   => $circle_id,
                    'compare' => '=',
                ) ),
            ) );
            foreach ( $solution_ids as $sid ) {
                wp_delete_post( $sid, true );
            }

            // Delete problems (CPT)
            $problem_ids = get_posts( array(
                'post_type'      => 'jc_problem',
                'posts_per_page' => -1,
                'post_status'    => 'any',
                'fields'         => 'ids',
                'meta_query'     => array( array(
                    'key'     => '_jc_journey_circle_id',
                    'value'   => $circle_id,
                    'compare' => '=',
                ) ),
            ) );
            foreach ( $problem_ids as $pid ) {
                wp_delete_post( $pid, true );
            }

            // Delete the journey circle CPT itself
            wp_delete_post( $circle_id, true );
        }
    }
    
    /**
     * Check if service area has journey circles.
     *
     * @since 1.0.0
     * @param int $service_area_id Service area ID.
     * @return bool True if has journey circles, false otherwise.
     */
    public function has_journey_circles( $service_area_id ) {
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
     * Format service area data for API response.
     *
     * @since 1.0.0
     * @param WP_Post $post Post object.
     * @return array Formatted service area data.
     */
    private function format_service_area( $post ) {
        $status_terms = wp_get_object_terms( $post->ID, 'jc_status' );
        $status = ! empty( $status_terms ) && ! is_wp_error( $status_terms ) ? $status_terms[0]->slug : 'draft';
        
        return array(
            'id'          => $post->ID,
            'title'       => $post->post_title,
            'name'        => $post->post_title,
            'description' => $post->post_content,
            'client_id'   => get_post_meta( $post->ID, '_jc_client_id', true ),
            'status'      => $status,
            'created_at'  => $post->post_date,
            'updated_at'  => $post->post_modified,
        );
    }
    
    /**
     * Validate service area data.
     *
     * @since 1.0.0
     * @param array $args Service area arguments.
     * @return true|WP_Error True if valid, WP_Error on validation failure.
     */
    public function validate( $args ) {
        $errors = new WP_Error();
        
        if ( empty( $args['title'] ) ) {
            $errors->add( 'missing_title', 'Service area title is required' );
        }
        
        if ( isset( $args['title'] ) && strlen( $args['title'] ) > 255 ) {
            $errors->add( 'title_too_long', 'Title must be 255 characters or less' );
        }
        
        if ( isset( $args['status'] ) && ! in_array( $args['status'], array( 'draft', 'active', 'archived' ) ) ) {
            $errors->add( 'invalid_status', 'Invalid status value' );
        }
        
        if ( $errors->has_errors() ) {
            return $errors;
        }
        
        return true;
    }
}