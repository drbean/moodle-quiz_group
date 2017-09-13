<?php
// This file is part of Moodle - http://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.
//
// Moodle is distributed in the hope that it will be useful,
// but WITHOUT ANY WARRANTY; without even the implied warranty of
// MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
// GNU General Public License for more details.
//
// You should have received a copy of the GNU General Public License
// along with Moodle.  If not, see <http://www.gnu.org/licenses/>.

/**
 * This file defines the quiz group report class.
 *
 * @package   quiz_group
 * @copyright 2017 Dr Bean
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */


defined('MOODLE_INTERNAL') || die();

require_once($CFG->dirroot . '/mod/quiz/report/attemptsreport.php');
require_once($CFG->dirroot . '/mod/quiz/report/group/group_table.php');
require_once($CFG->dirroot . '/mod/quiz/report/group/group_form.php');
require_once($CFG->dirroot . '/mod/quiz/report/group/group_options.php');
require_once($CFG->dirroot . '/mod/quiz/report/group/last_responses_table.php');
require_once($CFG->dirroot . '/mod/quiz/report/group/first_or_all_responses_table.php');


/**
 * Quiz report subclass for the group report.
 *
 * This report lists some combination of
 *  * what question each student saw (this makes sense if random questions were used).
 *  * the response they gave,
 *  * and what the right answer is.
 *
 * Like the overview report, there are options for showing students with/without
 * attempts, and for deleting selected attempts.
 *
 * @copyright 1999 onwards Martin Dougiamas and others {@link http://moodle.com}
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class quiz_group_report extends quiz_attempts_report {

    /**
     *  Override quiz_attempts_report init and use our get_student_joins
     *
     *  Initialise various aspects of this report.
     *
     * @param string $mode
     * @param string $formclass
     * @param object $quiz
     * @param object $cm
     * @param object $course
     * @return array with four elements:
     *      0 => integer the current group id (0 for none).
     *      1 => \core\dml\sql_join Contains joins, wheres, params for all the students in this course.
     *      2 => \core\dml\sql_join Contains joins, wheres, params for all the students in the current group.
     *      3 => \core\dml\sql_join Contains joins, wheres, params for all the students to show in the report.
     *              Will be the same as either element 1 or 2.
     */
    protected function init($mode, $formclass, $quiz, $cm, $course) {
        $this->mode = $mode;
        $this->context = context_module::instance($cm->id);
        $alldata = $this->get_students_joins( $cm, $course);
        list($currentgroup, $studentsjoins, $groupstudentsjoins, $allowedjoins) = $alldata;
        $this->qmsubselect = quiz_report_qm_filter_select($quiz);
        $this->form = new $formclass($this->get_base_url(),
                array('quiz' => $quiz, 'currentgroup' => $currentgroup, 'context' => $this->context));
        return array($currentgroup, $studentsjoins, $groupstudentsjoins, $allowedjoins);
    }

    /**
     *  Override quiz_attempts_report get_student_joins and use our init
     *
     * Get sql fragments (joins) which can be used to build queries that
     * will select an appropriate set of students to show in the reports.
     *
     * @param object $cm the course module.
     * @param object $course the course settings.
     * @return array with four elements:
     *      0 => integer the current group id (0 for none).
     *      1 => \core\dml\sql_join Contains joins, wheres, params for all the students in this course.
     *      2 => \core\dml\sql_join Contains joins, wheres, params for all the students in the current group.
     *      3 => \core\dml\sql_join Contains joins, wheres, params for all the students to show in the report.
     *              Will be the same as either element 1 or 2.
     */
    protected function get_students_joins($cm, $course = null) {

        $allgroups = groups_get_all_groups($cm->id, 0, 0, 'g.*', false);
        $alldata = array ();

        foreach ( $allgroups as $currentgroup_object )
        {
            $currentgroup = $currentgroup_object->id;
            $empty = new \core\dml\sql_join();

            if ($currentgroup == self::NO_GROUPS_ALLOWED) {
                return array($currentgroup, $empty, $empty, $empty);
            }

            $studentsjoins = get_enrolled_with_capabilities_join($this->context);

            if (empty($currentgroup)) {
                return array($currentgroup, $studentsjoins, $empty, $studentsjoins);
            }

            if (!empty (groups_group_exists($currentgroup ))) 
            {
                // We have a currently selected group.
                $groupstudentsjoins = get_enrolled_with_capabilities_join($this->context, '',
                    array('mod/quiz:attempt', 'mod/quiz:reviewmyattempts'), $currentgroup);

                $alldata[$currentgroup] = array($currentgroup, $studentsjoins, $empty, $empty);
            }
        }
        return $alldata['1-01'];
    }


    public function display($quiz, $cm, $course) {
        global $OUTPUT, $DB;

        list($currentgroup, $studentsjoins, $groupstudentsjoins, $allowedjoins)
            = $this->init('group', 'quiz_group_settings_form',
                $quiz, $cm, $course);

        $options = new quiz_group_options('group', $quiz, $cm, $course);

        if ($fromform = $this->form->get_data()) {
            $options->process_settings_from_form($fromform);

        } else {
            $options->process_settings_from_params();
        }

        $this->form->set_data($options->get_initial_form_data());

        // Load the required questions.
        $questions = quiz_report_get_significant_questions($quiz);

        // Prepare for downloading, if applicable.
        $courseshortname = format_string($course->shortname, true,
                array('context' => context_course::instance($course->id)));
        if ($options->whichtries === question_attempt::LAST_TRY) {
            $tableclassname = 'quiz_last_responses_table';
        } else {
            $tableclassname = 'quiz_first_or_all_responses_table';
        }
        $table = new $tableclassname($quiz, $this->context, $this->qmsubselect,
            $options, $groupstudentsjoins, $studentsjoins,
            $questions, $options->get_url());

        $this->hasgroupstudents = false;
        if (!empty($groupstudentsjoins->joins)) {
            $sql = "SELECT DISTINCT u.id
                      FROM {user} u
                    $groupstudentsjoins->joins
                     WHERE $groupstudentsjoins->wheres";
            $this->hasgroupstudents = $DB->record_exists_sql($sql,
                $groupstudentsjoins->params);
        }
        $hasstudents = false;
        if (!empty($studentsjoins->joins)) {
            $sql = "SELECT DISTINCT u.id
                    FROM {user} u
                    $studentsjoins->joins
                    WHERE $studentsjoins->wheres";
            $hasstudents = $DB->record_exists_sql($sql, $studentsjoins->params);
        }
        if ($options->attempts == self::ALL_WITH) {
            // This option is only available to users who can access all groups
            // in groups mode, so setting allowed to empty (which means all
            // quiz attempts are accessible, is not a security problem.
            $allowedjoins = new \core\dml\sql_join();
        }

        $this->process_actions($quiz, $cm, $currentgroup, $groupstudentsjoins,
            $allowedjoins, $options->get_url());

        // Start output.
        if (!$table->is_downloading()) {
            // Only print headers if not asked to download data.
            $this->print_header_and_tabs($cm, $course, $quiz, $this->mode);
        }

        if ($groupmode = groups_get_activity_groupmode($cm)) {
            // Groups are being used, so output the group selector if we are
            // not downloading.
            if (!$table->is_downloading()) {
                $group_menu = groups_print_activity_menu($cm, $options->get_url(), true, false);
            }
        }

        // Print information on the number of existing attempts.
        if (!$table->is_downloading()) {
            // Do not print notices when downloading.
            if ($strattemptnum = quiz_num_attempt_summary($quiz, $cm, true,
                $currentgroup)) {
                echo '<div class="quizattemptcounts">' . $strattemptnum .
                    '</div>';
            }
        }

        $hasquestions = quiz_has_questions($quiz->id);
        if (!$table->is_downloading()) {
            if (!$hasquestions) {
                echo quiz_no_questions_message($quiz, $cm, $this->context);
            } else if (!$hasstudents) {
                echo $OUTPUT->notification(get_string('nostudentsyet'));
            } else if ($currentgroup && !$this->hasgroupstudents) {
                echo $OUTPUT->notification(get_string('nostudentsingroup'));
            }

            // Print the display options.
            $this->form->display();
        }

        $hasstudents = $hasstudents && (!$currentgroup || $this->hasgroupstudents);
        if ($hasquestions && ($hasstudents || $options->attempts == self::ALL_WITH)) {

            list($fields, $from, $where, $params) = $table->base_sql($allowedjoins);

            $table->set_count_sql("SELECT COUNT(1) FROM $from WHERE $where", $params);

            $table->set_sql($fields, $from, $where, $params);

            if (!$table->is_downloading()) {
                // Print information on the grading method.
                if ($strattempthighlight = quiz_report_highlighting_grading_method(
                        $quiz, $this->qmsubselect, $options->onlygraded)) {
                    echo '<div class="quizattemptcounts">' . $strattempthighlight . '</div>';
                }
            }

            // Define table columns.
            $columns = array();
            $headers = array();

            $this->add_user_columns($table, $columns, $headers);

            $this->add_grade_columns($quiz, $options->usercanseegrades, $columns, $headers);

            foreach ($questions as $id => $question) {
                if ($options->showqtext) {
                    $columns[] = 'question' . $id;
                    $headers[] = get_string('questionx', 'question', $question->number);
                }
			if ($options->showresponses) {
                    $columns[] = 'response' . $id;
                    $headers[] = get_string('responsex', 'quiz_group', $question->number);
                }
                if ($options->showright) {
                    $columns[] = 'right' . $id;
                    $headers[] = get_string('rightanswerx', 'quiz_group', $question->number);
                }
            }

            $table->define_columns($columns);
            $table->define_headers($headers);
            $table->sortable(true, 'uniqueid');

            // Set up the table.
            $table->define_baseurl($options->get_url());

            $this->configure_user_columns($table);

            $table->no_sorting('feedbacktext');
            $table->column_class('sumgrades', 'bold');

            $table->set_attribute('id', 'group');

            $table->collapsible(true);

            $table->out($options->pagesize, true);
        }
        return true;
    }


    /**
     * In attemptsreport.php, but not downloading, no email, pics
     *
     * Add all the user-related columns to the $columns and $headers arrays.
     * @param table_sql $table the table being constructed.
     * @param array $columns the list of columns. Added to.
     * @param array $headers the columns headings. Added to.
     */
    protected function add_user_columns($table, &$columns, &$headers) {
        global $CFG;
	$columns[] = 'fullname';
	$headers[] = get_string('name');
    }
}
