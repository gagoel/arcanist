<?php

function format_bytes($bytes, $precision = 2) {
  $units = array('B', 'KB', 'MB', 'GB', 'TB');

  $bytes = max($bytes, 0);
  $pow = floor(($bytes ? log($bytes, 2) : 0) / log(1024, 2));
  $pow = min($pow, count($units) - 1);
  $result = $bytes / pow(1024, $pow);
  return round($result, $precision).$units[$pow];
}

function curl_show_download_progress($download_size, $downloaded,
  $upload_size, $uploaded) {
  if ($download_size > 0) {
    $percentage = number_format(($downloaded / $download_size) * 100, 2)."%";
    echo "\r" . format_bytes($downloaded) . " downloaded out of " .
      format_bytes($download_size) . " -- " . $percentage;
  }
  sleep(1);
}
