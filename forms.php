<?php

require_once($CFG->libdir.'/formslib.php');

class pearson_file_form extends moodleform {
    function definition() {
        global $COURSE;

        $_s = function($key) { return get_string($key, 'gradeimport_pearson'); };

        $mform =& $this->_form;

        $mform->addElement('header', 'general', $_s('upload_file'));

        $mform->addElement('hidden', 'id', $COURSE->id);
        $mform->setType('id', PARAM_INT);

        $mform->addElement('filepicker', 'userfile', $_s('file'));
        $mform->addRule('userfile', null, 'required');

        $type_options = array(
            1 => $_s('my_math_lab'),
            2 => $_s('my_stat_lab'),
            3 => $_s('mastering_chemistry'),
            4 => $_s('mastering_biology'),
            5 => $_s('mastering_physics')
        );

        $mform->addElement('select', 'file_type', $_s('file_type'), $type_options);

        $this->add_action_buttons(false, $_s('upload_file'));
    }
}

class pearson_mapping_form extends moodleform {
    function definition() {
        global $COURSE;

        $_s = function($key) { return get_string($key, 'gradeimport_pearson'); };

        $mform =& $this->_form;

        $id = $COURSE->id;

        $mform->addElement('hidden', 'id', $id);
        $mform->setType('id', PARAM_INT);

        $mform->addElement('header', 'general', $_s('map_grade_items'));

        $data = $this->_customdata;

        $file_text = isset($data['file_text']) ? $data['file_text'] : null;
        $file_type = isset($data['file_type']) ? $data['file_type'] : null;

        $mform->addElement('hidden', 'file_text', $file_text);
        $mform->setType('file_text', PARAM_TEXT);
        $mform->addElement('hidden', 'file_type', $file_type);
        $mform->setType('file_type', PARAM_TEXT);

        $pearson_file = pearson_create_file($file_text, $file_type);

        $options = $this->get_grade_item_options();

        foreach ($pearson_file->headers as $n => $item_title) {
            $mform->addElement('select', 'item_' . $n, $item_title, $options);
        }

        $this->add_action_buttons(false, $_s('map_grade_items'));
    }

    function get_grade_item_options() {
        global $COURSE, $DB;

        $_s = function($key) { return get_string($key, 'gradeimport_pearson'); };

        $params = array('courseid' => $COURSE->id, 'locked' => False);

        $items = $DB->get_records('grade_items', $params, 'itemname asc',
            'id, itemname, itemtype, gradetype');

        $options = array(-1 => $_s('ignore_this_item'));

        foreach ($items as $n => $item) {
            if ($item->itemtype == 'manual' and $item->gradetype > 0) {
                $options[$item->id] = $item->itemname;
            }
        }

        return $options;
    }
}

class pearson_results_form extends moodleform {
    function definition() {
        $_s = function($key) { return get_string($key, 'gradeimport_pearson'); };

        $mform =& $this->_form;

        $mform->addElement('header', 'general', $_s('import_results'));

        $data = $this->_customdata;

        $messages = isset($data['messages']) ? $data['messages'] : null;

        if (is_array($messages)) {
            foreach (array_unique($messages) as $message) {
                $mform->addElement('static', '', '', $message);
            }
        }
    }
}
