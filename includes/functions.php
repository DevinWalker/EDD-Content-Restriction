<?php
/**
 * Add Helper Functions and Template Overrides
 *
 * @package     EDD\ContentRestriction\Functions
 * @copyright   Copyright (c) 2013-2014, Pippin Williamson
 * @since       1.0.0
 */


// Exit if accessed directly
if( ! defined( 'ABSPATH' ) ) exit;


/**
 * Check to see if a user has access to a post/page
 *
 * @since       2.0
 * @param       int $user_id The ID of the user to check
 * @param       array $restricted_to The array of downloads for a post/page
 * @param       int $post_id The ID of the object we are viewing
 * @return      array $return An array containing the status and optional message
 */
function edd_cr_user_can_access( $user_id = false, $restricted_to, $post_id = false ) {

    $has_access         = false;
    $restricted_count   = count( $restricted_to );
    $products           = array();

    // If no user is given, use the current user
    if( ! $user_id ) {
        $user_id = get_current_user_id();
    }

    // bbPress specific checks. Moderators can see everything
    if( class_exists( 'bbPress' ) && current_user_can( 'moderate' ) ) {
        $has_access = true;
    }

    // Admins have full access
    if( current_user_can( 'manage_options' ) ) {
        $has_access = true;
    }

    // The post author can always access
    if( $post_id && current_user_can( 'edit_post', $post_id ) ) {
        $has_access = true;
    }

    if( $restricted_to && is_array( $restricted_to ) && ! $has_access ) {

        foreach( $restricted_to as $item => $data ) {

            if( empty( $data['download'] ) ) {
                $has_access = true;
            }

            // The author of a download always has access
            if( (int) get_post_field( 'post_author', $data['download'] ) === (int) $user_id && is_user_logged_in() ) {
                $has_access = true;
                break;
            }

            // If restricted to any customer and user has purchased something
            if( 'any' === $data['download'] && edd_has_purchases( $user_id ) && is_user_logged_in() ) {
                $has_access  = true;
                break;
            } elseif( 'any' === $data['download'] ) {
                $products[0] = __( 'any product', 'edd_cr' );
                $has_access  = false;
                break;
            }

            // Check for variable prices
            if( ! $has_access ) {

                if( edd_has_variable_prices( $data['download'] ) ) {

                    if( strtolower( $data['price_id'] ) !== 'all' && ! empty( $data['price_id'] ) ) {
                        $products[] = '<a href="' . get_permalink( $data['download'] ) . '">' . get_the_title( $data['download'] ) . ' - ' . edd_get_price_option_name( $data['download'], $data['price_id'] ) . '</a>';

                        if( edd_has_user_purchased( $user_id, $data['download'], $data['price_id'] ) ) {
                            $has_access = true;
                        }

                    } else {
                        $products[] = '<a href="' . get_permalink( $data['download'] ) . '">' . get_the_title( $data['download'] ) . '</a>';

                        if( edd_has_user_purchased( $user_id, $data['download'] ) ) {
                            $has_access = true;
                        }
                    }

                } else {

                    $products[] = '<a href="' . get_permalink( $data['download'] ) . '">' . get_the_title( $data['download'] ) . '</a>';
                    if( is_user_logged_in() && edd_has_user_purchased( $user_id, $data['download'] ) ) {
                        $has_access = true;
                    }
                }

            }

        }

        if( $has_access == false ) {

            if( $restricted_count > 1 ) {

                $message  = __( 'This content is restricted to buyers of:', 'edd_cr' );

                if( ! empty( $products ) ) {

                    $message .= '<ul>';

                    foreach( $products as $id => $product ) {
                        $message .= '<li>' . $product . '</li>';
                    }

                    $message .= '</ul>';

                }

            } else {

                $message = sprintf(
                    __( 'This content is restricted to buyers of %s.', 'edd_cr' ),
                    $products[0]
                );

            }

        }

        if( isset( $message ) ) {

            $return['message'] = $message;

        } else {

            $return['message'] = __( 'This content is restricted to buyers.', 'edd_cr' );

        }

    } else {

        // Just in case we're checking something unrestricted...
        $has_access = true;

    }

    // Allow plugins to modify the restriction requirements
    $has_access = apply_filters( 'edd_cr_user_can_access', $has_access, $user_id, $restricted_to );

    $return['status'] = $has_access;

    return $return;
}


/**
 * Filter content to handle restricted posts/pages
 *
 * @since       1.0.0
 * @param       string $content The content to filter
 * @global      object $post The post we are editing
 * @return      string $content The filtered content
 */
function edd_cr_filter_content( $content ) {
    global $post;

    // If $post isn't an object, we aren't handling it!
    if( ! is_object( $post ) ) {
        return $content;
    }

    $restricted = edd_cr_is_restricted( $post->ID );

    if( $restricted ) {
        $content = edd_cr_filter_restricted_content( $content, $restricted, null, $post->ID );
    }

    return $content;
}
add_filter( 'the_content', 'edd_cr_filter_content' );


/**
 * Filter restricted content
 *
 * @since       1.0.0
 * @param       string $content The content to filter
 * @param       array $restricted The items to which this is restricted
 * @param       string $message The message to display to users
 * @param       int $post_id The ID of the current post/page
 * @param       string $class Additional classes for the displayed error
 * @global      int $user_ID The ID of the current user
 * @return      string $content The content to display to the user
 */
function edd_cr_filter_restricted_content( $content = '', $restricted = false, $message = null, $post_id = 0, $class = '' ) {
    global $user_ID;

    // If the current user can edit this post, it can't be restricted!
    if( ! current_user_can( 'edit_post', $post_id ) && $restricted ) {

        $has_access = edd_cr_user_can_access( $user_ID, $restricted, $post_id );

        if( $has_access['status'] == false ) {

            if( ! empty( $message ) ) {
                $has_access['message'] = $message;
            }

            $content = '<div class="edd_cr_message ' . $class . '">' . $has_access['message'] . '</div>';
        }
    }

    return do_shortcode( $content );
}


/**
 * Check if a post/page is restricted
 *
 * @since       1.0.0
 * @param       int $post_id the ID of the post to check
 * @return      bool True if post is restricted, false otherwise
 */
function edd_cr_is_restricted( $post_id ) {
    $restricted = get_post_meta( $post_id, '_edd_cr_restricted_to', true );

    return $restricted;
}


/**
 * Add restricted content to confirmation page
 *
 * @since       1.3.0
 * @param       object $payment The payment we are processing
 * @param       array $edd_receipt_args The args for a given receipt
 * @return      void
 */
function edd_cr_add_to_receipt( $payment, $edd_receipt_args ) {
    // Get the array of restricted pages for this payment
    $meta = edd_cr_get_restricted_pages( $payment->ID );

    // No pages? Quit!
    if( empty( $meta ) ) {
        return;
    }

    echo '</tbody></table><h3>' . __( 'Pages', 'edd_cr' ) . '</h3><table><tbody>';

    echo '<tr><td>';
    echo '<ul class="edd-cr-receipt">';

    foreach( $meta as $post ) {
        echo '<li>';
        echo '<a href="' . esc_url( get_permalink( $post->ID ) ) . '" class="edd_download_file_link">' . $post->post_title . '</a>';
        echo '</li>';
    }

    echo '</ul>';
    echo '</td></tr>';
}
add_action( 'edd_payment_receipt_after', 'edd_cr_add_to_receipt', 1, 2 );


/**
 * Registers our email tags
 *
 * @since       1.5.4
 * @return      void
 */
function edd_cr_register_email_tags() {
    edd_add_email_tag( 'page_list', __( 'Shows a list of restricted pages the customer has access to', 'edd_cr' ), 'edd_cr_add_template_tags' );
}
add_action( 'edd_add_email_tags', 'edd_cr_register_email_tags' );


/**
 * Add email template tags
 *
 * @since       1.3.0
 * @param       int $payment_id The payment ID
 * @return      string $page_list The list of accessible pages
 */
function edd_cr_add_template_tags( $payment_id ) {
    // Get the array of restricted pages for this payment
    $meta = edd_cr_get_restricted_pages( $payment_id );

    // No pages? Quit!
    if( empty( $meta ) ) {
        return '';
    }

    $page_list = '<div class="edd_cr_accessible_pages">' . __( 'Pages', 'edd_cr' ) . '</div>';
    $page_list .= '<ul>';

    foreach( $meta as $post ) {
        $page_list .= '<li><a href="' . esc_url( get_permalink( $post->ID ) ) . '">' . $post->post_title . '</a></li>';
    }

    $page_list .= '</ul>';
    $page_list .= '</li>';

    return $page_list;
}


/**
 * Get posts/pages restricted to the purchased files
 *
 * @since       1.3.0
 * @param       int $payment_id The ID of this payment
 * @return      array $meta The list of accessible files
 */
function edd_cr_get_restricted_pages( $payment_id = 0 ) {

    if( empty( $payment_id ) ) {
        return false;
    }

    $posts    = array();
    $post_ids = array();
    $files    = edd_get_payment_meta_downloads( $payment_id );
    $ids      = array_unique( wp_list_pluck( $files, 'id' ) );

    foreach( $ids as $download_id ) {

        $meta = get_post_meta( $download_id, '_edd_cr_protected_post' );
        if( $meta ) {
            $post_ids = array_merge( $post_ids, $meta );
        }

    }

    $post_ids = array_unique( array_map( 'absint', $post_ids ) );

    if( ! empty( $post_ids ) ) {

        $args = array(
            'post_type' => 'any',
            'nopaging'  => true,
            'post__in'  => $post_ids
        );

        $query = new WP_Query( $args );

        $posts = $query->posts;

    }

    return $posts;
}
