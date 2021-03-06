<div class="span-27 last">

    <?php
    if ($stackTrace == null) {
        $this->widget('zii.widgets.grid.CGridView', array(
            'dataProvider' => $model->searchThreads(),
            'selectableRows' => null,
            'columns' => array(
                array(
                    'name' => 'thread_id',
                    'type' => 'text',
                    'value' => '$data->thread_id',
                ),
                array(
                    'header' => 'Exception',
                    'type' => 'text',
                    'value' => '$data->thread_id==$data->crashreport->exception_thread_id?"Yes":""',
                ),
                array(
                    'header' => 'Thread Procedure',
                    'type' => 'text',
                    'value' => '$data->getThreadFuncName()',
                ),
                array(
                    'header' => 'Stack Trace',
                    'type' => 'raw',
                    'value' => 'CHtml::link("View stack trace", array("crashReport/view", "tab"=>"Threads", "id"=>$data->crashreport_id, "thread"=>$data->id))',
                ),
                // array(
                //     'header' => 'Stack Trace MD5',
                //     'type' => 'text',
                //     'value' => '$data->stack_trace_md5',
                // ),
            ),
        ));
    } else {
        echo '<div class="quiet">Viewing stack trace for thread ' . $threadModel->thread_id . '</div>';

        $this->widget('zii.widgets.grid.CGridView', array(
            'dataProvider' => $stackTrace,
            'summaryText'=>'',
            'selectableRows' => null,
            'columns' => array(
                array(
                    'header' => 'Frame',
                    'type' => 'text',
                    'value' => '$data->getTitle()',
                ),
            ),
        ));
    }
    ?>

</div>

