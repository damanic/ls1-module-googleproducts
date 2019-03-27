<?

	class GoogleProducts_Config extends Backend_Controller {
	
		protected $access_for_groups = array(Users_Groups::admin);
 		public $form_edit_title = 'Google Products Configuration';
		public $form_model_class = 'GoogleProducts_Params';
		public $implement = 'Db_ListBehavior, Db_FormBehavior';
		public $form_redirect = null;
		public $form_edit_save_flash = 'Google Products configuration has been saved.';

		public function __construct() {
			parent::__construct();
			
			$this->app_tab = 'googleproducts';
			$this->app_page = 'config';
			$this->app_module_name = 'Google Products';
			
			$this->app_page_title = 'Configuration';
		}
	
	public function index() {
			try {
				$params = new GoogleProducts_Params();
				$this->viewData['form_model'] = $params->load();
			}
			catch(exception $ex) {
				$this->_controller->handlePageError($ex);
			}
		}
		
		public function index_onSave() {
			try {
				$config = new GoogleProducts_Params();
				$config = $config->load();

				$config->save(post($this->form_model_class, array()), $this->formGetEditSessionKey());

				echo Backend_Html::flash_message('Google Products configuration has been successfully saved.');
			}
			catch(Exception $ex) {
				Phpr::$response->ajaxReportException($ex, true, true);
			}
		}


	}