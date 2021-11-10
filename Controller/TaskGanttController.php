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
            // midnight js date string
            $values['date_started'] = strtotime($changes['start']);
        }

        if (! empty($changes['end'])) {
            // midnight js date string
            $values['date_due'] = strtotime($changes['end']);
        }

        $startDiff = $dueDiff = 0;

        if (! empty($values)) {
            $values['id'] = $changes['id'];

            $task = $this->taskFinderModel->getById($values['id']);
            if (isset($values['date_started']) && $task['date_started']) {
                // keep time
                $oldStart = $task['date_started'];
                $oldStartMidnight = strtotime("midnight", $oldStart);
                $oldStartTime = $oldStart - $oldStartMidnight;

                $values['date_started'] += $oldStartTime;
                $startDiff = ($values['date_started'] - $oldStart) / 86400;
            }
            if (isset($values['date_due']) && $task['date_due']) {
                // keep time
                $oldDue = $task['date_due'];
                $oldDueMidnight = strtotime("midnight", $oldDue);
                $oldDueTime = $oldDue - $oldDueMidnight;

                $values['date_due'] += $oldDueTime;
                $dueDiff = ($values['date_due'] - $oldDue) / 86400;
            }

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
                $i2 = $this->subtaskModel->getQuery()
                            ->eq('task_id', $task['id'])
                            ->neq('status', 2)
                            ->neq('due_date', 0)
                            ->count();

                $this->response->json(array('message' => 'OK', 'result' => [
                    'linkedCount' => $i1 + $i2,
                    'linkedMoveUrl' => '/?controller=TaskGanttController&action=showMove&project_id='.$task['project_id'].'&task_id='.$task['id'].'&plugin=Gantt&startDiff='.floor($startDiff).'&dueDiff='.floor($dueDiff)
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
            if (! $linkedtask['is_active']) continue;
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
    }

    protected function traverseGraph(&$graph, $task, $test=false)
    {
        // add new task
        if (!isset($graph['tasks'][$task['id']])) {
            $graph['tasks'][$task['id']] = $task;
//             if (!$test) {
//                 $graph['tasks'][$task['id']]['subtasks'] = $this->subtaskModel->getAll($task['id']);
//             }
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


    public function saveMoveLinked()
    {
        $project = $this->getProject();
        $mainTask = $this->getTask();
        $values = $this->request->getValues();

        if (!count($values)) {
            die('Form data invalid!');
        }

        $startDiff  = isset($values["startDiffActive"]) && $values["startDiffActive"] && isset($values["startDiffAmount"]) && (int)$values["startDiffAmount"] !== 0 ? (int) $values["startDiffAmount"] : 0;
        $dueDiff    = isset($values["dueDiffActive"]) && $values["dueDiffActive"] && isset($values["dueDiffAmount"]) && (int)$values["dueDiffAmount"] !== 0 ? (int) $values["dueDiffAmount"] : 0;
        $tasks      = isset($values['tasks']) ? (array) $values['tasks'] : [];
        $columns    = isset($values['columns']) ? (array) $values['columns'] : [];

        $updSubtasks = isset($values["subtasksActive"]) && $values["subtasksActive"] ? true : false;

        $taskCounter = 0;
        $subTaskCounter = 0;
        // mainTask  subtasks
        if ($updSubtasks && $dueDiff !== 0) {
            // update subtasks
            $subTaskCounter += $this->updateSubtasks($mainTask["id"], $dueDiff);
        }

        // proccess passed tasks in columns
        if ( count($tasks) && count($columns) ) {
            foreach ($values['tasks'] as $taskId) {

                $updTask = $this->taskFinderModel->getById($taskId);
                // ignore  if no task or not in correct column
                if ( !count($updTask) || !isset($columns[$updTask['column_id']])){
                    continue;
                }

                // prepare update
                $updData = [
                    'id' => $updTask['id']
                ];
                // update due
                if ($updTask['date_due'] && $dueDiff !== 0) {
                    $updData['date_due'] = $updTask['date_due'] + $dueDiff * 86400;
                }
                // update start
                if ($updTask['date_started'] && $startDiff !== 0) {
                    $updData['date_started'] = $updTask['date_started'] + $startDiff * 86400;
                }

                if ( $this->taskModificationModel->update($updData, $fireEvents = true) ) {
                    $taskCounter ++;
                }

                if ($updSubtasks && $dueDiff !== 0) {
                    // update subtasks
                    $subTaskCounter += $this->updateSubtasks($taskId, $dueDiff);
                }
            }
        }

        $this->flash->success(t('%s task(s) and %s subtask(s) updated.', $taskCounter, $subTaskCounter));
        $this->response->redirect($this->helper->url->to('TaskGanttController', 'show', ['project_id' => $project['id'], 'plugin' => 'Gantt']));
    }

    private function updateSubtasks($task_id, $dueDiff = 0)
    {
        $subtasks = $this->subtaskListFormatter
            ->withQuery(
                    $this->subtaskModel->getQuery()
                        ->eq('task_id', $task_id)
                        ->neq('status', 2)
                        ->neq('due_date', 0)
            )->format();

        if (count($subtasks)) {
            foreach ($subtasks as $subtask) {
                $this->subtaskModel->update([
                    'id' => $subtask['id'],
                    'due_date' => $subtask['due_date'] + $dueDiff * 86400
                ], $fireEvents = true);
            }
        }
        return count($subtasks);
    }

}
