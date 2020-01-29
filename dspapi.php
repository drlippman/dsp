<?php

require("../init_without_validate.php");

$basecid = '1345';  // course ID where data is stored
$term = 'DSPv1';    // Designator for DPS version
$gradeitemid = 1542;    // Offline grade ID to store values in 

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
    $stm->execute(array(':SID'=>$diagSID, ':password'=>'none', ':rights'=>10, 
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
    $query = "INSERT INTO imas_grades (gradetype,gradetypeid,refid,userid,score,feedback) ";
    $query .= "VALUES ('offline',?,?,?,?)";
    $stm = $DBH->prepare($query);
    $stm->execute(array($gradeitemid, $now, $userid, $score, $fb));
  } else {
    $query = "UPDATE imas_grades SET refid=?,score=?,feedback=CONCAT(feedback, ',', ?) WHERE id=?";
    $stm = $DBH->prepare($query);
    $stm->execute(array($now, $score, $fb, $gradeid));
  }
  echo "DONE";
}