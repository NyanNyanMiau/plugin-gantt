<?php

namespace Kanboard\Plugin\Gantt\Controller;

use Kanboard\Controller\BaseController;
use Kanboard\Filter\TaskProjectFilter;
use Kanboard\Model\TaskModel;
use Kanboard\Model\SwimlaneModel;

/**
 * Tasks Gantt Controller
 *
 * @package  Kanboard\Controller
 * @author   Frederic Guillot
 * @property \Kanboard\Plugin\Gantt\Formatter\TaskGanttFormatter $taskGanttFormatter
 */
class TaskGanttController extends BaseController
{
    /**
     * Show Gantt chart for one project
     */
    public function show()
    {
        $project = $this->getProject();
        $search = $this->helper->projectHeader->getSearchQuery($project);
        $sorting = $this->request->getStringParam('sorting', '');
        $filter = $this->taskLexer->build($search)->withFilter(new TaskProjectFilter($project['id']));

        if ($sorting === '') {
          $sorting = $this->configModel->get('gantt_task_sort', 'board');
        }

        if ($sorting === 'date') {
            $filter->getQuery()->asc(TaskModel::TABLE.'.date_started')->asc(TaskModel::TABLE.'.date_due')->asc(TaskModel::TABLE.'.date_creation');
        } else if ($sorting === 'swimlane') {
            $filter->getQuery()->asc(SwimlaneModel::TABLE.'.position')->asc(TaskModel::TABLE.'.date_started')->asc(TaskModel::TABLE.'.date_creation');
        } else {

            $filter->getQuery()->asc('column_position')->asc(TaskModel::TABLE.'.position');
        }

        $this->response->html($this->helper->layout->app('Gantt:task_gantt/show', array(
            'project' => $project,
            'title' => $project['name'],
            'description' => $this->helper->projectHeader->getDescription($project),
            'sorting' => $sorting,
            'tasks' => $filter->format($this->taskGanttFormatter),
        )));
    }

    /**
     * Save new task start date and due date
     */
    public function save()
    {
        $this->getProject();
        $changes = $this->request->getJson();
        $values = [];

        if (! empty($changes['start'])) {
            $values['date_started'] = strtotime($changes['start']);
            $values['date_started'] = strtotime( $changes['starttime'], $values['date_started']);
        }

        if (! empty($changes['end'])) {
            $values['date_due'] = strtotime($changes['end']);
            $values['date_due'] = strtotime( $changes['endtime'], $values['date_due']);
        }

        if (! empty($values)) {
            $values['id'] = $changes['id'];

            $task = $this->taskFinderModel->getById($values['id']);
            $oldStart = $task['date_started'];
            $oldDue = $task['date_due'];

            $result = $this->taskModificationModel->update($values);

            if (! $result) {
                $this->response->json(array('message' => 'Unable to save task'), 400);
            } else {

                // just check if there are connections
                $graph = [ 'tasks' => [], 'edges' => [] ];
                $this->traverseGraph($graph, $task, $test=true);
                // remove initial Task
                unset($graph['tasks'][$task['id']]);
                $tasks_list = [];
                foreach ($graph['tasks'] as $linkedtask) {
                    // remove in_active tasks
                    if (!$task['is_active']) continue;
                    // if task has no start or due date remove them
                    if (!$linkedtask['date_started'] && !$linkedtask['date_due']) continue;
                    $tasks_list[] = $linkedtask;
                }

                $i1 = count($tasks_list);
                $i2 = count($this->subtaskModel->getAll($task['id']));

                $startDiff = ($values['date_started'] - $oldStart) / 86400;
                $dueDiff = ($values['date_due'] - $oldDue) / 86400;

                $this->response->json(array('message' => 'OK', 'result' => [
                    'linkedCount' => $i1 + $i2,
                    'linkedMoveUrl' => '/?controller=TaskGanttController&action=showMove&project_id='.$task['project_id'].'&task_id='.$task['id'].'&plugin=Gantt&startDiff='.$startDiff.'&dueDiff='.$dueDiff
                ]), 201);
            }
        } else {
            $this->response->json(array('message' => 'Ignored'), 200);
        }
    }

    public function showMove()
    {
        $project = $this->getProject();
        $task = $this->getTask();

        $startDiff = $this->request->getStringParam('startDiff', 0);
        $dueDiff = $this->request->getStringParam('dueDiff', 0);

        $graph = [ 'tasks' => [], 'edges' => [] ];
        $this->traverseGraph($graph, $task);
        // remove initial Task
        unset($graph['tasks'][$task['id']]);

        // $taskSubtasks = $graph['tasks'][$task['id']]['subtask'];

        $columns_list = [];
        $tasks_list = [];
        foreach ($graph['tasks'] as $linkedtask) {
            // not is_active  = 0
            if (!$task['is_active']) continue;
//             // not start or due date = 0
            if (!$linkedtask['date_started'] && !$linkedtask['date_due']) continue;

            $tasks_list[] = $linkedtask;
 			// add column info, if not loaded yet
            if (isset($columns_list[ $linkedtask["column_id"] ])) continue;
            $columns_list[ $linkedtask["column_id"] ] = $this->columnModel->getById($linkedtask["column_id"])['title'];
        }

        $this->response->html($this->template->render('Gantt:task_gantt/showMove', array(
            'project' => $project,
            'task' => $task,
            'linkedTasks' => $tasks_list,
            'startDiff' => $startDiff,
            'dueDiff' => $dueDiff,
            'columns_list' => $columns_list
        )));
//         echo "<pre>", print_r($graph['tasks'], 1), print_r($taskSubtasks, 1), "</pre>";
    }

    protected function traverseGraph(&$graph, $task, $test=false)
    {
        // add new task
        if (!isset($graph['tasks'][$task['id']])) {
            $graph['tasks'][$task['id']] = $task;
            if (!$test) {
                $graph['tasks'][$task['id']]['subtasks'] = $this->subtaskModel->getAll($task['id']);
            }
        }

        foreach ($this->taskLinkModel->getAllGroupedByLabel($task['id']) as $type => $links) {
            foreach ($links as $link) {
                if (!isset($graph['edges'][$task['id']][$link['task_id']]) &&
                    !isset($graph['edges'][$link['task_id']][$task['id']])) {
                        $graph['edges'][$task['id']][$link['task_id']] = $type;
                        $this->traverseGraph(
                            $graph,
                            $this->taskFinderModel->getDetails($link['task_id'])
                        );
                    }
            }
        }
    }

}
