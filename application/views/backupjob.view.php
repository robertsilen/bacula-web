<?php

/**
 * Copyright (C) 2010-2022 Davide Franco
 *
 * This file is part of Bacula-Web.
 *
 * Bacula-Web is free software: you can redistribute it and/or modify it under the terms of the GNU
 * General Public License as published by the Free Software Foundation, either version 2 of the License, or
 * (at your option) any later version.
 *
 * Bacula-Web is distributed in the hope that it will be useful, but WITHOUT ANY WARRANTY; without
 * even the implied warranty of MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.
 * See the GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License along with Bacula-Web. If not, see
 * <https://www.gnu.org/licenses/>.
 */

namespace App\Views;

use App\Tables\JobTable;
use Core\App\CView;
use Core\Db\CDBQuery;
use Core\Db\DatabaseFactory;
use Core\Graph\Chart;
use Core\Utils\CUtils;
use Core\Utils\DateTimeUtil;
use Core\Helpers\Sanitizer;
use Exception;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Session\Session;

class BackupJobView extends CView
{
    /**
     * @param Request $request
     */
    public function __construct(Request $request)
    {
        parent::__construct($request);

        $this->templateName = 'backupjob-report.tpl';
        $this->name = 'Backup job report';
        $this->title = 'Report per Bacula backup job name';
    }

    public function prepare(Request $request)
    {
        require_once BW_ROOT . '/core/const.inc.php';

        $interval = array();
        $interval[1] = NOW;
        $session = new Session();

        $days_stored_bytes = array();
        $days_stored_files = array();

        // Period list
        $periods_list = array( '7' => "Last week", '14' => "Last 2 weeks", '30' => "Last month");
        $this->assign('periods_list', $periods_list);

        // Stored Bytes on the defined period
        $jobs = new JobTable(DatabaseFactory::getDatabase($session->get('catalog_id', 0)));

        // Get backup job(s) list
        $jobslist = $jobs->get_Jobs_List(null, 'B');
        $this->assign('jobs_list', $jobslist);

        // Check backup job name from $_POST request
        $backupjob_name = null;

        if ($request->getMethod() === 'POST') {
            $backupjob_name = $request->request->get('backupjob_name');
        } elseif ($request->getMethod() === 'GET') {
            $backupjob_name = $request->query->get('backupjob_name');
        }
        $backupjob_name = Sanitizer::sanitize($backupjob_name);

        $where = array();

        if ($backupjob_name == null) {
            $this->assign('selected_jobname', '');
            $this->assign('no_report_options', 'true');

            // Set selected period
            $this->assign('selected_period', 7);
        } else {
            $this->assign('no_report_options', 'false');

            // Make sure provided backupjob_name exist
            if (!in_array($backupjob_name, $jobslist)) {
                throw new Exception("Critical: provided backupjob_name is not valid");
            }

            $this->assign('selected_jobname', $backupjob_name);

            /**
             * Get selected period from POST request, or set it to default value (7)
             */
            $backupjob_period = $request->request->getInt('period', 7);

            // Set selected period
            $this->assign('selected_period', $backupjob_period);

            switch ($backupjob_period) {
                case '7':
                    $periodDesc = "From " . date($session->get('datetime_format_short'), (NOW - WEEK)) . " to " . date($session->get('datetime_format_short'), NOW);
                    $interval[0] = NOW - WEEK;
                    break;
                case '14':
                    $periodDesc = "From " . date($session->get('datetime_format_short'), (NOW - (2 * WEEK))) . " to " . date($session->get('datetime_format_short'), NOW);
                    $interval[0] = NOW - (2 * WEEK);
                    break;
                case '30':
                    $periodDesc = "From " . date($session->get('datetime_format_short'), (NOW - MONTH)) . " to " . date($session->get('datetime_format_short'), NOW);
                    $interval[0] = NOW - MONTH;
            }

            // Get start and end datetime for backup jobs report and charts
            $periods = CDBQuery::get_Timestamp_Interval($jobs->get_driver_name(), $interval);

            $backupjob_bytes = $jobs->getStoredBytes($interval, $backupjob_name);
            $backupjob_bytes = CUtils::Get_Human_Size($backupjob_bytes);

            // Stored files on the defined period
            $backupjob_files = $jobs->getStoredFiles($interval, $backupjob_name);
            $backupjob_files = CUtils::format_Number($backupjob_files);

            // Get the last 7 days interval (start and end)
            $days = DateTimeUtil::getLastDaysIntervals($backupjob_period);

            // Last 7 days stored files chart
            foreach ($days as $day) {
                $stored_files = $jobs->getStoredFiles(array($day['start'], $day['end']), $backupjob_name);
                $days_stored_files[] = array(date("m-d", $day['start']), $stored_files);
            }

            $stored_files_chart = new Chart(
                array( 'type' => 'bar',
                'name' => 'chart_storedfiles',
                'data' => $days_stored_files,
                'ylabel' => 'Files' )
            );

            $this->assign('stored_files_chart_id', $stored_files_chart->name);
            $this->assign('stored_files_chart', $stored_files_chart->render());

            unset($stored_files_chart);

            // Last 7 days stored bytes chart
            foreach ($days as $day) {
                $stored_bytes = $jobs->getStoredBytes(array($day['start'], $day['end']), $backupjob_name);
                $days_stored_bytes[] = array(date("m-d", $day['start']), $stored_bytes);
            }

            $stored_bytes_chart = new Chart(
                array( 'type' => 'bar',
                'name' => 'chart_storedbytes',
                'uniformize_data' => true,
                'data' => $days_stored_bytes,
                'ylabel' => 'Bytes' )
            );

            $this->assign('stored_bytes_chart_id', $stored_bytes_chart->name);
            $this->assign('stored_bytes_chart', $stored_bytes_chart->render());
            unset($stored_bytes_chart);

            // Backup job name
            $jobs->addParameter('jobname', $backupjob_name);
            $where[] = 'Name = :jobname';

            // Backup job type
            $jobs->addParameter('jobtype', 'B');
            $where[] = "Type = :jobtype";

            // Backup job starttime and endtime
            $where[] = '(EndTime BETWEEN ' . $periods['starttime'] . ' AND ' . $periods['endtime'] . ')';

            $query = CDBQuery::get_Select(array('table' => $jobs->getTableName(),
            'fields' => array( 'JobId', 'Level', 'JobFiles', 'JobBytes', 'ReadBytes', 'Job.JobStatus', 'StartTime', 'EndTime', 'Name', 'Status.JobStatusLong'),
            'where' => $where,
            'orderby' => 'EndTime DESC',
            'join' => array(
                array('table' => 'Status', 'condition' => 'Job.JobStatus = Status.JobStatus')
            ) ), $jobs->get_driver_name());

            $joblist      = array();
            $joblevel     = array('I' => 'Incr', 'D' => 'Diff', 'F' => 'Full');
            $result = $jobs->run_query($query);

            foreach ($result->fetchAll() as $job) {
                // Job level description
                $job['joblevel'] = $joblevel[$job['level']];

                // Job execution execution time
                $job['elapsedtime'] = DateTimeUtil::Get_Elapsed_Time($job['starttime'], $job['endtime']);

                // Compression
                if (($job['jobbytes'] > 0) && ($job['readbytes'] > 0)) {
                    $compression = (1 - ($job['jobbytes'] / $job['readbytes']));
                    $job['compression'] = number_format($compression, 2);
                } else {
                    $job['compression'] = 'N/A';
                }

                // Job speed
                $start = $job['starttime'];
                $end = $job['endtime'];
                $seconds = DateTimeUtil::get_ElaspedSeconds($end, $start);

                if ($seconds !== false && $seconds > 0) {
                    $speed = $job['jobbytes'] / $seconds;
                    $job['speed'] = CUtils::Get_Human_Size($speed, 2) . '/s';
                } else {
                    $job['speed'] = 'N/A';
                }

                // Job bytes more easy to read
                $job['jobbytes'] = CUtils::Get_Human_Size($job['jobbytes']);
                $job['jobfiles'] = CUtils::format_Number($job['jobfiles']);

                // Format date/time
                $job['starttime'] = date($session->get('datetime_format'), strtotime($job['starttime']));
                $job['endtime'] = date($session->get('datetime_format'), strtotime($job['endtime']));

                $joblist[] = $job;
            } // end while

            // Assign vars to template
            $this->assign('jobs', $joblist);
            $this->assign('backupjob_name', $backupjob_name);
            $this->assign('periodDesc', $periodDesc);
            $this->assign('backupjob_bytes', $backupjob_bytes);
            $this->assign('backupjob_files', $backupjob_files);
        } // end else
    } // end of prepare() method
} // end of class
