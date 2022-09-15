<?php

error_reporting(E_ALL);
ini_set('display_errors', 1);

/*
if (isset($_SERVER['REMOTE_ADDR'])) {
    header('HTTP/1.0 403 Forbidden');
    die('No Permission');
}
*/

$outputdatas = [];
$imgparam = [];
$host = 'https://rgsntl.rgs.cuhk.edu.hk/aqs_prd_applx/Public/tt_dsp_crse_catalog.aspx';
date_default_timezone_set('Asia/Hong_Kong');


$html = file_get_contents($host, 0, stream_context_create(["http" => ["timeout" => 20]]));
if ($html === false) die('fetch');

//Get Input Fields
$dom = new DOMDocument();
libxml_use_internal_errors(true);
$dom->loadHTML($html);
foreach ((new DOMXPath($dom))->query('//input') as $html) {
    try {
        //Save to Array
        $outputdatas[$html->getAttribute('name')] = $html->getAttribute('value');
    } catch (Exception $e) {
    }
}
$outputdatas["__EVENTTARGET"] = "";
$outputdatas["__EVENTARGUMENT"] = "";
unset($outputdatas["btn_refresh"]);

//Get Cookie & Init Fetch
$ch = curl_init($host);
curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
curl_setopt($ch, CURLOPT_HEADER, 1);
$result = curl_exec($ch);
preg_match_all('/^Set-Cookie:\s*([^;]*)/mi', $result, $matches);
$cookies = array();
foreach ($matches[1] as $item) {
    parse_str($item, $cookie);
    $cookies = array_merge($cookies, $cookie);
}
$SESSIONID = $cookies["ASP_NET_SessionId"];
$browserheader = [
    'Host: rgsntl.rgs.cuhk.edu.hk',
    'Cache-Control: max-age=0',
    'Sec-Ch-Ua: "Chromium";v="105", "Not)A;Brand";v="8"',
    'Sec-Ch-Ua-Mobile: ?0',
    'Sec-Ch-Ua-Platform: "macOS"',
    'Upgrade-Insecure-Requests: 1',
    'Origin: https://rgsntl.rgs.cuhk.edu.hk',
    'Content-Type: application/x-www-form-urlencoded',
    'User-Agent: Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/105.0.5195.102 Safari/537.36',
    'Accept: text/html,application/xhtml+xml,application/xml;q=0.9,image/avif,image/webp,image/apng,*/*;q=0.8,application/signed-exchange;v=b3;q=0.9',
    'Sec-Fetch-Site: same-origin',
    'Sec-Fetch-Mode: navigate',
    'Sec-Fetch-User: ?1',
    'Sec-Fetch-Dest: document',
    'Referer: https://rgsntl.rgs.cuhk.edu.hk/aqs_prd_applx/Public/tt_dsp_crse_catalog.aspx',
    'Accept-Encoding: gzip, deflate',
    'Accept-Language: zh-TW,zh;q=0.9,en-US;q=0.8,en;q=0.7',
    'Cookie: PS_DEVICEFEATURES=maf:0 width:1728 height:1117 clientWidth:1200 clientHeight:871 pixelratio:2 touch:0 geolocation:1 websockets:1 webworkers:1 datepicker:1 dtpicker:1 timepicker:1 dnd:1 sessionstorage:1 localstorage:1 history:1 canvas:1 svg:1 postmessage:1 hc:0; ASP.NET_SessionId=' . $SESSIONID,
];


//Get Subject List
foreach ((new DOMXPath($dom))->query('//option') as $html) {
    if ($html->getAttribute('value'))
        $courses[$html->getAttribute('value')] = $html->textContent;
}

//Get Verification Code Image
foreach ((new DOMXPath($dom))->query('//img[contains(@id, "imgCaptcha")]') as $html) {
    $imgcode = explode("?", $html->getAttribute('src'));
}
foreach (explode("&", $imgcode[1]) as $param) {
    $imgparam[explode("=", $param)[0]] = explode("=", $param)[1];
}
$imgparam["len"] = 1;
$veriaddr = $imgcode[0] . "?captchaname=" . $imgparam["captchaname"] . "&len=" . $imgparam["len"];
$ch = curl_init();
curl_setopt($ch, CURLOPT_URL, dirname($host) . "/" . $veriaddr);
curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'GET');
curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
curl_setopt($ch, CURLOPT_HTTPHEADER, $browserheader);
$response = curl_exec($ch);
curl_close($ch);

//Force-brute Veri code
$ch = curl_init();
curl_setopt($ch, CURLOPT_URL, $host);
curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'POST');
curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
curl_setopt($ch, CURLOPT_HTTPHEADER, $browserheader);
$verilist = array_merge(range('A', 'Z'), range('0', '9'));
$vericount = 0;
$outputdatas["ddl_subject"] = array_keys($courses)[0];
do {
    $outputdatas["txt_captcha"] = $verilist[$vericount];
    $request = http_build_query($outputdatas);
    curl_setopt($ch, CURLOPT_POST, 1);
    curl_setopt($ch, CURLOPT_POSTFIELDS, $request);
    $response = curl_exec($ch);
    if ($vericount >= count($verilist) - 1)
        die($response . PHP_EOL);
    else
        $vericount++;
} while (strpos($response, 'Invalid Verification Code') !== false);
$vericode = $verilist[$vericount - 1];
curl_close($ch);


//Get All courses
$mh = curl_multi_init();
$curlarray = [];
foreach ($courses as $coursecode => $coursename) {
    $curlarray[$coursecode] = curl_init();
    curl_setopt($curlarray[$coursecode], CURLOPT_URL, $host);
    curl_setopt($curlarray[$coursecode], CURLOPT_CUSTOMREQUEST, 'POST');
    curl_setopt($curlarray[$coursecode], CURLOPT_RETURNTRANSFER, 1);
    curl_setopt($curlarray[$coursecode], CURLOPT_HTTPHEADER, $browserheader);
    $outputdatas["txt_captcha"] = $vericode;
    $outputdatas["ddl_subject"] = $coursecode;
    $request = http_build_query($outputdatas);
    curl_setopt($curlarray[$coursecode], CURLOPT_POST, 1);
    curl_setopt($curlarray[$coursecode], CURLOPT_POSTFIELDS, $request);
    curl_multi_add_handle($mh, $curlarray[$coursecode]);

    if($coursecode >= "AIST") break;
}

$active = null;
do {
    $mrc = curl_multi_exec($mh, $active);
} while ($mrc == CURLM_CALL_MULTI_PERFORM);

while ($active && $mrc == CURLM_OK) {
    if (curl_multi_select($mh) != -1) {
        do {
            $mrc = curl_multi_exec($mh, $active);
        } while ($mrc == CURLM_CALL_MULTI_PERFORM);
    }
}

foreach ($curlarray as $coursecode => $ch) {
    $tempcountcourse = substr_count(curl_multi_getcontent($ch), 'normalGridViewRowStyle');
    if ($tempcountcourse)
        $coursecountbycode[$coursecode] = $tempcountcourse;
    curl_multi_remove_handle($mh, $ch);
}
curl_multi_close($mh);

print_r($coursecountbycode);
