<?php

/**
 * Setup project working environment.
 *
*/

final class ArcanistSetupProjectWorkflow extends ArcanistBaseWorkflow {
    
  public function getWorkflowName() {
    return 'setupproject';
  }

  public function getCommandSynopses() {
    return phutil_console_format(<<<EOTEXT
      **setupproject**
EOTEXT
    );
  }

  public function getCommandHelp() {
    return phutil_console_format(<<<EOTEXT
          Gets all the required packages, configure, build and install 
          packages in project local directory.
EOTEXT
    );
  }

  public function getArguments() {
    $arguments = array(
      'verbose' => array(
        'short' => 'v',
        'help' => 'Show more detail while building packages.'
      )
    );
    return $arguments;
  }

  public function run() {
    $is_project_view = getenv('ARCANIST_PROJECT_VIEW');
    
    if($is_project_view != 'TRUE') {
      throw new ArcanistUsageException(
        "\nYou are not in project view, you need to run 'arc project -p " .
        "<project-name>' command before using this command."
      );
    }

    $setup_packages = $this->getConfigurationManager()
      ->getConfigFromAnySource('setup.packages');
    
    foreach($setup_packages as $key => $value) {
        echo "Starting '" . $key . "' package setup...\n";
        $this->setup_package($key, $value);
        echo "'" . $key . "' package setup completed successfully.\n";
    }
  }
  
  private function setup_package($pkg_name, $pkg_properties) {
    
    $current_path = getcwd();
    $source_location = $pkg_properties['source_location'];
    $build_location = $pkg_properties['build_location'];
    $install_location = $pkg_properties['install_location'];
    $file_name = $pkg_properties['file_name'];
    $download_urls = $pkg_properties['urls'];
    $build_type = $pkg_properties['build_type'];
    $configure_args = $pkg_properties['configure_args'];
    $install_check = $pkg_properties['install_check'];
    $arg_verbose = $this->getArgument('verbose');

    $abs_src_path = $current_path."/".$source_location.$pkg_name;    
    $abs_build_path = $current_path."/".$build_location.$pkg_name;
    $abs_install_path = $current_path."/".$install_location.$pkg_name;    
    $abs_src_file = $abs_src_path."/".$file_name;

    if (!file_exists($abs_src_path)) {
      mkdir($abs_src_path, 0777, true);    
    }
    if (!file_exists($abs_build_path)) {
      mkdir($abs_build_path, 0777, true);    
    }
    if (!file_exists($abs_install_path)) {
      mkdir($abs_install_path, 0777, true);    
    }
   
    // Checking if package is already install or not.
    $is_package_installed = true;
    
    for($i = 0; $i < count($install_check); $i++) {
      $install_check_file = $install_check[$i];
      $abs_file_path = $abs_install_path . "/" . $install_check_file;
      echo "Checking installation file - " . $abs_file_path . " -- ";
      if(!file_exists($abs_file_path)) {
        echo "not found\n";
        $is_package_installed = false;
        break;
      } else {
        echo "found\n";    
      }
    }

    if($is_package_installed == true) {
      echo "Package '" . $pkg_name . "' is already installed at '" . 
        $abs_install_path . "', Skipping installation.\n";
      return true;
    }
    
    // Get source file if not found.
    echo "Searching for source file: ".$abs_src_file."\n";
    
    if(file_exists($abs_src_file)) {
      echo "Source file found in cache.\n"; 
    } else {
      echo "Source file was not found in cache. Downloading... \n";
      
      for($x = 0; $x < count($download_urls); $x++) {
        $curr_url = $download_urls[$x].$file_name;
        echo "Downloading ".$file_name." from ".$curr_url;
        echo "\n";
        
        $fp = fopen($abs_src_file, 'w');
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $curr_url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
        curl_setopt($ch, CURLOPT_FILE, $fp);
        curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
        curl_setopt($ch, CURLOPT_FAILONERROR, true);
        curl_setopt($ch, CURLOPT_PROGRESSFUNCTION, 
          'setupproject_download_progress');
        curl_setopt($ch, CURLOPT_NOPROGRESS, false);
        $data = curl_exec($ch);
        if($data) {
          echo "\nDownload completed successfully.\n";
          break;    
        }
        curl_close($ch);
      }
    }

    // Extract source file.
    echo "Extracting file ".$abs_src_file . "\n";
    $extracted_dir = $this->extract_file($abs_src_file, $source_location);
    echo "Source file extraction completed ".$extracted_dir."\n";

    // Run Build.
    if($build_type == "make") {

      // Configuring.
      $configure_file = $extracted_dir . '/' . 'configure';
      $prefix_argument = '--prefix=' . $abs_install_path;
      $optional_argument = implode(" ", $configure_args);
      $configure_cmd = $configure_file . ' ' . $prefix_argument . ' ' .
        $optional_argument;
      echo "Running configure command :- \n" . $configure_cmd . "\n";
      $this->run_command($configure_cmd, $abs_build_path, $arg_verbose);

      // Building
      $build_cmd = 'make';
      echo "Running build command :- \n" . $build_cmd . "\n";
      $this->run_command($build_cmd, $abs_build_path, $arg_verbose);

      // Installing
      $install_cmd = 'make install';
      echo "Running install command :- \n" . $install_cmd . "\n";
      $this->run_command($install_cmd, $abs_build_path, $arg_verbose);

    } else {
      return phutil_console_format(<<<EOTEXT
        Build type is not supported.
EOTEXT
      );
    }
  }

  private function run_command($command, $execution_directory, $verbose) {
    $descriptorspec = array(
      0 => array("pipe", "r"),
      1 => array("pipe", "w"),
      2 => array("pipe", "a")
    );

    $pipes = array();
    $process = proc_open($command, $descriptorspec, $pipes, 
      $execution_directory, NULL);

    if(is_resource($process)) {
      while($str = fgets($pipes[1])) {
	if($verbose) {
	  echo $str;
	}
	flush();
      }       
      proc_close($process);
    }
  }

  private function extract_file($file, $extract_path) {
    if(preg_match('/^.*\.tar$/i', $file) == 1) {
      $current_dir = getcwd();
      $extract_cmd = 'tar -xvf '.$file;
      chdir($extract_path);
      exec($extract_cmd);
      chdir($current_dir); 
      return preg_replace('/\.tar$/i', '', $file); 
    } else if(preg_match('/^.*\.tar\.bz2$/i', $file) == 1) {
      $current_dir = getcwd();
      $extract_cmd = 'tar -xjvf '.$file;
      chdir($extract_path);
      exec($extract_cmd);
      chdir($current_dir); 
      return preg_replace('/\.tar\.bz2$/i', '', $file); 
    } else if(preg_match('/^.*\.tar\.gz$/i', $file) == 1) {
      $current_dir = getcwd();
      $extract_cmd = 'tar -xzvf '.$file;
      chdir($extract_path);
      exec($extract_cmd);
      chdir($current_dir); 
      return preg_replace('/\.tar\.gz$/i', '', $file); 
    } else if(preg_match('/^.*\.(xz)$/i', $file) == 1) {
      $current_dir = getcwd();
      $extract_cmd = 'tar -xJvf '.$file;
      chdir($extract_path);
      exec($extract_cmd);
      chdir($current_dir); 
      return preg_replace('/\.tar\.xz$/i', '', $file); 
    } else {     
      return phutil_console_format(<<<EOTEXT
	  Source file extension is not supported.
EOTEXT
      );      
    }
  }
}

function setupproject_download_progress($download_size, $downloaded, 
  $upload_size, $uploaded) {
  if($download_size > 0) {
    $percentage = number_format(($downloaded / $download_size) * 100, 2)."%";
    echo "\r" . setupproject_format_bytes($downloaded) . " downloaded out of " .
      setupproject_format_bytes($download_size) . " -- " . $percentage;
  }
  sleep(1);
}

function setupproject_format_bytes($bytes, $precision = 2) {
  $units = array('B', 'KB', 'MB', 'GB', 'TB');    
  
  $bytes = max($bytes, 0);
  $pow = floor(($bytes ? log($bytes, 2) : 0) / log(1024, 2));
  $pow = min($pow, count($units) - 1);
  $result = $bytes / pow(1024, $pow);
  return round($result, $precision).$units[$pow];
}
