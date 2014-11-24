<?php

require_once($CFG->dirroot.'/grade/lib.php');
require_once($CFG->dirroot.'/grade/import/lib.php');

function pearson_create_file($file_text, $file_type) {
    global $COURSE;

    if (in_array($file_type, array(0, 1))) {
        $pearson_file = new PearsonMyLabFile($file_text, $COURSE->id);
    } else {
        $pearson_file = new PearsonMasteringFile($file_text, $COURSE->id);
    }

    $pearson_file->extract_headers();

    return $pearson_file;
}

abstract class PearsonFile {
    public $lines;
    public $courseid;
    public $id_field;
    public $file_text;
    public $headers = array();
    public $messages = array();
    public $ids_to_grades = array();
    public $users_not_found = array();
    public $moodle_ids_to_grades = array();

    abstract public function parse($headers_to_items);
    abstract public function preprocess_headers();
    abstract public function discern_id_field();

    function __construct($file_text, $courseid) {
        $this->file_text = $file_text;
        $this->lines = explode("\n", $file_text);

        $this->id_field = $this->discern_id_field();

        $this->courseid = $courseid;
    }

    function convert_ids() {
        global $CFG;

        $_s = function($key, $a) { return get_string($key, 'gradeimport_pearson', $a); };

        $roleids = explode(',', $CFG->gradebookroles);
        $context = context_course::instance($this->courseid);

        $fields = 'u.id, u.' . $this->id_field;

        $moodle_ids_to_field = array();

        $users = get_role_users($roleids, $context, false);

        $found_users = array();

        foreach ($users as $k => $v) {
            $moodle_ids_to_field[$k] = $v->{$this->id_field};
            $found_users[] = $v->{$this->id_field};
        }

        foreach ($moodle_ids_to_field as $k => $v) {
            foreach ($this->ids_to_grades as $gi_id => $user_and_grade) {
                $ids_only = array_keys($this->ids_to_grades[$gi_id]);

                if (!array_key_exists($gi_id, $this->moodle_ids_to_grades)) {
                    $this->moodle_ids_to_grades[$gi_id] = array();
                }

                $found = array_search($v, $ids_only);

                if ($found !== false) {
                    $vv = $this->ids_to_grades[$gi_id][$ids_only[$found]];
                    $this->moodle_ids_to_grades[$gi_id][$k] = $vv;
                }
            }
        }

        $all_users = array();

        foreach ($this->ids_to_grades as $gi_id => $grades) {
            $all_users += array_keys($grades);
        }

        $all_users = array_unique($all_users);

        $this->users_not_found = array_diff($all_users, $found_users);

        foreach ($this->users_not_found as $user) {
            $this->messages[] = $_s('user_not_found', $user);
        }
    }

    function extract_headers() {
        $headers_raw = $this->preprocess_headers();

        $current_header = '';
        $quote_count = 0;
        $last = ',';

        foreach (str_split($headers_raw) as $c) {
            if ($c == '"') {
                $quote_count++;
            }

            $is_quote = $c == '"';
            $is_comma = $c == ',';
            $count_even = $quote_count % 2 == 0;
            $count_odd = $quote_count % 2 == 1;

            if ($is_quote && $count_even) {
                $this->headers[] = $current_header;
                $current_header = '';
            } else if ($is_quote && $count_odd) {
                // Skip $c
            } else if ($is_comma && $count_even) {
                if ($last != '"') {
                    $this->headers[] = $current_header;
                    $current_header = '';
                }
            } else {
                $current_header .= $c;
            }

            $last = $c;
        }

        if ($current_header) {
            $this->headers[] = $current_header;
        }
    }

    function import_grades() {
        global $DB, $USER;

        $_s = function($key, $a=null) { return get_string($key, 'gradeimport_pearson', $a); };
        $_g = function($key) { return get_string($key, 'grades'); };

        $importcode = get_new_importcode();

        foreach ($this->moodle_ids_to_grades as $gi_id => $grades) {
            $gi_params = array('id' => $gi_id, 'courseid' => $this->courseid);

            if (!$grade_item = grade_item::fetch($gi_params)) {
                continue;
            }

            foreach ($grades as $userid => $grade) {
                // Make sure grade_grade isn't locked
                $grade_params = array('itemid'=>$gi_id, 'userid'=>$userid);

                if ($grade_grade = new grade_grade($grade_params)) {
                    $grade_grade->grade_item =& $grade_item;

                    if ($grade_grade->is_locked()) {
                        continue;
                    }
                }

                $newgrade = new object();
                $newgrade->itemid = $grade_item->id;
                $newgrade->userid = $userid;
                $newgrade->importcode = $importcode;
                $newgrade->importer = $USER->id;

		// If we get garbage from the file, such as the string '--', don't choke on it.
		$clean_float = clean_param($grade, PARAM_FLOAT);
                $newgrade->finalgrade = $clean_float ? $clean_float : null;

                if (!$DB->insert_record('grade_import_values', $newgrade)) {
                    $this->messages[] = $_g('importfailed');
                }
            }
        }

        return grade_import_commit($this->courseid, $importcode, false, false);
    }

    function process($headers_to_items) {
        $this->parse($headers_to_items);
        $this->convert_ids();

        return $this->import_grades();
    }
}

class PearsonMyLabFile extends PearsonFile {
    function preprocess_headers() {
        return trim(trim($this->lines[0]), ',');
    }

    function discern_id_field() {
        return 'username';
    }

    function parse($headers_to_items) {
        $exploded_on_course = explode('Course:', $this->file_text);
        $lines = explode("\n", reset($exploded_on_course));
        $keepers = array_slice($lines, 5);

        $headers_to_grades = array();

        $percents = true;

        foreach ($keepers as $line) {
            if (trim($line) == '') {
                continue;
            }

            $fields = explode(',', $line);

            array_pop($fields);
            $expl_on_amp = explode('@', $fields[2]);
            $pawsid = ltrim(reset($expl_on_amp), '"');

            $grades = array_slice($fields, 5);

            while (count($grades) < count($this->headers)) {
                $grades[] = 0.000;
            }

            foreach ($grades as $n => $grade) {
                if (!isset($headers_to_grades[$n])) {
                    $headers_to_grades[$n] = array();
                }

                if ($grade > 2.00) {
                    $percents = false;
                }

                if (!$grade) {
                    $grade = 0.000;
                }

                $headers_to_grades[$n][$pawsid] = $grade;
            }
        }

        if ($percents) {
            foreach ($headers_to_grades as $i => $grades) {
                foreach ($grades as $j => $user) {
                    $headers_to_grades[$i][$j] *= 100;
                }
            }
        }

        foreach ($headers_to_items as $i => $gi_id) {
            $this->ids_to_grades[$gi_id] = $headers_to_grades[$i];
        }
    }
}

class PearsonMasteringFile extends PearsonFile {
    function preprocess_headers() {

        // This fn gets called twice; the first time around, 3 is an undefined offset.
        if(empty($this->lines[3])){
            return '';
        }

        $groups = explode('Group(s),', $this->lines[3]);
        return end($groups);
    }

    function discern_id_field() {
        $count = 0;

        foreach ($this->lines as $line) {
            $fields = explode(',', $line);

            if (count($fields) > 2 and preg_match('/^89\d{7}$/', $fields[2])) {
                $count += 1;
            }
        }

        return $count / (count($this->lines) - 6) > 0.5 ? 'idnumber' : 'username';
    }

    function parse($headers_to_items) {
        $lines = explode("\n", $this->file_text);
        $keepers = array_slice($lines, 4);

        $headers_to_grades = array();

        foreach ($keepers as $n => $line) {
            if (!$line) {
                continue;
            }

            if (strpos($line, '"","","","","",Average:,') !== False) {
                continue;
            }

            $fields = explode(',', $line);

            if (!isset($fields[2])) {
                continue;
            }

            $username = $fields[2];
            $grades = array_slice($fields, 6);

            foreach ($grades as $n => $grade) {
                if (!isset($headers_to_grades[$n])) {
                    $headers_to_grades[$n] = array();
                }

                $headers_to_grades[$n][$username] = $grade;
            }
        }

        foreach ($headers_to_items as $i => $gi_id) {
            $this->ids_to_grades[$gi_id] = $headers_to_grades[$i];
        }
    }
}

?>
