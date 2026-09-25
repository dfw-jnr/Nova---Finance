<?php
function icon($size, $path) {
  if (!function_exists('imagecreatetruecolor')) { echo "no-gd\n"; return false; }
  $im = imagecreatetruecolor($size, $size);
  $bg = imagecolorallocate($im, 11, 13, 16);
  $accent = imagecolorallocate($im, 110, 168, 254);
  $white = imagecolorallocate($im, 232, 234, 237);
  imagefilledrectangle($im, 0, 0, $size, $size, $bg);
  $m = (int)($size * 0.18);
  imagefilledrectangle($im, $m, $m, (int)($m + $size * 0.08), $size - $m, $accent);
  $font = 5;
  imagestring($im, $font, (int)($size * 0.35), (int)($size * 0.42), 'NOVA', $white);
  imagepng($im, $path);
  imagedestroy($im);
  return true;
}
$dir = 'C:/Users/kings/nova-finance/public/assets/icons';
icon(192, $dir . '/icon-192.png');
icon(512, $dir . '/icon-512.png');
echo "icons-ok\n";
