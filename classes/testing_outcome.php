<?php
// This file is part of CodeRunner - http://coderunner.org.nz/
//
// CodeRunner is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.
//
// CodeRunner is distributed in the hope that it will be useful,
// but WITHOUT ANY WARRANTY; without even the implied warranty of
// MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
// GNU General Public License for more details.
//
// You should have received a copy of the GNU General Public License
// along with CodeRunner.  If not, see <http://www.gnu.org/licenses/>.

/** Defines a testing_outcome class which contains the complete set of
 *  results from running all the tests on a particular submission.
 *
 * @package    qtype
 * @subpackage coderunner
 * @copyright  Richard Lobb, 2013, The University of Canterbury
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */


defined('MOODLE_INTERNAL') || die();
use qtype_coderunner\constants;

class qtype_coderunner_testing_outcome {
    const STATUS_VALID = 1;         // A full set of test results is returned.
    const STATUS_SYNTAX_ERROR = 2;  // The code (on any one test) didn't compile.
    const STATUS_BAD_COMBINATOR = 3; // A combinator template yielded an invalid result
    const STATUS_SANDBOX_ERROR = 4;  // The run failed altogether.

    const TOLERANCE = 0.00001;       // Allowable difference between actual and max marks for a correct outcome.

    public $status;                  // One of the STATUS_ constants above.
                                     // If this is not 1, subsequent fields may not be meaningful.
    public $errorcount;              // The number of failing test cases.
    public $errormessage;            // The error message to display if there are errors.
    public $maxpossmark;             // The maximum possible mark.
    public $actualmark;              // Actual mark (meaningful only if this is not an all-or-nothing question).
    public $testresults;             // An array of TestResult objects.
    public $sourcecodelist;          // Array of all test runs.

    public function __construct($maxpossmark, $numtestsexpected) {
        $this->status = self::STATUS_VALID;
        $this->errormessage = '';
        $this->errorcount = 0;
        $this->actualmark = 0;
        $this->maxpossmark = $maxpossmark;
        $this->numtestsexpected = $numtestsexpected;
        $this->testresults = array();
        $this->sourcecodelist = null;     // Array of all test runs on the sandbox.
    }

    public function set_status($status, $errormessage='') {
        $this->status = $status;
        $this->errormessage = $errormessage;
    }

    public function iscombinatorgrader() {
        return false;
    }

    public function run_failed() {
        return $this->status === self::STATUS_SANDBOX_ERROR;
    }

    public function has_syntax_error() {
        return $this->status === self::STATUS_SYNTAX_ERROR;
    }

    public function combinator_error() {
        return $this->status === self::STATUS_BAD_COMBINATOR;
    }

    public function is_ungradable() {
        return $this->run_failed() || $this->combinator_error();
    }

    public function mark_as_fraction() {
        // Need to return exactly 1.0 for a right answer.
        $fraction = $this->actualmark / $this->maxpossmark;
        return abs($fraction - 1.0) < self::TOLERANCE ? 1.0 : $fraction;
    }

    public function all_correct() {
        return $this->mark_as_fraction() === 1.0;
    }

    // True if the number of tests does not equal the number originally
    // expected, meaning that testing was aborted.
    public function was_aborted() {
        return count($this->testresults) != $this->numtestsexpected;
    }


    public function add_test_result($tr) {
        $this->testresults[] = $tr;
        $this->actualmark += $tr->awarded;
        if (!$tr->iscorrect) {
            $this->errorcount++;
        }
    }

    // Return a message summarising the nature of the error if this outcome
    // is not all correct.
    public function validation_error_message() {
        if ($this->run_failed()) {
            return get_string('run_failed', 'qtype_coderunner');
        } else if ($this->has_syntax_error()) {
            return get_string('syntax_errors', 'qtype_coderunner') . html_writer::tag('pre', $this->errormessage);
        } else if ($this->combinator_error()) {
            return get_string('badquestion', 'qtype_coderunner') . html_writer::tag('pre', $this->errormessage);
        } else {
            if ($this->iscombinatorgrader()) {
                $message = get_string('failedtesting', 'qtype_coderunner'); // Combinator graders are too hard!
            } else {
                $numerrors = 0;
                $firstfailure = '';
                if (isset($this->testresults)) { // Combinator graders may not have test results.
                    foreach ($this->testresults as $testresult) {
                        if (!$testresult->iscorrect) {
                            $numerrors += 1;
                            if ($firstfailure === '' && isset($testresult->expected) && isset($testresult->got)) {
                                $errorhtml = $this->make_error_html($testresult->expected, $testresult->got);
                                $firstfailure = get_string('firstfailure', 'qtype_coderunner', $errorhtml);
                            }
                        }
                    }
                    $message = get_string('failedntests', 'qtype_coderunner', array(
                        'numerrors' => $numerrors));
                    if ($firstfailure) {
                        $message .= html_writer::empty_tag('br') . $firstfailure;
                    };
                }
            }
            return $message . html_writer::empty_tag('br') . get_string('howtogetmore', 'qtype_coderunner');
        }
    }

    /**
     *
     * @global type $COURSE
     * @param qtype_coderunner $question
     * @return a table of test results.
     * The test result table is an array of table rows (each an array).
     * The first row is a header row, containing strings like 'Test', 'Expected',
     * 'Got' etc. Other rows are the values of those items for the different
     * tests that were run.
     * There are two special case columns. If the header is 'iscorrect', the
     * value in the row should be 0 or 1. The header of this column is left blank
     * and the row contents are replaced by a tick or a cross. There can be
     * multiple iscorrect columns. If the header is
     * 'ishidden', the column is not displayed but instead the row itself is
     * hidden from view unless the user has the grade:viewhidden capability.
     *
     * The set of columns to be displayed is specified by the question's
     * resultcolumns variable. This is a JSON-encoded list of column specifiers.
     * A column specifier is itself a list, usually with 2 or 3 elements.
     * The first element is the column header the second is (usually) the test
     * result object field name whose value is to be displayed in the column
     * and the third (optional) element is the sprintf format used to display
     * the field. It is also possible to combine more than one field of the
     * test result object into a single field by adding extra field names into
     * the column specifier before the format, which is then mandatory.
     * For example, to display the mark awarded for a test case as, say
     * '0.71 out of 1.00' the column specifier would be
     * ["Mark", "awarded", "mark", "%.2f out of %.2f"] A special case format
     * specifier is '%h' denoting that the result object field value should be
     * treated as ready-to-output html. Empty columns are suppressed.
     */
    protected function build_results_table(qtype_coderunner_question $question) {

        global $COURSE;

        if (isset($question->resultcolumns) && $question->resultcolumns) {
            $resultcolumns = json_decode($question->resultcolumns);
        } else {
            // Use default column headers, equivalent to json_decode of (in English):
            // '[["Test", "testcode"], ["Input", "stdin"], ["Expected", "expected"], ["Got", "got"]]'.
            $resultcolumns = array(
                array(get_string('testcolhdr', 'qtype_coderunner'), 'testcode'),
                array(get_string('inputcolhdr', 'qtype_coderunner'), 'stdin'),
                array(get_string('expectedcolhdr', 'qtype_coderunner'), 'expected'),
                array(get_string('gotcolhdr', 'qtype_coderunner'), 'got'),
            );
        }
        if ($COURSE && $coursecontext = context_course::instance($COURSE->id)) {
            $canviewhidden = has_capability('moodle/grade:viewhidden', $coursecontext);
        } else {
            $canviewhidden = false;
        }

        // Build the table header, containing all the specified field headers,
        // unless all rows in that column would be blank.

        $columnheaders = array('iscorrect'); // First column is a tick or cross, like last column.
        $hiddencolumns = array();  // Array of true/false for each element of $colspec.
        $numvisiblecolumns = 0;

        foreach ($resultcolumns as $colspec) {

            $len = count($colspec);
            if ($len < 3) {
                $colspec[] = '%s';  // Add missing default format.
            }
            $header = $colspec[0];
            $field = $colspec[1];  // Primary field - there may be more.
            $numnonblank = self::count_non_blanks($field, $this->testresults);
            if ($numnonblank == 0) {
                $hiddencolumns[] = true;
            } else {
                $columnheaders[] = $header;
                $hiddencolumns[] = false;
                $numvisiblecolumns += 1;
            }
        }
        if ($numvisiblecolumns > 1) {
            $columnheaders[] = 'iscorrect';  // Tick or cross at the end, unless <= 1 visible columns.
        }
        $columnheaders[] = 'ishidden';   // Last column controls if row hidden or not.

        $table = array($columnheaders);

        // Process each row of the results table.
        $hidingrest = false;
        foreach ($this->testresults as $testresult) {
            $testisvisible = $this->should_display_result($testresult) && !$hidingrest;
            if ($canviewhidden || $testisvisible) {
                $fraction = $testresult->awarded / $testresult->mark;
                $tablerow = array($fraction);   // Will be rendered as tick or cross.
                $icol = 0;
                foreach ($resultcolumns as $colspec) {
                    $len = count($colspec);
                    if ($len < 3) {
                        $colspec[] = '%s';  // Add missing default format.
                    }
                    if (!$hiddencolumns[$icol]) {
                        $len = count($colspec);
                        $format = $colspec[$len - 1];
                        if ($format === '%h') {  // If it's an html format, use value wrapped in an HTML wrapper.
                            $value = $testresult->gettrimmedvalue($colspec[1]);
                            $tablerow[] = new qtype_coderunner_html_wrapper($value);
                        } else if ($format !== '') {  // Else if it's a non-null column.
                            $args = array($format);
                            for ($j = 1; $j < $len - 1; $j++) {
                                $value = $testresult->gettrimmedvalue($colspec[$j]);
                                $args[] = $value;
                            }
                            $content = call_user_func_array('sprintf', $args);
                            $tablerow[] = $content;
                        }
                    }
                    $icol += 1;
                }
                if ($numvisiblecolumns > 1) { // Suppress trailing tick or cross in degenerate case.
                    $tablerow[] = $fraction;
                }
                $tablerow[] = !$testisvisible;
            }

            if ($testresult->hiderestiffail && !$testresult->iscorrect) {
                $hidingrest = true;
            }

            $table[] = $tablerow;
        }

        return $table;
    }


    // Count the number of errors in hidden testcases, given the array of
    // testresults.
    public function count_hidden_errors() {
        $count = 0;
        $hidingrest = false;
        foreach ($this->testresults as $tr) {
            if ($hidingrest) {
                $isdisplayed = false;
            } else {
                $isdisplayed = $this->should_display_result($tr);
            }
            if (!$isdisplayed && !$tr->iscorrect) {
                $count++;
            }
            if ($tr->hiderestiffail && !$tr->iscorrect) {
                $hidingrest = true;
            }
        }
        return $count;
    }


    // True iff the given test result should be displayed.
    protected static function should_display_result($testresult) {
        return !isset($testresult->display) ||  // E.g. broken combinator template?
             $testresult->display == 'SHOW' ||
            ($testresult->display == 'HIDE_IF_FAIL' && $testresult->iscorrect) ||
            ($testresult->display == 'HIDE_IF_SUCCEED' && !$testresult->iscorrect);
    }


    // Support function to count how many objects in the given list of objects
    // have the given 'field' attribute non-blank. Non-existent fields are also
    // included in order to generate a column showing the error, but null values.
    protected static function count_non_blanks($field, $objects) {
        $n = 0;
        foreach ($objects as $obj) {
            if (!property_exists($obj, $field) ||
                (!is_null($obj->$field) && !is_string($obj->$field)) ||
                (is_string($obj->$field) && trim($obj->$field !== ''))) {
                $n++;
            }
        }
        return $n;
    }


    /**
     * Make an HTML table describing a single failing test case
     * @param string $expected the expected output from the test
     * @param string $got the actual output from the test
     */
    protected static function make_error_html($expected, $got) {
        $table = new html_table();
        $table->attributes['class'] = 'coderunner-test-results';
        $table->head = array(get_string('expectedcolhdr', 'qtype_coderunner'),
                             get_string('gotcolhdr', 'qtype_coderunner'));
        $table->data = array(array(html_writer::tag('pre', $expected), html_writer::tag('pre', $got)));
        return html_writer::table($table);
    }


    // Getter methods for use by renderer.
    // ==================================.

    public function get_test_results(qtype_coderunner_question $q) {
        return $this->build_results_table($q);
    }

    // Called only in case of precheck == 1, and no errors.
    public function get_raw_output() {
        assert(count($this->testresults) === 1);
        $testresult = $this->testresults[0];
        assert(empty($testresult->stderr));
        return $testresult->got;
    }

    public function get_prologue() {
        return '';
    }

    public function get_epilogue() {
        return '';
    }

    public function get_sourcecode_list() {
        return $this->sourcecodelist;
    }

    public function get_error_count() {
        return $this->errorcount;
    }
}