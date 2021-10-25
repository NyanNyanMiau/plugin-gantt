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

                // build relation graph
                $graph = [];
                $graph['tasks'] = [];
                $graph['edges'] = [];
                $this->traverseGraph($graph, $task);
                // remove initial Task
//                 unset($graph['tasks'][$task['id']]);

                $this->response->json(array('message' => 'OK', 'result' => [
                    'startDiff' => ($values['date_started'] - $oldStart) / 86400,
                    'dueDiff' => ($values['date_due'] - $oldDue) / 86400,
                    'linkedTasks' => $graph['tasks']
                ]), 201);
            }
        } else {
            $this->response->json(array('message' => 'Ignored'), 200);
        }
    }


    protected function traverseGraph(&$graph, $task)
    {
        /*
         * äh nope,have to copy queries and add active = 1, also for subtasks -.-
         * */
        if (!isset($graph['tasks'][$task['id']])) {
            $graph['tasks'][$task['id']] = $task;
            $graph['tasks'][$task['id']]['subtasks'] = $this->subtaskModel->getAll($task['id']);
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
