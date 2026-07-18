<?php

$passwordadmin = "admin123";
$hashadmin = password_hash($passwordadmin, PASSWORD_DEFAULT);

$passwordstaff = "staff123";
$hashstaff = password_hash($passwordstaff, PASSWORD_DEFAULT);

echo $hashadmin;
echo "<br>";
echo $hashstaff;
?>