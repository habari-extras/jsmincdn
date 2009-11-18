<?php

/**
 * jsmincdn
 *
 * @version $Id$
 * @copyright 2009
 */

class jsMinCDN extends Plugin
{

	function help()
	{
		return _t('There is no helping you now.');
	}

	function action_plugin_activation( $plugin_file )
	{

	}

	function configure()
	{
		$ui = new FormUI('jsmincdn');

		$scripts = $ui->append( 'checkboxes', 'scripts', 'jsmincdn__storage', 'Select the scripts that should be served as minimized.' );
		$options = array_keys(Stack::get_named_stack('admin_header_javascript'));
		$scripts->options = array_combine($options, $options);

		$ui->append('submit', 'submit', 'Submit');

		return $ui;
	}

	function filter_stack_out($stack, $stack_name, $filter)
	{
		static $incmin = false;

		if ( is_callable($filter) && strcasecmp(implode('::', $filter), 'stack::scripts') == 0) {
			// Load the minifier class once
			if(!$incmin) {
				include 'jsmin/jsmin.php';
				$incmin = true;
			}

			// Get the script names to minify
			$domin = Options::get('jsmincdn__storage');

			// Find greatest common sequences
			$seqs = array();
			$script_build = 'jsmincdn';
			$seq = array();
			foreach( $stack as $name => $element ) {
				$doomit = false;

				if(in_array($name, $domin)) {
					$script_build .= '.' . $name;
					$seq[$name] = $element;
				}
				else {
					if(count($seq) > 0) {
						$seqs[$script_build] = $seq;
						$script_build = 'jsmincdn';
						$seq = array();
					}
					$seqs[$name] = $element;
				}
			}
			if(count($seq) > 0) {
				$seqs[$script_build] = $seq;
				$script_build = 'jsmincdn';
				$seq = array();
			}



			$script = '';
			$restack = array();
			$script_build = '';
			$output = '';
			foreach( $seqs as $seqname => $seqelement ) {

				if(is_string($seqelement)) {
					$doomit = true;
					$restack[$seqname] = $seqelement;
				}
				elseif(Cache::has(array('jsmincdn_post', $seqname))) {
					$doomit = false;
					$output = Cache::get(array('jsmincdn_post', $seqname));
					//$restack[$seqname] = $output;
					$restack[$seqname] = URL::get('jsmincdn', array('name' => $seqname));
				}
				else {
					foreach($seqelement as $name => $element) {
						if(strpos($element, "\n") !== FALSE) {
							$output = $element;
						}
						elseif(Cache::has(array('jsmincdn', $element))) {
							$output = Cache::get(array('jsmincdn', $element));
						}
						elseif( strpos($element, Site::get_url('scripts')) === 0 ) {
							$base = substr($element, strlen(Site::get_url('scripts')));
							$filename = HABARI_PATH . '/scripts' . $base;
							$output = file_get_contents($filename);
							Cache::set(array('jsmincdn', $element), $output, 3600 * 24);
						}
						elseif( strpos($element, Site::get_url('habari')) === 0 ) {
							$base = substr($element, strlen(Site::get_url('habari')));
							$filename = HABARI_PATH . $base;
							$output = file_get_contents($filename);
							Cache::set(array('jsmincdn', $element), $output, 3600 * 24);
						}
						elseif( strpos($element, Site::get_url('admin_theme')) === 0 ) {
							$base = substr($element, strlen(Site::get_url('admin_theme')));
							$filename = HABARI_PATH . '/system/admin' . $base;
							$output = file_get_contents($filename);
							Cache::set(array('jsmincdn', $element), $output, 3600 * 24);
						}
						elseif( ( strpos($element, 'http://') === 0 || strpos($element, 'https://' ) === 0 ) ) {
							$output = RemoteRequest::get_contents($element);
							Cache::set(array('jsmincdn', $element), $output, 3600 * 24);
						}
						else {
							$output = $element;
						}
						$script .= "\n\n/* {$name} */\n\n";
						$script .= JSMin::minify($output);
					}
					//$restack[$seqname] = $script;
					$restack[$seqname] = URL::get('jsmincdn', array('name' => $seqname));
					Cache::set(array('jsmincdn_post', $seqname), $script, 3600 * 24);
				}
			}

			$stack = $restack;
		}
		return $stack;
	}

	public function action_handler_script_cache( $handler_vars )
	{
		$cache_name = $handler_vars['name'];

		$script = Cache::get(array('jsmincdn_post', $cache_name));

		header('content-type: text/javascript');

		echo $script;
	}

	public function filter_rewrite_rules( $rules )
	{
		$rules[] = new RewriteRule( array(
			'name' => 'jsmincdn',
			'parse_regex' => '%^jsmincdn/(?P<name>.+)/?$%i',
			'build_str' => 'jsmincdn/{$name}/',
			'handler' => 'UserThemeHandler',
			'action' => 'script_cache',
			'priority' => 3,
			'is_active' => 1,
			'description' => 'Reply with a script from cache',
		));
		return $rules;
	}

}

?>