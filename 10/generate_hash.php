<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Generate Password Hash</title>
</head>
<body>
    <h1>Generate Password Hash</h1>
    <form method="post">
        <label for="password">Enter Password:</label>
        <input type="password" name="password" id="password" required>
        <button type="submit">Generate Hash</button>
    </form>
    <?php
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $password = filter_input(INPUT_POST, 'password', FILTER_SANITIZE_STRING);
        if (!empty($password)) {
            $hash = password_hash($password, PASSWORD_DEFAULT);
            echo '<p><strong>Generated Hash:</strong></p>';
            echo '<textarea rows="4" cols="70">' . htmlspecialchars($hash) . '</textarea>';
        } else {
            echo '<p style="color: red;">Please enter a password.</p>';
        }
    }
    ?>
</body>
</html>
