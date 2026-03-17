<style>
.form-container {
  background-color: #4D148C;
  width: 400px;
  margin: 80px auto;
  padding: 30px;
  border-radius: 20px;
  color: white;
  text-align: left;
}

.form-container h1 {
  text-align: center;
  margin-bottom: 20px;
}

.form-container input,
.form-container textarea,
.form-container select {
  width: 100%;
  padding: 10px;
  margin: 10px 0 20px 0;
  border: none;
  border-radius: 5px;
}

.form-container button {
  width: 100%;
  padding: 12px;
  background-color: white;
  color: #4D148C;
  border: none;
  border-radius: 5px;
  font-weight: bold;
  cursor: pointer;
}

.form-container button:hover {
  background-color: #ddd;
}
#charCount {
  text-align: right;
  font-size: 12px;
  margin-top: -15px;
  margin-bottom: 15px;
  color: #ddd;
}

textarea {
  resize: none;
  overflow-y: auto;
  height: 120px;
}
</style>

<div class="form-container">
  <h1>Update Request</h1>

  <form action="Frequest_handler.php" method="POST">

    <label>Employee Name</label><br>
    <input type="text" name="employee_name" maxlength="30"required><br>

    <label>Employee ID</label><br>
    <input type="text" name="employee_id" maxlength="10" required pattern="[0-9]*" oninput="this.value = this.value.replace(/[^0-9]/g, '')"><br>

    <label>Reason for Update</label><br>
    <select name="update_reason" required>
      <option value="">-- Select Reason --</option>
      <option value="Address Change">Address Change</option>
      <option value="Promotion">Promotion</option>
      <option value="Termination">Termination</option>
      <option value="Department Transfer">Department Transfer</option>
      <option value="Other">Other</option>
    </select><br>

    <label>Details</label><br>
	<textarea name="details" rows="4" maxlength="500" required oninput="updateCounter(this)"></textarea>
	<div id="charCount">0 / 500</div>

    <button type="submit">Submit Request</button>
  </form>
</div>

<script>
function updateCounter(textarea) {
  const counter = document.getElementById("charCount");
  counter.textContent = textarea.value.length + " / 250";
}
</script>
