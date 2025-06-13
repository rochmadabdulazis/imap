<pre></pre>
<?php
include './config.php';
include './imap.php';

imapOpen($imapServer . 'INBOX');
$hasil = imapGetBody($_GET['no']);
print_r(autoLink($hasil));
//$hasil = imapGetMail();
//print_r($hasil);
imapClose();