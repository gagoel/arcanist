<?php

/**
 * Change current directory to project working directory and clone project if
 * project was not already installed. 
 *
 * ARCANIST_LOCAL_REPO is an envrionment variable stores project root 
 * directory path.
 * ARCANIST_REMOTE_REPO is an environment variable stores project root 
 * directory path at remote location.
 *
 * If ARCANIST_LOCAL_REPO environment variable is not set or 
 * ARCANIST_REMOTE_REPO environment variable is not set, raises an error 
 * message.
*/

final class ArcanistCloneProjectWorkflow extends ArcanistBaseWorkflow {
    
  public function getWorkflowName() {
    return 'cloneproject';
  }

  public function getCommandSynopses() {
    return phutil_console_format(<<<EOTEXT
      **cloneproject**
EOTEXT
    );
  }

  public function getCommandHelp() {
    return phutil_console_format(<<<EOTEXT
          This command uses ARCANIST_LOCAL_REPO environment variable to locate 
          project root directory and ARCANIST_REMOTE_REPO environment variable 
          to locate project remote root directory please make sure these 
          variables are set before using this command.

          Change current directory to project working directory, and clone
          project if it is not already installed.

          If ARCANIST_LOCAL_REPO or ARCANIST_REMOTE_REPO environment variables 
          are not set, raises an error message.
EOTEXT
    );
  }

  public function getArguments() {
    $arguments = array(
      'project' => array(
        'short' => 'p',
        'param' => 'project name',
        'help' => 'Project name which needs to be clone.',
      ),
      'verbose' => array(
        'short' => 'v',
        'help' => 'Shows all commands execution output.',
      ),
    );
    return $arguments;
  }

  public function run() {
    $is_project_view = getenv('ARCANIST_PROJECT_VIEW');
    $project_local_root_dir = getenv('ARCANIST_LOCAL_REPO');
    $project_remote_root_dir = getenv('ARCANIST_REMOTE_REPO');
    
    $arg_project = $this->getArgument('project');
    $arg_verbose = $this->getArgument('verbose');
    
    $project_dir = $project_local_root_dir . '/' . $arg_project;
    $project_remote_dir = $project_remote_root_dir . '/' . $arg_project;
    
    if($is_project_view == 'TRUE') {
      throw new ArcanistUsageException(
        "\nYou are already in project view, you need to exit from this view " .
        "to run cloneproject command."
      );
    }

    if(!$project_local_root_dir or !$project_remote_root_dir) {
      throw new ArcanistUsageException(
        "\nARCANIST_LOCAL_REPO or ARCANIST_REMOTE_REPO environment variable ".
        "is not set." .
        "\nARCANIST_LOCAL_REPO environment variable contains the root path ".
        "of project directory." .
        "\nARCANIST_REMOTE_REPO environment variable conatins the root path ".
        "of project at remote location."
      );
    }

    if(!$arg_project or $arg_project == '') {
      throw new ArcanistUsageException(
        "\npass the project name as example " .
        "'arc cloneproject -p sample-project-name'"
      );
    }

    // Checks project directory exists or not.
    if(file_exists($project_dir)) {
      throw new ArcanistUsageException(
        "\nproject already exists at '" . $project_dir . "', ignoring cloning"
      );
    }

    echo "Cloning project '" . $arg_project . "' to directory " .
      $project_local_root_dir . "\n";

    $cmd = "git clone " . $project_remote_dir . ".git " . $arg_project;
    echo "Running command " . $cmd . "\n";
    
    $status = $this->run_command($cmd, $project_local_root_dir, $arg_verbose);
    if($status) {
      echo "'" . $arg_project . "' project cloned successfully.\n";
    } else {
      echo "'" . $arg_project . "' project cloning failed.\n";
    }

  }

  private function run_command($command, $execution_directory, $verbose) {
    $descriptorspec = array(
      0 => array("pipe", "r"),
      1 => array("pipe", "w"),
      2 => array("pipe", "w")
    );

    $pipes = array();
    $process = proc_open($command, $descriptorspec, $pipes, 
      $execution_directory, NULL);

    $return_status = true;
    if(is_resource($process)) {
      while($str = fgets($pipes[1])) {
        if($verbose) {
          echo $str;
        }
        flush();
      }       
      while($str = fgets($pipes[2])) {
        if($verbose) {
          echo $str;
        }
        flush();
        $return_status = false;
      }       
      proc_close($process);
      return $return_status;
    }
  }
}
