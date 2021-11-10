<div class="page-header">
    <h2>
    	<a class="collapseOpener collapsed px-2 text-black" data-bs-toggle="collapse" href="#taskDesc"
			role="button" aria-expanded="false" aria-controls="projectList<?= $project['id'] ?>"><i class="fa fa-caret-down"></i></a>
		 # <?= $task['id'] ?> &gt; <?= t('abhängige Aufgaben umdatieren') ?></h2>

		<div id="taskDesc" class="collapse">
			<div class="px-2 pt-3 pb-0">
   				<h2><?= $this->text->e($task['title']) ?></h2>
    			<br>
    			<a class="btn" href="/?controller=relationgraph&action=show&plugin=relationgraph&task_id=<?= $task['id'] ?>" target="_blank"><i class="fa fa-rotate-left fa-fw"></i>Beziehungsdiagramm</a>
   			</div>
		</div>
</div>

<form method="post" action="<?= $this->url->href('TaskGanttController', 'saveMoveLinked', array('project_id' => $project['id'], 'task_id' => $task['id'], 'plugin' => 'Gantt')) ?>"
	autocomplete="off" onkeydown="return event.key != 'Enter';">
    <?= $this->form->csrf() ?>
	<div class="panel">
    	<div class="d-flex align-items-center">
    		<div class="col-6">
    			<div class="d-flex align-items-center">
        			<label class="m-0 col-6"><input type="checkbox" name="dueDiffActive" value="1" <?= $dueDiff !== 0 ? 'checked="checked"' :'' ?>>&nbsp;<?= t('Neues Fälligkeitsdatum') ?></label>
        			<input type="number" name="dueDiffAmount" value="<?= $dueDiff ?>" class="d-inline small m-0"
        				oninput="var newInput = this; $('#redateTasks .new_duedate').each(function(){ var el = $(this); el.data('date') && el.text( moment.unix(el.data('date')).add(newInput.value, 'days').format('L')); });">
        				&nbsp;<i class="fa fa-refresh p-1" onclick="$(this).siblings('input').val('<?= $dueDiff ?>').trigger('input')"></i>
        				&nbsp;Tage
        		</div>
			</div>
			<div class="col-6">
    			<div class="d-flex">
        			<label class="m-0">
        			<input type="checkbox" name="subtasksActive" value="1" checked="checked">&nbsp;<?= t('Unteraufgaben einbeziehen') ?></label>
        		</div>
			</div>
		</div>
    	<div class="d-flex align-items-center mt-3">
    		<div class="col-6">
    			<div class="d-flex align-items-center">
        			<label class="m-0 col-6"><input type="checkbox" name="startDiffActive" value="1" <?= $startDiff!== 0 ? 'checked="checked"' :'' ?>>&nbsp;<?= t('neues Startdatum') ?></label>
        			<input type="number" name="startDiffAmount" value="<?= $startDiff?>" class="d-inline small m-0"
        				oninput="var newInput = this; $('#redateTasks .new_startdate').each(function(){ var el = $(this); el.data('date') && el.text( moment.unix(el.data('date')).add(newInput.value, 'days').format('L')); });">
        				&nbsp;<i class="fa fa-refresh p-1" onclick="$(this).siblings('input').val('<?= $startDiff?>').trigger('input')"></i>
        				&nbsp;Tage
        		</div>
			</div>
		</div>
	</div>

<?php if (count($columns_list)) :?>
	<div class="panel">
		<div class="d-flex align-items-center mb-4">
			<div class="col-auto"><button class="m-0 btn btn" type="button" onclick="toggleCheckboxes(this, '#redateColumns input')" data-state="0"><?= t('alle Spalten aktivieren/deaktivieren') ?></button></div>
		</div>
		<div id="redateColumns" class="d-flex">
			<?php $chunks = array_chunk($columns_list, 4, true);
			    foreach ($chunks as $chunk): ?>
					<div class="col-3"><?= $this->form->checkboxes('columns', $chunk) ?></div>
			<?php endforeach; ?>
		</div>
	</div>
<?php endif;?>

<?php if (count($linkedTasks)): ?>
	<div class="panel">
		<div class="d-flex align-items-center mb-4">
			<div class="col-auto"><button class="m-0 btn btn" type="button" onclick="toggleCheckboxes(this, '#redateTasks input')" data-state="0"><?= t('alle Aufgaben aktivieren/deaktivieren') ?></button></div>
			<div class="col-3 ms-auto text-right">neues Start- / Fälligkeitsdatum</div>
		</div>
		<div id="redateTasks">
 			<?php foreach ($linkedTasks as $task): ?>
 			<div class="d-flex">
				<label class="col-10 align-items-start">
					<input type="checkbox" name="tasks[]" value="<?= $task["id"] ?>">&nbsp;<span>#<?= $task["id"] ?>
					<?= $this->text->e($task["title"]) ?> •
					<i><?= $this->text->e($task['project_name']) ?></i> &gt;
					<i><?= $this->text->e($task['swimlane_name']) ?></i> &gt;
					<i><?= $this->text->e($columns_list[$task['column_id']]) ?></i>
				</span></label>
				<div class="col-2 text-right calculateWrap">
					<b class="new_startdate" data-date="<?= $task['date_started'] ?>"><?= $task['date_started'] ? date('d.m.Y', $task['date_started']) : '-' ?></b> /
					<b class="new_duedate" data-date="<?= $task['date_due'] ?>"><?= $task['date_due'] ? date('d.m.Y', $task['date_due']) : '-' ?></b>
				</div>
			</div>
			<?php endforeach; ?>
		</div>
	</div>
<?php endif; ?>

	<div class="px-4 py-3"style="color:#fff; background-color:#ccc;">Anmerkung: Bereits abgeschlossene Aufgaben werden nicht neu datiert. Unteraufgaben werden in der Liste nicht dargestellt, jedoch berücksichtigt.</div>

	<div class="mt-3 task-form-bottom"><?= $this->modal->submitButtons() ?></div>
</form>
