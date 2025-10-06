<?php
$pdf = '/var/www/html/wine/assets/pdfs/Wine_Tasting_Journal.pdf';
if (!is_file($pdf)) { http_response_code(404); exit('File not found'); }
header('Content-Type: application/pdf');
header('Content-Disposition: attachment; filename="Wine_Tasting_Journal.pdf"');
readfile($pdf);
