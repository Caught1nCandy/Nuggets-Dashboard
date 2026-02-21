<?php
session_start();
if (!isset($_SESSION['authorized'])) {
    session_destroy();
    header("Location: FEDEXHR.php"); // Figure out whats wrong here before next meeting (all people need to use the hard code login
    exit();
}
?>

<html>
<head>
<title> Private Home </title>
<link rel="stylesheet" href="FPriv.css"> 
</head>
<body>

<div class="navbar">
  <a href="Fprivhome.php">Employee Search</a>
  <a href="Fmap.php">Maps</a>
  <a href="Fevent.php">Events</a>
  <a href="Fdrill.php">Drill Down</a>
  <a href="Frequest.php">Update Request</a>
</div>

<img src="fimg/esearch.jpg" width="1275">
<img src="fimg/eesearch.jpg" width="1275">



</body>
</html>