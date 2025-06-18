<?php
if (isset($dsn) and !empty($dsn)) {
    try {
        $storage = new PDO($dsn, $mysqlUser, $mysqlPasswd);
        $storage->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    } catch (PDOException $e) {
        echo $e->getMessage();
    }
}

function dbQuery($sql, $params = [])
{
    global $storage;
    $stmt = $storage->prepare($sql);
    $stmt->execute($params);
    $return = $stmt->fetchAll(PDO::FETCH_ASSOC);
    return $return;
}
function dbExec($sql, $params = [], &$stmt = null)
{
    global $storage;
    $stmt = $storage->prepare($sql);
    return $stmt->execute($params);
}