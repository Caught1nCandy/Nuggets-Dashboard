<?php
session_start();

if (!isset($_SESSION['data_request'])) {
    echo "No request data found.";
    exit();
}
$data = $_SESSION['data_request'];
?>

<html>
<head>
<title> Request Success</title>
<link rel="stylesheet" href="FPriv.css">
<style>
body {
  background-image: url('Fimg/closeup.jpg');
}

.box {
  background-color: #4D148C;
  color: white;
  width: 400px;
  margin: 100px auto;
  padding: 30px;
  border-radius: 20px;
}

button {
  margin: 10px;
  padding: 10px 20px;
  border: none;
  background-color: white;
  color: #4D148C;
  font-weight: bold;
  cursor: pointer;
}
</style>
</head>
<body>

<div class="navbar">
  <a href="Fprivhome.php">Request Submitted</a>
  <a href="Fmap.php">Maps</a>
  <a href="Fevent.php">Events</a>
  <a href="Fdrill.php">Drill Down</a>
  <a href="Frequest.php">Update Request</a>
</div>

<div class="box">
  <h2>Request Submitted</h2>

  <p><b>Name: </b><?php echo $data['name']; ?></p>
  <p><b>ID: </b><?php echo $data['id']; ?></p>
  <p><b>Reason: </b><?php echo $data['reason']; ?></p>
  <p><b>Details: </b><?php echo $data['details']; ?></p>

  <br>

  <a href="Frequest.php"> <button>Submit Another Request</button> </a>
  <a href="Fprivhome.php"> <button>Return to Home</button> </a>

</div>
<?php unset($_SESSION['request_data']);?>
</body>
</html>