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
    'Cookie: PS_DEVICEFEATURES=maf:0 width:1728 height:1117 clientWidth:1200 clientHeight:871 pixelratio:2 touch:0 geolocation:1 websockets:1 webworkers:1 datepicker:1 dtpicker:1 timepicker:1 dnd:1 sessionstorage:1 localstorage:1 history:1 canvas:1 svg:1 postmessage:1 hc:0; ASP.NET_SessionId=' . $SESSIONID,
];


//Get Subject List
foreach ((new DOMXPath($dom))->query('//option') as $html) {
    if ($html->getAttribute('value'))
        $courses[$html->getAttribute('value')] = $html->textContent;
}

//Get Verification Code Image
$imgurl = explode("?", (new DOMXPath($dom))->evaluate('//img[@id="imgCaptcha"]')->item(0)->getAttribute('src'));
foreach (explode("&", $imgurl[1]) as $param) {
    $imgparam[explode("=", $param)[0]] = explode("=", $param)[1];
}
$imgparam["len"] = 1;
$veriaddr = $imgurl[0] . "?captchaname=" . $imgparam["captchaname"] . "&len=" . $imgparam["len"];
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


//Get All courses by search
$mh = curl_multi_init();
$outputdatas["txt_captcha"] = $vericode;
$curlarray = [];
foreach ($courses as $subjectcode => $coursename) {
    $curlarray[$subjectcode] = curl_init();
    curl_setopt($curlarray[$subjectcode], CURLOPT_URL, $host);
    curl_setopt($curlarray[$subjectcode], CURLOPT_CUSTOMREQUEST, 'POST');
    curl_setopt($curlarray[$subjectcode], CURLOPT_RETURNTRANSFER, 1);
    curl_setopt($curlarray[$subjectcode], CURLOPT_HTTPHEADER, $browserheader);
    $outputdatas["ddl_subject"] = $subjectcode;
    $request = http_build_query($outputdatas);
    curl_setopt($curlarray[$subjectcode], CURLOPT_POST, 1);
    curl_setopt($curlarray[$subjectcode], CURLOPT_POSTFIELDS, $request);
    curl_multi_add_handle($mh, $curlarray[$subjectcode]);

    //if ($subjectcode >= "ACPY") break;
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

foreach ($curlarray as $subjectcode => $ch) {
    $html = curl_multi_getcontent($ch);
    $tempcountcourse = substr_count($html, 'normalGridViewRowStyle') + substr_count($html, 'normalGridViewAlternatingRowStyle');
    if ($tempcountcourse) {
        $coursecountbycode[$subjectcode] = $tempcountcourse;
        $dom = new DOMDocument();
        libxml_use_internal_errors(true);
        $dom->loadHTML($html);
        foreach ((new DOMXPath($dom))->query('//input') as $html) {
            try {
                $courseinputbycode[$subjectcode][$html->getAttribute('name')] = $html->getAttribute('value');
            } catch (Exception $e) { }
        }
        unset($courseinputbycode[$subjectcode]["btn_search"]);
        unset($courseinputbycode[$subjectcode]["btn_refresh"]);
        $courseinputbycode[$subjectcode]["__EVENTARGUMENT"] = '';
        $courseinputbycode[$subjectcode]["ddl_subject"] = $subjectcode;
    }
    curl_multi_remove_handle($mh, $ch);
}
unset($curlarray);
curl_multi_close($mh);

//Search check all course
$mh = curl_multi_init();
foreach ($coursecountbycode as $subjectcode => $totalcount) {
    for ($i=2; $i<=$totalcount ; $i++) {
    //for ($i = 2; $i <= 5; $i++) {
        $curlarray[$subjectcode][$i] = curl_init();
        curl_setopt($curlarray[$subjectcode][$i], CURLOPT_URL, $host);
        curl_setopt($curlarray[$subjectcode][$i], CURLOPT_CUSTOMREQUEST, 'POST');
        curl_setopt($curlarray[$subjectcode][$i], CURLOPT_RETURNTRANSFER, 1);
        curl_setopt($curlarray[$subjectcode][$i], CURLOPT_HTTPHEADER, $browserheader);
        $courseinputbycode[$subjectcode]["__EVENTTARGET"] = 'gv_detail$ctl' . sprintf('%02d', $i) . '$lbtn_course_nbr';
        $request = http_build_query($courseinputbycode[$subjectcode]);
        curl_setopt($curlarray[$subjectcode][$i], CURLOPT_POST, 1);
        curl_setopt($curlarray[$subjectcode][$i], CURLOPT_POSTFIELDS, $request);
        curl_multi_add_handle($mh, $curlarray[$subjectcode][$i]);
    }
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

foreach ($curlarray as $subjectcode => $course) {
    foreach ($course as $courseid => $ch) {
        $html = curl_multi_getcontent($ch);
        $dom = new DOMDocument();
        libxml_use_internal_errors(true);
        $dom->loadHTML($html);
        $coursehtml = new DOMXPath($dom);
        foreach ($coursehtml->query('//tr[@class="normalGridViewRowStyle"]') as $tablerow) {
            try {
                $classstatussrc = $coursehtml->evaluate('.//img[contains(@id, "_img_status")]', $tablerow);
                if ($classstatussrc->length > 0) {
                    $coursename = $coursehtml->evaluate('//span[@id="uc_course_lbl_course"]')->item(0)->nodeValue;
                    $classname = $coursehtml->evaluate('.//a[contains(@id, "_lkbtn_class_section")]', $tablerow)->item(0)->nodeValue;
                    $classstatussrc = $coursehtml->evaluate('.//img[contains(@id, "_img_status")]', $tablerow)->item(0)->getAttribute('src');
                    $classstatus =  strpos($classstatussrc, "open") !== false ? "Open" : (strpos($classstatussrc, "closed") !== false ? "Closed" : (strpos($classstatussrc, "wait") !== false ? "Waitlist" : "Error"));
                    $coursedetails[$subjectcode][$coursename][$classname] = $classstatus;
                }
            } catch (Exception $e) { }
        }
    }
}
curl_multi_close($mh);

echo "<pre>".json_encode($coursedetails, JSON_PRETTY_PRINT)."</pre>";
