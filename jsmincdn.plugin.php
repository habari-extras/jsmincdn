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
		$theme = Themes::create();
		Plugins::act('template_header', $theme);
		Plugins::act('template_footer', $theme);
		$options = Stack::get_named_stack('template_header_javascript');
		$options = array_merge($options, Stack::get_named_stack('template_footer_javascript'));

		$options_out = array();
		foreach($options as $option => $value) {
			if(preg_match('#[a-f0-9]{32}#', $option)) {
				$value = htmlspecialchars(substr($value, 0, 80));
			}
			else {
				$value = $option;
			}
			$options_out[$option] = $value;
		}
		$scripts->options = $options_out;

		$ui->append('submit', 'submit', 'Submit');

		return $ui;
	}

	function filter_stack_out($stack, $stack_name, $filter)
	{
		static $incmin = false;

		if ( in_array($stack_name, array('template_header_javascript', 'template_footer_javascript')) && is_callable($filter) && strcasecmp(implode('::', $filter), 'stack::scripts') == 0) {

			// Load the minifier class once
			if(!$incmin) {
				include __DIR__ . '/jsmin/jsmin.php';
				$incmin = true;
			}

			// Get the script names to minify
			$domin = Options::get('jsmincdn__storage');

			// Find greatest common sequences
			$seqs = array();
			$script_build = 'jsmincdn';
			$seq = array();
			foreach( $stack as $stackitem ) {
				$doomit = false;
				$name = $stackitem->name;

				if($domin && in_array($name, $domin)) {
					$script_build .= '.' . $name;
					$seq[$name] = $stackitem;
				}
				else {
					if(count($seq) > 0) {
						$seqs[$script_build] = $seq;
						$script_build = 'jsmincdn';
						$seq = array();
					}
					$seqs[$name] = $stackitem;
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

				if($seqelement instanceof StackItem) {
					$doomit = true;
					$restack[$seqname] = $seqelement;
					//$restack[$seqname] = new StackItem($seqname, '0', $seqelement);
				}
				elseif(Cache::has(array('jsmincdn_post', $seqname))) {
					$doomit = false;
					$output = Cache::get(array('jsmincdn_post', $seqname));
					//$restack[$seqname] = $output;
					//$restack[$seqname] = URL::get('jsmincdn', array('name' => $seqname));
					$restack[$seqname] = new StackItem($seqname, '0', URL::get('jsmincdn', array('name' => $seqname)));
				}
				else {
					foreach($seqelement as $name => $element) {
						$script .= "\n\n/* {$name} */\n\n";
						if(is_string($element) && strpos($element, "\n") !== FALSE) {
							$script .= '/** FROM (a): ' . $element->resource . " **/\n";
							$output = $element;
						}
						elseif(Cache::has(array('jsmincdn', $element->resource))) {
							$script .= '/** FROM (b): ' . $element->resource . " **/\n";
							$output = Cache::get(array('jsmincdn', $element->resource));
						}
						elseif( strpos($element->resource, Site::get_url('scripts')) === 0 ) {
							$script .= '/** FROM (c): ' . $element->resource . " **/\n";
							$base = substr($element->resource, strlen(Site::get_url('scripts')));
							$filename = HABARI_PATH . '/system/vendor' . $base;
							$output = file_get_contents($filename);
							Cache::set(array('jsmincdn', $element->resource), $output, 3600 * 24);
						}
						elseif( strpos($element->resource, Site::get_url('habari')) === 0 ) {
							$script .= '/** FROM (d): ' . $element->resource . " **/\n";
							$base = substr($element->resource, strlen(Site::get_url('habari')));
							$filename = HABARI_PATH . $base;
							$output = file_get_contents($filename);
							Cache::set(array('jsmincdn', $element->resource), $output, 3600 * 24);
						}
						elseif( strpos($element->resource, Site::get_url('admin_theme')) === 0 ) {
							$script .= '/** FROM (e): ' . $element->resource . " **/\n";
							$base = substr($element->resource, strlen(Site::get_url('admin_theme')));
							$filename = HABARI_PATH . '/system/admin' . $base;
							$output = file_get_contents($filename);
							Cache::set(array('jsmincdn', $element->resource), $output, 3600 * 24);
						}
						elseif( ( strpos($element->resource, 'http://') === 0 || strpos($element->resource, 'https://' ) === 0 ) ) {
							$script .= '/** FROM (f): ' . $element->resource . " **/\n";
							$output = RemoteRequest::get_contents($element->resource);
							Cache::set(array('jsmincdn', $element->resource), $output, 3600 * 24);
						}
						else {
							$output .= '/** FROM (g): ' . $element->resource . " **/\n";
							$output = $element->resource;
						}
						$script .= JSMin::minify($output);
					}
					$restack[$seqname] = new StackItem($seqname, '0', URL::get('jsmincdn', array('name' => $seqname)));
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
