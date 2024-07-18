<?

session_start();
$debug = true;

if ($debug)
  {
  error_reporting(E_ALL);
  ini_set('display_errors', 'On');
  }

  # load config file
$config_file_raw = file_get_contents('../config.json'); 
$config = json_decode($config_file_raw); 
if (empty($config)) die("failed to parse JSON config");

# check if logged in
if (empty($_SESSION['id'])) header('Location: login/');


foreach ($config->configuration->lease_files as $lease_file)
	{
	echo $lease_file."<br>";
	$pattern = '/^lease\s/m';
	$exploded = preg_split($pattern, file_get_contents($lease_file), -1, PREG_SPLIT_NO_EMPTY);
	$exploded = array_slice($exploded, 1);
	foreach ( $exploded as $one_lease )
		{
		$ip = explode(" ", $one_lease)[0];
		$content = explode(";", $one_lease);
		echo '<h4>'.$ip."</h4>";
		echo '<ul>';
		foreach($content as $one_line)
			{
			echo '<li>';
			echo $one_line;
			echo '</li>';
			}
		echo '</ul>';
		}
	}

?>