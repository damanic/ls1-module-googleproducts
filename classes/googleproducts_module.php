<?

	class GoogleProducts_Module extends Core_ModuleBase {
		
		private $products_count = 0; //count number of products added to feed
		const max_products = 100000; //Google only processes 100,000 products per file
		const max_categories = 10; // Google allows only 10 product_type elements
		private $params;
		private $currency;
		private $target_countries = array('AU', 'BR', 'CN', 'FR', 'DE', 'IT', 'JP', 'NL', 'ES', 'CH', 'GB', 'US');
		public $currencies = array(
			'AU' => 'AUD', 'BR' => 'BRL', 'CN' => 'CNY', 'FR' => 'EUR', 'DE' => 'EUR', 'IT' => 'EUR', 'JP' => 'JPY', 'NL' => 'EUR', 'ES' => 'EUR', 'CH' => 'CHF',	'US' => 'USD',	'GB' => 'GBP'
		);
		public $weight_units = array ('LBS' => 'lb', 'KGS' => 'kg');
		private $countries = null;
		private $weight_unit = null;
		private $converter_cache = array();
		private $tax_class_cache = array();
		private $category_path_cache = array();
		private $variant_options = array('COLOR', 'COLOUR', 'MATERIAL', 'PATTERN', 'SIZE');
		private $postponed_entries = array(); //used for generating item groups / variants
		private $test_product;
		private $display_prices_incl_tax;
		 
		protected function createModuleInfo() {
			return new Core_ModuleInfo(
				"Google Products",
				"Generate LemonStand product feed that can be uploaded to Google Merchant to display your products in Google Shopping results",
				"Limewheel Creative Inc,  MJMAN.NET" );
		}
		
		public function listTabs($tabCollection) {
			$user = Phpr::$security->getUser();
			
			if (!$user->is_administrator())
				return;
		
			$menu_item = $tabCollection->tab('googleproducts', 'Google Products', 'config', 95);
			$menu_item->addSecondLevel('config', 'Configuration', 'config');
			$menu_item->addSecondLevel('categories', 'Categories', 'categories');
		}
		
		public function subscribeEvents() {
			Backend::$events->addEvent('shop:onExtendProductModel', $this, 'extend_product_model');
			Backend::$events->addEvent('shop:onExtendProductForm', $this, 'extend_product_form');
			Backend::$events->addEvent('shop:onGetProductFieldOptions', $this, 'get_product_condition_options');
			Backend::$events->addEvent('shop:onGetProductFieldOptions', $this, 'get_product_gender_options');
			Backend::$events->addEvent('shop:onGetProductFieldOptions', $this, 'get_product_age_group_options');
			
			Backend::$events->addEvent('shop:onExtendCategoryModel', $this, 'extend_category_model');
			Backend::$events->addEvent('shop:onExtendCategoryForm', $this, 'extend_category_form');
			Backend::$events->addEvent('shop:onGetCategoryFieldOptions', $this, 'get_category_field_options');
		}
		
		public function get_category_field_options($field_name, $current_key_value) {
			if ($field_name == 'googleproducts_category')
			{
				$options = array(
					null => '<none>'
				);
				$gcategories = Db_DbHelper::objectArray('select id, full_name from googleproducts_category');
				foreach ($gcategories as $gcategory)
				{
					$options[$gcategory->id] = $gcategory->full_name;
				}
			 
				if ($current_key_value == -1)
					return $options;
			 
				if (array_key_exists($current_key_value, $options))
					return $options[$current_key_value];
			}
		}
		
		public function extend_category_model($category) {

			$category->add_relation('belongs_to', 
				'googleproducts_category', array('class_name'=>'GoogleProducts_Category', 'foreign_key'=>'x_googleproducts_category_id')
			);
			$category->define_relation_column('googleproducts_category', 'googleproducts_category', 'Google Products Category', db_varchar, '@full_name')->invisible();
		}
		
		public function extend_category_form($category) {
			$category->add_form_partial(dirname(__FILE__).'/../partials/_shop_categories_hint.htm')->tab('Google Products');
			$category->add_form_field('googleproducts_category')->tab('Google Products');
		}
		
		public function extend_product_model($product) {
			$product->define_column('x_googleproducts_condition', 'Product Condition')->defaultInvisible()->listTitle('Product Condition');
			$product->define_column('x_googleproducts_brand', 'Product Brand')->defaultInvisible()->listTitle('Product Brand');
			$product->define_column('x_googleproducts_gtin', 'Product GTIN')->defaultInvisible()->listTitle('Product GTIN');
			$product->define_column('x_googleproducts_mpn', 'Product MPN')->defaultInvisible()->listTitle('Product MPN');

			$product->define_column('x_googleproducts_id_exists', 'Identifier Exists')->defaultInvisible()->listTitle('Identifier exists');
			
			$product->define_column('x_googleproducts_gender', 'Gender')->defaultInvisible()->listTitle('Product Gender');
			$product->define_column('x_googleproducts_age_group', 'Age Group')->defaultInvisible()->listTitle('Product Age Group');
			$product->define_column('x_googleproducts_size', 'Size')->defaultInvisible()->listTitle('Product Size');
			$product->define_column('x_googleproducts_color', 'Color')->defaultInvisible()->listTitle('Product Color');
			
			$product->define_column('x_googleproducts_included', 'Include this product when generating the data feed')->defaultInvisible()->listTitle('Included in GP data feed');
			$product->define_column('x_googleproducts_description', 'Google Products Description')->defaultInvisible()->listTitle('GP Description');

			$product->define_column('x_googleproducts_promotion_id', 'Promotion ID')->defaultInvisible()->listTitle('Google Promotion ID');
		}
		
		public function extend_product_form($product, $context = null) {
			$tab_name = 'Google Product Attributes';
			if($context != 'preview')
			{
				$product->add_form_field('x_googleproducts_included')->tab($tab_name)->renderAs(frm_checkbox);
				$product->add_form_section('The following are used when generating the Google Products data feed. ')->tab($tab_name);

				$product->add_form_field('x_googleproducts_brand', 'left')->tab($tab_name)->comment('Leave empty to use the manufacturer name as the brand.', 'above');
				$product->add_form_field('x_googleproducts_mpn', 'right')->tab($tab_name)->comment('If available, enter the manufacturer part number.', 'above');
				
				$product->add_form_field('x_googleproducts_gtin', 'left')->tab($tab_name)->comment('Enter the UPC, EAN, JAN or ISBN code of the product or leave empty to use the product SKU.', 'above');
				$product->add_form_field('x_googleproducts_condition', 'right')->emptyOption('<default>')->tab($tab_name)->renderAs(frm_dropdown)->comment('Required for all products. When left empty "new" will be used.', 'above');
				
				if ($product->is_new_record())
					$product->x_googleproducts_id_exists = true;
					
				$product->add_form_field('x_googleproducts_id_exists', 'left')->tab($tab_name)->comment('Uncheck the checkbox if the product identifier is required for the product\'s category but don\’t exists.', 'above');

				$product->add_form_field('x_googleproducts_description')->tab($tab_name)->comment('Optional. If left empty, the product\'s short description will be used.', 'above');

				$product->add_form_section('If you intend to promote this product in a google campaigns, enter a promotion ID', 'Promote')->tab($tab_name);
				$product->add_form_field('x_googleproducts_promotion_id', 'left')->tab($tab_name)->comment('Unicode characters (Recommended: ASCII only): alphanumeric, underscores, and dashes. 1–50 characters.');


				$product->add_form_section('For all products in Apparel category, the following fields are required. They are optional for other products. If left empty, LemonStand will look for attributes with the same names and use those values when generating the product feed.', 'Apparel properties')->tab($tab_name);
				$product->add_form_field('x_googleproducts_gender', 'right')->emptyOption('<none>')->tab($tab_name)->comment('Required for all Clothing products.', 'above')->renderAs(frm_dropdown);
				$product->add_form_field('x_googleproducts_age_group', 'left')->emptyOption('<none>')->tab($tab_name)->comment('Required for all Clothing products.', 'above')->renderAs(frm_dropdown);
				$product->add_form_field('x_googleproducts_size', 'right')->tab($tab_name)->comment('Required for all Clothing and Shoes products.', 'above');
				$product->add_form_field('x_googleproducts_color', 'left')->tab($tab_name)->comment('Required for all Clothing & Accessories products.', 'above');
			}
		}
		
		public function get_product_condition_options($field_name, $key_index = -1) {
			if ($field_name == 'x_googleproducts_condition') 	{
				$options = array(
					'new' => 'new',
					'used' => 'used',
					'refurbished' => 'refurbished'
				);
			 
			if ($key_index == -1)
				return $options;
			 
			if (array_key_exists($key_index, $options))
				return $options[$key_index];
			}
		}
		
		public function get_product_gender_options ($field_name, $key_index = -1) {
			if ($field_name == 'x_googleproducts_gender') 	{
				$options = array(
					'male' => 'Male',
					'female' => 'Female',
					'unisex' => 'Unisex'
				);
			 
			if ($key_index == -1)
				return $options;
			 
			if (array_key_exists($key_index, $options))
				return $options[$key_index];
			}
		}
		
		public function get_product_age_group_options ($field_name, $key_index = -1) {
			if ($field_name == 'x_googleproducts_age_group') 	{
				$options = array(
					'adult' => 'Adult',
					'kids' => 'Kids'
				);
			 
			if ($key_index == -1)
				return $options;
			 
			if (array_key_exists($key_index, $options))
				return $options[$key_index];
			}
		}
		
		public function register_access_points() {
			return array(
				'googleproducts_update_feeds' =>'generate_datafeeds', //generate and save data feed files
				'googleproducts_datafeed' =>'generate_datafeed_xml'	 //generate and output a single data feed file (example /googleproducts_datafeed/US/xml/ )
			);
		}
		
		/**
		* Used to ensure object variables are set with valid values
		*/
		public function set_object_params() {
			if(!$this->params) $this->params = GoogleProducts_Params::create();
			if(!$this->currency) $this->currency = Shop_CurrencySettings::get();
			if(!$this->weight_unit) $this->weight_unit = Shop_ShippingParams::get();
			$this->display_prices_incl_tax = Shop_CheckoutData::display_prices_incl_tax();
			
			if(!$this->countries) {
				$countries = Db_DbHelper::queryArray('select id, code from shop_countries where code in (:countries)', array('countries' => $this->target_countries));
				foreach($countries as $country) {
					$c[$country['code']] = array ('id' => $country['id'], 'currency' => $this->currencies[$country['code']]);
				}
				$this->countries = $c;
			}
			if(!$this->test_product) {
				$this->test_product = Shop_Product::create();
			}
		}
		
		/**
		* Generates the product data feeds and saves them to files specified in the googleproducts configuration
		*/
		public function generate_datafeeds() {
			$this->set_object_params();
			
			foreach($this->countries as $code => $country) {
				$g = 'generate_'.$code;
				if($this->params->$g) 	$this->generate_datafeed(array(0 => $code));
			}
		}
		
		/**
		* Generates and saves a single data feed
		* @param array $param where first element with index 0 is the country code (example array(0=>'US')), index 1 is optional: set to 'xml' to output the xml instead of saving it to file
		*/
		public function generate_datafeed($param) {
			$this->set_object_params();
			$this->products_count = 0;
			$this->generate_datafeed_xml($param);
		}
		
		/**
		* Saves file contents to a file (located at PATH_APP.$folder). If file exists it is overwritten, if not it is created.
		* @param string $text the contents to be written to the file
		* @param string $filename the name of the file to write to
		* @param string $folder folder to save the filename to
		*/
		public function save_datafeed($text, $filename, $folder='') {
			$full_path = PATH_APP.$folder.'/'.$filename;
			try {
				if (!file_exists($full_path) || !is_file($full_path)) {
					//if file does not exist, create an empty one with that name
					if(!$file_handle = @fopen($full_path, 'w')) {
						throw new Phpr_ApplicationException('Could not create the file. Please check the folder permissions or create the file yourself.');
					}
					else {
						fwrite($file_handle, ' ');
						chmod($full_path, Phpr_Files::getFilePermissions());
						fclose($file_handle);
					}
				}

				if(!@file_put_contents($full_path, $text)) {
					throw new Phpr_ApplicationException('Unable to save changes to the file.');
				} else {
					//echo "Successfully updated ".$filename." with ".$this->products_count." products. ";
				}
			}
			catch (Exception $ex) {
				Phpr::$response->ajaxReportException($ex, true, true);
			}
		}
		
		public function append_to_file($text_to_add, $filename, $folder) {
			$full_path = PATH_APP.$folder.'/'.$filename;
			try {
				if (!file_exists($full_path) || !is_file($full_path)) {
					//if file does not exist, create an empty one with that name
					if(!$file_handle = @fopen($full_path, 'a')) {
						throw new Phpr_ApplicationException('Could not create the file. Please check the folder permissions or create the file yourself.');
					}
				}
	
				if(!@file_put_contents($full_path, $text_to_add, FILE_APPEND)) {
					throw new Phpr_ApplicationException('Unable to save changes to the file.');
				} else {
					//echo "Successfully updated ".$filename." with ".$this->products_count." products. ";
				}
			}
			catch (Exception $ex) {
				Phpr::$response->ajaxReportException($ex, true, true);
			}
		}
		
		/**
		* Generates a data feed and returns the xml as a string
		* @param array $param where first element with index 0 is the country code, set the second element to 'xml' to echo the generated xml instead of returning it as string (example array(0=>'US', 1=>'xml')) 
		* @return string or null
		*/
		public function generate_datafeed_xml($param) {
			if(array_key_exists(1, $param) && $param[1] == 'xml') $output_xml = true;
			else $output_xml = false;
			
			if(!array_key_exists(0, $param) || !array_key_exists($param[0], $this->currencies))
				return false;
			if(!$this->params) $this->set_object_params();
			$country = $param[0];
			$opening_xml = '<?xml version="1.0" encoding="UTF-8"?><feed xmlns="http://www.w3.org/2005/Atom" xmlns:g="http://base.google.com/ns/1.0"><title>'.$this->cdata_wrap($this->params->shop_title).'</title><link rel="self" href="'.site_url('').'"/><updated>'.date('c').'</updated>';
			if($output_xml) {
				header("Content-Type: application/xml");
				echo $opening_xml;
			} else {
				$filename = 'datafeed_'.$param[0].'.xml';
				$this->save_datafeed($opening_xml, $filename, $this->params->output_folder);
			}

			//$product_list = new Shop_Product(null, array('no_column_init' => true, 'no_validation' => true));
			//$product_list = $product_list->apply_filters()->where('enabled=1')->limit(self::max_products)->order('shop_products.updated_at desc')->find_all();
			$product_list = Db_DbHelper::objectArray('select sp.id, sp.name, sp.url_name, sp.page_id, sp.short_description, sp.sku, sp.tax_class_id, sp.price, sp.track_inventory, sp.in_stock, sp.stock_alert_threshold, sp.allow_pre_order, sp.x_googleproducts_condition, sp.x_googleproducts_brand, sp.x_googleproducts_id_exists, sp.manufacturer_id, sp.x_googleproducts_description, sp.x_googleproducts_gtin, sp.x_googleproducts_mpn, sp.x_googleproducts_gender,  sp.x_googleproducts_age_group, sp.x_googleproducts_color, sp.x_googleproducts_size, sp.weight, sp.grouped_attribute_name, sp.grouped_option_desc, sp.price_rules_compiled, sp.tier_price_compiled, sp.price_rule_map_compiled, sp.on_sale, sp.sale_price_or_discount, sp.x_googleproducts_promotion_id, sm.name as manufacturer_name from shop_products sp left join shop_manufacturers sm on (sp.manufacturer_id = sm.id) where enabled is true and (grouped is null or grouped = 0) and x_googleproducts_included is true order by updated_at limit '.self::max_products);

			foreach($product_list as $product) {
				if($entry = $this->generate_datafeed_entry($product, $country, $product)) {
					if($output_xml) echo $entry;
					else $this->append_to_file($entry, $filename, $this->params->output_folder);
				}
				if(count($this->postponed_entries)) {
					//add product variants to the feed
					foreach($this->postponed_entries as $entry) {
						if($output_xml) echo $entry;
						else $this->append_to_file($entry, $filename, $this->params->output_folder);
					}
					$this->postponed_entries = array();
				}
			}
			
			if($output_xml) {
				echo '</feed>';
			}	else 	{
				$this->append_to_file('</feed>', $filename ,$this->params->output_folder);
				echo "Successfully updated ".$filename." with ".$this->products_count." products. ";
			}
		}
		
		/**
		* Uses a specified tax_class_id to calculate the price with tax for a selected country
		* @param numeric $price base price without tax
		* @param numeric $tax_class_id tax_class_id to use for the calculation
		* @param string $country country code (GB, UK etc)
		* @return numeric $price price with tax added
		*/
		public function get_price_with_tax($price, $tax_class_id, $country) {
			$total_taxes = 0;
			if(isset($this->total_tax_cache[$tax_class_id][$country])) {
				$total_taxes = $this->total_tax_cache[$tax_class_id][$country];
			} else {
				$shipping_info = new Shop_CheckoutAddressInfo();
				$shipping_info->country = $this->countries[$country]['id'];
				$taxes = Shop_TaxClass::get_tax_rates_static($tax_class_id, $shipping_info);
				//$taxes = Shop_TaxClass::eval_total_tax($taxes);
				if(count($taxes)) {
					foreach ($taxes as $tax)
						$total_taxes += $tax->tax_rate;
				}
				$this->total_tax_cache[$tax_class_id][$country] = $total_taxes;
			}
			$price = round($price + ($price * $total_taxes), 2);
			return $price;
		}
		
		public function cdata_wrap($text) {
			return "<![CDATA[".$text."]]>";
		}
		
		/**
		* Generates a data feed entry for one products and selected target country
		* @param Shop_Product $product
		* @param string $country country code of the target country (GB, UK etc)
		* @param Shop_Product $parent_product (should be either the parent product when generating an entry for a grouped product or the same as the first parameter)
		* @return DOMElement $entry
		*/
		public function generate_datafeed_entry($product, $country, $parent_product = null) {
			if($this->products_count < self::max_products) {
				if(is_null($parent_product))
					$parent_product = $product;
				
				$entry = '
				<entry>';
				$entry .= '<title>'.$this->cdata_wrap($product->name).'</title>';
				
				//should we use the url of the parent product for grouped products url?
				if($this->params->use_parent_product_url)
					$url_product = $parent_product;
				else $url_product = $product;
				
				$root_url = Phpr::$request->getRootUrl();
				if($parent_product->page_id)
					$page_url = self::get_theme_product_url($parent_product, $this->params->products_path);
				else $page_url = $this->params->products_path;
				$product_url = site_url($page_url.'/'.$url_product->url_name);
				$entry .= '<link>'.$product_url.'</link>';
				
				if(strlen($product->x_googleproducts_description))
					$entry .= '<summary>'.$this->cdata_wrap($product->x_googleproducts_description).'</summary>';
				else
					$entry .= '<summary>'.$this->cdata_wrap($product->short_description).'</summary>';
				$entry .= '<g:id>'.$this->cdata_wrap($product->sku.'_'.$country).'</g:id>';
				if($product->x_googleproducts_condition != '')
					$entry .= '<g:condition>'.$product->x_googleproducts_condition.'</g:condition>';
				else
					$entry .= '<g:condition>new</g:condition>';
				
				$price = $product->price;
				$price_no_tax = $price;
				$price_with_tax = $this->get_price_with_tax($price, $product->tax_class_id, $country);
				if($country != 'US') {
					//all countries except for US require price submitted with tax
					$price = $price_with_tax;
				}
				
				//correct currency
				if($this->currency->code != $this->currencies[$country]) {
					if(!array_key_exists($country, $this->converter_cache)) {
						$this->converter_cache[$country] = new Shop_CurrencyConverter();
					}
					$price = $this->converter_cache[$country]->convert($price, $this->currency->code, $this->currencies[$country]);
					$currency = $this->currencies[$country];
				} else {
					$currency = $this->currency->code;
				}
				$entry .= '<g:price>'.$price.' '.$currency.'</g:price>';
				
				//check if there is a sale price on the item
				$proxy = new Db_ActiverecordProxy( $product->id, 'Shop_Product', $product );
				$sale_price = $proxy->get_sale_price();
				$sale_price_no_tax = $sale_price;
				
				if($this->display_prices_incl_tax) $price = $price_with_tax;
				else $price = $price_no_tax;
				
				if($price != $sale_price) {
					if($country != 'US' && !$this->display_prices_incl_tax) {
						$sale_price = $this->get_price_with_tax($sale_price, $product->tax_class_id, $country);
					}
					//correct currency
					if($this->currency->code != $this->currencies[$country]) {
						if(!array_key_exists($country, $this->converter_cache)) {
							$this->converter_cache[$country] = new Shop_CurrencyConverter();
						}
						$sale_price = $this->converter_cache[$country]->convert($sale_price, $this->currency->code, $this->currencies[$country]);
					}
					$entry .= '<g:sale_price>'.$sale_price.' '.$currency.'</g:sale_price>';
					//figure out when the sale price expires
					$rule_map = unserialize($product->price_rule_map_compiled);
					if(!$product->on_sale && array_key_exists(1, $rule_map))
					{
						$expiry_date = Db_DbHelper::scalar('select min(date_end) from shop_catalog_rules where id in (:rule_ids)', array('rule_ids' => $rule_map[1]));
						if($expiry_date)
						{
							$entry .= '<g:sale_price_effective_date>';
							$timeZone = Phpr::$config->get('TIMEZONE');
							//$timeZoneObj = new DateTimeZone( $timeZone);
							$date = new DateTime(null, new DateTimeZone($timeZone));
							$start_date = $date->format('Y-m-d\TH:i:sO');
							$date = new DateTime($expiry_date, new DateTimeZone($timeZone));
							$end_date = $date->format('Y-m-d\T23:59:59O');
							$entry .= $start_date.'/'.$end_date.'</g:sale_price_effective_date>';
						}
					}
				}
				
				if($product->stock_alert_threshold > $product->in_stock) {
					if($product->allow_pre_order){
						$availability = 'preorder';
					} else {
						$availability = 'out of stock';
					}
				} else $availability = 'in stock';
				$entry .= '<g:availability>'.$availability.'</g:availability>';
				
				$images = Db_DbHelper::objectArray("select disk_name from db_files where master_object_class = 'Shop_Product' and field = 'images' and master_object_id = :product_id order by sort_order asc", array('product_id'=>$product->id));
				if(count($images)) {
					foreach($images as $index => $image) {
						if($index > 0 && $index < 11) {
							$entry .= '<g:additional_image_link>'.site_url('/uploaded/public/'.$image->disk_name).'</g:additional_image_link>';
						}
						elseif($index == 0) {
							$entry .= '<g:image_link>'.site_url('/uploaded/public/'.$image->disk_name).'</g:image_link>';
						}
					}
				} elseif($country != 'JP') {
					//image is a required element for all items when target country is not Japan
					return false;
				}
				
				$gcategory_added = false;
				
				if(!$this->params->include_hidden_categories)
					$include_hidden = " and (sc.category_is_hidden != 1 or sc.category_is_hidden is null)";
				else $include_hidden = "";
				
				//for categories, use the parent product
				$category_list = Db_DbHelper::objectArray("select * from shop_products_categories spc inner join shop_categories sc on (spc.shop_category_id = sc.id) where spc.shop_product_id = :product_id".$include_hidden, array('product_id'=>$parent_product->id));
				$categories_count = 0;
				foreach($category_list as $index => $category) {
					//get the google products categories assigned to this shop category
					if(!$gcategory_added) {
						if(strlen($category->x_googleproducts_category_id)) {
							$gcategories = Db_DbHelper::objectArray('select id, full_name from googleproducts_category where id=?',$category->x_googleproducts_category_id);
							if (sizeof($gcategories) > 0) {
								$entry .= "<g:google_product_category>".$this->cdata_wrap($gcategories[0]->full_name).'</g:google_product_category>';
								$gcategory_added = true;
							}
						}
						else {
							$gcategories = Db_DbHelper::objectArray('select id, full_name from googleproducts_category where shop_category_id=?',$category->id);			
							if (sizeof($gcategories) > 0) {
								$entry .= "<g:google_product_category>".$this->cdata_wrap($gcategories[0]->full_name).'</g:google_product_category>';
								$gcategory_added = true;
							}
						}
					}
					if($categories_count < self::max_categories)	{
						$entry .= '<g:product_type>'.$this->cdata_wrap($this->get_category_path($category)).'</g:product_type>';
						$categories_count ++;
					}
				}
				
				if($product->x_googleproducts_mpn) {
					$entry .= '<g:mpn>'.$this->cdata_wrap($product->x_googleproducts_mpn).'</g:mpn>';
				} elseif($this->params->use_sku_as_mpn) {
					$entry .= '<g:mpn>'.$this->cdata_wrap($product->sku).'</g:mpn>';
				}
				if($product->x_googleproducts_gtin) {
					$entry .= '<g:gtin>'.$this->cdata_wrap($product->x_googleproducts_gtin).'</g:gtin>';
				} elseif($this->params->use_sku_as_gtin) {
					$entry .= '<g:gtin>'.$this->cdata_wrap($product->sku).'</g:gtin>';
				}
				
				if ($product->x_googleproducts_id_exists)
					$entry .= '<g:identifier_exists>TRUE</g:identifier_exists>';
				else
					$entry .= '<g:identifier_exists>FALSE</g:identifier_exists>';

				if($product->x_googleproducts_brand) {
					$entry .= '<g:brand>'.$this->cdata_wrap($product->x_googleproducts_brand).'</g:brand>';
				} elseif($brand = $this->get_product_attribute($product->id, 'brand')) {
					$entry .= '<g:brand>'.$this->cdata_wrap($brand).'</g:brand>';
				} elseif($product->manufacturer_name) {
					$entry .= '<g:brand>'.$this->cdata_wrap($product->manufacturer_name).'</g:brand>';
				}
				
				//apparel fields - use custom fields or if those are empty, check product attributes
				if($product->x_googleproducts_gender) {
					$entry .= '<g:gender>'.$product->x_googleproducts_gender.'</g:gender>';
				} elseif($gender = $this->get_product_attribute($product->id, 'gender')) {
					$entry .= '<g:gender>'.$gender.'</g:gender>';
				}
				
				if($product->x_googleproducts_age_group) {
					$entry .= '<g:age_group>'.$product->x_googleproducts_age_group.'</g:age_group>';
				} elseif($age_group = $this->get_product_attribute($product->id, 'age group')) {
					$entry .= '<g:age_group>'.$age_group.'</g:age_group>';
				}
				
				if($product->x_googleproducts_color) {
					$entry .= '<g:color>'.$this->cdata_wrap($product->x_googleproducts_color).'</g:color>';
				} elseif(strtoupper($product->grouped_attribute_name) == 'COLOR' || strtoupper($product->grouped_attribute_name) == 'COLOUR') {
					$entry .= '<g:color>'.$this->cdata_wrap($product->grouped_option_desc).'</g:color>';
				} elseif($color = $this->get_product_attribute($product->id, 'color')) {
					$entry .= '<g:color>'.$this->cdata_wrap($color).'</g:color>';
				}
				
				if($product->x_googleproducts_size) {
					$entry .= '<g:size>'.$this->cdata_wrap($product->x_googleproducts_size).'</g:size>';
				} elseif(strtoupper($product->grouped_attribute_name) == 'SIZE') {
					$entry .= '<g:size>'.$this->cdata_wrap($product->grouped_option_desc).'</g:size>';
				} elseif($size = $this->get_product_attribute($product->id, 'size')) {
					$entry .= '<g:size>'.$this->cdata_wrap($size).'</g:size>';
				}
				
				if($product->weight) {
					$entry .= '<g:shipping_weight>'.$product->weight.' '.$this->weight_units[$this->weight_unit->weight_unit].'</g:shipping_weight>';
				}
				
				//include tax information if required (US only)
				if($country=='US' && $this->params->generate_tax_info) {
					$tax_class =$this->get_tax_class($product->tax_class_id);
					$rates = $tax_class->rates;
					foreach($rates as $rate) {
						$entry .= '<g:tax>';
						$entry .= '<g:country>'.$rate['country'].'</g:country>';
						$region = '';
						if($rate['zip'] != '*') $region = $rate['zip'];
						elseif($rate['state'] != '*') $region = $rate['state'];
						if($region != '')
							$entry .= '<g:region>'.htmlentities($region).'</g:region>';
						$entry .= '<g:rate>'.$rate['rate'].'</g:rate>';
						$entry .= '</g:tax>';
					}
				}
				
				//variants support
				if(in_array(strtoupper($product->grouped_attribute_name), $this->variant_options) && $product->grouped_option_desc && !isset($product->item_group_id)) {
					//Product contains grouped products that match Google's specification for variants
					$item_group_id = 'G-'.$product->sku.'_'.$country;
					//find grouped products and add them to postponed entries
					$product_list = Db_DbHelper::objectArray('select sp.id, sp.x_googleproducts_id_exists, sp.name, sp.url_name, sp.page_id, sp.short_description, sp.sku, sp.tax_class_id, sp.price, sp.track_inventory, sp.in_stock, sp.stock_alert_threshold, sp.allow_pre_order, sp.x_googleproducts_condition, sp.x_googleproducts_brand, sp.manufacturer_id, sp.x_googleproducts_description, sp.x_googleproducts_gtin, sp.x_googleproducts_mpn, sp.x_googleproducts_gender,  sp.x_googleproducts_age_group, sp.x_googleproducts_color, sp.x_googleproducts_size, sp.weight, :grouped_attribute_name as grouped_attribute_name, sp.grouped_option_desc, sp.price_rules_compiled, sp.tier_price_compiled, sp.price_rule_map_compiled, sp.on_sale, sp.sale_price_or_discount, sp.x_googleproducts_promotion_id, sm.name as manufacturer_name, :item_group_id as item_group_id from shop_products sp left join shop_manufacturers sm on (sp.manufacturer_id = sm.id) where enabled is true and sp.product_id = :product_id and x_googleproducts_included is true order by updated_at limit '.self::max_products, array('item_group_id' => $item_group_id, 'grouped_attribute_name' => $product->grouped_attribute_name, 'product_id' => $product->id));
					foreach($product_list as $grouped_product) {
						if($grouped_entry = $this->generate_datafeed_entry($grouped_product, $country, $product)) {
							array_push($this->postponed_entries, $grouped_entry);
							//if at least one group item was added, save the group ID
							$product->item_group_id = $item_group_id;
						}
					}
				}
				if(isset($product->item_group_id)) {
					$entry .= '<g:item_group_id>'.$this->cdata_wrap($product->item_group_id).'</g:item_group_id>';
				}

				if(isset($product->x_googleproducts_promotion_id)) {
					$entry .= '<g:promotion_id>'.$this->cdata_wrap($product->x_googleproducts_promotion_id).'</g:promotion_id>';
				}
				
				$this->products_count++;
				$entry .= '</entry>';
				return $entry;
			}
			else return false;
		}
		
		/**
		* Returns the tax class object
		* @param number $tax_class_id
		* @return Shop_TaxClass
		**/
		private function get_tax_class($tax_class_id) {
			if(!array_key_exists($tax_class_id, $this->tax_class_cache)) {
				$this->tax_class_cache[$tax_class_id] = Shop_TaxClass::create()->where('id=?', $tax_class_id)->find();
			}
			return $this->tax_class_cache[$tax_class_id];
		}
		
		/**
		* Returns the category path as text (Parent > Category)
		* @param object $category (uses three variables, id, name and category_id (optional))
		* @return string $path
		*/
		public function get_category_path($category) {
			if(!isset($this->category_path_cache[$category->id])) {
				$path = '';
				if($category->category_id) {
					$category_obj = Shop_Category::create()->where('id=?', $category->id)->find_all();
					$parents = $category_obj->get_parents();
					foreach($parents as $i=>$parent) {
						$path .= $parent->name.' > ';
					}
				}
				$path .= $category->name;
				$this->category_path_cache[$category->id] = $path;
			}
			return $this->category_path_cache[$category->id];
		}
		
		/**
		* Returns the value of a set attribute for a set product
		* @param number $product_id id of the product
		* @param string $name name of the attribute
		* @return string
		*/
		public function get_product_attribute($product_id, $name) {
			return Db_DbHelper::scalar("select value from shop_product_properties where product_id=:product_id and upper(name) = :name", array('product_id'=>$product_id, 'name'=>$name));
		}
		
		/**
		* Finds and returns the url of a CMS page associated with a product
		* @param Shop_Product $product the product we need the CMS page for
		* @param string $default value to return if theming is not enabled or no CMS page is found
		* @return string url of the found CMS page or default return value
		*/
		public static function get_theme_product_url($product, $default = null)
		{
			if(Cms_Theme::is_theming_enabled() && $active_theme = Cms_Theme::get_active_theme()) {
				$page = Db_DbHelper::scalar("select url from pages inner join cms_page_references cpr on (pages.id = cpr.page_id)
					where cpr.object_id=:product_id
					and object_class_name = 'Shop_Product'
					and reference_name = 'page_id'
					and page_id in (select id from pages where theme_id = :theme_id)", array('product_id' => $product->id, 'theme_id' => $active_theme->id));
				if($page)
					return $page;
				else return $default;
			}
			elseif(!Cms_Theme::is_theming_enabled() && $product->page_id) {
				if($page = Db_DbHelper::scalar('select url from pages where id=:page_id', array('page_id' => $product->page_id)))
					return $page;
				else return $default;
			}
			else {
				return $default;
			}
		}
	}