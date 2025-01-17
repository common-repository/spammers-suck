<?php

class SpamHammer {
	const VERSION = "3.9.7.1";

	static $servers = array(
		'production' => array(
			'label' => "Live (Production)",
			'value' => "services.wpspamhammer.com"
		),
		'testing' => array(
			'label' => "Beta (Testing)",
			'value' => "test.services.wpspamhammer.com"
		)
	);

	static $options = array(
		"server",
		"auth_token",
		"version",
		"honeypot_website_url",
		"uncloak_website_url",
		"nuke_comments",
		"default_policy"
	);

	public static function defaultFilters() {
		# register_activation_hook(SPAM_HAMMER_DIR . '/index.php', array(__CLASS__, 'register_activation_hook'));
		# register_deactivation_hook(SPAM_HAMMER_DIR . '/index.php', array(__CLASS__, 'register_deactivation_hook'));

		load_plugin_textdomain('spammers-suck', false, 'spammers-suck/languages');

		if (!current_user_can('administrator')) {
			# Process Form

			add_filter('pre_comment_approved', array(__CLASS__, 'pre_comment_approved'));
			add_filter('registration_errors', array(__CLASS__, 'registration_errors'));

			if (has_filter('wpcf7_spam')):
				add_filter('wpcf7_spam', array(__CLASS__, 'wpcf7_spam'));
			endif;

			# Render Form

			add_filter('comment_form', array(__CLASS__, 'comment_form'));
			add_action('comment_form_default_fields', array(__CLASS__, 'comment_form_default_fields'));
			add_filter('register_form', array(__CLASS__, 'register_form'));

			if (has_filter('wpcf7_form_elements')):
				add_filter('wpcf7_form_elements', array(__CLASS__, 'wpcf7_form_elements'));
			endif;
		}

		add_action('admin_menu', array(__CLASS__, 'admin_menu'));
		add_action('admin_head', array(__CLASS__, 'admin_head'));

		add_action('wp_dashboard_setup', array(__CLASS__, 'wp_dashboard_setup'));
		add_action('widgets_init', array(__CLASS__, 'widgets_init'));
	}

	public static function defaultOptions() {
		add_filter("default_option_spam_hammer_server", array(__CLASS__, "defaultServer"));
		add_filter("default_option_spam_hammer_auth_token", array(__CLASS__, "defaultAuthToken"));
		add_filter("default_option_spam_hammer_version", array(__CLASS__, "defaultVersion"));

		add_filter("default_option_spam_hammer_honeypot_website_url", array(__CLASS__, "defaultOptionTrue"));
		add_filter("default_option_spam_hammer_uncloak_website_url", array(__CLASS__, "defaultOptionTrue"));
		add_filter("default_option_spam_hammer_nuke_comments", array(__CLASS__, "defaultOptionTrue"));

		add_filter("default_option_spam_hammer_default_policy", array(__CLASS__, "defaultOptionStatistics"));

		if (current_user_can("administrator")):
			foreach (self::$options as $option):
				$key = "spam_hammer_{$option}";

				if (get_option($key, "@unset") === "@unset"):
					add_option($key, get_option($key));
				endif;

				register_setting("spam_hammer", $key);
			endforeach;
		endif;
	}

	public static function defaultServer($default = null) {
		if ($default):
			return $default;
		endif;

		return self::$servers['production']['value'];
	}

	public static function defaultAuthToken($default = null) {
		if ($default):
			return $default;
		endif;

		return "";
	}

	public static function defaultVersion($default = null) {
		if ($default):
			return $default;
		endif;

		return self::VERSION;
	}

	public static function defaultOptionStatistics($default = null) {
		if ($default):
			return $default;
		endif;

		return SpamHammer_Proxy::statistics(array('action' => "set"));
	}

	public static function defaultOptionTrue($default = null) {
		if ($default):
			return $default;
		endif;

		return true;
	}

	public static function defaultOptionFalse($default = null) {
		if ($default):
			return $default;
		endif;

		return false;
	}

	public static function comment_form_default_fields($fields) {
		if (isset($fields['url']) && get_option("spam_hammer_honeypot_website_url")):
			unset($fields['url']);
		endif;

		return $fields;
	}

	public static function getRemoteAddr($src_ip = null) {
		if (!$src_ip):
			$src_ip = $_SERVER["REMOTE_ADDR"];
		endif;

		if (filter_var($src_ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)):
			return $src_ip;
		endif;

		$dst_ip = '';

		foreach (array("HTTP_X_FORWARDED_FOR", "HTTP_X_REAL_IP", "HTTP_CLIENT_IP", "HTTP_FROM") as $header):
			if (!$dst_ip && isset($_SERVER[$header]) && !empty($_SERVER[$header]) && preg_match_all('/(\d{1,3}\.\d{1,3}\.\d{1,3}\.\d{1,3})/', $_SERVER[$header], $matches)):
				foreach ($matches[0] as $match):
					if (filter_var($match, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)):
						$dst_ip = $match;
						break;
					endif;
				endforeach;
			endif;
		endforeach;

		return $dst_ip ? $dst_ip : $src_ip;
	}

	public static function getWebsiteUrl($url) {
		$user_agent = "Mozilla/5.0 (Windows NT 6.3; rv:36.0) Gecko/20100101 Firefox/36.0";
		$max_redirects = 20;
		$timeout = 15;

		if (function_exists("get_headers", "timeout") && ini_get("allow_url_fopen")) {
			stream_context_set_default(array(
				'http' => compact("max_redirects") + array(
					'header' => "User-Agent: {$user_agent}"
				)
			));

		    if (($headers = @get_headers($url, 1)) != false):
			    foreach (array_keys($headers) as $key):
			    	if (preg_match("/^Location$/i", $key) && $key != "Location"):
			    		$headers["Location"] = $headers[$key];
			    		unset($headers[$key]);
			    	endif;
			    endforeach;

			    if (isset($headers["Location"])):
			    	if (($location = !is_array($headers["Location"]) ? $headers["Location"] : end($headers["Location"])) != false):
			    		return $location;
			    	endif;
			    endif;
			endif;
		}

		if (function_exists("curl_init") && ($ch = curl_init()) != false) {
			curl_setopt_array($ch, array(
				CURLOPT_URL => $url,
				CURLOPT_HTTPHEADER => array(
					"User-Agent: {$user_agent}"
				),
				CURLOPT_RETURNTRANSFER => true,
				CURLOPT_FOLLOWLOCATION => true,
				CURLOPT_MAXREDIRS => $max_redirects,
				CURLOPT_TIMEOUT => $timeout
			));

			@curl_exec($ch);

			if (($location = curl_getinfo($ch, CURLINFO_EFFECTIVE_URL)) != false):
				curl_close($ch);
				return $location;
			endif;

			curl_close($ch);
		}

		return $url;
	}

	public static function adminInit() {
		if (($plugins = self::getPlugins()) != false):
			foreach (array("w3-total-cache", "wp-super-cache", "quick-cache", "disqus-comment-system", "akismet") as $plugin):
				if (in_array($plugin, array_keys($plugins)) && is_plugin_active("{$plugin}/{$plugins[$plugin]['Script']}")):
					deactivate_plugins("{$plugin}/{$plugins[$plugin]['Script']}");
				endif;
			endforeach;
		endif;

		if (get_option("spam_hammer_version") != self::VERSION):
			update_option("spam_hammer_version", self::VERSION);
		endif;

		if (!get_option("spam_hammer_auth_token")):
			SpamHammer_Network::get("spamhammer", "get");
		endif;

		if (!($statistics = get_option("spam_hammer_statistics")) || !$statistics['pull'] || $statistics['pull'] <= strtotime("-1 hour")):
			return SpamHammer_Proxy::statistics(array('action' => "set"));
		endif;
	}

	public static function admin_head() {
		global $current_screen;

		if (!isset($current_screen) || !$current_screen || $current_screen->id != "dashboard") {
			return;
		}

		$tags = array(
			array(
				'html' => '<script src="https://www.google.com/jsapi"></script>',
				'data' => array()
			),
			array(
				'html' => '<script src="//%1$s/js/remote-stats.js" id="SpamHammerRemoteStats" data-spam-hammer-server="%1$s" data-spam-hammer-auth-token="%2$s" data-spam-hammer-timezone-string="%3$s" data-spam-hammer-platform="%4$s" data-spam-hammer-admin="%5$s"></script>',
				'data' => array(get_option('spam_hammer_server'), get_option('spam_hammer_auth_token'), get_option('timezone_string'), 'wordpress', get_option('admin_email'))
			)
		);

		if (!wp_script_is('jquery', 'registered')) {
			array_push($tags, array(
				'html' => '<script src="//ajax.googleapis.com/ajax/libs/jquery/1/jquery.min.js"></script>',
				'data' => array()
			));
		}

		foreach ($tags as $tag) {
			vprintf($tag['html'], $tag['data']);
		}
	}

	public static function wp_dashboard_setup() {
		add_meta_box('spam_hammer_counter', __('Right Now', 'spammers-suck'), array(__CLASS__, 'render_counter'), 'dashboard', 'normal', 'high');
		add_meta_box('spam_hammer_chart', __('Spam Attack Chart', 'spammers-suck'), array(__CLASS__, 'render_chart'), 'dashboard', 'side', 'high');
	}

	public static function widgets_init() {
		require_once SPAM_HAMMER_DIR . '/includes/widget.php';
		register_widget('SpamHammer_Widget');
	}

	public static function render_counter() {
		echo self::template('wp-admin/dashboard_widgets/counter', array('description' => __('Total Spam Attacks Against Your Blog', 'spammers-suck')));
	}

	public static function render_chart() {
		echo self::template('wp-admin/dashboard_widgets/chart');
	}

	public static function updateOptions() {
		SpamHammer_Network::get('spamhammer', 'set', array('remote-settings' => $_POST['remote-settings']));
	}

	static function pre_comment_approved($approved, &$comment_data = null) {
	    if ($comment_data && $comment_data['comment_author_url']):
	    	$comment_data['comment_author_url'] = self::getWebsiteUrl($comment_data['comment_author_url']);
	    endif;

		if (!self::process_form(array('type' => 'comment'))) {
			if (!get_option("spam_hammer_nuke_comments")):
				$approved = 'spam';
			else:
				wp_die(__("There is suspicious activity on your network and registration can only continue with the webmaster's assistance -- contact him or her immediately.", 'spammers-suck'));
			endif;
		}

		return $approved;
	}

	static function registration_errors($errors, $sanitized_user_login = '', $user_email = '') {
		if (!$errors->errors && !self::process_form(array('type' => 'register'))) {
			wp_die(__("There is suspicious activity on your network and registration can only continue with the webmaster's assistance -- contact him or her immediately.", 'spammers-suck'));
		}

		return $errors;
	}

	static function wpcf7_spam($spam) {
		if ($spam) {
			return $spam;
		}

		return !self::process_form(array(
			'type' => 'contact',
			'user_name' => isset($_POST['your-name']) ? $_POST['your-name'] : '',
			'email_address' => isset($_POST['your-email']) ? $_POST['your-email'] : ''
		));
	}

	static function process_form($params = array()) {
		$defaults = array(
			'request' => array('spam_hammer' => $_POST['spam_hammer']),
			'ip_address' => self::getRemoteAddr(),
			'honeypots' => array(
				'website_url' => array(
					'snare' => get_option("spam_hammer_honeypot_website_url"),
					'input' => isset($_POST['url']) ? self::getWebsiteUrl($_POST['url']) : ""
				)
			),
			'user_name' => isset($_POST['author']) ? $_POST['author'] : '',
			'email_address' => isset($_POST['email']) ? $_POST['email'] : '',
			'user_agent' => (isset($_SERVER['HTTP_USER_AGENT']) && !empty($_SERVER['HTTP_USER_AGENT'])) ? $_SERVER['HTTP_USER_AGENT'] : ''
		);

		$params += $defaults;
		$process = SpamHammer_Network::get('spamhammer', 'process_form', $params);

		if (!$process || is_array($process)) {
			return false;
		}

		return true;
	}

	static function wpcf7_form_elements($form_elements) {
		return $form_elements . self::render_form(array('type' => 'contact'));
	}

	static function comment_form($post_id) {
		echo self::render_form(array('type' => 'comment'));
	}

	static function register_form() {
		echo self::render_form(array('type' => 'register'));
	}

	static function render_form($params = array()) {
		$defaults = array(
			'template' => 'input',
			'type' => 'comment',
			'time' => time(),
			'ip_address' => self::getRemoteAddr()
		);

		$params += $defaults;

		if (!($markup = SpamHammer_Network::get('spamhammer', 'render_form', $params)) || is_array($markup)):
			return '';
		endif;

		return $markup;
	}

	static function register_activation_hook() {
		# TODO
	}

	static function register_deactivation_hook() {
		# TODO
	}

	static function admin_menu() {
		global $wp_version;

		add_menu_page(__('Spam Hammer', 'spammers-suck'), __('Spam Hammer', 'spammers-suck'), 'manage_options', 'spam_hammer', array(__CLASS__, 'admin_options_page'), "http://" . get_option("spam_hammer_server", "services.wpspamhammer.com") . "/img/dashicon.png", 3);
	}

	static function admin_options_page() {
		if (!($response = SpamHammer_Network::get('spamhammer', 'get')) || is_array($response)) {
			if (!is_array($response)) {
				$statistics = sprintf('<h1 style="color: red; margin: 0;">%1$s*</h1>', __('Protection Inactive', 'spammers-suck')) .
					sprintf('<dfn>* %1$s</dfn>', __('Critical Error #0: Contact Support', 'spammers-suck'));
			} else {
				$statistics = $response['response'];
			}
		} else {
			$statistics = $response;
		}

		$servers = array();

		foreach (self::$servers as $type => $server):
			if ($type == "testing" && !defined("WP_DEBUG") && !WP_DEBUG):
				continue;
			endif;

			$servers[] = $server;
		endforeach;

		$input_fields = implode("\n", array(
			 self::template('wp-admin/settings_form/radio', array(
				'options' => $servers,
				'key' => 'spam_hammer_server',
				'name' => __('Software Version', 'spammers-suck'),
				'value' => get_option('spam_hammer_server'),
				'description' => __('Use the Live option; the Beta option works for registered beta testers only.')
			)),
			self::template('wp-admin/settings_form/text', array(
				'key' => 'spam_hammer_auth_token',
				'name' => __('Authentication Token', 'spammers-suck'),
				'value' => esc_attr(get_option('spam_hammer_auth_token')),
				'description' => implode('<br />', array(
					implode('&nbsp;&nbsp;', array(
						__('Authentication token for your account.', 'spammers-suck'),
					))
				))
			)),
			self::template('wp-admin/settings_form/radio', array(
				'options' => array(
					array('label' => __('Yes', 'spammers-suck'), 'value' => true),
					array('label' => __('No', 'spammers-suck'), 'value' => false)
				),

				'key' => 'spam_hammer_nuke_comments',
				'name' => __('Nuke Comments', 'spammers-suck'),
				'value' => get_option('spam_hammer_nuke_comments'),
				'description' => __('Whether or not to delete comments instead of sending them to the Spam folder.', 'spammers-suck')
			)),
			self::template('wp-admin/settings_form/radio', array(
				'options' => array(
					array('label' => __('Yes', 'spammers-suck'), 'value' => true),
					array('label' => __('No', 'spammers-suck'), 'value' => false)
				),

				'key' => 'spam_hammer_honeypot_website_url',
				'name' => __('Website Url Honeypot', 'spammers-suck'),
				'value' => get_option('spam_hammer_honeypot_website_url'),
				'description' => __('Hide the comment form "Website Url" box from humans and snare bots that submit the website url anyway.', 'spammers-suck')
			)),
			self::template('wp-admin/settings_form/radio', array(
				'options' => array(
					array('label' => __('Yes', 'spammers-suck'), 'value' => true),
					array('label' => __('No', 'spammers-suck'), 'value' => false)
				),

				'key' => 'spam_hammer_uncloak_website_url',
				'name' => __('Uncloak Website Urls', 'spammers-suck'),
				'value' => get_option('spam_hammer_uncloak_website_url'),
				'description' => __('Get and display the final destinations of website urls sent in the comment form.', 'spammers-suck')
			)),
			self::template('wp-admin/settings_form/radio', array(
				'options' => array(
					array('label' => __('MODERATE', 'spammers-suck'), 'value' => true),
					array('label' => __('DROP', 'spammers-suck'), 'value' => false)
				),

				'key' => 'spam_hammer_default_policy',
				'name' => __('Default Policy', 'spammers-suck'),
				'value' => get_option('spam_hammer_default_policy'),
				'description' => __('How to treat comments if the Spam Hammer network becomes unreachable.', 'spammers-suck')
			)),
			self::template('wp-admin/settings_form/raw', array(
				'key' => 'spam_hammer_statistics',
				'name' => __('Connection & Account', 'spammers-suck'),
				'markup' => $statistics
			))
		));

		$hidden_fields = implode(PHP_EOL, array(
			'<input type="hidden" name="option_page" value="spam_hammer" />',
			'<input type="hidden" name="action" value="update" />',
			wp_nonce_field('spam_hammer-options', '_wpnonce', true, false)
		));

		echo self::template('wp-admin/settings_form/form', compact('input_fields', 'hidden_fields') + array(
			'title' => __('Spam Hammer Settings', 'spammers-suck'),
			'icon' => 'icon-users',

			'submit' => __('Save Changes', 'spammers-suck'),
			'cancel' => __('Reset', 'spammers-suck')
		));
	}

	public static function template($name, $data = array()) {
		if (!$name || !file_exists(($template__ = SPAM_HAMMER_DIR . "/templates/{$name}.html.php"))) {
			return false;
		}

		if (!empty($data)) {
			extract($data, EXTR_OVERWRITE);
		}

		ob_start();
		include $template__;
		return ob_get_clean();
	}

	public static function getPlugins() {
		if (!($defaults = get_plugins())):
			return false;
		endif;

		foreach ($defaults as $key => $value):
			if (strpos($key, DIRECTORY_SEPARATOR) !== false):
				list($plugin, $Script) = explode(DIRECTORY_SEPARATOR, $key, 2);
				$plugins[$plugin] = $value + compact("Script");
			else:
				$plugins[$key] = $value;
			endif;
		endforeach;

		return $plugins;
	}
}

if (!class_exists('SpamHammer_Network')) {
	class SpamHammer_Network {
		public static $functions = array(
			'SpamHammer_Proxy' => array(
				'set_auth_token',
				'terminate'
			),

			'wp_mail',
			'wp_die'
		);

		public static function functions($key = null, $value = null) {
			if (!isset(self::$functions)) {
				self::$functions = array();
			}

			if ($key === null) {
				return self::$functions;
			}

			if ($value !== null) {
				return self::$functions[$key] = $value;
			}

			return isset(self::$functions[$key]) ? self::$functions[$key] : false;
		}

		public static function get($product, $hook, $params = array()) {
			$server = get_option("spam_hammer_server");

			$params += array(
				'url' => get_bloginfo('url'),
				'time' => time(),
				'blog_version' => get_bloginfo('version'),
				'auth_token' => get_option('spam_hammer_auth_token'),
				'10_day' => !get_option('spam_hammer_auth_token'),
				'plugin_version' => SpamHammer::VERSION,
				'member' => is_user_logged_in(),
				'session_hash' => md5(LOGGED_IN_SALT . md5(SpamHammer::getRemoteAddr()))
			);

			if (ini_get('allow_url_fopen')) {
				$opts = array('http' => array(
					'method' => 'POST',
					'timeout' => 30,
					'header' => implode("\r\n", array(
						sprintf('Accept: %1$s', 'application/json'),
						sprintf('Accept-Language: %1$s', !WPLANG ? 'en_US' : WPLANG),
						sprintf('Accept-Charset: %1$s', get_bloginfo('charset'))
					)),
					'content' => http_build_query($params)
				));

				if (($response = @json_decode(@file_get_contents("http://{$server}/plugins/{$product}/{$hook}", false, stream_context_create($opts)), true)) === null) {
					return false;
				}
			} else if (function_exists('curl_init') && ($ch = curl_init()) != false) {
				curl_setopt_array($ch, array(
					CURLOPT_URL => "http://{$server}/plugins/{$product}/{$hook}",
					CURLOPT_POST => true,
					CURLOPT_POSTFIELDS => http_build_query($params),
					CURLOPT_HTTPHEADER => array(
						sprintf('Accept: %1$s', 'application/json'),
						sprintf('Accept-Language: %1$s', !WPLANG ? 'en_US' : WPLANG),
						sprintf('Accept-Charset: %1$s', get_bloginfo('charset'))
					),
					CURLOPT_RETURNTRANSFER => true
				));

				$response = @json_decode(@curl_exec($ch), true);
				curl_close($ch);
			} else {
				return false;
			}

			if (is_array($response)) {
				if (isset($response['executions']) && !empty($response['executions'])) {
					foreach ($response['executions'] as $execution) {
						if ((!$execution['class'] && !$execution['function']) || !$execution['function']) {
							continue;
						}

						if ($execution['class'] && $execution['function'] && ($function = array($execution['class'], $execution['function'])) != false) {
							if (!in_array($execution['function'], self::functions($execution['class']))) {
								continue;
							}
						}

						if (!$execution['class'] && ($function = $execution['function']) != false) {
							if (!in_array($execution['function'], self::functions())) {
								continue;
							}

							if ($function == 'wp_mail') {
								$execution['params'][0] = get_option('admin_email');
							}
						}

						if (isset($execution['params'])) {
							call_user_func_array($function, $execution['params']);
						} else {
							call_user_func($function);
						}
					}

					if (isset($response['response']) && !empty($response['response'])) {
						return $response['code'] == 200 ? $response['response'] : $response;
					}

					return true;
				}

				if (isset($response['response']) && !empty($response['response'])) {
					return $response['code'] == 200 ? $response['response'] : $response;
				}
			}

			return $response;
		}

		public static function wget($params = array()) {
			$defaults = array(
				'method' => '',
				'timeout' => 15,
				'content' => array(),
				'user_agent' => 'Mozilla/5.0 (X11; Linux x86_64) AppleWebKit/537.22 (KHTML, like Gecko) Ubuntu Chromium/25.0.1364.160 Chrome/25.0.1364.160 Safari/537.22',
				'headers' => array(),
				'curl_setopt_array' => array()
			);

			$params += $defaults;

			if (!$params['method']) {
				$params['method'] = 'GET';
			}

			if ($params['method'] == 'GET' && !empty($params['content'])) {
				$params['url'] = implode('?', array($params['url'], http_build_query($params['content'])));
			}

			if (function_exists('curl_init')) {
				$resource = curl_init();

				curl_setopt($resource, CURLOPT_URL, $params['url']);
				curl_setopt($resource, CURLOPT_TIMEOUT, $params['timeout']);
				curl_setopt($resource, CURLOPT_USERAGENT, $params['user_agent']);
				curl_setopt($resource, CURLOPT_SSL_VERIFYPEER, false);
				curl_setopt($resource, CURLOPT_RETURNTRANSFER, true);

				if ($params['curl_setopt_array']) {
					curl_setopt_array($resource, $params['curl_setopt_array']);
				}

				if ($params['method'] == 'POST') {
					$params['headers'][] = sprintf('Content-Length: %d', strlen(http_build_query($params['content'])));

					curl_setopt($resource, CURLOPT_POST, true);
					curl_setopt($resource, CURLOPT_POSTFIELDS, http_build_query($params['content']));
				}

				if ($params['headers']) {
					curl_setopt($resource, CURLOPT_HTTPHEADER, $params['headers']);
				}

				$response = curl_exec($resource);
				curl_close($resource);
			} else if (ini_get('allow_url_fopen')) {
				extract(array_intersect_key($params, $defaults));
				$options = compact('method', 'content', 'timeout', 'user_agent');

				if ($params['headers']) {
					$options['header'] = implode("\r\n", $params['headers']);
				}

				$response = file_get_contents($params['url'], false, stream_context_create($options));
			}

			return $response;
		}
	}
}

class SpamHammer_Proxy {
	public static function set_auth_token($params = array()) {
		$auth_token = trim(get_option("spam_hammer_auth_token"));

		if ($auth_token) {
			return false;
		}

		return update_option("spam_hammer_auth_token", $params['auth_token']);
	}

	public static function statistics($params = array()) {
		$defaults = array(
			'action' => "get"
		);

		$params += $defaults;

		if ($params['action'] == "set") {
			$server = get_option("spam_hammer_server");
			$auth_token = get_option("spam_hammer_auth_token");

			if (!($response = @json_decode(@SpamHammer_Network::wget(array('url' => "http://{$server}/cache/subscriptions/{$auth_token}/statistics.ytd.json"), true))) || !is_array($response) || empty($response)) {
				return false;
			}

			return update_option("spam_hammer_statistics", $response + array('pull' => time()));
		}
	}

	public static function terminate() {
		exit;
	}
}