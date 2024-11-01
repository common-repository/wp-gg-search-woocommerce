<?php
/*
Plugin Name: WP GG Search WooCommerce
Plugin URI: http://wordpress.org/plugins/wp-gg-search-woocommerce/
Description: This Plugin extends your GG Search Engine. It allows to explore your shop!
Author: Matthias Günter
Version: 1.0
Author URI: http://matthias-web.de
Licence: GPLv2
*/

/**
 * Table of Content:
 * - Localize
 * - Filter Shop Order
 * - Filter Shop Coupon
 * - Filter Shop Product
 * - Posts SQL-Where WP-Filter
 * - Extra Orders by User
 * - Extra Orders by Category
 * - Extra Create Product from Term
 * - Init WP to Rewrite Extras
 * - Javascript / CSS
 * 
 * 
 * 
 * =============================================================================
 */

// Localize the plugin
add_action( 'plugins_loaded', "gg_search_wc_plugins_laoded" );
function gg_search_wc_plugins_laoded() {
    load_plugin_textdomain( 'ggsearch-woocommerce', FALSE, dirname(plugin_basename(__FILE__)).'/languages/' );
}

/**
 * Filter Shop Order
 * - Add Search Algo
 * - Add Permission
 * - Add Output
 */
add_filter("gg_filter_cpt_shop_order", function($filter) {
    global $wpdb;
    $filter["search"] = function($term, $opt) {
        $search_fields = array_map( 'wc_clean', apply_filters( 'woocommerce_shop_order_search_fields', array(
			'_order_key', '_billing_company', '_billing_address_1', '_billing_address_2', '_billing_city',
			'_billing_postcode', '_billing_country', '_billing_state', '_billing_email', '_billing_phone',
			'_shipping_address_1', '_shipping_address_2', '_shipping_city', '_shipping_postcode',
			'_shipping_country', '_shipping_state'
		) ) );

		$search_order_id = str_replace( 'Order #', '', $term );
		if ( ! is_numeric( $search_order_id ) ) $search_order_id = 0;

		global $wpdb;
		$post_ids = array_unique( array_merge(
			$wpdb->get_col(
				$wpdb->prepare( "
					SELECT DISTINCT p1.post_id
					FROM {$wpdb->postmeta} p1
					INNER JOIN {$wpdb->postmeta} p2 ON p1.post_id = p2.post_id
					WHERE
						( p1.meta_key = '_billing_first_name' AND p2.meta_key = '_billing_last_name' AND CONCAT(p1.meta_value, ' ', p2.meta_value) LIKE '%%%s%%' )
					OR
						( p1.meta_key = '_shipping_first_name' AND p2.meta_key = '_shipping_last_name' AND CONCAT(p1.meta_value, ' ', p2.meta_value) LIKE '%%%s%%' )
					OR
						( p1.meta_key IN ('" . implode( "','", $search_fields ) . "') AND p1.meta_value LIKE '%%%s%%' )
					",
					esc_attr( $term ), esc_attr( $term ), esc_attr( $term )
				)
			),
			$wpdb->get_col(
				$wpdb->prepare( "
					SELECT order_id
					FROM {$wpdb->prefix}woocommerce_order_items as order_items
					WHERE order_item_name LIKE '%%%s%%'
					",
					esc_attr( $term )
				)
			),
			array( $search_order_id )
		) );

        $query = new WP_Query(array(
            'post_type' => "shop_order",
            'post__in' => $post_ids,
            "post_status" => "any",
            "post_count" => $opt["limit"] ));
        return $query->posts;
    };
    $filter["cap"] = "edit_shop_orders";
    
    $gg_search_woocommerce_order_statuses = null;
    $filter["output"] = function($row) {
        global $gg_search_woocommerce_order_statuses;
        if ($gg_search_woocommerce_order_statuses == null) {
            $gg_search_woocommerce_order_statuses = wc_get_order_statuses();
        }
        return array("s" => $gg_search_woocommerce_order_statuses[$row["post_status"]], "id" => $row["ID"]);
    };
    return $filter;
});

/**
 * Filter Shop Coupon
 * - Add Search Algo
 * - Add Permission
 * - Add Output
 */
add_filter("gg_filter_cpt_shop_coupon", function($filter) {
    $filter["search"] = function($term, $opt) {
        $query = new WP_Query(array(
            'post_type' => "shop_coupon",
            "post_status" => "any",
            "post_count" => $opt["limit"],
            "gg_search_wc_coupons_posts_where" => $term
        ));
        return $query->posts;
    };
    $filter["output"] = function($row) {
        $usage_count = absint( get_post_meta( $row["ID"], 'usage_count', true ) );
		$usage_limit = esc_html( get_post_meta( $row["ID"], 'usage_limit', true ) );
		if ( $usage_limit ) {
			return sprintf( __( '%s / %s', 'woocommerce' ), $usage_count, $usage_limit );
		} else {
			return sprintf( __( '%s / &infin;', 'woocommerce' ), $usage_count );
		}
    };
    $filter["cap"] = "edit_shop_coupons";
    return $filter;
});

/**
 * Filter Shop Product
 * - Add Permission
 * - Add Output
 */
add_filter("gg_filter_cpt_product", function($filter) {
    $filter["cap"] = "edit_products";
    $filter["output"] = function($row) {
        $p = new WC_Product($row["ID"]);
        $t = get_the_post_thumbnail( $row["ID"], 'thumbnail' );
        if ($t == "") $t = '<img src="' . content_url() . '/plugins/woocommerce/assets/images/placeholder.png"/>';
        return array(
            "p" => $p->get_price_html() ? $p->get_price_html() : '<span class="na">&ndash;</span>',
            "i" => $t
        );
    };
    return $filter;
});

/**
 * Posts SQL-Where WP-Filter
 * Allows to search LIKE coupon
 */
add_filter( 'posts_where', 'gg_search_wc_coupons_posts_where', 10, 2 );
function gg_search_wc_coupons_posts_where( $where, &$wp_query )
{
    global $wpdb;
    if ( $wpse18703_title = $wp_query->get( 'gg_search_wc_coupons_posts_where' ) ) {
        $where .= ' AND (' . $wpdb->posts . '.post_title LIKE \'%' . esc_sql( $wpdb->esc_like( $wpse18703_title ) ) . '%\' OR ' . $wpdb->posts . '.post_content LIKE \'%' . esc_sql( $wpdb->esc_like( $wpse18703_title ) ) . '%\')';
    }
    return $where;
}

/**
 * Extra Orders by User
 * - edit.php?post_type=shop_order&_customer_user=2&paged=1&mode=list
 */
add_filter("gg_extra", "gg_extra_orders_by_name");
function gg_extra_orders_by_name($collection) {
    $collection->add(array(
        "name" => "wc-orders-by-name",
        "title" => __("Show all Orders from &quot;{0}&quot;...", "ggsearch-woocommerce"),
        "link" => admin_url( '?wc_redirector_order_by_name={0}' ),
        "cap" => "edit_shop_orders",
        "priority" => 3,
        "condition" => function($term) {
            return username_exists($term) > 0;
        }
    ));
    
    return $collection;
}

/**
 * Extra Orders by Category
 * - edit.php?post_type=product&product_cat={0}
 */
add_filter("gg_extra", "gg_extra_wc_product_category");
function gg_extra_wc_product_category($collection) {
    $collection->add(array(
        "name" => "wc-product-category",
        "title" => __("Show all Products with category &quot;{0}&quot;...", "ggsearch-woocommerce"),
        "link" => admin_url( 'edit.php?post_type=product&product_cat={0}' ),
        "cap" => "edit_shop_orders",
        "condition" => function($term) {
            return term_exists($term, 'product_cat') > 0;
        }
    ));
    
    return $collection;
}

/**
 * Extra Create Product from Term
 * - post-new.php?wc-product-create={0}&post_type=product
 */
add_filter("gg_extra", "gg_extra_wc_product_create");
function gg_extra_wc_product_create($collection) {
    $collection->add(array(
        "name" => "wc-product-create",
        "priority" => 2,
        "title" => __("Create Product &quot;{0}&quot;...", "ggsearch-woocommerce"),
        "link" => admin_url( 'post-new.php?wc-product-create={0}&post_type=product' ),
        "script" => '$(\'input[type="text"][name="post_title"]\').val(term);',
        "cap" => "edit_products"
    ));
    
    return $collection;
}

/**
 * Init Wordpress to rewrite URL from extra links
 * - Rewrite Search Products by Customers
 */
add_action("init", "gg_search_woocommerce_init");
function gg_search_woocommerce_init() {
    if (isset($_GET["wc_redirector_order_by_name"])) {
        $id = username_exists($_GET["wc_redirector_order_by_name"]);
        wp_redirect(admin_url( 'edit.php?post_type=shop_order&_customer_user=' . $id . '&paged=1&mode=list' ));
    }
}

/**
 * Javascript / CSS
 */
add_action("gg_search_box_end", "gg_search_end_box_woocommerce");
function gg_search_end_box_woocommerce() {
    ?>
    
    <script type="text/javascript">
        "use strict";
        jQuery(document).ready(function($) {
            var gg = GG_HOOK;
            
            // GG Search Shop Order
            gg.register("output_cpt_shop_order", function(objs, args) {
                var row = args[0], status;
                $('<div class="gg-group gg-group-' + row.name + '">' + row.category + '</div>').appendTo(objs.rows);
                $.each(row.rows, function(key, value) {
                    status = (typeof value.output.s !== "undefined") ? value.output.s : "";
                    $('<a href="' + value.link + '" data-id="' + value.output.id + '" class="gg-item gg-cpt gg-group-' + row.name + '" data-name="' + row.name + '"><span><b># ' + value.output.id + '</b>' + value.title + '</span><div class="right-text">' + status + '</div></a>').appendTo(objs.rows);
                });
            });
            
            // GG Search Shop Coupon
            gg.register("output_cpt_shop_coupon", function(objs, args) {
                var row = args[0], status;
                $('<div class="gg-group gg-group-' + row.name + '">' + row.category + '</div>').appendTo(objs.rows);
                $.each(row.rows, function(key, value) {
                    $('<a href="' + value.link + '" data-id="' + value.output.id + '" class="gg-item gg-cpt gg-group-' + row.name + '" data-name="' + row.name + '"><span>' + value.title + '</span><div class="right-text">' + value.output + '</div></a>').appendTo(objs.rows);
                });
            });
            
            // GG Search Shop Product
            gg.register("output_cpt_product", function(objs, args) {
                var row = args[0], status;
                $('<div class="gg-group gg-group-' + row.name + '">' + row.category + '</div>').appendTo(objs.rows);
                $.each(row.rows, function(key, value) {
                    $('<a href="' + value.link + '" data-id="' + value.output.id + '" class="gg-item gg-cpt gg-group-' + row.name + '" data-name="' + row.name + '">'
                    + '<span>' + value.output.i + value.title + '<br />' + value.output.p + '</span>'
                    + '</a>').appendTo(objs.rows);
                });
            });
        });
    </script>
    <style type="text/css">
        /** GG Search Product */
        #gg-search .gg-group-cpt_product img {
            max-width: 36px;
            max-height: 36px;
            float: right;
        }
    
        /** GG Search Shop Order */
        #gg-search .gg-group-cpt_shop_order b {
            background-color: rgb(255, 207, 119);
            font-size: 9px;
            padding: 1px 5px;
            margin-right: 10px;
            color: black !important;
        }
    </style>
    
    <?php
    // Test-DIV
    return;
    ?>
    <div style="padding:30px;position:fixed;z-index:999;top:100px;left:100px;width:500px;height:500px;background:white;overflow:scroll;">
        <?php
        echo '<pre>';
        echo '</pre>';
        ?>
    </div>
    <?php
    
}
?>