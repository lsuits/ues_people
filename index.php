<?php

require_once '../../config.php';
require_once $CFG->dirroot . '/enrol/ues/publiclib.php';
ues::require_daos();

require_once 'lib.php';

if (!defined('DEFAULT_PAGE_SIZE')) {
    define('DEFAULT_PAGE_SIZE', 20);
}

$page = optional_param('page', 0, PARAM_INT);
$perpage = optional_param('perpage', DEFAULT_PAGE_SIZE, PARAM_INT);
$roleid = optional_param('roleid', 0, PARAM_INT);
$groupid = optional_param('groupid', 0, PARAM_INT);
$meta = optional_param('meta', 'lastname', PARAM_TEXT);
$sortdir = optional_param('dir', 'ASC', PARAM_TEXT);

$silast = optional_param('silast', 'all', PARAM_TEXT);
$sifirst = optional_param('sifirst', 'all', PARAM_TEXT);

$id = required_param('id', PARAM_INT);

$PAGE->set_url('/blocks/ues_people/index.php', array(
    'id' => $id,
    'roleid' => $roleid,
    'groupid' => $groupid,
    'page' => $page,
    'perpage' => $perpage
));

$PAGE->set_pagelayout('incourse');

$course = $DB->get_record('course', array('id' => $id), '*', MUST_EXIST);

require_login($course);

$context = get_context_instance(CONTEXT_COURSE, $id);

require_capability('moodle/course:viewparticipants', $context);

$_s = ues::gen_str('block_ues_people');

$user = ues_user::get(array('id' => $USER->id));

$allroles = get_all_roles();
$roles = ues_people::ues_roles();

$allrolenames = array();
$rolenames = array(0 => get_string('allparticipants'));
foreach ($allroles as $role) {
    $allrolenames[$role->id] = strip_tags(role_get_name($role, $context));
    if (isset($roles[$role->id])) {
        $rolenames[$role->id] = $allrolenames[$role->id];
    }
}

if (empty($rolenames[$roleid])) {
    print_error('noparticipants');
}

add_to_log($course->id, 'ues_people', 'view all', 'index.php?id='.$course->id, '');

$all_sections = ues_section::from_course($course);

$meta_names = ues_people::outputs();

$using_meta_sort = $using_ues_sort = false;

if (($meta == 'section' or $meta == 'credit_hours') and isset($meta_names[$meta])) {
    $using_ues_sort = true;
} else if (isset($meta_names[$meta])) {
    $using_meta_sort = true;
}

$PAGE->set_title("$course->shortname: " . get_string('participants'));
$PAGE->set_heading($course->fullname);
$PAGE->set_pagetype('course-view-' . $course->format);

$select = 'SELECT u.id, u.firstname, u.lastname, u.email, ues.sec_number, u.deleted,
                  u.picture, u.imagealt, u.lang, u.timezone, ues.credit_hours';
$joins = array('FROM {user} u');

list($ccselect, $ccjoin) = context_instance_preload_sql('u.id', CONTEXT_USER, 'ctx');

$select .= $ccselect;
$joins[] = $ccjoin;

list($esql, $params) = get_enrolled_sql($context, '', $groupid);

$joins[] = "JOIN ($esql) e ON e.id = u.id";

$unions = array();

$selects = array(
    't' =>
    'SELECT t.userid, t.sectionid, NULL AS sec_number, NULL AS credit_hours
        FROM '.ues_teacher::tablename('t').' WHERE ',
    'stu' =>
    'SELECT stu.userid, stu.sectionid, sec.sec_number, stu.credit_hours
        FROM '.ues_student::tablename('stu').'
        JOIN '.ues_section::tablename('sec').' ON sec.id = stu.sectionid WHERE '
);

$sectionids = array_keys($all_sections);

foreach ($selects as $key => $union) {
    $union_where = ues::where()
        ->sectionid->in($sectionids)
        ->status->in(ues::ENROLLED, ues::PROCESSED);

    $unions[$key] = '(' . $union . $union_where->sql(function($k) use ($key) {
        return $key . '.' . $k;
    }) . ' GROUP BY userid)';
}

$joins[] = 'JOIN ('. implode(' UNION ', $unions) . ') AS ues ON ues.userid = u.id';

if ($using_meta_sort) {
    $meta_table = ues_user::metatablename('um');
    $joins[] = 'LEFT JOIN ' . $meta_table. ' ON (
        um.userid = u.id AND um.name = :metakey
    )';
    $params['metakey'] = $meta;
}

$from = implode("\n", $joins);

$wheres = ues::where()->sectionid->in($sectionids);

if ($sifirst != 'all') {
    $wheres->firstname->starts_with($sifirst);
}

if ($silast != 'all') {
    $wheres->lastname->starts_with($silast);
}

if ($roleid) {
    $contextlist = get_related_contexts_string($context);

    $sub = 'SELECT userid FROM {role_assignments}
        WHERE roleid = :roleid AND context ' . $contextlist;

    $wheres->id->raw("IN ($sub)");
}

$where = $wheres->is_empty() ? '' : 'WHERE ' . $wheres->sql(function($k) {
    switch ($k) {
        case 'sectionid': return 'ues.' . $k;
        default: return 'u.' . $k;
    }
});

if ($using_meta_sort) {
    $sort = 'ORDER BY um.value ' . $sortdir;
} else if ($using_ues_sort) {
    $sort = 'ORDER BY ues.' . $meta . ' ' . $sortdir;
} else {
    $sort = 'ORDER BY u.' . $meta . ' ' . $sortdir;
}

$sql = "$select $from $where $sort";

$count = $DB->count_records_sql("SELECT COUNT(u.id) $from $where", $params);

echo $OUTPUT->header();

if ($roleid > 0) {
    $a->number = $count;
    $a->role = $rolenames[$roleid];

    $heading = format_string(get_string('xuserswiththerole', 'role', $a));

    if ($currentgroup and $group) {
        $a->group = $group->name;
        $heading .= ' ' . format_string(get_string('ingroup', 'role', $a));
    }

    $heading .= ": $a->number";
    echo $OUTPUT->heading($heading, 3);
} else {
    $strall = get_string('allparticipants');
    $sep = get_string('labelsep', 'langconfig');
    echo $OUTPUT->heading($strall . $sep . $count, 3);
}

$table = new html_table();

$headers = array(
    get_string('userpic'),
    get_string('firstname') . ' / ' . get_string('lastname'),
    get_string('email')
);

foreach ($meta_names as $output) {
    $headers[] = $output->name;
}

// Transform function to optimize table formatting
$to_row = function ($user) use ($OUTPUT, $meta_names, $id) {

    // Needed for user_picture
    $underlying = new stdClass;
    foreach (get_object_vars($user) as $field => $value) {
        $underlying->$field = $value;
    }

    // Needed for user meta
    $user->fill_meta();

    $line = array();
    $line[] = $OUTPUT->user_picture($underlying, array('courseid' => $id));
    $line[] = fullname($user);
    $line[] = $user->email;

    foreach ($meta_names as $output) {
        $line[] = $output->format($user);
    }

    return new html_table_row($line);
};

$table->head = $headers;
$table->data = ues_user::by_sql($sql, $params, $page, $perpage, $to_row);

echo html_writer::start_tag('div', array('class' => 'no-overflow'));
echo html_writer::table($table);
echo html_writer::end_tag('div');

echo $OUTPUT->footer();
