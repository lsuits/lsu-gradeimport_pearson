<?php

require_once('../../../config.php');
//require_once($CFG->dirroot.'/grade/lib.php');
require_once('forms.php');
require_once('lib.php');

$_s = function($key) { return get_string($key, 'gradeimport_pearson'); };

$id = required_param('id', PARAM_INT);

$url = new moodle_url('/grade/import/pearson/index.php', array('id' => $id));
$PAGE->set_url($url);

if (!$course = $DB->get_record('course', array('id' => $id))) {
    print_error('nocourseid');
}

require_login($course);

$context = context_course::instance($id);

$PAGE->set_context($context);

require_capability('moodle/grade:import', $context);
require_capability('gradeimport/pearson:view', $context);

$file_text = optional_param('file_text', null, PARAM_TEXT);
$file_type = optional_param('file_type', null, PARAM_TEXT);

$mapping_data = array(
    'file_text' => $file_text,
    'file_type' => $file_type,
);

print_grade_page_head($course->id, 'import', 'pearson');

$file_form = new pearson_file_form();
$mapping_form = new pearson_mapping_form(null, $mapping_data);
$results_form = new pearson_results_form(null, array('messages' => null));

if ($form_data = $file_form->get_data()) {
    $data = array(
        'file_text' => $file_form->get_file_content('userfile'),
        'file_type' => $form_data->file_type
    );

    $mapping_form = new pearson_mapping_form(null, $data);
    $mapping_form->display();
} else if ($form_data = $mapping_form->get_data()) {
    $file_text = $form_data->file_text;
    $file_type = $form_data->file_type;

    $pearson_file = pearson_create_file($file_text, $file_type);

    $last = count($pearson_file->headers) - 1;

    if (rtrim($pearson_file->headers[$last]) == '') {
        unset($pearson_file->headers[$last]);
    }

    $headers_to_items = array();

    foreach ($pearson_file->headers as $n => $header) {
        $data_name = 'item_' . $n;

        if ($form_data->$data_name != -1) {
            $headers_to_items[$n] = $form_data->$data_name;
        }
    }

    if ($pearson_file->process($headers_to_items)) {
        echo $OUTPUT->notification($_s('success'), 'notifysuccess');
    } else {
        echo $OUTPUT->notification($_s('failure'));
    }

    $data = array('messages' => $pearson_file->messages);

    $results_form = new pearson_results_form(null, $data);
    $results_form->display();

    $url = new moodle_url('/grade/index.php', array('id' => $id));
    echo $OUTPUT->continue_button($url);
} else {
    $file_form->display();
};

echo $OUTPUT->footer();

?>
