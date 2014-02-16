<?php

/**
 * Change current directory to project working directory and setup project
 * environment. ARCANIST_LOCAL_REPO is an envrionment variable stores project 
 * root directory path. If ARCANIST_LOCAL_REPO environment variable is not set 
 * or it does not contain project directory, raise a error message.
*/

final class ArcanistUseProjectWorkflow extends ArcanistBaseWorkflow {
    
  public function getWorkflowName() {
    return 'useproject';
  }

  public function getCommandSynopses() {
    return phutil_console_format(<<<EOTEXT
      **useproject**
EOTEXT
    );
  }

  public function getCommandHelp() {
    return phutil_console_format(<<<EOTEXT
          This command uses ARCANIST_LOCAL_REPO environment variable to locate 
          project root directory please make sure it is set before using this 
          command.

          Change current directory to project working directory, and setup
          project environment variables.
          If ARCANIST_LOCAL_REPO environment variable is not set or it does not 
          contain input project, this command will not have any effect.
EOTEXT
    );
  }

  public function getArguments() {
    $arguments = array(
      'project' => array(
        'short' => 'p',
        'param' => 'project_name',
        'help' => 'Project name which needs to be set.',
      ),
    );
    return $arguments;
  }

  public function run() {
    $is_project_view = getenv('ARCANIST_PROJECT_VIEW');
    $project_local_root_dir = getenv('ARCANIST_LOCAL_REPO');
    
    $project_name = $this->getArgument('project');
    
    $project_dir = $project_local_root_dir . '/' . $project_name;
    $project_env_file = $project_dir . '/' . ".setupenv.sh";
    
    if($is_project_view == 'TRUE') {
      throw new ArcanistUsageException(
        "\nYou are already in project view, you need to exit from this view " .
        "before entering in new project view or same project view."
      );
    }

    if(!$project_local_root_dir) {
      throw new ArcanistUsageException(
        "\nARCANIST_LOCAL_REPO environment variable is not set." .
        "\nARCANIST_LOCAL_REPO environment variable contains the root path ".
        "of project directory."
      );
    }

    if(!$project_name or $project_name == '') {
      throw new ArcanistUsageException(
        "\npass the project name as example " .
        "'arc useproject -p sample-project-name'"
      );
    }

    // Checks project directory exists or not.
    if(!file_exists($project_dir)) {
      throw new ArcanistUsageException(
        "\nproject directory does not exist. Make sure you have cloned this " .
        "project in your local repo."
      );
    }

    if(!file_exists($project_env_file)) {
      throw new ArcanistUsageException(
        "\nThis is not arcanist configured project. " .
        "\nArcanist configured project must have .setupenv.sh file in " .
        "project root directory."
      );
    }
    
    echo phutil_console_format(
    "PROJECT NAME: %s\n".
    "PROJECT DIRECTORY: %s\n".
    "Setting up project environment...\n",
    $project_name, $project_dir);
    
    chdir($project_dir);
    $args = array("--rcfile", $project_env_file);
    pcntl_exec("/bin/bash", $args);
  }
}
