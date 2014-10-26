<?php

class MP_Cart {
	/**
	 * Refers to a single instance of the class
	 *
	 * @since 3.0
	 * @access private
	 * @var object
	 */
	private static $_instance = null;
	
	/**
	 * Refers to the cart's items
	 *
	 * @since 3.0
	 * @access protected
	 * @var array
	 */
	protected $_items = array();
	
	/**
	 * Refers to the current cart ID
	 *
	 * @since 3.0
	 * @access protected
	 * @var int
	 */
	protected $_id = null;
	
	/**
	 * Refers to the original cart ID
	 *
	 * @since 3.0
	 * @access protected
	 * @var int
	 */
	protected $_id_original = null;
	
	/**
	 * Refers to the cart cookie id
	 *
	 * @since 3.0
	 * @access protected
	 * @var string
	 */
	protected $_cookie_id = null;
	
	/**
	 * Refers to if the cart is download only
	 *
	 * @since 3.0
	 * @access protected
	 * @var bool
	 */
	protected $_is_download_only = null;
	
	/**
	 * Gets the single instance of the class
	 *
	 * @since 3.0
	 * @access public
	 * @return object
	 */
	public static function get_instance() {
		if ( is_null(self::$_instance) ) {
			self::$_instance = new MP_Cart();
		}
		return self::$_instance;
	}
	
	/**
	 * Add an item to the cart
	 *
	 * @since 3.0
	 * @access public
	 * @param int $item_id The id of the item to add
	 * @param int $qty The quantity of the item
	 */
	public function add_item( $item_id, $qty = 1 ) {
		if ( $in_cart = $this->has_item($item_id) ) {
			$qty += $in_cart;
		}
		
		mp_push_to_array($this->_items, $this->_id . '->' . $item_id, $qty);
		$this->_update_cart_cookie();
	}
	
	/**
	 * Update the cart (ajax)
	 *
	 * @since 3.0
	 * @access public
	 * @action wp_ajax_mp_update_cart, wp_ajax_nopriv_mp_update_cart
	 */
	public function ajax_update_cart() {
		$item = $item_id = mp_get_post_value('product', null);
		$qty = mp_get_post_value('qty', 1);
		
		if ( is_null($item) ) {
			wp_send_json_error();
		}
		
		if ( is_array($item) ) {
			if ( $product_id = mp_arr_get_value('product_id', $item) ) {
				unset($item['product_id']);
				$product = new MP_Product($product_id);
				if ( $variation = $product->get_variations_by_attributes($item, 0) ) {
					$item_id = $variation->ID;
				}
			}
		}
		
		if ( is_null($item_id) ) {
			wp_send_json_error();
		}
		
		switch ( mp_get_post_value('cart_action') ) {
			case 'add_item' :
				$this->add_item($item_id, $qty);
				wp_send_json_success($this->floating_cart_html());
			break;
		}
		
		wp_send_json_error();
	}

	/**
	 * Convert an array of items to an array of MP_Product objects
	 *
	 * @since 3.0
	 * @access public
	 * @param array $items
	 * @return array
	 */
	protected function _convert_to_objects( $items ) {
		$posts = get_posts(array(
			'post__in' => array_keys($items),
			'posts_per_page' => -1,
			'post_type' => array(MP_Product::get_post_type(), 'mp_product_variation'),
		));
		
		$products = array();
		foreach ( $posts as $post ) {
			$product = new MP_Product($post);
			$product->qty = array_shift($items);
			$products[] = $product;
		}
		
		return $products;
	}
	
	/**
	 * Get cart cookie
	 *
	 * @since 3.0
	 * @access protected
	 */
	protected function _get_cart_cookie( $global = false ) {
		$this->_cookie_id = 'mp_globalcart_' . COOKIEHASH;
		$global_cart = array($this->_id => array());
	 
		if ( $cart_cookie = mp_get_cookie_value($this->_cookie_id) ) {
			$global_cart = unserialize($cart_cookie);
		}
		
		$this->_items = $global_cart;
	 
		if ( $global ) {
			return $this->get_all_items();
		} else {
	 		return $this->get_items();
		}
	}

	/**
	 * Get all cart items across all blogs
	 *
	 * @since 3.0
	 * @access public
	 * @return array
	 */
	public function get_all_items() {
		return $this->_items;
	}
		
	/**
	 * Get a single item from the cart
	 *
	 * @since 3.0
	 * @access public
	 * @param 
	 */
	public function get_item( $item_id ) {
		
	}

	/**
	 * Get cart items
	 *
	 * @since 3.0
	 * @access public
	 */
	public function get_items() {
		return mp_arr_get_value($this->_id, $this->_items, array());
	}
	
	/**
	 * Get the cart total
	 *
	 * @since 3.0
	 * @access public
	 * @return float
	 */
	public function get_total() {
		$items = $this->get_items();
		$total = 0;
		
		foreach ( $items as $item => $qty ) {
			$product = new MP_Product($item);
			$price_obj = $product->get_price();

			if ( $product->has_coupon() ) {
				$total += $price_obj['coupon'] * $qty;
			} elseif ( $product->on_sale() ) {
				$total += ($price_obj['sale']['amount'] * $qty);
			} else {
				$total += ($price_obj['regular'] * $qty);
			}
		}
		
		return $total;
	}
	
	/**
	 * Display cart meta html
	 *
	 * @since 3.0
	 * @access public
	 * @param bool $echo Optional, whether to echo or return. Defaults to true.
	 */
	public function cart_meta( $echo = true ) {
		$zipcode = mp_get_current_user_zipcode();
		$html = '';
		
		if ( empty($zipcode) ) {
			// Show the zipcode lightbox
			add_action('wp_footer', array(&$this, 'show_zipcode_popup'));
			$header = __('Estimated Total', 'mp');
		} else {
			$header = sprintf(__('Estimated Total for %s', 'mp'), $zipcode);
		}
		
		$html .= '
			<div id="mp-cart-meta">
				<div class="mp-cart-meta-header">' . $header . '</div>
				<div id="mp-cart-meta-line-product-total" class="mp-cart-meta-line clearfix">
					<strong class="mp-cart-meta-line-label">' . __('Product Total', 'mp') . '</strong>
					<span class="mp-cart-meta-line-amount">' . mp_format_currency('', $this->get_total()) . '</span>
				</div>
				<div id="mp-cart-meta-line-estimated-tax" class="mp-cart-meta-line clearfix">
					<strong class="mp-cart-meta-line-label">' . sprintf(__('Estimated %s', 'mp'), mp_get_setting('tax->label')) . '</strong>
					<span class="mp-cart-meta-line-amount">' . mp_format_currency('', $this->get_total()) . '</span>
				</div>
				<div id="mp-cart-meta-line-order-total" class="mp-cart-meta-line clearfix">
					<strong class="mp-cart-meta-line-label">' . __('Estimated Order Total', 'mp') . '</strong>
					<span class="mp-cart-meta-line-amount">' . mp_format_currency('', $this->get_total()) . '</span>
				</div>
			</div>';
		
		if ( $echo ) {
			echo $html;
		} else {
			return $html;
		}
	}
		
	/**
	 * Display the cart contents
	 *
	 * @since 3.0
	 * @access public
	 * @param array $args {
	 * 		Optional, an array of arguments.
	 *
	 *		@type bool $echo Optional, whether to echo or return. Defaults to false.
	 *		@type string $view Optional, how to display the cart contents - either list or table or a custom view name. Defaults to list.
	 * }
	 */
	public function display( $args = array() ) {
		$args = $args2 = array_replace_recursive(array(
			'echo' => false,
			'view' => 'list',
		), $args);
		$products = $this->_convert_to_objects($this->get_items());
		
		extract($args);
		
		$html = '
			<div id="mp-cart" class="mp-cart-' . $view . '">';
		
		foreach ( $products as $product ) {
			$html .= '
				<div class="mp-cart-item clearfix" id="mp-cart-item-' . $product->ID . '">
					<div class="mp-cart-item-thumb">' . $product->image_custom(false, 75) . '</div>
					<div class="mp-cart-item-title"><h2>' . $product->title(false) . '</h2></div>
					<div class="mp-cart-item-price">' . $product->display_price(false) . '</div>
					<div class="mp-cart-item-qty">' .
						$this->dropdown_quantity(array('echo' => false, 'class' => 'mp_select2', 'selected' => $product->qty)) . '<br />
						<a class="mp-cart-item-remove-link" href="javascript:mp_cart.removeItem(' . $product->ID . ')">' . __('Remove', 'mp') . '</a>
					</div>
				</div>';
		}
		
		$html .= '
			</div>
			<div class="clearfix">' .
				$this->cart_meta(false) . '
			</div>';
		
		/**
		 * Filter the cart contents html
		 *
		 * @since 3.0
		 * @param string $html The current html.
		 * @param MP_Cart $this The current MP_Cart object.
		 * @param array $args The array of arguments as passed to the method.
		 */
		$html = apply_filters('mp_cart/display', $html, $this, $args);
		
		if ( $echo ) {
			echo $html;
		} else {
			return $html;
		}
	}

	/**
	 * Display the item quantity dropdown
	 *
	 * @since 3.0
	 * @access public
	 * @param array $args {
	 *		Optional, an array of arguments.
	 *
	 * 		@type int $max Optional, the max quantity allowed. Defaults to 10.
	 * 		@type int $selected Optional, the selected option. Defaults to 1.
	 *		@type bool $echo Optional, whether to echo or return. Defaults to true.
	 * }
	 */
	public function dropdown_quantity( $args = array() ) {
		/**
		 * Change the default max quantity allowed
		 *
		 * @since 3.0
		 * @param int The default maximum.
		 */
		$max = apply_filters('mp_cart/quantity_dropdown/max_default', 10);
		$defaults = array(
			'max' => $max,
			'selected' => 1,
			'echo' => true,
			'name' => '',
			'class' => 'mp-cart-item-qty-field',
			'id' => '',
		);
		$args = array_replace_recursive($defaults, $args);
		
		extract($args);
		
		// Build select field attributes
		$attributes = mp_array_to_attributes(compact('name', 'class', 'id'));
		
		$html = '
			<select' . $attributes . '>';
		for ( $i = 1; $i <= $max; $i ++ ) {
			$html .= '
				<option value="' . $i . '" ' . selected($i, $selected, false) . '>' . number_format_i18n($i, 0) . '</option>'; 
		}
		$html .= '
			</select>';
			
		if ( $echo ) {
			echo $html;
		} else {
			return $html;
		}		
	}
		
	/**
	 * Empty cart
	 *
	 * @since 3.0
	 * @access public
	 */
	public function empty_cart() {
		/**
		 * Fires right before the cart is emptied
		 *
		 * @since 3.0
		 * @param int The cart id
		 * @param array The items in the cart before being emptied
		 */
		do_action('mp_cart/empty', $this->_id, $this->get_items());
		
		$this->_items[$this->_id] = array();
		$this->_update_cart_cookie();
	}
	
	/**
	 * Enqueue styles and scripts
	 *
	 * @since 3.0
	 * @access public
	 * @action wp_enqueue_scripts
	 * @uses $post
	 */
	public function enqueue_styles_scripts() {
		global $post;
		
		if ( ! mp_is_shop_page() || mp_get_setting('pages->cart') == $post->ID ) {
			return;
		}
		
		// Styles
		wp_enqueue_style('mp-cart', mp_plugin_url('ui/css/mp-cart.css'), false, MP_VERSION);
		wp_enqueue_style('colorbox', mp_plugin_url('ui/css/colorbox.css'), false, MP_VERSION);
		
		// Scripts
		wp_enqueue_script('jquery-validate', mp_plugin_url('ui/js/jquery.validate.min.js'), array('jquery'), MP_VERSION, true);
		wp_enqueue_script('jquery-validate-methods', mp_plugin_url('ui/js/jquery.validate.methods.min.js'), array('jquery-validate'), MP_VERSION, true);
		wp_enqueue_script('ajaxq', mp_plugin_url('ui/js/ajaxq.min.js'), array('jquery'), MP_VERSION, true);
		wp_enqueue_script('colorbox', mp_plugin_url('ui/js/jquery.colorbox-min.js'), array('jquery'), MP_VERSION, true);
		wp_enqueue_script('mp-cart', mp_plugin_url('ui/js/mp-cart.js'), array('ajaxq', 'colorbox', 'jquery-validate'), MP_VERSION, true);
		
		// Localize scripts
		wp_localize_script('mp-cart', 'mp_cart_i18n', array(
			'ajaxurl' => admin_url('admin-ajax.php'),
		));
	}
	
	/**
	 * Display the floating cart html
	 *
	 * @since 3.0
	 * @access public
	 * @action wp_footer
	 */
	public function floating_cart_html() {
		$echo = true;
		if ( mp_doing_ajax() ) {
			$echo = false;
		}
		
		if ( (! mp_is_shop_page() || mp_get_setting('pages->cart') == get_the_ID()) && ! mp_doing_ajax() ) {
			return;
		}
		
		$items = $this->get_items();
		$html = '
		<div id="mp-floating-cart"' . (( $this->has_items() ) ? ' class="has-items"' : '') . '>
			<div id="mp-floating-cart-tab" class="clearfix"><span id="mp-floating-cart-total">' . mp_format_currency('', $this->get_total()) . '</span> ' . $this->item_count(false) . '</div>
			<div id="mp-floating-cart-contents">';
	
		if ( $this->has_items() ) {
			$html .= '
				<ul id="mp-floating-cart-items-list">';
		
			foreach ( $items as $item => $qty ) {
				$product = new MP_Product($item);
			
				$html .= '
					<li class="mp-floating-cart-item" id="mp-floating-cart-item-' . $product->ID . '">
						<a class="mp-floating-cart-item-link" href="' . $product->url(false) . '">' . $product->image(false, 'floating-cart', 50) . '
							<div class="mp-floating-cart-item-content">
								<h3 class="mp-floating-cart-item-title">' . $product->title(false) . '</h3>
								<span class="mp-floating-cart-item-attribute"><strong>' . __('Quantity', 'mp') . ':</strong> <em>' . $qty . '</em></span>';
				
				// Display attributes
				if ( $product->is_variation() ) {
					$attributes = $product->get_attributes();
					foreach ( $attributes as $taxonomy => $att ) {
						$term = current($att['terms']);
						$html .= '
									<span class="mp-floating-cart-item-attribute"><strong>' . $att['name'] . ':</strong> <em>' . $term . '</em></span>';
					}
				}
				
				$html .= '
							</div>
						</a>
					</li>';
			}
			
			$html .= '
				</ul>
				<a id="mp-floating-cart-button" href="' . get_permalink(mp_get_setting('pages->cart')) . '">' . __('View Cart', 'mp') . '</a>';
		} else {
			$html .= '
				<div id="mp-floating-cart-no-items">
					<p><strong>' . __('Your shopping cart is empty.', 'mp') . '</strong></p>
					<p>' . __('As you add browse items and add them to your add cart they will show up here.', 'mp') . '</p>
				</div>';
		}
	
		$html .= '
			</div>
		</div>';
		
		if ( ! mp_doing_ajax() ) {
			$html .= '<span class="mp-ajax-loader" style="display:none"><img src="' . mp_plugin_url('ui/images/ajax-loader.gif') . '" alt="" /> ' . __('Adding...' , 'mp') . '</span>';
		}
		
		if ( $echo ) {
			echo $html;
		} else {
			return $html;
		}
	}
	
	/**
	 * Check if cart has a specific item
	 *
	 * @since 3.0
	 * @access public
	 * @param $item_id The item ID
	 * @return int How many of the item are in the cart
	 */
	public function has_item( $item_id ) {
		return mp_arr_get_value($this->_id . '->' . $item_id, $this->_items, 0);
	}
	
	/**
	 * Check if cart has items
	 *
	 * @since 3.0
	 * @access public
	 */
	public function has_items() {
		$items = $this->get_items();
		return ( count($items) > 0 );
	}

	/**
	 * Check if cart contains only downloadable products
	 *
	 * @since 3.0
	 * @access public
	 * @return bool
	 */
	public function is_download_only() {
		if ( ! is_null($this->_is_download_only) ) {
			return $this->_is_download_only;
		}
		
		$items = $this->get_items();
		$this->_is_download_only = true;
		
		foreach ( $items as $item_id => $qty ) {
			$product = new MP_Product($item_id);
			if ( ! $product->is_download() ) {
				$this->_is_download_only = false;
				break;
			}
		}
		
		return $this->_is_download_only;
	}
	
	/**
	 * Display the item count
	 *
	 * @since 3.0
	 * @access public
	 * @param bool $echo
	 */
	public function item_count( $echo = true ) {
		$items = $this->get_items();
		$numitems = 0;
		
		foreach ( $items as $item_id => $qty ) {
			$numitems += $qty;
		}
		
		if ( $numitems == 0 ) {
			$snippet = __('0 items', 'mp');
		} else {
			$snippet = sprintf(_n('1 item', '%s items', $numitems, 'mp'), $numitems);
		}
		
		if ( $echo ) {
			echo $snippet;
		} else {
			return $snippet;
		}
	}
	
	/**
	 * Reset cart ID back to the original
	 *
	 * @since 3.0
	 * @access public
	 */
	public function reset_id() {
		$this->_id = $this->_id_original;
	}

	/**
	 * Set the cart ID
	 *
	 * @since 3.0
	 * @access public
	 * @param int $id
	 */
	public function set_id( $id ) {
		$this->_id = $id;
		
		if ( is_null($this->_id_original) ) {
			$this->_id_original = $id;
		}
	}
	
	/**
	 * Get the calculated price for shipping
	 *
	 * @since 3.0
	 * @access public
	 * @return float The calculated price. False, if shipping address is not available
	 */
	public function shipping_price( $format = false ) {
		$products = $this->_convert_to_objects($items);
		$shipping_plugins = MP_Shipping_API::get_active_plugins();
		$total = $this->get_total();
		

	 //get address
	 $meta = get_user_meta(get_current_user_id(), 'mp_shipping_info', true);
	 $address1 = isset($_SESSION['mp_shipping_info']['address1']) ? $_SESSION['mp_shipping_info']['address1'] : (isset($meta['address1']) ? $meta['address1'] : '');
	 $address2 = isset($_SESSION['mp_shipping_info']['address2']) ? $_SESSION['mp_shipping_info']['address2'] : (isset($meta['address2']) ? $meta['address2'] : '');
	 $city = isset($_SESSION['mp_shipping_info']['city']) ? $_SESSION['mp_shipping_info']['city'] : (isset($meta['city']) ? $meta['city'] : '');
	 $state = isset($_SESSION['mp_shipping_info']['state']) ? $_SESSION['mp_shipping_info']['state'] : (isset($meta['state']) ? $meta['state'] : '');
	 $zip = isset($_SESSION['mp_shipping_info']['zip']) ? $_SESSION['mp_shipping_info']['zip'] : (isset($meta['zip']) ? $meta['zip'] : '');
	 $country = isset($_SESSION['mp_shipping_info']['country']) ? $_SESSION['mp_shipping_info']['country'] : (isset($meta['country']) ? $meta['country'] : '');
	 $selected_option = isset($_SESSION['mp_shipping_info']['shipping_sub_option']) ? $_SESSION['mp_shipping_info']['shipping_sub_option'] : null;

	 //check required fields
	 if ( empty($address1) || empty($city) || !$this->is_valid_zip($zip, $country) || empty($country) || !(is_array($cart) && count($cart)) )
		return false;

		//don't charge shipping if only digital products
	 if ( $this->download_only_cart($cart) ) {
		$price = 0;
	 } else if ( $this->get_setting('shipping->method') == 'calculated' && isset($_SESSION['mp_shipping_info']['shipping_option']) && isset($mp_shipping_active_plugins[$_SESSION['mp_shipping_info']['shipping_option']]) ) {
			//shipping plugins tie into this to calculate their shipping cost
			$price = apply_filters( 'mp_calculate_shipping_'.$_SESSION['mp_shipping_info']['shipping_option'], 0, $total, $cart, $address1, $address2, $city, $state, $zip, $country, $selected_option );
		} else {
			//shipping plugins tie into this to calculate their shipping cost
			$price = apply_filters( 'mp_calculate_shipping_'.$this->get_setting('shipping->method'), 0, $total, $cart, $address1, $address2, $city, $state, $zip, $country, $selected_option );
		}
		
		//calculate extra shipping
	 $extras = array();
	 foreach ($cart as $product_id => $variations) {
			$shipping_meta = get_post_meta($product_id, 'mp_shipping', true);
			foreach ($variations as $variation => $data) {
				 if (!$data['download'])
			 	$extras[] = $shipping_meta['extra_cost'] * $data['quantity'];
			}
	 }
	 $extra = array_sum($extras);

	 //merge
	 $price = round($price + $extra, 2);
	 
		//boot if shipping plugin didn't return at least 0
		if (empty($price))
			return false;
		
		if ($format)
			return $this->format_currency('', $price);
		else
			return round($price, 2);
	}
	
	/**
	 * Show the lightbox popup form
	 *
	 * @since 3.0
	 * @access public
	 * @action wp_footer
	 */
	public function show_zipcode_popup() {
		?>
<div style="display:none">
	<form id="mp-zipcode-form" action="<?php echo admin_url('admin-ajax.php?action=mp-update-zipcode'); ?>" method="post">
		<h2><?php printf(__('Enter your %s', 'mp'), mp_get_setting('zipcode_label', 'zip code')); ?></h2>
	</form>
</div>
<script type="text/javascript">
jQuery(document).ready(function($){
	$.colorbox({
		"inline" : true,
		"href" : "#mp-zipcode-form"
	});
});
</script>
		<?php
	}
	
	/**
	 * Gets the calculated price for taxes based on a bunch of foreign tax laws.
	 *
	 * @access public
	 * @param bool $format (optional) Format number as currency when returned
	 * @return string/float 
	 */
	function tax_price( $format = false ) {
		$items = $this->get_items();

		//get address
		$user = wp_get_current_user();
		$shipping_info = $user->get('mp_shipping_info');

		$state = mp_get_session_value('mp_shipping_info->state', mp_arr_get_value('state', $shipping_info));
		$country = mp_get_session_value('mp_shipping_info->country', mp_arr_get_value('country', $shipping_info));

		//if we've skipped the shipping page and no address is set, use base for tax calculation
		if ( $this->download_only_cart($cart) || mp_get_setting('tax->tax_inclusive') || mp_get_setting('shipping->method') == 'none' ) {
			if ( empty($country) ) {
				$country = mp_get_setting('base_country');
			}
			
			if ( empty($state) ) {
				$state = mp_get_setting('base_province');
			}
		}

		//get total after any coupons
		$totals = array();
		$special_totals = array();
		$coupon_code = $this->get_coupon_code();
	 
		foreach ($cart as $product_id => $variations) {
			//check for special rate
			$special = (bool)get_post_meta($product_id, 'mp_is_special_tax', true);
			
			if ( $special ) {
				$special_rate = get_post_meta($product_id, 'mp_special_tax', true);
			}
			
			foreach ( $variations as $variation => $data ) {
				//if not taxing digital goods, skip them completely
				if ( ! mp_get_setting('tax->tax_digital') && isset($data['download']) && is_array($data['download']) ) {
					continue;
				}

				$product_price = $this->coupon_value_product($coupon_code, $data['price'] * $data['quantity'], $product_id);
			
				if ( mp_get_setting('tax->tax_inclusive') ) {
					$product_price = $product_price / (1 + (float) mp_get_setting('tax->rate'));
				}
			
				if ( $special ) {
					$special_totals[] = $product_price * $special_rate;
				} else {
					$totals[] = $product_price;
				}
			}
		}
		
		$total = array_sum($totals);
		$special_total = array_sum($special_totals);
		
		//add in shipping?
		$shipping_tax = 0;
		if ( mp_get_setting('tax->tax_shipping') && ($shipping_price = $this->shipping_price() ) ) {
			if ( mp_get_setting('tax->tax_inclusive') ) {
				$shipping_tax = $shipping_price - $this->before_tax_price($shipping_price);
			} else {
				$shipping_tax = $shipping_price * (float) mp_get_setting('tax->rate');
			}
		}
		
		//check required fields
		if ( empty($country) || !(is_array($cart) && count($cart)) || ($total + $special_total) <= 0 ) {
			return false;
		}
	
		switch ( mp_get_setting('base_country') ) {
			case 'US':
				// USA taxes are only for orders delivered inside the state
				if ( $country == 'US' && $state == mp_get_setting('base_province') ) {
					$price = round(($total * mp_get_setting('tax->rate')) + $special_total, 2);
				}
			break;
	
			case 'CA':
				 //Canada tax is for all orders in country, based on province shipped to. We're assuming the rate is a combination of GST/PST/etc.
				if ( $country == 'CA' && array_key_exists($state, mp()->canadian_provinces) ) {
					if ( $tax_rate = mp_get_setting("tax->canada_rate->$state") ) {
						$price = round(($total * $tax_rate) + $special_total, 2);
					} else { //backwards compat with pre 2.2 if per province rates are not set
						$price = round(($total * $this->get_setting('tax->rate')) + $special_total, 2);
					}
				}
			break;
	
			case 'AU':
				//Australia taxes orders in country
				if ( $country == 'AU' ) {
					$price = round(($total * $this->get_setting('tax->rate')) + $special_total, 2);
				}
			break;
	
			default:
				//EU countries charge VAT within the EU
				if ( in_array(mp_get_setting('base_country'), mp()->eu_countries) ) {
					if ( in_array($country, mp()->eu_countries) ) {
						$price = round(($total * $this->get_setting('tax->rate')) + $special_total, 2);
					}
				} else {
					//all other countries use the tax outside preference
					if ( mp_get_setting('tax->tax_outside') || (! mp_get_setting('tax->tax_outside') && $country == mp_get_setting('base_country')) ) {
						$price = round(($total * $this->get_setting('tax->rate')) + $special_total, 2);
					}
				}
			break;
		}
		
		if ( empty($price) ) {
			$price = 0;
		}
		
		$price += $shipping_tax;
		
		/**
		 * Filter the tax price
		 *
		 * @since 3.0
		 * @param float $price The calculated tax price.
		 * @param float $total The cart total.
		 * @param MP_Cart $this The current cart object.
		 * @param string $country The user's country.
		 * @param string $state $the user's state/province.
		 */
		$price = apply_filters('mp_tax_price', $price, $total, $this, $country, $state);
		$price = apply_filters('mp_cart/tax_price', $price, $total, $this, $country, $state);
			 
		if ( $format ) {
			return $this->format_currency('', $price);
		} else {
			return $price;
		}
	}
		
	/**
	 * Update the cart cookie
	 *
	 * @since 3.0
	 * @access protected
	 */
	protected function _update_cart_cookie() {
		$expire = strtotime('+1 month');
		if ( empty($this->_items) ) {
			if ( $cart_cookie = mp_get_cookie_value($this->_cookie_id) ) {
				$expire = strotime('-1 month');
			} else {
				return;
			}
		}
		
		setcookie($this->_cookie_id, serialize($this->_items), $expire, COOKIEPATH, COOKIE_DOMAIN);
	}
	
	/**
	 * Constructor function
	 *
	 * @since 3.0
	 * @access private
	 */
	private function __construct() {
		$this->set_id(get_current_blog_id());
		$this->_get_cart_cookie();
		
		// Enqueue styles/scripts
		add_action('wp_enqueue_scripts', array(&$this, 'enqueue_styles_scripts'));
		
		// Display the floating cart html
		add_action('wp_footer', array(&$this, 'floating_cart_html'));
		
		// Ajax hooks
		add_action('wp_ajax_mp_update_cart', array(&$this, 'ajax_update_cart'));
		add_action('wp_ajax_nopriv_mp_update_cart', array(&$this, 'ajax_update_cart'));
	}
}

$GLOBALS['mp_cart'] = MP_Cart::get_instance();