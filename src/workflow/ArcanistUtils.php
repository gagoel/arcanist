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
  
function run_command($command, $execution_directory, $verbose) {
  $descriptorspec = array(
    0 => array("pipe", "r"),
    1 => array("pipe", "w"),
    2 => array("pipe", "w")
  );

  $pipes = array();
  
  if($verbose) {
    echo "\n[COMMAND] " . $command . " at " . $execution_directory;
  }
  
  $process = proc_open($command, $descriptorspec, $pipes, $execution_directory,
    NULL);

  //$return_dict = {};
  $return_dict['status'] = true;
  $return_dict['output'] = "";

  if(is_resource($process)) {
    if($verbose) {
      echo "\n";    
    }

    while($str = fgets($pipes[1])) {
      $return_dict['output'] = $return_dict['output'] + $str;
      if($verbose) {
        echo $str;
      }
      flush();
    }       
    while($str = fgets($pipes[2])) {
      $return_dict['output'] = $return_dict['output'] + $str;
      if(preg_match('/\s+ERROR\s+/', $str)) {
        echo "Command warning or error - " . $str;
        $return_dict['status'] = false;
      } else if($verbose) {
        echo $str;    
      }
      flush();
    }       
    proc_close($process);
    return $return_dict;
  }
}
  
function extract_file($file, $extract_path, $arg_verbose) {
  $tar_pattern_dict = array(
    "/^.*\.tar$/i" => array('-xvf', '/\.tar$/i'),
    "/^.*\.tar\.bz2$/i" => array('-xjvf', '/\.tar\.bz2$/i'),
    "/^.*\.tar\.gz$/i" => array('-xzvf', '/\.tar\.gz$/i'),
    "/^.*\.tar\.xz$/i" => array('-xJvf', '/\.tar\.xz$/i')
  );
 
  // If it is git repo no extraction require, returns repo directory only.
  $zip_file_pattern = "/^.*\.git$/i";
  if(preg_match($zip_file_pattern, $file) == 1) {
    $file_without_ext = preg_replace('/\.git$/i', '', $file);
    $extract_cmd = "cp -r " . $file_without_ext . " " . $extract_path;
    run_command($extract_cmd, $extract_path, $arg_verbose);
    return $file_without_ext;
  }

  // Extraction for zip files.
  $zip_file_pattern = "/^.*\.zip$/i";
  if(preg_match($zip_file_pattern, $file) == 1) {
    $file_without_ext = preg_replace('/\.zip$/i', '', $file);
    $extract_cmd = "unzip " . $file;
    run_command($extract_cmd, $extract_path, $arg_verbose);
    return $file_without_ext;
  }

  // Extraction for tar files.
  foreach($tar_pattern_dict as $key => $value) {
    if(preg_match($key, $file) == 1) {
      $extract_cmd = 'tar ' . $value[0] . ' ' . $file;
      run_command($extract_cmd, $extract_path, $arg_verbose);
      return preg_replace($value[1], '', $file); 
    }  
  }
  
  return phutil_console_format(<<<EOTEXT
    Source file extension is not supported.
EOTEXT
    );
}

function echo_string_in_block($msg_string, $block_char) {
  $block_len = strlen($msg_string) + 10;
  
  echo "\n";
  for($i = 0; $i < $block_len; $i++) {
    echo $block_char;    
  }
  echo "\n" . $msg_string . "\n";
  for($i = 0; $i < $block_len; $i++) {
    echo $block_char;    
  }
}

function join_paths() {
  $args = func_get_args();
  $path = $args[0];
  
  for($i = 1; $i < count($args); $i++) {
    $path = rtrim($path, "/") . "/" . ltrim($args[$i], "/");
  }

  return $path;   
}
  
function download_file($filename, $from_location, $to_location, $verbose) {
    
  // git cloning.
  $zip_file_pattern = "/^.*\.git$/i";
  $from = join_paths($from_location, $filename);
  if(preg_match($zip_file_pattern, $filename) == 1) {
    $file_without_ext = preg_replace('/\.git$/i', '', $filename);
    $extract_cmd = "git clone " . $from . " " . $file_without_ext;
    echo "\n[GIT CLONING...] from " . $from_location . " to " . $to_location; 
    run_command($extract_cmd, $to_location, $verbose);
    return true;
  }

  // zip, tar.gz, tar.bz2, tar.xz files download.
  $to = join_paths($to_location, $filename);
  echo "\n[DOWNLOADING...] from - " . $from . " to - " . $to . "\n";
  
  $fp = fopen($to, 'w');
  $ch = curl_init();
  curl_setopt($ch, CURLOPT_URL, $from);
  curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
  curl_setopt($ch, CURLOPT_FILE, $fp);
  curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
  curl_setopt($ch, CURLOPT_FAILONERROR, true);
  curl_setopt($ch, CURLOPT_PROGRESSFUNCTION, 
    'curl_show_download_progress');
  curl_setopt($ch, CURLOPT_NOPROGRESS, false);
  
  $data = curl_exec($ch);

  if($data) {
    curl_close($ch);
    return true;
  }

  echo "\nDownloding failed - " . curl_error($ch);
  curl_close($ch);
  return false;
}
  
function get_package_extraction_path($pkg_filename, $pkg_source_path) 
{
  $tar_pattern_dict = array(
    "/^.*\.tar$/i" => array('-xvf', '/\.tar$/i'),
    "/^.*\.tar\.bz2$/i" => array('-xjvf', '/\.tar\.bz2$/i'),
    "/^.*\.tar\.gz$/i" => array('-xzvf', '/\.tar\.gz$/i'),
    "/^.*\.tar\.xz$/i" => array('-xJvf', '/\.tar\.xz$/i')
  );
 
  // If it is git repo no extraction require, returns repo directory only.
  $git_repo_pattern = "/^.*\.git$/i";
  if(preg_match($git_repo_pattern, $pkg_filename ) == 1) {
    $repo = preg_replace('/\.git$/i', '', $pkg_filename);
    return join_paths($pkg_source_path, $repo);
  }

  // Extraction for zip files.
  $zip_file_pattern = "/^.*\.zip$/i";
  if(preg_match($zip_file_pattern, $pkg_filename) == 1) {
    $file_without_ext = preg_replace('/\.zip$/i', '', $pkg_filename);
    return join_paths($pkg_source_path, $file_without_ext);
  }

  // Extraction for tar files.
  foreach($tar_pattern_dict as $key => $value) {
    if(preg_match($key, $pkg_filename) == 1) {
      $file_without_ext = preg_replace($value[1], '', $pkg_filename);
      return join_paths($pkg_source_path, $file_without_ext);
    }
  }
  
  return phutil_console_format(<<<EOTEXT
    Source file extension is not supported.
EOTEXT
    );
}

function get_package_install_path($pkg_name, $pkg_build_type, 
  $pkg_install_path) 
{
  $pkg_install_dir = "";
  
  if($pkg_build_type == "make" or $pkg_build_type == "cmake") {
    $pkg_install_dir = join_paths($pkg_install_path, $pkg_name);
  } else if($pkg_build_type == "distutils") {
    $cmd = "python -c 'import site; site.getsitepackages()[0]'";
    $cmd_result = run_command($cmd, $pkg_install_path, false);
    $pkg_install_dir = $cmd_result['output'];
  } else {
    return phutil_console_format(<<<EOTEXT
      Package build type is not supported.
EOTEXT
    );
  }
  return $pkg_install_dir;
}

function get_package_build_path($pkg_filename, $pkg_build_type, 
  $pkg_source_path, $pkg_build_path) 
{
  $file_without_ext = "";
  $git_repo_pattern = "/^.*\.git$/i";
  $zip_file_pattern = "/^.*\.zip$/i";
  $tar_pattern = "/^.*\.tar$/i";
  $tar_bz2_pattern = "/^.*\.tar\.bz2$/i";
  $tar_gz_pattern = "/^.*\.tar\.gz$/i";
  $tar_xz_pattern = "/^.*\.tar\.xz$/i";

  if(preg_match($git_repo_pattern, $pkg_filename ) == 1) {
    $file_without_ext = preg_replace('/\.git$/i', '', $pkg_filename);
  } else if(preg_match($zip_file_pattern, $pkg_filename) == 1) {
    $file_without_ext = preg_replace('/\.zip$/i', '', $pkg_filename);
  } else if(preg_match($tar_pattern, $pkg_filename) == 1) {
    $file_without_ext = preg_replace('/\.tar$/i', '', $pkg_filename);
  } else if(preg_match($tar_bz2_pattern, $pkg_filename) == 1) {
    $file_without_ext = preg_replace('/\.tar\.bz2$/i', '', $pkg_filename);
  } else if(preg_match($tar_gz_pattern, $pkg_filename) == 1) {
    $file_without_ext = preg_replace('/\.tar\.gz$/i', '', $pkg_filename);
  } else if(preg_match($tar_xz_pattern, $pkg_filename) == 1) {
    $file_without_ext = preg_replace('/\.tar\.xz$/i', '', $pkg_filename);
  } else {
    return phutil_console_format(<<<EOTEXT
      Source file extension is not supported.
EOTEXT
    );
  }

  $pkg_build_dir = "";
  
  if($pkg_build_type == "make" or $pkg_build_type == "cmake") {
    $pkg_build_dir = join_paths($pkg_build_path, $file_without_ext);
  } else if($pkg_build_type == "distutils") {
    $pkg_build_dir = join_paths($pkg_source_path, $file_without_ext);
  } else {
    return phutil_console_format(<<<EOTEXT
      Package build type is not supported.
EOTEXT
    );
  }
  return $pkg_build_dir;
}
