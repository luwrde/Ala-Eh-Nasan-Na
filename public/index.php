<?php
declare(strict_types=1);
require_once __DIR__ . '/../app/bootstrap.php';

$driver = current_driver();
if ($driver !== null) {
    redirect('dashboard.php');
}

page_start('Driver portal');
?>
<h1>Driver Portal</h1>
<p>Register to start driver onboarding, or log in to check your application status.</p>
<p><a href="register.php">Create a driver account</a> · <a href="login.php">Log in</a></p>
<?php page_end(); ?>
