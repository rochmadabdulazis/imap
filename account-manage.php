<?php
$notification = [];
// model
function addUserData($table, $data = [])
{
    if (empty($data)) return false;
    foreach ($data as $key => $value) {
        $col[] = $key;
        $val[] = $value;
    }
    $phCol = implode(",", $col);
    $ph = rtrim(str_repeat("?,", count($col)), ",");
    dbExec("INSERT INTO $table ($phCol) VALUES ($ph);", $val, $stmt);
    return $stmt->rowCount();
}
function getUserData($table, $col, $val)
{
    return dbQuery("SELECT * FROM $table WHERE $col = ?", [$val]);
}
function updateUserData($table, $src, $key, $col, $val)
{
    dbExec("UPDATE $table SET $col = ? WHERE $src = ?;", [$val, $key], $stmt);
    return $stmt->rowCount();
}
function deleteUserData($table, $src, $key)
{
    dbExec("DELETE FROM $table WHERE $src = ?", [$key], $stmt);
    return $stmt->rowCount();
}
function addNotif($stt, $msg)
{
    if ($stt) {
        $GLOBALS['notification']['success'][] = $msg;
    } else {
        $GLOBALS['notification']['errors'][] = $msg;
    }
}
function displayNotif()
{
    global $notification;
    $success = $errors = "";
    if (empty($notification)) return '';
    if (!empty($notification['success'])){
        $success = handlerMsg($notification['success']);
        $success = '<div class="success">'. $success .'</div>';
    }
    if (!empty($notification['errors'])){
        $errors = handlerMsg($notification['errors']);
        $errors = '<div class="errors">'. $errors .'</div>';
    }
    $return = <<<DATA
<div class="notification">
$success
$errors
</div>
DATA;
return $return;
}
function handlerMsg($array = [])
{
    if (empty($array)) return "";
    $return = '';
    foreach ($array as $value) {
        $return .= '<p>'. $value .'</p>';
    }
    return $return;
}
function errorsValidate()
{
    global $notification;
    return (!isset($notification['errors'])) ? true : false;
}
function saveSession($val)
{
    setcookie("mailbox_uid", $val, time() + strtotime("+1 year"), "/");
}
function getSession(&$session)
{
    if (isset($_COOKIE['mailbox_uid'])) {
        $session = $_COOKIE['mailbox_uid'];
    } else {
        $session = false;
    }
}
function deleteSession()
{
    setcookie("mailbox_uid", "", time() - 3600, "/");
}
function authZone($zone)
{
    getSession($session);
    if ($zone) {
        if ($session == false) referer('index.php');
    } else {
        if ($session !== false) referer('selectMailbox.php');
    }
}
function referer($loc)
{
    header("Location: ". $loc);
    exit;
}

// view
function pageAddUser()
{
    authZone(0);
    $uname = $passwd = "";
    if (isset($_POST['username']) and isset($_POST['password'])) {
        $uname = strtolower($_POST['username']);
        $passwd = $_POST['password'];

        if (!preg_match('/^\w{6,20}$/', $uname)) addNotif(0, "Username only text, number, _ and 6 - 20 characters");
        if (strlen($passwd) < 6 or strlen($passwd) > 20) addNotif(0, "Set Password 6 - 20 characters");
    }
    if (errorsValidate()) {
        $userAuth = getUserData('users', 'username', $uname);
        if (isset($_POST['action']) and $_POST['action'] === "LOGIN") {
            if (empty($userAuth)) addNotif(0, "Username not found");
            if (isset($userAuth[0]['passwd']) and $userAuth[0]['passwd'] != md5($passwd)) addNotif(0, "Password not match");
            if (errorsValidate()) {
                saveSession($userAuth[0]['id']);
                referer("info.php");
            }
        } elseif (isset($_POST['action']) and $_POST['action'] === "REGISTER") {
            if (!empty($userAuth)) addNotif(0, "Username already exist");
            if (errorsValidate()) {
                $save = addUserData('users', ['username'=>$uname, 'passwd'=>md5($passwd)]);
                if ($save > 0) {
                    addNotif(1, "Register success");
                } else {
                    addNotif(0, "Failed register");
                }
            }
        }
    }
    $notif = displayNotif();
$form = <<<DATA
<div>
    <div>
        <h1>MAILBOX</h1>
        <form action="" method="post">
            <p><input type="text" name="username" placeholder="Username" autocomplete="off" autofocus required value="$uname"></p>
            <p><input type="password" name="password" placeholder="Password" autocomplete="off" required value="$passwd"></p>
            <p><input type="submit" name="action" value="LOGIN"> <input type="submit" name="action" value="REGISTER"></p>
        </form>
    </div>
    $notif
</div>
DATA;
printHtml('Mailbox', $form);
}
function pageSelectMailbox()
{
    authZone(1);
    getSession($session);
    $mailbox = getUserData('mailbox_accounts', 'owner', $session);
    if (empty($mailbox)) referer('addMailbox.php');
}
function pageManageMailbox($edit = null)
{
    authZone(1);
    getSession($session);
    $dn = $email = $passwd = "";

    if (!is_null($edit)) {
        $editData = getUserData('mailbox_accounts', 'id', $edit);
        if (!empty($editData) and $session == $editData[0]['owner']) {
            $dn = $editData[0]['displayName'];
            $email = $editData[0]['email'];
            $passwd = $editData[0]['passwd'];
            $owner = $editData[0]['owner'];
        } else {
            referer('addMailbox.php');
        }
    }

    if (isset($_POST['displayName']) and isset($_POST['email']) and isset($_POST['password'])) {
        $dn = $_POST['displayName'];
        $email = strtolower($_POST['email']);
        $passwd = $_POST['password'];

        if (strlen($dn) < 2 or strlen($dn) > 20) addNotif(0, "Display name 2 - 20 characters");
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) addNotif(0, "Wrong format email");
        if (strlen($passwd) < 6 or strlen($passwd) > 20) addNotif(0, "Set password 6 - 20 characters");
        if (errorsValidate()) {
            if (is_null($edit)) {
                $add = addUserData('mailbox_accounts', ['owner'=>$session, 'displayName'=>$dn, 'email'=>$email, 'passwd'=>$passwd]);
                if ($add > 0) {
                    addNotif(1, "Add mailbox success");
                    $dn = $email = $passwd = "";
                } else {
                    addNotif(0, "Error add mailbox");
                }
            } elseif (!is_null($edit) and isset($owner) and $owner == $session) {
                updateUserData('mailbox_accounts', 'id', $edit, 'displayName', $dn);
                updateUserData('mailbox_accounts', 'id', $edit, 'email', $email);
                updateUserData('mailbox_accounts', 'id', $edit, 'passwd', $passwd);
                addNotif(1, "Saved");
            }
        }
    }

    $form = formMailbox($dn, $email, $passwd, $edit);

    $page = '<div>'.$form. displayNotif() .'</div>';
printHtml('Manage Mailbox', $page);
}

// theme
function printHtml($title, $body)
{
echo <<<DATA
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>$title</title>
</head>
<body>
    $body
</body>
</html>
DATA;
}
function formMailbox($dn, $email, $passwd, $edit = null)
{
    $title = (is_null($edit)) ? 'Add' : 'Edit';
    return <<<DATA
    <div>
        <h1>$title Mailbox</h1>
        <form action="" method="post">
            <p><input type="text" name="displayName" placeholder="Display name" autofocus required value="$dn"></p>
            <p><input type="email" name="email" placeholder="Email" required value="$email"></p>
            <p><input type="password" name="password" placeholder="Password" required value="$passwd"></p>
            <p><input type="submit" value="Save"></p>
        </form>
    </div>
DATA;
}
?>