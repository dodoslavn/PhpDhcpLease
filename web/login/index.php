<?php
session_start();

if (isset($_SESSION['id'])) header('Location: ..');


if ( isset($_POST) )
  {
  if ( !empty($_POST['username']) && !empty($_POST['pass']) )
    {
    # load config file
    $config_file_raw = file_get_contents('../../config.json');
    $config = json_decode($config_file_raw);
    if (empty($config)) die("failed to parse JSON config");

    foreach ($config->users as $user) 
      {
      if (isset($user->name) && isset($user->pass) && isset($user->id) )
        {
        if ($user->name == $_POST['username'] && $user->pass == $_POST['pass'] ) 
          {
          $_SESSION["id"] = $user->id;
          header('Location: ..');
          }
        #else WRONG PASSWORD
        }
      #else JSON IS NOT CLEAN 
      }
    }
  }
#else FORM WAS NOT SUBMITED YET


?>




<form class="login100-form validate-form flex-sb flex-w" action="" method="post">

Login


<input class="input100" type="text" name="username" placeholder="Username">



<input class="input100" type="password" name="pass" placeholder="Password">


<input class="input-checkbox100" id="ckb1" type="checkbox" name="remember-me">


<button class="login100-form-btn" type="submit">
Login
</button>
</form>