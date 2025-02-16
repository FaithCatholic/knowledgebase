<?php
$dir = './web/slides';
if (!is_dir($dir)) {
    exec('git clone --depth=1 git@github.com:mikejon-es/slides.git ' . escapeshellarg($dir));
}
?>