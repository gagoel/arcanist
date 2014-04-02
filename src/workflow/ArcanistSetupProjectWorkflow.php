<?php

require_once 'ArcanistUtils.php';

/**
 * Setup project working environment.
 *
*/
final class ArcanistSetupProjectWorkflow extends ArcanistBaseWorkflow {
  private $arguments = array();
  private $setup_config = array();
    
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

  // This is the main method and it does following things.
  // 1. Checks that we are in project environment or not.
  // 2. Create project temp files.
  // 3. For each package - check project installed or not, get package, 
  // extract, run configure commands, run build commands, 
  // run pre-install scripts, run install commands,
  // run post-install scripts and copy configuration files.
  public function run() {
    // Checks that project environments are set or not.
    if(getenv('ARCANIST_PROJECT_VIEW') != 'TRUE') {
      throw new ArcanistUsageException(
        "\nYou are not in project view, you need to run 'arc project -p " .
        "<project-name>' command before using this command."
      );
    }
   
    echo_string_in_block("SETTING UP PROJECT...", "#");
   
    // Getting all arguments values.
    $this->arguments['verbose'] = $this->getArgument('verbose');

    // Getting all setup related configuration values.
    $this->setup_config['files_create'] = $this->getConfigurationManager()
      ->getConfigFromAnySource('setup.files.create');
    $this->setup_config['packages'] = $this->getConfigurationManager()
      ->getConfigFromAnySource('setup.packages');
    $this->setup_config['files_copy'] = $this->getConfigurationManager()
      ->getConfigFromAnySource('setup.files.copy');
   
    // Running setup.
    echo "\n";
    $this->setup_files_create();
    $this->download_packages();
    $this->extract_packages();
    $this->install_packages();

    echo "\n\nSetup has successfully completed.\n";
  }

  // Create project temp files such as logs file.
  private function setup_files_create() {
    echo_string_in_block("Creating project temporary files...", "-");
    
    $project_root = getenv("PROJECT_ROOT");
    $files_create = $this->setup_config['files_create'];
    
    for($i = 0; $i < count($this->setup_config['files_create']); $i++) {
      $file = join_paths($project_root, $files_create[$i]);
      
      if(!file_exists($file)) {
        $file_dir = pathinfo($file); 
        if(!is_dir($file_dir['dirname'])) {
          mkdir($file_dir['dirname'], 0777, true);
        }
        
        $file_creation_cmd = "touch " . $file;
        $command_output = run_command($file_creation_cmd, $project_root, 
          $this->arguments['verbose']);

        if(!$command_output['status']) {
          echo "\n[FILE CREATION] File Creation failed.";
          return false;  
        }

        $file_permission_cmd = "chmod 666 " . $file;
        $command_output = run_command($file_permission_cmd, $project_root, 
          $this->arguments['verbose']);
        
        if(!$command_output['status']) {
          echo "\n[FILE CREATION] Changing File Permission to 666 failed.";
          return false;
        }
        echo "\n[CREATED] " . $file;
      } else {
        echo "\n[CACHE] " . $file;
      }
    }
    return true;
  }

  private function get_package_data($pkg_name) {
    $project_root = getenv("PROJECT_ROOT");
    $pkg_data = $this->setup_config['packages'][$pkg_name];

    // Getting package details.
    $pkg_info = array(
      'name' => $pkg_name,
      'source_repo' => join_paths($project_root, "externals/src_repo"),
      'source_path' => join_paths($project_root, "externals/src"),
      'build_path' => join_paths($project_root, "externals/build"),
      'install_path' => join_paths($project_root, "externals/install"),
      'pkg_filename' => $pkg_data['file_name'],
      'download_urls' => $pkg_data['urls'],
      'build_type' => $pkg_data['build_type'],
      'configure_args' => "",
      'install_check' => "",
      'install_check_cmd' => array(),
      'config_files' => array(),
      'pkg_extraction_path' => "",
      'pkg_build_path' => "",
      'pkg_install_path' => ""
    );

    if(array_key_exists('configure_args', $pkg_data)) {
      $pkg_info['configure_args'] = $pkg_data['configure_args'];
    }
    if(array_key_exists('install_check', $pkg_data)) {
      $pkg_info['install_check'] = $pkg_data['install_check'];
    }
    if(array_key_exists('install_check_cmd', $pkg_data)) {
      $pkg_info['install_check_cmd'] = $pkg_data['install_check_cmd'];
    }
    if(array_key_exists('config_files', $pkg_data)) {
      $pkg_info['config_files'] = $pkg_data['config_files'];
    }

    $pkg_info['pkg_extraction_path'] = get_package_extraction_path(
      $pkg_info['pkg_filename'], $pkg_info['source_path']);
    
    $pkg_info['pkg_build_path'] = get_package_build_path(
      $pkg_info['pkg_filename'], $pkg_info['build_type'], 
      $pkg_info['source_path'], $pkg_info['build_path']);
    
    $pkg_info['pkg_install_path'] = get_package_install_path(
      $pkg_info['name'], $pkg_info['build_type'], $pkg_info['install_path']);

    // Creating source, build and install directories if does not exist.
    if (!file_exists($pkg_info['source_repo'])) {
      mkdir($pkg_info['source_repo'], 0777, true);    
    }
    if (!file_exists($pkg_info['source_path'])) {
      mkdir($pkg_info['source_path'], 0777, true);    
    }
    if (!file_exists($pkg_info['build_path'])) {
      mkdir($pkg_info['build_path'], 0777, true);    
    }
    if (!file_exists($pkg_info['install_path'])) {
      mkdir($pkg_info['install_path'], 0777, true);    
    }
    
    // Replacing environment variable in configure arguments.
    $pkg_info['configure_args'] = $this->replace_environment_variables(
      $pkg_info['configure_args'], $pkg_info);
    
    return $pkg_info;
  }

  private function download_packages() {
    echo_string_in_block("\nDOWNLOADING PACKAGES...", '-');
    
    $packages = $this->setup_config["packages"];

    foreach ($packages as $key => $value) {
      $pkg_data = $this->get_package_data($key);
      $pkg_source_file = join_paths($pkg_data['source_repo'],
        $pkg_data['pkg_filename']);
      echo "\n[FILE] " . $pkg_source_file;
      
      if(!$this->is_package_exists($pkg_data['name'])) 
      {
        echo " [NOT FOUND]";
        $status = false;

        for($x = 0; $x < count($pkg_data['download_urls']); $x++) {
          $filename = $pkg_data['pkg_filename'];
          $url = $pkg_data['download_urls'][$x];
          $status = download_file($filename, $url, $pkg_data['source_repo'], 
            $this->arguments['verbose']);
          if($status) {
            break;
          }
        }
        if(!$status) {
          throw new ArcanistUsageException(
            "\nError in downloading package " . $pkg_data['pkg_filename']
          );
        }
      }
      else 
      {
        echo " [FOUND]";
      }
    } 
  }

  private function is_package_exists($pkg_name) {
    $git_file_pattern = "/^.*\.git$/i";
    $pkg_data = $this->get_package_data($pkg_name);
    $source_repo = $pkg_data['source_repo'];
    $filename = $pkg_data['pkg_filename'];
    
    $file_path = join_paths($source_repo, $filename);
  
    if(preg_match($git_file_pattern, $filename) == 1) {
      $file_without_ext = preg_replace('/\.git$/i', '', $filename);
      $package_git_file = join_paths($source_repo, $file_without_ext, ".git"); 
      if(file_exists($package_git_file)) {
        return true;
      } else {
        return false;  
      }
    }

    $file_path = join_paths($source_repo, $filename);
    if(file_exists($file_path)) {
      return true;  
    } else {
      return false;  
    }
  }

  private function extract_packages() {
    echo_string_in_block("\nEXTRACTING PACKAGES...", "-");
    
    foreach ($this->setup_config['packages'] as $pkg_name => $pkg_config) {
      $pkg_data = $this->get_package_data($pkg_name);
      
      // If package already extracted.
      $pkg_extraction_path = $pkg_data['pkg_extraction_path'];
      
      echo "\n[CACHE EXTRACTED PACKAGE] " . $pkg_extraction_path;
      
      if(file_exists($pkg_extraction_path)) {
        echo " [FOUND]";
        continue;
      } else {
        echo " [NOT FOUND]";    
      }
      
      $pkg_file_path = join_paths($pkg_data['source_repo'],
        $pkg_data['pkg_filename']);
      
      echo "\n[UNZIP] " . $pkg_file_path;
      
      extract_file($pkg_file_path, $pkg_data['source_path'],
        $this->arguments['verbose']);
      
      echo " [UNZIP COMPLETED]";
    }
  }

  private function is_package_installed($pkg_name) {
    $pkg_data = $this->get_package_data($pkg_name);
    $project_root = getenv("PROJECT_ROOT");

    // Checking if package is already install or not.
    $is_package_installed = true;
    
    for($i = 0; $i < count($pkg_data['install_check']); $i++) {
      $abs_file_path = join_paths($pkg_data['install_path'],
        $pkg_data['install_check'][$i]);
      
      echo "\n[VERIFYING] Checking installed file " . $abs_file_path . " ";

      if(!file_exists($abs_file_path)) {
        echo "[NOT FOUND]";
        $is_package_installed = false;
        break;
      } else {
        echo "[FOUND]";    
      }
    }

    foreach($pkg_data['install_check_cmd'] as $command => $expected_output) {
      echo "\n[VERIFYING] Checking installed command " . $command . " ";
      $command_output = run_command($command, $project_root, 
        $this->arguments['verbose']); 
      //echo "\ncommand output is - " . $command_output['output'] . "\n"; 
      if($command_output['output'] != $expected_output) {
        $is_package_installed = false;
        echo "[FAILED]";
        break;    
      } else {
        echo "[PASSED]";
      } 
    }
    return $is_package_installed;
  }

  private function install_packages() { 
    echo_string_in_block("\nINSTALLING PACKAGES...", "-");
     
    foreach ($this->setup_config['packages'] as $pkg_name => $pkg_config) {
      echo "\n\n[" . $pkg_name . " package installing...]";
      
      $pkg_data = $this->get_package_data($pkg_name);
      $pkg_build_type = $pkg_data['build_type'];

      if($this->is_package_installed($pkg_name)) {
        echo "\n[CACHE] '" . $pkg_name . "' is already installed.";
        continue;
      }

      // Running pre build scripts.
      $this->run_pre_install_scripts($pkg_name);

      // Running build.
      if($pkg_build_type == "make") {
        if(!$this->run_make_build($pkg_name)) {
          throw new ArcanistUsageException("Build Failed.\n");
        }
      } else if($pkg_build_type == "imake") {
        if(!$this->run_imake_build($pkg_name)) {
          throw new ArcanistUsageException("Build Failed.\n");
        }
      } else if($pkg_build_type == "cmake") {
        if(!$this->run_cmake_build($pkg_name)) {
          throw new ArcanistUsageException("Build Failed.\n");
        }
      } else if($pkg_build_type == "distutils") {
        if(!$this->run_distutils_build($pkg_name)) {
          throw new ArcanistUsageException("Build Failed.\n");
        }
      } else {
        throw new ArcanistUsageException(
          "\nBuild type " . $pkg_build_type . " is not supported"
        );
      }

      $this->setup_config_files($pkg_name);
      $this->run_post_install_scripts($pkg_name);
      
      echo "\n'" . $pkg_name . "' package installed successfully.";
    }
    return true;
  }

  // Replace environment variables for input string or array, and returns
  // updated string or array.
  private function replace_environment_variables($replacing_data, $pkg_data) {
    $new_data = $replacing_data;
    $pkg_name = $pkg_data['name'];

    $new_data = preg_replace('/\$PACKAGE_INSTALL_DIR/',
      $pkg_data['pkg_install_path'], $new_data);
    $new_data = preg_replace('/\$PACKAGE_SOURCE_DIR/',
      $pkg_data['pkg_extraction_path'], $new_data);
    $new_data = preg_replace('/\$PACKAGE_BUILD_DIR/',
      $pkg_data['pkg_build_path'], $new_data);
    
    // Replacing environment variables.
    $environment_vars = $_ENV;
     
    foreach($environment_vars as $key => $value) {
      $pattern = "/\\\$" . $key . "/";
      $new_data = preg_replace($pattern, $value, $new_data);   
    }

    return $new_data;
  }

  private function run_pre_install_scripts($pkg_name) {
    $project_root = getenv("PROJECT_ROOT");
    $pre_install_script = join_paths($project_root, "scripts/setup/packages/",
      $pkg_name, "/pre-install.sh");
    $pkg_data = $this->get_package_data($pkg_name);
    $exec_dir = $pkg_data['pkg_extraction_path'];
    return $this->run_script($pre_install_script, $exec_dir, $pkg_name);      
  }

  private function run_post_install_scripts($pkg_name) {
    $project_root = getenv("PROJECT_ROOT");
    $pre_install_script = join_paths($project_root, "scripts/setup/packages/",
      $pkg_name, "/post-install.sh");
    $pkg_data = $this->get_package_data($pkg_name);
    $exec_dir = $pkg_data['install_path'];
    return $this->run_script($pre_install_script, $exec_dir, $pkg_name);      
  }

  private function run_script($script_file, $exec_dir, $pkg_name) {
    echo "\n[SCRIPT] " . $script_file;
    
    if(!file_exists($script_file)) {
      echo " [NOT FOUND]";
      return true;  
    }

    $pkg_data = $this->get_package_data($pkg_name); 
    // Getting file in a string and replacing environment variables.
    $script_string = file_get_contents($script_file);
    $script_string = $this->replace_environment_variables($script_string, 
      $pkg_data);

    $script_temp_file = $script_file . "-temp.sh";
    $fd = fopen($script_temp_file, "w");
    fwrite($fd, $script_string);
    fclose($fd);

    //echo "\nExecuting script file - " . $script_string;
    $bash_cmd = "bash " . $script_temp_file;
    $cmd_output = run_command($bash_cmd, $exec_dir, 
      $this->arguments['verbose']);
    
    unlink($script_temp_file);

    if(!$cmd_output['status']) {
      echo " [EXECUTION FAILED]";
      return false;
    }

    echo " [EXECUTION PASSED]";
    return true;
  }

  private function setup_config_files($pkg_name) {
    $project_root = getenv("PROJECT_ROOT");
    $pkg_data = $this->get_package_data($pkg_name);
   
    echo "\n[CONFIGURATION]";

    foreach($pkg_data['config_files'] as $source_file => $dest_file) {
      $source_file_path = join_paths($project_root, $source_file);
      $dest_file_path = join_paths($project_root, $dest_file);
        
      echo "\n[COPY] " . $source_file_path . " to " . $dest_file_path;
        
      if(file_exists($source_file_path)) {
    
        // Creating destination directory if does not exist.
        $file_dir = pathinfo($dest_file_path); 
        if(!is_dir($file_dir['dirname'])) { 
          mkdir($file_dir['dirname'], 0777, true);
        }
        
        // Getting source file data and replacing environment variables.
        $source_file_str = file_get_contents($source_file_path);
        $source_file_str = $this->replace_environment_variables(
          $source_file_str, $pkg_data);
        
        file_put_contents($dest_file_path, $source_file_str);
        echo " [SUCCESS]";    
      
      } else {
        echo " [FAILED]";
      }
    }
  }
  
  private function run_make_build($pkg_name) {
    $pkg_data = $this->get_package_data($pkg_name);
    $pkg_build_dir = join_paths($pkg_data['build_path'], $pkg_name);
    $pkg_install_dir = join_paths($pkg_data['install_path'], $pkg_name);

    if(!file_exists($pkg_build_dir)) {
      mkdir($pkg_build_dir, 0777, true);  
    }
    if(!file_exists($pkg_install_dir)) {
      mkdir($pkg_install_dir, 0777, true);  
    }

    // Configuring package.
    $optional_argument = implode(" ", $pkg_data['configure_args']);
    $pkg_src_path = $pkg_data['pkg_extraction_path'];
    $configure_file = join_paths($pkg_src_path, 'configure');
    $prefix_argument = '--prefix=' . $pkg_install_dir;
    
    $configure_cmd = $configure_file . ' ' . $prefix_argument . ' ' .
      $optional_argument;
    
    echo "\n[MAKE] Configuring package...";
    
    $command_output = run_command($configure_cmd, $pkg_build_dir, 
      $this->arguments['verbose']);
    
    if(!$command_output['status']) {
      echo "\n[MAKE] Configuration was failed.";
      return false;  
    } else {
      echo "\n[MAKE] Configuration successed.";
    }

    // Building package
    $build_cmd = 'make';
    
    echo "\n[MAKE] Building package...";
    
    $command_output = run_command($build_cmd, $pkg_build_dir, 
      $this->arguments['verbose']);
    
    if(!$command_output['status']) {
      echo "\n[MAKE] Build was failed.";
      return false;  
    } else {
      echo "\n[MAKE] Build successed.";    
    }

    // Installing package
    $install_cmd = 'make install';
    
    echo "\n[MAKE] installing package...";
    
    $command_output = run_command($install_cmd, $pkg_build_dir, 
      $this->arguments['verbose']);
    
    if(!$command_output['status']) {
      echo "\n[MAKE] Installation was failed.";
      return false;  
    } else {
      echo "\n[MAKE] Installation passed.";    
    }

    // Testing installation.
    echo "\n[VERIFYING] Testing package installation...";
    if($this->is_package_installed($pkg_name)) {
      echo " [PASSED]";
      return true;  
    } else {
      echo " [FAILED]";
      return false;
    }
  }
  
  private function run_imake_build($pkg_name) {
    $pkg_data = $this->get_package_data($pkg_name);
    $pkg_build_dir = join_paths($pkg_data['build_path'], $pkg_name);
    $pkg_install_dir = join_paths($pkg_data['install_path'], $pkg_name);

    if(!file_exists($pkg_build_dir)) {
      mkdir($pkg_build_dir, 0777, true);  
    }
    if(!file_exists($pkg_install_dir)) {
      mkdir($pkg_install_dir, 0777, true);  
    }

    // Configuring package.
    $optional_argument = implode(" ", $pkg_data['configure_args']);
    $pkg_src_path = $pkg_data['pkg_extraction_path'];
    $configure_file = join_paths($pkg_src_path, 'configure');
    $prefix_argument = '--prefix=' . $pkg_install_dir;
    
    $configure_cmd = $configure_file . ' ' . $prefix_argument . ' ' .
      $optional_argument;
    
    echo "\n[MAKE] Configuring package...";
    
    $command_output = run_command($configure_cmd, $pkg_src_path, 
      $this->arguments['verbose']);
    
    if(!$command_output['status']) {
      echo "\n[MAKE] Configuration was failed.";
      return false;  
    } else {
      echo "\n[MAKE] Configuration successed.";
    }

    // Building package
    $build_cmd = 'make';
    
    echo "\n[MAKE] Building package...";
    
    $command_output = run_command($build_cmd, $pkg_src_path, 
      $this->arguments['verbose']);
    
    if(!$command_output['status']) {
      echo "\n[MAKE] Build was failed.";
      return false;  
    } else {
      echo "\n[MAKE] Build successed.";    
    }

    // Installing package
    $install_cmd = 'make install';
    
    echo "\n[MAKE] installing package...";
    
    $command_output = run_command($install_cmd, $pkg_src_path, 
      $this->arguments['verbose']);
    
    if(!$command_output['status']) {
      echo "\n[MAKE] Installation was failed.";
      return false;  
    } else {
      echo "\n[MAKE] Installation passed.";    
    }

    // Testing installation.
    echo "\n[VERIFYING] Testing package installation...";
    if($this->is_package_installed($pkg_name)) {
      echo " [PASSED]";
      return true;  
    } else {
      echo " [FAILED]";
      return false;
    }
  }

  private function run_cmake_build($pkg_name) {
    $pkg_data = $this->get_package_data($pkg_name);
    $pkg_build_dir = join_paths($pkg_data['build_path'], $pkg_name);
    $pkg_install_dir = join_paths($pkg_data['install_path'], $pkg_name);

    if(!file_exists($pkg_build_dir)) {
      mkdir($pkg_build_dir, 0777, true);  
    }
    if(!file_exists($pkg_install_dir)) {
      mkdir($pkg_install_dir, 0777, true);  
    }

    // Configuring package.
    $prefix_argument = '-DCMAKE_INSTALL_PREFIX=' . $pkg_install_dir;
    $optional_argument = implode(" ", $pkg_data['configure_args']);
    $pkg_src_path = $pkg_data['pkg_extraction_path'];
    
    $configure_cmd = 'cmake ' . $pkg_src_path . ' ' . $prefix_argument . ' ' .
      $optional_argument;
    
    echo "\n[CMAKE] Configuring package...";
    
    $command_output = run_command($configure_cmd, $pkg_build_dir, 
      $this->arguments['verbose']);
    
    if(!$command_output['status']) {
      echo "\n[CMAKE] Configuration was failed.";
      return false;  
    } else {
      echo "\n[CMAKE] Configuration successed.";
    }

    // Building package
    $build_cmd = 'make';
    
    echo "\n[CMAKE] Building package...";
    
    $command_output = run_command($build_cmd, $pkg_build_dir, 
      $this->arguments['verbose']);
    
    if(!$command_output['status']) {
      echo "\n[CMAKE] Build was failed.";
      return false;  
    } else {
      echo "\n[CMAKE] Build successed.";    
    }

    // Installing package
    $install_cmd = 'make install';
    
    echo "\n[CMAKE] Installing package...";
    
    $command_output = run_command($install_cmd, $pkg_build_dir, 
      $this->arguments['verbose']);
    
    if(!$command_output['status']) {
      echo "\n[CMAKE] Installation was failed.";
      return false;  
    } else {
      echo "\n[CMAKE] Installation successed.";    
    }

    // Testing installation.
    echo "\n[VERIFYING] Testing package installation...";
    if($this->is_package_installed($pkg_name)) {
      echo " [PASSED]";
      return true;  
    } else {
      echo " [FAILED]";
      return false;
    }
  }

  private function run_distutils_build($pkg_name) {
    $pkg_data = $this->get_package_data($pkg_name);
    $pkg_src_dir = $pkg_data['pkg_extraction_path'];

    // Installing
    $install_cmd = 'python setup.py install';
    
    echo "\n[DISTUTILS] Installting package...";
    
    $command_output = run_command($install_cmd, $pkg_src_dir, 
      $this->arguments['verbose']);
    
    if(!$command_output['status']) {
      echo "\n[DISTUTILS] Installation was failed.";
      return false;  
    } else {
      echo "\n[DISTUTILS] Installation successed.";    
    }
    
    // Testing installation.
    echo "\n[VERIFYING] Testing package installation...";
    if($this->is_package_installed($pkg_name)) {
      echo " [PASSED]";
      return true;  
    } else {
      echo " [FAILED]";
      return false;
    }
  }
}
