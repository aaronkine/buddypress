<?php
/*
Plugin Name: WP User Frontend PRO - BuddyPress Profile
Plugin URI: http://wedevs.com/
Description: Register and upgrade user profiles and sync data with BuddyPress
Version: 0.2
Author: Tareq Hasan
Author URI: http://tareq.wedevs.com/
License: GPL2
*/

/**
 * Copyright (c) 2013 Tareq Hasan (email: tareq@wedevs.com). All rights reserved.
 *
 * Released under the GPL license
 * http://www.opensource.org/licenses/gpl-license.php
 *
 * This is an add-on for WordPress
 * http://wordpress.org/
 *
 * **********************************************************************
 * This program is free software; you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation; either version 2 of the License, or
 * (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License
 * along with this program; if not, write to the Free Software
 * Foundation, Inc., 51 Franklin St, Fifth Floor, Boston, MA  02110-1301  USA
 * **********************************************************************
 */

// don't call the file directly
if ( !defined( 'ABSPATH' ) ) exit;

/**
 * WPUF_BP_Profile class
 *
 * @class WPUF_BP_Profile The class that holds the entire WPUF_BP_Profile plugin
 */
class WPUF_BP_Profile {

    /**
     * Constructor for the WPUF_BP_Profile class
     *
     * Sets up all the appropriate hooks and actions
     * within our plugin.
     *
     * @uses is_admin()
     * @uses add_action()
     */
    public function __construct() {

        // Localize our plugin
        add_action( 'init', array( $this, 'localization_setup' ) );

        add_action( 'wpuf_profile_form_tab', array( $this, 'bp_settings_tab_head' ) );
        add_action( 'wpuf_profile_form_tab_content', array( $this, 'bp_settings_content' ) );

        add_action( 'save_post', array( $this, 'save_form_settings' ), 1, 2 );

        add_action( 'wpuf_update_profile', array( $this, 'on_profile_update' ), 10, 2 );
        add_action( 'wpuf_after_register', array( $this, 'on_user_registration' ), 10, 3 );

    }

    /**
     * Initializes the WPUF_BP_Profile() class
     *
     * Checks for an existing WPUF_BP_Profile() instance
     * and if it doesn't find one, creates it.
     */
    public static function init() {
        static $instance = false;

        if ( ! $instance ) {
            $instance = new WPUF_BP_Profile();
        }

        return $instance;
    }

    /**
     * Initialize plugin for localization
     *
     * @uses load_plugin_textdomain()
     */
    public function localization_setup() {
        load_plugin_textdomain( 'wpuf-bp', false, dirname( plugin_basename( __FILE__ ) ) . '/languages/' );
    }

    function bp_settings_tab_head() {
        ?>
        <a href="#wpuf-metabox-buddypress" class="nav-tab" id="wpuf_buddypress-tab"><?php _e( 'BuddyPress', 'wpuf' ); ?></a>
        <?php
    }

    function bp_settings_content() {
        global $post, $wpdb;

        $this->bp_fields   = $wpdb->get_results( "SELECT id, name FROM {$wpdb->prefix}bp_xprofile_fields WHERE parent_id = 0");

        $this->bp_settings = get_post_meta( $post->ID, 'wpuf_bp_form', true );
        $wpuf_fields       = wpuf_get_form_fields( $post->ID );

        $excluded          = array( 'user_login' );
        $allowed_type      = array( 'text', 'email', 'textarea', 'date', 'radio', 'select', 'multiselect', 'checkbox' );

        $user_profile      = is_multisite() ? admin_url( 'network/users.php?page=bp-profile-setup' ) : admin_url( 'users.php?page=bp-profile-setup' );
        ?>
        <div id="wpuf-metabox-buddypress" class="group">
            <p><a class="button" href="<?php echo $user_profile; ?>"><?php _e( 'BuddyPress profile fields &rarr;', 'wpuf-bp' ); ?></a></p>

            <?php if ( $wpuf_fields ) { ?>
                <table class="form-table">
                    <tbody>
                        <?php
                        foreach ($wpuf_fields as $index => $field) {
                            if ( $field['input_type'] == 'password' ) {
                                continue;
                            }

                            if ( isset( $field['name'] ) && in_array( $field['name'], $excluded ) ) {
                                continue;
                            }

                            if ( !in_array( $field['input_type'], $allowed_type ) ) {
                                continue;
                            }

                            ?>
                        <tr>
                            <th><?php echo $field['label']; ?></th>
                            <td>
                                <?php //var_dump( $field ); ?>
                                <?php echo $this->bp_get_dropdown( $field['name'] ); ?>
                            </td>
                        </tr>
                        <?php } ?>
                    </tbody>
                </table>
            <?php } else { ?>

                <?php _e( 'No fields found in the form. Add fields first and update the form.', 'wpuf-bp' ) ?>

            <?php } ?>
        </div>
        <?php
    }

    function bp_get_dropdown( $index, $field_id = false ) {

        $dropdown = '<select name="_wpuf_bp_mapping[' . $index . ']">';
        $dropdown .= '<option value="-1">' . __( '-- Select --', 'wpuf-bp' ) . '</option>';

        if ( $this->bp_fields ) {
            foreach ($this->bp_fields as $field) {
                $selected = '';

                if ( isset( $this->bp_settings[$index] ) && $this->bp_settings[$index] == $field->id ) {
                    $selected = ' selected="selected"';
                }

                $dropdown .= '<option value="'. $field->id .'"' . $selected . '>' . $field->name . '</option>';
            }
        }

        $dropdown .= '</select>';

        return $dropdown;
    }

    function save_form_settings( $post_id, $post ) {
        if ( !isset($_POST['_wpuf_bp_mapping'])) {
            return;
        }

        // Is the user allowed to edit the post or page?
        if ( !current_user_can( 'edit_post', $post->ID ) ) {
            return $post->ID;
        }

        update_post_meta( $post->ID, 'wpuf_bp_form', $_POST['_wpuf_bp_mapping'] );
    }

    function update_user_data( $user_id, $form_id ) {
        $fields = get_post_meta( $form_id, 'wpuf_bp_form', true );

        if ( $fields && is_array( $fields ) ) {
            foreach ($fields as $input_name => $xprofile_field_id ) {
                if ( $xprofile_field_id != '-1' && isset( $_POST[$input_name] ) ) {
                    xprofile_set_field_data( $xprofile_field_id, $user_id, $_POST[$input_name]);
                }
            }
        }
    }

    function on_profile_update( $user_id, $form_id ) {
        $this->update_user_data( $user_id, $form_id );
    }
    function on_user_registration( $user_id, $userdata, $form_id ) {
		         $this->update_user_data( $user_id, $form_id );
	}}


    $wpuf_bp = WPUF_BP_Profile::init();
