<?

	class GoogleProducts_Params extends Core_Configuration_Model {
		public $record_code = 'googleproducts_params';
		public $target_countries = array('AU', 'BR', 'CN', 'FR', 'DE', 'IT', 'JP', 'NL', 'ES', 'CH', 'GB', 'US'); //possible target countries for google products
	
		public static function create() {
			$config = new self();

			return $config->load();
		}
		
		protected function build_form() {
		
			$this->add_field('output_folder', 'Data Feed Output Folder', 'full', db_varchar)->tab('General')->comment('Enter a folder in the Lemonstand installation folder where you would like the generated data feeds to be stored (i.e. /datafeeds)', 'above');
			$this->add_field('products_path', 'Product Root Path', 'full', db_varchar)->tab('General');
			
			$this->add_field('shop_title', 'Shop Title', 'full', db_varchar)->tab('General')->comment('Enter the name of your store as you want it to appear in the data feed', 'above');
			
			$this->add_field('use_sku_as_gtin', 'Use SKU as GTIN', 'full', db_bool)->tab('General')->renderAs(frm_checkbox)->comment('Select to use product SKU as product GTIN where GTIN is not entered.');
			$this->add_field('use_sku_as_mpn', 'Use SKU as MPN', 'full', db_bool)->tab('General')->renderAs(frm_checkbox)->comment('Select to use product SKU as product MPN where MPN is not entered.');
			
			$this->add_field('generate_tax_info', 'Include tax information (US only)', 'full', db_bool)->tab('General')->renderAs(frm_checkbox)->comment('Select this if you are submitting products that use different tax classes. If you are submitting products from one tax class, your tax settings should be set in the Google Merchant Center settings.');
			$this->add_field('include_hidden_categories', 'Include hidden categories', 'full', db_bool)->tab('General')->renderAs(frm_checkbox)->comment('The module will ignore any category associations for categories that are marked as hidden in LemonStand. Select this if you wish to include hidden categories in the product_type attribute of the data feed.');
			$this->add_field('use_parent_product_url', 'Use parent product url for grouped product url', 'full', db_bool)->tab('General')->renderAs(frm_checkbox)->comment('Select this to use the parent product\'s page as the landing page for all its grouped products.');
			
			//$this->add_field('include_grouped_products', 'Include Grouped Products', 'full', db_bool)->tab('General')->renderAs(frm_checkbox)->comment('Select to generate a separate entry for each of the grouped products if they are grouped by size, colour, pattern or material.');
			
			$countries = Db_DbHelper::queryArray('select id, code, name from shop_countries where code in (:countries) order by name asc', array('countries' => $this->target_countries));
			foreach($countries as $country) {
				$this->add_field('generate_'.$country['code'], $country['name'], 'full', db_bool)->tab('Target Countries')->renderAs(frm_checkbox);
			}
			
			$this->add_form_custom_area('instructions')->tab('Quick Guide');
		}
		
		protected function init_config_data() {
			//default values
			$this->products_path = '/store/product';
			$this->use_sku_as_gtin = 1;
			$this->generate_tax_info = 0;
		}
	}