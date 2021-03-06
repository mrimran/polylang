<?php

class Admin_Filters_Post_Test extends PLL_UnitTestCase {
	static $editor;

	static function wpSetUpBeforeClass() {
		parent::wpSetUpBeforeClass();

		self::$polylang->model->post->register_taxonomy();

		self::create_language( 'en_US' );
		self::create_language( 'fr_FR' );
		self::create_language( 'de_DE_formal' );
		self::create_language( 'es_ES' );

		self::$editor = self::factory()->user->create( array( 'role' => 'editor' ) );
	}

	function setUp() {
		parent::setUp();

		wp_set_current_user( self::$editor ); // set a user to pass current_user_can tests
		self::$polylang->filters_post = new PLL_Admin_Filters_Post( self::$polylang );
	}

	function tearDown() {
		parent::tearDown();

		$_REQUEST = $_GET = $_POST = array();
	}

	function test_default_language() {
		// user preferred language
		self::$polylang->pref_lang = self::$polylang->model->get_language( 'fr' );
		$post_id = $this->factory->post->create();
		$this->assertEquals( 'fr', self::$polylang->model->post->get_language( $post_id )->slug );

		// language set from parent
		$parent = $this->factory->post->create();
		self::$polylang->model->post->set_language( $parent, 'de' );
		$post_id = $this->factory->post->create( array( 'post_parent' => $parent ) );
		$this->assertEquals( 'de', self::$polylang->model->post->get_language( $post_id )->slug );

		// language set when adding a new translation
		$_GET['new_lang'] = 'es';
		$post_id = $this->factory->post->create();
		$this->assertEquals( 'es', self::$polylang->model->post->get_language( $post_id )->slug );
	}

	function test_save_post_from_metabox() {
		$GLOBALS['post_type'] = 'post';

		$_REQUEST = $_POST = array(
			'post_lang_choice' => 'en',
			'_pll_nonce'       => wp_create_nonce( 'pll_language' ),
			'post_ID'          => $en = $this->factory->post->create(),
		);
		edit_post();

		$this->assertEquals( 'en', self::$polylang->model->post->get_language( $en )->slug );

		// set the language and translations
		$_REQUEST = $_POST = array(
			'post_lang_choice' => 'fr',
			'_pll_nonce'       => wp_create_nonce( 'pll_language' ),
			'post_tr_lang'     => array( 'en' => $en ),
			'post_ID'          => $fr = $this->factory->post->create(),
		);
		edit_post();

		$this->assertEquals( 'fr', self::$polylang->model->post->get_language( $fr )->slug );
		$this->assertEqualSets( compact( 'en', 'fr' ), self::$polylang->model->post->get_translations( $en ) );
	}

	function test_save_post_from_quick_edit() {
		$post_id = $this->factory->post->create();
		self::$polylang->model->post->set_language( $post_id, 'en' );

		$_REQUEST = $_POST = array(
			'inline_lang_choice' => 'fr',
			'_inline_edit'       => wp_create_nonce( 'inlineeditnonce' ),
		);

		wp_update_post( array( 'ID' => $post_id ) );
		$this->assertEquals( 'fr', self::$polylang->model->post->get_language( $post_id )->slug );
	}

	function test_save_post_from_bulk_edit() {
		$posts = $this->factory->post->create_many( 2 );
		self::$polylang->model->post->set_language( $posts[0], 'en' );
		self::$polylang->model->post->set_language( $posts[1], 'fr' );

		// first do not modify any language
		$_REQUEST = $_GET = array(
			'inline_lang_choice' => -1,
			'_wpnonce'           => wp_create_nonce( 'bulk-posts' ),
			'bulk_edit'          => 'Update',
			'post'               => $posts,
			'_status'            => 'publish',
		);

		$done = bulk_edit_posts( $_REQUEST );
		$this->assertEquals( 'en', self::$polylang->model->post->get_language( $posts[0] )->slug );
		$this->assertEquals( 'fr', self::$polylang->model->post->get_language( $posts[1] )->slug );

		// second modify all languages
		$_REQUEST['inline_lang_choice'] = 'fr';
		$done = bulk_edit_posts( $_REQUEST );
		$this->assertEquals( 'fr', self::$polylang->model->post->get_language( $posts[0] )->slug );
		$this->assertEquals( 'fr', self::$polylang->model->post->get_language( $posts[1] )->slug );
	}

	function test_quickdraft() {
		$_REQUEST = array(
			'action'   => 'post-quickdraft-save',
			'_wpnonce' => wp_create_nonce( 'add-post' ),
		);

		self::$polylang->pref_lang = self::$polylang->model->get_language( 'fr' );
		$post_id = $this->factory->post->create();
		$this->assertEquals( 'fr', self::$polylang->model->post->get_language( $post_id )->slug );
	}

	function test_save_post_with_categories() {
		$en = $this->factory->category->create();
		self::$polylang->model->term->set_language( $en, 'en' );

		$fr = $this->factory->category->create();
		self::$polylang->model->term->set_language( $fr, 'fr' );

		self::$polylang->model->term->save_translations( $en, compact( 'en', 'fr' ) );

		$en2 = $this->factory->category->create();
		self::$polylang->model->term->set_language( $en2, 'en' );

		$fr2 = $this->factory->category->create();
		self::$polylang->model->term->set_language( $fr2, 'fr' );

		$_REQUEST = $_POST = array(
			'post_lang_choice' => 'fr',
			'_pll_nonce'       => wp_create_nonce( 'pll_language' ),
			'post_category'    => array( $en, $en2, $fr2 ),
			'post_ID'          => $post_id = $this->factory->post->create(),
		);
		edit_post();

		$this->assertFalse( is_object_in_term( $post_id, 'category' , $en ) );
		$this->assertTrue( is_object_in_term( $post_id, 'category' , $fr ) );
		$this->assertFalse( is_object_in_term( $post_id, 'category' , $en2 ) );
		$this->assertTrue( is_object_in_term( $post_id, 'category' , $fr2 ) );
	}

	function test_save_post_with_tags() {
		$en = $this->factory->tag->create( array( 'name' => 'test' ) );
		self::$polylang->model->term->set_language( $en, 'en' );

		$fr = $this->factory->tag->create( array( 'name' => 'test', 'slug' => 'test-fr' ) );
		self::$polylang->model->term->set_language( $fr, 'fr' );

		$_REQUEST = $_POST = array(
			'post_lang_choice' => 'fr',
			'_pll_nonce'       => wp_create_nonce( 'pll_language' ),
			'tax_input'        => array( 'post_tag' => array( 'test', 'new' ) ),
			'post_ID'          => $post_id = $this->factory->post->create(),
		);
		edit_post();

		$this->assertFalse( is_object_in_term( $post_id, 'post_tag' , $en ) );
		$this->assertTrue( is_object_in_term( $post_id, 'post_tag' , $fr ) );

		$this->markTestIncomplete(); // FIXME does not work
		$new = get_term_by( 'name', 'new', 'post_tag' );
		$this->assertTrue( is_object_in_term( $post_id, 'post_tag' , $new ) );
		$this->assertEquals( 'fr', self::$polylang->model->term->get_language( $new )->slug );
	}

	function test_delete_post() {
		$en = $this->factory->post->create();
		self::$polylang->model->post->set_language( $en, 'en' );

		$fr = $this->factory->post->create();
		self::$polylang->model->post->set_language( $fr, 'fr' );

		$de = $this->factory->post->create();
		self::$polylang->model->post->set_language( $de, 'de' );

		self::$polylang->model->post->save_translations( $en, compact( 'en', 'fr', 'de' ) );

		wp_delete_post( $en, true ); // forces delete
		$this->assertEqualSetsWithIndex( compact( 'fr', 'de' ), self::$polylang->model->post->get_translations( $fr ) );
	}

	function test_page_attributes_meta_box() {
		$en = $this->factory->post->create( array( 'post_title' => 'test', 'post_type' => 'page' ) );
		self::$polylang->model->post->set_language( $en, 'en' );

		$fr = $this->factory->post->create( array( 'post_title' => 'essai', 'post_type' => 'page' ) );
		self::$polylang->model->post->set_language( $fr, 'fr' );

		$page = $this->factory->post->create_and_get( array( 'post_type' => 'page' ) );
		self::$polylang->model->post->set_language( $page->ID, 'fr' );

		self::$polylang->filters = new PLL_Admin_Filters( self::$polylang ); // we need the get_pages filter
		$GLOBALS['hook_suffix'] = 'post.php';
		set_current_screen( 'page' );
		require_once ABSPATH . 'wp-admin/includes/meta-boxes.php';

		ob_start();
		page_attributes_meta_box( $page );
		$out = ob_get_clean();

		$this->assertFalse( strpos( $out, 'test' ) );
		$this->assertNotFalse( strpos( $out, 'essai' ) );

		$_POST['lang'] = 'en'; // prevails on the post language (ajax response to language change)
		ob_start();
		page_attributes_meta_box( $page );
		$out = ob_get_clean();

		$this->assertNotFalse( strpos( $out, 'test' ) );
		$this->assertFalse( strpos( $out, 'essai' ) );

		unset( $_POST, $GLOBALS['hook_suffix'], $GLOBALS['current_screen'] );
	}

	function test_languages_meta_box_for_new_post() {
		global $post_ID;

		$lang = self::$polylang->pref_lang = self::$polylang->model->get_language( 'en' );
		self::$polylang->links = new PLL_Admin_Links( self::$polylang );
		$post_ID = $this->factory->post->create();
		wp_set_object_terms( $post_ID, null, 'language' ); // intentionally remove the language

		ob_start();
		self::$polylang->filters_post->post_language();
		$form = ob_get_clean();
		$xml = simplexml_load_string( "<root>$form</root>" ); // add a root xml tag to get a valid xml doc

		$option = $xml->xpath( 'div/select/option[.="' . $lang->name . '"]' );
		$attributes = $option[0]->attributes();
		$this->assertEquals( 'selected', $attributes['selected'] );

		unset( $_GET );
	}

	function test_languages_meta_box_for_new_translation() {
		global $post_ID;

		self::$polylang->links = new PLL_Admin_Links( self::$polylang );
		$post_ID = $this->factory->post->create();
		wp_set_object_terms( $post_ID, null, 'language' ); // intentionally remove the language

		$en = $this->factory->post->create( array( 'post_title' => 'test' ) );
		self::$polylang->model->post->set_language( $en, 'en' );
		$lang = self::$polylang->model->get_language( 'fr' );
		$_GET['from_post'] = $en;
		$_GET['new_lang'] = 'fr';

		ob_start();
		self::$polylang->filters_post->post_language();
		$form = ob_get_clean();
		$xml = simplexml_load_string( "<root>$form</root>" ); // add a root xml tag to get a valid xml doc

		$option = $xml->xpath( 'div/select/option[.="' . $lang->name . '"]' );
		$attributes = $option[0]->attributes();
		$this->assertEquals( 'selected', $attributes['selected'] );
		$input = $xml->xpath( '//input[@name="post_tr_lang[en]"]' );
		$attributes = $input[0]->attributes();
		$this->assertEquals( $en, (int) $attributes['value'] );
		$input = $xml->xpath( '//input[@id="tr_lang_en"]' );
		$attributes = $input[0]->attributes();
		$this->assertEquals( 'test', $attributes['value'] );

		unset( $_GET );
	}

	function test_languages_meta_box_for_existing_post_with_translations() {
		global $post_ID;

		self::$polylang->links = new PLL_Admin_Links( self::$polylang );

		$en = $this->factory->post->create( array( 'post_title' => 'test' ) );
		self::$polylang->model->post->set_language( $en, 'en' );

		$post_ID = $fr = $this->factory->post->create( array( 'post_title' => 'essai' ) );
		self::$polylang->model->post->set_language( $fr, 'fr' );

		self::$polylang->model->post->save_translations( $en, compact( 'en', 'fr' ) );

		$lang = self::$polylang->model->get_language( 'fr' );

		ob_start();
		self::$polylang->filters_post->post_language();
		$form = ob_get_clean();
		$xml = simplexml_load_string( "<root>$form</root>" ); // add a root xml tag to get a valid xml doc

		// language is French
		$option = $xml->xpath( 'div/select/option[.="' . $lang->name . '"]' );
		$attributes = $option[0]->attributes();
		$this->assertEquals( 'selected', $attributes['selected'] );
		// Link to English post
		$input = $xml->xpath( '//input[@name="post_tr_lang[en]"]' );
		$attributes = $input[0]->attributes();
		$this->assertEquals( $en, (int) $attributes['value'] );
		$input = $xml->xpath( '//input[@id="tr_lang_en"]' );
		$attributes = $input[0]->attributes();
		$this->assertEquals( 'test', $attributes['value'] );
		// No self link
		$this->assertEmpty( $xml->xpath( '//input[@name="post_tr_lang[fr]"]' ) );
		$this->assertEmpty( $xml->xpath( '//input[@id="tr_lang_fr"]' ) );
		// Link to empty German post
		$input = $xml->xpath( '//input[@name="post_tr_lang[de]"]' );
		$attributes = $input[0]->attributes();
		$this->assertEquals( 0, (int) $attributes['value'] );
		$input = $xml->xpath( '//input[@id="tr_lang_de"]' );
		$attributes = $input[0]->attributes();
		$this->assertEquals( '', $attributes['value'] );
	}

	function test_languages_meta_box_for_media() {
		global $post_ID;

		self::$polylang->options['media_support'] = 1;
		self::$polylang->filters_media = new PLL_Admin_Filters_Media( self::$polylang );

		$en = $this->factory->attachment->create_object( 'image0.jpg' );
		self::$polylang->model->post->set_language( $en, 'en' );

		$post_ID = self::$polylang->filters_media->create_media_translation( $en, 'fr' );

		$lang = self::$polylang->model->get_language( 'fr' );

		ob_start();
		self::$polylang->filters_post->post_language();
		$form = ob_get_clean();
		$xml = simplexml_load_string( "<root>$form</root>" ); // add a root xml tag to get a valid xml doc

		// language is French
		$option = $xml->xpath( 'div/select/option[.="' . $lang->name . '"]' );
		$attributes = $option[0]->attributes();
		$this->assertEquals( 'selected', $attributes['selected'] );
		// Link to English post
		$input = $xml->xpath( '//input[@name="media_tr_lang[en]"]' );
		$attributes = $input[0]->attributes();
		$this->assertEquals( $en, (int) $attributes['value'] );
		$this->assertNotFalse( strpos( $form, 'Edit the translation in English' ) );
		// No self link
		$this->assertEmpty( $xml->xpath( '//input[@name="media_tr_lang[fr]"]' ) );
		// Link to empty German post
		$this->assertNotFalse( strpos( $form, 'Add a translation in Deutsch' ) );
	}

	function test_get_posts_language_filter() {
		$en = $this->factory->post->create();
		self::$polylang->model->post->set_language( $en, 'en' );

		$fr = $this->factory->post->create();
		self::$polylang->model->post->set_language( $fr, 'fr' );

		$de = $this->factory->post->create();
		self::$polylang->model->post->set_language( $de, 'de' );

		$posts = get_posts( array( 'fields' => 'ids', 'lang' => 'fr' ) );
		$this->assertEquals( $fr, reset( $posts ) );

		$posts = get_posts( array( 'fields' => 'ids', 'lang' => 'en,de' ) );
		$this->assertEqualSets( array( $en, $de ), $posts );

		$posts = get_posts( array( 'fields' => 'ids', 'lang' => array( 'de', 'fr' ) ) );
		$this->assertEqualSets( array( $fr, $de ), $posts );

		$posts = get_posts( array( 'fields' => 'ids', 'lang' => '' ) );
		$this->assertEqualSets( array( $en, $fr, $de ), $posts );
	}
}
