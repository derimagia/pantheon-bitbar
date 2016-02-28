#!/usr/bin/php
<?php

// <bitbar.title>Pantheon - List Sites</bitbar.title>
// <bitbar.version>v1.0</bitbar.version>
// <bitbar.author>Dave Wikoff</bitbar.author>
// <bitbar.author.github>derimagia</bitbar.author.github>
// <bitbar.desc>List all of your sites you are on in Pantheon.</bitbar.desc>
// <bitbar.dependencies>php, terminus</bitbar.dependencies>
// <bitbar.image>http://i.imgur.com/2ark3Bq.png</bitbar.image>

define('TERMINUS_PATH', '/usr/local/bin/terminus');
define('CONFIG_PATH', '/tmp/pantheon-list-sites-config.json');

$php = PHP_BINARY;
$script = $argv[0];
$directory = dirname(__FILE__);
$html_filename = pathinfo(__FILE__, PATHINFO_FILENAME) . '.dynamic.html';
$html_filepath = $directory . '/' . $html_filename;
$config = get_config();
$env_id = $config->env_id ? $config->env_id : 'dev';

if (!empty($argv[1]) && function_exists($argv[1])) {
  $args = $argv;
  // Shift the first 2 arguments
  array_shift($args);
  array_shift($args);
  call_user_func_array($argv[1], $args);
  exit(0);
}

$sites = terminus("sites list --cached");

if (!is_array($sites)) {
  echo 'Could not get site list. Did you auth using Terminus?';
  exit();
}
if (!getenv('TERMINUS_ENV')) {
  putenv('TERMINUS_ENV=dev');
}


$symbolMap = [
  'dev' => '🔵',
  'test' => '⚫',
  'live' => '🔴',
];

$symbol = isset($symbolMap[$env_id]) ? $symbolMap[$env_id] : '';

$items = array(
  ['title'  => "Environment: $env_id -- $symbol", 'bash' => $php, 'param1' => $script, 'param2' => 'pantheon_switch_environment', 'param3' => $env_id, 'terminal' => 'false', 'refresh' => 'true'],
  '---',
);

foreach ($sites as $site) {
  $items[] = ['title' => $site->name, 'bash' => $php, 'param1' => $script, 'param2' => 'pantheon_open_site', 'param3' => $site->name, 'param4' => $env_id, 'terminal' => 'false'];
  if ($site->framework === 'drupal') {
    $items[] = ['title' => "$site->name -- 🔒", 'alternate' => 'true', 'bash' => $php, 'param1' => $script, 'param2' => 'drush_user_login', 'param3' => $site->name, 'param4' => $env_id, 'terminal' => 'false'];
  }
  $items[] = ['title' => '└ Pantheon Dashboard -- ⚡', 'bash' => $php, 'param1' => $script, 'param2' => 'pantheon_open_dashboard', 'param3' => $site->name, 'param4' => $env_id, 'terminal' => 'false'];
  $items[] = '---';
}

echo "⚡\n";
echo "---\n";

foreach ($items as $item) {
  if (is_array($item)) {
    $parts = [];
    foreach ($item as $param => $value) {
      $parts[] = $param . '="' . $value . '"';
    }
    $item = $item['title'] . ' | ' . implode(' ', $parts);
  }

  echo $item . "\n";
}
exit(0);

$html = template($items);
unlink($html_filepath);
file_put_contents($html_filepath, $html);
exit(0);

/**
 * Open the dashboard for a site
 */
function pantheon_open_dashboard($site_id) {
  return browser_open(terminus("site dashboard --print", $site_id));
}

/**
 * Login to the site as User 1 for Drupal
 */
function drush_user_login($site_id, $env_id) {
  $login_url = drush($site_id, $env_id, "user-login 1");
  return browser_open($login_url);
}

/**
 * Switch the environment
 */
function pantheon_switch_environment($current_env_id) {
  global $script, $env_id, $config;

  $environments = ['dev', 'test', 'live'];

  foreach ($environments as $env) {
    if ($env == $current_env_id) {
      break;
    }
  }

  $next_env = current($environments) ? current($environments) : 'dev';

  $config->env_id = $next_env;
  save_config($config);
}

/**
 * Returns the domain for a pantheon site.
 */
function pantheon_open_site($site_id, $env_id) {
  $alias = drush_get_alias($site_id, $env_id);

  $url = sprintf('%s://%s', 'https', $alias['uri']);

  return browser_open($url);
}

/**
 * Gets a Drush Alias for a Site ID / Env ID
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
 * Get a list of Pantheon Environments
 */
function pantheon_get_envs($site_id) {
  return terminus('site environments', $site_id);
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

  $drush_command .= ' --pipe';

  $command = escapeshellarg('drush ' . $drush_command);
  $command = 'ssh -T ' . $remote_user . '@' . $remote_host . ' ' . $ssh_options . ' ' . $command;

  return passthrough_return($command);
}

/**
 * Pass's the command through and returns it
 */
function passthrough_return($command) {
  ob_start();
  passthru($command . ' 2>/dev/null');
  $output = ob_get_clean();
  return $output;
}

/**
 * Gets the config for this plugin
 */
function get_config() {
  if (file_exists(CONFIG_PATH)) {
    $config = json_decode(file_get_contents(CONFIG_PATH));
  }
  return !empty($config) ? $config : new stdClass();
}

/**
 * Saves the config for this plugin
 */
function save_config($config) {
  return file_put_contents(CONFIG_PATH, json_encode($config));
}

/**
 * Print out the HTML
 */
function template($items = array()) {
$js = json_encode($items);
$pantheon_logo_base64 = "data:image/png;base64,iVBORw0KGgoAAAANSUhEUgAAAEAAAABACAMAAACdt4HsAAAABGdBTUEAALGPC/xhBQAAAAFzUkdCAK7OHOkAAAMAUExURQAAAB8fHiMjIv///97e3/3hL/T0/+vmwLGxsbKysh4iN7W1tVxcXL6+vv//+WFhYf///F9fX1lZWfj48+Li5xwhNermyv/iMuvmxR0hM////x0dHfz55P357v/66f//++/VNZGRlP/jNebm7f////352QkJCf///6WlpYGBgYWFhWlpaWRkZHJycsDAwODg4IiIiLCwsHJycj09PUBAQIeHh3p6evjtrPf37fThdu3RH/v33v////v21fLy5a2trfz1zffqn/ThcPDZRfbliPHcVfjvtvjvr/373v3fIh8kOu3p5ebhuerq6vz102xsbF1dXe7TKu7UL15eXrS0svbYKf396dDU4NHR0f371/////////n59v/wZB8iLry+2eLk+v/8pbu7u1JSUlpaWq6urkZGRkNDQ1ZWVr+/v/XmijIyMkRERPfsp/HbUfLfaP/87vHdWfPgZ/HaUfPeZPbqmu3RJ/HcVh4jNfTiefnxvt7e3klJSfjusP//6fjojCAhJPXje/DZS//46v/////jMPXlfvz214aGhvbplO/XPf/23vrxuvfnjvLeXx0eJ05OTv//56CgoPXmifXjf//zgvfnkebm2vDYRf3dIBweKa6ursbGxrS0tPzbFoyMjBccMHh4eObixe3ktOrq6oyMjPj4+G1tbWBjePfbJ9/ctfrYBi4wRhkbML29vfTdSJmdzP//yru7u+TfxQkNHxkZGe/WMqSs3v/1zyQkJLGxsfnhMyUlJZeVkhgZI5ubl6enpXJ2kaSnyi0vSP//rURHXPzqTvzsV1xeYf/tbf/6fv//////zy8xQP//wQEBBYCDov//pV9jfvnkOv//4///n6+11L/E4YiNrP///7S0tAAAAO7QG+3QG+3NDezLAuzNCu7RHurGAOrFAO3PG+zIAO3OEuvJAAEBAevHAO7RHfDXPu3OEOzLB+zLAurIAOzLBO7QGO3PFvDVMfDXPO7RIOi+AOnEAO3JAO7SI/XWGfDVOejDAO/KAPjcJe/GAF5WfaoAAADbdFJOUwD7+yEe6QNBa2jgU6lHI6UeusYQGuBA6j/fAfo1KyMS9n/qHBBP/Rh0qYK4ubtPLZpotungpLWSHcf8QgZIFFlZe738s9d5h0Pq4CNDNyWqpvz7wU/8OiFLOQ0nPMvaQC95QK2qSenpqUjY6NWM5NIn6dbfxJb53uGXXze+fxii97PYJQnloXOSmfo/cKzh7MwLfKe8rqMq5/7paF4+6XrimD5WPogomp/zSfXh3lbrN1xIMev8+T425mz17Zv3hWyKV9CBudbOrb+lLFXHQvxbaovwSc3INGxcd/PwkOYAAAMxSURBVFjDpZcFcNNQGIDT0bUbWlZox1zZ8G3IhkyYwYQBMzY2ZLgz3N3d3d3d3d1dmvSa0K10pGk7Cw5JoV13B3d5eV/uniT3vntJXv73B0H+gaglAsngYEhB2LcISMM4pR+cwCdvOuQUppUughO01RGxcIbF35ULoAQLKW3yZBjBsvkUESOCMczTFhLRMIIZJSpS3wbGMFuLG1ShfEa6V2WRzCHzcU0LCQ+Bb1HRJ4aoL4UoSoxoBS7o7EFTGooyoJgaMxIreUxhksaIqlEUVWNoAanl8SDdkw3McBRjD1yn8wU3TKFVShNMRRL5rsAC6aw4WxPyfSW28gNdQMcLw2TiPyQmJe0Xi3esETJ04rywmwkEY+Mrmti6V3qwZvzYqTYMgqYcxzdSWNOuvaXZmKPAvpxgWztLswlHgWc5Qe2speZmH46CuuYBgway5fIJEyuz9OrZkKOgjlkwN5stbZBN4ZVYwntLuQkaWGYvSWGrjC2KjywKRQA3QT3L/ftnsGVKgrnvwE1Q3yK4mHWOrXbvARNUL3sF7/3Zsv3Ov90gIMGpS55v34iunLezO4xstDMRAiSo3dnnWQ4iumpv779rgz1DYKA32C08f/mr9NVr60UVBPYQn3h3oX++e2QlcAB8jU99P394fB1cYFlIXSUv6Nt+l8sEAYBLWdEth/j8sFPq8Sos/VN7SAE/JsWtB1/pe6K7HaqxdOjXEPxz7n6f/pp9w9zrCx5QrqX/oG92Aw0o1iGt+x36gl9XwJCGNHMWCJZUrMVw8kymJu/Y0UM11wtsBM5NOYf1GkKhLFHm6OgoO3H2NH3Ebbs4gTkldALK0tbFecnlXmnJHQvz07w2rwLf3VZ/oJQqhkLcqFJpSBdwQyRh2l/V7BarJlaAC2au1eKMAVMzGz1mKHIHN+SW6HE1hpNMrkFRdGseeUaEQYdj+R5RxcXFn74Md+JhCNaTBZphkgpszpXLK9OLJIyk0gXhT6shWpxoDpNsurXQKAtcYQyhOoN+FFTaH6snPKCSdiSa0I6HEjiNKY2B+/kJGU1kwhla0iMh/wFbU26QhqHNIQU+A1wgDa7p/7vyG6UfRMrV+1PBAAAAAElFTkSuQmCC";
$template = <<<HTML
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="utf-8">
	<style type="text/css">
    * {
      user-select: none;
      -webkit-user-select: none;
      padding: 0;
      margin: 0;
    }

    html, body {
      width: 100%;
    }

		body {
			white-space: nowrap;
			font-family: "Lucida Grande";
			font-size: 12px;
			line-height: 22px;
		}
    .container {
      display: flex;
      width: 100%;
    }
		.pantheon-logo {
      width: 22px;
      height: 22px;
		}

	</style>
	<script type="text/javascript" src="https://ajax.googleapis.com/ajax/libs/jquery/2.2.0/jquery.min.js"></script>
</head>
<body>
  <div class="container">
    <img class="pantheon-logo" src="$pantheon_logo_base64" />
  </div>
	<script>
    var items = $js;
		jQuery(function($){
      $(window).contextmenu(function() {
        BitBar.showWebInspector();
      });
			$(window).click(function () {
        BitBar.resetMenu();
        BitBar.addMenuItems(items);
        BitBar.showMenu();
			});
		});
	</script>
</body>
</html>
HTML;
return $template;
}
