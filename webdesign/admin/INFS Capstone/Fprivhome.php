<?php
session_start();

if (!isset($_SESSION['authorized'])) {
    session_destroy();
    header("Location: FEDEXHR.php");
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
</div>

<img src="fimg/esearch.jpg">
<img src="fimg/eesearch.jpg">

<h3> Private Home </h3>
Private content...

</body>
</html>