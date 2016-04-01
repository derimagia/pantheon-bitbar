#!/usr/bin/php
<?php

// <bitbar.title>Pantheon - List Sites</bitbar.title>
// <bitbar.version>v1.0</bitbar.version>
// <bitbar.author>Dave Wikoff</bitbar.author>
// <bitbar.author.github>derimagia</bitbar.author.github>
// <bitbar.desc>List and manage all of your sites you are on in Pantheon.</bitbar.desc>
// <bitbar.dependencies>php, terminus</bitbar.dependencies>
// <bitbar.image>https://i.imgur.com/VYBizXY.png</bitbar.image>
// <bitbar.abouturl>https://github.com/derimagia/pantheon-bitbar</bitbar.abouturl>

define('TERMINUS_PATH', '/usr/local/bin/terminus');
define('CACHE_PATH', '/tmp/pantheon-list-sites-cache.json');
define('DEBUG_MODE', false);

$php = PHP_BINARY;
$script = escapeshellarg($argv[0]);
$directory = dirname(__FILE__);
$html_filename = pathinfo(__FILE__, PATHINFO_FILENAME) . '.dynamic.html';
$html_filepath = $directory . '/' . $html_filename;
$cache = get_cache();

if (!empty($argv[1]) && function_exists($argv[1])) {
  $args = $argv;
  // Shift the first 2 arguments
  array_shift($args);
  array_shift($args);
  call_user_func_array($argv[1], $args);
  exit(0);
}


/**
 * Fetch site information
 */
if (isset($cache->sites)) {
	$sites = $cache->sites;
} else {
	$sites = terminus("sites list");

	if (!is_array($sites)) {
		echo 'Could not get site list. Did you auth using Terminus?';
		exit();
	}

	/* Fetch a list of environments for each site, sort them by name. */
	foreach ($sites as $key => $site) {
		$environments = terminus("site environments --site=$site->name");
		if (is_array($environments))
		{
			usort($environments, function($a, $b) {
				return strcmp($a->name, $b->name);
			});

			/* Move 'dev' 'test' 'live' to the beginning of the list. */
			foreach (array('live','test','dev') as $name) {
				foreach ($environments as $key2=>$value) {
					if ($value->name == $name) {
						$move = $environments[$key2];
						unset($environments[$key2]);
						array_unshift($environments, $move);
					}
				}
			}
		}
		
		$sites[$key]->environments = $environments;
	}

	$cache->sites = $sites;
	save_cache($cache);
}

/**
 * Build menu for each site
 */
foreach ($sites as $site) {
	$items[] = ['title' => $site->name];
	if (is_array($site->environments))
	{
		foreach ($site->environments as $environment) {
			$items[] = ['title' => '--'.$environment->name];
			$items[] = ['title' => "----Website", 'bash' => $php, 'param1' => $script, 'param2' => 'pantheon_open_site', 'param3' => $site->name, 'param4' => $environment->name, 'terminal' => 'false'];
			$items[] = ['title' => "----Admin Login", 'alternate' => 'true', 'bash' => $php, 'param1' => $script, 'param2' => 'drush_user_login', 'param3' => $site->name, 'param4' => $environment->name, 'terminal' => 'true'];
			$items[] = ['title' => '----Dashboard', 'bash' => $php, 'param1' => $script, 'param2' => 'pantheon_open_dashboard', 'param3' => $site->name, 'param4' => $environment->name, 'terminal' => 'false'];
			$items[] = ['title' => '----Clear Caches', 'bash' => $php, 'param1' => $script, 'param2' => 'pantheon_clear_cache', 'param3' => $site->name, 'param4' => $environment->name, 'terminal' => 'false'];
			if ($environment->name == 'live' AND count($site->environments) > 3) $items[] = ['title' => '--Multidev'];
		}
	} else { /* This site is "frozen" and has no environments available. Provide link to Dashboard page with "Unfreeze site" option.  */
		$items[] = ['title' => '--Dashboard', 'bash' => $php, 'param1' => $script, 'param2' => 'pantheon_open_dashboard', 'param3' => $site->name, 'terminal' => 'false'];
	}
}

/**
 * Output text in BitBar format
 */
echo "⚡\n";
echo "---\n";

foreach ($items as $item) {
  if (is_array($item)) {
    $parts = [];
    foreach ($item as $param => $value) {
      if ($param != 'title') $parts[] = $param . '="' . $value . '"';
    }
    $item = $item['title'] . ' | ' . implode(' ', $parts);
  }
  echo $item . "\n";
}

echo "---\n";
echo "Refresh Sites | bash=$php param1=$script param2=clear_cache terminal=false refresh=true";
exit(0);

/**
 * Open the dashboard for a site
 */
function pantheon_open_dashboard($site_id, $env_id) {
  return browser_open(terminus("site dashboard --print", $site_id, $env_id));
}

/**
 * Login to a site as User 1 for Drupal
 */
function drush_user_login($site_id, $env_id) {
  $login_url = drush($site_id, $env_id, "user-login 1");
  return browser_open($login_url);
}

/**
 * Clear all caches for a site
 */
function pantheon_clear_cache($site_id, $env_id) {
  terminus("site clear-cache", $site_id, $env_id);
}

/**
 * Open the URL for a site.
 */
function pantheon_open_site($site_id, $env_id) {
  $alias = drush_get_alias($site_id, $env_id);

  $url = sprintf('%s://%s', 'http', $alias['uri']);

  return browser_open($url);
}

/**
 * Get the Drush Alias for a Site ID / Env ID
 *
 * @return bool|array
 */
function drush_get_alias($site_id, $env_id) {
  $phpcode = terminus('sites aliases --print', NULL, NULL, FALSE);
  /* @var $aliases array[] */
  eval($phpcode);

  if (empty($aliases[$site_id . '.' . $env_id])) {
    echo 'Invalid Alias';
    exit(1);
  }

  return $aliases[$site_id . '.' . $env_id];
}

/**
 * Opens a URL in the browser
 */
function browser_open($url) {
  passthru("open $url", $return_var);
  return $return_var;
}

/**
 * Run a terminus command
 * @return object
 */
function terminus($command, $site_id = null, $env_id = null, $json = TRUE) {
  $extras = ' --yes';
  if (!empty($json)) $extras .= ' --format=json';
  if (!empty($site_id)) $extras .= ' --site=' . $site_id;
  if (!empty($env_id)) $extras .= ' --env=' . $env_id;

  $command = TERMINUS_PATH . " {$command}{$extras}";

  $output = passthrough_return($command);
  return $json ? json_decode($output) : $output;
}

/**
 * We need to manually call drush because we need to add custom SSH options that terminus doesn't support.
 *
 * @return bool
 */
function drush($site_id, $env_id, $drush_command) {
  $alias = drush_get_alias($site_id, $env_id);
  $remote_host = $alias['remote-host'];
  $remote_user = $alias['remote-user'];
  $ssh_options = $alias['ssh-options'] . ' -o "StrictHostKeyChecking=no" -o "UserKnownHostsFile=/dev/null"';

  $command = escapeshellarg('drush ' . $drush_command);
  $command = 'ssh -T ' . $remote_user . '@' . $remote_host . ' ' . $ssh_options . ' ' . $command;

  return passthrough_return($command);
}

/**
 * Passes the command through and returns it
 */
function passthrough_return($command) {
  ob_start();

  if (!DEBUG_MODE) {
    $command = $command . ' 2>/dev/null';
  }

  passthru($command);
  $output = ob_get_clean();

  if (DEBUG_MODE) {
    echo "----- DEBUG [$command] -----\n";
    echo "OUTPUT:\n";
    var_dump($output);
    echo "\n";
  }

  return $output;
}

/**
 * Get the cache for this plugin
 */
function get_cache() {
  if (file_exists(CACHE_PATH)) {
    $cache = json_decode(file_get_contents(CACHE_PATH));
  }
  return !empty($cache) ? $cache : new stdClass();
}

/**
 * Save the cache for this plugin
 */
function save_cache($cache) {
  return file_put_contents(CACHE_PATH, json_encode($cache));
}

/**
 * Clear the cache for this plugin
 */
function clear_cache() {
  return file_put_contents(CACHE_PATH, NULL);
}