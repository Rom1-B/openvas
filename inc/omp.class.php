<?php
/*
 * @version $Id$
 LICENSE

  This file is part of the openvas plugin.

 Order plugin is free software; you can redistribute it and/or modify
 it under the terms of the GNU General Public License as published by
 the Free Software Foundation; either version 2 of the License, or
 (at your option) any later version.

 openvas plugin is distributed in the hope that it will be useful,
 but WITHOUT ANY WARRANTY; without even the implied warranty of
 MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 GNU General Public License for more details.

 You should have received a copy of the GNU General Public License
 along with GLPI; along with openvas. If not, see <http://www.gnu.org/licenses/>.
 --------------------------------------------------------------------------
 @package   openvas
 @author    Teclib'
 @copyright Copyright (c) 2016 Teclib'
 @license   GPLv2+
            http://www.gnu.org/licenses/gpl.txt
 @link      https://github.com/pluginsglpi/openvas
 @link      http://www.glpi-project.org/
 @link      http://www.teclib-edition.com/
 @since     2016
 ---------------------------------------------------------------------- */

if (!defined('GLPI_ROOT')){
   die("Sorry. You can't access directly to this file");
}

/**
* Class to connect to OpenVAS and send commands
* Inspired by the work made php-omp (https://github.com/dmth/php-omp)
*
*/
class PluginOpenvasOmp {

   //Actions to be processed through the API

   //Read actions
   const TARGET   = 'get_targets';
   const RESULT   = 'get_results';
   const REPORT   = 'get_reports';
   const TASK     = 'get_tasks';
   const CONFIG   = 'get_configs';
   const SCANNER  = 'get_scanners';
   const SCHEDULE = 'get_schedules';

   //Execute actions
   const START_TASK  = 'start_task';
   const CANCEL_TASK = 'stop_task';
   const ADD_TASK    = 'create_task';

   const SORT_ASC   = 'sort'; //Ascending sort
   const SORT_DESC  = 'sort-reverse'; //Descending sort
   const NO_FILTER  = 'ignore_filter'; //Do not add filter to XML command

   const DETAIL = 1; //Get details (for results)
   const NO_DETAIL    = 0; //Do not ask for details

   /**
   * Get the X first or last item
   * @since 1.0
   * @param $options options as an array
   * @return a string representing the filter to be applied during query
   */
   private static function getFilter($options = array ()) {

      $filter = '';
      $params = [];
      $extra  = '';

      foreach ($options as $key => $value) {
         if ($key == 'filter') {
            continue;
         }
         $filter.= $key."='$value' ";
      }

      //Override filter params if needed
      if (isset($options['filter'])) {
         foreach ($options['filter'] as $key => $value) {
            if ($key != 'extra') {
               $params['filter'][$key] = $value;
            }
         }
      }

      if (!empty($params['filter'])) {
        $extra.= " AND ";
      }
      if (isset($options['filter']['extra'])) {
         $extra .= $options['filter']['extra'];
      }

      if (!empty($params['filter']) || $extra != '') {
        $filter .= " filter='";
        if (!empty($params['filter'])) {
           $filter.= http_build_query($params['filter'], '', ' AND ');
        }
        if ($extra != '') {
          $filter.= ' '.$extra;
        }
        $filter.= "'";
    }

      return $filter;
   }


   /**
   * @since 1.0
   *
   * Build the XML command
   * @param $action the action to perform
   * @param $options optional params for this action
   * @return the XML command as a string
   */
   static function getXMLForAction($action, $options) {
      return "<$action ".self::getFilter($options)." />";
   }

   /**
   * @since 1.0
   *
   * Get one or all targets
   * @param target_id the target uuid in OpenVAS
   * @param tasks true si all tasks linked to the target must be collected
   * @return an array of targets, or false if an error occured
   */
   static function getTargets($target_id = false, $tasks = false, $extra_params = '') {
     $options = [ 'filter' => ['extra' => $extra_params ] ];
      if ($target_id) {
        $options['target_id'] = $target_id;
      }
      if ($tasks) {
        $options['tasks'] = 1;
      }
      return self::executeCommand(self::TARGET, $options);
   }

   /**
   * Get one or all results
   * @since 1.0
   *
   * @param $extra_params extra params to add to the filter
   * @return an array of results, or false if an error occured
   */
   static function getResults($extra_params = false) {
      return self::executeCommand(self::RESULT, [ 'filter'  => [ 'rows' => -1, 'extra' => $extra_params] ]);
   }

   /**
   * Get reports
   * @since 1.0
   *
   * @param $params params to add
   * @return an array of reports, or false if an error occured
   */
   static function getReports($params = []) {
      return self::executeCommand(self::REPORT, $params);
   }


   /**
   * Check if the command's status indicates a success
   * @since 1.0
   *
   * @param the command return code
   * @return true if success, false otherwise
   */
   static function isCodeOK($status) {
      //A code 200 means success
      return $status == '200';
   }

   /**
   * @since 1.0
   *
   * Get one or all tasks
   * @param task_id the uuid of a task, or false to get all tasks
   * @return an array of tasks, or false if an error occured
   */
   static function getTasks($task_id = false) {
      if ($task_id) {
        $options = [ 'filter' => [ 'task_id' => $task_id ,
                                   self::SORT_ASC => 'name'] ];
      } else {
        $options = [];
      }
      return self::executeCommand(self::TASK, $options);
   }

   static function getLastReportForAHost($host) {
      $options = [ 'type' => 'assets', 'host' => $host, 'pos' => 1,
                   'levels' => 'hml', 'first_result' => 1,
                   'max_results' => 100 ];
      return self::executeCommand(self::REPORT, $options);
   }

   /**
   * Request starting a task
   * @since 1.0
   *
   * @param task_id the task to start
   * @return true if task scan request is sucessful
   */
   static function startTask($task_id = 0) {
      return self::executeCommand(self::START_TASK,
                                  [ 'task_id' => $task_id,
                                    self::NO_FILTER => 1 ]);
   }

   /**
   * Request stopping a task
   * @since 1.0
   *
   * @param task_id the task to start
   * @return true if task scan request is sucessful
   */
   static function stopTask($task_id = 0) {
      return self::executeCommand(self::CANCEL_TASK,
                                  [ 'task_id' => $task_id,
                                    self::NO_FILTER => 1 ]);
   }

   /**
   * @since 1.0
   *
   * Execute a command, and get it's result
   * @param $action the action to be performed
   * @param $options options passed to the command
   * @param send raw command or it should be processed
   * @return the command's result, as a SimpleXMLObject
   */
   private static function executeCommand($action, $options = array(), $raw = false) {
      $config = PluginOpenvasConfig::getInstance();
      $omp    = new self();

      //Get the command in XML format
      if (!$raw) {
        $command = self::getXMLForAction($action, $options);
      } else {
        $command = $options['command'];
      }
      $content = $omp->sendCommand($config, $command);
      if ($content) {
         return simplexml_load_string($content);
      } else {
         return false;
      }
   }

   /**
   * @since 1.0
   *
   * Send a command to OpenVAS
   * @param $omp OpenVAS object
   * @param $config plugin configuration
   * @param $command the XML command to send to OpenVAS
   * @param $xml is is a command in XML format ? If yes, it means that the response will also be in XML
   * @return the XML response from OpenVAS
   */
   private function sendCommand(PluginOpenvasConfig $config, $command = '',
                                $xml = false) {

      /*
      if ($config->fields['openvas_verify_peer']) {
         $verify_peer = true;
      } else {
         $verify_peer = false;
      }
      if ($config->fields['openvas_allow_self_signed']) {
         $allow_self_signed = true;
      } else {
         $allow_self_signed = false;
      }

      //Set SSL options
      $context = stream_context_create(array(
          'ssl' => array(
             'verify_peer' => $verify_peer,
             'allow_self_signed' => $allow_self_signed
          )
      ));

      $response = null;
      $errno    = null;
      $errstr   = null;
      $content  = '';

      //Connect to OpenVAS using TLS
      $url    = "tls://".$config->fields['openvas_host'].":".$config->fields['openvas_port'];
      $socket = @stream_socket_client($url, $errno, $errstr, 30, STREAM_CLIENT_CONNECT, $context);
      if ($errno) {
         return false;
      } else {
         Toolbox::logDebug("Sending command", $command);
        //Write command in the PHP socket
        fwrite($socket, $command);
        //Get the results
        $content = stream_get_contents($socket);
        if (!Toolbox::seems_utf8($content)) {
           $content = Toolbox::encodeInUtf8($content);
        }
        //Close the socket
        fclose($socket);
     }*/

     //Check if omp exists && is executable
     if (!file_exists($config->fields['openvas_omp_path'])
        || !is_executable($config->fields['openvas_omp_path'])
           || !$this->ping($config)) {
        return false;
     }

     //Build the omp command line
     //By using the -X flag, we can send XML commands
     $url = $config->fields['openvas_omp_path']." -h "
           .$config->fields['openvas_host']." -p "
           .$config->fields['openvas_port']."  -u "
           .$config->fields['openvas_username']." -w "
           .$config->fields['openvas_password']." -X \"$command\"";

     Toolbox::logDebug("Execute command : ".$url);

     $content    = '';

     //Launch omp executable and get the command's result in $content array
     //We do not use exec() because output is truncated
     $handle = popen($url, 'r');
     //Read until there's no data left
     while(!feof($handle)) {
        $content.=fread($handle, 1024);
     }

     if (empty($content)) {
        return false;
     } else {
        if (!Toolbox::seems_utf8($content)) {
           $content = Toolbox::encodeInUtf8($content);
        }
        return $content;
     }
   }

   /**
   * @since 1.0
   *
   * Try to open a connection to the server
   * @param $config a plugin configuration object
   * @return true if a connection can be opened to the server
   */
   static function ping() {
      $config  = PluginOpenvasConfig::getInstance();
      $errCode = $errStr = '';
      $result  = false;
      $fp = @fsockopen($config->fields['openvas_host'], $config->fields['openvas_port'],
                       $errCode, $errStr, 1);
      if ($errCode == 0) {
         $result = true;
         fclose($fp);
      }
      return $result;
   }

   /**
   * @since 1.0
   *
   * Show a dropdown displaying OpenVAS targets
   * @param name the dropdown name
   * @param value the selected value to show
   * @return the dropdown ID (is needed)
   */
   static function dropdownTargets($name, $value='') {
      global $DB;

      //Get all targets
      $results = self::getTargetsAsArray();

      //Get targets uuid already in use
      $used    = array();
      foreach ($DB->request('glpi_plugin_openvas_items',
                            [ 'NOT' => [ 'openvas_id' => $value]]) as $val) {
         $used[$val['openvas_id']] = $val['openvas_id'];
      }

      asort($results);
      //Display a dropdown with targets data
      return Dropdown::showFromArray($name, $results,
                                     ['value' => $value,
                                      'used'  => $used,
                                      'display_emptychoice' => true
                                     ]);
   }

   /**
   * @since 1.0
   *
   * Get all available targets in OpenVAS
   * @return all targets as an array of target uuid => target name or IP address
   */
   static function getTargetsAsArray() {
      $target_response = self::executeCommand(self::TARGET);

      $results       = array();
      foreach ($target_response->target as $response) {
         $host         = $response->hosts->__toString();
         $name         = $response->name->__toString();
         $id           = $response->attributes()->id->__toString();
         $results[$id] = $host." ($name)";
      }
      return $results;
   }

   /**
   * @since 1.0
   *
   * Get all available targets in OpenVAS
   * @return all targets as an array of target uuid => target name or IP address
   */
   static function getOneTargetsDetail($target_id = false) {
      if (!$target_id) {
         return false;
      }

      $options = [ 'filter'     => [ 'first'         => 1,
                                    self::SORT_DESC => 'name',
                                    'rows'          => 1, ],
                   'target_id' => $target_id
                 ];
      $target_response = self::executeCommand(self::TARGET, $options);

      $target          = array();
      foreach ($target_response->target as $response) {
         $target['host']    = $response->hosts->__toString();
         $target['name']    = $response->name->__toString();
         $target['id']      = $response->attributes()->id->__toString();
         $target['comment'] = $response->comment->__toString();
      }
      return $target;
   }

   /**
   * @since 1.0
   *
   * Get all tasks for a target
   * @param target_id target ID
   * @return tasks info as an array
   */
   static function getTasksForATarget($target_id = false) {
      if (!$target_id) {
         return true;
      }

      //To get all tasks for a target, we first need to get all tasks, and check for each
      //task if it's linked to our target...

      //Get all tasks
      $tasks_response = self::executeCommand(self::TASK,
                                             [ 'filter' => [ self::SORT_DESC => 'last', 'rows' => -1] ]);

      //Array to store the results
      $results        = array();

      foreach ($tasks_response->task as $response) {

        $tid = strval($response->target->attributes()->id);
        if ($tid == $target_id) {
          $ret = self::getOneTaskInfos($response);
          if (is_array($ret)) {
            $results[$ret['id']] = $ret;
          }
        }
      }
      return $results;

   }

   static function getOneTaskInfos($task) {
     global $CFG_GLPI;

     //If there's no target, go to the next task
     if (!isset($task->attributes()->id) ||!strval($task->attributes()->id)) {
        return false;
     }

     //Check it the tasks
    $id    = strval($task->attributes()->id);
    $tid   = strval($task->target->attributes()->id);
    $tname = strval($task->target->name);

    $progress  = "";
    $severity  = 0;
    $scan_date = '';
    $report_id = '';

    $name    = strval($task->name);
    $status  = strval($task->status);
    if ($status != 'Running') {
      $node = 'last_report';
    } else {
      $node = 'current_report';
    }
    $progress  = strval($task->progress);
    if (isset($task->$node->report->severity)) {
      $severity = strval($task->$node->report->severity);
    } else {
      $severity = 0;
    }

    if (isset($task->$node->report->scan_end)) {
      $tmp_scan_date = strval($task->$node->report->scan_end);
      if (!empty($tmp_scan_date)) {
         $date_scan_end = new DateTime($tmp_scan_date);
         $scan_date     = date_format($date_scan_end, 'Y-m-d H:i:s');
      }
    }

    if (isset($task->$node->report)
      && isset($task->$node->report->attributes()->id)) {
       $report_id = strval($task->$node->report->attributes()->id);
    }

    $config  = strval($task->config->name);
    $scanner = strval($task->scanner->name);

    $results       = [ 'name'           => $name,
                       'config'         => $config,
                       'scanner'        => $scanner,
                       'status'         => $status,
                       'progress'       => $progress,
                       'date_last_scan' => $scan_date,
                       'severity'       => $severity,
                       'report'         => $report_id,
                       'id'             => $id,
                       'target'         => $tid,
                       'target_name'    => $tname
                    ];
    return $results;
   }

  static function isTaskRunning($task_status) {
    switch ($task_status) {
      case 'Running':
      case 'Internal Error':
      case 'Requested':
       return true;
    }
    return false;
  }


  static function displayDropdown($action, $name, $empty = false) {
    $response = self::executeCommand($action);
    $returns = [];

    foreach ($response->$name as $res) {
      $id = strval($res->attributes()->id);
      $returns[$id] = strval($res->name);
    }
    return Dropdown::showFromArray($name, $returns, ['display_emptychoice' => $empty]);
  }

  static function addTask($options = []) {
    $command = "<create_task><name>".$options['name']."</name>";
    $command.= "<comment>".$options['comment']."</comment>";
    $command.= "<scanner id='".$options['scanner']."'/>";
    $command.= "<config id='".$options['config']."'/>";
    $command.= "<target id='".$options['target']."'/>";
    $command.= "<schedule id='".$options['schedule']."'/>";
    $command.= "</create_task>";

    $response = self::executeCommand(self::ADD_TASK, ['command' => $command], true);
    return ($response->status == '201');
  }
}
