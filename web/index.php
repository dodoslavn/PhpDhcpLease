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


foreach ($config->configuration->lease_files as $user)
	{
	echo $user."<br>";
	echo file_get_contents('../../config.json');
	}

?>