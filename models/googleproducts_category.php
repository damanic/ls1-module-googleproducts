<?

	class GoogleProducts_Category extends Db_ActiveRecord {
		public $table_name = 'googleproducts_category';
		public $implement = 'Db_Act_As_Tree';
		
		public $belongs_to = array(
			'googleproducts_categories'=>array('class_name'=>'GoogleProducts_Category', 'foreign_key'=>'googleproducts_category_id'),
			'shop_categories'=>array('class_name'=>'Shop_Category', 'foreign_key'=>'shop_category_id')
		);

		public static function create($init_columns = false) {
			if	($init_columns)
				return new self();
			else 
				return new self(null, array('no_column_init'=>true, 'no_validation'=>true));
		}
		
		public function define_columns($context = null) {
			$this->define_column('name', 'Google Products Category Name')->invisible()->validation()->fn('trim')->required();
			$this->define_column('full_name', 'Google Products FULL Category Name')->order('asc')->validation()->fn('trim')->required();
			$this->define_relation_column('shop_category_id', 'shop_categories', 'Shop category', db_varchar, '@name');
		}
		
		public function define_form_fields($context = null) {
			$this->add_form_field('name');
			$this->add_form_field('full_name');
			$this->add_form_section('Select shop category for: '.$this->full_name);
			$this->add_form_field('shop_category_id')->emptyOption('<none>');
		}
	 }