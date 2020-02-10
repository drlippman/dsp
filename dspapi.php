<?php

require("../init_without_validate.php");

$basecid = 22891;  // course ID where data is stored
$term = 'DSPv1';    // Designator for DPS version
$gradeitemid = 24985;    // Offline grade ID to store values in
$processor = 'dlippman@pierce.ctc.edu';  // receivor of DSP scores

$sid = preg_replace('/\W/','',$_POST['sid']);
if ($sid == '') {
  echo "ERROR";
  exit;
}
$diagsid = $sid.'~'.$basecid.'~'.$term;
$now = time();

if (isset($_POST['checksid'])) {
  $sid = $_POST['sid'];

  $query = "SELECT iu.id,ig.refid FROM imas_users AS iu LEFT JOIN imas_grades AS ig ";
  $query .= "ON ig.userid=iu.id AND ig.gradetype='offline' AND ig.gradetypeid=? ";
  $query .= "WHERE iu.SID=?";
  $stm = $DBH->prepare($query);
  $stm->execute(array($gradeitemid, $diagsid));

  $hasgrade = false;
  if ($stm->rowcount() > 0) {
    $lastdate = $stm->fetchColumn(1);
    if ($now - $lastdate > 24*60*60) {
      $hasgrade = true;
    }
  }

  echo $hasgrade ? 'ERROR' : 'OK';
  exit;
} else if (isset($_POST['email'])) {
  require_once('../includes/email.php');
  require_once("../includes/htmLawed.php");
  $rec = myhtmLawed($_POST['rec']);
  $email = Sanitize::emailAddress($_POST['email']);
  if ($email === false || trim($email) == '' || $rec == '') {
    return 'ERROR';
  } else {
    send_email($email, $sendfrom, 'Pierce Directed Self Placement', $rec);
    echo "DONE";
  }
  exit;
} else if (isset($_POST['record'])) {
  $query = "SELECT iu.id,ig.id AS gradeid,ig.refid FROM imas_users AS iu LEFT JOIN imas_grades AS ig ";
  $query .= "ON ig.userid=iu.id AND ig.gradetype='offline' AND ig.gradetypeid=? ";
  $query .= "WHERE iu.SID=?";
  $stm = $DBH->prepare($query);
  $stm->execute(array($gradeitemid, $diagsid));

  $row = $stm->fetch(PDO::FETCH_ASSOC);

  if (empty($row)) { // no record at all, create student
    $query = "INSERT INTO imas_users (SID, password, rights, FirstName, LastName, email, lastaccess) ";
    $query .= "VALUES (:SID, :password, :rights, :FirstName, :LastName, :email, :lastaccess);";
    $stm = $DBH->prepare($query);
    if (!isset($_POST['passwd'])) {
      $_POST['passwd'] = "none";
    }
    $stm->execute(array(':SID'=>$diagsid, ':password'=>'none', ':rights'=>10,
      ':FirstName'=>$_POST['firstname'], ':LastName'=>$_POST['lastname'],
      ':email'=>'@', ':lastaccess'=>$now
      ));
    $userid = $DBH->lastInsertId();
    $gradeid = null;

    $stm = $DBH->prepare("INSERT INTO imas_students (userid,courseid) VALUES (:userid, :courseid);");
    $stm->execute(array(':userid'=>$userid, ':courseid'=>$basecid));

  } else if (!empty($row['gradeid']) && ($now - $row['refid']) > 24*60*60) {
    echo "ERROR: Already has a recorded score";
    exit;
  } else {
    $userid = $row['id'];
    $gradeid = $row['gradeid'];

    $stm = $DBH->prepare("SELECT id FROM imas_students WHERE courseid=? and userid=?");
    $stm->execute(array($basecid, $userid));
    if ($stm->rowCount()==0) { //stu has been unenrolled; reenroll
      $stm = $DBH->prepare("INSERT INTO imas_students (userid,courseid) VALUES (:userid, :courseid);");
      $stm->execute(array(':userid'=>$userid, ':courseid'=>$basecid));
    }
  }

  $score = intval($_POST['placement']);
  $fb = $_POST['values'];

  if (empty($gradeid)) { // new grade
    $emailsubject = 'Pierce DSP Score';
    $query = "INSERT INTO imas_grades (gradetype,gradetypeid,refid,userid,score,feedback) ";
    $query .= "VALUES ('offline',?,?,?,?,?)";
    $stm = $DBH->prepare($query);
    $stm->execute(array($gradeitemid, $now, $userid, $score, $fb));
  } else {
    $emailsubject = 'Revised Pierce DSP Score';
    $query = "UPDATE imas_grades SET refid=?,score=?,feedback=CONCAT(feedback, ',', ?) WHERE id=?";
    $stm = $DBH->prepare($query);
    $stm->execute(array($now, $score, $fb, $gradeid));
  }

  $message = '<h2>Pierce Math Directed Self Placement Result</h2><p>';
  $message .= "Name: " . $_POST['lastname'] . ', ' . $_POST['firstname'] . ' ' . $_POST['middlename'] . '<br/>';
  $message .= "Student ID: " . $_POST['sid'] . '<br/>';
  $message .= "Birth Date: " . $_POST['birthday'] . '<br/>';
  $message .= "Telephone: " . $_POST['tel'] . '<br/>';
  $message .= "Placement: " . $score . '</p>';

  require_once('../includes/email.php');
  send_email($processor, $sendfrom, $emailsubject, $message);


  echo "DONE";
}
