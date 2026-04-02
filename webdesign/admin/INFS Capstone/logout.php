<?php
session_start();
session_destroy();
header("Location: FEDEXHR.php");
exit();
?>
