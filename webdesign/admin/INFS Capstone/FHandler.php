<html>

<head> 
<title> php handler </title> 
</head> 

<body>
<h3> Handler <h3> 
<?php 
session_start();
$un = $_POST['name'] ;  // Captures what is in the post section in Method section
$ps = $_POST['pswd'] ;  // Captures password in name section 
$ip = $_POST['ip'] ;

echo $ip . "<br>";

// echo $un ;  // inputs as whatever name in your browser or put $ps to display password (soft coding) 

if ($un == "Manager1" && $ps == "Abc123") {
    $_SESSION['authorized'] = true;
    header("Location: Fprivhome.php");
    exit();
}
else {
	echo "sorry login failed";
} 

?>


</body>


</html>
