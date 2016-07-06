<?php

/*
Plugin Name: HM Network Auto Upload
Version: 0.12
Description: Automatically copies and attachs files uploaded to one site to all other sites in the network
Plugin URI:
Author: Martin Wecke, HATSUMATSU
Author URI: http://hatsumatsu.de/
GitHub Plugin URI: https://github.com/hatsumatsu/hm-network-auto-upload
GitHub Branch: master
*/


class HMNetworkAutoUpload {
    protected $mpl_api_cache;

    public function __construct() {
        // cache MPL API
        add_action( 'inpsyde_mlp_loaded', array( $this, 'cacheMLPAPI' ) );

        add_filter( 'add_attachment', array( $this, 'copyFile' ), 10, 2 );
    }


    /**
     * Cache MultillingualPress API
     */
    public function cacheMLPAPI( $data ) {
        $this->mpl_api_cache = $data;   
    }


    /**
     * Copy attachment to other sites of the network
     * 
     * https://gist.github.com/hissy/7352933
     * https://github.com/inpsyde/multilingual-press/issues/117
     *
     * @param int $source_post_id Source post ID
     */
    public function copyFile( $source_post_id ) {
        $this->writeLog( 'copyFile()' );

        $source_site_id = get_current_blog_id();
        $source_parent_id = get_post_field( 'post_parent', $source_post_id, null );

        $sites = wp_get_sites();

        // each site... 
        foreach( $sites as $site ) {
            $target_site_id = $site['blog_id'];

            // skip current site
            if( $target_site_id == $source_site_id ) {
                continue;
            }

            $src = wp_get_attachment_image_src( $source_post_id, 'full' )[0];

            $file = get_attached_file( $source_post_id );
            $filename = basename( $file );
            $target_parent_id = 0;

            // if file is attached to a post and MultilingualPress is present
            if( $source_parent_id && function_exists( 'mlp_get_linked_elements' ) ) {
                $relations = mlp_get_linked_elements( $source_parent_id, '', $source_site_id );

                // if there is a connected post
                if( array_key_exists( $target_site_id, $relations ) ) {
                    $target_parent_id = $relations[$target_site_id];
                }
            }

            // switch to other site
            switch_to_blog( $target_site_id );

            // upload file
            $upload_file = wp_upload_bits( $filename, null, file_get_contents( $file ) );
            // file upload successful
            if( !$upload_file['error'] ) {
                $wp_filetype = wp_check_filetype( $filename, null );
                $target = array(
                    'post_mime_type' => $wp_filetype['type'],
                    'post_parent' => $target_parent_id,
                    'post_title' => preg_replace('/\.[^.]+$/', '', $filename ),
                    'post_content' => '',
                    'post_status' => 'inherit'
                );

                // remove filter to prevent infinite loop
                remove_filter( 'add_attachment', array( $this, 'copyFile' ) );
                // create attachment
                $target_post_id = wp_insert_attachment( $target, $upload_file['file'], $target_parent_id );
                // reintroduce filter
                add_filter( 'add_attachment', array( $this, 'copyFile' ), 10, 2 );
            
                // attachment creation successful
                if( !is_wp_error( $target_post_id ) ) {
                    require_once( ABSPATH . 'wp-admin/includes/image.php' );

                    // create attachment meta data
                    $target_data = wp_generate_attachment_metadata( $target_post_id, $upload_file['file'] );
                    wp_update_attachment_metadata( $target_post_id,  $target_data );

                    // link as translation if MultiligualPress is present
                    if( $this->mpl_api_cache ) {            
                        $relations = $this->mpl_api_cache->get( 'content_relations' );

                        $relations->set_relation(
                            $source_site_id,
                            $target_site_id,
                            $source_post_id,
                            $target_post_id
                        );
                    }
                }
            }

            // switch back to current site
            restore_current_blog();
        }

        return $source_post_id;
    }


    /**
     * Write log if WP_DEBUG is active
     * @param  string|array $log 
     */
    public function writeLog( $log )  {
        if( true === WP_DEBUG ) {
            if( is_array( $log ) || is_object( $log ) ) {
                error_log( 'hmnau: ' . print_r( $log, true ) . "\n", 3, trailingslashit( ABSPATH ) . 'wp-content/debuglog.log' );
            } else {
                error_log( 'hmnau: ' . $log . "\n", 3, trailingslashit( ABSPATH ) . 'wp-content/debuglog.log' );
            }
        }
    }
}

new HMNetworkAutoUpload();