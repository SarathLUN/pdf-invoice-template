<?php
date_default_timezone_set("Asia/Phnom_Penh");
parse_str($argv[1], $output);

$base_path = $output['path']; // for production

$current_day = $output['day']; // for production

$dbserver = "host";
$dbusername = "user";
$dbpassword = "password";
$defaultdb = "db";



$conn = mssql_connect($dbserver, $dbusername, $dbpassword) or die("Connection Error");
$db = mssql_select_db($defaultdb, $conn) or die("Database Error");



$query1 = "exec wfa_audit_report '" . $current_day . "'";
$rs = mssql_query($query1, $conn);


$arr_invoice_id = array();
$arr_customer_id = array();
$arr_net_value = array();
$arr_vat = array();
$arr_value = array();
$data = "";
while ($row = mssql_fetch_object($rs)) {
    $id = $row->id;
    $arr_invoice_id[] = $id;
    $arr_customer_id[$id] = $row->customer_id;
    $arr_net_value[$id] = $row->net_value;
    $arr_vat[$id] = $row->vat;
    $arr_value[$id] = $row->value;
}
$service = getSubscriptionPlan(); // call subscription plan
foreach ($arr_invoice_id as $id) {
    $query2 = "exec invoice_print $id,2,1 ";
    $rs2 = mssql_query($query2, $conn);
    $subfee = "";
    $subfee_vat="";
    $subfee_after_vat="";

    while ($r = mssql_fetch_object($rs2)) {
        $description = $r->description;
        $des = str_replace('<b>', '', $description);
        $des = str_replace('</b>', '', $des);
        $des = str_replace('</b', '', $des);
        $des = str_replace('<i>', '', $des);
        $des = str_replace('</i>', '', $des);
        if ($des == '&nbsp;') {
            continue;
        }
        $value = $r->price;
        $sname = 'null';

        if (strpos($description, 'Sub Total') !== false) {

            continue;
        } else if (strpos($description, 'Invoice value before VAT') !== false) {
            continue;
        } else if (strpos($description, 'VAT Charge') !== false) {
            $subfee_vat .= trim($des) . '[$' . trim($value) . '];';
            continue;
        } else if (strpos($description, 'Invoice value') !== false || strpos($description, 'Amount due after VAT') !== false) {
            $subfee_after_vat .= trim($des) . '[$' . trim($value) . ']}';
            continue;
        } else if (strpos($description, 'ID:') !== false) {
            $sname = trim($des);

            $string = $sname;
            $len = strlen($string);
            $sid = "";
            for ($i = $len - 2; $i >= 0; $i--) {
                if ($string[$i] == ' ') {
                    break;
                }
                $sid = $string[$i] . $sid;
            }
            $sname = trim($service[$sid]);

            $subfee .= 'ID: ' . $sid . $sname . '{';

        } else if (strpos($description, 'Payments made') !== false) {
            continue;
        } else if (strpos($description, 'Balance Due') !== false) {
            continue;
        } else if (trim($description) == "") {
            continue;
        } else {
            $subfee .= trim($des) . '[$' . trim($value) . '];';
        }
    }
    $subfee = $subfee . $subfee_vat . $subfee_after_vat;

    $line = $id . ' , ' . $arr_net_value[$id] . ' , ' . $arr_vat[$id] . ' , ' . $arr_value[$id] . ' , ' . $arr_customer_id[$id] . ' , ' . trim($subfee);
    $data .= $line . "\n";
}
$header = 'inv_id' . ',' . 'net_value' . ' , ' . 'vat' . ' , ' . 'inv_value' . ' , ' . 'customer_id' . ' , ' . 'description';
$content = $header . "\n" . $data;


create_report($content, $base_path, $current_day);

function getSubscriptionPlan()
{
    global $conn;
    $query3 = "exec get_subscription_plan";
    $rs3 = mssql_query($query3, $conn);
    $service = array();
    while ($row = mssql_fetch_object($rs3)) {
        $service[$row->id] = '(' . trim($row->spname) . ')';
    }
    return $service;
}


function create_report($content, $base_path, $current_day)
{
    $file = 'inv' . str_replace('.', '-', $current_day) . '.csv';
    $file_path = $base_path . $file;
    if (!file_exists($file_path)) {
        touch($file_path);

    }
    file_put_contents($file_path, $content);
}

mssql_close($conn); 


