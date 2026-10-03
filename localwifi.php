<?php
/*
  Legacy browser-direct bookmark. Both transports now use index.php and its
  V8 Theme Studio/updater. The suggested mode is saved only after confirmation
  in Connection settings; visiting this URL does not change preferences.
*/
declare(strict_types=1);
header('Cache-Control: no-store, private');
header('Location: index.php?connection=direct', true, 302);
exit;
