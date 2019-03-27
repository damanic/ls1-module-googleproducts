<?

	class GoogleProducts_Categories extends Backend_Controller {
	
		public $implement = 'Db_ListBehavior, Db_FormBehavior';
		
		public $list_model_class = 'GoogleProducts_Category';
		public $list_search_enabled = true;
		public $list_search_fields = array('@full_name');
		public $list_search_prompt = 'find categories by name';
		public $list_record_url = null;
		
		protected $access_for_groups = array(Users_Groups::admin);
		public $form_model_class = 'GoogleProducts_Category';
		public $form_redirect = null;
		public $form_edit_title = 'Edit Google Products Category';
		public $form_not_found_message = 'Google Products Category not found';

		public function __construct() {
			parent::__construct();
			
			$this->app_tab = 'googleproducts';
			$this->app_page = 'categories';
			$this->app_module_name = 'Google Products';
			
			$this->form_redirect = url('/googleproducts/categories/');
			$this->list_record_url = url('/googleproducts/categories/edit/');
		}
		
		public function index() {
			$this->app_page_title = 'Categories';
		}
	
	
	}