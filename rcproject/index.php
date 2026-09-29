<?php
// rcproject home: send visitors to the ProjectDesk app in /app.
// A redirect (not include) keeps the app's relative links, CSS and JS working.
$base = rtrim(str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'])), '/');
header('Location: ' . $base . '/app/index.php', true, 302);
exit;
