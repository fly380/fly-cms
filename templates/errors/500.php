<?php
?>
<!DOCTYPE html>
<html lang="uk">
<head>
	<meta charset="UTF-8">
	<title>403 — Доступ заборонено</title>
	<meta http-equiv="refresh" content="5;url=index.php">
	<style>
	body {
		font-family: Arial, sans-serif;
		<?= $bgStyle ?>
		display: flex;
		justify-content: center;
		align-items: center;
		height: 100vh;
		margin: 0;
	}
		.message-box {
			background: #fff3cd;
			border: 1px solid #ffeeba;
			color: #856404;
			padding: 30px 40px;
			border-radius: 8px;
			box-shadow: 0 4px 12px rgba(0,0,0,0.1);
			text-align: center;
			font-size: 20px;
		}
	</style>
</head>
<body>
	<div class="message-box">
		Помилка підключення до бази даних.
	</div>
</body>
</html>