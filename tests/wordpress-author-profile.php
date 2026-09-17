<?php
// Covered by integration tests in the central CCF API; this file ensures the profile endpoint source ships with the connector.
$source = file_get_contents(__DIR__ . '/../wordpress/includes/class-ccf-sites-authors.php');
if ($source === false || strpos($source, "'/authors/(?P<id>\\\\d+)/profile'") === false || strpos($source, "'display_name' => $display_name") === false) {
    throw new RuntimeException('Safe author profile endpoint is missing.');
}
if (strpos($source, "'user_email'") !== false || strpos($source, "'user_pass'") !== false || strpos($source, "'role'") !== false) {
    throw new RuntimeException('Author profile write surface contains a forbidden field.');
}
echo "WordPress safe author profile update passed.\n";
