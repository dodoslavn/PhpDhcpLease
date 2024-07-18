<html>
<body>
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
$binary = $config->configuration->binary;

foreach ($config->configuration->lease_files as $lease_file)
	{
	$command = $binary." --parsable --lease ".$lease_file;
	exec($command, $output, $return_var);
	foreach($output as $one_lease)
		{
		$exploded = explode(" ", $one_lease);
		echo '
<table>
	<tr>
		<td>'.$exploded[0].'</td>
		<td>'.$exploded[1].'</td>
	</tr>
	<tr>
		<td>'.$exploded[2].'</td>
		<td>'.$exploded[3].'</td>
	</tr>
	<tr>
		<td>'.$exploded[4].'</td>
		<td>'.$exploded[5].'</td>
	</tr>
	<tr>
		<td>'.$exploded[6].'</td>
		<td>'.$exploded[7].' '.$exploded[8].'</td>
	</tr>
	<tr>
		<td>'.$exploded[8].'</td>
		<td>'.$exploded[9].' '.$exploded[10].'</td>
	</tr>
	<tr>
		<td>'.$exploded[11].'</td>
		<td>'.$exploded[12].'</td>
	</tr>
</table>';
		}
	}




?>

</body>
</body>