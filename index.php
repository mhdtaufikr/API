<?php
// Preserve the machine's existing /API/?cardno=... URL without a redirect/session.
if (array_intersect(['cardno','d','h','m'], array_keys($_GET))) {
    require __DIR__.'/public/API/index.php';
    exit;
}
header('Location: public/');
exit;
