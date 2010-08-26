<?php
/*
	TODO:
		@ Strip out potentially malicious LaTeX commands. (This definitely needs implemented before any sort of post-alpha release.)
		@ Make a preview comment plugin
*/

include_once( dirname(__FILE__) . '/latex.dict.php' );

class jLaTeX extends Plugin		// Extends the core Plugin class
{

	//TODO: Put this in a separate file.
	//XXX: $CENTERED = '<div style="text-align:center">%s</div>';
	private $dict = array (
	//array ( inputcontainer, texcoontainer, outputcontainer (optional) ),
	//array ( '\$$(.*?)\$$', '<div style="text-align:center">%s</div>' )
		array ( '\\\\\((.*?)\\\\\)', '\(%s\)', '%s' ),
		array ( '\\\\\[(.*?)\\\\\]', '\[%s\]', '<div class="centered">%s</div>' ),
		array ( '\[tex\](.*?)\[\/tex\]', '%s', '%s' ),
		array ( '\[ctex\](.*?)\[\/ctex\]', '%s', '<div class="centered">%s</div>' ),
	);
	

	/**
	 * function action_plugin_activation
	 * Adds the configuration options
	*/
	public function action_plugin_activation( $file )
	{
		//Set the default options
		if ( realpath( $file ) == __FILE__ )
		{
			Options::set( 'jLaTeX__latex', '/usr/bin/latex' );
			Options::set( 'jLaTeX__dvips', '/usr/bin/dvips' );
			Options::set( 'jLaTeX__convert', '/usr/bin/convert' );
			Options::set( 'jLaTeX__tmp_path', '/tmp/' );
			Options::set( 'jLaTeX__imagedpi', '120' );
			Options::set( 'jLaTeX__imagewidth', '557' );
			Options::set( 'jLaTeX__verbose_errors', false );
			
			//Default to a simple template
			$template  = "\\documentclass[12pt]{article}\n";
			$template .= "\\usepackage{amsmath,amssymb,amsfonts}\n";
			$template .= "\\usepackage{verbatim}\n";
			$template .= "\\pagestyle{empty}\n\n";
			$template .= "\\newcommand{\\charf}{\\raisebox{\\depth}{\\(\\chi\\)}}\n\n";
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
		return '<span style="font-family: \'Courier New\'; background-color: #aa3333; color: yellow; padding: 1px; font-weight: bold;">' . trim( $texcode ) . '</span>';
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
		//Set this so it can be referenced in case of an error
		$this->error_texcode = rtrim( $texcode, '/' ) . '/';
		
		$tmp = Options::get( 'jLaTeX__tmp_path' );
		
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
		
		//Convert dvi file to postscript using dvips
		$command = Options::get( 'jLaTeX__dvips' ) . ' ' . $filename . '.dvi -o ' . $filename . '.ps';
		if ( $this->do_command( $command, $tmp ) === false ) {
			return false;
		}
		
		//Convert the ps file to an image and trim the excess
		$command = Options::get( 'jLaTeX__convert' ) . ' -density ' . Options::get( 'jLaTeX__imagedpi' ) . ' -trim ' . $filename . '.ps ' . $filename . '.png';
		if ( $this->do_command( $command, $tmp ) === false ) {
			return false;
		}
		
		//Store the file in cache
		$file = $tmp . $filename . '.png';
		$group = 'jLaTeX-' . $post_id;
		$name = $comment_id . '-' . md5($texcode);
		RenderCache::put( array( $group, $name ), $file, 60*60*24*7, true );
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
		$texcode = sprintf( $texcontainer, $texcode );
		$group = 'jLaTeX-' . $post_id;
		$name = $comment_id . '-' . md5($texcode);
		
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
	

	/************************** Actions & Filters **************************/
	

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
	
	
	/**
	 * function filter_post_content
	 * Search for the LaTeX code so the tags can be replaced by images
	*/
	public function filter_post_content_out( $content, $post )
	{
	/*
		XXX: For content-type stuff.  Remove this.
		if ( preg_match( "/^\#\!latex[\n\r]/i", (string)$content ) )
		{
			return 'This is a LaTeX document.';
		}
	*/
		
		$this->passthrough_callback = 'insert_image';
		$this->passthrough_args = array( $post->id, null );
		
		//Replace for each definition
		foreach ( $this->dict as $record )
		{

			//Send additional data from the dictionary to the function.
			$this->passthrough_args['texcontainer'] = $record[1];
			$this->passthrough_args['outputcontainer'] = $record[2];
			
			$content = preg_replace_callback ( '#' . $record[0] . '#si', array( $this, 'passthrough' ), $content );
		}
		
		return $content;
		
		//return preg_replace_callback ( '#\\\\\((.*?)\\\\\)#si', array( $this, 'passthrough' ), $content );
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
	 * function action_post_delete_before
	 * When a post is deleted, delete all images for that post and its comments.
	*/
	public function action_post_delete_before( $post )
	{
		//Delete all images for the deleted post including images in comments
		$group = 'jLaTeX-' . $post->id;
		$name = '*';
		RenderCache::expire( array( $group, $name ), 'glob' );
	}

	
	/**
	 * function filter_comment_content_out
	 * When a comment is displayed, search for tex.
	 * @return string $content
	*/
	public function filter_comment_content_out( $content, $comment )
	{
		$this->passthrough_callback = 'insert_image';
		$this->passthrough_args = array( $comment->post_id, $comment->id );
		
		//return preg_replace_callback ( '#\\\\\((.*?)\\\\\)#si', array( $this, 'passthrough' ), $content );
	}
	
	
	public function action_comment_delete_after( $comment )
	{
		//Delete all images for the deleted comment
		$group = 'jLaTeX-' . $comment->post_id;
		$name = $comment->id . '-*';
		RenderCache::expire( array( $group, $name ), 'glob' );

	}
	
	public function action_comment_update_after( $comment )
	{
		//Delete all images for the comment
		self::action_comment_delete_after( $comment );
		
		//Render the images for the comment
		self::filter_comment_content_out( $comment->content, $comment );
	}
	
	public function action_post_update_after( $post )
	{
		//Delete all images for the post (don't delete comment images)
		$group = 'jLaTeX-' . $post->id;
		$name = '/^-/';	//Search for - at the beginning of $name
		RenderCache::expire( array( $group, $name ), 'regex' );
		
		//Render the images for the post
		self::filter_post_content_out( $post->content, $post );
	}
	
	
	/*
	 * function action_init_theme
	 * Called when the theme is initialized
	 */
	public function action_init_theme()
	{
		//Add the stylesheet for LaTeX images and such
		Stack::add('template_stylesheet', array( URL::get_from_filesystem(__FILE__) . '/latex.css', 'screen', 'screen' ), 'cssfilename');
	}
	
	
	/************************** Admin Stuff **************************/


	/**
	 * function help
	 * Returns a quick bit of help
	 * @return string The help string
	*/
	public function help()		// Shows a text with basic usage instructions
	{
		$help = '<p>Currently, the only available tags are \( \) as in \( x^2 \).  It works in both posts and comments.  This will be extended later.</p>';
	}
	

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
						$executables_fieldset->append( 'text', 'dvips', 'option:jLaTeX__dvips', _t( 'DVIPS' ) );
						$executables_fieldset->append( 'text', 'convert', 'option:jLaTeX__convert', _t( 'Convert' ) );
						$executables_fieldset->append( 'text', 'tmp_path', 'option:jLaTeX__tmp_path', _t( 'Temporary Directory' ) );
					
					$latexsettings_fieldset = $ui->append( 'fieldset', 'latexsettings', _t( 'LaTeX Settings' ) );
						$latexsettings_fieldset->append( 'static', 'latextemplate_static', _t( 'You can place anything you want in the following template.  %formula% gets replaced by the LaTeX code you enter between appropriate tags in posts and comments.' ) );
						$latextemplate = $latexsettings_fieldset->append( 'textarea', 'latextemplate', 'option:jLaTeX__template', _t( 'Template' ) );
						$latextextwidth = $latexsettings_fieldset->append( 'text', 'textwidth', 'option:jLaTeX_textwidth', _t( 'Text Width (in inches)' ) );

					$imagesettings_fieldset = $ui->append( 'fieldset', 'imagesettings', _t( 'Image Settings' ) );
						$imagesettings_fieldset->append( 'text', 'imagedpi', 'option:jLaTeX__imagedpi', _t( 'DPI' ) );
						$imagesettings_fieldset->append( 'text', 'imagewidth', 'option:jLaTeX__imagewidth', _t( 'Maximum Image Width (in pixels)' ) );
						$imagesettings_fieldset->append( 'static', 'imagewidth_static', _t( 'jLaTeX will calculate the maximum image width in inches and automatically set the LaTeX document text-width to this value. This way the text is not wider than the container width.' ) );
						
					$errorsettings_fieldset = $ui->append( 'fieldset', 'errorsettings', _t( 'Error Settings' ) );
						$errorsettings_fieldset->append( 'static', 'verboseerrors_static', _t( 'When Verbose Errors is checked and an error is encountered during rendering, an exhaustive error message is supplied.  When Verbose Errors is unchecked and a rendering error occurs, the LaTeX code is printed in red and no error message is given.  The prior is good for debugging rendering issues and testing templates while the latter better in general usage.' ) );
						$errorsettings_fieldset->append( 'checkbox', 'verbose_errors', 'option:jLaTeX__verbose_errors', _t( 'Verbose Errors' ) );			
										
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
		//TODO
		//Expire all LaTeX images
		
		//Delete all images for the post (don't delete comment images)
		//$group = 'jLaTeX-' . $post->id;
		//$name = '/^-/';	//Search for - at the beginning of $name
		//RenderCache::expire( array( $group, $name ), 'regex' );
	
		//Session::notice( _t( 'All rendered LaTeX images in the cache have been deleted.' ) );
		
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
	protected $passthrough_callback = null;
	protected $passthrough_args = null;
	public function passthrough( $match )
	{
		$args = $this->passthrough_args;
		array_unshift( $args, $match[1] );
		
		return call_user_func_array( array( $this , $this->passthrough_callback ), $args );
	}
}

?>
