<?php
$pdf = '/var/www/html/wine/assets/pdfs/Wine_Cellar_Setup_Checklist.pdf';
if (!is_file($pdf)) { http_response_code(404); exit('File not found'); }
header('Content-Type: application/pdf');
header('Content-Disposition: attachment; filename="Wine_Cellar_Setup_Checklist.pdf"');
readfile($pdf);
