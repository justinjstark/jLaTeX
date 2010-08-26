<?php


/*
	TODO:
		@ There is currently support for blacklisting commands. We either need a thorough list of commands to disallow or we need to incorporate a whitelist of commands to allow.
*/


class jLaTeX extends Plugin		// Extends the core Plugin class
{

	//The dictionary of LaTeX tag styles and how to apply each
	private $dict = array (
	//array ( inputcontainer, texcontainer, outputcontainer ),
	//array ( '\$$(.*?)\$$', '<div style="text-align:center">%s</div>' )
		array ( '\\\\\((.*?)\\\\\)', '\(%s\)', '%s' ),
		array ( '\\\\\[(.*?)\\\\\]', '\[%s\]', '<div class="centered">%s</div>' ),
		array ( '\[tex\](.*?)\[\/tex\]', '%s', '%s' ),
		array ( '\[ctex\](.*?)\[\/ctex\]', '%s', '<div class="centered">%s</div>' ),
	);
	
	//The following is a whitelist from drutex
  //$D['drutex_security_allowedcommands'] = '\atop \binom \cdot \cfrac \choose \frac \int \ln \over \sum \to';
  //$D['drutex_security_allowedenvironments'] = 'align array equation equations gather matrix split';
  //\dfrac \displaystyle \lim \emptyset ...
	
	
	//Blacklisted LaTeX commands.  These should be regex patterns to be stripped.
	private $blacklist = array (
		"\\\\include",
	);
	
	//"\\include", "\\def", "command", "loop", "repeat", "open", "toks", "output", "input", "catcode", "name", "^^", "\\every", "\\errhelp", "\\errorstopmode", "\\scrollmode", "\\nonstopmode", "\\batchmode", "\\read", "\\write", "csname", "\\newhelp", "\\uppercase", "\\lowercase", "\\relax", "\\aftergroup", "\\afterassignment", "\\expandafter", "\\noexpand", "\\special"
	
	
	/**
	 * function action_plugin_activation
	 * Adds the configuration options
	*/
	public function action_plugin_activation( $file )
	{
		if ( realpath( $file ) == __FILE__ )
		{
			//Set the default options
			Options::set( 'jLaTeX__latex', '/usr/bin/latex' );
			Options::set( 'jLaTeX__dvipng', '/usr/bin/dvipng' );
			Options::set( 'jLaTeX__tmp', '/tmp/' );
			Options::set( 'jLaTeX__imagedpi', '120' );
			Options::set( 'jLaTeX__imagewidth', '557' );
			Options::set( 'jLaTeX__incomments', true );
			
			//Default to a simple template
			$template  = "\\documentclass[12pt]{article}\n";
			$template .= "\\usepackage{amsmath,amssymb,amsfonts}\n";
			$template .= "\\usepackage{verbatim}\n";
			$template .= "\\pagestyle{empty}\n\n";
			$template .= "\\newcommand{\\charf}{\\raisebox{\\depth}{\\(\\chi\\)}}\n\n";
			$template .= "\\setlength{\\parindent}{0in}\n\n";
			$template .= "\\begin{document}\n";
			$template .= "%s\n";
			$template .= "\\end{document}";
			Options::set( 'jLaTeX__template', $template );
		}
	}
	
	
	/**
	 * function action_init
	 * A function which makes sure we are good to go for plugin activation.
	*/
	public function action_init()
	{
		if ( !class_exists( 'RenderCache' ) )
		{
			Session::error( _t( "LaTeX activation failed. This plugin requires the RenderCache class which was not found." ) );
			Plugins::deactivate_plugin( __FILE__ ); //Deactivate plugin
			Utils::redirect(); //Refresh page. Unfortunately, if not done so then results don't appear
		}
	}


	/************************** LaTeX Stuff **************************/


	/**
	 * function replace_tags
	 * Loops through the dictionary and passes matches to another function.
	 * @param string $command The command to execute
	 * @param string $working_dir The directory in which to work
	 * @return boolean true if the command succeded, false if not
	*/
	public function replace_tags( $content )
	{
		//Replace for each definition
		foreach ( $this->dict as $record )
		{

			//Send additional data from the dictionary to the function.
			$this->passthrough_args['texcontainer'] = $record[1];
			$this->passthrough_args['outputcontainer'] = $record[2];
			
			$content = preg_replace_callback ( '#' . $record[0] . '#si', array( $this, 'passthrough' ), $content );
		}
		
		return $content;
	}
	
	
	/**
	 * function insert_image
	 * Insert the image tag for the given image.  Make sure it is present first.
	 * @param string $texcode The TeX code
	 * @param integer $post_id The Post ID
	 * @param integer $commend_id The Comment ID if a comment
	 * @return string The html image tag
	*/
	private function insert_image( $texcode, $post_id, $comment_id = null, $texcontainer = '%s', $outputcontainer = '%s' )
	{
		//Set the LaTeX code in its container
		$texcode = sprintf( $texcontainer, $texcode );
		
		//Set the group and name for RenderCache
		$group = 'jLaTeX';
		$name = $post_id . '-' . $comment_id . '-' . md5($texcode);
		
		//If the image is not in cache, render it
		if ( !RenderCache::has( array( $group, $name ) ) ) {
			if ( $this->render_image( $texcode, $post_id, $comment_id ) === false ) {
				return $this->error_format( $texcode );
			}
		}
				
		//Get the url of the image
		$file_url = RenderCache::get_url( array( $group, $name ) );
		
		//Return the image tag
		return sprintf( $outputcontainer, "<img src=\"$file_url\" alt=\"" . trim($texcode) . "\" class=\"jLaTeX\">" );
	}
	
	
	/**
	 * function render_image
	 * Create the image and place it in the proper place
	 * @param string $texcode The LaTeX code to render
	 * @param integer $post_id The post id
	 * @param integer $commend_id The commend id if a comment
	 * @return string The image filename
	*/
	private function render_image( $texcode, $post_id, $comment_id = null )
	{
		//Strip blacklisted elements
		foreach ( $this->blacklist as $black )
		{
			$texcode = preg_replace ( '/' . $black . '/si', '', $texcode );
		}
	
		//Set this so it can be referenced in case of an error
		//XXX
		$this->error_texcode = rtrim( $texcode, '/' ) . '/';
		
		$tmp = Options::get( 'jLaTeX__tmp' );
		
		//Make sure the tmp directory exists
		if ( !is_dir( $tmp ) ) {
			mkdir( $tmp, 0755, true );
			//TODO: ERROR
		}
		
		$filename = md5( $texcode );
		
		//Put the formula in the latex document template
		$latex_document = sprintf( Options::get( 'jLaTeX__template' ), $texcode );

		//Set the textwidth of the document
		$textwidth = round ( Options::get( 'jLaTeX__imagewidth' ) / Options::get( 'jLaTeX__imagedpi' ) , 2 );
		$latex_document = preg_replace( '/\\\\begin{document}/si', "\\\\setlength{\\\\textwidth}{" . $textwidth . "in}\n\\\\begin{document}", $latex_document );
		
		//Create the temproary TEX file
		file_put_contents( $tmp . $filename . '.tex', $latex_document );
		
		//Create the temprorary DVI file
		$command = Options::get( 'jLaTeX__latex' ) . ' --interaction=nonstopmode ' . $filename . '.tex';
		if ( $this->do_command( $command, $tmp ) === false ) {
			return false;
		}
		
		//Convert dvi file to png using dvipng
		$command = Options::get( 'jLaTeX__dvipng' ) . ' ' . $filename . '.dvi -D ' . Options::get( 'jLaTeX__imagedpi' ) . ' -T tight -o ' . $filename . '.png';
		if ( $this->do_command( $command, $tmp ) === false ) {
			return false;
		}
		
		
		//Store the file in cache
		$file = $tmp . $filename . '.png';
		$group = 'jLaTeX';
		$name = $post_id . '-' . $comment_id . '-' . md5($texcode);
		RenderCache::put( array( $group, $name ), $file, 60*60*24*7, true );
	}

	
	/**
	 * function do_command
	 * Executes a command in a given directory and checks for an error
	 * @param string $command The command to execute
	 * @param string $working_dir The directory in which to work
	 * @return boolean true if the command succeded, false if not
	*/
	private function do_command( $command, $working_dir = null )
	{
		//Change to the working directory
		$current_dir = getcwd();
		if ( $working_dir ) chdir( $working_dir );
		
		//Execute the command
		exec( $command, $output, $status_code );
		
		//If there is an error, return false
		if ( $status_code )
		{
			chdir( $current_dir );
			return false;
		}
		unset( $output );
		
		//Return to the previous directory
		chdir( $current_dir );
	}
	
	
	/**
	 * function error_format
	 * Formats the texcode if it is not renderable
	 * @param string $texcode The LaTeX code
	 * @return string The formatted html
	*/
	private function error_format( $texcode )
	{
		return '<span style="background-color: white; color: red; font-weight: bold;">' . trim( $texcode ) . '</span>';
	}
	

	/************************** Actions & Filters **************************/
	

	/**
	 * function filter_post_content
	 * Search for the LaTeX code so the tags can be replaced by images
	*/
	public function filter_post_content_out( $content, $post )
	{
		$this->passthrough_args = array( $post->id, null );
		
		return $this->replace_tags ( $content );
	}
	
	
	/**
	 * function filter_post_title_out
	 * Render LaTeX code in the title
	**/
	public function filter_post_title_out( $title, $post )
	{
		return $this->filter_post_content_out( $title, $post );
	}
	
	
	/**
	 * function filter_post_content_excerpt
	 * Does the same thing as filter_post_content_out but for excerpts.
	 */
	public function filter_post_content_excerpt( $content, $post )
	{
		//why reinvent the wheel?
		return $this->filter_post_content_out( $content, $post );
	}
	

	/**
	 * function filter_comment_content_out
	 * When a comment is displayed
	 * @return string $content
	*/
	public function filter_comment_content_out( $content, $comment )
	{
		if ( ! Options::get ( 'jLaTeX__incomments' ) )
		{
			return $content;
		}
	
		$this->passthrough_args = array( $comment->post_id, $comment->id );
		
		return $this->replace_tags ( $content );
	}
	

	/**
	 * function action_post_update_after
	 * When a post is updated
	*/
	public function action_post_update_after( $post )
	{
		//Delete all images for the post (don't delete comment images)
		$group = 'jLaTeX';
		$name = '/--/';	//Search for names with --
		RenderCache::expire( array( $group, $name ), 'regex' );
		
		//Render the images for the post
		self::filter_post_content_out( $post->content, $post );
	}
	
	
	/**
	 * function action_comment_update_before
	 * When a comment is updated
	*/
	public function action_comment_update_after( $comment )
	{
		//Delete all images for the comment
		self::action_comment_delete_after( $comment );
		
		//Render the images for the comment
		self::filter_comment_content_out( $comment->content, $comment );
	}
	

	/**
	 * function action_post_delete_after
	 * When a post is deleted
	*/
	public function action_post_delete_after( $post )
	{
		//Delete all images for the deleted post including images in comments
		$group = 'jLaTeX';
		$name = '/^(' . $post->id . '-)/si';
		RenderCache::expire( array( $group, $name ), 'regex' );
	}
	

	/**
	 * function action_comment_delete_after
	 * When a comment is deleted
	*/
	public function action_comment_delete_after( $comment )
	{
		//Delete all images for the deleted comment
		$group = 'jLaTeX';
		$name = '/^(' . $comment->post_id . '-' . $comment->id . '-)/si';
		RenderCache::expire( array( $group, $name ), 'regex' );
	}
	
	
	/*
	 * function action_init_theme
	 * Called when the theme is initialized
	 */
	public function action_init_theme()
	{
		//Add the stylesheet for LaTeX images and such
		Stack::add('template_stylesheet', array( URL::get_from_filesystem(__FILE__) . '/latex.css', 'screen', 'screen' ), 'jLaTeX-css');
	}
	

	/**
	 * function set_priorities
	 * Sets priorities of various actions and filters so they don't interfere with one another. (default priority is 8)
	 */
	function set_priorities()
	{
		return array
		(
			//Make this happen after the excerpt filter so images don't get stripped
			'filter_post_content_excerpt' => 9,
			//And push these back for good measure
			'filter_post_content_out' => 9,
			'filter_comment_content_out' => 9,
		);
	}
	
	
	/************************** Admin Stuff **************************/


	public function filter_plugin_config( $actions, $plugin_id )
	{
		if ( $plugin_id == $this->plugin_id() ) {
			$actions[]= _t( 'Configure' );
			$actions[]= _t( 'Clear Cache' );
		}
		return $actions;
	}
	
	
	public function action_plugin_ui( $plugin_id, $action )
	{
		if ( $plugin_id == $this->plugin_id() )
		{
			switch ( $action )
			{
			
				case _t( 'Configure' ):
				
					$ui = new FormUI( strtolower( get_class( $this ) ) );
					
					$executables_fieldset = $ui->append( 'fieldset', 'executables', _t( 'Executables' ) );
						$executables_fieldset->append( 'text', 'latex', 'option:jLaTeX__latex', _t( 'LaTeX' ) );
						$executables_fieldset->append( 'text', 'dvipng', 'option:jLaTeX__dvipng', _t( 'DVIPNG' ) );
						$executables_fieldset->append( 'text', 'tmp_path', 'option:jLaTeX__tmp', _t( 'Temporary Directory' ) );
					
					$latexsettings_fieldset = $ui->append( 'fieldset', 'latexsettings', _t( 'LaTeX Settings' ) );
						$latexsettings_fieldset->append( 'static', 'latextemplate_static', _t( 'You can place anything you want in the following template.  %s% gets replaced by the LaTeX code you enter between appropriate tags in posts and comments.' ) );
						$latexsettings_fieldset->append( 'textarea', 'latextemplate', 'option:jLaTeX__template', _t( 'Template' ) );
						$latexsettings_fieldset->append( 'checkbox', 'incomments', 'option:jLaTeX__incomments', _t( 'Render LaTeX in comments' ) );

					$imagesettings_fieldset = $ui->append( 'fieldset', 'imagesettings', _t( 'Image Settings' ) );
						$imagesettings_fieldset->append( 'text', 'imagedpi', 'option:jLaTeX__imagedpi', _t( 'DPI' ) );
						$imagesettings_fieldset->append( 'text', 'imagewidth', 'option:jLaTeX__imagewidth', _t( 'Maximum Image Width (in pixels)' ) );
						$imagesettings_fieldset->append( 'static', 'imagewidth_static', _t( 'jLaTeX will calculate the maximum image width in inches and automatically set the LaTeX document text-width to this value. This way the text is not wider than the container width.' ) );
										
					$ui->append( 'submit', 'save', 'Save' );
					
					$ui->on_success( array( $this, 'updated_config' ) );
					$ui->out();
					
					break;
					
				case _t( 'Clear Cache' ):
					
					$ui = new FormUI( strtolower( get_class( $this ) ) );
					
					$ui->append( 'static', 'explanation', _t( 'Clearing the cache will delete all rendered images for all posts and comments.  The images will be rerendered the first time they are viewed.  Press the button below if you wish to continue.' ) );
					$ui->append( 'submit', 'clear', 'Clear Cache' );
					$ui->on_success( array( $this, 'clear_cache' ) );
					$ui->out();
					break;
			}

		}
	}
	
	public function clear_cache( $ui )
	{
		Session::notice( _t( 'All rendered LaTeX images in the cache have been deleted.' ) );
		
		//Expire all cached images
		$group = 'jLaTeX';
		$name = '*';
		RenderCache::expire( array( $group, $name ), 'glob' );
		
		return false;
	}

	public function updated_config( $ui )
	{
		$ui->save();
		return false;
	}
	
	
	/************************** Miscellaneous **************************/
	
	
	/**
	 * function passthrough
	 * Allows us to pass arguments through preg_replace_callback.  Calls a second layer callback function $this->preg_callback with additional arguments $this->passthrough_args.
	*/
	protected $passthrough_args = null;
	public function passthrough( $match )
	{
		$args = $this->passthrough_args;
		array_unshift( $args, $match[1] );
		
		return call_user_func_array( array( $this , 'insert_image' ), $args );
	}
}

?>
